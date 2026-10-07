import os
import sys, asyncio, json; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from navlib import nav
import staff_seed
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); AXE=open(os.environ.get('AXE_JS', 'node_modules/axe-core/axe.min.js')).read()
async def login(pg,email,pw):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1800)
async def run(pg):
    await pg.evaluate(AXE)
    return await pg.evaluate("""async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','best-practice']},resultTypes:['violations']});
      return r.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,n:v.nodes.length,ex:v.nodes.slice(0,3).map(n=>n.target.join(' ')+' :: '+(n.failureSummary||'').split('\\n').slice(1,2).join(''))}))}""")
async def main():
    sql("DELETE FROM login_attempts"); staff_seed.seed(); res={}
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for theme in ('dark','light'):
            for who,(em,pw) in {'líder':('maria@teste.pt','123456'),'funcionária':('st-ana@teste.pt',staff_seed.PW)}.items():
                sql("DELETE FROM login_attempts")
                ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script(f"localStorage.setItem('gf-reptil','off');localStorage.setItem('gf-tema','{theme}');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
                await login(pg,em,pw)
                async def state(name, fn):
                    try: await fn(); await pg.wait_for_timeout(900); res[f'{theme}/{who}: {name}']=await run(pg)
                    except Exception as e: res[f'{theme}/{who}: {name}']=[{'id':'TESTE-ERRO','impact':'critical','help':str(e)[:90],'n':1,'ex':''}]
                    finally: await pg.keyboard.press('Escape'); await pg.wait_for_timeout(300)
                async def noop(): pass
                await state('sino aberto', lambda: pg.click('#notif-btn'))
                if who=='líder':
                    await state('Visão geral com «A tua equipa»', lambda: nav(pg,'overview'))
                    await state('aba Funcionários (lista)', lambda: nav(pg,'staff'))
                    async def prof(): await pg.click('.staff-open >> text=Ana Silva'); await pg.wait_for_selector('.staff-profile')
                    await state('perfil de um funcionário', prof)
                    async def semana(): await pg.click('[data-unit=week]')
                    await state('perfil: gráficos semanais', semana)
                    async def nt(): await pg.click('.staff-actions [data-act=task]'); await pg.wait_for_selector('.sf-form')
                    await state('janela «Nova tarefa»', nt)
                    async def meta(): await pg.click('.staff-actions [data-act=goal]'); await pg.wait_for_selector('.sf-form')
                    await state('janela «Definir meta»', meta)
                    async def back(): await pg.click('[data-act=back]'); await pg.wait_for_selector('.staff-card')
                    await back()
                    async def anun(): await pg.click('#staff-announce'); await pg.wait_for_selector('.sf-form')
                    await state('janela «Anúncio»', anun)
                    async def filt(): await pg.select_option('#staff-filters [name=status]','pause'); await pg.wait_for_timeout(800)
                    await state('lista com filtro ativo', filt)
                    async def vazio(): await pg.select_option('#staff-filters [name=status]',''); await pg.fill('#staff-filters [name=perf_min]','99'); await pg.wait_for_timeout(900)
                    await state('lista sem resultados', vazio)
                else:
                    await state('aba Mensagens e tarefas', lambda: nav(pg,'work'))
                await ctx.close()
        await b.close()
    bad={k:v for k,v in res.items() if v}
    print(f'estados auditados: {len(res)} | sem nenhuma violação: {len(res)-len(bad)}')
    for k,v in bad.items():
        print('\n',k)
        for x in v: print(f"   [{x['impact']}] {x['id']}: {x['help']} — {x['n']} elemento(s)\n      ex: {x['ex'][:2]}")
asyncio.run(main())
