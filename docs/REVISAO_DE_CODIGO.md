# Revisão de código: Lumina (versão unida)

Revisão de **segurança, desempenho, correção e manutenção** feita ao projeto resultante da junção do `gestao-facil-unido` (fluxo de caixa v2, revisão da base de dados, equipa) com o `gestao-facil-calendario` (assistente de IA, cartões associados, janela de impressão, menu de vidro). Os números de linha referem-se ao código **original** (antes das correções).

## Resumo

O código é sólido na base: consultas sempre com parâmetros preparados, isolamento por negócio em todas as consultas, CSRF em todos os pedidos que escrevem e permissões verificadas no servidor. Foram encontrados **6 problemas** no código original e **3 problemas de integração** causados pela junção (as duas versões assumiam modelos de dados diferentes). **Todos foram corrigidos e testados.** Ficam 9 recomendações por fazer, nenhuma delas urgente num ambiente de demonstração.

## Problemas críticos e importantes (todos corrigidos)

| # | Ficheiro | Linha | Problema | Gravidade |
|---|----------|-------|----------|-----------|
| 1 | `database/backup_antes_v2_20261001_1047.sql` | n/a | Cópia **real** da base de dados (utilizadores com palavra-passe cifrada, movimentos, produtos) dentro da pasta do site. Com a pasta `database/` sem proteção, é descarregável por URL. | 🔴 Crítico |
| 2 | `api/auth.php` | 23 | O login não tem limite de tentativas e o registo aceita palavras-passe de 6 caracteres: dá para adivinhar palavras-passe por força bruta. | 🟠 Alto |
| 3 | `auth.php:83`, `calendar.php:40,43,45`, `clients.php:59`, `data.php:49`, `profile.php:38`, `team.php:108` | vários | Os `catch` devolvem `$error->getMessage()` ao navegador. Num erro de base de dados isto revela o SQL e os nomes das colunas (testado: `SQLSTATE[22001] ... Data too long for column 'name'`). | 🟠 Alto |
| 4 | `includes/auth.php` | 8 | `session_start()` sem opções: o cookie de sessão não era `HttpOnly` nem `SameSite` e o PHP aceitava ids de sessão que não criou. | 🟠 Alto |
| 5 | `api/data.php` | 9 | A permissão só era verificada se o módulo estivesse no mapa (`isset($needs[$module])`): um módulo novo esquecido ficava aberto a qualquer funcionário. Falha aberto. | 🟡 Médio |
| 6 | `assets/js/reminder.js` | 97 | Pede `calendar.php?upcoming=1` a todos, e quem não tem a permissão "Calendário" recebe `403` em cada carregamento do painel. | 🟢 Baixo |
| 7 | `includes/ai_tools.php`, `api/cards.php` (integração) | n/a | O assistente de IA e os cartões isolavam por `user_id`. Com equipas, os dados do negócio são do **dono** (`tenant_id`): um funcionário veria dados vazios, e ao mudar para o dono as permissões por área seriam ignoradas. | 🟠 Alto |
| 8 | `includes/ai_tools.php` (integração) | n/a | A IA somava todos os movimentos (incluindo os **previstos**) e as contas **canceladas**, ao contrário do painel (só realizados contam para o saldo). Os valores dados no chat não batiam com o ecrã. | 🟠 Alto (valores errados) |
| 9 | `config/app.php` (integração) | n/a | Duas implementações incompatíveis de `app_timezone()` (uma constante, outra devolvendo um array) davam erro fatal ao carregar as duas versões. | 🟡 Médio |

### O que foi feito

