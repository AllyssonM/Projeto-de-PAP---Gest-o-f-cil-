"""PROVA DE QUE O DESIGN NÃO MUDOU. Abre a versão ANTERIOR (BASE_OLD, porta 8087) e a NOVA (BASE_NEW, porta 8086), em português, com o mesmo
utilizador, e compara elemento a elemento: etiqueta, classes, posição/tamanho e estilos calculados (cor, fundo, tipo de letra, tamanho, espaçamentos, bordas,
sombras, opacidade...). Ignora só os elementos NOVOS (seletor de idioma, aba/secção Meta Ads, ligações de idioma do rodapé).
Também guarda capturas de ecrã lado a lado em /tmp/parity/ e calcula a % de píxeis diferentes (fora da zona dos elementos novos)."""
import asyncio, os, sys, json, io
from playwright.async_api import async_playwright
OLD = os.environ.get('BASE_OLD', 'http://127.0.0.1:8087'); NEW = os.environ.get('BASE_NEW', 'http://127.0.0.1:8086')
NEW_SEL = '.nav-lang, .lang-pop-host, .lf-lang, #meta, [data-section=meta], #meta-panel, .lf-lang *'
PROPS = ['display','position','color','background-color','background-image','font-family','font-size','font-weight','line-height','letter-spacing','text-align','text-transform',
         'margin-top','margin-right','margin-bottom','margin-left','padding-top','padding-right','padding-bottom','padding-left',
         'border-top-width','border-top-color','border-top-style','border-radius','box-shadow','opacity','overflow','z-index','flex-direction','gap','grid-template-columns','transform','cursor','visibility']
JS = """([props, newSel]) => {
  const out = []; const skip = new Set(document.querySelectorAll(newSel));
  const inNew = el => { for (let e = el; e; e = e.parentElement) if (skip.has(e)) return true; return false; };
  document.querySelectorAll('body, body *').forEach(el => {
    if (['SCRIPT','STYLE','NOSCRIPT','CANVAS','VIDEO','SOURCE'].includes(el.tagName) || inNew(el)) return;
    const cs = getComputedStyle(el), r = el.getBoundingClientRect();
    if (cs.display === 'none') return;
    const path = []; for (let e = el; e && e !== document.body; e = e.parentElement) path.unshift(e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0,3).join('.') : ''));
    const st = {}; props.forEach(p => st[p] = cs.getPropertyValue(p));
    out.push({ path: path.join('>'), rect: [Math.round(r.x*10)/10 + 0, Math.round(r.y*10)/10, Math.round(r.width*10)/10, Math.round(r.height*10)/10], st });
  });
  return out;
}"""
FREEZE = "*,*::before,*::after{animation:none!important;transition:none!important;caret-color:transparent!important} video,canvas{visibility:hidden!important}"
async def snap(ctx, base, page_fn):
    pg = await ctx.new_page(); await pg.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1');localStorage.setItem('lumina-lang','pt')")
    await pg.route('**/*', lambda r: r.continue_() if r.request.url.startswith(('http://127.0.0.1', 'data:')) else r.abort())
    await page_fn(pg, base); await pg.add_style_tag(content=FREEZE); await pg.wait_for_timeout(500)
    data = await pg.evaluate(JS, [PROPS, NEW_SEL]); shot = await pg.screenshot(full_page=False); await pg.close(); return data, shot
async def login(pg, base, email='maria@teste.pt'):
    await pg.goto(base + '/index.php'); await pg.wait_for_timeout(500)
    await pg.fill('#login-form [name=email]', email); await pg.fill('#login-form [name=password]', '123456'); await pg.click('#login-form button[type=submit]')
    await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def open_menu(pg):
    await pg.click('#menu-btn'); await pg.wait_for_function("document.documentElement.classList.contains('menu-open')"); await pg.wait_for_timeout(700)
