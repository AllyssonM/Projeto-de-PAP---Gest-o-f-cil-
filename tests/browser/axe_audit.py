import os
from navlib import nav
import sys, asyncio, json; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from engine_ui import set_profile
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); AXE=open(os.environ.get('AXE_JS','node_modules/axe-core/axe.min.js')).read()
async def login(pg,email='maria@teste.pt',pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1700)
async def run(pg, label, theme):
    await pg.evaluate(AXE)
    r=await pg.evaluate("""async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','best-practice']},resultTypes:['violations']});
      return r.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,n:v.nodes.length,ex:v.nodes.slice(0,3).map(n=>n.target.join(' ')+' :: '+(n.failureSummary||'').split('\\n').slice(1,2).join(''))}))}""")
    return r
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()"); set_profile('restaurant')
    results={}
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for theme in ('dark','light'):
            ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script(f"localStorage.setItem('gf-reptil','off');localStorage.setItem('gf-tema','{theme}')")
            pg=await ctx.new_page()
            await pg.goto(BASE+'/index.php'); await pg.wait_for_timeout(1200); results[f'{theme}: página inicial']=await run(pg,'',theme)
            await login(pg)
            for tab in ['overview','cashflow','calendar','notes','time','insights','clients','accounts','stock','team','calculator','reports']:
                try: await nav(pg,tab); await pg.wait_for_timeout(900)
                except Exception: continue
                results[f'{theme}: painel/{tab}']=await run(pg,tab,theme)
            await pg.click('#menu-btn'); await pg.wait_for_timeout(700); results[f'{theme}: menu lateral aberto']=await run(pg,'menu',theme); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(500)
            await pg.click('#chart-controls [data-t=lines]') if False else None
            # ---- estados novos: janelas e cartões
            async def state(name, opener, closer=None):
                try:
                    await opener(); await pg.wait_for_timeout(900); results[f'{theme}: {name}']=await run(pg,name,theme)
                except Exception as e: results[f'{theme}: {name}']=[{'id':'TESTE-ERRO','impact':'critical','help':'o estado não abriu: '+str(e)[:70],'n':1,'ex':''}]
                finally:
                    if closer: 
                        try: await closer()
                        except Exception: pass
                    await pg.keyboard.press('Escape'); await pg.wait_for_timeout(350)
            await state('registo rápido (+)', lambda: pg.click('#quick-fab'))
            await state('pesquisa Ctrl+K', lambda: pg.keyboard.press('Control+k'))
            await state('fecho do dia', lambda: pg.click('#close-day-btn'))
            for k in range(1,7):
                async def op(k=k):
                    await pg.click('#change-profile'); await pg.wait_for_timeout(500)
                    for _ in range(k-1): await pg.click('#wiz-next'); await pg.wait_for_timeout(250)
                await state(f'questionário passo {k}', op)
            await pg.reload(); await pg.wait_for_timeout(1800); await pg.evaluate("window.showSection('overview')"); await pg.wait_for_timeout(600)
            await state('Visão geral com datas fiscais', lambda: pg.wait_for_selector('#fiscal-card', state='visible', timeout=4000))
            for legal in ['politica-privacidade','politica-cookies','termos-e-condicoes']:
                await pg.goto(BASE+'/'+legal); await pg.wait_for_timeout(900); results[f'{theme}: {legal}']=await run(pg,legal,theme)
            await pg.goto(BASE+'/area-pessoal.php'); await pg.wait_for_timeout(1200)
            for t in ['perfil','empresa','cartoes','seguranca']:
                await pg.click(f'[data-tab={t}]'); await pg.wait_for_timeout(700); results[f'{theme}: área pessoal/{t}']=await run(pg,t,theme)
            await ctx.close()
        await b.close()
    set_profile('general')
    json.dump(results,open(os.path.join(os.environ.get('LUMINA_SHOTS','/tmp'),'axe_results.json'),'w'),ensure_ascii=False,indent=1)
    agg={}
    for page,vs in results.items():
        for v in vs: agg.setdefault((v['impact'],v['id'],v['help']),[]).append((page,v['n'],v['ex']))
    order={'critical':0,'serious':1,'moderate':2,'minor':3}
    print(f'páginas/abas auditadas: {len(results)} | páginas sem nenhuma violação: {sum(1 for v in results.values() if not v)}')
    for (imp,id_,help_),lst in sorted(agg.items(), key=lambda x:(order.get(x[0][0],9), -sum(n for _,n,_ in x[1]))):
        print(f'\n[{imp}] {id_}: {help_} — {sum(n for _,n,_ in lst)} elementos em {len(lst)} páginas'); print('   ex:', lst[0][2][0][:170] if lst[0][2] else '')
        print('   páginas:', ', '.join(sorted({p for p,_,_ in lst}))[:200])
asyncio.run(main())