1. Backup removido do pacote; `.htaccess` em `database/` e `docs/`, e na raiz (sem listagem de pastas e sem acesso a `.sql`, `.bat`, `.md`, `.log`).
2. Limite de tentativas de login: 8 falhas por conta (ou 40 por IP) em 10 minutos. Tabela `login_attempts` (migração v6). Sem a tabela, o login continua a funcionar. Compara sempre a palavra-passe, mesmo sem conta, para o tempo de resposta não revelar se o email existe.
3. Função `internal_error()`: regista os detalhes no log do PHP e devolve uma mensagem genérica. Interruptor `APP_DEBUG` em `config/app.php` para ver os detalhes ao desenvolver.
4. Cookie de sessão `HttpOnly`, `SameSite=Lax` (e `Secure` em HTTPS) e modo estrito.
5. `data.php` falha fechado: módulo desconhecido = 404.
6. `reminder.js` só pede as reuniões a quem tem a permissão.
7. IA e cartões usam o dono do negócio e as permissões do painel; cartões só para o dono; a agenda continua pessoal.
8. A IA conta só os movimentos realizados no saldo (os previstos aparecem à parte) e ignora as contas canceladas.
9. Mantida a versão do `unido` de `config/app.php`; os meus ficheiros deixaram de a duplicar.

## Sugestões (por fazer)

| # | Ficheiro | Linha | Sugestão | Categoria |
|---|----------|-------|----------|-----------|
| 1 | `api/auth.php`, `api/team.php` | 43, 102 | Exigir pelo menos 8 caracteres nas palavras-passe (hoje 6). | Segurança |
| 2 | `config/database.php` | 7-8 | A aplicação liga-se como `root` sem palavra-passe (padrão do XAMPP). Criar um utilizador MySQL só para a aplicação, como já existe para a IA, e pôr palavra-passe no `root`. | Segurança |
| 3 | `api/data.php` | 12-17 | As listas (movimentos, contas, produtos) não têm `LIMIT` nem paginação: o painel descarrega tudo a cada atualização. Com dezenas de milhares de registos vai ficar lento. **Não medido.** Filtrar por mês no servidor. | Desempenho |
| 4 | `api/auth.php` | 14 | O `logout` não exige CSRF (efeito pequeno: terminar a sessão de alguém). | Segurança |
| 5 | `api/auth.php` | 60 | Depois de mudar a palavra-passe, chamar `session_regenerate_id(true)` e terminar as outras sessões da conta. | Segurança |
| 6 | `api/auth.php` | login | O limite por email permite a alguém bloquear o login de uma conta durante 10 minutos de propósito. É o compromisso habitual; um captcha ou atrasos progressivos resolvem. | Segurança |
| 7 | `includes/ai_tools.php` | list_products | O filtro `status <> 'archived'` não usa o índice `(user_id, status)`; lê todos os produtos do dono (3 000 produtos: 6 ms). Aceitável, mas melhora com `status IN ('active')`. | Desempenho |
| 8 | projeto | n/a | Não há testes automáticos no projeto. Guardar os testes de API (isolamento, permissões e cartões) numa pasta `tests/`. | Manutenção |
| 9 | `api/auth.php` | register | O registo diz "Este email já está registado" (409), o que permite descobrir que emails existem. Inerente a um registo aberto. | Segurança |

## O que está bom

- **Consultas com parâmetros preparados em todo o projeto**; tabelas e colunas dinâmicas só vêm de listas fixas (por exemplo `$tables` em `data.php`).
- **Isolamento por negócio** (`tenant_id`) em todas as consultas, e `find_employee()` valida sempre que o funcionário pertence ao dono antes de mexer nele.
- **CSRF** em todos os pedidos que escrevem e `session_regenerate_id` no login e no registo.
- **`require_login()` volta a ler o utilizador em cada pedido**: desativar um funcionário ou mudar-lhe as permissões conta logo, e a palavra-passe provisória bloqueia a API até ser trocada.
- **Palavras-passe provisórias com `random_int`** e mostradas uma única vez.
- **Sem XSS no JavaScript**: o texto vindo de utilizadores passa por `esc()` ou por `textContent` (verificadas as 15 interpolações suspeitas: nenhuma é um risco).
- **Migração v2**: chaves estrangeiras com `ON DELETE`, índices por `(user_id, data)` e validações `CHECK`. Com 20 063 movimentos, 5 007 contas e 3 003 produtos, as consultas da IA usam os índices e demoram **8 ms ou menos**.
- **Camada de consultas da IA** (`ai_tools.php`): a IA nunca escreve SQL, as ferramentas têm parâmetros fechados, ligação separada só de leitura e auditoria de cada chamada.

