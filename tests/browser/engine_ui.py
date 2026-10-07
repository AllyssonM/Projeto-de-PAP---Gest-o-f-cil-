import os
import sys, asyncio, json; sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from playwright.async_api import async_playwright
from ai_lib import Client, sql
BASE = os.environ.get('LUMINA_URL', 'http://127.0.0.1:8080').rstrip('/'); ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
def set_profile(ramo, name='Mota Importz'):
    c=Client(); s,d=c.req('POST','/api/auth.php?action=login',{'email':'maria@teste.pt','password':'123456'}); c.csrf=d['csrf']
    s,r=c.req('POST','/api/profile.php',{'business_name':name,'business_type':ramo,'enabled_modules':[],'labels':{},'csrf':c.csrf}); return s,r
async def login(pg,email='maria@teste.pt',pw='123456'):
    await pg.goto(BASE+'/index.php'); await pg.fill('#login-form [name=email]',email); await pg.fill('#login-form [name=password]',pw)
    await pg.click('#login-form button[type=submit]'); await pg.wait_for_url('**/dashboard.php'); await pg.wait_for_timeout(1500)
    await pg.add_style_tag(content='html{scroll-behavior:auto!important} #weather-chip{visibility:hidden!important}')
EXPECT={  # o que o utilizador pediu explicitamente + coerência com o motor
 'real_estate':dict(tab='Imóveis',fields=5,stock=False,tool='Comissão da venda'),
 'cars':dict(tab='Viaturas',fields=6,stock=False,tool='Margem da viatura'),
 'uber':dict(tab='Viagens',fields=5,stock=False,tool='Ganho líquido da viagem'),
 'barber':dict(tab='Serviços',fields=3,stock=False,tool='Ganho por hora'),
 'online_store':dict(tab='Estoque',fields=0,stock=True,tool=None),
 'clothing':dict(tab='Estoque',fields=1,stock=True,tool=None),
 'restaurant':dict(tab='Menu',fields=3,stock=True,tool='Custo do prato'),
 'mechanic':dict(tab='Serviços e peças',fields=3,stock=True,tool='Orçamento'),
 'freelancer':dict(tab='Projetos',fields=3,stock=False,tool='Preço por hora'),
 'personal':dict(tab=None,fields=0,stock=False,tool='Regra 50 / 30 / 20'),
 'general':dict(tab='Estoque',fields=0,stock=True,tool=None)}
async def main():
    fails=0
    async with async_playwright() as p:
        b=await p.chromium.launch(); ctx=await b.new_context(locale='pt-PT', viewport={'width':1366,'height':900}); await ctx.add_init_script("localStorage.setItem('gf-reptil','off')")
        errs=[]
        for ramo,e in EXPECT.items():
            s,r=set_profile(ramo); assert s==200,(ramo,s,r)
            await ctx.clear_cookies(); pg=await ctx.new_page(); pg.on('pageerror',lambda x:errs.append(str(x))); pg.on('console',lambda m:errs.append(m.text) if m.type=='error' and 'open-meteo' not in m.text and 'ERR_FAILED' not in m.text else None)
            await login(pg)
            r=await pg.evaluate("""()=>{const T=document.querySelector('.nav-tab[data-section=stock]'),C=document.querySelector('.nav-tab[data-section=clients]'),f=document.querySelector('#product-form');
              const cfg=GFP.current();return {type:GFP.type,tab:T.classList.contains('ramo-off')?null:T.textContent.trim(),clientsOff:C.classList.contains('ramo-off'),fields:document.querySelectorAll('#product-extra .extra-field').length,
              stockHidden:[...f.querySelectorAll('[data-stock-only]')].every(l=>l.classList.contains('ramo-off')),stockShown:[...f.querySelectorAll('[data-stock-only]')].every(l=>!l.classList.contains('ramo-off')||(cfg.variants&&l.hasAttribute('data-qty-only'))),
              tool:document.querySelector('#ramo-tool').classList.contains('ramo-off')?null:document.querySelector('#ramo-tool h2').textContent,toolOut:document.querySelector('#ramo-tool .tool-out.main strong')?.textContent||null,
              tip:document.querySelector('#ramo-tip').textContent.slice(0,30),cats:[...document.querySelectorAll('#category-list option')].map(o=>o.value),expectCats:cfg.categories,
              title:document.querySelector('#stock h2').textContent,newTitle:document.querySelector('#stock .panel:nth-child(2) h2').textContent,html:document.documentElement.dataset.ramo,
              ccomp:document.querySelector('#client-form-title').textContent}}""")
            good = (r['type']==ramo and r['tab']==e['tab'] and r['fields']==e['fields'] and (r['stockHidden'] if not e['stock'] else r['stockShown']) and r['tool']==e['tool'] and r['tip'].startswith('💡') and all(c in r['cats'] for c in r['expectCats']) and r['html']==ramo)
            fails+=not good
            if not good: print('   DEBUG', {k:v for k,v in r.items() if k not in ('ccomp',)})
            print(f"{ramo:13s} {ok(good)} | aba: {r['tab']!s:16s} | título: {r['title']!s:22s} | novo: {r['newTitle']!s:26s} | campos próprios: {r['fields']} | estoque: {'sim' if e['stock'] else 'não'} | ferramenta: {r['tool']} {('→ '+r['toolOut']) if r['toolOut'] else ''}")
            if ramo=='personal': print('              (gestão pessoal) aba Clientes escondida:', ok(r['clientsOff']), '| aba Estoque escondida:', ok(r['tab'] is None))
            await pg.close()
        print('\nERROS JS:', errs or 'nenhum'); print('RESULTADO:', 'todos os ramos aplicam o seu vocabulário, campos e ferramentas' if not fails else f'{fails} ramos com falha')
        await b.close()
    set_profile('general')
if __name__=="__main__": asyncio.run(main())
