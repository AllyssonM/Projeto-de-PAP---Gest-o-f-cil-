import os
import sys, asyncio, glob, os, json, re, base64, email; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import sql, Client
from navlib import nav
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); MAILDIR=''+os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)),'..','..'))+'/storage/mail'; ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
def mails(): return sorted(glob.glob(MAILDIR+'/*.eml'))
def mail_link(files, needle):
    for f in reversed(files):
        m=email.message_from_string(open(f,encoding='utf-8').read())
        for p in m.walk():
            if p.get_content_type()=='text/html':
                h=p.get_payload(decode=True).decode(); r=re.search(r'href="([^"]*'+needle+r'[^"]*)"',h)
                if r: return r.group(1).replace('&amp;','&')
async def login(pg,e,p):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',e); await pg.fill('#login-form [name=password]',p)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
async def main():
    sql("DELETE FROM login_attempts"); sql("DELETE FROM users WHERE email IN ('convidado@teste.pt','ui-del@lumina.test')"); sql("UPDATE users SET email_verified_at=NULL WHERE email='maria@teste.pt'")
    for f in mails(): os.remove(f)
    errs=[]
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900},accept_downloads=True); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
        pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await login(pg,'maria@teste.pt','123456')
        print('=== AVISO DE EMAIL ===')
        print('aviso visível a quem não confirmou:', ok(await pg.is_visible('.verify-banner')), '|', (await pg.inner_text('.verify-banner p'))[:70] if await pg.locator('.verify-banner').count() else '')
        n0=len(mails()); await pg.click('.verify-banner [data-resend]'); await pg.wait_for_timeout(900); print('«Reenviar email» envia um email:', ok(len(mails())==n0+1))
        await pg.click('.verify-banner [data-hide]'); await pg.reload(); await pg.wait_for_timeout(1500); print('«Agora não» esconde e mantém-se escondido ao recarregar:', ok(not await pg.is_visible('.verify-banner')))
        print('\n=== CONVITE DE FUNCIONÁRIO ===')
        await nav(pg,'team'); await pg.wait_for_timeout(600)
        await pg.fill('#team-form [name=name]','Convidado Teste'); await pg.fill('#team-form [name=email]','convidado@teste.pt'); await pg.check('#team-form [name=send_invite]')
        before=set(mails()); await pg.click('#team-save'); await pg.wait_for_timeout(1200)
        new=[f for f in mails() if f not in before]; print('criar com «enviar convite»: email enviado:', ok(len(new)==1), '| link de convite:', ok(bool(mail_link(new,'tipo=convite'))))
        print('   (driver "log": o email não saiu de verdade) -> a palavra-passe provisória É mostrada como alternativa:', ok(await pg.is_visible('#temp-pass-modal')))
        await pg.click('#temp-pass-close'); await pg.wait_for_timeout(300)
        link=mail_link(new,'tipo=convite')
        await pg.click('[data-team-invite]'); await pg.wait_for_selector('.ux-confirm.show'); print('«Enviar convite» pede confirmação com o texto certo:', ok('Enviar' in await pg.inner_text('.ux-confirm [data-yes]'))); before=set(mails())
        await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1000); print('   reenvio entrega novo email:', ok(len(set(mails())-before)==1))
        link=mail_link([f for f in mails() if f not in before] or new,'tipo=convite') or link
        c2=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await c2.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg2=await c2.new_page()
        await pg2.goto(link); await pg2.wait_for_timeout(800); print('o funcionário abre o link: «Bem-vindo ao Lumina»:', ok('Bem-vindo' in await pg2.inner_text('h1')))
        await pg2.fill('[name=password]','convite-ok-1'); await pg2.fill('[name=confirm]','outra-diferente'); await pg2.click('button[type=submit]'); await pg2.wait_for_timeout(500); print('   palavras-passe diferentes: aviso:', ok('não coincidem' in await pg2.inner_text('.account-msg')))
        await pg2.fill('[name=confirm]','convite-ok-1'); await pg2.click('button[type=submit]'); await pg2.wait_for_url('**/index.php#autenticacao',timeout=6000); print('   guarda e vai para o login:', ok(True))
        await pg2.fill('#login-form [name=email]','convidado@teste.pt'); await pg2.fill('#login-form [name=password]','convite-ok-1'); await pg2.click('#login-form button[type=submit]'); await pg2.wait_for_url('**/dashboard.php',timeout=8000); await pg2.wait_for_timeout(1200)
        print('   entra SEM ter de trocar a palavra-passe provisória:', ok(not await pg2.is_visible('#password-modal:not(.hidden)') and await pg2.locator('#menu-btn').count()==1))
        await c2.close()
        print('\n=== ÁREA PESSOAL: OS MEUS DADOS ===')
        await pg.goto(BASE+'/area-pessoal.php#seguranca'); await pg.wait_for_timeout(1500)
        print('cartão «Os meus dados» visível:', ok(await pg.is_visible('#my-data-card')), '| botão eliminar (dono):', ok(await pg.is_visible('#delete-account')))
        async with pg.expect_download() as dl: await pg.click('#export-data')
        d=await dl.value; path=await d.path(); data=json.load(open(path,encoding='utf-8')); print('«Descarregar os meus dados» -> ficheiro', d.suggested_filename, '| JSON com conta e negócio:', ok('conta' in data and 'negocio' in data and data['conta']['email']=='maria@teste.pt'))
        await ctx.close()
        # eliminar conta (conta descartável)
        c=Client(); s,d=c.req('POST','/api/auth.php?action=register',{'name':'Para Apagar','email':'ui-del@lumina.test','password':'palavra-passe-1'}); 
        ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off');localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page()
        await login(pg,'ui-del@lumina.test','palavra-passe-1'); await pg.goto(BASE+'/area-pessoal.php#seguranca'); await pg.wait_for_timeout(1500)
        await pg.click('#delete-account'); await pg.wait_for_selector('.ap-modal-card'); print('janela «Eliminar a conta?» (centrada, sobre a página):', ok(await pg.is_visible('.ap-modal-card') and 'para sempre' in await pg.inner_text('.ap-modal-card')))
        await pg.fill('.ap-modal-card [name=pw]','palavra-passe-1'); await pg.fill('.ap-modal-card [name=word]','apagar'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(700)
        print('   palavra errada: mostra erro e NÃO apaga:', ok(await pg.is_visible('.ap-modal-card .ap-form-error') and sql("SELECT COUNT(*) FROM users WHERE email='ui-del@lumina.test'")=='1'))
        await pg.fill('.ap-modal-card [name=word]','ELIMINAR'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_url('**/index.php?conta=eliminada',timeout=8000)
        print('   palavra certa: conta apagada e volta ao início:', ok(sql("SELECT COUNT(*) FROM users WHERE email='ui-del@lumina.test'")=='0'))
        await ctx.close()
        # funcionário: só descarrega
        sql("UPDATE users SET email_verified_at=NOW() WHERE email='convidado@teste.pt'")
        ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('lumina-aviso-cookies','1')"); pg=await ctx.new_page(); await login(pg,'convidado@teste.pt','convite-ok-1'); await pg.goto(BASE+'/area-pessoal.php#seguranca'); await pg.wait_for_timeout(1500)
        print('funcionário vê «Descarregar» mas NÃO «Eliminar»:', ok(await pg.is_visible('#export-data') and await pg.locator('#delete-account').count()==0), '|', (await pg.inner_text('#my-data-card .muted:last-of-type'))[:55])
        await ctx.close(); await b.close()
    print('\nERROS JS:', errs or 'nenhum')
    sql("DELETE FROM users WHERE email IN ('convidado@teste.pt','ui-del@lumina.test')"); sql("UPDATE users SET email_verified_at=NOW() WHERE email='maria@teste.pt'")
    for f in mails(): os.remove(f)
asyncio.run(main())
