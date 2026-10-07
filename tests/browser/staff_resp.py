import os
import sys, asyncio; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from navlib import nav
import staff_seed
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/')
async def login(pg,em,pw):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',em); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def main():
    staff_seed.seed(); bad=0
    async with async_playwright() as p:
        b=await p.chromium.launch()
        for name,w,h in [('telemóvel pequeno',320,640),('telemóvel',390,844),('tablet',768,1024),('portátil pequeno',1024,768),('ecrã grande',1920,1080)]:
            probs=[]
            for who,(em,pw) in {'líder':('maria@teste.pt','123456'),'funcionária':('st-ana@teste.pt',staff_seed.PW)}.items():
                sql("DELETE FROM login_attempts")
                ctx=await b.new_context(locale='pt-PT', viewport={'width':w,'height':h}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page(); await login(pg,em,pw)
                sw=lambda: pg.evaluate("document.documentElement.scrollWidth")
                async def chk(label):
                    s=await sw()
                    if s>w+2: probs.append(f'{who}/{label}: scroll horizontal ({s}px)')
                if who=='líder':
                    await nav(pg,'overview'); await pg.wait_for_timeout(700); await chk('visão geral')
                    await nav(pg,'staff'); await pg.wait_for_selector('.staff-card'); await pg.wait_for_timeout(500); await chk('lista')
                    await pg.click('.staff-open >> text=Ana Silva'); await pg.wait_for_selector('.staff-profile'); await pg.wait_for_timeout(900); await chk('perfil')
                    await pg.click('.staff-actions [data-act=task]'); await pg.wait_for_selector('.sf-form'); await pg.wait_for_timeout(400)
                    r=await pg.evaluate("(()=>{const r=document.querySelector('.ap-modal-card').getBoundingClientRect();return [r.left,r.right]})()")
                    if r[0]<-1 or r[1]>w+1: probs.append(f'{who}/janela: sai do ecrã {r}')
                    await pg.keyboard.press('Escape'); await pg.wait_for_timeout(300)
                else:
                    await nav(pg,'work'); await pg.wait_for_selector('.work-grid'); await pg.wait_for_timeout(900); await chk('mensagens e tarefas')
                await pg.click('#notif-btn'); await pg.wait_for_timeout(500)
                r=await pg.evaluate("(()=>{const r=document.querySelector('#notif-panel').getBoundingClientRect();return [r.left,r.right,r.bottom]})()")
                if r[0]<-1 or r[1]>w+1: probs.append(f'{who}/sino: sai do ecrã {r}')
                await ctx.close()
            print(f'{name:18s} {w}x{h}:', 'sem problemas' if not probs else probs); bad+=len(probs)
        await b.close()
    print('TOTAL com problemas:',bad); staff_seed.clean()
asyncio.run(main())