## Veredicto

**Aprovar, com as correções aplicadas nesta versão.** Antes de pôr o site online (fora do XAMPP local): fazer as sugestões 1 e 2, ativar HTTPS e confirmar no Apache que o `.htaccess` bloqueia o acesso (abrir `http://localhost/lumina/database/gestao_facil.sql` deve dar 403).

## Como foi verificado

| Verificação | Resultado |
|---|---|
| Base de dados: instalação nova e atualização do `unido`, migrações v2 a v6, repetição | Sem erros (MariaDB 10.11) |
| Assistente de IA com 2 negócios e 7 funcionários: âmbito, permissões, regras da v2, abusos | 28 verificações, 0 falhas |
| Cartões: validação, SQL injection, isolamento e exclusividade do dono | 40 verificações, 0 falhas |
| Interface do dono e de 3 funcionários (menus, cartões escondidos, chat, janela de impressão) | 16 verificações, 0 erros de JavaScript |
| Login (limite, cookie, erros genéricos, falha fechado) | 12 verificações, 0 falhas |
| A interface do `unido` não mudou (11 combinações de aba e tema) | Medidas iguais e 0 píxeis diferentes, exceto as adições |

### Limites desta revisão

- O assistente de IA foi testado com um **modelo simulado**, não com a IA real (não há chave nem internet no ambiente de teste).
- O `.htaccess` não foi testado em Apache (o servidor de teste não o interpreta); a sintaxe é a padrão.
- O desempenho foi medido nas consultas da IA, não nas respostas do `api/data.php` (sugestão 3).
- Revisão feita sobre o código existente, sem testes de penetração externos.

---

# Segunda ronda: Área pessoal, segurança, tempo ativo, notas, painéis e Google Calendar

Revisão feita enquanto as funcionalidades novas eram construídas. Os testes automáticos encontraram os defeitos abaixo (**todos corrigidos**):

| # | Onde | Defeito encontrado | Como foi apanhado |
|---|------|--------------------|-------------------|
| 1 | `assets/js/notes.js` | Ao abrir "Nova nota", a gravação assíncrona da anterior colava o identificador à nota em branco e a **segunda nota sobrescrevia a primeira** (perda de dados). | Teste de navegador: a lista só mostrava 1 nota. |
| 2 | `api/google.php` | `header(...) && exit` nunca saía (o `header()` não devolve valor): o regresso da Google continuava a executar e dava erro. | Teste do fluxo OAuth. |
| 3 | `includes/google.php` | Um token que a Google já tinha invalidado fazia pedir logo "volta a ligar". Agora renova **uma vez** e repete o pedido. | Teste com token expirado. |
| 4 | `api/insights.php` | `lines` é palavra reservada do MariaDB (erro 500). | Teste da API. |
| 5 | `assets/css/ux.css` | Regras como `label { display: grid }` sobrepunham-se ao atributo `hidden` e mostravam opções escondidas. Passou a haver `[hidden] { display: none !important }`. | Teste do cartão do Google. |
| 6 | `api/data.php` | A chave `__proto__` passava o filtro dos campos do ramo. Agora só se aceitam chaves que começam por letra. | Teste de campos perigosos. |
| 7 | `assets/js/area-pessoal.js` | Um texto chamado `foto.png` abria uma pré-visualização partida. Passou a verificar que a imagem abre antes de a mostrar. | Teste de ficheiro falso. |
| 8 | Acessibilidade (axe-core) | Calendário com `role="gridcell"` incompleto, campos sem etiqueta em Contas, `aria-label` em ícones decorativos, cabeçalhos de tabela vazios, separador fora de região. **34 páginas auditadas: 0 violações.** | Auditoria automática. |

## Decisões de segurança das funcionalidades novas

