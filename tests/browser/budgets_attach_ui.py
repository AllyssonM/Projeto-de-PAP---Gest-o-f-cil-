import os
import sys, asyncio, io; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from navlib import nav
from PIL import Image
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c:'PASSOU' if c else '*** FALHOU ***'
AXE=open(os.environ.get('AXE_JS', 'node_modules/axe-core/axe.min.js')).read()
UID="(SELECT id FROM users WHERE email='maria@teste.pt')"
def clean():
    sql(f"DELETE FROM attachments WHERE user_id={UID}"); sql(f"DELETE FROM budgets WHERE user_id={UID} AND category LIKE 'QA-%'")
    sql(f"DELETE FROM transactions WHERE user_id={UID} AND description LIKE 'QA-%'"); sql(f"DELETE FROM financial_documents WHERE user_id={UID} AND title LIKE 'QA-%'")
def png(w=40,h=30):
    b=io.BytesIO(); Image.new('RGB',(w,h),(30,160,120)).save(b,'PNG'); return b.getvalue()
async def axe(pg):
    await pg.evaluate(AXE)
    return await pg.evaluate("""async()=>{const r=await axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','best-practice']},resultTypes:['violations']});return r.violations.map(v=>v.id+': '+v.help+' ['+v.nodes.slice(0,2).map(n=>n.target.join(' ')).join(' | ')+']')}""")
