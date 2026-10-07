import os
IMG = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'imgs') + os.sep
os.makedirs(IMG, exist_ok=True)
open(IMG+'falso.png','w').write('isto não é uma imagem')                         # texto com extensão .png
open(IMG+'grande.png','wb').write(b'\x89PNG\r\n\x1a\n' + b'\0' * (3 * 1024 * 1024))   # 3 MB: acima do limite de 2 MB
import sys, asyncio, re, time, hmac, hashlib, struct, base64; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import Client, sql
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
def totp(secret, t=None):
    key=base64.b32decode(secret.upper()+'='*((8-len(secret)%8)%8)); c=int((t or time.time())//30); h=hmac.new(key,struct.pack('>Q',c),hashlib.sha1).digest(); o=h[19]&15
    return f"{((struct.unpack('>I',h[o:o+4])[0]&0x7fffffff)%1000000):06d}"
def nif(prefix='50196484'):
    s=sum(int(prefix[i])*(9-i) for i in range(8)); c=11-(s%11); return prefix+str(0 if c>=10 else c)
async def choose(pg, btn, path):
    async with pg.expect_file_chooser() as fc: await pg.click(btn)
    await (await fc.value).set_files(path)
async def toasts(pg): return await pg.evaluate("[...document.querySelectorAll('.ux-toast')].map(t=>t.innerText.replace(/\\n×/,'').trim())")
async def main():
    h=__import__('subprocess').run(['php','-r','echo password_hash("123456", PASSWORD_DEFAULT);'],capture_output=True,text=True).stdout
    sql(f"UPDATE users SET totp_enabled=0,totp_secret=NULL,totp_backup=NULL,avatar_path=NULL,preferences=NULL,password_hash='{h}',name='Maria Teste',phone=NULL WHERE email='maria@teste.pt'"); sql("DELETE FROM login_attempts; DELETE FROM payment_cards; DELETE FROM calendar_events WHERE event_date=CURDATE()")
    sql("UPDATE business_profiles SET logo_path=NULL,brand_color=NULL,tax_number=NULL WHERE user_id=(SELECT id FROM users WHERE email='maria@teste.pt')")
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
        pg=await ctx.new_page(); errs=[]; pg.on('pageerror',lambda e:errs.append(str(e))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
        await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]','maria@teste.pt'); await pg.fill('#login-form [name=password]','123456'); await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
        print('=== 1) ENTRAR PELO BOTÃO DE PERFIL ===')
        await pg.click('#avatar-btn'); await pg.wait_for_url('**/area-pessoal.php'); await pg.wait_for_timeout(1200)
        print('página:', pg.url.split('/')[-1], '| título:', await pg.inner_text('h1.ap-h1'), '| subtítulo:', (await pg.inner_text('.topbar p.muted')).strip(), ok('Gira a tua conta e as informações da empresa.' in await pg.inner_text('.topbar')))
        print('\n=== 2) SEPARADORES ===')
        tabs=await pg.evaluate("[...document.querySelectorAll('.ap-tabs [role=tab]')].map(t=>t.textContent.trim())"); print('separadores:', tabs, ok(len(tabs)==4))
        async def ind(): return await pg.evaluate("(()=>{const i=document.querySelector('.ap-ind').getBoundingClientRect(),t=document.querySelector('.ap-tabs [aria-selected=true]').getBoundingClientRect();return {dx:Math.round(Math.abs(i.x-t.x)),dw:Math.round(Math.abs(i.width-t.width)),sel:document.querySelector('.ap-tabs [aria-selected=true]').dataset.tab}})()")
        r=await ind(); print('indicador alinhado com "Perfil":', r, ok(r['dx']<=1 and r['dw']<=1))
        await pg.click('[data-tab=empresa]'); await pg.wait_for_timeout(500); r=await ind(); print('depois de clicar em "A minha empresa":', r, ok(r['sel']=='empresa' and r['dx']<=1), '| endereço:', pg.url.split('#')[-1])
        await pg.keyboard.press('ArrowRight'); await pg.wait_for_timeout(400); r=await ind(); print('seta → no teclado muda para:', r['sel'], ok(r['sel']=='cartoes'))
        await pg.click('[data-tab=perfil]'); await pg.wait_for_timeout(300)
        print('\n=== 3) PERFIL ===')
        print('dados:', (await pg.inner_text('#profile-view')).replace('\n',' | '), ok('Maria Teste' in await pg.inner_text('#profile-view') and 'maria@teste.pt' in await pg.inner_text('#profile-view')))
        await pg.click('#profile-edit'); await pg.fill('#profile-form [name=phone]','abc'); await pg.click('#profile-form button.primary'); await pg.wait_for_timeout(300); print('telefone inválido ->', (await pg.inner_text('#profile-form .ap-form-error')).strip(), ok(await pg.is_visible('#profile-form .ap-form-error')))
        await pg.fill('#profile-form [name=phone]','+351 912 345 678'); await pg.fill('#profile-form [name=name]','Maria Teste Silva'); await pg.click('#profile-form button.primary'); await pg.wait_for_timeout(900)
        print('guardado:', (await pg.inner_text('#profile-view')).replace('\n',' | ')[:90], ok('+351 912 345 678' in await pg.inner_text('#profile-view')), '| aviso:', await toasts(pg))
        print('\n=== 4) FOTO DE PERFIL ===')
        print('sem foto: mostra as iniciais', repr(await pg.inner_text('#ap-avatar-initials')), '| botões visíveis:', [await pg.is_visible(x) for x in ('#avatar-add','#avatar-change','#avatar-remove')], ok(await pg.is_visible('#avatar-add') and not await pg.is_visible('#avatar-remove')))
        await choose(pg,'#avatar-add',IMG+'falso.png'); await pg.wait_for_timeout(400); print('ficheiro falso ->', (await toasts(pg))[-1:], ok(any('não é uma imagem' in t for t in await toasts(pg))))
        await choose(pg,'#avatar-add',IMG+'grande.png'); await pg.wait_for_timeout(400); print('3 MB ->', (await toasts(pg))[-1:], ok(any('demasiado grande' in t for t in await toasts(pg))))
        await choose(pg,'#avatar-add',IMG+'foto.png'); await pg.wait_for_selector('.ap-preview img'); print('imagem válida -> pré-visualização aberta:', ok(await pg.is_visible('.ap-preview img')), '| ainda NÃO guardada no servidor:', ok(sql("SELECT IFNULL(avatar_path,'nenhuma') FROM users WHERE email='maria@teste.pt'")=='nenhuma'))
        await pg.keyboard.press('Escape'); await pg.wait_for_timeout(500); print('   Esc cancela e nada foi guardado:', ok(sql("SELECT IFNULL(avatar_path,'nenhuma') FROM users WHERE email='maria@teste.pt'")=='nenhuma' and await pg.locator('.ap-preview').count()==0))
        await choose(pg,'#avatar-add',IMG+'foto.png'); await pg.wait_for_selector('.ap-preview img'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(1200)
        print('guardar -> foto no ecrã:', ok(await pg.is_visible('#ap-avatar-img')), '| botões "Alterar" e "Remover" visíveis:', ok(await pg.is_visible('#avatar-change') and await pg.is_visible('#avatar-remove')), '| aviso:', (await toasts(pg))[-1:])
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ap_perfil.png', full_page=True)
        await pg.click('#avatar-remove'); await pg.wait_for_selector('.ux-confirm'); print('remover -> pede confirmação:', ok(await pg.is_visible('.ux-confirm [data-yes]'))); await pg.click('.ux-confirm [data-no]'); await pg.wait_for_timeout(400); print('   cancelar mantém a foto:', ok(await pg.is_visible('#ap-avatar-img')))
        await pg.click('#avatar-remove'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(900); print('   confirmar remove e volta às iniciais:', await pg.inner_text('#ap-avatar-initials'), ok(await pg.is_visible('#ap-avatar-initials') and sql("SELECT IFNULL(avatar_path,'nenhuma') FROM users WHERE email='maria@teste.pt'")=='nenhuma'))
        print('\n=== 5) EMPRESA ===')
        await pg.click('[data-tab=empresa]'); await pg.wait_for_timeout(500); f='#company-form '
        await pg.fill(f+'[name=name]','Mota Importz'); await pg.fill(f+'[name=activity]','Venda de produtos importados'); await pg.fill(f+'[name=tax_number]','123456788'); await pg.click(f+'.form-actions button.primary'); await pg.wait_for_timeout(300); print('NIF inválido ->', (await pg.inner_text(f+'.ap-form-error')).strip(), ok('NIF' in await pg.inner_text(f+'.ap-form-error')))
        await pg.fill(f+'[name=tax_number]',nif()); await pg.fill(f+'[name=address]','Rua Exemplo 1, Leiria'); await pg.fill(f+'[name=email]','geral@motaimportz.pt'); await pg.fill(f+'[name=website]','https://motaimportz.pt'); await pg.fill('#brand-hex','#e11d48'); await pg.dispatch_event('#brand-hex','input')
        print('cor: o seletor acompanha o código hex:', await pg.input_value('#brand-color'), ok(await pg.input_value('#brand-color')=='#e11d48'))
        await choose(pg,'#logo-add',IMG+'logo.png'); await pg.wait_for_selector('.ap-preview img'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(1200); print('logo carregada:', ok(await pg.is_visible('#ap-logo-img')), '| botões Alterar/Remover:', ok(await pg.is_visible('#logo-change') and await pg.is_visible('#logo-remove')))
        await pg.click(f+'.form-actions button.primary'); await pg.wait_for_timeout(1000); print('guardar empresa -> aviso:', (await toasts(pg))[-1:], '| na BD:', sql("SELECT CONCAT(business_name,'|',tax_number,'|',brand_color) FROM business_profiles WHERE user_id=(SELECT id FROM users WHERE email='maria@teste.pt')"))
        await pg.reload(); await pg.wait_for_timeout(1200); await pg.click('[data-tab=empresa]'); await pg.wait_for_timeout(400); print('depois de recarregar:', await pg.input_value(f+'[name=name]'), await pg.input_value(f+'[name=tax_number]'), await pg.input_value('#brand-hex'), '| logo visível:', ok(await pg.is_visible('#ap-logo-img')))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ap_empresa.png', full_page=True)
        print('\n=== 6) PREFERÊNCIA: ocultar valores ===')
        await pg.click('[data-tab=perfil]'); await pg.wait_for_timeout(300); await pg.click('.ap-switch-row'); await pg.wait_for_timeout(500); print('interruptor ligado:', ok(await pg.is_checked('#pref-hide')), '| botão do cabeçalho:', await pg.get_attribute('.icon-btn[data-privacy-toggle]','aria-pressed'), '| BD:', sql("SELECT preferences FROM users WHERE email='maria@teste.pt'"))
        await pg.click('.ap-switch-row'); await pg.wait_for_timeout(400)
        print('\n=== 7) CARTÕES: gestão ===')
        c=Client(); s,d=c.req('POST','/api/auth.php?action=login',{'email':'maria@teste.pt','password':'123456'}); c.csrf=d['csrf']
        for br,l4 in [('visa','4242'),('mastercard','1098')]: c.req('POST','/api/cards.php',{'holder':'DADOS DE EXEMPLO','brand':br,'last4':l4,'exp_month':12,'exp_year':2031,'csrf':c.csrf})
        await pg.click('[data-tab=cartoes]'); await pg.wait_for_timeout(300); await pg.reload(); await pg.wait_for_timeout(1000); await pg.click('[data-tab=cartoes]'); await pg.wait_for_timeout(700)
        cards=await pg.evaluate("[...document.querySelectorAll('.wallet-card')].map(c=>c.innerText.replace(/\\n+/g,' | ').slice(0,95))"); print('cartões:', cards, ok(len(cards)==2 and any('4242' in x for x in cards) and any('1098' in x for x in cards)))
        mc='.wallet-card:has-text("1098")'
        await pg.click(mc+' button:has-text("Desativar cartão")'); await pg.wait_for_selector('.ux-confirm'); print('desativar -> pede confirmação:', (await pg.inner_text('.ux-confirm h2')).strip(), ok(True)); await pg.click('.ux-confirm [data-no]'); await pg.wait_for_timeout(400); print('   cancelar: continua ativo:', ok(sql("SELECT status FROM payment_cards WHERE last4='1098'")=='active'))
        await pg.click(mc+' button:has-text("Desativar cartão")'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(900); print('   confirmar: estado', sql("SELECT status FROM payment_cards WHERE last4='1098'"), '| etiqueta:', await pg.inner_text(mc+' .wc-badge:last-child'), '| aviso:', (await toasts(pg))[-1:], ok(sql("SELECT status FROM payment_cards WHERE last4='1098'")=='disabled'))
        await pg.click(mc+' button:has-text("Ativar cartão")'); await pg.wait_for_timeout(900); print('ativar cartão ->', sql("SELECT status FROM payment_cards WHERE last4='1098'"), ok(sql("SELECT status FROM payment_cards WHERE last4='1098'")=='active'), '| aviso:', (await toasts(pg))[-1:])
        await pg.click(mc+' button:has-text("Definir como principal")'); await pg.wait_for_timeout(900); print('definir como principal ->', sql("SELECT last4 FROM payment_cards WHERE is_primary=1"), ok(sql("SELECT last4 FROM payment_cards WHERE is_primary=1")=='1098'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ap_cartoes.png', full_page=True)
        await pg.click(mc+' button:has-text("Remover")'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(900); print('remover ->', sql("SELECT COUNT(*) FROM payment_cards"), 'cartão(ões) restante(s)', ok(sql("SELECT COUNT(*) FROM payment_cards")=='1'))
        print('   "Adicionar cartão" abre a janela:', end=' '); await pg.click('#card-add-btn'); await pg.wait_for_timeout(600); print(ok(await pg.is_visible('#card-modal')), '| aria-modal:', await pg.get_attribute('#card-modal [role=dialog], #card-modal','aria-modal')); await pg.keyboard.press('Escape'); await pg.wait_for_timeout(500)
        print('\n=== 8) SEGURANÇA ===')
        await pg.click('[data-tab=seguranca]'); await pg.wait_for_timeout(1200)
        print('avisos:', await pg.evaluate("[...document.querySelectorAll('#sec-warnings li')].map(l=>l.innerText.slice(0,60))"), ok(await pg.is_visible('#sec-warnings-card')))
        print('sessão atual marcada:', ok('Este dispositivo' in await pg.inner_text('#sessions')), '| chip 2 passos:', await pg.inner_text('#tfa-chip'))
        pw='#password-form '
        await pg.fill(pw+'[name=new_password]','abc'); print('medidor com "abc":', (await pg.inner_text('#pw-meter')).strip()[:40], ok('Fraca' in await pg.inner_text('#pw-meter'))); await pg.fill(pw+'[name=new_password]','Maria#2026-Segura'); print('   com "Maria#2026-Segura":', (await pg.inner_text('#pw-meter')).strip(), ok('Forte' in await pg.inner_text('#pw-meter')))
        await pg.fill(pw+'[name=current_password]','123456'); await pg.fill(pw+'[name=repeat]','diferente'); await pg.click(pw+'button.primary'); await pg.wait_for_timeout(300); print('palavras-passe diferentes ->', (await pg.inner_text(pw+'.ap-form-error')).strip(), ok('não coincidem' in await pg.inner_text(pw+'.ap-form-error')))
        await pg.fill(pw+'[name=current_password]','errada'); await pg.fill(pw+'[name=repeat]','Maria#2026-Segura'); await pg.click(pw+'button.primary'); await pg.wait_for_timeout(700); print('palavra-passe atual errada ->', (await pg.inner_text(pw+'.ap-form-error')).strip(), ok('incorreta' in await pg.inner_text(pw+'.ap-form-error')))
        print('--- ativar o 2.º passo')
        await pg.click('#tfa-toggle'); await pg.wait_for_selector('.ap-qr svg'); secret=re.sub(r'\s','',await pg.inner_text('.ap-secret')); print('QR code desenhado (SVG com', await pg.evaluate("document.querySelectorAll('.ap-qr svg rect, .ap-qr svg path').length"), 'elementos) | chave manual:', secret[:8]+'…', ok(len(secret)==32))
        await pg.fill('.ap-code-input','000000'); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(600); print('código errado ->', (await pg.inner_text('.ap-modal-card .ap-form-error')).strip(), ok(await pg.is_visible('.ap-modal-card .ap-form-error')))
        await pg.fill('.ap-code-input',totp(secret)); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_selector('.ap-codes'); codes=await pg.evaluate("[...document.querySelectorAll('.ap-codes code')].map(c=>c.textContent)"); print('código certo -> códigos de recuperação:', len(codes), codes[:2], ok(len(codes)==8))
        await pg.click('.ap-modal-card [data-done]'); await pg.wait_for_timeout(1000); print('   chip:', await pg.inner_text('#tfa-chip'), '| botão:', await pg.inner_text('#tfa-toggle'), ok(await pg.inner_text('#tfa-chip')=='Ativo'))
        await pg.screenshot(path=''+os.environ.get('LUMINA_SHOTS','/tmp')+'/ap_seguranca.png', full_page=True)
        print('--- desativar o 2.º passo'); await pg.click('#tfa-toggle'); await pg.wait_for_selector('.ap-modal-card [name=pw]'); await pg.fill('.ap-modal-card [name=pw]','123456'); await pg.fill('.ap-modal-card [name=code]',totp(secret)); await pg.click('.ap-modal-card [data-yes]'); await pg.wait_for_timeout(1100); print('desativado:', await pg.inner_text('#tfa-chip'), ok(await pg.inner_text('#tfa-chip')=='Desligado'))
        print('--- sessões'); c2=Client(); c2.req('POST','/api/auth.php?action=login',{'email':'maria@teste.pt','password':'123456'}); await pg.click('[data-tab=perfil]'); await pg.click('[data-tab=seguranca]'); await pg.wait_for_timeout(900)
        n=await pg.locator('.session-item').count(); print('sessões listadas:', n, ok(n>=2)); await pg.click('[data-revoke] >> nth=0'); await pg.wait_for_selector('.ux-confirm'); await pg.click('.ux-confirm [data-yes]'); await pg.wait_for_timeout(1000); print('   terminar uma sessão -> restam', await pg.locator('.session-item').count(), '| aviso:', (await toasts(pg))[-1:])
        print('\nERROS JS:', errs or 'nenhum')
        await b.close()
asyncio.run(main())