- **Segredos cifrados** (libsodium): segredo do 2 passos e tokens do Google. A chave fica em `config/app_key.php` (criado automaticamente; **nunca partilhar nem enviar para o GitHub**).
- **2 passos** (TOTP, RFC 6238): validado com os vetores oficiais. Os códigos de recuperação guardam-se só como hash e servem uma vez.
- **Uploads:** o tipo é lido do **conteúdo**, não do nome; SVG limpo de scripts e entidades externas (recusado se o servidor não tiver a extensão DOM); ficheiros fora do alcance de URLs diretos e servidos só a quem pertence ao negócio.
- **Notas privadas:** nem o dono lê as notas privadas dos funcionários. **Tempo ativo:** um funcionário só vê os seus registos; corrigir exige motivo e fica na auditoria.
- **Google:** OAuth com PKCE e `state`; só 2 âmbitos (lista de calendários + eventos); nunca vemos a palavra-passe da Google.

## Recomendações novas (por fazer)

1. Os códigos TOTP podem ser reutilizados dentro da janela de ±30 s: guardar o último passo usado impede o reaproveitamento.
2. A sincronização automática do Google só corre quando o calendário é aberto (não há tarefa em segundo plano).
3. O PHP do servidor precisa das extensões `dom` e `gd` para SVG e para recodificar imagens (o XAMPP normal já as tem).
4. Edição de eventos já sincronizados com o Google (hoje só criar e apagar).

## Como foi verificado nesta ronda

Todas as suites foram repetidas no fim, depois da última alteração ao código. Resultados (verificações passadas, 0 falhas em todas):

| Área | API | Navegador |
|---|---|---|
| Segurança da conta (2 passos, sessões, palavra-passe) | 33 | — |
| Área pessoal (perfil, foto, empresa, logo, preferências) | 43 | 42 |
| Cartões associados | 40 | (incluído na Área pessoal) |
| Ocultar valores | — | 16 |
| Bloco de notas | 26 | 21 |
| Tempo ativo | 43 | 20 |
| Painéis por ramo | 36 | 27 |
| Google Calendar | 38 | 15 |
| Motor de ramos (11 ramos) e fluxo completo | — | 12 + 14 |
| Assistente de IA com equipas | 28 | — |
| Interface do dono e dos funcionários | — | 16 |
| **Acessibilidade (axe-core)** | — | **34 páginas/abas, 0 violações** |

Verificado também: sem scroll horizontal em nenhuma aba, em 5 tamanhos de ecrã (320 a 1920 px).

**Limites:** a ligação ao Google foi testada contra um servidor Google **simulado** (não há credenciais reais); a IA contra um modelo simulado; o `.htaccess` não foi testado em Apache; os cartões são demonstração; o axe-core não mede tudo (por exemplo, a experiência real com um leitor de ecrã).

---

# Terceira ronda: identidade Lumina, menu lateral, gráficos, PDF, assistente e páginas legais

Defeitos encontrados pelos testes e verificações visuais (**todos corrigidos**):

| # | Onde | Defeito | Como foi apanhado |
|---|------|---------|-------------------|
| 1 | `area-pessoal.php` | As janelas (confirmações, pré-visualização da foto, 2 passos) **não apareciam centradas por cima da página**: o estilo base (`reminder.css`) não era carregado nesta página e as janelas ficavam soltas no fundo, ao lado do rodapé. | Capturas de ecrã (o Playwright fazia scroll até ao botão e escondia o problema). |
| 2 | `assets/css/ux.css` | O `z-index` das janelas (70) perdia para o do `reminder.css` (30), e o aviso de cookies (40) ficava por cima do fundo desfocado. | Captura. |
| 3 | `assets/js/menu.js` + CSS original | O foco **não entrava no menu** ao abrir: os botões das abas têm `transition: .2s` (todas as propriedades), por isso o `visibility` herdado ainda valia `hidden` no instante em que o menu abria. (Afetava teclado e leitores de ecrã.) | Teste de foco com instrumentação de `focus()`. |
| 4 | `assets/js/app.js` | Os produtos podiam ser desenhados **antes** de o ramo ser aplicado e ficavam com o vocabulário geral (sem etiquetas nem estado). Corrida entre dois pedidos à API. | Teste do fluxo dos ramos. |
| 5 | `assets/js/charts.js` | Etiquetas do eixo cortadas nas pontas do gráfico no PDF. | PDF real visto em imagem. |
| 6 | PDF (impressão) | Secções em coluna única e cores claras difíceis de ler no papel. | PDF real visto em imagem. |
| 7 | Capturas do utilizador | "Saltar para o conteúdo" sem estilo, botão-link sublinhado, barras de scroll brancas. | Capturas enviadas. |

