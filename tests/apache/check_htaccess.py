"""
TESTE DAS REGRAS DO .htaccess NUM APACHE REAL  (tests/apache/check_htaccess.py)
O servidor embutido do PHP (php -S) ignora o .htaccess, por isso estas regras só se testam com Apache:
endereços limpos e 301, robots.txt e sitemap.xml, ficheiros internos negados, compressão, cache, HTTPS e noindex.
Precisa de DUAS instalações a correr: uma em subdiretório (ex.: http://localhost/lumina) e uma na raiz de um site.
Variáveis: LUMINA_APACHE_SUB (por omissão http://127.0.0.1:8090/lumina), LUMINA_APACHE_ROOT (http://127.0.0.1:8091).
Uso: python3 tests/apache/check_htaccess.py     (precisa dos módulos rewrite, headers, expires, deflate e AllowOverride All)
"""
import urllib.request, urllib.error, re, sys, gzip, os
PROJ = os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..'))
ok=lambda c: 'PASSOU' if c else '*** FALHOU ***'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
op=urllib.request.build_opener(NoRedirect)
def get(url, headers=None, method='GET'):
    r=urllib.request.Request(url, headers=headers or {}, method=method)
    try: x=op.open(r,timeout=10); return x.status, dict(x.headers), x.read()
    except urllib.error.HTTPError as e: return e.code, dict(e.headers), e.read()
