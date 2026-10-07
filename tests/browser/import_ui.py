import os
import sys, asyncio, datetime; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c:'PASSOU' if c else '*** FALHOU ***'
UID="(SELECT id FROM users WHERE email='maria@teste.pt')"
def csv_bank():
    t=datetime.date.today(); d=lambda n: f"{n:02d}/{t.month:02d}/{t.year}"
    txt=f"Data mov.;Descrição;Débito;Crédito;Saldo\n{d(1)};QA-Compra Pingo Doce;45,30;;1.000,00\n{d(2)};QA-Venda balcão;;120,00;1.120,00\n{d(3)};QA-Café;1,20;;1.118,80\n{d(3)};QA-Café;1,20;;1.117,60\n99/99/{t.year};QA-data errada;9,00;;0\n32/13/{t.year};QA-Mau;5,00;;0\n"
    return txt.encode('cp1252')
def clean(): sql(f"DELETE FROM transactions WHERE user_id={UID} AND (description LIKE 'QA-%' OR import_batch IS NOT NULL)")
async def main():
    sql("DELETE FROM login_attempts"); clean(); errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text and '400' not in m.text else None)
        await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1800)
        await nav(pg,'cashflow'); await pg.wait_for_timeout(700)
        print('botão «Importar CSV» ao lado de «Exportar CSV»:', ok(await pg.is_visible('#cf-import') and await pg.is_visible('#cf-export')))
        await pg.click('#cf-import'); await pg.wait_for_selector('.ap-modal-card'); await pg.wait_for_timeout(300)
        print('janela abre com instruções e o botão de importar escondido:', ok('Escolher ficheiro CSV' in await pg.inner_text('.ap-modal-card') and not await pg.is_visible('.ap-modal-card [data-yes]')))
        print('\n=== PASSO 2: PRÉ-VISUALIZAÇÃO ===')
        await pg.set_input_files('.ap-modal-card input[type=file]', files=[{'name':'extrato.csv','mimeType':'text/csv','buffer':csv_bank()}]); await pg.wait_for_selector('.imp-sum'); await pg.wait_for_timeout(500)
        sm=await pg.inner_text('.imp-sum'); print('resumo:', sm.replace('\n',' '), ok('4 novos' in sm and '0 já existem' in sm and '2 com erro' in sm))
        sel=await pg.evaluate("Object.fromEntries([...document.querySelectorAll('.imp-map select[name]')].map(s=>[s.name,s.options[s.selectedIndex].text]))")
        print('colunas sugeridas:', sel, ok(sel['date']=='Data mov.' and sel['description']=='Descrição' and sel['debit']=='Débito' and sel['credit']=='Crédito' and 'nenhuma' in sel['amount']))
        tb=await pg.inner_text('.imp-table'); print('acentos certos (Windows-1252) e tipos:', ok('Descrição' in await pg.inner_text('.ap-modal-card') and 'QA-Venda balcão' in tb and 'Entrada' in tb and 'Saída' in tb))
        print('lista de erros com a linha e o motivo:', ok('data inválida' in await pg.inner_text('.imp-errs') and 'Linha' in await pg.inner_text('.imp-errs')))
        print('botão: «Importar 4 movimentos»:', await pg.inner_text('.ap-modal-card [data-yes]'), ok('Importar 4 movimentos' == (await pg.inner_text('.ap-modal-card [data-yes]')).strip()))
        print('nada gravado ainda:', sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'"), ok(sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'")=='0'))
        await pg.select_option('.imp-map [name=debit]','-1'); await pg.wait_for_timeout(900); sm=await pg.inner_text('.imp-sum')
        print('tirar a coluna «Débito» refaz a pré-visualização (só ficam as entradas):', sm.replace('\n',' '), ok('1 novo' in sm))
        await pg.select_option('.imp-map [name=debit]','2'); await pg.wait_for_timeout(900)
        await pg.select_option('.imp-opts [name=sign_mode]','expense'); await pg.wait_for_timeout(900); tb=await pg.inner_text('.imp-table'); print('«Tudo são despesas»: todas as linhas viram Saída:', ok('Entrada' not in tb))
        await pg.select_option('.imp-opts [name=sign_mode]','auto'); await pg.fill('.imp-opts [name=default_category]','Banco'); await pg.press('.imp-opts [name=default_category]','Tab'); await pg.wait_for_timeout(900)
        print('\n=== PASSO 3: IMPORTAR ===')
        await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_selector('.imp-done'); await pg.wait_for_timeout(900)
        dn=await pg.inner_text('.imp-done'); print('concluída:', dn.replace('\n',' ')[:120], ok('4 movimentos importados' in dn and '2 linhas com erro' in dn and 'Anular' in dn))
        n=sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%' AND import_batch IS NOT NULL AND status='paid' AND category='Banco'"); print('na BD: 4 pagos, na categoria «Banco», com identificador:', n, ok(n=='4'))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(600); await pg.fill('#cf-search','QA-'); await pg.wait_for_timeout(700)
        print('os movimentos aparecem na tabela do Fluxo de caixa:', ok('QA-Venda balcão' in await pg.inner_text('#transactions') and 'QA-Compra Pingo Doce' in await pg.inner_text('#transactions')))
        print('\n=== DUPLICADOS E ANULAR ===')
        await pg.fill('#cf-search',''); await pg.click('#cf-import'); await pg.wait_for_selector('.ap-modal-card'); await pg.set_input_files('.ap-modal-card input[type=file]', files=[{'name':'extrato.csv','mimeType':'text/csv','buffer':csv_bank()}]); await pg.wait_for_selector('.imp-sum'); await pg.wait_for_timeout(600)
        sm=await pg.inner_text('.imp-sum'); print('o MESMO ficheiro outra vez: «4 já existem», 0 novos:', sm.replace('\n',' '), ok('0 novos' in sm and '4 já existem' in sm))
        print('   e o botão de importar fica desativado:', ok(await pg.is_disabled('.ap-modal-card [data-yes]')))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(500)
        await pg.click('#cf-import'); await pg.wait_for_selector('.ap-modal-card'); await pg.set_input_files('.ap-modal-card input[type=file]', files=[{'name':'extrato.csv','mimeType':'text/csv','buffer':csv_bank().replace(b'QA-Compra Pingo Doce',b'QA-Compra Lidl')}]); await pg.wait_for_selector('.imp-sum'); await pg.wait_for_timeout(600)
        sm=await pg.inner_text('.imp-sum'); print('ficheiro com 1 linha nova: «1 novo, 3 já existem»:', sm.replace('\n',' '), ok('1 novo' in sm and '3 já existem' in sm))
        await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_selector('#imp-undo'); await pg.wait_for_timeout(500)
        await pg.click('#imp-undo'); await pg.wait_for_function("document.querySelectorAll('.ux-confirm.show').length>=2"); cf=pg.locator('.ux-confirm.show').last; print('«Anular» pede confirmação (diálogo por cima do assistente):', ok('Anular importação' in await cf.locator('[data-yes]').inner_text())); await cf.locator('[data-yes]').click(); await pg.wait_for_timeout(1000)
        print('anulada: só o movimento dessa importação sai (os outros 4 ficam):', sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'"), ok(sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'")=='4' and 'anulada' in (await pg.inner_text('.imp-done')).lower()))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(400)
        print('\n=== FICHEIROS PROBLEMÁTICOS ===')
        async def tryfile(name, buf, expect):
            await pg.click('#cf-import'); await pg.wait_for_selector('.ap-modal-card'); await pg.set_input_files('.ap-modal-card input[type=file]', files=[{'name':name,'mimeType':'text/csv','buffer':buf}]); await pg.wait_for_timeout(1300)
            t=await pg.inner_text('.ap-modal-card'); r=ok(expect in t); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(400); return r, t
        r,_=await tryfile('vazio.csv', b'', 'vazio'); print('ficheiro vazio:', r)
        r,_=await tryfile('foto.csv', bytes(range(0,64))*20, 'binários'); print('ficheiro binário disfarçado de CSV:', r)
        r,_=await tryfile('grande.csv', ('Data;Valor\n'+'01/10/2026;1\n'*2100).encode(), '2000'); print('mais de 2000 linhas:', r)
        await pg.click('#cf-import'); await pg.wait_for_selector('.ap-modal-card'); await pg.set_input_files('.ap-modal-card input[type=file]', files=[{'name':'x.csv','mimeType':'text/csv','buffer':b'Foo;Bar;Baz\n01/10/2026;QA-x;5\n'}]); await pg.wait_for_selector('.imp-map'); await pg.wait_for_timeout(500)
        print('títulos desconhecidos: pede para escolher as colunas e o botão fica desativado:', ok('Escolhe qual é a coluna da data' in await pg.inner_text('.ap-modal-card') and await pg.is_disabled('.ap-modal-card [data-yes]')))
        await pg.select_option('.imp-map [name=date]','0'); await pg.wait_for_timeout(700); await pg.select_option('.imp-map [name=description]','1'); await pg.wait_for_timeout(700); await pg.select_option('.imp-map [name=amount]','2'); await pg.wait_for_timeout(900)
        print('   escolhendo Data, Descrição e Valor, já pré-visualiza e ativa:', ok('1 novo' in await pg.inner_text('.imp-sum') and not await pg.is_disabled('.ap-modal-card [data-yes]')))
        print('\n=== TECLADO / ACESSIBILIDADE ===')
        print('a janela tem título e os campos têm rótulo:', ok(await pg.evaluate("document.querySelector('.ap-modal-card').closest('[role=dialog]')?.getAttribute('aria-labelledby')!==null") and await pg.evaluate("[...document.querySelectorAll('.ap-modal-card select, .ap-modal-card input')].every(i=>i.closest('label')||i.getAttribute('aria-label'))")))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(400); print('Esc fecha a janela:', ok(await pg.locator('.ap-modal-card').count()==0))
        await b.close()
    clean(); print('\nERROS JS:', errs or 'nenhum')
asyncio.run(main())