**Auxiliares de teste (não eram defeitos do produto):** o auxiliar de navegação tinha uma corrida com a animação de fecho do menu (220 ms); o teste da API contava o `.gitkeep`; a alteração a `config/legal.php` leva uns segundos a ser lida pelo servidor.

## Informação legal: decisões

- Os textos descrevem **o que o sistema faz de facto** (conferido com o código: o que se guarda, durações, fornecedores externos só quando ativados).
- **Não** se usam os dados, a logo nem os selos de outra empresa (PRR, República Portuguesa, União Europeia): afirmariam financiamento que não existe.
- São **textos-modelo**: rever por um jurista antes de uso comercial. `config/legal.php` tem o e-mail de privacidade por preencher.
- A página inicial **não carrega** conteúdos de terceiros por omissão (o 3D do Spline só carrega se houver um URL configurado).

## Como foi verificado nesta ronda

Todas as suites repetidas no fim: segurança 33 · Área pessoal API 43 / navegador 42 · cartões 40 · notas 26 / 21 · tempo 43 / 20 · painéis 36 / 27 · Google 38 / 15 · ramos 12 + 14 · IA com equipas 28 · interface 16 · privacidade 16 · gráficos e relatório 10 · assistente 9 · **menu, páginas legais, cookies e PDF 29** · **acessibilidade (axe-core) 42 páginas e estados, 0 violações** · sem scroll horizontal de 320 a 1920 px. **Limites:** Google e IA testados com simuladores; o `.htaccess` não foi testado em Apache; o PDF foi gerado com o Chromium (outros navegadores podem paginar de forma ligeiramente diferente); os textos legais não foram revistos por um jurista.

---

# Quarta ronda: revisão de redundância e funcionalidades para microempresários

## Revisão de código e redundância (feita antes de construir)

| Encontrado | Resolvido |
|---|---|
| `esc()` definida em 10 ficheiros JS; `$`/`$$` em 7; dois clientes de API quase iguais (`app.js` e `api-lite.js`) | Um só `assets/js/common.js` |
| Duas rotas para mudar a palavra-passe, com mínimos diferentes (6 e 8) | Uma só `change_password_for()` em `includes/auth.php`; mínimo 8 em todo o sistema |
| Três listas de colunas do utilizador e dois `GF_USER` montados à mão | `SESSION_COLUMNS`/`USER_COLUMNS` e `client_user_payload()` |
| Validações `is_numeric`/`trim` repetidas no `api/data.php` (e fracas: aceitavam valores negativos e datas inventadas) | `Validator` único; um movimento já não se liga a um cliente de outro negócio |
| Lógica de períodos duplicada (PHP e JavaScript) e código morto dos gráficos | Totais calculados no servidor (`module=series`) |
| `confirm()` do navegador a quebrar o design | `UX.confirm` em todo o lado |
| Esquema da base de dados em dois sítios (base e migrações) | Só nas migrações |
| Textos legais duplicados em PHP | Ficheiros `.md` num só sítio (`legal/documentos/`) |
| `preview.html`, `preview.css`, `abrir_preview.bat`, `storage/mail/.htaccess` | Removidos |

## Defeitos encontrados pelos testes e verificações (todos corrigidos)