def PAGES():
    P = {}
    async def landing(pg, b): await pg.goto(b + '/index.php'); await pg.wait_for_timeout(1500)
    async def landing_login(pg, b): await pg.goto(b + '/index.php'); await pg.wait_for_timeout(800); await pg.click('[data-open-mode=login]'); await pg.wait_for_timeout(700)
    async def legal(pg, b): await pg.goto(b + '/politica-privacidade.php'); await pg.wait_for_timeout(800)
    async def forgot(pg, b): await pg.goto(b + '/esqueci-palavra-passe.php'); await pg.wait_for_timeout(800)
    P.update({'landing': landing, 'landing-login': landing_login, 'legal-privacidade': legal, 'esqueci-palavra-passe': forgot})
    for sec in ['overview', 'cashflow', 'calendar', 'notes', 'time', 'clients', 'accounts', 'stock', 'staff', 'team', 'calculator', 'weather', 'reports']:
        def mk(sec):
            async def f(pg, b):
                await login(pg, b); await pg.evaluate(f"document.querySelector('#main-menu [data-section={sec}]')?.click()"); await pg.wait_for_timeout(1200)
            return f
        P['dashboard-' + sec] = mk(sec)
    async def menu(pg, b): await login(pg, b); await open_menu(pg)
    P['dashboard-menu-aberto'] = menu
    async def lumina(pg, b): await login(pg, b); await pg.click('#ai-fab'); await pg.wait_for_timeout(1200)
    P['dashboard-lumina'] = lumina
    async def ap(pg, b): await login(pg, b); await pg.goto(b + '/area-pessoal.php'); await pg.wait_for_timeout(1200)
    P['area-pessoal'] = ap
    async def emp(pg, b): await login(pg, b, 'fin@teste.pt'); 
    P['dashboard-funcionario'] = emp
    return P
async def main():
    from PIL import Image, ImageChops
    os.makedirs('/tmp/parity', exist_ok=True); total_bad = 0; only = sys.argv[1:] 
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for name, fn in PAGES().items():
            if only and name not in only: continue
            res = {}
            for tag, base in (('old', OLD), ('new', NEW)):
                ctx = await b.new_context(viewport={'width': 1366, 'height': 900}, locale='pt-PT', timezone_id='Europe/Lisbon')
                try: res[tag] = await snap(ctx, base, fn)
                except Exception as e: res[tag] = ([], b''); print('  ERRO', tag, name, str(e)[:100])
                await ctx.close()
            (do, so), (dn, sn) = res['old'], res['new']
            diffs = []
            if len(do) != len(dn): diffs.append(f'nº de elementos: antes {len(do)} / agora {len(dn)}')
            # junta por caminho (ordem) 
            from collections import defaultdict
            mo = defaultdict(list); [mo[e['path']].append(e) for e in do]; mn = defaultdict(list); [mn[e['path']].append(e) for e in dn]
            for path, lo in mo.items():
                ln = mn.get(path, [])
                for i, e in enumerate(lo):
                    if i >= len(ln): diffs.append('desapareceu: ' + path[-80:]); continue
                    f = ln[i]
                    if any(abs(a - c) > 0.6 for a, c in zip(e['rect'], f['rect'])): diffs.append(f"posição/tamanho {e['rect']} -> {f['rect']}: {path[-70:]}")
                    for k in PROPS:
                        if e['st'][k] != f['st'][k]: diffs.append(f"{k}: {e['st'][k][:40]} -> {f['st'][k][:40]}: {path[-60:]}")
            for path in mn:
                if path not in mo: diffs.append('novo: ' + path[-80:])
            pix = ''
            if so and sn:
                a = Image.open(io.BytesIO(so)).convert('RGB'); c = Image.open(io.BytesIO(sn)).convert('RGB')
                d = ImageChops.difference(a, c).convert('L').point(lambda v: 255 if v > 24 else 0); n = sum(1 for v in d.getdata() if v); pix = f'{100 * n / (a.width * a.height):.3f}% píxeis'
                a.save(f'/tmp/parity/{name}_antes.png'); c.save(f'/tmp/parity/{name}_agora.png'); d.save(f'/tmp/parity/{name}_diff.png')
            print(f"{'PASSOU' if not diffs else '*** DIFERENÇAS ***'}  {name:24} elementos {len(do)}  {pix}")
            for x in diffs[:12]: print('     ', x)
            total_bad += 1 if diffs else 0
        await b.close()
    print('PÁGINAS COM DIFERENÇAS:', total_bad)
asyncio.run(main())
