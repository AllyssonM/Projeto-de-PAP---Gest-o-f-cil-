import os
from navlib import nav
import sys, asyncio, json; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/')
ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def login(pg,email,pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
TABS = "[...document.querySelectorAll('.nav-tab:not(.nav-lang-btn)')].filter(t=>t.offsetParent!==null).map(t=>t.textContent.trim())"
GLASS="""()=>{const g=document.querySelector('.tab-glass'),t=document.querySelector('.nav-tab.active');if(!g||!t)return null;const a=g.getBoundingClientRect(),b=t.getBoundingClientRect();return Math.abs(a.x-b.x)<1&&Math.abs(a.width-b.width)<1}"""
async def newpage(p,w=1366,h=900):
    b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':w,'height':h}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
    pg=await ctx.new_page(); errs=[]
    pg.on('pageerror',lambda e:errs.append('PAGEERROR: '+str(e))); pg.on('console',lambda m:errs.append('CONSOLE: '+m.text) if m.type=='error' else None); return b,pg,errs
async def main():
    async with async_playwright() as p:
        # ---------- DONO ----------
        b,pg,errs=await newpage(p); await login(pg,'maria@teste.pt')
        tabs=await pg.evaluate(TABS); print('1) DONO — abas:', tabs)
        print('   inclui «Equipa» e a secção «Cartões associados»:', ok('Equipa' in tabs and await pg.locator('#cards-panel').count()==1 and await pg.locator('#card-modal').count()==1))
        print('   destaque de vidro alinhado na aba ativa:', ok(await pg.evaluate(GLASS)))
        await nav(pg,'team'); await pg.wait_for_timeout(1500)
        print('2) DONO — aba Equipa: lista os funcionários:', await pg.inner_text('#team-total'), 'funcionários |', ok(await pg.evaluate(GLASS)), '(vidro alinhado)', '| lista:', ok(await pg.locator('#team-list > *').count()>=6))
        await nav(pg,'reports'); await pg.wait_for_timeout(800); await pg.click('#report-print-btn'); await pg.wait_for_timeout(600)
        print('3) DONO — Relatórios: o botão abre a janela «Preparar relatório»:', ok(await pg.is_visible('#print-modal'))); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(500)
        await nav(pg,'clients'); await pg.wait_for_timeout(500); await pg.reload(); await pg.wait_for_timeout(1800)
        print('4) DONO — atualizar a página mantém a aba «Clientes» ativa e o vidro alinhado:', ok(await pg.evaluate("document.querySelector('.nav-tab.active').dataset.section")=='clients' and await pg.evaluate(GLASS)))
        print('   erros JS:', [e for e in errs if 'open-meteo' not in e and 'ERR_FAILED' not in e] or 'nenhum'); await b.close()
        # ---------- FUNCIONÁRIOS ----------
        for email,label,expect_tabs,has_overview in [('cf@teste.pt','Func Caixa (cashflow)',['Início','Visão geral','Fluxo de caixa','Notas','Tempo ativo','Mensagens e tarefas','Calculadora','Tempo','Relatórios'],True),
                                                    ('cal@teste.pt','Func Calendário (calendar)',['Início','Calendário','Notas','Tempo ativo','Mensagens e tarefas','Calculadora','Tempo'],False),
                                                    ('fin@teste.pt','Func Contas e Stock (accounts, stock, calendar)',['Início','Calendário','Notas','Tempo ativo','Contas','Estoque','Mensagens e tarefas','Calculadora','Tempo'],False)]:
            b,pg,errs=await newpage(p); await login(pg,email); tabs=await pg.evaluate(TABS)
            print(f'\n5) {label}: abas = {tabs}', ok([t for t in tabs if t not in ('Mais pedidos','Produtos mais vendidos','Painel do ramo')]==expect_tabs))   # a aba do ramo muda de nome conforme o ramo escolhido
            print('   cartões escondidos (secção e janela nem existem no HTML):', ok(await pg.locator('#cards-panel').count()==0 and await pg.locator('#card-modal').count()==0), '| aba «Equipa» ausente:', ok('Equipa' not in tabs), '| botão da IA presente:', ok(await pg.is_visible('#ai-fab')), '| vidro alinhado:', ok(await pg.evaluate(GLASS)))
            if has_overview:
                await nav(pg,'overview'); await pg.wait_for_timeout(800); print('   Visão geral do funcionário sem a secção de cartões:', ok(await pg.locator('#cards-panel').count()==0 and await pg.is_visible('#overview')))
            await pg.click('#ai-fab'); await pg.wait_for_timeout(700); await pg.fill('#ai-input','Quanto temos para receber?'); await pg.press('#ai-input','Enter'); await pg.wait_for_timeout(3800)
            reply=await pg.inner_text('#ai-messages'); print('   chat de IA (pergunta sobre contas a receber):', reply.strip().split('\n')[-1][:95], '|', ok(('permiss' in reply.lower()) if email!='fin@teste.pt' else ('6.700' in reply)))
            print('   erros JS:', [e for e in errs if 'open-meteo' not in e and 'ERR_FAILED' not in e] or 'nenhum'); await b.close()
        # ---------- palavra-passe provisória ----------
        b,pg,errs=await newpage(p); await login(pg,'pw@teste.pt'); vis=await pg.is_visible('#password-modal'); fab=await pg.evaluate("getComputedStyle(document.getElementById('ai-fab')).display")
        print('\n6) Func com palavra-passe provisória: janela de troca visível:', ok(vis), '| botão da IA escondido enquanto isso:', ok(fab=='none'), f'(display={fab})'); await b.close()
asyncio.run(main())