async def main():
    sql("DELETE FROM login_attempts"); clean(); errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text and '400' not in m.text else None)
        await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1800)
        print('=== ORÇAMENTOS ===')
        await nav(pg,'cashflow'); await pg.wait_for_timeout(800)
        print('cartão «Orçamentos do mês» visível:', ok(await pg.is_visible('#budgets-card')), '| vazio:', (await pg.inner_text('#budget-list')).strip()[:50])
        await pg.click('#budget-form button'); await pg.wait_for_timeout(300)
        await pg.fill('#budget-form [name=category]','QA-Combustível'); await pg.fill('#budget-form [name=monthly_limit]','0'); await pg.evaluate("document.querySelector('#budget-form [name=monthly_limit]').removeAttribute('min')"); await pg.click('#budget-form button'); await pg.wait_for_timeout(700)
        print('limite 0 é recusado com mensagem:', repr((await pg.inner_text('#budget-msg')).strip()[:60]), ok((await pg.inner_text('#budget-msg')).strip()!=''))
        await pg.fill('#budget-form [name=monthly_limit]','100'); await pg.click('#budget-form button'); await pg.wait_for_timeout(1000)
        it=pg.locator('.budget-item', has_text='QA-Combustível'); print('orçamento criado (0 de 100 €), «Dentro do orçamento»:', ok(await it.count()==1 and 'Dentro do orçamento' in await it.inner_text() and 'budget-ok' in (await it.get_attribute('class'))))
        sql(f"INSERT INTO transactions (user_id,type,description,category,amount,status,occurred_at) VALUES ({UID},'expense','QA-gasolina','QA-Combustível',85,'paid',NOW())")
        await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg,'cashflow'); await pg.wait_for_timeout(900); it=pg.locator('.budget-item', has_text='QA-Combustível')
        print('85 € de 100 €: «A acabar», 85 %, barra amarela, progressbar acessível:', ok('A acabar' in await it.inner_text() and '85%' in await it.inner_text() and 'budget-warn' in (await it.get_attribute('class')) and await it.locator('[role=progressbar][aria-valuenow="85"]').count()==1))
        await nav(pg,'overview'); await pg.wait_for_timeout(600); print('   alerta na Visão geral:', ok('«QA-Combustível» a 85%' in await pg.inner_text('#cash-alerts')))
        sql(f"INSERT INTO transactions (user_id,type,description,category,amount,status,occurred_at) VALUES ({UID},'expense','QA-gasolina 2','QA-Combustível',30,'paid',NOW())")
        await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg,'cashflow'); await pg.wait_for_timeout(900); it=pg.locator('.budget-item', has_text='QA-Combustível')
        print('115 € de 100 €: «Ultrapassado», vermelho, «Passaste 15,00 €»:', ok('Ultrapassado' in await it.inner_text() and 'budget-over' in (await it.get_attribute('class')) and 'Passaste' in await it.inner_text()))
        await nav(pg,'overview'); await pg.wait_for_timeout(500); print('   alerta «ultrapassado» na Visão geral:', ok('ultrapassado' in await pg.inner_text('#cash-alerts')))
        await nav(pg,'cashflow'); await pg.wait_for_timeout(500)
        ax=await axe(pg); print('acessibilidade do cartão de orçamentos:', ax or 'sem violações', ok(not ax))
        await pg.click('[data-bdel]'); await pg.wait_for_selector('.ux-confirm.show'); await pg.click('.ux-confirm.show [data-yes]'); await pg.wait_for_timeout(1000)
        print('apagar o orçamento (com confirmação); os movimentos ficam:', ok(await pg.locator('.budget-item', has_text='QA-Combustível').count()==0 and sql(f"SELECT COUNT(*) FROM transactions WHERE user_id={UID} AND description LIKE 'QA-gasolina%'")=='2'))
        print('\n=== ANEXOS (recibos) ===')
        sql(f"INSERT INTO transactions (user_id,type,description,category,amount,status,occurred_at) VALUES ({UID},'expense','QA-compra com recibo','QA-Stock',12.5,'paid',NOW())")
        sql(f"INSERT INTO financial_documents (user_id,direction,title,amount,due_date,status) VALUES ({UID},'payable','QA-renda',500,CURDATE(),'pending')")
        await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg,'cashflow'); await pg.fill('#cf-search','QA-compra'); await pg.wait_for_timeout(800)
        btn=pg.locator('#transactions [data-attach=transaction]').first
        print('botão 📎 em cada movimento, com nome acessível:', await btn.get_attribute('aria-label'), ok((await btn.get_attribute('aria-label')).startswith('Recibos e anexos de QA-compra')))
        await btn.click(); await pg.wait_for_selector('.attach-list'); await pg.wait_for_timeout(600); print('janela «Recibos e anexos» (vazia):', ok('Ainda não há ficheiros' in await pg.inner_text('.attach-list')))
        up='.ap-modal-card input[type=file] >> nth=1'
        await pg.set_input_files(up, files=[{'name':'recibo-farmácia.png','mimeType':'image/png','buffer':png()}]); await pg.wait_for_selector('.attach-item'); await pg.wait_for_timeout(500)
        print('foto anexada: aparece com miniatura que carrega:', ok(await pg.evaluate("(()=>{const i=document.querySelector('.attach-item img');return !!i&&i.complete&&i.naturalWidth>0})()")), '| nome:', await pg.inner_text('.attach-meta a'))
        await pg.set_input_files(up, files=[{'name':'fatura.pdf','mimeType':'application/pdf','buffer':b'%PDF-1.4\n%%EOF\n'}]); await pg.wait_for_timeout(1000)
        print('PDF anexado: ícone «PDF»:', ok(await pg.locator('.attach-item').count()==2 and await pg.locator('.attach-pdf').count()==1))
        await pg.set_input_files(up, files=[{'name':'falso.png','mimeType':'image/png','buffer':b'isto nao e uma imagem'}]); await pg.wait_for_timeout(900)
        print('ficheiro falso: erro visível e não é guardado:', repr((await pg.inner_text('.ap-modal-card .ap-form-error')).strip()[:50]), ok(await pg.locator('.attach-item').count()==2 and (await pg.inner_text('.ap-modal-card .ap-form-error')).strip()!=''))
        await pg.set_input_files(up, files=[{'name':'enorme.pdf','mimeType':'application/pdf','buffer':b'%PDF-'+b'0'*(5*1024*1024+10)}]); await pg.wait_for_timeout(600)
        print('ficheiro acima de 5 MB: recusado antes de enviar:', ok('demasiado grande' in await pg.inner_text('.ap-modal-card .ap-form-error')))
        ax=await axe(pg); print('acessibilidade da janela de anexos:', ax or 'sem violações', ok(not ax))
        await pg.click('.attach-item [data-del] >> nth=0'); await pg.wait_for_function("document.querySelectorAll('.ux-confirm.show').length>=2"); await pg.locator('.ux-confirm.show').last.locator('[data-yes]').click(); await pg.wait_for_timeout(900)
        print('apagar um ficheiro (com confirmação):', ok(await pg.locator('.attach-item').count()==1 and sql(f"SELECT COUNT(*) FROM attachments WHERE user_id={UID}")=='1'))
        await pg.click('.ap-modal-card [data-no]'); await pg.wait_for_timeout(900)
        print('a contagem aparece no botão (📎 1):', await pg.locator('#transactions [data-attach=transaction]').first.inner_text(), ok((await pg.locator('#transactions [data-attach=transaction]').first.inner_text()).strip()=='📎 1'))
        await nav(pg,'accounts'); await pg.wait_for_timeout(800); bb=pg.locator('#bills [data-attach=bill]').first
        print('contas a pagar/receber também têm 📎:', ok(await bb.count()==1))
        await bb.click(); await pg.wait_for_selector('.attach-list'); await pg.set_input_files(up, files=[{'name':'renda.pdf','mimeType':'application/pdf','buffer':b'%PDF-1.4\n%%EOF\n'}]); await pg.wait_for_selector('.attach-item'); await pg.click('.ap-modal-card [data-no]'); await pg.wait_for_timeout(700)
        print('   anexo numa conta guardado:', ok(sql(f"SELECT COUNT(*) FROM attachments WHERE user_id={UID} AND entity='bill'")=='1'))
        sql(f"DELETE FROM transactions WHERE user_id={UID} AND description LIKE 'QA-compra%'")
        print('apagar o movimento (BD) não deixa ficheiros órfãos: (testado na API)')
        await b.close()
    clean(); print('\nERROS JS:', errs or 'nenhum')
asyncio.run(main())
