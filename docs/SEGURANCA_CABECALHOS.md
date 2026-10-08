# Cabeçalhos de segurança, CSP e limite de pedidos

Estado: **implementado e testado** (Apache 2.4 + PHP 8.3 em Linux, Chromium). **Não testado** no XAMPP/Windows, no Raspberry Pi, nem em Firefox/Safari.
O que **não** está feito (por precisar da sua decisão) está na secção 4.

## 1. O que está aplicado (`.htaccess`)

| Cabeçalho | Valor | Para quê |
|---|---|---|
| `X-Frame-Options` | `SAMEORIGIN` | Ninguém põe o Lumina dentro de uma moldura de outro site (clickjacking). Para navegadores antigos. |
| `Content-Security-Policy` (**aplicada**) | `frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'` | O mesmo para navegadores modernos; um HTML injetado não consegue mudar o `<base>`, enviar formulários para fora nem carregar plugins. **Não toca em scripts nem estilos**, por isso não muda o aspeto nem o funcionamento. |
| `Content-Security-Policy-Report-Only` | política estrita (ver 2) | **Não bloqueia nada.** O navegador só regista na consola o que bloquearia. É a meta para travar scripts injetados (XSS). |
| `Permissions-Policy` | câmara, microfone, pagamentos, USB, sensores e ecrã desligados; `geolocation=(self)` | O Lumina não os usa. A localização serve só para o tempo no painel. |
| `X-Content-Type-Options` / `Referrer-Policy` / HSTS | `nosniff` / `strict-origin-when-cross-origin` / 1 ano (só em HTTPS) | Já existiam. |
| `X-Permitted-Cross-Domain-Policies` | `none` | Sem políticas Flash/PDF entre domínios. |
| `X-Powered-By` | removido | Não anuncia a versão do PHP. |

Os cabeçalhos vêm em **todas** as respostas (páginas, API, ficheiros estáticos, `/health`, erros 404).

> **Falha encontrada e corrigida:** a regra `Header always unset X-Powered-By` sozinha **não** remove o cabeçalho que o PHP acrescenta (fica noutra tabela do Apache). Com o `php.ini` do XAMPP (que anuncia a versão) a versão continuava visível. Agora há também `Header unset X-Powered-By`, e o teste com Apache real prova-o.

## 2. A política estrita (só de relatório) e o que falta para a aplicar

Medida com Chromium a percorrer o site (`tests/browser/csp_audit.py`: páginas públicas, login, as 14 secções do painel, Lumina, Área pessoal):

- **Política aplicada: 0 violações** (não parte nada).
- **Política estrita: 2 violações**, ambas *scripts inline de dados*:
  - `dashboard.php`: `window.GF_USER = {...}`
  - `area-pessoal.php`: `window.GF_CSRF = ...; window.GF_USER = {...}`
- O site **nunca usa `eval`**, e não tem frames nem `<base>`.
- O pequeno script do tema (no `<head>`) é permitido por **hash sha256**; um teste confirma que o hash continua certo em todas as páginas.
- Estilos: há ~130 atributos `style=""` no HTML, por isso a política mantém `style-src 'unsafe-inline'`. O ganho de segurança está nos **scripts**.
- `window.LUMINA_ACCOUNT_LANG` (idioma da conta, em `includes/partials/i18n_head.php`) também é um script inline de dados, mas só aparece com sessão iniciada; foi coberto pela medição das duas páginas acima.

### Passos para tornar `script-src` obrigatória (precisa do seu OK)

1. Trocar os scripts de dados por `<script type="application/json" id="gf-boot">…</script>` (não executável, logo não conta para a CSP) e um pequeno ficheiro `assets/js/boot.js` que o lê e define `window.GF_USER`, `window.GF_CSRF` e `window.LUMINA_ACCOUNT_LANG`. Sem mudança visual; mexe em `dashboard.php`, `area-pessoal.php`, `i18n_head.php` e na ordem de carregamento dos scripts.
2. Correr `tests/browser/csp_audit.py` até a política estrita dar **0 violações** e as suites do navegador (`tests/browser`) passarem.
3. Trocar `Content-Security-Policy-Report-Only` por `Content-Security-Policy` no `.htaccess` e atualizar `tests/sections/35_cabecalhos_http.php` (há um teste que impede este passo sem ninguém reparar).

**Não apliquei** estes passos: alteram o arranque das páginas e quero a sua autorização primeiro.

## 3. Origens externas e privacidade

| Origem | Quando | O que recebe |
|---|---|---|
| `api.open-meteo.com`, `geocoding-api.open-meteo.com` | Widget do tempo no painel (do **navegador** do utilizador) | O IP do utilizador e as coordenadas/cidade pedidas. Sem conta nem cookies. A mencionar na política de privacidade. |
| Google / Meta (OAuth) | Só quando o utilizador liga Google Calendar / Meta Ads (navegação para o site deles) | Os dados do início de sessão do próprio serviço. |
| `unpkg.com` (visualizador 3D Spline) | **Adormecido:** só carrega se alguém configurar `data-spline-url` em `assets/js/landing.js` | Código de terceiros a correr no site. Não ativar sem rever (ou sem o copiar para o projeto). |

