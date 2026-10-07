# Guia do projeto — Lumina

**Projeto de PAP (Prova de Aptidão Profissional)** de Alisson Miguel Mota Madalena, Arthur Siqueira e Arthur Silva.

Este guia serve para saberes **o que cada parte do site faz e onde está o código**, quando andares pelo painel. Todos os ficheiros têm um cabeçalho a explicar o que fazem e com quem falam.

## Como o sistema está organizado

```
O navegador (HTML + CSS + JavaScript)  --pede dados-->  api/*.php  --lê/grava-->  Base de dados (MySQL)
        assets/js/*.js, assets/css/*.css                includes/*.php                database/*.sql
```

- **Páginas** (`index.php`, `dashboard.php`, `area-pessoal.php`): a estrutura do que se vê.
- **`assets/js/`**: o comportamento (cliques, formulários, gráficos). Cada ficheiro trata de uma aba.
- **`api/`**: as "portas" onde o JavaScript pede ou grava dados. Cada uma valida quem és e o que podes fazer.
- **`includes/`**: peças partilhadas (login, permissões, cifra, IA, Google...).
- **`database/`**: a estrutura da base de dados. O `instalar_base_dados.bat` aplica tudo.

## O que cada aba faz e onde está o código

| Aba / página | O que faz | JavaScript | API |
|---|---|---|---|
| Visão geral | Totais, alertas, movimentos recentes, dica do ramo | `app.js`, `profiles.js` | `data.php` |
| Fluxo de caixa | Entradas, saídas, previsões, categorias, relatórios | `cashflow.js` | `data.php` |
| Calendário | Eventos, aviso de reunião, Google Calendar | `calendar.js`, `reminder.js`, `google-cal.js` | `calendar.php`, `google.php` |
| Notas | Notas privadas ou partilhadas, etiquetas, guardar sozinho | `notes.js` | `notes.php` |
| Tempo ativo | Entrada/saída, pausas, histórico, correções | `time.js` | `time.php` |
| Painel do ramo | "Mais pedidos", "Produtos mais vendidos", "Distância e custo-benefício" | `insights.js` | `insights.php` |
| Clientes | Fichas de clientes | `app.js` | `clients.php` |
| Contas | Contas a pagar/receber e contas internas | `app.js` | `data.php` |
| Estoque | Produtos (imóveis, viaturas, serviços... conforme o ramo) | `app.js`, `profiles.js` | `data.php` |
| Equipa | Funcionários, permissões, palavras-passe provisórias | `team.js` | `team.php` |
| Assistente (✦) | Chat sobre os dados (modo básico ou IA) | `ai-chat.js` | `ai_chat.php` |
| Menu lateral (☰) | Abre/fecha o menu com todas as abas | `menu.js` | — |
| Gráficos | Período (dia, semana, mês, 12 meses, ano) e tipo (barras, linhas) | `charts.js` | `data.php` |
| Relatório / PDF | Cabeçalho com logo, gráfico e secções | `app.js` (renderReport), `charts.js` | `data.php` |
| Páginas legais | Privacidade, Cookies e Termos (públicas) | `lumina-footer.js` | `includes/legal.php`, `config/legal.php` |
| Questionário de arranque | 6 passos que configuram o Lumina para o negócio | `profile.js`, `profiles.js` | `profile.php` |
| Registo rápido (+) | Venda ou despesa em 3 toques | `quick-add.js` | `data.php` |
| Fecho do dia | Conferir a caixa ao fim do dia | `closing.js` | `closing.php` |
| Funcionários (líder) | Cartões, filtros, perfil, tarefas, metas, observações, anúncios | `staff.js`, `team-common.js`, `team-chat.js` | `staff.php`, `includes/team.php` |
| Mensagens e tarefas (funcionário) | Tarefas, metas, pausa, conversa com o líder | `work.js`, `team-chat.js` | `work.php`, `messages.php` |
| Sino de avisos | Entradas, mensagens, tarefas, metas, comentários e anúncios | `notifications.js` | `notifications.php`, `includes/team.php` |
| Importar CSV | Trazer movimentos do banco ou do Excel, com pré-visualização e anular | `import.js` | `import.php`, `includes/csv_import.php` |
| Orçamentos | Limite mensal por categoria | `budgets.js` | `budgets.php` |
| Anexos | Foto ou PDF de recibos em movimentos e contas | `attachments.js` | `attachments.php`, `includes/attachments.php` |
| Contas recorrentes | Renda, salários... geram contas pendentes | `recurring.js` | `recurring.php`, `includes/recurring.php` |
| Datas fiscais | Próximos prazos de IVA, Segurança Social e IRS | `fiscal.js` | `fiscal.php`, `config/fiscal.php` |
| Pesquisa Ctrl+K | Pesquisa global e comandos | `palette.js` | `search.php` |
| Os meus dados (RGPD) | Descarregar tudo / eliminar a conta | `area-pessoal.js` | `account.php` |
| Recuperar palavra-passe | Esqueci-me, confirmar email, convites | `account-pages.js`, `verify-banner.js` | `auth.php`, `includes/account_tokens.php`, `includes/mailer.php` |
| Instalável (PWA) | Instalar no telemóvel, página offline | `pwa.js` | `sw.js`, `manifest.webmanifest` |
| **Área pessoal** | Perfil, foto, empresa, logo, cartões, segurança | `area-pessoal.js`, `cards.js` | `me.php`, `security.php`, `cards.php`, `files.php` |