| Onde | Defeito |
|---|---|
| Retenção | As sessões e os links de email expirados **nunca eram apagados**, ao contrário do que a política dizia. Agora: sessões terminadas 30 dias, links 7 dias. |
| `UX.modal` | Janelas com `role="dialog"` **sem nome acessível** (violação grave do axe). Agora usam o título ou um `label`. |
| `UX.modal` | O foco inicial ia para o primeiro botão (um `querySelector` com lista devolve o primeiro do documento, não o de maior prioridade). Agora há `data-autofocus`. |
| Menu lateral | O foco não entrava no menu: os botões de aba têm `transition: .2s` em todas as propriedades. |
| Produtos | Podiam ser desenhados antes de o ramo ser aplicado (vocabulário errado). |
| Perfil | Uma lista vazia de módulos escondia todas as abas. Passou a significar "todas". |
| Login | A regra de tamanho mínimo bloqueava quem já tinha uma palavra-passe mais curta. Só vale ao criar. |
| Área pessoal | As janelas apareciam soltas no fundo (faltava o `reminder.css`). |
| Marca | O "Esqueci-me" ficaria invisível no computador (usava a classe `.mobile-switch`). |

## Como foi verificado

- `php tests/run.php`: **155 testes**, 0 falhas (Validator, email, Markdown, páginas legais, conta, RGPD, dados, pesquisa, fiscal, recorrentes, fecho do dia, questionário, isolamento).
- Navegador: questionário 26 · registo rápido, pesquisa e datas fiscais 21 · fecho do dia e recorrentes 17 · PWA 8 · conta e RGPD 17 · menu e páginas legais 29 · gráficos 10 · interface 16 · notas 21 · tempo 20 · painéis 27 · Google 15 · Área pessoal 42 · privacidade 16 · ramos 12 e 14 · IA com equipas 28 · assistente básico 9.
- **Acessibilidade (axe-core): 62 páginas e estados, 0 violações.** Sem scroll horizontal de 320 a 1920 px.

## Limites conhecidos

- Google e a IA foram testados com servidores simulados. O STARTTLS real do SMTP e a revogação do Google ao eliminar a conta não foram testados de ponta a ponta.
- O `.htaccess` não foi testado em Apache. O PDF foi gerado com o Chromium.
- As datas fiscais são **indicativas**. Os textos legais são **modelos** e precisam de revisão por um jurista.
- O Lumina **não emite faturas** (não é software certificado pela AT).
- Não construído: anexos, importar CSV, orçamentos por categoria, notificações, e a profundidade por ramo (marcações, variantes de stock, funil imobiliário, ordens de reparação).

---

# Quinta ronda: SEO

Medido com o Lighthouse (telemóvel simulado) nas páginas públicas, antes e depois:

| | Antes | Depois |
|---|---|---|
| Performance (página inicial) | 66 | **97** |
| SEO | 91 | **100** (todas as páginas públicas) |
| Acessibilidade / Boas práticas | 100 / 100 | 100 / 100 |
| Maior elemento visível (LCP) | 24,4 s | **1,4 s** |
| Peso da página inicial | 4 592 KB | **58 KB** |

Feito: fundo 4,5 MB → 45 KB (WebP); títulos, descrições, canonical, Open Graph, Twitter e dados estruturados (JSON-LD) únicos por página; `robots.txt` e `sitemap.xml` gerados; endereços limpos com 301 a partir dos antigos; HTTPS forçado (menos localhost); compressão, cache e cabeçalhos de segurança; `noindex` em todas as páginas privadas e na API; `defer` nos scripts; links do rodapé com área de toque confortável. Detalhe e plano de backlinks em `docs/SEO.md`.

**Defeitos reais apanhados e corrigidos nesta ronda**
- O redirecionamento da barra final (`/termos-e-condicoes/`) enviava para um caminho com o **diretório do disco do servidor**: um alvo relativo num `.htaccess` leva o caminho físico à frente. Só se viu ao testar num **Apache real**; agora o destino constrói-se a partir do pedido original.
- Os links do rodapé tinham alvos de toque pequenos demais (apanhado pelo Lighthouse, não pelo axe).
- Três scripts das páginas legais bloqueavam o desenho (apanhado pelo teste de SEO).

