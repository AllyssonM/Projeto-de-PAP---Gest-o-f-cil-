"""Estoque em tempo real: editar (subir/descer), validações, persistência na BD, resumo da visão geral, conflito, sem erros de consola."""
import asyncio, os, subprocess, sys
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8085')
bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
def sql(q): return subprocess.run(['mysql','-uroot','gestao_facil','-N','-e',q],capture_output=True,text=True).stdout.strip()
def qty(name): return int(sql(f"SELECT stock_quantity FROM products WHERE name='{name}' AND user_id=(SELECT id FROM users WHERE email='maria@teste.pt')"))
def card(pg, name): return pg.locator('.product-card', has=pg.locator(f'h3:text-is("{name}")'))
async def main():
    async with async_playwright() as p:
        sql("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','general',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE business_type='general',onboarding_done_at=NOW()")
        b = await p.chromium.launch(); ctx = await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900})
        await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
        pg = await ctx.new_page(); errs = []
        pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'ERR_TUNNEL' not in m.text and '409' not in m.text else None); pg.on('pageerror', lambda e: errs.append(str(e)))
        await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456')
        await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
        await nav(pg, 'stock'); await pg.wait_for_timeout(500)
        print('1) cartões com editor de estoque:', ok(await pg.locator('.stock-editor').count() >= 3))
        c = card(pg, 'Camisola básica'); inp = c.locator('.stock-input')
        print('2) valor mostrado = BD (24):', ok(await inp.input_value() == '24' and qty('Camisola básica') == 24))
        await c.locator('[data-step="1"]').click(); await c.locator('[data-step="1"]').click()
        print('3) + + mostra 26 e botão Guardar, BD ainda 24:', ok(await inp.input_value() == '26' and await c.locator('.stock-save').is_visible() and qty('Camisola básica') == 24))
        await c.locator('.stock-save').click(); await pg.wait_for_timeout(900)
        c = card(pg, 'Camisola básica')
        print('4) aumentar guardou na BD (26) + "Guardado":', ok(qty('Camisola básica') == 26 and 'Guardado' in await c.locator('.stock-feedback').inner_text()))
        print('5) movimento registado (+2):', ok(sql("SELECT quantity FROM stock_movements ORDER BY id DESC LIMIT 1") == '2'))
        inp = c.locator('.stock-input'); await inp.fill('10'); await c.locator('.stock-save').click(); await pg.wait_for_timeout(900)
        print('6) reduzir para 10 guardou (BD=10), movimento -16:', ok(qty('Camisola básica') == 10 and sql("SELECT quantity FROM stock_movements ORDER BY id DESC LIMIT 1") == '-16'))
        c = card(pg, 'Camisola básica'); inp = c.locator('.stock-input')
        for bad_val, label in [('', 'vazio'), ('abc', 'texto'), ('-3', 'negativo'), ('2,5', 'decimal'), ('99999999', 'enorme')]:
            await inp.fill(bad_val); await c.locator('.stock-save').click() if await c.locator('.stock-save').is_visible() else None; await pg.wait_for_timeout(250)
            txt = await c.locator('.stock-feedback').inner_text()
            print(f'7) {label} rejeitado com mensagem, BD intacta:', ok(txt != '' and qty('Camisola básica') == 10))
        await inp.fill('10'); await pg.wait_for_timeout(100)
        await inp.fill('0'); await inp.press('Enter'); await pg.wait_for_timeout(900)
        c = card(pg, 'Camisola básica')
        print('8) 0 permitido (Sem estoque):', ok(qty('Camisola básica') == 0 and 'Sem estoque' in await c.inner_text()))
        # persistência
        await pg.reload(); await pg.wait_for_timeout(1800); await nav(pg, 'stock')
        print('9) depois de recarregar continua 0:', ok(await card(pg, 'Camisola básica').locator('.stock-input').input_value() == '0'))
        # visão geral = BD
        await nav(pg, 'overview'); await pg.wait_for_timeout(300)
        txt = await pg.inner_text('#stock-overview')
        low = int(sql("SELECT COUNT(*) FROM products WHERE user_id=(SELECT id FROM users WHERE email='maria@teste.pt') AND stock_quantity>0 AND stock_quantity<=minimum_stock"))
        out = int(sql("SELECT COUNT(*) FROM products WHERE user_id=(SELECT id FROM users WHERE email='maria@teste.pt') AND stock_quantity<=0"))
        vals = await pg.evaluate("[...document.querySelectorAll('#stock-overview .stock-stat strong')].map(e=>+e.textContent)")
        print(f'10) resumo da visão geral = BD (baixo {low}, sem {out}):', ok(vals[2] == low and vals[3] == out and vals[3] >= 1))
        # conflito: alterar na BD enquanto a página mostra outro valor
        await nav(pg, 'stock'); c = card(pg, 'Perfume X'); await c.locator('.stock-input').fill('7')
        sql("UPDATE products SET stock_quantity=3 WHERE name='Perfume X' AND user_id=(SELECT id FROM users WHERE email='maria@teste.pt')")
        await c.locator('.stock-save').click(); await pg.wait_for_timeout(900)
        print('11) conflito mostra aviso e valor real, não grava:', ok(qty('Perfume X') == 3 and 'mudou' in await card(pg, 'Perfume X').locator('.stock-feedback').inner_text()))
        # atualização automática
        sql("UPDATE products SET stock_quantity=44 WHERE name='Embalagem premium' AND user_id=(SELECT id FROM users WHERE email='maria@teste.pt')")
        await pg.evaluate("GFStock.refresh()"); await pg.wait_for_timeout(900)
        print('12) refresh automático traz 44:', ok(await card(pg, 'Embalagem premium').locator('.stock-input').input_value() == '44'))
        # mobile
        await pg.set_viewport_size({'width':390,'height':844}); await pg.wait_for_timeout(300)
        print('13) mobile sem scroll horizontal:', ok(await pg.evaluate("document.documentElement.scrollWidth<=innerWidth")))
        print('14) sem erros de consola:', ok(not errs), errs[:3])
        await b.close()
    print('\nFALHAS:', bad); sys.exit(1 if bad else 0)
asyncio.run(main())
