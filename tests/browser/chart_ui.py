import os
import sys, asyncio; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from engine_ui import set_profile
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def go(pg,name):
    await pg.click('#menu-btn'); await pg.wait_for_timeout(450); await pg.click(f'#main-menu [data-section={name}]'); await pg.wait_for_timeout(900)
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()"); set_profile('general')
    errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')"); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1800)
        print('por omissão:', await pg.inner_text('#chart-title'), '| barras HTML originais:', await pg.locator('#bar-chart .bar-group').count(), 'grupos', ok(await pg.locator('#bar-chart .bar-group').count()==6), '| botões:', await pg.evaluate("[...document.querySelectorAll('#chart-controls button')].map(b=>b.textContent)"))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/chart_default.png', clip={'x':80,'y':740,'width':1220,'height':160}) if False else None
        for per,exp,n in [('day','Movimento diário',14),('week','Movimento semanal',8),('months12','Movimento de 12 meses',12),('year','Movimento anual',5),('month','Movimento mensal',6)]:
            await pg.click(f'#chart-controls [data-p={per}]'); await pg.wait_for_timeout(350)
            cnt=await pg.locator('#bar-chart .bar-group').count(); print(f'   {per:9s} -> título:', await pg.inner_text('#chart-title'), '| grupos:', cnt, ok(cnt==n and await pg.inner_text('#chart-title')==exp))
        await pg.click('#chart-controls [data-p=month]'); await pg.click('#chart-controls [data-t=lines]'); await pg.wait_for_timeout(400)
        print('linhas:', 'svg' , await pg.locator('#bar-chart svg.gf-svg').count(), '| polylines:', await pg.locator('#bar-chart polyline').count(), '| pontos:', await pg.locator('#bar-chart circle').count(), ok(await pg.locator('#bar-chart polyline').count()==2 and await pg.locator('#bar-chart circle').count()==12), '| aria:', (await pg.get_attribute('#bar-chart','aria-label'))[:80])
        tot=await pg.evaluate("[...document.querySelectorAll('#bar-chart circle title')].filter(t=>t.textContent.includes('Entradas')).map(t=>t.textContent).join('|')"); print('   valores dos pontos incluem Entradas de 2.370,00?', ok('2370' in tot.replace('.','').replace(' ','').replace(',00','').replace('\u00a0','') or '2.370' in tot or '2370' in tot))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/chart_lines.png', full_page=True)
        await pg.reload(); await pg.wait_for_timeout(1600); print('depois de recarregar a página a escolha mantém-se (Linhas):', ok(await pg.locator('#bar-chart svg.gf-svg').count()==1))
        await pg.click('#chart-controls [data-t=bars]'); await pg.wait_for_timeout(300); await pg.click('#chart-controls [data-p=day]'); await pg.wait_for_timeout(300)
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/chart_day_bars.png', full_page=True)
        await pg.click('#chart-controls [data-p=month]')
        print('\n=== RELATÓRIO / PDF ===')
        await go(pg,'reports')
        print('cabeçalho:', (await pg.inner_text('.report-header')).replace('\n',' | '), ok(await pg.locator('.report-header img').count()>=1))
        print('gráfico no relatório:', await pg.locator('#report-chart svg').count(), '| controlos:', await pg.locator('#report-chart-controls button').count(), '| título:', await pg.inner_text('#report-chart-title'))
        await pg.click('#report-chart-controls [data-t=lines]'); await pg.wait_for_timeout(300); await pg.click('#report-chart-controls [data-p=week]'); await pg.wait_for_timeout(300); print('   linhas + semanal:', await pg.locator('#report-chart polyline').count(), 'linhas |', await pg.inner_text('#report-chart-title'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/report_screen.png', full_page=True)
        await pg.emulate_media(media='print'); await pg.pdf(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/relatorio.pdf', format='A4', print_background=True, margin={'top':'14mm','bottom':'14mm','left':'14mm','right':'14mm'}); await pg.emulate_media(media='screen')
        print('\nERROS JS:', errs or 'nenhum'); await b.close()
asyncio.run(main())
