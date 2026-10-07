"""Rastreio de idiomas: abre o site no idioma LANG (en|es) com o coletor de textos ligado e lista tudo o que NÃO foi traduzido.
Uso: LANG=en python3 i18n_crawl.py [--dict-off]  (--dict-off: sem dicionário, lista TODOS os textos = extração)  Saída: /tmp/i18n_missing_<lang>.json"""
import asyncio, os, sys, json
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086'); LANG = os.environ.get('CRAWL_LANG', 'en')
OUT = os.environ.get('CRAWL_OUT', f'/tmp/i18n_missing_{LANG}.json')
seen = {}
def dump():
    json.dump(seen, open(OUT, 'w'), ensure_ascii=False, indent=0)
async def grab(pg, where):
    try: items = await pg.evaluate("[...(window.LUMINA_I18N_MISSING||[])]")
    except Exception: return
    for s in items: seen.setdefault(s, where)
    dump()
async def block_ext(route):
    u=route.request.url
    if u.startswith(BASE) or u.startswith('data:'): await route.continue_()
    else: await route.abort()
async def newpage(ctx):
    pg = await ctx.new_page(); return pg
async def login(pg, email, pw='123456'):
    await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(700)
    await pg.fill('#login-form [name=email]', email); await pg.fill('#login-form [name=password]', pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
POKE = """()=>{const out=[];document.querySelectorAll('form').forEach(f=>{if(f.offsetParent===null)return;f.noValidate=true;const b=f.querySelector('[type=submit]');if(b&&!/logout|sair/i.test(b.textContent)){try{f.requestSubmit()}catch(e){}}});return out}"""
async def sweep(pg, where):
    """Clica nos botões seguros (abrir janelas, alternar vistas) e dispara validações; recolhe os textos depois de cada passo."""
    await pg.wait_for_timeout(600); await grab(pg, where)
    try: await pg.evaluate(POKE)
    except Exception: pass
    await pg.wait_for_timeout(700); await grab(pg, where + ' (validação)')
    n = await pg.locator('main button:visible, .section.active button:visible').count()
    for i in range(min(n, 25)):
        try:
            b = pg.locator('main button:visible, .section.active button:visible').nth(i)
            t = (await b.inner_text(timeout=500)).strip().lower()
            if re.search(r'elimin|apag|remov|termin|sair|fechar o dia|limpar|reiniciar|exemplo|desligar|import|encerr|cancelar convite|revog', t): continue
            await b.click(timeout=600, no_wait_after=True); await pg.wait_for_timeout(250); await grab(pg, where + ' [' + t[:20] + ']')
            await pg.keyboard.press('Escape'); await pg.wait_for_timeout(120)
        except Exception: pass
import re
async def main():
    dictoff = '--dict-off' in sys.argv
    async with async_playwright() as p:
        b = await p.chromium.launch(); ctx = await b.new_context(viewport={'width': 1366, 'height': 900})
        await ctx.add_init_script(f"localStorage.setItem('lumina-lang','{LANG}');localStorage.setItem('lumina-aviso-cookies','1');window.LUMINA_I18N_DEBUG=true;")
        if dictoff: await ctx.route('**/assets/i18n/*.json*', lambda r: r.fulfill(status=200, body='{}', content_type='application/json'))
        # público
        await ctx.route('**/*', block_ext)
        pg = await ctx.new_page(); pg.on('dialog', lambda d: asyncio.ensure_future(d.dismiss()))
        await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(1500); await grab(pg, 'landing')
        for m in ('login', 'register'):
            try: await pg.click(f'[data-open-mode={m}]', timeout=1500); await pg.wait_for_timeout(500); await grab(pg, 'auth ' + m)
            except Exception: pass
        await sweep(pg, 'landing')
        for u in ('politica-privacidade', 'politica-cookies', 'termos-e-condicoes', 'informacao-legal', 'esqueci-palavra-passe', 'redefinir-palavra-passe', 'verificar-email', 'offline.html'):
            try: await pg.goto(f'{BASE}/{u}' + ('' if '.' in u else '.php')); await pg.wait_for_timeout(800); await grab(pg, u); await sweep(pg, u)
            except Exception as e: print('ERRO', u, e)
        await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(500)
        # login com erros
        await pg.fill('#login-form [name=email]', 'x@x.pt'); await pg.fill('#login-form [name=password]', 'errada'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_timeout(1200); await grab(pg, 'login erro')
        for who, email in (('dono', 'maria@teste.pt'), ('func-calendario', 'cal@teste.pt'), ('func-contas', 'fin@teste.pt'), ('func-clientes', 'cli@teste.pt'), ('func-caixa', 'cf@teste.pt')):
            c2 = await b.new_context(viewport={'width': 1366, 'height': 900}); 
            await c2.add_init_script(f"localStorage.setItem('lumina-lang','{LANG}');localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1');window.LUMINA_I18N_DEBUG=true;")
            if dictoff: await c2.route('**/assets/i18n/*.json*', lambda r: r.fulfill(status=200, body='{}', content_type='application/json'))
            await c2.route('**/*', block_ext)
            pg = await c2.new_page(); pg.on('dialog', lambda d: asyncio.ensure_future(d.dismiss()))
            await login(pg, email); await grab(pg, who + ' painel')
            secs = await pg.evaluate("[...document.querySelectorAll('#main-menu [data-section]')].map(t=>t.dataset.section)")
            pg.set_default_timeout(4000)
            for s in secs:
                try:
                    await pg.goto(BASE + '/dashboard.php'); await pg.wait_for_timeout(1000)
                    await nav(pg, s); await sweep(pg, f'{who}:{s}')
                except Exception as e: print('ERRO nav', who, s, str(e)[:80])
            # menu aberto, perfil, área pessoal
            await pg.goto(BASE + '/area-pessoal.php'); await pg.wait_for_timeout(1200); await grab(pg, who + ' área pessoal')
            tabs = await pg.evaluate("[...document.querySelectorAll('.ap-tabs [role=tab],.ap-tabs button,.ap-tabs a')].map((e,i)=>i)")
            for i in tabs:
                try: await pg.locator('.ap-tabs [role=tab], .ap-tabs button, .ap-tabs a').nth(i).click(timeout=800); await sweep(pg, f'{who}:area-pessoal#{i}')
                except Exception: pass
            await c2.close()
        await b.close()
    json.dump(seen, open(OUT, 'w'), ensure_ascii=False, indent=0)
    print(len(seen), 'textos por traduzir ->', OUT)
if __name__ == '__main__': asyncio.run(main())
