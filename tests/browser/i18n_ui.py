"""Idiomas: deteção automática, fallback, escolha manual, persistência (navegador e conta), tradução de mensagens, legal, Meta."""
import asyncio, os, sys, subprocess
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086'); bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
def sql(q): return subprocess.run(['mysql','-uroot','gestao_facil','-N','-e',q],capture_output=True,text=True).stdout.strip()
NAVTXT = "[...document.querySelectorAll('.main-nav a')].map(a=>a.textContent.trim()).join('|')"
async def ctx_for(b, locale, tz='Europe/Lisbon', accept=None, nolang=True):
    c = await b.new_context(locale=locale, timezone_id=tz, viewport={'width': 1366, 'height': 900}, extra_http_headers={'Accept-Language': accept or locale})
    await c.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
    return c
async def open_menu(pg):
    await pg.wait_for_function("(()=>{const m=document.querySelector('#main-menu');return document.documentElement.classList.contains('menu-open')||getComputedStyle(m).visibility==='hidden'})()", timeout=5000)
    if not await pg.evaluate("document.documentElement.classList.contains('menu-open')"):
        await pg.click('#menu-btn')
        await pg.wait_for_function("(()=>{const m=document.querySelector('#main-menu');return document.documentElement.classList.contains('menu-open')&&getComputedStyle(m).visibility==='visible'&&['none','matrix(1, 0, 0, 1, 0, 0)'].includes(getComputedStyle(m).transform)})()", timeout=5000)