**Verificação:** `php tests/run.php` **250** testes, 0 falhas (95 só de SEO: páginas, privadas, sitemap, rastreador de links quebrados, peso das imagens); regras do `.htaccess` testadas num **Apache 2.4 real** (subdiretório e raiz): endereços limpos, 301, robots, sitemap, ficheiros internos negados, compressão, cache, HTTPS e `noindex`; acessibilidade 62 de 62; sem scroll horizontal.

**Limites:** não se pode medir o Search Console nem os backlinks (dependem do teu domínio e de terceiros); o HTTPS só se testou com cabeçalhos simulados (sem certificado real); as páginas privadas não foram medidas no Lighthouse (precisam de sessão).

---

# Sexta ronda: funcionários, dinheiro (CSV, orçamentos, anexos) e correções transversais

## O que foi construído
- **Gestão de funcionários:** aba «Funcionários» (líder), «Mensagens e tarefas» (funcionário), sino de avisos, mensagens com «lida», tarefas, metas, observações, anúncios e cartão na Visão geral. Cálculos num só módulo (`includes/team.php`), com a fórmula explicada na interface.
- **Importar CSV, orçamentos por categoria e anexos de recibos**, já com testes de API **e de navegador**.

## Defeitos reais encontrados pelos testes (todos corrigidos)
| Onde | Defeito |
|---|---|
| Base de dados | O relógio do MySQL e o da aplicação podiam estar em fusos diferentes (a mesma entrada aparecia com 1 h de diferença). A ligação passou a usar o fuso da aplicação; há testes. |
| `google-cal.js` | Comparava a hora do servidor com o relógio do computador (errado noutro fuso). Passou a usar a hora do servidor. |
| `api/attachments.php`, `recurring.php`, `time.php` | Devolviam o `id` de OUTRA tabela (liam `lastInsertId()` depois de outra escrita). Agora há um teste que confirma o `id` de todas as APIs. |
| Exportação CSV do fluxo de caixa | Texto a começar por `=`, `+`, `-` ou `@` seria executado como fórmula no Excel. Neutralizado nas colunas de texto. |
| Orçamentos | O estado vinha da percentagem arredondada (79,99 % passava a 80 %; 100,01 € de 100 € não contava como ultrapassado). Agora usa cêntimos exatos. |
| Migrações | Uma tabela nova ficou com outra regra de comparação de texto e partia uma consulta entre tabelas. A migração fixa `utf8mb4_unicode_ci`. |
| Interface | Avisos de confirmação tapavam os botões do cabeçalho; foco do teclado perdia-se no sino; pedidos recusados (403) de funcionários sem permissão; classes de CSS a colidir com as abas Notas e gráficos. |
| Retenção | Entradas (90 dias) e avisos (60 e 180 dias) têm limpeza automática **testada**. |

## Como foi verificado
- `php tests/run.php`: **406 testes**, 0 falhas.
- Navegador: funcionários 73 · importar CSV 25 · orçamentos e anexos 20 · e todas as suites antigas repetidas (interface, painéis, Área pessoal, Google, questionário, fecho do dia, PWA, ramos, IA, assistente básico e as de API).
- **Acessibilidade 62 de 62** (páginas antigas) **e 24 de 24** (ecrãs novos, dois temas, líder e funcionário). Sem scroll horizontal de 320 a 1920 px.

## Limites
- «Online» depende do painel estar aberto (o Lumina pergunta de 45 em 45 s com o separador visível). Sem o navegador aberto, a pessoa fica «offline» ao fim de 30 min.
- Produtividade vem só das tarefas atribuídas; sem tarefas mostra «—».
- Os textos legais são modelos: o acompanhamento de trabalhadores deve ser revisto por um jurista e comunicado aos funcionários.

