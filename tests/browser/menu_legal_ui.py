import os
import sys, asyncio, re, subprocess, urllib.request; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
LEGAL=''+os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..'))+'/config/legal.php'
async def login(pg,email='maria@teste.pt',pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def mk(b,w=1366,h=900):
    ctx=await b.new_context(locale='pt-PT', viewport={'width':w,'height':h}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); return ctx
OPEN="document.documentElement.classList.contains('menu-open')"
async def wait_open(pg): await pg.wait_for_function("document.documentElement.classList.contains('menu-open')&&getComputedStyle(document.querySelector('#main-menu')).visibility==='visible'"); await pg.wait_for_timeout(300)
async def wait_closed(pg): await pg.wait_for_function("!document.documentElement.classList.contains('menu-open')&&getComputedStyle(document.querySelector('#main-menu')).visibility==='hidden'"); 
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()"); errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await mk(b); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg)
        print('=== MENU LATERAL ===')
        vis=[t for t in ['overview','cashflow','calendar','notes','accounts','reports'] if await pg.is_visible(f'#main-menu [data-section={t}]')]
        print('fechado: nenhuma aba visível no topo:', vis, ok(vis==[]), '| barra horizontal antiga removida do fluxo:', ok(await pg.evaluate("getComputedStyle(document.querySelector('#main-menu')).position")=='fixed'))
        bx=await pg.evaluate("(()=>{const r=document.querySelector('#menu-btn').getBoundingClientRect();const svg=document.querySelector('#menu-btn svg path').getAttribute('d');return {x:Math.round(r.x),y:Math.round(r.y),w:Math.round(r.width),d:svg,label:document.querySelector('#menu-btn').getAttribute('aria-label'),exp:document.querySelector('#menu-btn').getAttribute('aria-expanded')}})()")
        print('botão ☰:', bx, ok(bx['x']<140 and bx['y']<120 and bx['d'].count('M')==3 and bx['exp']=='false' and bx['label']=='Abrir menu'), '(canto superior esquerdo, 3 barras horizontais)')
        print('secção atual junto à marca:', repr((await pg.inner_text('#menu-current')).strip()), ok('Visão geral' in await pg.inner_text('#menu-current')))
        await pg.click('#menu-btn'); await wait_open(pg)
        st=await pg.evaluate("({exp:document.querySelector('#menu-btn').getAttribute('aria-expanded'),label:document.querySelector('#menu-btn').getAttribute('aria-label'),items:[...document.querySelectorAll('#main-menu .nav-tab')].filter(t=>t.offsetParent!==null&&!t.classList.contains('ramo-off')).map(t=>t.textContent.trim()),cur:document.querySelector('#main-menu [aria-current=page]')?.textContent.trim(),inert:document.querySelector('#main-menu').hasAttribute('inert'),focusIn:document.querySelector('#main-menu').contains(document.activeElement),w:Math.round(document.querySelector('#main-menu').getBoundingClientRect().width),scroll:getComputedStyle(document.documentElement).overflow})")
        print('aberto:', st['items'], ok(len(st['items'])>=10 and st['exp']=='true' and not st['inert']))
        print('   aba atual marcada (aria-current):', st['cur'], ok(st['cur']=='Visão geral'), '| foco passa para dentro do menu:', ok(st['focusIn']), '| página não faz scroll por trás:', st['scroll'], ok(st['scroll']=='hidden'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/menu_open2.png')
        await pg.keyboard.press('Escape'); await wait_closed(pg); f=await pg.evaluate("document.activeElement?.id"); print('Esc fecha e o foco volta ao ☰:', f, ok(f=='menu-btn'), '| menu inerte outra vez:', ok(await pg.evaluate("document.querySelector('#main-menu').hasAttribute('inert')")))
        await pg.keyboard.press('Enter'); await wait_open(pg); print('Enter no ☰ (teclado) abre:', ok(await pg.evaluate(OPEN)))
        await pg.mouse.click(1100,500); await wait_closed(pg); print('clicar fora (fundo escurecido) fecha:', ok(not await pg.evaluate(OPEN)))
        await pg.click('#menu-btn'); await wait_open(pg); await pg.click('#menu-close'); await wait_closed(pg); print('botão ✕ fecha:', ok(not await pg.evaluate(OPEN)))
        await pg.click('#menu-btn'); await wait_open(pg)
        await pg.keyboard.press('ArrowDown'); f1=await pg.evaluate("document.activeElement.textContent.trim()"); await pg.keyboard.press('ArrowDown'); f2=await pg.evaluate("document.activeElement.textContent.trim()"); print('setas ↓ percorrem as abas:', f1,'→',f2, ok(f1!=f2))
        inside=True
        for i in range(30):
            await pg.keyboard.press('Tab'); inside &= await pg.evaluate("document.querySelector('#main-menu').contains(document.activeElement)")
        print('Tab 30 vezes: o foco nunca sai do menu aberto:', ok(inside))
        await pg.click('#main-menu [data-section=cashflow]'); await wait_closed(pg); await pg.wait_for_timeout(500)
        print('escolher "Fluxo de caixa": menu fecha, secção muda:', ok(await pg.is_visible('#cashflow')), '| junto à marca:', repr((await pg.inner_text('#menu-current')).strip()), ok('Fluxo de caixa' in await pg.inner_text('#menu-current')))
        await nav(pg,'reports'); print('relatórios acessíveis pelo menu:', ok(await pg.is_visible('#report-content')))
        print('ERROS JS:', errs or 'nenhum'); await ctx.close()
        # telemóvel
        ctx=await mk(b,390,844); pg=await ctx.new_page(); await login(pg); await pg.click('#menu-btn'); await wait_open(pg)
        m=await pg.evaluate("({w:Math.round(document.querySelector('#main-menu').getBoundingClientRect().width),vw:innerWidth,scroll:document.documentElement.scrollWidth-innerWidth})"); print('telemóvel (390 px): menu', m['w'], 'px (≤ 88% do ecrã):', ok(m['w']<=0.88*m['vw']+1), '| sem scroll horizontal:', ok(m['scroll']<=2)); await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/menu_mobile.png'); await ctx.close()
        # funcionário
        ctx=await mk(b); pg=await ctx.new_page(); await login(pg,'cal@teste.pt'); await pg.click('#menu-btn'); await wait_open(pg)
        items=await pg.evaluate("[...document.querySelectorAll('#main-menu .nav-tab')].filter(t=>t.offsetParent!==null&&!t.classList.contains('ramo-off')).map(t=>t.textContent.trim())"); print('funcionário só com Calendário vê no menu:', items, ok('Contas' not in items and 'Fluxo de caixa' not in items and 'Calendário' in items and 'Equipa' not in items)); await ctx.close()
        print('\n=== PÁGINAS LEGAIS ===')
        ctx=await mk(b); pg=await ctx.new_page()
        for url,h1,must in [('politica-privacidade','Política de Privacidade',['RGPD','CNPD','Última atualização','Quem é o responsável','palavra-passe']),('politica-cookies','Política de Cookies',['PHPSESSID','gf-tema','Open-Meteo','não usamos cookies de publicidade']),('termos-e-condicoes','Termos e Condições',['Lei aplicável','cartões associados','Projeto de Prova de Aptidão Profissional' if False else 'PAP'])]:
            r=await pg.goto(BASE+'/'+url); t=await pg.inner_text('body'); tl=t.lower()
            print(f'{url:28s}', r.status, '| h1:', await pg.inner_text('h1'), ok(r.status==200 and await pg.inner_text('h1')==h1), '| conteúdo-chave:', ok(all(m.lower() in tl for m in must)), '| aviso "texto-modelo":', ok('texto-modelo' in tl))
        print('   sem login mostra "Voltar ao início":', ok('Voltar ao início' in await pg.inner_text('.top-actions')), '| sem referências a PRR/UE:', ok(not re.search(r'PRR|NextGeneration|Financiado pela', t)), '| sem Livro de Reclamações por omissão:', ok('Livro de Reclamações' not in t))
        print('   nada da empresa do exemplo:', ok(not re.search(r'SOL AS RAJADAS|solasrajadas|Agualva|919 675', t, re.I)))
        await pg.goto(BASE+'/index.php'); await pg.wait_for_timeout(1500); links=await pg.evaluate("[...document.querySelectorAll('footer.lum-footer a')].map(a=>a.getAttribute('href'))"); print('rodapé da página inicial:', links, ok(all(x in links for x in ['politica-privacidade','politica-cookies','termos-e-condicoes'])))
        copy=await pg.inner_text('footer.lum-footer .lf-bar'); print('   crédito do grupo:', copy[:110], ok('Alisson Miguel Mota Madalena' in copy and 'Arthur Siqueira' in copy and 'Arthur Silva' in copy))
        await pg.goto(BASE+'/politica-cookies'); await pg.evaluate("window.scrollTo(0,document.body.scrollHeight)"); await pg.wait_for_timeout(400); y0=await pg.evaluate("window.scrollY"); await pg.click('[data-back-to-top]'); await pg.wait_for_timeout(900); print('botão ↑ volta ao topo:', y0,'→',await pg.evaluate("window.scrollY"), ok(await pg.evaluate("window.scrollY")<5 and y0>200))
        await ctx.close()
        print('\n=== AVISO DE COOKIES ===')
        ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); pg=await ctx.new_page(); await pg.goto(BASE+'/index.php'); await pg.wait_for_timeout(1500)
        print('1.ª visita mostra o aviso:', ok(await pg.is_visible('.cookie-note')), '|', (await pg.inner_text('.cookie-note')).split('\n')[0][:70], '| "Saber mais" ->', await pg.get_attribute('.cookie-note a','href'), ok(await pg.get_attribute('.cookie-note a','href')=='politica-cookies'))
        await pg.click('.cookie-note button'); await pg.wait_for_timeout(600); print('"Entendi" fecha e guarda:', ok(await pg.locator('.cookie-note').count()==0 and await pg.evaluate("localStorage.getItem('lumina-aviso-cookies')")=='1'))
        await pg.reload(); await pg.wait_for_timeout(1500); print('depois de recarregar já não aparece:', ok(await pg.locator('.cookie-note').count()==0))
        await ctx.close()
        print('\n=== LIVRO DE RECLAMAÇÕES (interruptor) ===')
        orig=open(LEGAL,encoding='utf-8').read(); open(LEGAL,'w',encoding='utf-8').write(orig.replace("'complaints_book' => false","'complaints_book' => true").replace("'email'       => ''","'email'       => 'privacidade@exemplo.pt'"))
        try:
            await asyncio.sleep(2.6)      # o opcache do PHP só confere se o ficheiro mudou de 2 em 2 segundos
            ctx=await mk(b); pg=await ctx.new_page(); await pg.goto(BASE+'/termos-e-condicoes'); t=await pg.inner_text('body')
            print('ligado: ligação para livroreclamacoes.pt:', ok('Livro de Reclamações Eletrónico' in t), '| e-mail configurado aparece:', ok('privacidade@exemplo.pt' in t), '| href:', await pg.evaluate("[...document.querySelectorAll('a')].find(a=>a.href.includes('livroreclamacoes'))?.href"))
            await ctx.close()
        finally: open(LEGAL,'w',encoding='utf-8').write(orig)
        print('\n=== PDF DO RELATÓRIO (texto) ===')
        ctx=await mk(b); pg=await ctx.new_page(); await login(pg); await nav(pg,'reports'); await pg.wait_for_timeout(800)
        await pg.emulate_media(media='print'); await pg.pdf(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/relatorio2.pdf', format='A4', print_background=True, margin={'top':'14mm','bottom':'14mm','left':'14mm','right':'14mm'}); await pg.emulate_media(media='screen')
        txt=subprocess.run(['pdftotext','-layout',''+os.environ.get('LUMINA_SHOTS','/tmp')+'/relatorio2.pdf','-'],capture_output=True,text=True).stdout; pages=txt.count('\f'); txt=re.sub(r'[ \t]+',' ',txt)
        print('PDF:', pages, 'páginas | tem "Lumina":', ok('Lumina' in txt), '| "Relatório financeiro":', ok('Relatório financeiro' in txt), '| gráfico:', ok('Entradas e saídas' in txt), '| rodapé do sistema NÃO sai no papel:', ok('Política de Privacidade' not in txt and 'Termos e Condições' not in txt), '| aviso de cookies não sai:', ok('armazenamento essenciais' not in txt))
        imgs=subprocess.run(['pdfimages','-list',''+os.environ.get('LUMINA_SHOTS','/tmp')+'/relatorio2.pdf'],capture_output=True,text=True).stdout.strip().split('\n'); print('imagens no PDF (logo):', max(0,len(imgs)-2), ok(len(imgs)-2>=1))
        await ctx.close(); await b.close()
asyncio.run(main())