Um teste (`35_cabecalhos_http.php`) falha se o JavaScript do site passar a falar com uma origem externa que não esteja nesta lista.

## 4. Não feito, de propósito

- **Tornar `script-src` obrigatória** (secção 2): aguarda a sua autorização.
- **HSTS atrás de um proxy que faz o HTTPS** (Cloudflare, nginx): o Apache do Lumina só vê HTTP e não envia HSTS; configure-o no proxy. Não mudei o HTTPS/domínio/alojamento (precisa de autorização).
- **`ServerTokens Prod` / `ServerSignature Off`** (esconder a versão do Apache): só se define na configuração do servidor, não no `.htaccess`. XAMPP: `conf/extra/httpd-default.conf`; Debian/Ubuntu: `/etc/apache2/conf-available/security.conf`.
- **`report-uri` / `report-to`** na CSP: exigiria um endereço que recolhesse relatórios (dados de navegação); não é necessário agora.
- **COOP/COEP/CORP**: sem benefício claro para este site e risco de partir o início de sessão com Google/Meta.

## 5. Limite de pedidos (`includes/rate_limit.php`, migração v17)

| Âmbito | Quem conta | Por omissão |
|---|---|---|
| `api` | cada utilizador (só a API; as páginas não contam) | 300 por minuto |
| `auth_login` | IP (todas as tentativas; as **falhas por conta** têm o limite próprio, 8 em 10 min) | 120 por 10 min |
| `auth_register` | IP | 20 por hora |
| `auth_forgot` / `auth_forgot_email` | IP / email-alvo (existindo ou não: a resposta é igual) | 15 / 5 por hora |
| `auth_token` | IP (links de recuperação e confirmação) | 60 por hora |
| `mail` | utilizador (convites de equipa, teste de envio) | 20 por hora |
| `export`, `import` | utilizador | 10 e 30 por hora |

- Medição real: uso intenso do painel (duas voltas rápidas por todas as secções) ≈ **75 pedidos/min** e **31 num só segundo** ao abrir o painel; o limite da API deixa ~4× de folga. Com o limite ligado, o percurso completo no Chromium (via Apache) e a suite inteira não receberam nenhum 429.
- Resposta ao exceder: **HTTP 429**, `Retry-After: <segundos>` e JSON `{"success":false,"error":"…","retry_after":N}`. O pedido não é processado (um convite recusado não cria o funcionário).
- **Falha «aberta»:** se a base de dados falhar ou a migração v17 faltar, o pedido passa e fica uma linha no log. O `/health` fica `degraded` se a v17 faltar. O `/health` não é limitado (não escreve nada); recomenda-se um limite no proxy.
- **Privacidade:** a tabela guarda só um HMAC do IP/utilizador/email e é limpa a cada 2 h.
- **Ajustar:** `LUMINA_RATE_<ÂMBITO>="máximo/segundos"` (ex.: `LUMINA_RATE_AUTH_REGISTER=50/3600`) e `LUMINA_RATE_MULTIPLIER=2` (multiplica todos os máximos; útil para uma escola inteira atrás de um só IP).
- **Atrás de um proxy:** sem configuração o Lumina **ignora** `X-Forwarded-For` (qualquer pessoa o consegue falsificar). Com proxy, defina `LUMINA_TRUSTED_PROXIES=10.0.0.5,192.168.0.0/24`; só então o IP real é lido (percorrendo da direita). `/0` e valores inválidos são recusados.
- IPv6 conta por rede `/64`.
- **Limites da abordagem:** janela fixa (nos 2 lados da fronteira da janela pode passar até o dobro do máximo); não protege contra inundação ao nível do servidor/rede (isso é do proxy/firewall); quem muda de IP evita o limite por IP (por isso o `forgot` também conta por email-alvo e o login por conta).
- **Achado (não alterado):** o registo responde «Este email já está registado» (409), o que permite descobrir contas. O limite por IP torna-o mais lento, não o elimina.

## 6. Como testar

```bash
php tests/run.php "Cabeçalhos"          # configuração (sem servidor)
php tests/run.php "Limite de pedidos"   # contagem, concorrência, falhas, API
bash tests/apache/iniciar_apache.sh start && python3 tests/apache/check_htaccess.py   # Apache real
LUMINA_URL=http://127.0.0.1:8091 python3 tests/browser/csp_audit.py                   # Chromium real (precisa de Playwright)
```

O CI do GitHub corre os três primeiros (o Apache no passo 5/5); o do Chromium é manual.
