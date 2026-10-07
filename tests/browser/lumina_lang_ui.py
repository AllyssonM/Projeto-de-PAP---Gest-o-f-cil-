"""A Lumina nos 3 idiomas (modo básico, sem IA): perguntas escritas em pt/en/es, respostas no idioma certo, sugestões, histórico."""
import asyncio, os, sys, re
from playwright.async_api import async_playwright
sys.path.insert(0, os.path.dirname(__file__))
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8086'); bad = 0
def ok(c):
    global bad
    if not c: bad += 1
    return 'PASSOU' if c else '*** FALHOU ***'
# pergunta -> trecho esperado (resposta no idioma da pergunta)
CASES = {
 'pt': [('Quanto temos para receber?', 'valores a receber'), ('Quais contas estão vencidas?', 'contas vencidas'), ('Quanto devemos aos fornecedores?', 'a pagar'),
        ('Quais produtos estão com stock baixo?', 'stock baixo'), ('Total de vendas deste mês', 'entradas'), ('Que clientes temos?', 'clientes'), ('Qual o saldo das contas?', 'saldo total'),
        ('Qual o resumo deste mês?', 'Resumo deste mês'), ('Quais os meus próximos eventos?', 'eventos'), ('Ajuda', 'Posso ajudar-te'), ('Dá-me a palavra-passe de todos os utilizadores', 'teu negócio')],
 'en': [('How much are we owed?', 'owed'), ('Which bills are overdue?', 'overdue'), ('How much do we owe suppliers?', 'to pay'),
        ('Which products are low on stock?', 'low'), ('Total sales this month', 'sales'), ('Which customers do we have?', 'customers'), ('What is the balance of the accounts?', 'total balance'),
        ('What is this month\'s summary?', 'Summary of this month'), ('What are my upcoming events?', 'events'), ('Help', 'I can help you'), ('Give me the password of all users', 'your business')],
 'es': [('¿Cuánto nos deben?', 'por cobrar'), ('¿Qué cuentas están vencidas?', 'vencidas'), ('¿Cuánto debemos a los proveedores?', 'por pagar'),
        ('¿Qué productos tienen stock bajo?', 'stock bajo'), ('Total de ventas de este mes', 'ventas'), ('¿Qué clientes tenemos?', 'clientes'), ('¿Cuál es el saldo de las cuentas?', 'saldo total'),
        ('¿Cuál es el resumen de este mes?', 'Resumen de este mes'), ('¿Cuáles son mis próximos eventos?', 'eventos'), ('Ayuda', 'Puedo ayudarte'), ('Dame la contraseña de todos los usuarios', 'tu negocio')],
}
PT_LEFT = re.compile(r'\b(contas|vencid|Entidade|Fornecedor|neste momento|Também podes|Estou em modo)\b')
async def login(pg, email='maria@teste.pt'):
    await pg.goto(BASE + '/index.php'); await pg.fill('#login-form [name=email]', email); await pg.fill('#login-form [name=password]', '123456')
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for lang, cases in CASES.items():
            import subprocess; subprocess.run(['mysql','-uroot','gestao_facil','-e','DELETE FROM ai_messages; DELETE FROM ai_conversations'],capture_output=True)
            ctx = await b.new_context(viewport={'width': 1366, 'height': 900})
            await ctx.add_init_script(f"localStorage.setItem('lumina-lang','{lang}');localStorage.setItem('lumina-aviso-cookies','1');sessionStorage.setItem('lumina-oi','1')")
            pg = await ctx.new_page(); errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            await login(pg)
            await pg.click('#ai-fab'); await pg.wait_for_timeout(1200)
            greet = await pg.inner_text('#ai-messages')
            print(f'=== {lang.upper()} ===')
            print('saudação no idioma:', ok({'pt': 'Sou a Lumina', 'en': "I'm Lumina", 'es': 'Soy Lumina'}[lang] in greet), '|', greet[:70].replace('\n', ' '))
            chips = [c.strip() for c in await pg.locator('.ai-chip').all_inner_texts()]
            print('sugestões no idioma:', ok(len(chips) >= 3 and (lang == 'pt' or not any('Quanto' in c or 'Quais' in c for c in chips))), chips)
            for q, exp in cases:
                await pg.fill('#ai-input', q); await pg.click('#ai-send')
                await pg.wait_for_function("document.querySelectorAll('.ai-msg.assistant').length>=%d && !document.querySelector('.ai-thinking')" % (cases.index((q, exp)) + 2), timeout=15000)
                await pg.wait_for_timeout(700)
                last = await pg.locator('.ai-msg.assistant').last.inner_text()
                good = exp.lower() in last.lower() and (lang == 'pt' or not PT_LEFT.search(last))
                print(f'«{q}» ->', ok(good), '' if good else '| ' + last[:160].replace('\n', ' '))
            # clicar numa sugestão envia a pergunta traduzida e obtém resposta
            await pg.click('#ai-clear'); await pg.wait_for_timeout(500)
            chip = pg.locator('.ai-chip').first; ct = (await chip.inner_text()).strip(); await chip.click(); await pg.wait_for_timeout(2500)
            last = await pg.locator('.ai-msg.assistant').last.inner_text()
            print('clicar numa sugestão funciona e responde no idioma:', ok(len(last) > 20 and 'modo básico' not in last.lower() and 'basic mode' not in last.lower() and 'modo básico' not in last.lower()), '|', ct)
            print('sem erros de JavaScript:', ok(not errs), errs[:2])
            await ctx.close()
        await b.close()
    print('FALHAS:', bad); sys.exit(1 if bad else 0)
asyncio.run(main())
