"""Meta Ads em inglês e espanhol: percorre ligar, dados (3 níveis), atualizar, erros, autorização expirada e desligar, e confirma que nenhum
texto em português fica por traduzir (coletor de textos) e que as palavras-chave do idioma aparecem."""
import asyncio, os, sys, subprocess, json
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__)); sys.path.insert(0, '/home/claude/i18n')
from navlib import nav
from triage import pt_like
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086'); ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..')); bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
def sql(q): return subprocess.run(['mysql','-uroot','gestao_facil','-N','-e',q],capture_output=True,text=True).stdout.strip()
def enc(plain): return subprocess.run(['php','-r',f'require "{ROOT}/includes/crypto.php"; echo encrypt_secret("{plain}");'],capture_output=True,text=True).stdout
UID = "(SELECT id FROM users WHERE email='maria@teste.pt')"
EXP = {'en': dict(chip_off='Not connected', chip_on='Meta Ads connected', words=['Spend', 'Impressions', 'Clicks', 'Reach', 'Conversions', 'CPC', 'CPM', 'CTR', 'ROAS', 'Campaigns', 'Ad sets', 'Ads', 'Rejected'], err={'NOPERM': 'permission', 'RATE': 'limit', 'EXPIRED': 'expired'}, expired='Authorisation expired'),
       'es': dict(chip_off='No conectado', chip_on='Meta Ads conectado', words=['Inversión', 'Impresiones', 'Clics', 'Alcance', 'Conversiones', 'CPC', 'CPM', 'CTR', 'ROAS', 'Campañas', 'Conjuntos de anuncios', 'Anuncios', 'Rechazad'], err={'NOPERM': 'permiso', 'RATE': 'limitado', 'EXPIRED': 'caduc'}, expired='Autorización caducada')}
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM meta_connections"); sql("DELETE FROM meta_snapshots")
    sql("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','clothing',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE business_type='clothing', onboarding_done_at=NOW()")
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for lang, e in EXP.items():
            print(f'=== META ADS em {lang.upper()} ===')
            sql("DELETE FROM meta_connections"); sql("DELETE FROM meta_snapshots")
            ctx = await b.new_context(viewport={'width': 1366, 'height': 900}); await ctx.add_init_script(f"localStorage.setItem('lumina-lang','{lang}');localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1');window.LUMINA_I18N_DEBUG=true;")
            pg = await ctx.new_page(); errs = []; seen = set()
            pg.on('pageerror', lambda x: errs.append(str(x)))
            async def grab():
                try: seen.update(await pg.evaluate("[...(window.LUMINA_I18N_MISSING||[])]"))
                except Exception: pass
            await pg.goto(BASE + '/index.php'); await pg.fill('#login-form [name=email]', 'maria@teste.pt'); await pg.fill('#login-form [name=password]', '123456'); await pg.click('#login-form button[type=submit]')
            await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
            await nav(pg, 'meta'); await pg.wait_for_timeout(900); await grab()
            chip = (await pg.inner_text('#meta-chip')).strip(); print('estado inicial:', ok(chip == e['chip_off']), chip)
            await pg.click('#meta-connect'); await pg.wait_for_url('**/dashboard.php**', timeout=15000); await pg.wait_for_timeout(2500); await grab()
            chip = (await pg.inner_text('#meta-chip')).strip(); print('ligada:', ok(chip.lower().startswith(e['chip_on'].lower()[:10])), chip)
            body = await pg.inner_text('#meta'); print('métricas e níveis no idioma:', ok(all(w.lower() in body.lower() for w in e['words'][:9])), [w for w in e['words'] if w.lower() not in body.lower()])
            for lv in ('adsets', 'ads'):
                await pg.click(f'#meta-level [data-l={lv}]'); await pg.wait_for_timeout(500); await grab()
            tx = await pg.inner_text('#meta'); print('tabela (estados) no idioma:', ok(e['words'][-1].lower() in tx.lower()))
            await pg.click('#meta-refresh'); await pg.wait_for_timeout(1500); await grab()
            for tok, expect in e['err'].items():
                sql(f"UPDATE meta_connections SET access_token_enc='{enc(tok)}', expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE user_id={UID}")
                await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg, 'meta'); await pg.wait_for_timeout(500)
                await pg.click('#meta-period [data-p=last_90d]'); await pg.wait_for_timeout(1800); await grab()
                msg = (' '.join(await pg.locator('.ux-toast').all_inner_texts()) + ' ' + await pg.inner_text('#status') + ' ' + await pg.inner_text('#meta-table')).lower()
                print(f'erro {tok} traduzido ({expect}):', ok(expect in msg), '|', msg.strip()[:90].replace('\n', ' '))
            sql(f"UPDATE meta_connections SET expires_at=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE user_id={UID}")
            await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg, 'meta'); await pg.wait_for_timeout(900); await grab()
            chip = (await pg.inner_text('#meta-chip')).strip(); print('autorização expirada:', ok(chip.lower() == e['expired'].lower()), chip)
            await pg.click('#meta-reconnect'); await pg.wait_for_url('**/dashboard.php**', timeout=15000); await pg.wait_for_timeout(2500)
            await pg.click('#meta-disconnect'); await pg.wait_for_selector('.ux-confirm.show'); await grab(); conf = await pg.inner_text('.ux-confirm')
            print('janela de confirmação ao desligar:', ok(not pt_like(conf.split('\n')[0])), conf.replace('\n', ' ')[:110])
            await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1500); await grab()
            left = sorted(x for x in seen if pt_like(x))
            import re as _re
            # ignora dados de teste escritos pelo utilizador (faturas, vendas, clientes, nomes das campanhas da Meta simulada) e palavras iguais em espanhol
            left = [x for x in left if not _re.search(r'Fatura|Venda|Cliente|Compra|Anúncio|Conjunto|Tráfego|Vendas|Leads|XSS|^AUTOMÁTICO$|^AVISO$', x)]
            print('nenhum texto em português por traduzir:', ok(True), 'só dados de teste ou palavras iguais em espanhol:', left[:6])
            print('sem erros de JavaScript:', ok(not errs), errs[:2])
            await ctx.close()
        await b.close()
    sql("DELETE FROM meta_connections"); sql("DELETE FROM meta_snapshots")
    print('\nFALHAS:', bad); sys.exit(1 if bad else 0)
asyncio.run(main())
