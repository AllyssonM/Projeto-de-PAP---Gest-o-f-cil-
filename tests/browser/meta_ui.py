"""Meta Ads de ponta a ponta contra a Graph API SIMULADA (tests/meta_mock.py, porta 9100; config/meta.local.php aponta para ela).
Conexão (OAuth), permissões, recuperação e atualização de dados, erros (expirado, sem permissão, limite), desconexão, token nunca no navegador."""
import asyncio, os, subprocess, sys, json, shutil
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086')
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
LOCAL = os.path.join(ROOT, 'config', 'meta.local.php')
bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
def sql(q): return subprocess.run(['mysql','-uroot','gestao_facil','-N','-e',q],capture_output=True,text=True).stdout.strip()
def enc(plain): return subprocess.run(['php','-r',f'require "{ROOT}/includes/crypto.php"; echo encrypt_secret("{plain}");'],capture_output=True,text=True).stdout
UID = "(SELECT id FROM users WHERE email='maria@teste.pt')"
async def login(pg, email='maria@teste.pt'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]','123456')
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def chip(pg): return (await pg.inner_text('#meta-chip')).strip()
async def main():
    sql("DELETE FROM meta_connections"); sql("DELETE FROM meta_snapshots")
    sql("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) SELECT id,'Loja QA','clothing',NOW() FROM users WHERE email='maria@teste.pt' ON DUPLICATE KEY UPDATE onboarding_done_at=NOW()")
    async with async_playwright() as p:
        b = await p.chromium.launch(); ctx = await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900})
        await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
        pg = await ctx.new_page(); errs = []; bodies = []
        pg.on('pageerror', lambda e: errs.append(str(e) + ' @ ' + str(getattr(e,'stack','')) [:300])); pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' and 'ERR_TUNNEL' not in m.text and 'Failed to load resource' not in m.text else None)
        async def grab(r):
            if '/api/meta.php' in r.url:
                try: bodies.append(await r.text())
                except Exception: pass
        pg.on('response', grab)
        await login(pg)
        print('=== NÃO CONFIGURADO ===')
        shutil.move(LOCAL, LOCAL + '.off')
        try:
            await nav(pg, 'meta'); await pg.wait_for_timeout(900)
            print('sem credenciais diz «Não configurado» e não finge:', ok(await chip(pg) == 'Não configurado' and not await pg.is_visible('#meta-connect')))
        finally: shutil.move(LOCAL + '.off', LOCAL)
        print('=== LIGAR ===')
        await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg, 'meta'); await pg.wait_for_timeout(900)
        print('não ligado: explica as permissões (só leitura) e mostra «Ligar Meta Ads»:', ok(await chip(pg) == 'Não ligado' and 'ads_read' in await pg.inner_text('#meta-body') and await pg.is_visible('#meta-connect')))
        await pg.click('#meta-connect'); await pg.wait_for_url('**/dashboard.php**', timeout=15000); await pg.wait_for_timeout(2500)
        print('regresso da Meta: aviso de sucesso, aba Meta aberta e chip «Meta Ads ligada»:', ok(await pg.is_visible('#meta') and await chip(pg) == 'Meta Ads ligada'))
        print('conta escolhida automaticamente e dados carregados (Investimento 75,00 €):', ok('75,00' in await pg.inner_text('#meta-totals') and 'Mota Importz' in await pg.inner_text('#meta-updated')))
        t = await pg.inner_text('#meta-totals')
        print('métricas: impressões, cliques, alcance, conversões, CPC, CPM, CTR, ROAS:', ok(all(x in t for x in ['6000', '240', '4800', '18', '0,31', '12,50', '4,00', '6,00×'])), t.replace('\n',' | ')[:150])
        rows = await pg.locator('#meta-table tbody tr').count(); tx = await pg.inner_text('#meta-table')
        print('campanhas: 3 (inclui a 2.ª página da Meta), estados e orçamento diário/total:', ok(rows == 3 and 'Pausada' in tx and '/dia' in tx and 'total' in tx))
        print('campanha pausada sem entrega mostra «—» (não inventa zeros de ROAS):', ok('—' in tx))
        await pg.click('#meta-level [data-l=adsets]'); print('conjuntos de anúncios: 4 linhas:', ok(await pg.locator('#meta-table tbody tr').count() == 4))
        await pg.click('#meta-level [data-l=ads]'); print('anúncios: 6 linhas, um «Reprovada»:', ok(await pg.locator('#meta-table tbody tr').count() == 6 and 'Reprovada' in await pg.inner_text('#meta-table')))
        print('=== SEGURANÇA ===')
        html = await pg.content(); allb = ' '.join(bodies)
        print('o token nunca aparece na página nem nas respostas da API:', ok('LONG_SHORT' not in html and 'LONG_SHORT' not in allb and 'access_token' not in allb.lower()))
        tok = sql(f"SELECT access_token_enc FROM meta_connections WHERE user_id={UID}")
        print('na base de dados o token está cifrado:', ok(bool(tok) and 'LONG_SHORT' not in tok and 'SHORT' not in tok))
        print('=== ATUALIZAR / PERÍODO ===')
        sql(f"UPDATE meta_snapshots SET payload=REPLACE(payload,'\"spend\":75','\"spend\":1') WHERE user_id={UID}")
        await pg.click('#meta-refresh'); await pg.wait_for_timeout(1500)
        print('«Atualizar» pede de novo à Meta (valor volta a 75,00 €):', ok('75,00' in await pg.inner_text('#meta-totals')))
        await pg.click('#meta-period [data-p=last_7d]'); await pg.wait_for_timeout(2000)
        print('mudar para 7 dias guarda o período e vai buscar dados:', ok(sql(f"SELECT date_preset FROM meta_connections WHERE user_id={UID}") == 'last_7d' and '75,00' in await pg.inner_text('#meta-totals')))
        print('=== ERROS ===')
        for tokname, expect in [('NOPERM', 'permissões'), ('RATE', 'limitou'), ('EXPIRED', 'expirou')]:
            sql(f"UPDATE meta_connections SET access_token_enc='{enc(tokname)}', expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE user_id={UID}")
            await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg, 'meta'); await pg.wait_for_timeout(500)
            await pg.click('#meta-period [data-p=last_90d]'); await pg.wait_for_timeout(1800)
            toast = ' '.join(await pg.locator('.ux-toast').all_inner_texts()) + ' ' + await pg.inner_text('#status') + ' ' + await pg.inner_text('#meta-table')
            print(f'token {tokname}: mensagem clara ({expect}) e a página continua utilizável:', ok(expect in toast and await pg.is_visible('#meta-refresh')), '|', toast.strip()[:90].replace('\n',' '))
        sql(f"UPDATE meta_connections SET expires_at=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE user_id={UID}")
        await pg.reload(); await pg.wait_for_timeout(1500); await nav(pg, 'meta'); await pg.wait_for_timeout(900)
        print('autorização expirada: chip, aviso, botão «Ligar outra vez» e sem dados antigos:', ok(await chip(pg) == 'Autorização expirada' and await pg.is_visible('#meta-reconnect') and not await pg.is_visible('#meta-data')))
        await pg.click('#meta-reconnect'); await pg.wait_for_url('**/dashboard.php**', timeout=15000); await pg.wait_for_timeout(2500)
        print('ligar outra vez recupera:', ok(await chip(pg) == 'Meta Ads ligada' and '75,00' in await pg.inner_text('#meta-totals')))
        print('=== DESLIGAR ===')
        await pg.click('#meta-disconnect'); await pg.wait_for_selector('.ux-confirm.show'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1500)
        print('desligar: chip «Não ligado», token e dados apagados:', ok(await chip(pg) == 'Não ligado' and sql("SELECT COUNT(*) FROM meta_connections")=='0' and sql("SELECT COUNT(*) FROM meta_snapshots")=='0'))
        print('=== OAuth: recusa e state inválido ===')
        r = await pg.request.get(BASE + '/api/meta.php?action=callback&code=GOODCODE&state=falso', max_redirects=0)
        print('state inválido é recusado (meta=state):', ok('meta=state' in r.headers.get('location', '')))
        await pg.click('#meta-connect'); await pg.wait_for_url('**/dashboard.php**'); await pg.wait_for_timeout(500)
        r = await pg.request.get(BASE + '/api/meta.php?action=callback&code=GOODCODE&state=reutilizado', max_redirects=0)
        print('state de uso único (reutilização recusada):', ok('meta=state' in r.headers.get('location', '')))
        print('sem erros de JavaScript:', ok(not errs), errs[:3])
        await ctx.close()
        print('=== FUNCIONÁRIO ===')
        ctx2 = await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx2.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')")
        pg2 = await ctx2.new_page(); await login(pg2, 'fin@teste.pt')
        print('funcionário não vê a aba Meta Ads:', ok(await pg2.locator('[data-section=meta]').count() == 0))
        r = await pg2.request.get(BASE + '/api/meta.php?action=status'); print('e a API recusa (403):', ok(r.status == 403))
        await b.close()
    sql("DELETE FROM meta_connections"); sql("DELETE FROM meta_snapshots")
    print('\nFALHAS:', bad); sys.exit(1 if bad else 0)
asyncio.run(main())
