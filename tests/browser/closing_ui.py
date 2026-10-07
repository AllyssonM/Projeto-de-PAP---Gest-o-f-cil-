import os
import sys, asyncio, json, re; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql, Client
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def login(pg,e,p):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',e); await pg.fill('#login-form [name=password]',p)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1700)
UID="(SELECT id FROM users WHERE email='maria@teste.pt')"
def clean():
    sql(f"DELETE FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'"); sql(f"DELETE FROM day_closings WHERE user_id={UID}")
    sql(f"DELETE FROM financial_documents WHERE user_id={UID} AND title='QA Renda'"); sql(f"DELETE FROM recurring_items WHERE user_id={UID}")
async def main():
    sql("DELETE FROM login_attempts"); clean(); sql("UPDATE users SET email_verified_at=NOW() WHERE email='maria@teste.pt'"); errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg,'maria@teste.pt','123456')
        print('=== REGISTO RÁPIDO: MÉTODO DE PAGAMENTO ===')
        async def quick(kind, method, amount, desc):
            await pg.click('#quick-fab'); await pg.wait_for_selector('.ap-modal-card'); await pg.wait_for_timeout(250)
            if kind=='expense': await pg.click('.quick-type [data-type=expense]')
            if method!='cash': await pg.click(f'.quick-method [data-method={method}]')
            await pg.fill('.ap-modal-card [name=amount]',amount); await pg.fill('.ap-modal-card [name=description]',desc); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(1300)
        print('por omissão está «Dinheiro»:', end=' '); await pg.click('#quick-fab'); await pg.wait_for_selector('.ap-modal-card'); print(ok(await pg.get_attribute('.quick-method [data-method=cash]','aria-pressed')=='true')); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(350)
        await quick('income','mbway','20','QA-mb'); await quick('income','cash','10','QA-cash'); await quick('expense','cash','4','QA-gasto')
        rows=sql(f"SELECT CONCAT(description,'=',payment_method) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%' ORDER BY id").split('\n'); print('gravados com o método:', rows, ok(rows==['QA-mb=mbway','QA-cash=cash','QA-gasto=cash']))
        print('\n=== FECHO DO DIA ===')
        c=Client(); c.req('POST','/api/auth.php?action=login',{'email':'maria@teste.pt','password':'123456'}); s,api=c.req('GET','/api/closing.php'); exp=api['totals']['expected_cash']
        await pg.click('#close-day-btn'); await pg.wait_for_selector('.ap-modal-card'); await pg.wait_for_timeout(350)
        shown=(await pg.inner_text('#cl-expected')); print('a janela mostra o dinheiro esperado (servidor =', exp, '):', shown, ok(abs(float(re.sub(r'[^\d,\-]','',shown).replace(',','.'))-exp)<0.01))
        print('   foco no campo do dinheiro contado:', ok(await pg.evaluate("document.activeElement.name")=='counted'))
        await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(250); print('   guardar sem contar: erro:', ok(await pg.is_visible('.ap-modal-card .ap-form-error')))
        await pg.fill('[name=counted]',str(exp-3.5).replace('.',',')); await pg.wait_for_timeout(200); print('   contar 3,50 a menos:', repr(await pg.inner_text('#cl-diff')), ok('Faltam' in await pg.inner_text('#cl-diff') and 'off' in await pg.get_attribute('#cl-diff','class')))
        await pg.fill('[name=counted]',str(exp).replace('.',',')); await pg.wait_for_timeout(200); print('   contar certo:', repr(await pg.inner_text('#cl-diff')), ok('certo' in await pg.inner_text('#cl-diff')))
        await pg.fill('[name=counted]',str(exp+2).replace('.',',')); await pg.fill('[name=note]','QA-nota'); await pg.press('[name=note]','Enter'); await pg.wait_for_timeout(1300)
        row=sql(f"SELECT CONCAT(counted_cash,'|',difference,'|',note) FROM day_closings WHERE user_id={UID}"); print('   gravado (Enter):', row, ok(row.endswith('|2.00|QA-nota')))
        await pg.click('#close-day-btn'); await pg.wait_for_selector('.ap-modal-card'); await pg.wait_for_timeout(300); t=await pg.inner_text('.ap-modal-card'); print('reabrir: indica que já foi fechado, traz os valores e o histórico:', ok('já fechado' in t and await pg.input_value('[name=counted]')!='' and 'Últimos fechos' in t))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(350)
        print('\n=== CONTAS RECORRENTES (interface) ===')
        await nav(pg,'accounts'); await pg.wait_for_timeout(700)
        print('cartão visível na aba Contas:', ok(await pg.is_visible('#recurring-card')), '| vazio:', repr((await pg.inner_text('#recurring-list')).strip()[:40]))
        await pg.fill('#recurring-form [name=title]','QA Renda'); await pg.fill('#recurring-form [name=amount]','500'); await pg.fill('#recurring-form [name=start_date]',sql("SELECT CURDATE()")); await pg.click('#recurring-form button.primary'); await pg.wait_for_timeout(1500)
        print('criada: aparece na lista:', (await pg.inner_text('#recurring-list')).replace('\n',' | ')[:110], ok('QA Renda' in await pg.inner_text('#recurring-list')))
        n=sql(f"SELECT COUNT(*) FROM financial_documents WHERE user_id={UID} AND title='QA Renda' AND status='pending'"); print('   e já gerou a conta pendente em «Contas»:', n, ok(int(n)>=1), '| visível na tabela:', ok('QA Renda' in await pg.inner_text('#bills')))
        await pg.fill('#recurring-form [name=amount]','-5'); await pg.fill('#recurring-form [name=title]','QA Mau'); await pg.fill('#recurring-form [name=start_date]',sql("SELECT CURDATE()")); await pg.click('#recurring-form button.primary'); await pg.wait_for_timeout(600); print('   valor negativo: mensagem de erro:', repr(await pg.inner_text('#recurring-msg')), ok(await pg.inner_text('#recurring-msg')!=''))
        await pg.click('[data-toggle]'); await pg.wait_for_timeout(900); print('   «Pausar» -> «Retomar» e «Em pausa»:', ok('Retomar' in await pg.inner_text('#recurring-list') and 'Em pausa' in await pg.inner_text('#recurring-list')))
        await pg.click('[data-toggle]'); await pg.wait_for_timeout(900); print('   «Retomar» -> volta a «Pausar»:', ok('Pausar' in await pg.inner_text('#recurring-list')))
        await pg.click('[data-del]'); await pg.wait_for_selector('.ux-confirm.show'); print('   «Eliminar» pede confirmação (botão certo):', ok('Eliminar' in await pg.inner_text('.ux-confirm [data-yes]'))); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1200)
        print('   eliminada da lista; as contas já geradas ficam:', ok('Ainda não tens' in await pg.inner_text('#recurring-list') and int(sql(f"SELECT COUNT(*) FROM financial_documents WHERE user_id={UID} AND title='QA Renda'"))>=1))
        await ctx.close(); await b.close()
    print('\nERROS JS:', errs or 'nenhum'); clean()
asyncio.run(main())
