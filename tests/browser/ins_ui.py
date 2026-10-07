import os
from navlib import nav
import sys, asyncio; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql
from engine_ui import set_profile
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
async def login(pg,email,pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1700)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
async def newpg(b,errs):
    ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
    pg=await ctx.new_page(); pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None); return ctx,pg
async def tab(pg): return await pg.evaluate("(()=>{const t=document.querySelector('.nav-tab[data-section=insights]');return t&&!t.classList.contains('ramo-off')?t.textContent.trim():null})()")
async def main():
    sql("DELETE FROM sales"); sql("DELETE FROM trips"); sql("DELETE FROM login_attempts"); sql("DELETE FROM calendar_events WHERE event_date=CURDATE()"); sql("UPDATE users SET preferences=NULL")
    sql("UPDATE users SET permissions='[\"accounts\",\"stock\",\"calendar\"]' WHERE email='fin@teste.pt'")
    errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch()
        print('=== O SEPARADOR SÓ APARECE NOS RAMOS COM PAINEL ===')
        for ramo,exp in [('general',None),('personal',None),('real_estate',None),('restaurant','Mais pedidos'),('clothing','Produtos mais vendidos'),('uber','Distância e custo-benefício'),('rider','Distância e custo-benefício')]:
            set_profile(ramo); ctx,pg=await newpg(b,errs); await login(pg,'maria@teste.pt'); t=await tab(pg); print(f'   {ramo:12s} -> separador:', t, ok(t==exp)); await ctx.close()
        print('\n=== RESTAURANTE: "Mais pedidos" ===')
        set_profile('restaurant'); ctx,pg=await newpg(b,errs); await login(pg,'maria@teste.pt'); await nav(pg,'insights'); await pg.wait_for_timeout(1200)
        print('título:', await pg.inner_text('#ins-title'), '| sem dados -> convite:', ok('Carregar dados de exemplo' in await pg.inner_text('#ins-demo')), '| estado vazio:', ok('Ainda não há dados' in await pg.inner_text('#ins-body')))
        await pg.click('#ins-demo-load'); await pg.wait_for_timeout(1800)
        await pg.click('[data-period=custom]'); await pg.fill('#ins-from','2026-08-01'); await pg.fill('#ins-to','2026-12-31'); await pg.dispatch_event('#ins-to','change'); await pg.wait_for_timeout(1800)
        print('exemplos carregados -> etiqueta "Dados de exemplo":', ok('Dados de exemplo' in await pg.inner_text('#ins-demo')))
        hero=(await pg.inner_text('.ins-hero')).replace('\n',' | '); print('cartão principal:', hero, ok('unidades vendidas' in hero and 'Receita gerada' in hero))
        k=(await pg.inner_text('.ins-kpis')).replace('\n',' | '); print('indicadores:', k[:200], ok('Horário com mais pedidos' in k and 'Categoria mais vendida' in k))
        bars=await pg.evaluate("[...document.querySelectorAll('.ins-bars')][0] ? [...document.querySelectorAll('.ins-bars')][0].querySelectorAll('li').length : 0"); w=await pg.evaluate("[...document.querySelectorAll('.ins-bar i')].map(i=>Math.round(i.getBoundingClientRect().width))"); print('gráfico: barras =', bars, '| larguras (px, animadas):', w[:5], ok(bars==5 and all(x>0 for x in w[:5]) and w[0]>=w[4]))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ins_orders.png', full_page=True)
        for per,exp in [('day','hoje'),('week','esta semana'),('month','este mês')]:
            await pg.click(f'[data-period={per}]'); await pg.wait_for_timeout(1200); print(f'   período {per}: intervalo', (await pg.inner_text('#ins-range')).strip(), '| botão ativo:', ok(await pg.get_attribute(f'[data-period={per}]','aria-pressed')=='true'))
        await pg.click('[data-period=custom]'); await pg.wait_for_timeout(1200)
        print('registar um pedido:', end=' '); await pg.fill('#sale-form [name=product_name]','Francesinha'); await pg.fill('#sale-form [name=quantity]','3'); await pg.fill('#sale-form [name=amount]','35.70'); await pg.click('#sale-form button.primary'); await pg.wait_for_timeout(1500)
        print(sql("SELECT CONCAT(product_name,' x',quantity,' = ',amount) FROM sales WHERE is_demo=0 ORDER BY id DESC LIMIT 1"), ok(sql("SELECT COUNT(*) FROM sales WHERE is_demo=0")=='1'), '| aviso:', await pg.evaluate("[...document.querySelectorAll('.ux-toast')].map(t=>t.innerText.replace(/\\n×/,'').trim()).slice(-1)"))
        await pg.fill('#sale-form [name=quantity]','0'); await pg.fill('#sale-form [name=product_name]','X'); await pg.fill('#sale-form [name=amount]','1'); await pg.click('#sale-form button.primary'); await pg.wait_for_timeout(300); print('   quantidade 0 ->', (await pg.inner_text('#sale-form .ap-form-error')).strip(), ok(await pg.is_visible('#sale-form .ap-form-error')))
        print('\n=== PRIVACIDADE: valores ocultos também aqui ===')
        await pg.click('.icon-btn[data-privacy-toggle]'); await pg.wait_for_timeout(700); txt=await pg.inner_text('#ins-body'); print('valores em € visíveis no painel:', '€' in txt, ok('€' not in txt), '| títulos mantidos:', ok('Receita' in txt)); await pg.click('.icon-btn[data-privacy-toggle]'); await pg.wait_for_timeout(500)
        print('\n=== REMOVER EXEMPLOS ===')
        await pg.click('#ins-demo-clear'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1500); print('exemplos removidos:', sql("SELECT COUNT(*) FROM sales WHERE is_demo=1"), '| o pedido real ficou:', ok(sql("SELECT COUNT(*) FROM sales WHERE is_demo=0")=='1'))
        await ctx.close()
        print('\n=== LOJA DE ROUPAS ===')
        sql("DELETE FROM sales"); set_profile('clothing'); ctx,pg=await newpg(b,errs); await login(pg,'maria@teste.pt'); await nav(pg,'insights'); await pg.wait_for_timeout(1000); await pg.click('#ins-demo-load'); await pg.wait_for_timeout(1500); await pg.click('[data-period=custom]'); await pg.fill('#ins-from','2026-08-01'); await pg.fill('#ins-to','2026-12-31'); await pg.dispatch_event('#ins-to','change'); await pg.wait_for_timeout(1800)
        k=(await pg.inner_text('.ins-kpis')).replace('\n',' | '); print('indicadores:', k[:230], ok(all(x in k for x in ['Marca mais vendida','Tamanho mais procurado','Cor mais escolhida'])))
        print('filtros:', await pg.evaluate("[...document.querySelectorAll('[data-filter]')].map(s=>s.dataset.filter)"), ok(await pg.locator('[data-filter]').count()==3))
        await pg.select_option('[data-filter=brand]','Nike'); await pg.wait_for_timeout(1500); print('   marca Nike:', await pg.evaluate("[...document.querySelectorAll('.ins-box')][1]?.innerText.replace(/\\n/g,' | ').slice(0,90)"))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ins_sales.png', full_page=True); await ctx.close()
        print('\n=== MOTORISTA ===')
        set_profile('uber'); ctx,pg=await newpg(b,errs); await login(pg,'maria@teste.pt'); await nav(pg,'insights'); await pg.wait_for_timeout(1000); await pg.click('#ins-demo-load'); await pg.wait_for_timeout(1500); await pg.click('[data-period=custom]'); await pg.fill('#ins-from','2026-08-01'); await pg.fill('#ins-to','2026-12-31'); await pg.dispatch_event('#ins-to','change'); await pg.wait_for_timeout(1800)
        hero=(await pg.inner_text('.ins-hero')).replace('\n',' | '); print('principal:', hero[:120], ok('km' in hero))
        k=(await pg.inner_text('.ins-kpis')).replace('\n',' | '); print('indicadores:', k[:260], ok(all(x in k for x in ['Consumo médio','Combustível gasto','Custo por quilómetro','Receita por viagem'])))
        t=await pg.inner_text('#ins-body'); print('melhor relação distância/custo:', ok('Melhor relação distância/custo' in t and 'Leiria' in t), '| nota de privacidade (sem GPS):', ok('Sem GPS' in t))
        await pg.fill('#trip-form [name=route]','Leiria–Fátima'); await pg.fill('#trip-form [name=distance_km]','26'); await pg.fill('#trip-form [name=fuel_cost]','2.2'); await pg.click('#trip-form button.primary'); await pg.wait_for_timeout(1500); print('registar viagem:', sql("SELECT CONCAT(route,' ',distance_km,'km') FROM trips WHERE is_demo=0"), ok(sql("SELECT COUNT(*) FROM trips WHERE is_demo=0")=='1'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ins_trips.png', full_page=True); await ctx.close()
        print('\n=== FUNCIONÁRIO DE ENTRADA DE DADOS (Estoque, sem Fluxo de caixa) ===')
        set_profile('restaurant'); ctx,pg=await newpg(b,errs); await login(pg,'fin@teste.pt'); await nav(pg,'insights'); await pg.wait_for_timeout(1200)
        print('vê o aviso em vez dos totais:', ok('só os vê o administrador' in await pg.inner_text('#ins-noview') and not await pg.is_visible('#ins-body')), '| pode registar:', ok(await pg.is_visible('#sale-form')))
        await pg.fill('#sale-form [name=product_name]','Sumo Natural'); await pg.fill('#sale-form [name=amount]','3.2'); await pg.click('#sale-form button.primary'); await pg.wait_for_timeout(1300); print('registou:', ok(sql("SELECT COUNT(*) FROM sales WHERE product_name='Sumo Natural' AND is_demo=0")=='1'))
        await ctx.close(); print('\nERROS JS:', [e for e in errs if 'net::' not in e] or 'nenhum'); await b.close()
    set_profile('general'); sql("DELETE FROM sales"); sql("DELETE FROM trips")
asyncio.run(main())
