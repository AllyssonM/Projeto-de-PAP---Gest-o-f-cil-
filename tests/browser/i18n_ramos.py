"""Rastreio por ramo (imóveis, viaturas, TVDE...): cada ramo muda os textos de Estoque/Clientes/Painel do ramo. Recolhe o que falta traduzir. CRAWL_LANG=en|es"""
import asyncio, os, sys, json, subprocess
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
import i18n_crawl as C
from navlib import nav
RAMOS = ['real_estate', 'cars', 'uber', 'rider', 'barber', 'online_store', 'clothing', 'restaurant', 'mechanic', 'freelancer', 'personal', 'general']
def sql(q): return subprocess.run(['mysql','-uroot','gestao_facil','-N','-e',q],capture_output=True,text=True).stdout.strip()
async def main():
    per = {}
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for r in RAMOS:
            sql(f"INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Negócio QA','{r}',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE business_type='{r}', onboarding_done_at=NOW()")
            C.seen.clear()
            ctx = await b.new_context(viewport={'width': 1366, 'height': 900})
            await ctx.add_init_script(f"localStorage.setItem('lumina-lang','{C.LANG}');localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1');window.LUMINA_I18N_DEBUG=true;")
            await ctx.route('**/*', C.block_ext)
            pg = await ctx.new_page(); pg.set_default_timeout(4000); pg.on('dialog', lambda d: asyncio.ensure_future(d.dismiss()))
            await C.login(pg, 'maria@teste.pt'); await C.grab(pg, r)
            for s in ['overview', 'stock', 'clients', 'insights', 'cashflow', 'accounts']:
                try:
                    await pg.goto(C.BASE + '/dashboard.php'); await pg.wait_for_timeout(900); await nav(pg, s); await C.sweep(pg, f'{r}:{s}')
                except Exception as e: print('ERRO', r, s, str(e)[:70])
            per[r] = dict(C.seen); await ctx.close()
            json.dump(per, open(f'/tmp/ramos_{C.LANG}.json', 'w'), ensure_ascii=False)
            print(r, len(per[r]), flush=True)
        await b.close()
    sql("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','clothing',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE business_type='clothing'")
asyncio.run(main())