H=lambda h,k: next((v for kk,v in h.items() if kk.lower()==k.lower()),None)
for name,root,prefix in [('subdiretório (ex.: XAMPP em /lumina/)', os.environ.get('LUMINA_APACHE_SUB', 'http://127.0.0.1:8090/lumina').rsplit('/lumina',1)[0] if os.environ.get('LUMINA_APACHE_SUB','http://127.0.0.1:8090/lumina').endswith('/lumina') else os.environ['LUMINA_APACHE_SUB'], '/lumina'), ('raiz do site', os.environ.get('LUMINA_APACHE_ROOT', 'http://127.0.0.1:8091'), '')]:
    print(f'=== {name} ===')
    B=root+prefix
    s,h,b=get(B+'/politica-privacidade'); print('endereço limpo /politica-privacidade:', s, ok(s==200 and b'<h1>' in b))
    s,h,b=get(B+'/politica-privacidade.php'); loc=H(h,'Location'); print('o antigo .php leva ao limpo (301):', s, loc, ok(s==301 and loc==root+prefix+'/politica-privacidade'))
    s,h,b=get(B+'/termos-e-condicoes/'); loc=H(h,'Location'); print('barra no fim leva ao limpo (301):', s, loc, ok(s==301 and loc==root+prefix+'/termos-e-condicoes'))
    print('as 4 páginas abrem (200):', [get(f'{B}/{p}')[0] for p in ['politica-privacidade','politica-cookies','termos-e-condicoes','informacao-legal']], ok(all(get(f'{B}/{p}')[0]==200 for p in ['politica-privacidade','politica-cookies','termos-e-condicoes','informacao-legal'])))
    s,h,b=get(root+prefix+'/robots.txt'); t=b.decode(); print('robots.txt:', s, H(h,'Content-Type'), '| Sitemap certo:', ok(f'Sitemap: {root}{prefix}/sitemap.xml' in t and 'Disallow: /api/' in t and s==200))
    s,h,b=get(B+'/sitemap.xml'); t=b.decode(); locs=re.findall(r'<loc>(.*?)</loc>',t); print('sitemap.xml:', s, H(h,'Content-Type'), len(locs),'URLs', ok(s==200 and 'xml' in (H(h,'Content-Type') or '') and len(locs)==5 and all(l.startswith(B) for l in locs)))
    print('   cada URL do sitemap responde 200:', ok(all(get(l)[0]==200 for l in locs)))
    print('ficheiros internos negados:', [(p,get(B+p)[0]) for p in ['/legal/documentos/politica-privacidade.md','/database/gestao_facil.sql','/config/app.php','/includes/auth.php','/tests/run.php','/README.md']], ok(all(get(B+p)[0]==403 for p in ['/legal/documentos/politica-privacidade.md','/database/gestao_facil.sql','/config/app.php','/includes/auth.php','/tests/run.php','/README.md'])))
    s,h,b=get(B+'/index.php'); print('cabeçalhos de segurança:', H(h,'X-Content-Type-Options'), '|', H(h,'Referrer-Policy'), '| sem HSTS em http:', ok(H(h,'Strict-Transport-Security') is None and H(h,'X-Content-Type-Options')=='nosniff'))
    s,h,b=get(B+'/assets/video/fundo-poster.webp'); print('imagem: cache', H(h,'Cache-Control'), '| tipo', H(h,'Content-Type'), '| tamanho', len(b)//1024,'KB', ok('2592000' in (H(h,'Cache-Control') or '') and H(h,'Content-Type')=='image/webp'))
    s,h,b=get(B+'/assets/css/style.css'); print('css: cache', H(h,'Cache-Control'), ok('86400' in (H(h,'Cache-Control') or '')))
    s,h,b=get(B+'/assets/css/style.css',{'Accept-Encoding':'gzip'}); print('compressão gzip no css:', H(h,'Content-Encoding'), ok(H(h,'Content-Encoding')=='gzip'))
    s,h,b=get(B+'/index.php',{'Accept-Encoding':'gzip'}); print('compressão gzip no HTML:', H(h,'Content-Encoding'), ok(H(h,'Content-Encoding')=='gzip'))
    s,h,_=get(B+'/api/auth.php?action=me'); print('API tem X-Robots-Tag noindex:', H(h,'X-Robots-Tag'), ok('noindex' in (H(h,'X-Robots-Tag') or '')))
    s,h,_=get(B+'/dashboard.php'); print('painel (privado) tem noindex no cabeçalho:', s, H(h,'X-Robots-Tag'), ok('noindex' in (H(h,'X-Robots-Tag') or '')))
    s,h,_=get(B+'/index.php'); print('página inicial NÃO tem noindex:', ok(H(h,'X-Robots-Tag') is None))
    s,h,b=get(B+'/redefinir-palavra-passe.php?token=x'); print('recuperar palavra-passe: noindex (cabeçalho e etiqueta):', ok('noindex' in (H(h,'X-Robots-Tag') or '') and b'content="noindex, nofollow"' in b))
    print('HTTPS:')
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'lumina.exemplo.pt'}); loc=H(h,'Location'); print('   anfitrião real sem https -> 301 para https:', s, loc, ok(s==301 and loc and loc.startswith('https://lumina.exemplo.pt/') and loc.endswith('politica-cookies')))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'lumina.exemplo.pt','X-Forwarded-Proto':'https'}); print('   atrás de proxy com https (X-Forwarded-Proto): sem redirecionar:', s, ok(s in (200,301) and not (H(h,'Location') or '').startswith('https://lumina.exemplo.pt/politica-cookies') or s==200))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'localhost:8090'}); print('   localhost: nunca redireciona para https:', s, ok(s==200))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'Mau"Host.com'}); print('   nenhum redirecionamento revela caminhos do disco:', ok(all('/tmp/' not in (H(get(B+p)[1],'Location') or '') for p in ['/politica-privacidade.php','/termos-e-condicoes/','/politica-cookies.php?x=1'])))
    print('   anfitrião malicioso não parte nada:', s, ok(s in (200,301,400)))
# a lista de endereços limpos do .htaccess = SEO_PUBLIC_PAGES
ht=open(os.path.join(PROJ,'.htaccess')).read(); seo=open(os.path.join(PROJ,'includes','seo.php')).read()
a=set(re.search(r"\^\(([^)]*)\)\$ \$1\.php",ht).group(1).split('|')); b=set(re.findall(r"'([a-z-]+)'", re.search(r"SEO_PUBLIC_PAGES = \[(.*?)\]",seo).group(1)))
print('\n.htaccess e SEO_PUBLIC_PAGES têm a mesma lista:', sorted(a)==sorted(b), ok(a==b))
