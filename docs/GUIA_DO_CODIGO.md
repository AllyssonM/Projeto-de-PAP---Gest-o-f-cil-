# Guia do código para quem está a começar

Este guia complementa `docs/GUIA_DO_PROJETO.md` (que diz que ficheiro trata de cada aba). Aqui explica-se **como o código se liga** e **por onde começar a estudar**. Foi escrito a 11 de outubro de 2026.

## 1. Como tudo se liga

```
NAVEGADOR                              SERVIDOR (PHP)                      BASE DE DADOS (MariaDB)
dashboard.php + assets/js/*.js  --->   api/*.php + includes/*.php  --->    tabelas (products, sales...)
HTML, botões, gráficos                 valida sessão, permissões,          database/*.sql cria e
fetch('api/xxx.php')                   CSRF e dados; faz as contas         atualiza as tabelas
```

1. O **HTML** (`dashboard.php`) desenha a página e carrega os ficheiros JavaScript.
2. O **JavaScript** (`assets/js/`) reage ao clique e pede dados à API com `fetch`.
3. A **API** (`api/*.php`) confirma quem és e o que podes fazer; só depois lê ou grava.
4. A **lógica partilhada** fica em `includes/*.php` (login, cifra, e-mail, alertas, relatórios).

Não há framework: é PHP 8.3 simples, MariaDB e JavaScript puro.

## 2. Mapa das pastas

| Pasta / ficheiro | O que é | Quando lhe tocas |
|---|---|---|
| `index.php`, `dashboard.php`, `area-pessoal.php` | As páginas principais | Para acrescentar um botão ou secção |
| `assets/js/` | O comportamento (um ficheiro por aba) | Para mudar o que acontece no ecrã |
| `assets/css/` | O aspeto | Só com autorização: a identidade visual não se altera |
| `api/` | As «portas» que o JavaScript chama | Para mudar o que se lê ou grava |
| `includes/` | Peças partilhadas (`auth.php`, `crypto.php`, `mailer.php`, `stock_alerts.php`, `sales_report.php`...) | Para mudar regras comuns |
| `config/` | Configurações, sem segredos dentro | Para ligar serviços |
| `database/` | Desenho da BD e migrações `migracao_v2` a `v19` | Para acrescentar tabela ou coluna |
| `bin/` | Scripts de linha de comandos (backup, restauro, alertas) | Cópias de segurança e avisos agendados |
| `tests/` | 905 testes (`tests/run.php`, `tests/sections/*.php`) | Para confirmar que nada partiu |
| `docs/` | Guias | Para ler e documentar |
| `*.bat` | Atalhos Windows/XAMPP | Para instalar no PC |

Dica no Visual Studio: **Ctrl+Shift+F** procura um nome em todos os ficheiros (por exemplo `product_import`).

## 3. Exemplo completo: o botão «Importar CSV»

1. **Botão:** `dashboard.php`, no Estoque (`id="st-import"`).
2. **Comportamento:** `assets/js/product_import.js` abre a janela, envia o ficheiro com `fetch` para `api/product_import.php` com a ação `preview` (não grava nada).
3. **Porta:** `api/product_import.php` verifica login, CSRF e permissões e chama a lógica.
4. **Lógica:** `includes/product_import.php` lê o CSV, adivinha as colunas, valida cada linha e devolve novos / já existem / erros.
5. **Confirmar:** a ação `import` cria os produtos e variações, marcados com um `import_batch` (migração `v18`).
6. **Anular:** a ação `undo` só apaga os produtos do lote que ninguém mexeu.
7. **Testes:** `tests/sections/50_importar_produtos.php` (86 testes).

O mesmo padrão repete-se em todas as abas: botão no `.php` → `.js` com esse `id` → `api/` que chama → `includes/` → base de dados.

## 4. Regras que o código cumpre (não as quebres sem querer)

- Nunca segredos no código: chaves e tokens só em variáveis de ambiente (`tests/secret_scan.php` verifica).
- Cada negócio só vê os seus dados: `tenant_id` vem da sessão, nunca do pedido.
- Todo o `POST` leva token CSRF.
- SQL só com `?` (parâmetros).
- Permissões verificadas no servidor, não só no navegador.
- Não se altera o design (cores, layout, componentes) sem autorização.

## 5. Como correr e testar (Linux)

```
php -S 127.0.0.1:8086 -t . router.php
LUMINA_URL=http://127.0.0.1:8086/ php tests/run.php            # tudo (cerca de 4-5 minutos)
LUMINA_URL=http://127.0.0.1:8086/ php tests/run.php importar   # só uma secção
```

O servidor de teste tem de arrancar **com** `router.php`. Para os testes, os limites de pedidos sobem com variáveis `LUMINA_RATE_*` (nunca em produção). No Windows/XAMPP usa os `.bat` da raiz (**não testados por mim**).

## 6. Roteiro para aprender (uma coisa por semana)

1. **Ver:** ler `docs/GUIA_DO_PROJETO.md` e encontrar o `.php`, o `.js` e a API de uma aba.
2. **Seguir um clique:** por exemplo Notas (`notes.js` → `api/notes.php`; o cabeçalho explica tudo).
3. **Base de dados:** `database/gestao_facil.sql` e as migrações `v16` a `v19`; cada tabela é como uma folha de cálculo.
4. **Testar:** ler `tests/sections/60_alertas_estoque.php` e correr a suite.

## 7. Glossário

| Termo | Em português simples |
|---|---|
| API | Uma «porta» do servidor que o JavaScript chama |
| Migração | Ficheiro `.sql` que altera a BD e fica registado para não repetir |
| `tenant_id` | O número do negócio; impede ver dados de outro |
| CSRF | Um «bilhete» secreto em cada formulário |
| CSP | Regra do navegador sobre que scripts podem correr |
| Rate limit | Limite de tentativas, contra força bruta |
| Branch (ramo) | Cópia do código para trabalhar sem estragar o `main` |
| Draft PR | Pedido para juntar o ramo ao `main`, ainda em rascunho |
| Mock | Simulador de um serviço real, para testar sem o usar |