## Os conceitos principais

- **Ramo de atividade** (`assets/js/profiles.js`): cada ramo muda o vocabulário (o "produto" é um imóvel, uma viatura, um serviço, uma viagem...), os campos, as categorias, as dicas e a ferramenta de cálculo. **Para acrescentar um ramo novo:** copia um bloco de `RAMOS`, muda os textos e acrescenta a chave em `$allowed` no `api/profile.php`.
- **Chefe e funcionário:** o chefe (dono) vê tudo. O funcionário só vê as áreas que o chefe lhe der; por omissão é de *entrada de dados* (regista produtos, contas e clientes, sem ver totais e **sem eliminar**). Quem tem a área "Tempo ativo da equipa" é *gerente* (vê e corrige os horários de todos).
- **Isolamento dos dados:** todas as consultas filtram pelo negócio do dono. Um negócio nunca vê os dados de outro.
- **Ocultar valores** (`privacy.js`): troca os valores em euros por •••••• (os títulos ficam), e guarda a preferência.
- **Segurança:** palavras-passe cifradas, limite de tentativas, sessões ativas, 2 passos (TOTP), tokens do Google cifrados, auditoria. Ver `docs/REVISAO_DE_CODIGO.md`.

## Peças partilhadas (usa-as, não as repitas)

| O quê | Onde |
|---|---|
| `$`, `$$`, `esc()`, `api()`, `msg()`, `csrf` no JavaScript | `assets/js/common.js` |
| Avisos, confirmações e janelas | `assets/js/ux.js` (`UX.toast`, `UX.confirm`, `UX.modal`) |
| Validar dados do navegador | `includes/validator.php` (`Validator`) |
| Mudar palavra-passe, confirmar palavra-passe, verificar o 2.º passo | `includes/auth.php` |
| Enviar emails | `includes/mailer.php` e `includes/mail_templates.php` |
| Textos legais | `legal/documentos/*.md` (renderizados por `includes/markdown.php`) |

## Regra do design

**Não se muda o design original.** Qualquer funcionalidade nova reutiliza os componentes que já existem (cartões de vidro, botões, `UX.toast`, `UX.confirm`, `UX.modal`, `.seg`, `.panel`), as mesmas variáveis de cor e a mesma tipografia. Os estilos novos vão para ficheiros próprios (`ux.css`, `menu.css`, `legal.css`...), nunca por cima dos antigos.

## Instalação rápida

1. Copia a pasta para `C:\xampp\htdocs\lumina` e liga o Apache e o MySQL.
2. Faz duplo clique em `instalar_base_dados.bat` (aplica todas as migrações; seguro de repetir).
3. Abre `http://localhost/lumina/`.

**Opcional — Google Calendar:** preenche `config/google.php` (instruções no próprio ficheiro). Sem isso, o botão diz "não configurado".
**Opcional — IA:** põe a chave em `config/ai.php`. Sem chave, o assistente funciona em modo básico.

## Créditos

Projeto PAP — **Alisson Miguel Mota Madalena**, **Arthur Siqueira** e **Arthur Silva**.
Biblioteca de QR code: *QR Code Generator for JavaScript*, de Kazuhiko Arase (licença MIT), em `assets/js/vendor/`.
