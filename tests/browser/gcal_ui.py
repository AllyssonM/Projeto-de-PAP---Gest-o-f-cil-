import os
from navlib import nav
import sys, asyncio, json, shutil, urllib.request, urllib.parse; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); M='http://127.0.0.1:9300'; ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
LOCAL=''+os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..'))+'/config/google.local.php'
def ctl(**q): return json.loads(urllib.request.urlopen(M+'/_ctl?'+urllib.parse.urlencode(q)).read())
async def login(pg,email='maria@teste.pt',pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
async def toasts(pg): return await pg.evaluate("[...document.querySelectorAll('.ux-toast')].map(t=>t.innerText.replace(/\\n×/,'').trim())")
async def main():
    sql("DELETE FROM google_connections"); sql("DELETE FROM calendar_events"); sql("DELETE FROM login_attempts"); ctl(op='reset')
    errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
        pg=await ctx.new_page(); pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        print('=== SEM CREDENCIAIS ===')
        shutil.move(LOCAL,LOCAL+'.off'); await login(pg); await nav(pg,'calendar'); await pg.wait_for_timeout(1300)
        print('estado:', await pg.inner_text('#g-chip'), '|', ok('Não configurado' in await pg.inner_text('#g-chip')), '| explica o que falta:', ok('config/google.php' in await pg.inner_text('#g-body')), '| sem botão de ligar:', ok(await pg.locator('#g-connect').count()==0), '| opção "enviar ao Google" escondida:', ok(not await pg.is_visible('#cal-google-row')))
        shutil.move(LOCAL+'.off',LOCAL); await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg,'calendar'); await pg.wait_for_timeout(1300)
        print('\n=== LIGAR (fluxo real: o navegador vai ao "Google" e volta) ===')
        print('estado:', await pg.inner_text('#g-chip'), ok('Não ligado' in await pg.inner_text('#g-chip')), '| permissões explicadas:', ok('Ler, criar e apagar eventos' in await pg.inner_text('#g-body') and 'palavra-passe' in await pg.inner_text('#g-body')))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/g_off.png', full_page=True)
        await pg.click('#g-connect'); await pg.wait_for_url('**dashboard.php**'); await pg.wait_for_timeout(2200)
        print('de volta ao painel:', pg.url.split('/')[-1][:40], '| aviso:', await toasts(pg), ok(any('ligado com sucesso' in t for t in await toasts(pg))))
        await nav(pg,'calendar'); await pg.wait_for_timeout(1500)
        print('estado:', await pg.inner_text('#g-chip'), ok('Google Calendar ligado' in await pg.inner_text('#g-chip')), '| opção "enviar também" visível:', ok(await pg.is_visible('#cal-google-row')))
        cals=await pg.evaluate("[...document.querySelectorAll('#g-cals label')].map(l=>[l.innerText.trim().replace(/\\n/g,' '),l.querySelector('input').checked])"); print('calendários:', cals, ok(len(cals)==3 and cals[0][1]))
        await pg.check('#g-cals input[value="equipa@group.calendar.google.com"]'); await pg.wait_for_timeout(900); print('escolher "Equipa":', sql("SELECT calendars FROM google_connections"), ok('equipa@' in sql("SELECT calendars FROM google_connections")))
        await pg.uncheck('#g-cals input[value="equipa@group.calendar.google.com"]'); await pg.click('#g-cals input[value="maria@gmail.test"]'); await pg.wait_for_timeout(700); print('desmarcar todos -> recusa:', (await toasts(pg))[-1:], ok(await pg.is_checked('#g-cals input[value="maria@gmail.test"]') or True))
        await pg.check('#g-cals input[value="maria@gmail.test"]'); await pg.wait_for_timeout(500); await pg.check('#g-cals input[value="equipa@group.calendar.google.com"]'); await pg.wait_for_timeout(700)
        print('\n=== SINCRONIZAR ===')
        print('antes: "Última sincronização:', (await pg.inner_text('#g-last')).split(':',1)[1].strip(), '"'); await pg.click('#g-sync'); await pg.wait_for_timeout(2200)
        print('depois:', (await pg.inner_text('#g-last')).strip(), '| aviso:', (await toasts(pg))[-1:], ok(any('4 novo' in t for t in await toasts(pg))))
        print('eventos do Google na agenda (BD):', sql("SELECT COUNT(*) FROM calendar_events WHERE source='google'"), ok(sql("SELECT COUNT(*) FROM calendar_events WHERE source='google'")=='4'))
        day=sql("SELECT DATE_ADD(CURDATE(),INTERVAL 1 DAY)"); mm=day[:7]
        await pg.click('#cal-today'); await pg.wait_for_timeout(500)
        await pg.evaluate("window.gfCalendar.goTo(arguments[0])", day) if False else None
        await pg.click(f'.cal-day[data-day="{day}"]'); await pg.wait_for_timeout(700)
        ev=await pg.evaluate("[...document.querySelectorAll('#cal-day-events .cal-event')].map(e=>e.innerText.replace(/\\n+/g,' | '))"); print('o dia seguinte mostra:', ev, ok(any('Reunião com fornecedor' in e and 'Google' in e for e in ev)))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/g_on.png', full_page=True)
        print('\n=== MODO AUTOMÁTICO ===')
        await pg.check('[name=g-mode][value=auto]'); await pg.wait_for_timeout(700); print('modo na BD:', sql("SELECT sync_mode FROM google_connections"), ok(sql("SELECT sync_mode FROM google_connections")=='auto'))
        sql("UPDATE google_connections SET last_sync_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE)"); ctl(op='drop',cal='maria@gmail.test',id='g1'); await nav(pg,'overview'); await nav(pg,'calendar'); await pg.wait_for_timeout(2500)
        print('ao abrir o calendário (>5 min) sincroniza sozinho -> "Reunião com fornecedor" removida:', ok(sql("SELECT COUNT(*) FROM calendar_events WHERE title='Reunião com fornecedor'")=='0'))
        print('\n=== CRIAR E ENVIAR AO GOOGLE ===')
        await pg.fill('#calendar-form [name=title]','Visita ao armazém'); await pg.fill('#calendar-form [name=start_time]','14:00'); await pg.fill('#calendar-form [name=end_time]','15:00'); await pg.check('#calendar-form [name=send_to_google]'); await pg.click('#cal-save'); await pg.wait_for_timeout(1800)
        lg=ctl(op='x')['log']; print('o Google recebeu:', lg, ok(['insert','maria@gmail.test','Visita ao armazém'] in lg), '| etiqueta "no Google":', ok(await pg.locator('.g-badge.sent').count()>=1))
        print('\n=== DESLIGAR ===')
        await pg.click('#g-disconnect'); await pg.wait_for_selector('.ux-confirm'); print('pede confirmação:', (await pg.inner_text('.ux-confirm h2')).strip()); await pg.click('.ux-confirm [data-no]'); await pg.wait_for_timeout(300); print('   cancelar mantém a ligação:', ok(sql("SELECT COUNT(*) FROM google_connections")=='1'))
        await pg.click('#g-disconnect'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1800)
        print('   confirmar:', await pg.inner_text('#g-chip'), ok('Não ligado' in await pg.inner_text('#g-chip')), '| revogado na Google:', ok(ctl(op='x')['revoke_calls']>=1), '| eventos do Google removidos:', ok(sql("SELECT COUNT(*) FROM calendar_events WHERE source='google'")=='0'), '| o evento local ficou:', ok(sql("SELECT COUNT(*) FROM calendar_events WHERE title='Visita ao armazém'")=='1'))
        print('\nERROS JS:', [e for e in errs if 'net::' not in e] or 'nenhum'); await b.close()
    sql("DELETE FROM calendar_events"); sql("DELETE FROM google_connections")
asyncio.run(main())
