import os
from navlib import nav
import sys, asyncio; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def login(pg,email='maria@teste.pt',pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1700)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
async def txt(pg,sel): return (await pg.inner_text(sel)).strip()
async def main():
    sql("UPDATE users SET preferences=NULL WHERE email='maria@teste.pt'"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()")
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
        pg=await ctx.new_page(); errs=[]; pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg)
        print('=== BOTÃO DE PERFIL ===')
        a=await pg.evaluate("(()=>{const e=document.getElementById('avatar-btn'),r=e.getBoundingClientRect(),c=getComputedStyle(e);return {href:e.getAttribute('href'),text:e.textContent.trim(),w:Math.round(r.width),h:Math.round(r.height),round:c.borderRadius,tip:e.dataset.tip,aria:e.getAttribute('aria-label'),right:Math.round(innerWidth-r.right),top:Math.round(r.top),blur:c.backdropFilter}})()")
        print('avatar:', a, ok(a['href']=='area-pessoal.php' and a['text']=='MT' and 42<=a['w']<=46 and a['tip']=='Área pessoal'))
        await pg.hover('#avatar-btn'); await pg.wait_for_timeout(350); tipop=await pg.evaluate("getComputedStyle(document.getElementById('avatar-btn'),'::after').opacity"); print('   tooltip "Área pessoal" ao passar o rato (opacidade):', tipop, ok(float(tipop)>0.9))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/pv_header.png', clip={'x':700,'y':20,'width':666,'height':120})
        print('\n=== OCULTAR VALORES ===')
        before={k:await txt(pg,f'#{k}') for k in ['balance','income','expense','receivable']}; label_before=await txt(pg,'#overview .summary-card:first-child span'); rows_before=await pg.inner_text('#recent-transactions')
        print('valores antes:', before)
        await pg.click('#overview .summary-card:first-child .eye-btn'); await pg.wait_for_timeout(500)
        after={k:await txt(pg,f'#{k}') for k in ['balance','income','expense','receivable']}
        print('depois de clicar no olho do cartão:', after, ok(all(v=='••••••' for v in after.values())))
        print('   títulos continuam visíveis ("'+label_before+'"):', ok(await txt(pg,'#overview .summary-card:first-child span')==label_before), '| tabela "Movimentos recentes" sem valores:', ok('€' not in await pg.inner_text('#recent-transactions') and 'Venda' in await pg.inner_text('#recent-transactions')))
        toast=await pg.evaluate("[...document.querySelectorAll('.ux-toast')].map(t=>t.innerText.trim())"); print('   mensagem "Valores ocultos":', toast, ok(any('Valores ocultos' in t for t in toast)))
        btn=await pg.evaluate("(()=>{const b=document.querySelector('.icon-btn[data-privacy-toggle]');return [b.getAttribute('aria-pressed'),b.getAttribute('aria-label')]})()"); print('   botão do cabeçalho: aria-pressed =', btn[0], '| aria-label =', btn[1], ok(btn==['true','Mostrar valores']))
        print('   a preferência ficou guardada no servidor:', sql("SELECT preferences FROM users WHERE email='maria@teste.pt'"), ok('"hide_values":true' in sql("SELECT preferences FROM users WHERE email='maria@teste.pt'")))
        await nav(pg,'cashflow'); await pg.wait_for_timeout(900); cf=await pg.evaluate("({sum:[...document.querySelectorAll('#cashflow .summary-card strong')].map(e=>e.textContent.trim()),table:document.querySelector('#transactions').innerText,blur:getComputedStyle(document.querySelector('.cat-bar')||document.body).filter})"); print('Fluxo de caixa -> totais:', cf['sum'], ok(all(v=='••••••' for v in cf['sum'])), '| tabela sem euros:', ok('€' not in cf['table']), '| barras desfocadas:', cf['blur'])
        await nav(pg,'accounts'); await pg.wait_for_timeout(700); ac=await pg.evaluate("({a:document.querySelector('#accounts-list').innerText,b:document.querySelector('#bills').innerText})"); print('Contas -> sem euros:', ok('€' not in ac['a'] and '€' not in ac['b']))
        await nav(pg,'reports'); await pg.wait_for_timeout(700); rp=await pg.inner_text('#report-content'); print('Relatórios -> sem euros:', ok('€' not in rp), '| títulos mantidos:', ok('Saldo' in rp or 'Entradas' in rp))
        await nav(pg,'calculator'); await pg.wait_for_timeout(500); calc=await txt(pg,'#calc-total'); print('Calculadora NÃO é escondida ->', calc, ok('€' in calc and '•' not in calc))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/pv_cashflow_hidden.png')
        print('\n=== PERSISTE ===')
        await nav(pg,'overview'); await pg.reload(); await pg.wait_for_timeout(1700); print('depois de atualizar a página:', await txt(pg,'#balance'), ok(await txt(pg,'#balance')=='••••••'), '| <html data-hide-values> escrito pelo servidor:', ok(await pg.evaluate("document.documentElement.hasAttribute('data-hide-values')")))
        ctx2=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); pg2=await ctx2.new_page(); await login(pg2); print('noutro dispositivo (sessão nova):', await txt(pg2,'#balance'), ok(await txt(pg2,'#balance')=='••••••')); await ctx2.close()
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/pv_overview_hidden.png')
        print('\n=== MOSTRAR OUTRA VEZ ===')
        await pg.click('.icon-btn[data-privacy-toggle]'); await pg.wait_for_timeout(500); back={k:await txt(pg,f'#{k}') for k in ['balance','income','expense','receivable']}; print('valores de volta:', back, ok(back==before))
        print('   tabela recuperada:', ok(await pg.inner_text('#recent-transactions')==rows_before), '| preferência guardada como visível:', ok('"hide_values":false' in sql("SELECT preferences FROM users WHERE email='maria@teste.pt'")))
        print('\n=== REAPLICA QUANDO OS DADOS SE ATUALIZAM ===')
        await pg.click('.icon-btn[data-privacy-toggle]'); await pg.wait_for_timeout(400); await pg.evaluate("loadAll()"); await pg.wait_for_timeout(1500); print('recarregar os dados com valores ocultos:', await txt(pg,'#balance'), ok(await txt(pg,'#balance')=='••••••'))
        await pg.keyboard.press('Tab'); print('   erros JS:', errs or 'nenhum'); await pg.click('.icon-btn[data-privacy-toggle]'); await b.close()
asyncio.run(main())
