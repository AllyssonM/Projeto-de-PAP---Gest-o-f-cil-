import os
from navlib import nav
import sys, asyncio, re; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
def secs(t): h,m,s=map(int,t.split(':')); return h*3600+m*60+s
async def login(pg,email,pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
async def ctr(pg): return (await pg.inner_text('#time-counter')).strip()
async def vis(pg,sel): return await pg.is_visible(sel)
async def newctx(b,skew_ms=0):
    ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
    if skew_ms: await ctx.add_init_script(f"(()=>{{const o=Date.now.bind(Date);Date.now=()=>o()+{skew_ms};}})()")
    return ctx
async def main():
    sql("DELETE FROM work_shifts"); sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()")
    sql("UPDATE users SET permissions='[\"accounts\",\"stock\",\"calendar\",\"time\"]' WHERE email='fin@teste.pt'")
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await newctx(b,skew_ms=2*3600*1000)       # relógio do PC adiantado 2 horas
        pg=await ctx.new_page(); errs=[]; pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg,'cal@teste.pt'); await nav(pg,'time'); await pg.wait_for_timeout(1200)
        print('=== FUNCIONÁRIO: O MEU TURNO (com o relógio do PC adiantado 2 horas!) ===')
        print('estado inicial:', await pg.inner_text('#time-state'), '|', await ctr(pg), ok(await ctr(pg)=='00:00:00'), '| só "Registar entrada" visível:', ok(await vis(pg,'#time-in') and not await vis(pg,'#time-pause') and not await vis(pg,'#time-out')))
        await pg.click('#time-in'); await pg.wait_for_timeout(1200); a=secs(await ctr(pg)); await pg.wait_for_timeout(3000); b2=secs(await ctr(pg))
        print('depois de entrar:', await pg.inner_text('#time-state'), '| contador', a, 's ->', b2, 's (sem recarregar):', ok(2<=b2-a<=4 and a<10), '| NÃO mostra as 2 h de diferença do relógio do PC:', ok(b2<60))
        print('   botões: Pausar e Registar saída visíveis:', ok(await vis(pg,'#time-pause') and await vis(pg,'#time-out') and not await vis(pg,'#time-in')), '| "Entrada às":', (await pg.inner_text('#time-since')).strip(), '| na BD:', sql("SELECT status FROM work_shifts WHERE ended_at IS NULL"))
        today=await pg.inner_text('#time-today'); print('   totais em direto — hoje:', today, '| semana:', await pg.inner_text('#time-week'), '| mês:', await pg.inner_text('#time-month'))
        await pg.click('#time-pause'); await pg.wait_for_timeout(1000); f1=await ctr(pg); await pg.wait_for_timeout(2500); f2=await ctr(pg)
        print('pausar:', await pg.inner_text('#time-state'), '| contador PARADO:', f1, '=', f2, ok(f1==f2), '| "Em pausa há":', (await pg.inner_text('#time-pause-for')).strip(), ok(await vis(pg,'#time-pause-for')))
        await pg.click('#time-resume'); await pg.wait_for_timeout(2500); r1=secs(await ctr(pg)); print('retomar:', await pg.inner_text('#time-state'), '| volta a contar a partir de', f2, '->', r1, ok(r1>=secs(f2))); await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/time_on.png', full_page=True)
        await pg.click('#time-out'); await pg.wait_for_selector('.ux-confirm'); print('saída pede confirmação:', (await pg.inner_text('.ux-confirm h2')).strip()); await pg.click('.ux-confirm [data-no]'); await pg.wait_for_timeout(300); print('   cancelar mantém o turno:', ok(await vis(pg,'#time-out')))
        await pg.click('#time-out'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1500)
        print('   confirmar:', await pg.inner_text('#time-state'), ok(await pg.inner_text('#time-state')=='Fora de turno'), '| histórico:', (await pg.inner_text('#time-table')).replace('\n',' | ')[:110])
        print('   o funcionário NÃO vê controlos de gestor:', ok(not await vis(pg,'#time-employee') and not await vis(pg,'#time-manual') and await pg.locator('[data-edit]').count()==0), '| coluna "Funcionário":', ok('Funcionário' not in await pg.inner_text('.time-table thead')))
        print('   leitores de ecrã: o estado é anunciado:', repr(await pg.inner_text('#time-live')))
        print('   ERROS JS:', errs or 'nenhum'); await ctx.close()
        # ---- administrador ----
        sql("DELETE FROM work_shifts")
        sql("INSERT INTO work_shifts (tenant_id,employee_id,started_at,ended_at,status,source) SELECT o.id,e.id,DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 30 HOUR),'%Y-%m-%d %H:%i:00'),DATE_FORMAT(DATE_SUB(NOW(),INTERVAL 22 HOUR),'%Y-%m-%d %H:%i:00'),'closed','clock' FROM users o JOIN users e ON e.owner_id=o.id WHERE o.email='maria@teste.pt' AND e.email IN ('cal@teste.pt','fin@teste.pt')")
        ctx=await newctx(b); pg=await ctx.new_page(); await login(pg,'maria@teste.pt'); await nav(pg,'time'); await pg.wait_for_timeout(1400)
        print('\n=== ADMINISTRADOR ===')
        rows=await pg.locator('.time-table tbody tr').count(); print('vê os registos de todos:', rows, 'linhas |', await pg.evaluate("[...document.querySelectorAll('#time-totals .time-chip')].map(c=>c.innerText.replace(/\\n/g,' '))"), ok(rows==2))
        print('   controlos de gestor visíveis:', ok(await vis(pg,'#time-employee') and await vis(pg,'#time-manual')), '| opções do filtro:', await pg.locator('#time-employee option').count())
        await pg.select_option('#time-employee',label='Func Calendário'); await pg.wait_for_timeout(900); print('   filtrar por funcionário:', await pg.locator('.time-table tbody tr').count(), 'linha', ok(await pg.locator('.time-table tbody tr').count()==1))
        await pg.fill('#time-from','2020-01-01'); await pg.fill('#time-to','2020-01-31'); await pg.dispatch_event('#time-to','change'); await pg.wait_for_timeout(900); print('   datas sem registos:', ok('Sem registos neste período' in await pg.inner_text('#time-table')))
        await pg.select_option('#time-employee',''); await pg.fill('#time-from','2020-01-01'); await pg.fill('#time-to','2099-01-01'); await pg.dispatch_event('#time-to','change'); await pg.wait_for_timeout(900)
        print('   ligação de exportar:', await pg.get_attribute('#time-export','href'), ok('action=export' in await pg.get_attribute('#time-export','href')))
        await pg.click('[data-edit] >> nth=0'); await pg.wait_for_selector('.ap-modal-card [name=reason]'); print('corrigir -> janela:', (await pg.inner_text('.ap-modal-card h2')).strip(), '| botão Eliminar (só o administrador):', ok(await vis(pg,'.ap-modal-card [data-del]')))
        await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(400); print('   sem motivo ->', (await pg.inner_text('.ap-modal-card .ap-form-error')).strip(), ok(await vis(pg,'.ap-modal-card .ap-form-error')))
        await pg.fill('.ap-modal-card [name=reason]','Saiu mais tarde do que marcou'); await pg.fill('.ap-modal-card [name=end]','2099-01-01T10:00'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(700); _e=await pg.inner_text('.ap-modal-card .ap-form-error'); print('   saída no futuro ->', (await pg.inner_text('.ap-modal-card .ap-form-error')).strip(), ok(('futuro' in _e) or ('24 horas' in _e)))
        start=await pg.input_value('.ap-modal-card [name=start]'); import datetime as dt; endv=(dt.datetime.fromisoformat(start)+dt.timedelta(hours=9)).strftime('%Y-%m-%dT%H:%M'); await pg.fill('.ap-modal-card [name=end]',endv); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(1200)
        print('   correção válida -> aviso:', await pg.evaluate("[...document.querySelectorAll('.ux-toast')].map(t=>t.innerText.replace(/\\n×/,'').trim()).slice(-1)"), '| etiqueta "Corrigido":', ok(await pg.locator('.time-badge.manual').count()==1), '| dica:', (await pg.get_attribute('.time-badge.manual','title'))[:70])
        await pg.click('#time-manual'); await pg.wait_for_selector('.ap-modal-card [name=employee]'); print('registo manual: lista de funcionários na janela:', await pg.locator('.ap-modal-card [name=employee] option').count(), ok(await pg.locator('.ap-modal-card [name=employee] option').count()>=3)); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(400)
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/time_admin.png', full_page=True); await ctx.close()
        # ---- gerente ----
        ctx=await newctx(b); pg=await ctx.new_page(); await login(pg,'fin@teste.pt'); await nav(pg,'time'); await pg.wait_for_timeout(1400)
        print('\n=== GERENTE (funcionário com a permissão "Tempo ativo da equipa") ===')
        print('vê todos:', await pg.locator('.time-table tbody tr').count(), 'linhas', ok(await pg.locator('.time-table tbody tr').count()==2), '| controlos de gestor:', ok(await vis(pg,'#time-employee')))
        await pg.click('[data-edit] >> nth=0'); await pg.wait_for_selector('.ap-modal-card [name=reason]'); print('pode corrigir, mas NÃO eliminar:', ok(not await vis(pg,'.ap-modal-card [data-del]'))); await pg.keyboard.press('Escape')
        await ctx.close(); await b.close()
asyncio.run(main())
