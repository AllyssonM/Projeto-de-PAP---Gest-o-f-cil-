import os
from navlib import nav
import sys, asyncio, json; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import Client, sql
from engine_ui import set_profile, login
ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/')
def api_client(email='maria@teste.pt',pw='123456'):
    c=Client(); s,d=c.req('POST','/api/auth.php?action=login',{'email':email,'password':pw}); c.csrf=d.get('csrf',''); return c
def last_attrs(name): return sql(f"SELECT attributes FROM products WHERE name='{name}' ORDER BY id DESC LIMIT 1")
async def main():
    sql("DELETE FROM products WHERE user_id=(SELECT id FROM users WHERE email='maria@teste.pt')")
    set_profile('real_estate')
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
        pg=await ctx.new_page(); errs=[]; pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg); await nav(pg,'stock'); await pg.wait_for_timeout(500)
        print('=== 1) CORRETOR IMOBILIÁRIO: criar um imóvel pela interface ===')
        f='#product-form '
        await pg.fill(f+'[name=name]','Apartamento T2 em Marrazes'); await pg.fill(f+'[name=sku]','IMO-001'); await pg.fill(f+'[name=category]','Venda')
        await pg.fill(f+'[name=cost_price]','120000'); await pg.fill(f+'[name=sale_price]','165000')
        await pg.select_option('#attr-tipologia','T2'); await pg.fill('#attr-area','85'); await pg.fill('#attr-localizacao','Leiria, Marrazes'); await pg.select_option('#attr-estado','Disponível'); await pg.fill('#attr-comissao','5')
        print('   campos de quantidade escondidos (um imóvel é único):', ok(await pg.evaluate("[...document.querySelectorAll('#product-form [data-stock-only]')].every(l=>l.offsetParent===null)")))
        await pg.click(f+'button.button.primary'); await pg.wait_for_timeout(1500)
        card=await pg.evaluate("(()=>{const c=[...document.querySelectorAll('.product-card')].find(x=>x.textContent.includes('Marrazes'));return c?{text:c.innerText.replace(/\\n+/g,' | '),chips:[...c.querySelectorAll('.attr-chip')].map(x=>x.textContent),state:c.querySelector('.attr-status')?.value,cls:c.querySelector('.attr-status')?.className}:null})()")
        print('   cartão criado:', card['text'] if card else None)
        print('   etiquetas dos campos do ramo:', card['chips'], ok(card and set(card['chips'])=={'T2','85 m²','Leiria, Marrazes','5%'}), '| estado:', card['state'], ok(card['state']=='Disponível'), '| "Preço pedido" em vez de "Preço de venda":', ok('Preço pedido' in card['text'] and '165' in card['text']))
        print('   guardado na base de dados como JSON:', last_attrs('Apartamento T2 em Marrazes'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ramo_imovel.png', clip={'x':60,'y':150,'width':1250,'height':700})
        print('\n=== 2) mudar o estado diretamente no cartão (Disponível -> Reservado) ===')
        await pg.select_option('.product-card:has-text("Marrazes") .attr-status','Reservado'); await pg.wait_for_timeout(1500)
        print('   na base de dados:', json.loads(last_attrs('Apartamento T2 em Marrazes'))['estado'], ok(json.loads(last_attrs('Apartamento T2 em Marrazes'))['estado']=='Reservado'), '| os outros campos mantiveram-se:', ok(json.loads(last_attrs('Apartamento T2 em Marrazes')).get('tipologia')=='T2'))
        await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg,'stock'); await pg.wait_for_timeout(500)
        st=await pg.evaluate("(()=>{const s=document.querySelector('.product-card:has(.attr-chip) .attr-status');return [s.value,s.className]})()")
        print('   depois de atualizar a página continua:', st[0], ok(st[0]=='Reservado'), '| cor própria do estado:', st[1])
        print('\n=== 3) HTML malicioso nos campos NÃO executa ===')
        await pg.fill(f+'[name=name]','<img src=x onerror=window.__x=1>'); await pg.fill(f+'[name=cost_price]','1'); await pg.fill(f+'[name=sale_price]','2'); await pg.fill('#attr-localizacao','<script>window.__y=1</script>'); await pg.click(f+'button.button.primary'); await pg.wait_for_timeout(1500)
        x=await pg.evaluate("({x:window.__x,y:window.__y,imgs:document.querySelectorAll('.product-card img').length,shown:[...document.querySelectorAll('.product-card h3')].some(h=>h.textContent.includes('<img'))})")
        print('   nada executou:', ok(x['x'] is None and x['y'] is None and x['imgs']==0), '| o texto aparece como texto:', ok(x['shown']))
        print('\n=== 4) A API recusa/limpa campos perigosos ===')
        c=api_client(); body={'name':'Teste atributos','cost_price':1,'sale_price':2,'stock_quantity':1,'minimum_stock':0,'csrf':c.csrf,
          'attributes':{'tipologia':'T3','Chave Invalida':'x','__proto__':'x','aninhado':{'a':1},'lista':[1,2],'longo':'L'*500,'ctrl':'a\x00b\x07c','vazio':'  ', **{f'campo{i}':str(i) for i in range(20)}}}
        s,r=c.req('POST','/api/data.php?module=products',body); a=json.loads(last_attrs('Teste atributos')) if s==201 else {}
        print('   guardou (201):', ok(s==201), '| máximo 12 campos:', ok(len(a)<=12), f'({len(a)})', '| chaves inválidas e aninhados descartados:', ok(not any(k in a for k in ['Chave Invalida','__proto__','aninhado','lista','vazio'])), '| valores até 120 caracteres:', ok(all(len(v)<=120 for v in a.values())), '| controlo removido:', repr(a.get('ctrl')))
        s,r=c.req('POST','/api/data.php?module=product_attr',{'id':999999,'attributes':{'estado':'x'},'csrf':c.csrf}); print('   atualizar produto que não existe ->', s, ok(s==404))
        other=api_client('outra@teste.pt','abcdef'); pid=sql("SELECT id FROM products WHERE name='Apartamento T2 em Marrazes'")
        s,r=other.req('POST','/api/data.php?module=product_attr',{'id':int(pid),'attributes':{'estado':'Vendido'},'csrf':other.csrf}); print('   outro negócio tenta mudar o estado deste imóvel ->', s, ok(s==404), '| estado intacto:', ok(json.loads(last_attrs('Apartamento T2 em Marrazes'))['estado']=='Reservado'))
        print('\n=== 5) BARBEIRO: o produto é um serviço ===')
        set_profile('barber'); await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg,'stock'); await pg.wait_for_timeout(500)
        await pg.fill(f+'[name=name]','Corte clássico'); await pg.fill(f+'[name=cost_price]','2'); await pg.fill(f+'[name=sale_price]','15'); await pg.select_option('#attr-tipo','Corte'); await pg.fill('#attr-duracao','30'); await pg.fill('#attr-profissional','João'); await pg.click(f+'button.button.primary'); await pg.wait_for_timeout(1500)
        row=sql("SELECT CONCAT(stock_quantity,'/',minimum_stock,'/',attributes) FROM products WHERE name='Corte clássico'"); print('   serviço guardado (quantidade/mínimo/campos):', row, ok(row.startswith('1/0/')))
        print('   cartão:', await pg.evaluate("[...document.querySelectorAll('.product-card')].find(c=>c.textContent.includes('Corte'))?.innerText.replace(/\\n+/g,' | ')"))
        print('   ERROS JS:', errs or 'nenhum'); await b.close()
    print('\n=== 6) CHEFE × FUNCIONÁRIO: só o chefe elimina ===')
    owner=api_client(); emp=api_client('fin@teste.pt','123456')
    s,r=owner.req('POST','/api/data.php?module=bills',{'direction':'payable','title':'Conta para eliminar','amount':10,'due_date':'2026-12-01','csrf':owner.csrf}); bid=r.get('id')
    s1,r1=emp.req('DELETE',f'/api/data.php?module=bills&id={bid}',{'csrf':emp.csrf}); print('   funcionário tenta eliminar uma conta ->', s1, r1.get('error'), ok(s1==403), '| a conta continua:', ok(sql(f"SELECT COUNT(*) FROM financial_documents WHERE id={bid}")=='1'))
    s2,r2=owner.req('DELETE',f'/api/data.php?module=bills&id={bid}',{'csrf':owner.csrf}); print('   o chefe elimina ->', s2, ok(s2==200 and r2.get('deleted')==1))
    s3,r3=emp.req('POST','/api/data.php?module=bills',{'direction':'payable','title':'Conta do funcionário','amount':25,'due_date':'2026-12-01','csrf':emp.csrf}); print('   o funcionário REGISTA contas ->', s3, ok(s3==201)); sql("DELETE FROM financial_documents WHERE title='Conta do funcionário'")
    s4,r4=emp.req('POST','/api/data.php?module=products',{'name':'Produto do funcionário','cost_price':1,'sale_price':2,'stock_quantity':1,'minimum_stock':0,'csrf':emp.csrf}); print('   o funcionário REGISTA produtos ->', s4, ok(s4==201)); sql("DELETE FROM products WHERE name='Produto do funcionário'")
    s5,r5=emp.req('GET','/api/data.php?module=summary'); print('   o funcionário (sem Fluxo de caixa) vê os totais? ->', s5, ok(s5==403))
    set_profile('general')
asyncio.run(main())
