"""Vídeo de fundo + animação «Oi!»: autoplay, loop, sem controlos, cobre o ecrã, não bloqueia cliques; Oi! só 1x por sessão."""
import asyncio, os
from playwright.async_api import async_playwright
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080')
AXE = open(os.environ.get('AXE_JS', 'node_modules/axe-core/axe.min.js')).read()
bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
async def info(pg):
    return await pg.evaluate("""(()=>{const v=document.querySelector('#fundo-video video');if(!v)return null;const r=v.getBoundingClientRect(),cs=getComputedStyle(v);
      return {n:document.querySelectorAll('video').length,muted:v.muted,loop:v.loop,controls:v.controls,paused:v.paused,t:v.currentTime,fit:cs.objectFit,pe:cs.pointerEvents,
      w:r.width,h:r.height,x:r.left,y:r.top,vw:innerWidth,vh:innerHeight,z:getComputedStyle(v.parentElement).zIndex,black:v.videoWidth>0}})()""")
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch(args=['--autoplay-policy=no-user-gesture-required'])
        for w, h in [(390, 844), (820, 1100), (1366, 768), (1920, 1080)]:
            ctx = await b.new_context(locale='pt-PT', viewport={'width': w, 'height': h}); await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
            pg = await ctx.new_page(); await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(2500); i = await info(pg)
            print(f'{w}x{h}: um só vídeo, mudo, em loop, sem controlos:', ok(i and i['n'] == 1 and i['muted'] and i['loop'] and not i['controls']))
            print(f'{w}x{h}: a reproduzir sozinho (tempo avança):', ok(i and not i['paused'] and i['t'] > 0.5 and i['black']))
            print(f'{w}x{h}: cobre o ecrã todo (cover, sem faixas):', ok(i and i['fit'] == 'cover' and abs(i['w'] - i['vw']) < 1 and abs(i['h'] - i['vh']) < 1 and i['x'] == 0 and i['y'] == 0))
            print(f'{w}x{h}: atrás do conteúdo e sem receber cliques:', ok(i and i['pe'] == 'none' and int(i['z']) < 0))
            hit = await pg.evaluate("document.elementFromPoint(innerWidth/2, innerHeight/2).closest('#fundo-video')===null")
            print(f'{w}x{h}: clicar no centro não atinge o vídeo:', ok(hit))
            await ctx.close()
        ctx = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 768}); pg = await ctx.new_page()
        await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')")
        for path in ['/index.php', '/termos-e-condicoes.php', '/politica-privacidade.php', '/esqueci-palavra-passe.php']:
            await pg.goto(BASE + path); await pg.wait_for_timeout(700)
            print(f'{path}: tem fundo em vídeo:', ok(await pg.locator('#fundo-video video').count() == 1))
        await ctx.close()
        # Oi!
        ctx = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 768}); await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg = await ctx.new_page()
        await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(600)
        print('«Oi!» aparece à primeira visita:', ok(await pg.locator('#oi').count() == 1))
        await pg.wait_for_timeout(1500); print('   o vídeo continua visível por trás (overlay translúcido):', ok(await pg.evaluate("parseFloat(getComputedStyle(document.querySelector('#oi')).backgroundColor.split(',')[3])<0.95")))
        await pg.wait_for_timeout(3200); print('   termina sozinho em ~4 s e revela a página:', ok(await pg.locator('#oi').count() == 0 and not await pg.evaluate("document.documentElement.classList.contains('oi-on')")))
        await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(600); print('   não repete ao recarregar/navegar na mesma sessão:', ok(await pg.locator('#oi').count() == 0))
        ctx2 = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 768}); await ctx2.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg2 = await ctx2.new_page()
        await pg2.goto(BASE + '/index.php'); await pg2.wait_for_timeout(500); await pg2.click('.oi-skip'); await pg2.wait_for_timeout(600)
        print('«Saltar» fecha de imediato:', ok(await pg2.locator('#oi').count() == 0 and await pg2.locator('a.button.primary').first.is_visible()))
        ctx3 = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 768}); await ctx3.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg3 = await ctx3.new_page()
        await pg3.goto(BASE + '/index.php'); await pg3.wait_for_timeout(400); await pg3.keyboard.press('Escape'); await pg3.wait_for_timeout(600)
        print('Esc também fecha:', ok(await pg3.locator('#oi').count() == 0))
        await pg3.goto(BASE + '/index.php'); await pg3.evaluate("sessionStorage.clear()"); await pg3.reload(); await pg3.wait_for_timeout(500)
        await pg3.evaluate(AXE); v = await pg3.evaluate("axe.run(document,{runOnly:['wcag2a','wcag2aa']}).then(r=>r.violations.filter(x=>x.id!=='color-contrast').map(v=>v.id))")
        print('animação sem violações axe (exceto contraste do site por trás):', v, ok(not v))
        for c in (ctx, ctx2, ctx3): await c.close()
        # movimento reduzido
        ctx = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 768}, reduced_motion='reduce'); await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg = await ctx.new_page()
        await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(700); i = await info(pg)
        print('movimento reduzido: vídeo parado (fica o poster):', ok(i and i['paused']))
        print('movimento reduzido: «Oi!» vira só o nome, curto:', ok(await pg.locator('#oi.oi-reduce').count() == 1 and not await pg.locator('.oi-svg').is_visible()))
        await pg.wait_for_timeout(1500); print('   e desaparece sozinho:', ok(await pg.locator('#oi').count() == 0))
        await b.close()
    print('\nRESULTADO:', 'TUDO OK' if not bad else f'{bad} falhas'); raise SystemExit(1 if bad else 0)
asyncio.run(main())
