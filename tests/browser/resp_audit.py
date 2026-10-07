import os
from navlib import nav
import sys, asyncio; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from engine_ui import set_profile
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/')
async def login(pg):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456')
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
OVER="(()=>{const w=document.documentElement.clientWidth;const bad=[...document.querySelectorAll('body *')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&e.offsetParent!==null&&(r.right>w+2)&&!e.closest('.time-scroll,.ins-scroll,.ap-tabs,.tabs,.note-list,.cal-scroll,table,.nav-tabs,.ux-toasts,.top-actions')});return {scroll:document.documentElement.scrollWidth-w,bad:bad.slice(0,3).map(e=>e.tagName.toLowerCase()+(e.id?'#'+e.id:'')+'.'+String(e.className).split(' ')[0])}})()"
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()"); set_profile('restaurant'); bad_total=0
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for w,h,name in [(320,640,'telemóvel pequeno'),(390,844,'telemóvel'),(768,1024,'tablet'),(1024,768,'portátil pequeno'),(1920,1080,'ecrã grande')]:
            ctx=await b.new_context(locale='pt-PT', viewport={'width':w,'height':h}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')"); pg=await ctx.new_page(); await login(pg); res=[]
            for tab in ['overview','cashflow','calendar','notes','time','insights','clients','accounts','stock','team','calculator','reports']:
                try: await nav(pg,tab)
                except Exception:
                    await pg.evaluate(f"showSection('{tab}')")
                await pg.wait_for_timeout(500); r=await pg.evaluate(OVER)
                if r['scroll']>2: res.append((tab,r['scroll'],r['bad']))
            await pg.goto(BASE+'/area-pessoal.php'); await pg.wait_for_timeout(900)
            for t in ['perfil','empresa','cartoes','seguranca']:
                await pg.click(f'[data-tab={t}]'); await pg.wait_for_timeout(400); r=await pg.evaluate(OVER)
                if r['scroll']>2: res.append(('pessoal/'+t,r['scroll'],r['bad']))
            bad_total+=len(res); print(f'{name:18s} {w}x{h}: ', 'sem scroll horizontal em nenhuma aba' if not res else res); await ctx.close()
        await b.close()
    set_profile('general'); print('\nTOTAL com problemas:', bad_total)
asyncio.run(main())
