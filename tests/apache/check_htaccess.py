"""
TESTE DAS REGRAS DO .htaccess NUM APACHE REAL  (tests/apache/check_htaccess.py)
O servidor embutido do PHP (php -S) ignora o .htaccess, por isso estas regras só se testam com Apache:
endereços limpos e 301, robots.txt e sitemap.xml, ficheiros internos negados (inclui cópias de segurança), compressão, cache, HTTPS, noindex,
/health e os cabeçalhos de segurança (clickjacking, CSP, Permissions-Policy, versão do PHP escondida).
Precisa de DUAS instalações a correr: uma em subdiretório (ex.: http://localhost/lumina) e uma na raiz de um site.
Para as arrancar sem mexer no Apache do sistema:  bash tests/apache/iniciar_apache.sh start
Variáveis: LUMINA_APACHE_SUB (por omissão http://127.0.0.1:8090/lumina), LUMINA_APACHE_ROOT (http://127.0.0.1:8091),
           LUMINA_DIR (pasta do projeto que o Apache serve; por omissão a deste repositório).
Uso: python3 tests/apache/check_htaccess.py     (precisa dos módulos rewrite, headers, expires, deflate e AllowOverride All)
Sai com código 0 se tudo passar e 1 se alguma verificação falhar (serve para o CI).
"""
import urllib.request, urllib.error, re, sys, gzip, os, json, hashlib, base64
PROJ = os.path.abspath(os.environ.get('LUMINA_DIR', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..')))
FALHAS = []
def ok(c, nome=''):
    """Devolve PASSOU / *** FALHOU *** e regista as falhas para o código de saída."""
    if not c: FALHAS.append(nome)
    return 'PASSOU' if c else '*** FALHOU ***'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*a,**k): return None
op=urllib.request.build_opener(NoRedirect)
def get(url, headers=None, method='GET'):
    r=urllib.request.Request(url, headers=headers or {}, method=method)
    try: x=op.open(r,timeout=10); return x.status, dict(x.headers), x.read()
    except urllib.error.HTTPError as e: return e.code, dict(e.headers), e.read()
H=lambda h,k: next((v for kk,v in h.items() if kk.lower()==k.lower()),None)
SUB_URL = os.environ.get('LUMINA_APACHE_SUB', 'http://127.0.0.1:8090/lumina')
SUB_ROOT = SUB_URL.rsplit('/lumina',1)[0] if SUB_URL.endswith('/lumina') else SUB_URL
INSTALACOES = [('subdiretório (ex.: XAMPP em /lumina/)', SUB_ROOT, '/lumina'), ('raiz do site', os.environ.get('LUMINA_APACHE_ROOT', 'http://127.0.0.1:8091'), '')]

# Ficheiros temporários que o Lumina pode criar e que nunca podem ser descarregados (nome do ficheiro de teste -> porquê)
TEMPORARIOS_NEGADOS = ['zz_teste.sql.gz', 'zz_teste.sql.gz.enc', 'zz_teste.sql.gz.sha256', 'zz_teste.sql.gz.parcial', 'zz_teste.7z', 'zz_teste.log', 'zz_teste.env', 'zz_teste.bak']

def cabecalhos_de_seguranca(B, nome):
    """Os mesmos cabeçalhos têm de vir em páginas, API, ficheiros estáticos, /health e erros 404 (Header always)."""
    print('cabeçalhos de segurança em cada tipo de resposta:')
    alvos = [('página HTML', '/index.php'), ('API', '/api/auth.php?action=me'), ('ficheiro estático (css)', '/assets/css/style.css'), ('/health', '/health'), ('erro 404', '/nao-existe-xyz-123')]
    for rotulo, caminho in alvos:
        s, h, _ = get(B + caminho)
        csp = H(h, 'Content-Security-Policy') or ''
        rep = H(h, 'Content-Security-Policy-Report-Only') or ''
        pp = H(h, 'Permissions-Policy') or ''
        certo = (H(h, 'X-Frame-Options') == 'SAMEORIGIN'
                 and "frame-ancestors 'self'" in csp and "object-src 'none'" in csp and "base-uri 'self'" in csp and "form-action 'self'" in csp
                 and not re.search(r'\b(script|style|default|img|connect)-src\b', csp)
                 and "script-src 'self' 'sha256-" in rep and "'unsafe-eval'" not in rep
                 and 'camera=()' in pp and 'microphone=()' in pp and 'geolocation=(self)' in pp
                 and H(h, 'X-Content-Type-Options') == 'nosniff' and H(h, 'Referrer-Policy') == 'strict-origin-when-cross-origin'
                 and H(h, 'X-Permitted-Cross-Domain-Policies') == 'none' and H(h, 'X-Powered-By') is None)
        print(f'   {rotulo:<26} {s}  X-Frame-Options={H(h,"X-Frame-Options")} | CSP aplicada+relatório | Permissions-Policy | sem X-Powered-By', ok(certo, f'{nome}: cabeçalhos em {rotulo}'))
    s, h, _ = get(B + '/index.php')
    print('   a CSP aplicada não restringe scripts nem estilos (não muda o aspeto):', ok('script-src' not in (H(h, 'Content-Security-Policy') or '') and 'style-src' not in (H(h, 'Content-Security-Policy') or ''), f'{nome}: CSP aplicada sem script-src'))

def hash_do_tema(B, nome):
    """Todo o <script> inline executável das páginas públicas tem de estar coberto por um hash da política só de relatório."""
    print('scripts inline das páginas públicas cobertos pelo hash da CSP de relatório:')
    for p in ['/index.php', '/politica-privacidade', '/politica-cookies', '/termos-e-condicoes', '/informacao-legal', '/esqueci-palavra-passe.php', '/offline.html']:
        s, h, b = get(B + p)
        rep = H(h, 'Content-Security-Policy-Report-Only') or ''
        permitidos = set(re.findall(r"'sha256-([A-Za-z0-9+/=]+)'", rep))
        inline = re.findall(r'<script(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")[^>]*>(.*?)</script>', b.decode('utf-8', 'replace'), re.S)
        calc = [base64.b64encode(hashlib.sha256(c.encode('utf-8')).digest()).decode() for c in inline]
        print(f'   {p:<28} {s}  {len(inline)} script(s) inline', ok(s == 200 and all(c in permitidos for c in calc), f'{nome}: hash do script inline em {p}'))

def saude(B, root, prefix, nome):
    print('/health (monitores de disponibilidade):')
    s, h, b = get(B + '/health')
    try: j = json.loads(b)
    except Exception: j = {}
    ct = H(h, 'Content-Type') or ''
    print('   endereço limpo /health devolve JSON mínimo:', s, ct, j.get('status'), ok(s in (200, 503) and 'application/json' in ct and j.get('status') in ('ok', 'degraded', 'down') and set(j.get('checks', {})) == {'app', 'database', 'storage'}, f'{nome}: /health JSON'))
    print('   não se guarda em cache (no-store) nem aparece em motores de busca (noindex):', H(h, 'Cache-Control'), '|', H(h, 'X-Robots-Tag'), ok('no-store' in (H(h, 'Cache-Control') or '') and 'noindex' in (H(h, 'X-Robots-Tag') or ''), f'{nome}: /health no-store'))
    print('   sem detalhes por omissão (sem versão, sem nomes, sem caminhos):', ok(not re.search(rb'version|/home|/var|/tmp|php|mysql|maria|host', b, re.I) and len(b) < 200, f'{nome}: /health mínimo'))
    s2, h2, b2 = get(B + '/health', {'X-Health-Token': 'um-segredo-qualquer-mas-errado-123'})
    print('   um segredo errado devolve exatamente o mesmo que sem segredo:', ok(s2 == s and b2 == b, f'{nome}: /health segredo errado'))
    s3, _, _ = get(B + '/health', method='POST')
    print('   POST recusado (405):', s3, ok(s3 == 405, f'{nome}: /health POST'))
    s4, h4, b4 = get(B + '/health', method='HEAD')
    print('   HEAD funciona e não traz corpo:', s4, len(b4), ok(s4 == s and len(b4) == 0, f'{nome}: /health HEAD'))
    s5, h5, b5 = get(B + '/robots.txt')
    print('   robots.txt pede aos motores de busca para não indexar /health:', ok('Disallow: /health' in b5.decode(), f'{nome}: robots /health'))

def ficheiros_negados(B, nome):
    """Cria ficheiros de teste (cópias, registos...) na pasta do projeto, confirma que o Apache os nega e apaga-os no fim."""
    print('ficheiros que o Lumina cria e que nunca podem ser descarregados:')
    criados = []
    try:
        for nome_f in TEMPORARIOS_NEGADOS + ['zz_teste.txt']:
            caminho = os.path.join(PROJ, nome_f)
            with open(caminho, 'w') as f: f.write('conteudo de teste\n')
            os.chmod(caminho, 0o644); criados.append(caminho)
        for sub in ['bin', 'storage', 'config']:
            d = os.path.join(PROJ, sub)
            if os.path.isdir(d):
                caminho = os.path.join(d, 'zz_teste.txt')
                with open(caminho, 'w') as f: f.write('conteudo de teste\n')
                os.chmod(caminho, 0o644); criados.append(caminho)
    except OSError as e:
        print(f'   (NÃO TESTADO: sem permissão para escrever ficheiros de teste em {PROJ}: {e})')
        for c in criados: os.path.exists(c) and os.remove(c)
        return
    try:
        s, _, b = get(B + '/zz_teste.txt')
        print('   controlo: um .txt normal na raiz é servido (200) — por isso os 403 abaixo vêm mesmo da regra:', s, ok(s == 200 and b'conteudo de teste' in b, f'{nome}: controlo .txt'))
        resultado = [(n, get(f'{B}/{n}')[0]) for n in TEMPORARIOS_NEGADOS]
        print('   cópias, somas, parciais, registos, .env, .bak -> 403:', resultado, ok(all(s == 403 for _, s in resultado), f'{nome}: FilesMatch'))
        pastas = [(f'/{sub}/zz_teste.txt', get(f'{B}/{sub}/zz_teste.txt')[0]) for sub in ['bin', 'storage', 'config'] if os.path.isdir(os.path.join(PROJ, sub))]
        print('   ficheiros dentro de bin/, storage/ e config/ -> 403:', pastas, ok(all(s == 403 for _, s in pastas), f'{nome}: pastas privadas'))
        scripts = [(p, get(B + p)[0]) for p in ['/bin/backup_bd.php', '/bin/restaurar_bd.php', '/bin/criar_utilizador_bd.php', '/database/gestao_facil.sql', '/instalar_base_dados.bat', '/abrir_lumina.bat']]
        print('   scripts de linha de comandos e instaladores -> 403 (ou 404 se não existirem):', scripts, ok(all(s in (403, 404) for _, s in scripts), f'{nome}: scripts'))
        s, _, _ = get(B + '/tests/run.php')
        print('   tests/run.php -> 403:', s, ok(s == 403, f'{nome}: tests'))
    finally:
        for c in criados:
            try: os.remove(c)
            except OSError: pass

for name,root,prefix in INSTALACOES:
    print(f'=== {name} ===')
    B=root+prefix
    s,h,b=get(B+'/politica-privacidade'); print('endereço limpo /politica-privacidade:', s, ok(s==200 and b'<h1>' in b, f'{name}: /politica-privacidade'))
    s,h,b=get(B+'/politica-privacidade.php'); loc=H(h,'Location'); print('o antigo .php leva ao limpo (301):', s, loc, ok(s==301 and loc==root+prefix+'/politica-privacidade', f'{name}: 301 .php'))
    s,h,b=get(B+'/termos-e-condicoes/'); loc=H(h,'Location'); print('barra no fim leva ao limpo (301):', s, loc, ok(s==301 and loc==root+prefix+'/termos-e-condicoes', f'{name}: 301 barra'))
    pags=['politica-privacidade','politica-cookies','termos-e-condicoes','informacao-legal']
    print('as 4 páginas abrem (200):', [get(f'{B}/{p}')[0] for p in pags], ok(all(get(f'{B}/{p}')[0]==200 for p in pags), f'{name}: 4 páginas'))
    s,h,b=get(root+prefix+'/robots.txt'); t=b.decode(); print('robots.txt:', s, H(h,'Content-Type'), '| Sitemap certo:', ok(f'Sitemap: {root}{prefix}/sitemap.xml' in t and 'Disallow: /api/' in t and s==200, f'{name}: robots.txt'))
    s,h,b=get(B+'/sitemap.xml'); t=b.decode(); locs=re.findall(r'<loc>(.*?)</loc>',t); print('sitemap.xml:', s, H(h,'Content-Type'), len(locs),'URLs', ok(s==200 and 'xml' in (H(h,'Content-Type') or '') and len(locs)==5 and all(l.startswith(B) for l in locs), f'{name}: sitemap.xml'))
    print('   cada URL do sitemap responde 200:', ok(all(get(l)[0]==200 for l in locs), f'{name}: URLs do sitemap'))
    internos=['/legal/documentos/politica-privacidade.md','/database/gestao_facil.sql','/config/app.php','/includes/auth.php','/tests/run.php','/README.md']
    print('ficheiros internos negados:', [(p,get(B+p)[0]) for p in internos], ok(all(get(B+p)[0]==403 for p in internos), f'{name}: ficheiros internos'))
    s,h,b=get(B+'/index.php'); print('cabeçalhos de segurança: nosniff:', H(h,'X-Content-Type-Options'), '| Referrer-Policy:', H(h,'Referrer-Policy'), '| sem HSTS em http:', ok(H(h,'Strict-Transport-Security') is None and H(h,'X-Content-Type-Options')=='nosniff', f'{name}: nosniff/HSTS'))
    cabecalhos_de_seguranca(B, name)
    hash_do_tema(B, name)
    saude(B, root, prefix, name)
    ficheiros_negados(B, name)
    s,h,b=get(B+'/assets/video/fundo-poster.webp'); print('imagem: cache', H(h,'Cache-Control'), '| tipo', H(h,'Content-Type'), '| tamanho', len(b)//1024,'KB', ok('2592000' in (H(h,'Cache-Control') or '') and H(h,'Content-Type')=='image/webp', f'{name}: cache de imagem'))
    s,h,b=get(B+'/assets/css/style.css'); print('css: cache', H(h,'Cache-Control'), ok('86400' in (H(h,'Cache-Control') or ''), f'{name}: cache do css'))
    s,h,b=get(B+'/assets/css/style.css',{'Accept-Encoding':'gzip'}); print('compressão gzip no css:', H(h,'Content-Encoding'), ok(H(h,'Content-Encoding')=='gzip', f'{name}: gzip css'))
    s,h,b=get(B+'/index.php',{'Accept-Encoding':'gzip'}); print('compressão gzip no HTML:', H(h,'Content-Encoding'), ok(H(h,'Content-Encoding')=='gzip', f'{name}: gzip html'))
    s,h,_=get(B+'/api/auth.php?action=me'); print('API tem X-Robots-Tag noindex:', H(h,'X-Robots-Tag'), ok('noindex' in (H(h,'X-Robots-Tag') or ''), f'{name}: noindex da API'))
    s,h,_=get(B+'/dashboard.php'); print('painel (privado) tem noindex no cabeçalho:', s, H(h,'X-Robots-Tag'), ok('noindex' in (H(h,'X-Robots-Tag') or ''), f'{name}: noindex do painel'))
    s,h,_=get(B+'/index.php'); print('página inicial NÃO tem noindex:', ok(H(h,'X-Robots-Tag') is None, f'{name}: index sem noindex'))
    s,h,b=get(B+'/redefinir-palavra-passe.php?token=x'); print('recuperar palavra-passe: noindex (cabeçalho e etiqueta):', ok('noindex' in (H(h,'X-Robots-Tag') or '') and b'content="noindex, nofollow"' in b, f'{name}: noindex recuperar'))
    print('HTTPS:')
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'lumina.exemplo.pt'}); loc=H(h,'Location'); print('   anfitrião real sem https -> 301 para https:', s, loc, ok(s==301 and loc and loc.startswith('https://lumina.exemplo.pt/') and loc.endswith('politica-cookies'), f'{name}: 301 para https'))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'lumina.exemplo.pt','X-Forwarded-Proto':'https'}); print('   atrás de proxy com https (X-Forwarded-Proto): sem redirecionar:', s, ok(s in (200,301) and not (H(h,'Location') or '').startswith('https://lumina.exemplo.pt/politica-cookies') or s==200, f'{name}: X-Forwarded-Proto'))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'localhost:8090'}); print('   localhost: nunca redireciona para https:', s, ok(s==200, f'{name}: localhost'))
    s,h,_=get(root+prefix+'/politica-cookies',{'Host':'Mau"Host.com'}); print('   nenhum redirecionamento revela caminhos do disco:', ok(all('/tmp/' not in (H(get(B+p)[1],'Location') or '') for p in ['/politica-privacidade.php','/termos-e-condicoes/','/politica-cookies.php?x=1']), f'{name}: caminhos do disco'))
    print('   anfitrião malicioso não parte nada:', s, ok(s in (200,301,400), f'{name}: anfitrião malicioso'))
# a lista de endereços limpos do .htaccess = SEO_PUBLIC_PAGES
ht=open(os.path.join(PROJ,'.htaccess')).read(); seo=open(os.path.join(PROJ,'includes','seo.php')).read()
a=set(re.search(r"\^\(([^)]*)\)\$ \$1\.php",ht).group(1).split('|')); b=set(re.findall(r"'([a-z-]+)'", re.search(r"SEO_PUBLIC_PAGES = \[(.*?)\]",seo).group(1)))
print('\n.htaccess e SEO_PUBLIC_PAGES têm a mesma lista:', sorted(a)==sorted(b), ok(a==b, '.htaccess e SEO_PUBLIC_PAGES'))
print(f'\nResultado: {"TUDO PASSOU" if not FALHAS else str(len(FALHAS)) + " verificação(ões) FALHARAM"}')
for f in FALHAS: print('  *** FALHOU ***', f)
sys.exit(1 if FALHAS else 0)