async def login(pg, email='maria@teste.pt', pw='123456'):
    await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(600)
    await pg.fill('#login-form [name=email]', email); await pg.fill('#login-form [name=password]', pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def main():
    sql("DELETE FROM meta_connections"); sql("UPDATE users SET preferences=NULL WHERE email='maria@teste.pt'")
    sql("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','clothing',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE onboarding_done_at=NOW()")
    async with async_playwright() as p:
        b = await p.chromium.launch()
        print('=== 1) DETEÇÃO AUTOMÁTICA ===')
        for loc, tz, exp_lang, exp_nav in [('pt-PT','Europe/Lisbon','pt-PT','Funcionalidades'), ('pt-BR','America/Sao_Paulo','pt-PT','Funcionalidades'), ('en-US','America/New_York','en-GB','Features'),
                                           ('en-GB','Europe/London','en-GB','Features'), ('es-ES','Europe/Madrid','es-ES','Funcionalidades'), ('es-MX','America/Mexico_City','es-ES','Funcionalidades'),
                                           ('fr-FR','Europe/Paris','pt-PT','Funcionalidades'), ('ja-JP','Asia/Tokyo','pt-PT','Funcionalidades')]:
            c = await ctx_for(b, loc, tz); pg = await c.new_page(); await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(1200)
            lang = await pg.evaluate("document.documentElement.lang"); navt = await pg.evaluate(NAVTXT)
            extra = ''
            print(f'navegador {loc:6} -> {lang} / menu «{navt[:40]}»:', ok(lang == exp_lang and navt.startswith('Sectores' if False else exp_nav) ))
            await c.close()
        print('=== 2) ESCOLHA MANUAL E PERSISTÊNCIA (navegador) ===')
        c = await ctx_for(b, 'en-US', 'America/New_York'); pg = await c.new_page(); await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(1000)
        print('abre em inglês:', ok(await pg.evaluate("document.documentElement.lang") == 'en-GB'))
        await pg.click('[data-lang-toggle]'); print('menu de idioma mostra Português/English/Español e marca o atual:', ok(await pg.locator('.lang-pop button:visible').all_inner_texts() == ['Português', 'English', 'Español'] and await pg.get_attribute('.lang-pop [data-lang=en]', 'aria-current') == 'true'))
        await pg.click('.lang-pop [data-lang=pt]'); await pg.wait_for_load_state(); await pg.wait_for_timeout(1000)
        print('escolher Português muda logo para português (navegador está em inglês):', ok(await pg.evaluate("document.documentElement.lang") == 'pt-PT' and (await pg.evaluate(NAVTXT)).startswith('Funcionalidades')))
        await pg.reload(); await pg.wait_for_timeout(800); print('recarregar mantém Português (a deteção não sobrescreve a escolha):', ok(await pg.evaluate("document.documentElement.lang") == 'pt-PT'))
        pg2 = await c.new_page(); await pg2.goto(BASE + '/politica-cookies.php'); await pg2.wait_for_timeout(800)
        print('outra página/visita seguinte continua em Português:', ok(await pg2.evaluate("document.documentElement.lang") == 'pt-PT' and 'Política de Cookies' in await pg2.inner_text('h1')))
        await pg2.click('.lum-footer [data-lang=es]'); await pg2.wait_for_load_state(); await pg2.wait_for_timeout(1000)
        print('o rodapé também muda o idioma (Español) e a política de cookies vem traduzida:', ok(await pg2.evaluate("document.documentElement.lang") == 'es-ES' and 'Traducción para facilitar' in await pg2.inner_text('main') and 'Qué son las cookies' in await pg2.inner_text('main') or 'cookies' in (await pg2.inner_text('main')).lower() and 'Traducción' in await pg2.inner_text('main')), '|', (await pg2.inner_text('h1')))
        await c.close()
        print('=== 3) CONTA: a preferência acompanha o utilizador ===')
        c = await ctx_for(b, 'en-US', 'America/New_York'); pg = await c.new_page(); await login(pg)
        print('painel abre em inglês (navegador em inglês):', ok(await pg.evaluate("document.documentElement.lang") == 'en-GB'))
        await open_menu(pg)
        langbtns = await pg.locator('#main-menu .nav-lang-btn').all_inner_texts()
        print('menu lateral tem «Idioma» com Português/English/Español:', ok(langbtns == ['Português', 'English', 'Español'] and 'language' in (await pg.inner_text('#main-menu .nav-lang-title')).lower()), langbtns)
        await pg.click('#main-menu .nav-lang-btn[data-lang=es]'); await pg.wait_for_load_state(); await pg.wait_for_timeout(2500)
        print('escolher Español no menu traduz o painel já:', ok(await pg.evaluate("document.documentElement.lang") == 'es-ES' and 'Hola' in await pg.inner_text('.welcome-row h2')), '|', (await pg.inner_text('.welcome-row h2')).strip())
        print('preferência guardada na conta (base de dados):', ok('"lang": "es"' in sql("SELECT preferences FROM users WHERE email='maria@teste.pt'").replace('":"', '": "') or '"lang":"es"' in sql("SELECT preferences FROM users WHERE email='maria@teste.pt'")), sql("SELECT preferences FROM users WHERE email='maria@teste.pt'"))
        await c.close()
        c = await ctx_for(b, 'en-US', 'America/New_York'); pg = await c.new_page(); await login(pg)      # outro aparelho: sem nada guardado
        print('noutro aparelho (navegador em inglês) o painel abre em Español, por causa da conta:', ok(await pg.evaluate("document.documentElement.lang") == 'es-ES' and 'Hola' in await pg.inner_text('.welcome-row h2')))
        await open_menu(pg); await pg.click('#main-menu .nav-lang-btn[data-lang=en]'); await pg.wait_for_load_state(); await pg.wait_for_timeout(2500)
        print('volta a English e fica guardado:', ok(await pg.evaluate("document.documentElement.lang") == 'en-GB' and 'Hello' in await pg.inner_text('.welcome-row h2')))
        await pg.reload(); await pg.wait_for_timeout(2000); print('recarregar mantém English:', ok('Hello' in await pg.inner_text('.welcome-row h2')))
        # inglês: menus e mensagens
        tabs = await pg.evaluate("[...document.querySelectorAll('#main-menu .nav-tab:not(.nav-lang-btn)')].map(t=>t.textContent.trim())")
        print('menu em inglês:', ok(all(x in tabs for x in ['Overview', 'Cash flow', 'Calendar', 'Notes', 'Customers', 'Inventory', 'Staff', 'Team', 'Meta Ads', 'Calculator', 'Weather', 'Reports'])), tabs)
        await c.close()
        print('=== 4) MENSAGENS (erro, validação, sucesso) ===')
        for lang, exp_login, exp_reg in (('pt', 'Email ou palavra-passe incorretos.', 'Introduz um email válido.'), ('en', 'Incorrect email or password.', 'Enter a valid email.'), ('es', 'Email o contraseña incorrectos.', 'Introduce un email válido.')):
            sql('DELETE FROM login_attempts'); c = await ctx_for(b, 'pt-PT'); await c.add_init_script(f"localStorage.setItem('lumina-lang','{lang}')"); pg = await c.new_page(); await pg.goto(BASE + '/index.php'); await pg.wait_for_timeout(1000)
            await pg.fill('#login-form [name=email]', f'x{lang}@x.pt'); await pg.fill('#login-form [name=password]', 'errada1'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_timeout(1800)
            errs = await pg.evaluate("[...document.querySelectorAll('.message')].map(e=>e.textContent.trim())")
            print(f'[{lang}] erro de login:', ok(exp_login in errs), errs)
            await pg.click('[data-open-mode=register]'); await pg.wait_for_timeout(500)
            await pg.fill('#register-form [name=name]', 'Ab'); await pg.fill('#register-form [name=email]', 'abc'); await pg.fill('#register-form [name=password]', '123')
            await pg.evaluate("document.querySelector('#register-form').noValidate=true"); await pg.click('#register-form button[type=submit]'); await pg.wait_for_timeout(1200)
            msgs = await pg.evaluate("[...document.querySelectorAll('.message')].map(e=>e.textContent.trim())")
            print(f'[{lang}] validação do registo:', ok(exp_reg in msgs), msgs)
            await c.close()
        sql('DELETE FROM login_attempts')
        print('=== 5) META ADS e LUMINA nos 3 idiomas ===')
        for lang, exp in (('pt', 'Não ligado'), ('en', 'Not connected'), ('es', 'No conectada')):
            c = await ctx_for(b, 'pt-PT'); await c.add_init_script(f"localStorage.setItem('lumina-lang','{lang}')"); pg = await c.new_page(); await login(pg)
            await nav(pg, 'meta'); await pg.wait_for_timeout(1000)
            chip = (await pg.inner_text('#meta-chip')).strip(); body = await pg.inner_text('#meta-body')
            print(f'[{lang}] Meta Ads: estado «{chip}»:', ok(chip == exp or chip == 'No conectado'), '|', body[:80].replace('\n', ' '))
            await c.close()
        await b.close()
    print('FALHAS:', bad); sys.exit(1 if bad else 0)
asyncio.run(main())
