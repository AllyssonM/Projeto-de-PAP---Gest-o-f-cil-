import os
import sys, asyncio, json, subprocess, time; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
PORT=8084; BASE=f'http://127.0.0.1:{PORT}'; ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def main():
    sql("DELETE FROM login_attempts"); errs=[]
    srv=subprocess.Popen(['php','-S',f'127.0.0.1:{PORT}','-t',''+os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..'))+''],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); time.sleep(1.5)
    try:
        async with async_playwright() as p:
            b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
            pg.on('pageerror',lambda e:errs.append(str(e)))
            await pg.goto(BASE+'/index.php'); await pg.wait_for_timeout(1500)
            man=await pg.evaluate("fetch(document.querySelector('link[rel=manifest]').href).then(r=>r.json())")
            print('manifesto:', man['name'], '|', man['display'], '|', len(man['icons']),'ícones', ok(man['name']=='Lumina' and man['display']=='standalone' and len(man['icons'])==3))
            codes=[(await pg.request.get(BASE+'/'+ic['src'])).status for ic in man['icons']]
            print('   ícones existem (HTTP):', codes, ok(codes==[200,200,200]), '| theme-color:', await pg.get_attribute('meta[name=theme-color]','content'))
            reg=await pg.evaluate("navigator.serviceWorker.ready.then(r=>r.active.state)"); print('service worker ativo:', reg, ok(reg=='activated'))
            await pg.reload(); await pg.wait_for_timeout(1200); print('   a página passa a ser controlada por ele:', ok(await pg.evaluate("!!navigator.serviceWorker.controller")))
            await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(2500)
            urls=await pg.evaluate("caches.keys().then(async ks=>{let out=[];for(const k of ks){const c=await caches.open(k);out.push(...(await c.keys()).map(r=>new URL(r.url).pathname))}return out})")
            print('na cache:', len(urls), 'ficheiros | todos estáticos ou offline.html:', ok(all('/assets/' in u or u.endswith('offline.html') for u in urls)), '| NENHUM da API, de PHP ou de storage:', ok(not any('/api/' in u or u.endswith('.php') or '/storage/' in u for u in urls)))
            srv.terminate(); srv.wait(); time.sleep(0.5)                           # a rede "desaparece" de verdade
            await pg.goto(BASE+'/dashboard.php'); await pg.wait_for_timeout(1000); t=await pg.inner_text('body')
            print('SEM REDE, abrir o painel: página offline:', ok('Sem ligação à internet' in t), '| não mostra dados do negócio:', ok('Saldo atual' not in t and 'Mota Importz' not in t and 'Visão geral' not in t))
            r=await pg.evaluate("fetch('api/data.php?module=summary').then(r=>'respondeu '+r.status).catch(()=>'falhou (sem rede)')"); print('   a API não é servida pela cache:', r, ok('falhou' in r))
            await pg.goto(BASE+'/index.php'); await pg.wait_for_timeout(800); print('   qualquer outra página também mostra a offline:', ok('Sem ligação' in await pg.inner_text('body')))
            await b.close()
    finally:
        if srv.poll() is None: srv.terminate()
    print('ERROS JS:', errs or 'nenhum')
asyncio.run(main())
