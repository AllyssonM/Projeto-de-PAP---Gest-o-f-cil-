"""Email de ponta a ponta pelo botão «Testar envio de email» + nome «Lumina» na assistente.
Precisa de DOIS servidores: LUMINA_URL (modo log) e LUMINA_URL_SMTP (com LUMINA_MAIL_DRIVER=smtp apontado para tests/mail_sink.py).
Para o SMTP: LUMINA_SINK_DIR = pasta onde o sink grava as mensagens."""
import asyncio, os, glob, sys
from playwright.async_api import async_playwright
LOG = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086'); SMTP = os.environ.get('LUMINA_URL_SMTP', 'http://127.0.0.1:8085'); SINK = os.environ.get('LUMINA_SINK_DIR', '/tmp/sink')
bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
async def run(b, base, label):
    ctx = await b.new_context(locale='pt-PT', viewport={'width': 1366, 'height': 900}); await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
    pg = await ctx.new_page(); errs = []; pg.on('pageerror', lambda e: errs.append(str(e)))
    await pg.goto(base + '/index.php'); await pg.fill('#login-form [name=email]', 'maria@teste.pt'); await pg.fill('#login-form [name=password]', '123456')
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
    fab = await pg.get_attribute('#ai-fab', 'aria-label'); head = await pg.inner_text('#ai-panel .ai-title strong')
    print(f'{label}: assistente chama-se Lumina (botão, painel):', ok('Lumina' in fab and head == 'Lumina' and 'ssistente' not in head))
    await pg.evaluate("document.querySelector('[data-section=team]')?.click()"); await pg.wait_for_timeout(700)
    await pg.evaluate("document.querySelector('#mail-test').click()"); await pg.wait_for_timeout(1800)
    toast = (await pg.inner_text('#status')).strip()
    await ctx.close(); return toast, errs
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for f in glob.glob(SINK + '/*.eml'): os.remove(f)
        t, e = await run(b, LOG, 'modo log')
        print('modo log: NÃO diz que enviou, explica porquê:', ok('NÃO foi enviado' in t and 'enviado para' not in t), '|', t[:90])
        t, e2 = await run(b, SMTP, 'SMTP')
        mails = sorted(glob.glob(SINK + '/*.eml'))
        print('SMTP: aviso de sucesso só porque o servidor aceitou:', ok('Email de teste enviado para maria@teste.pt' in t), '|', t[:80])
        print('SMTP: a mensagem chegou ao servidor de destino, com o destinatário certo:', ok(len(mails) == 1 and 'RCPT TO:<maria@teste.pt>' in open(mails[0]).read()))
        print('sem erros de JavaScript:', ok(not e and not e2))
        await b.close()
    sys.exit(1 if bad else 0)
asyncio.run(main())
