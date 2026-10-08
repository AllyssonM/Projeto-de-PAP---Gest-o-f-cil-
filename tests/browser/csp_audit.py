"""
INVENTÁRIO DA POLÍTICA CSP  (tests/browser/csp_audit.py)
Percorre o Lumina com um navegador a sério (Chromium) e regista TODAS as violações de Content-Security-Policy que o navegador deteta:
  - disposition "enforce": o que a política APLICADA (.htaccess) bloqueou. Tem de ser ZERO: essa política não pode partir o site.
  - disposition "report" : o que a política estrita, só de relatório, bloquearia se fosse aplicada. É a lista de trabalho para a tornar obrigatória.
Precisa de um Apache a servir o projeto com o .htaccess (ver tests/apache/iniciar_apache.sh) e da conta de teste maria@teste.pt (tests/browser/seed.py).
Uso:  LUMINA_URL=http://127.0.0.1:8091 python3 tests/browser/csp_audit.py        (sai com 1 se a política aplicada bloquear alguma coisa)
"""
import asyncio, json, os, sys, collections, subprocess
from playwright.async_api import async_playwright

BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8091').rstrip('/')
SECTIONS = ['overview', 'cashflow', 'calendar', 'notes', 'time', 'clients', 'accounts', 'stock', 'staff', 'team', 'meta', 'calculator', 'weather', 'reports']
LISTENER = """
document.addEventListener('securitypolicyviolation', e => {
  window.lumina_csp({disposition: e.disposition, directive: e.effectiveDirective, blocked: e.blockedURI, source: e.sourceFile, line: e.lineNumber, sample: (e.sample || '').slice(0, 80)});
});
"""
async def nav(pg, name):
    """Abre o menu e escolhe uma secção SÓ com cliques e esperas. (O nav() de navlib.py usa wait_for_function/evaluate, que o Playwright executa com eval()
    dentro da página: isso gerava violações de 'eval' falsas, vindas do Playwright e não do Lumina.)"""
    await pg.wait_for_timeout(350)
    await pg.click('#menu-btn'); await pg.wait_for_timeout(450)
    await pg.click(f'#main-menu [data-section={name}]'); await pg.wait_for_timeout(500)


found = []          # (onde, violação)
console_errors = []


def prepare_account():
    """A conta de teste não pode ter o assistente de boas-vindas por fazer (taparia o menu). Só mexe na base de DESENVOLVIMENTO de testes."""
    subprocess.run(['mysql', '-uroot', 'gestao_facil', '-e',
                    "INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','general',NOW() FROM users WHERE email='maria@teste.pt' "
                    "ON DUPLICATE KEY UPDATE onboarding_done_at=COALESCE(onboarding_done_at,NOW())"], check=False, capture_output=True)


async def main():
    prepare_account()
    async with async_playwright() as p:
        browser = await p.chromium.launch()
        ctx = await browser.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 900})
        await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
        where = {'now': '?'}
        await ctx.expose_function('lumina_csp', lambda v: found.append((where['now'], v)))
        await ctx.add_init_script(LISTENER)
        pg = await ctx.new_page()
        pg.set_default_timeout(10000)
        pg.on('console', lambda m: console_errors.append((where['now'], m.text[:200])) if m.type == 'error' and 'Content Security Policy' not in m.text and 'Content-Security-Policy' not in m.text else None)
        pg.on('pageerror', lambda e: console_errors.append((where['now'], 'pageerror: ' + str(e)[:200])))

        async def visit(path, label=None):
            where['now'] = label or path
            print('->', where['now'], flush=True)
            await pg.goto(BASE + '/' + path.lstrip('/'), wait_until='load')
            await pg.wait_for_timeout(900)

        for path in ['index.php', 'politica-privacidade', 'politica-cookies', 'termos-e-condicoes', 'informacao-legal', 'offline.html', 'esqueci-palavra-passe.php', 'redefinir-palavra-passe.php?token=x', 'verificar-email.php?token=x']:
            await visit(path)
        await visit('index.php', 'index.php (login)')
        await pg.fill('#login-form [name=email]', 'maria@teste.pt'); await pg.fill('#login-form [name=password]', '123456')
        await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1800)
        where['now'] = 'dashboard.php'
        for s in SECTIONS:
            where['now'] = f'dashboard.php#{s}'
            print('->', where['now'], flush=True)
            try:
                await nav(pg, s); await pg.wait_for_timeout(700)
            except Exception as e:                                   # secção que este perfil não tem
                print(f'(saltada {s}: {str(e)[:60]})')
        where['now'] = 'dashboard.php (Lumina, chat)'
        for sel in ['#ai-fab', '#lumina-fab', '[data-open-ai]', '.ai-fab']:
            if await pg.locator(sel).count():
                await pg.locator(sel).first.click(); await pg.wait_for_timeout(700); break
        await visit('area-pessoal.php')
        await browser.close()

    enforce = [(w, v) for w, v in found if v['disposition'] == 'enforce']
    report = [(w, v) for w, v in found if v['disposition'] != 'enforce']
    print(f'\nViolações da política APLICADA (têm de ser 0): {len(enforce)}')
    for w, v in enforce[:20]:
        print('  *** FALHOU ***', w, v['directive'], v['blocked'], v['source'], v['line'])
    groups = collections.OrderedDict()
    for w, v in report:
        key = (v['directive'], v['blocked'] if v['blocked'] not in ('inline', 'eval') else v['blocked'], v['source'].replace(BASE + '/', '') if v['source'] else '')
        groups.setdefault(key, {'pages': set(), 'n': 0, 'sample': v['sample']})
        groups[key]['pages'].add(w); groups[key]['n'] += 1
    print(f'\nViolações da política ESTRITA (só relatório): {len(report)} ocorrências, {len(groups)} origens distintas')
    for (directive, blocked, source), g in sorted(groups.items(), key=lambda kv: (kv[0][0], kv[0][2])):
        print(f'  {directive:<16} {blocked:<28} {source[:48]:<48} x{g["n"]:<3} em {len(g["pages"])} página(s)' + (f'  «{g["sample"]}»' if g['sample'] else ''))
    print(f'\nErros de consola (que não são CSP): {len(console_errors)}')
    for w, t in console_errors[:15]:
        print('  ', w, '|', t)
    json.dump({'enforce': [(w, v) for w, v in enforce], 'report': [(w, v) for w, v in report]}, open(os.environ.get('CSP_AUDIT_JSON', '/tmp/csp_audit.json'), 'w'), ensure_ascii=False)
    sys.exit(1 if enforce else 0)

asyncio.run(main())
