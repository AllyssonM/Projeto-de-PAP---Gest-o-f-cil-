<?php
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/includes/seo.php';
seo_noindex_header();                 // página privada: fora do Google (cabeçalho HTTP + etiqueta)
require_once __DIR__ . '/includes/auth.php';
$user = require_login();          // sem sessão -> volta para index.php
$firstName = explode(' ', trim($user['name'] ?? ''))[0];
$isOwner = $user['role'] === 'owner';
$can = fn(string $module): bool => can($user, $module);
// Preferências, foto e dados visuais da empresa: lidos aqui para a página já nascer certa (sem "flash").
$extra = db()->prepare('SELECT avatar_path, preferences FROM users WHERE id = ?');
$extra->execute([$user['id']]);
$ex = $extra->fetch() ?: [];
$prefs = !empty($ex['preferences']) ? (json_decode($ex['preferences'], true) ?: []) : [];
$hideValues = !empty($prefs['hide_values']);
$GLOBALS['lumina_account_lang'] = in_array($prefs['lang'] ?? '', ['pt', 'en', 'es'], true) ? $prefs['lang'] : null;   // idioma escolhido na conta (assets/js/i18n-boot.js)                       // "Ocultar valores" (ver assets/js/privacy.js)
$avatarUrl = !empty($ex['avatar_path']) ? 'api/files.php?type=avatar&id=' . (int)$user['id'] . '&v=' . substr(md5($ex['avatar_path']), 0, 8) : null;
$nameParts = preg_split('/\s+/u', trim($user['name'] ?? '')) ?: [];
$initials = mb_strtoupper(mb_substr($nameParts[0] ?? '?', 0, 1) . (count($nameParts) > 1 ? mb_substr(end($nameParts), 0, 1) : ''));
$coStmt = db()->prepare('SELECT business_name, logo_path, brand_color FROM business_profiles WHERE user_id = ? LIMIT 1');
$coStmt->execute([$user['tenant_id']]);
$co = $coStmt->fetch() ?: [];
$gfUser = client_user_payload($user) + ['modules' => TEAM_MODULES,
  'hideValues' => $hideValues, 'avatarUrl' => $avatarUrl, 'initials' => $initials,
  'company' => ['name' => $co['business_name'] ?? null, 'brandColor' => $co['brand_color'] ?? null,
                'logoUrl' => !empty($co['logo_path']) ? 'api/files.php?type=logo&v=' . substr(md5($co['logo_path']), 0, 8) : null]];
?>
<!doctype html>
<html lang="pt-PT"<?= $hideValues ? ' data-hide-values' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php seo_head(['title' => 'Painel — Lumina', 'index' => false]); ?>
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#061431">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/lumina-icon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/img/lumina-icon-64.png">
  <link rel="apple-touch-icon" href="assets/img/lumina-icon-180.png">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/fundo-video.css">
  <link rel="stylesheet" href="assets/css/clientes.css">
  <link rel="stylesheet" href="assets/css/profile.css">
  <link rel="stylesheet" href="assets/css/dashboard.css">
  <link rel="stylesheet" href="assets/css/cashflow.css">
  <link rel="stylesheet" href="assets/css/calendar.css">
  <link rel="stylesheet" href="assets/css/reminder.css">
  <link rel="stylesheet" href="assets/css/team.css">
  <link rel="stylesheet" href="assets/css/staff.css">
  <link rel="stylesheet" href="assets/css/ramos.css">
  <link rel="stylesheet" href="assets/css/notes.css">
  <link rel="stylesheet" href="assets/css/time.css">
  <link rel="stylesheet" href="assets/css/insights.css">
  <link rel="stylesheet" href="assets/css/variants.css">
  <link rel="stylesheet" href="assets/css/stock.css">
  <link rel="stylesheet" href="assets/css/meta.css">
  <link rel="stylesheet" href="assets/css/dashboard-polish.css">
  <link rel="stylesheet" href="assets/css/ai-chat.css">
  <link rel="stylesheet" href="assets/css/print-modal.css">
  <link rel="stylesheet" href="assets/css/cards.css">
  <link rel="stylesheet" href="assets/css/effects.css">
  <link rel="stylesheet" href="assets/css/ux.css">
  <link rel="stylesheet" href="assets/css/legal.css">     <!-- rodapé e aviso de cookies -->
  <link rel="stylesheet" href="assets/css/menu.css">
  <link rel="stylesheet" href="assets/css/wizard.css">
  <script>try{document.documentElement.dataset.tema=localStorage.getItem('gf-tema')||'dark'}catch(e){}</script>
  <?php include __DIR__ . '/includes/partials/i18n_head.php'; ?>
  <script>window.GF_USER = <?= json_encode($gfUser, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
</head>
<body>

<!-- ===== TOPO ===== -->
<header class="topbar">
  <!-- Botão do menu (☰): abre o menu lateral com todas as abas (assets/js/menu.js) -->
  <button type="button" id="menu-btn" class="icon-btn menu-btn" aria-label="Abrir menu" aria-expanded="false" aria-controls="main-menu" data-tip="Menu">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
  </button>
  <div class="topbar-title">
    <p class="eyebrow brand-eyebrow"><img src="assets/img/lumina-mark-96.png" alt="" width="22" height="21">LUMINA<span id="menu-current" class="menu-current"></span></p>
    <h1 id="business-title">Gestão financeira</h1>
    <p id="business-subtitle" class="muted">A tua gestão num só lugar.</p>
  </div>
  <div class="top-actions">
    <!-- Avisos (o sino): assets/js/notifications.js -->
    <button type="button" id="notif-btn" class="icon-btn notif-btn" aria-label="Avisos" aria-haspopup="dialog" aria-expanded="false" aria-controls="notif-panel" data-tip="Avisos"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg><span id="notif-badge" class="notif-badge" aria-hidden="true" hidden></span></button>
    <!-- Pesquisa global e comandos (Ctrl+K): assets/js/palette.js -->
    <button type="button" id="palette-btn" class="icon-btn" aria-label="Pesquisar (Ctrl+K)" data-tip="Pesquisar · Ctrl+K"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></svg></button>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Alternar tema">☀</button>
    <span id="profile-badge" class="profile-badge">📊 Negócio geral</span>
    <?php if ($isOwner): ?><button id="change-profile" class="button secondary">Alterar ramo</button><?php endif; ?>
    <button id="logout" class="button secondary">Sair</button>
    <!-- Ocultar/mostrar valores financeiros (assets/js/privacy.js) -->
    <button type="button" class="icon-btn" data-privacy-toggle data-tip="Ocultar valores" aria-pressed="false" aria-label="Ocultar valores">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
    </button>
    <!-- Botão de perfil: leva à Área pessoal. Mostra a foto ou, sem foto, as iniciais. -->
    <a href="area-pessoal.php" class="avatar-btn" id="avatar-btn" data-tip="Área pessoal" aria-label="<?= $avatarUrl ? 'Área pessoal' : e($initials) . ' — Área pessoal' ?>" title="Área pessoal">
      <?php if ($avatarUrl): ?><img src="<?= e($avatarUrl) ?>" alt=""><?php else: ?><span aria-hidden="true"><?= e($initials) ?></span><?php endif; ?>
    </a>
    <!-- Nome junto à fotografia (também leva à Área pessoal); a alteração da palavra-passe vive lá: Segurança -->
    <a href="area-pessoal.php" id="user-name" class="user-name-side" title="Área pessoal"><span class="un-name"><?= e($user['name'] ?? '') ?></span><?php if (!$isOwner): ?><small class="role-chip"><?= e($user['job_title'] ?: 'Funcionário') ?></small><?php endif; ?></a>
  </div>
</header>

<!-- ===== MENU DE ABAS ===== -->
<!-- Fundo escurecido atrás do menu lateral (clicar fecha) -->
<div id="menu-backdrop" class="menu-backdrop" hidden></div>
<nav class="tabs" id="main-menu" aria-label="Menu principal" inert>
  <div class="drawer-head">
    <span class="drawer-brand"><img src="assets/img/lumina-mark-96.png" alt="" width="30" height="29"><strong>Lumina</strong></span>
    <button type="button" id="menu-close" class="icon-btn" aria-label="Fechar menu"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
  </div>
  <?php if (!$isOwner): ?><button class="nav-tab active" data-section="home">Início</button><?php endif; ?>
  <?php if ($can('cashflow')): ?>
  <button class="nav-tab<?= $isOwner ? ' active' : '' ?>" data-section="overview">Visão geral</button>
  <button class="nav-tab" data-section="cashflow">Fluxo de caixa</button>
  <?php endif; ?>
  <?php if ($can('calendar')): ?><button class="nav-tab" data-section="calendar">Calendário</button><?php endif; ?>
  <button class="nav-tab" data-section="notes">Notas</button>      <!-- Bloco de notas: disponível para todos -->
  <button class="nav-tab" data-section="time">Tempo ativo</button>   <!-- registo de trabalho: todos marcam o seu turno -->
  <?php if ($can('cashflow') || $can('stock')): ?><button class="nav-tab ramo-off" data-section="insights"><span>Painel do ramo</span></button><?php endif; ?>   <!-- só aparece nos ramos com painel inteligente (profiles.js) -->
  <?php if ($can('clients')): ?><button class="nav-tab" data-section="clients"><span data-t="clientTab">Clientes</span></button><?php endif; ?>
  <?php if ($can('accounts')): ?><button class="nav-tab" data-section="accounts">Contas</button><?php endif; ?>
  <?php if ($can('stock')): ?><button class="nav-tab" data-section="stock"><span data-t="stockTab">Estoque</span></button><?php endif; ?>
  <?php if ($isOwner): ?><button class="nav-tab" data-section="staff">Funcionários</button><button class="nav-tab" data-section="team">Equipa</button><button class="nav-tab" data-section="meta">Meta Ads</button><?php endif; ?>
  <?php if (!$isOwner): ?><button class="nav-tab" data-section="work">Mensagens e tarefas</button><?php endif; ?>
  <button class="nav-tab" data-section="calculator">Calculadora</button>
  <button class="nav-tab" data-section="weather">Tempo</button>
  <?php if ($can('cashflow')): ?><button class="nav-tab" data-section="reports">Relatórios</button><?php endif; ?>
  <div class="nav-lang" role="group" aria-label="Idioma">
    <span class="nav-lang-title">Idioma</span>
    <button type="button" class="nav-tab nav-lang-btn" data-lang="pt" lang="pt" data-no-i18n>Português</button>
    <button type="button" class="nav-tab nav-lang-btn" data-lang="en" lang="en" data-no-i18n>English</button>
    <button type="button" class="nav-tab nav-lang-btn" data-lang="es" lang="es" data-no-i18n>Español</button>
  </div>
</nav>

<main class="container">
  <div id="status" class="status">Pronto.</div>

  <div class="welcome-row">
    <div>
      <p class="muted" id="today-message"></p>
      <h2>Olá, <span id="welcome-name"><?= e($firstName) ?></span> 👋</h2>
    </div>
    <div class="welcome-actions">
      <button type="button" id="weather-chip" class="weather-chip" data-go="weather" title="Ver previsão do tempo" hidden>⛅ --°</button>
      <?php if ($can('cashflow')): ?><button type="button" class="button secondary" id="close-day-btn">Fechar o dia</button><button class="button primary" data-go="cashflow" data-focus="#transaction-form [name=description]">+ Novo movimento</button><?php endif; ?>
    </div>
  </div>

  <section class="adaptive-banner">
    <div>
      <p class="eyebrow">PERFIL ADAPTADO</p>
      <h2 id="adaptive-title">Operações do negócio</h2>
      <p id="adaptive-subtitle">Escolhe o teu ramo para personalizar esta área.</p>
      <p id="ramo-tip" class="ramo-tip" role="note"></p>          <!-- dica do ramo (profiles.js) -->
    </div>
    <div id="adaptive-shortcuts" class="adaptive-shortcuts"></div>
  </section>

  <?php if (!$isOwner): ?>
  <!-- ================= INÍCIO (FUNCIONÁRIO) ================= -->
  <section id="home" class="section">
    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">O MEU PERFIL</p>
        <h2><?= e($user['name']) ?></h2>
        <div class="report-line"><span>Cargo</span><strong><?= e($user['job_title'] ?: '—') ?></strong></div>
        <div class="report-line"><span>Departamento</span><strong><?= e($user['department'] ?: '—') ?></strong></div>
        <div class="report-line"><span>Email</span><strong><?= e($user['email']) ?></strong></div>
        <p class="muted">Para alterar estes dados ou pedir acesso a outras áreas, fala com o responsável do negócio.</p>
      </article>
      <article class="panel">
        <p class="eyebrow">ATALHOS</p>
        <h2>As minhas áreas</h2>
        <div class="quick-list" id="home-shortcuts">
          <?php
          $shortcuts = ['cashflow' => ['◷', 'Fluxo de caixa', 'Registar entradas e saídas'], 'calendar' => ['▤', 'Calendário', 'A minha agenda'],
            'clients' => ['◎', 'Clientes', 'Contactos e fichas'], 'accounts' => ['▥', 'Contas', 'Pagar e receber'], 'stock' => ['▦', 'Estoque', 'Produtos e quantidades'], 'notes' => ['✎', 'Notas', 'As minhas notas e as da equipa'], 'time' => ['⏱', 'Tempo ativo', 'Registar entrada e saída']];
          foreach ($shortcuts as $module => [$icon, $label, $hint]):
            if (!in_array($module, ['notes', 'time'], true) && !$can($module)) continue; ?>
          <button class="quick-action" data-go="<?= $module ?>"><b><?= $icon ?> <span><?= e($label) ?></span></b><small><?= e($hint) ?></small></button>
          <?php endforeach; ?>
          <button class="quick-action" data-go="calculator"><b>▣ <span>Calculadora</span></b><small>Preço, margem e IVA</small></button>
        </div>
      </article>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= VISÃO GERAL ================= -->
  <section id="overview" class="section<?= $isOwner ? '' : ' hidden' ?>">
    <div class="summary-grid four">
      <article class="summary-card"><span>Saldo atual</span><strong id="balance">0,00 €</strong></article>
      <article class="summary-card income"><span>Entradas</span><strong id="income">0,00 €</strong></article>
      <article class="summary-card expense"><span>Saídas</span><strong id="expense">0,00 €</strong></article>
      <article class="summary-card"><span>A receber</span><strong id="receivable">0,00 €</strong></article>
    </div>

    <div id="cash-alerts" class="cash-alerts"></div>

    <!-- Estado do estoque (assets/js/stock.js): números reais da base de dados, só quem vê o Estoque -->
    <article class="panel stock-overview" id="stock-overview" hidden aria-live="polite"></article>

    <?php if ($isOwner): ?><!-- A tua equipa (assets/js/staff.js) --><article class="panel staff-overview" id="staff-overview" hidden></article><?php endif; ?>

    <!-- Próximas datas fiscais (assets/js/fiscal.js): só quem vê o Fluxo de caixa -->
    <article class="panel fiscal-card" id="fiscal-card" hidden>
      <div class="panel-heading"><div><p class="eyebrow">OBRIGAÇÕES</p><h2>Próximas datas fiscais</h2></div></div>
      <ul class="fiscal-list" id="fiscal-list"></ul>
      <p class="muted fiscal-note" id="fiscal-note"></p>
    </article>

    <div class="content-grid">
      <article class="panel">
        <div class="panel-heading">
          <div><p class="eyebrow">ANÁLISE</p><h2 id="chart-title">Movimento mensal</h2></div>
          <!-- Período (diário, semanal, mensal, 12 meses, anual) e tipo (barras ou linhas): desenhados por assets/js/charts.js -->
          <div id="chart-controls" class="chart-controls no-print"></div>
        </div>
        <div class="bar-chart" id="bar-chart" aria-label="Gráfico de entradas e saídas dos últimos 6 meses"></div>
        <div class="legend"><span><i class="legend-income"></i>Entradas</span><span><i class="legend-expense"></i>Saídas</span></div>
      </article>
      <article class="panel">
        <p class="eyebrow">ATALHOS</p>
        <h2>Ações rápidas</h2>
        <div class="quick-list">
          <button class="quick-action" data-go="cashflow" data-focus="#transaction-form [name=description]"><b>+ <span>Registar movimento</span></b><small>Atualizar o caixa</small></button>
          <button class="quick-action" data-go="clients" data-focus="#client-form [name=name]"><b>◎ <span data-t="qaClient">Adicionar cliente</span></b><small>Organizar contactos</small></button>
          <button class="quick-action" data-go="stock" data-focus="#product-form [name=name]"><b>▦ <span data-t="qaProduct">Adicionar produto</span></b><small>Controlar o estoque</small></button>
          <button class="quick-action" data-go="calculator"><b>▣ <span>Calcular preço</span></b><small>Margem e IVA</small></button>
        </div>
      </article>
    </div>

    <article class="panel">
      <div class="panel-heading">
        <div><p class="eyebrow">HISTÓRICO</p><h2>Movimentos recentes</h2></div>
        <button class="text-link" data-go="cashflow">Ver todos →</button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Descrição</th><th>Categoria</th><th>Data</th><th>Valor</th></tr></thead>
          <tbody id="recent-transactions"></tbody>
        </table>
      </div>
    </article>

    <?php if ($isOwner): ?>
    <article class="panel cards-panel" id="cards-panel">
      <div class="panel-heading">
        <div><p class="eyebrow">PAGAMENTOS</p><h2>Cartões associados</h2></div>
        <button type="button" class="button primary" id="card-add-btn">Adicionar cartão</button>
      </div>
      <div class="cards-list" id="cards-list" aria-live="polite"></div>
    </article>
    <?php endif; ?>
  </section>

  <!-- ================= FLUXO DE CAIXA ================= -->
  <section id="cashflow" class="section hidden">
    <!-- Orçamentos do mês por categoria (assets/js/budgets.js): quanto já gastaste do que definiste -->
    <article class="panel budgets-card" id="budgets-card" hidden>
      <div class="panel-heading"><div><p class="eyebrow">CONTROLO</p><h2>Orçamentos do mês</h2></div></div>
      <ul class="budget-list" id="budget-list" aria-live="polite"></ul>
      <form id="budget-form" class="inline-form" novalidate>
        <input name="category" list="budget-suggestions" placeholder="Categoria (ex.: Combustível)" aria-label="Categoria" maxlength="80" required>
        <datalist id="budget-suggestions"></datalist>
        <input name="monthly_limit" type="number" step="0.01" min="0.01" placeholder="Limite por mês (€)" aria-label="Limite por mês em euros" required>
        <button class="button primary">Definir orçamento</button>
      </form>
      <p class="message" id="budget-msg" role="alert"></p>
    </article>
    <div class="cf-toolbar panel">
      <label>Mês<input type="month" id="cf-month"></label>
      <label>Tipo<select id="cf-type"><option value="">Todos</option><option value="income">Entradas</option><option value="expense">Saídas</option></select></label>
      <label>Estado<select id="cf-status"><option value="">Todos</option><option value="paid">Realizado</option><option value="planned">Previsto</option></select></label>
      <label class="cf-search">Pesquisar<input type="search" id="cf-search" placeholder="Descrição, categoria ou cliente"></label>
      <button type="button" class="button secondary" id="cf-import">⬆ Importar CSV</button>
      <button type="button" class="button secondary" id="cf-export">⬇ Exportar CSV</button>
    </div>

    <div class="summary-grid four">
      <article class="summary-card"><span>Saldo inicial do mês</span><strong id="cf-opening">0,00 €</strong></article>
      <article class="summary-card income"><span>Entradas realizadas</span><strong id="cf-in">0,00 €</strong></article>
      <article class="summary-card expense"><span>Saídas realizadas</span><strong id="cf-out">0,00 €</strong></article>
      <article class="summary-card"><span>Saldo final do mês</span><strong id="cf-closing">0,00 €</strong></article>
    </div>

    <article class="panel cf-forecast">
      <div class="panel-heading">
        <div><p class="eyebrow">PREVISTO × REALIZADO</p><h2>Previsão do mês</h2></div>
        <strong id="cf-forecast-total" class="cf-forecast-total">0,00 €</strong>
      </div>
      <div class="cf-compare" id="cf-compare"></div>
      <p class="muted cf-note">O saldo previsto soma ao saldo final os movimentos marcados como <b>previstos</b> e as contas a pagar/receber ainda pendentes com vencimento neste mês.</p>
    </article>

    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">HISTÓRICO</p>
        <h2>Movimentos</h2>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Descrição</th><th>Categoria</th><th>Cliente</th><th>Tipo</th><th>Estado</th><th>Valor</th><th>Data</th><th><span class="sr-only">Ações</span></th></tr></thead>
            <tbody id="transactions"></tbody>
          </table>
        </div>
      </article>
      <article class="panel">
        <p class="eyebrow">NOVO REGISTO</p>
        <h2>Adicionar movimento</h2>
        <form id="transaction-form">
          <label>Tipo<select name="type"><option value="income">Entrada</option><option value="expense">Saída</option></select></label>
          <label>Descrição<input name="description" required></label>
          <label>Categoria<input name="category" list="category-list" placeholder="Ex.: Vendas, Renda, Fornecedores" required></label>
          <datalist id="category-list"></datalist>
          <label>Cliente<select name="client_id" id="transaction-client"><option value="">Sem cliente</option></select></label>
          <label>Valor<input name="amount" type="number" step="0.01" min="0.01" required></label>
          <label>Data<input name="occurred_at" type="datetime-local" required></label>
          <label>Estado<select name="status"><option value="paid">Realizado (já entrou/saiu)</option><option value="planned">Previsto (ainda vai acontecer)</option></select></label>
          <button class="button primary">Guardar</button>
        </form>
      </article>
    </div>

    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">CATEGORIAS</p>
        <h2>Para onde vai o dinheiro</h2>
        <div class="cf-categories">
          <div><h3 class="cf-cat-title positive">Entradas</h3><div id="cf-cat-income"></div></div>
          <div><h3 class="cf-cat-title negative">Saídas</h3><div id="cf-cat-expense"></div></div>
        </div>
      </article>
      <article class="panel">
        <p class="eyebrow">BOAS PRÁTICAS</p>
        <h2>Dicas de gestão do caixa</h2>
        <ol class="cf-tips">
          <li><b>Separa as contas pessoais das do negócio.</b> O dinheiro da empresa não é o teu salário.</li>
          <li><b>Define um pró-labore fixo</b> e regista-o como saída todos os meses.</li>
          <li><b>Regista tudo, todos os dias.</b> Até as pequenas despesas fazem diferença.</li>
          <li><b>Organiza por categorias</b> (vendas, fornecedores, renda, impostos…) para veres onde gastas.</li>
          <li><b>Planeia o futuro:</b> lança os movimentos previstos e acompanha previsto × realizado.</li>
          <li><b>Controla contas a pagar e a receber</b> e respeita os vencimentos.</li>
          <li><b>Cria uma reserva de emergência</b> com pelo menos 3 meses de custos fixos.</li>
          <li><b>Negoceia prazos:</b> recebe dos clientes mais cedo e paga aos fornecedores mais tarde.</li>
          <li><b>Calcula bem o preço de venda</b> com custos, margem e IVA (usa a Calculadora).</li>
          <li><b>Analisa o relatório no fim de cada mês</b> e corta os custos que não trazem retorno.</li>
        </ol>
      </article>
    </div>
  </section>

  <!-- ================= CALENDÁRIO ================= -->
  <section id="calendar" class="section hidden">
    <div class="content-grid">
      <article class="panel calendar-panel">
        <div class="calendar-head">
          <div><p class="eyebrow">AGENDA</p><h2 id="cal-title">&nbsp;</h2></div>
          <div class="calendar-nav">
            <button type="button" class="button secondary small" id="cal-prev" aria-label="Mês anterior">‹</button>
            <button type="button" class="button secondary small" id="cal-today">Hoje</button>
            <button type="button" class="button secondary small" id="cal-next" aria-label="Mês seguinte">›</button>
          </div>
        </div>
        <div class="calendar-weekdays" aria-hidden="true"><span>Seg</span><span>Ter</span><span>Qua</span><span>Qui</span><span>Sex</span><span>Sáb</span><span>Dom</span></div>
        <div class="calendar-grid" id="cal-grid" role="group" aria-label="Dias do mês"></div>
        <div class="calendar-legend"><span><i class="lg-today"></i>Hoje</span><span><i class="lg-events"></i>Dia com eventos</span><span class="muted">Clica num dia para criar um evento.</span></div>
      </article>

      <div class="calendar-side">
        <article class="panel">
          <p class="eyebrow">DIA SELECIONADO</p>
          <h2 id="cal-day-title">—</h2>
          <div id="cal-day-events" class="cards"></div>
        </article>

        <!-- Ligação ao Google Calendar (OAuth oficial; assets/js/google-cal.js, api/google.php) -->
        <article class="panel" id="google-panel">
          <p class="eyebrow">GOOGLE CALENDAR</p>
          <h2>Sincronizar com o Google <span class="ap-chip" id="g-chip">…</span></h2>
          <div id="g-body" aria-live="polite"><div class="skeleton-stack"><div class="skeleton" style="height:18px;width:70%"></div><div class="skeleton" style="height:36px"></div></div></div>
        </article>

        <article class="panel" id="cal-form-panel">
          <p class="eyebrow">NOVO EVENTO</p>
          <h2>Criar evento</h2>
          <form id="calendar-form">
            <label>Título<input name="title" maxlength="150" placeholder="Ex.: Reunião com cliente" required></label>
            <label>Data<input name="event_date" type="date" required></label>
            <div class="form-row">
              <label>Início<input name="start_time" type="time" value="09:00" required></label>
              <label>Fim<input name="end_time" type="time" value="10:00" required></label>
            </div>
            <label>Local<input name="location" maxlength="200" placeholder="Opcional"></label>
            <label>Descrição<textarea name="description" rows="3" maxlength="2000" placeholder="Opcional"></textarea></label>
            <label class="ap-check" id="cal-google-row" hidden><input type="checkbox" name="send_to_google"> Enviar também para o Google Calendar</label>
            <button class="button primary" id="cal-save">Guardar evento</button>
          </form>
          <p id="cal-message" class="status cal-message hidden" role="status"></p>
        </article>
      </div>
    </div>
  </section>

  <!-- ================= NOTAS ================= -->
  <section id="notes" class="section hidden">
    <div class="notes-layout">
      <!-- Lista: pesquisa, etiquetas e notas (fixadas primeiro) -->
      <article class="panel notes-list-panel">
        <div class="panel-heading">
          <div><p class="eyebrow">BLOCO DE NOTAS</p><h2>As minhas notas</h2></div>
          <button type="button" class="button primary" id="note-new">+ Nova nota</button>
        </div>
        <div class="notes-tools">
          <input type="search" id="note-search" placeholder="Pesquisar notas…" aria-label="Pesquisar notas" maxlength="80">
          <div id="note-tags" class="note-tagbar" role="group" aria-label="Filtrar por etiqueta"></div>
        </div>
        <div id="note-list" class="note-list" aria-live="polite"></div>
      </article>
      <!-- Editor: guarda-se sozinho enquanto escreves -->
      <article class="panel note-editor" aria-label="Editor de nota" data-color="teal">
        <div id="note-empty" class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">✍️</span><b>Escolhe uma nota ou cria uma nova</b><span>As notas guardam-se automaticamente.</span></div>
        <form id="note-form" hidden novalidate>
          <input name="title" class="note-title" placeholder="Título" maxlength="160" aria-label="Título da nota">
          <textarea name="detail" class="note-body" rows="10" placeholder="Escreve aqui…" maxlength="20000" aria-label="Texto da nota"></textarea>
          <div class="note-colors" role="radiogroup" aria-label="Cor da nota">
            <label class="note-swatch note-teal" title="Turquesa"><input type="radio" name="color" value="teal" checked><span class="sr-only">Turquesa</span></label>
            <label class="note-swatch note-blue" title="Azul"><input type="radio" name="color" value="blue"><span class="sr-only">Azul</span></label>
            <label class="note-swatch note-purple" title="Roxo"><input type="radio" name="color" value="purple"><span class="sr-only">Roxo</span></label>
            <label class="note-swatch note-coral" title="Coral"><input type="radio" name="color" value="coral"><span class="sr-only">Coral</span></label>
            <label class="note-swatch note-amber" title="Âmbar"><input type="radio" name="color" value="amber"><span class="sr-only">Âmbar</span></label>
            <label class="note-swatch note-slate" title="Cinza"><input type="radio" name="color" value="slate"><span class="sr-only">Cinza</span></label>
          </div>
          <div class="note-tags-edit"><span id="note-chips"></span><input id="note-tag-input" placeholder="+ etiqueta" maxlength="24" aria-label="Adicionar etiqueta (Enter)"></div>
          <div class="note-options">
            <button type="button" class="button secondary small" id="note-pin" aria-pressed="false">📌 Fixar</button>
            <label class="ap-check"><input type="checkbox" name="shared"> Partilhar com a equipa</label>
          </div>
          <div class="note-foot"><span id="note-status" class="muted" role="status" aria-live="polite"></span><button type="button" class="button danger small" id="note-delete" hidden>Apagar</button></div>
        </form>
      </article>
    </div>
  </section>

  <!-- ================= TEMPO ATIVO ================= -->
  <section id="time" class="section hidden">
    <div class="time-grid">
      <!-- O meu turno: contador em tempo real. Estados: Fora de turno / Em turno / Em pausa -->
      <article class="panel time-clock">
        <div><p class="eyebrow">REGISTO DE TRABALHO</p><h2>O meu turno</h2></div>
        <div class="time-state" id="time-state" data-state="off"><i class="time-dot" aria-hidden="true"></i><span>Fora de turno</span></div>
        <output class="time-counter" id="time-counter" aria-label="Tempo trabalhado neste turno">00:00:00</output>
        <p class="muted" id="time-since">A carregar…</p>
        <p class="muted" id="time-pause-for" hidden></p>
        <div class="time-buttons">
          <button type="button" class="button primary" id="time-in">Registar entrada</button>
          <button type="button" class="button secondary" id="time-pause" hidden>Pausar</button>
          <button type="button" class="button primary" id="time-resume" hidden>Retomar</button>
          <button type="button" class="button danger" id="time-out" hidden>Registar saída</button>
        </div>
        <span id="time-live" class="sr-only" role="status" aria-live="polite"></span>
        <div class="time-totals"><div><span>Hoje</span><b id="time-today">0h00</b></div><div><span>Esta semana</span><b id="time-week">0h00</b></div><div><span>Este mês</span><b id="time-month">0h00</b></div></div>
      </article>

      <!-- Histórico: filtros, totais, exportar. O administrador e os gerentes veem todos e podem corrigir. -->
      <article class="panel time-history">
        <div class="panel-heading"><div><p class="eyebrow">HISTÓRICO</p><h2>Horas trabalhadas</h2></div></div>
        <div class="time-filters">
          <label>De<input type="date" id="time-from"></label>
          <label>Até<input type="date" id="time-to"></label>
          <label data-manager hidden>Funcionário<select id="time-employee"><option value="">Todos os funcionários</option></select></label>
          <span class="spacer"></span>
          <button type="button" class="button secondary" id="time-manual" data-manager hidden>+ Registo manual</button>
          <a class="button secondary" id="time-export" href="#" download>Exportar CSV</a>
        </div>
        <div id="time-totals" aria-live="polite"></div>
        <div id="time-table"></div>
      </article>
    </div>
  </section>

  <!-- ================= PAINEL INTELIGENTE DO RAMO ================= -->
  <section id="insights" class="section hidden">
    <article class="panel">
      <p class="eyebrow">PAINEL DO TEU RAMO</p>
      <h2 id="ins-title">Painel</h2>
      <div class="ins-controls" data-view>
        <div class="ins-seg" role="group" aria-label="Período">
          <button type="button" data-period="day" aria-pressed="false">Dia</button>
          <button type="button" data-period="week" class="on" aria-pressed="true">Semana</button>
          <button type="button" data-period="month" aria-pressed="false">Mês</button>
          <button type="button" data-period="custom" aria-pressed="false">Personalizado</button>
        </div>
        <div id="ins-custom" hidden><label>De<input type="date" id="ins-from"></label><label>Até<input type="date" id="ins-to"></label></div>
        <div id="ins-filters" class="ins-controls"></div>
      </div>
      <p class="ins-range" data-view>Período: <span id="ins-range">—</span></p>
      <div id="ins-demo" class="ins-demo-bar" data-view hidden></div>
      <p id="ins-noview" class="muted" hidden>Podes registar vendas e viagens aqui em baixo. Os totais e comparações só os vê o administrador.</p>
      <div id="ins-body" data-view></div>
    </article>
    <article class="panel">
      <p class="eyebrow">REGISTAR</p>
      <h2>Novo registo</h2>
      <div id="ins-form"></div>
    </article>
  </section>

  <!-- ================= CLIENTES ================= -->
  <section id="clients" class="section hidden">
    <div class="summary-grid two">
      <article class="summary-card"><span data-t="clientTotal">Total de clientes</span><strong id="client-count">0</strong></article>
      <article class="summary-card"><span>Valorização estimada (saldo × 12)</span><strong id="business-value">0,00 €</strong></article>
    </div>
    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">CRM</p>
        <h2 data-t="clientTitle">Clientes</h2>
        <div id="clients-list" class="client-grid"></div>
      </article>
      <article class="panel">
        <p class="eyebrow" data-t="clientEyebrow">NOVO CLIENTE</p>
        <h2 id="client-form-title" data-t="clientNewTitle">Adicionar cliente</h2>
        <form id="client-form">
          <input type="hidden" name="id">
          <label>Nome<input name="name" required></label>
          <label>Email<input name="email" type="email"></label>
          <label>Telefone<input name="phone"></label>
          <label>NIF<input name="tax_number"></label>
          <label>Categoria<input name="category" placeholder="Ex.: Cliente habitual"></label>
          <label>Notas<textarea name="notes" rows="3"></textarea></label>
          <div class="form-actions">
            <button class="button primary" data-t="clientSave">Guardar cliente</button>
            <button type="button" id="cancel-client" class="button secondary hidden">Cancelar edição</button>
          </div>
        </form>
      </article>
    </div>
  </section>

  <!-- ================= CONTAS ================= -->
  <section id="accounts" class="section hidden">
    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">CONTAS</p>
        <h2>As tuas contas</h2>
        <div id="accounts-list" class="cards"></div>
      </article>
      <article class="panel">
        <p class="eyebrow">NOVA CONTA</p>
        <h2>Adicionar conta</h2>
        <form id="account-form">
          <label>Nome<input name="name" required></label>
          <label>Tipo<select name="type"><option value="cash">Caixa</option><option value="bank">Banco</option><option value="reserve">Reserva</option><option value="investment">Investimento</option></select></label>
          <label>Saldo inicial<input name="balance" type="number" step="0.01" min="0"></label>
          <label>Meta (opcional)<input name="target_amount" type="number" step="0.01" min="0" placeholder="Ex.: reserva de 3 meses"></label>
          <button class="button primary">Guardar</button>
        </form>
      </article>
    </div>
    <article class="panel">
      <p class="eyebrow">PAGAMENTOS</p>
      <h2>Contas a pagar e receber</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Título</th><th>Cliente</th><th>Tipo</th><th>Valor</th><th>Vencimento</th><th>Estado</th><th><span class="sr-only">Ações</span></th></tr></thead>
          <tbody id="bills"></tbody>
        </table>
      </div>
      <form id="bill-form" class="inline-form">
        <select name="direction" aria-label="Tipo de conta"><option value="payable">A pagar</option><option value="receivable">A receber</option></select>
        <input name="title" placeholder="Título" aria-label="Título da conta" required>
        <select name="client_id" id="bill-client" aria-label="Cliente"><option value="">Sem cliente</option></select>
        <input name="amount" type="number" step="0.01" placeholder="Valor" aria-label="Valor" required>
        <input name="due_date" type="date" aria-label="Data de vencimento" required>
        <input name="counterparty" placeholder="Entidade" aria-label="Entidade">
        <button class="button primary">Adicionar</button>
      </form>
    </article>
  
    <!-- Contas e despesas recorrentes (renda, salários, seguros...): geram sozinhas as contas pendentes (assets/js/recurring.js) -->
    <article class="panel recurring-card" id="recurring-card">
      <div class="panel-heading"><div><p class="eyebrow">AUTOMÁTICO</p><h2>Contas recorrentes</h2></div></div>
      <p class="muted">A renda, os salários, o seguro... Registas uma vez e o Lumina cria as contas pendentes todos os meses.</p>
      <ul class="recurring-list" id="recurring-list" aria-live="polite"></ul>
      <form id="recurring-form" class="inline-form" novalidate>
        <select name="direction" aria-label="Tipo"><option value="payable">A pagar</option><option value="receivable">A receber</option></select>
        <input name="title" placeholder="Ex.: Renda da loja" aria-label="Título" maxlength="160" required>
        <input name="amount" type="number" step="0.01" min="0.01" placeholder="Valor" aria-label="Valor" required>
        <select name="frequency" aria-label="Frequência"><option value="monthly">Todos os meses</option><option value="weekly">Todas as semanas</option><option value="quarterly">Todos os trimestres</option><option value="yearly">Todos os anos</option></select>
        <input name="start_date" type="date" aria-label="Primeira data" required>
        <input name="counterparty" placeholder="Entidade (opcional)" aria-label="Entidade" maxlength="160">
        <button class="button primary">Adicionar</button>
      </form>
      <p class="message" id="recurring-msg" role="alert"></p>
    </article>
  </section>

  <!-- ================= ESTOQUE ================= -->
  <section id="stock" class="section hidden">
    <div class="content-grid">
      <!-- Lista dos produtos do negócio. O texto muda com o ramo (Imóveis, Viaturas, Serviços, Viagens...) -->
      <article class="panel">
        <p class="eyebrow" data-t="stockEyebrow">INVENTÁRIO</p>
        <h2 data-t="stockTitle">Produtos</h2>
        <!-- Pesquisa e filtros do estoque (assets/js/variants.js): só aparecem nos ramos com tamanhos/cores -->
        <form id="stock-filters" class="ins-controls stock-filters ramo-off" autocomplete="off" role="search" aria-label="Pesquisar e filtrar o estoque">
          <label class="stock-f-q"><span>Pesquisar</span><input type="search" name="q" maxlength="80" placeholder="Nome, marca, referência ou categoria"></label>
          <label><span>Categoria</span><select name="category"><option value="">Todas</option></select></label>
          <label><span>Marca</span><select name="brand"><option value="">Todas</option></select></label>
          <label><span>Tamanho</span><select name="size"><option value="">Todos</option></select></label>
          <label><span>Cor</span><select name="color"><option value="">Todas</option></select></label>
          <label><span>Disponibilidade</span><select name="avail"><option value="">Todas</option><option value="ok">Disponível</option><option value="low">Stock reduzido</option><option value="out">Esgotado</option><option value="inactive">Inativo</option></select></label>
          <label><span>Ordenar por</span><select name="sort"><option value="name">Nome</option><option value="qty">Quantidade</option><option value="price">Preço</option><option value="date">Data de atualização</option></select></label>
        </form>
        <p id="stock-count" class="muted" aria-live="polite" hidden></p>
        <div id="products" class="product-grid"></div>
      </article>
      <!-- Formulário de novo produto. Os campos fixos são comuns a todos os ramos;
           #product-extra recebe os campos PRÓPRIOS do ramo (desenhados por profiles.js). -->
      <article class="panel">
        <p class="eyebrow" data-t="stockNewEyebrow">NOVO PRODUTO</p>
        <h2 data-t="stockNewTitle" id="product-form-title">Adicionar produto</h2>
        <form id="product-form">
          <label><span data-t="fName">Nome</span><input name="name" required maxlength="160"></label>
          <label><span data-t="fSku">SKU</span><input name="sku" maxlength="60"></label>
          <label><span data-t="fCategory">Categoria</span><input name="category" maxlength="80"></label>
          <label data-variants-only class="ramo-off"><span>Marca</span><input name="brand" maxlength="100" placeholder="Ex.: Nike"></label>
          <label><span data-t="fCost">Custo</span><input name="cost_price" type="number" step="0.01" min="0" required></label>
          <label><span data-t="fPrice">Preço de venda</span><input name="sale_price" type="number" step="0.01" min="0" required></label>
          <!-- data-stock-only: só existem nos ramos com estoque em unidades (loja, roupa, restaurante, oficina) -->
          <label data-stock-only data-qty-only><span data-t="fQty">Quantidade</span><input name="stock_quantity" type="number" min="0" required></label>
          <label data-stock-only><span data-t="fMin">Estoque mínimo</span><input name="minimum_stock" type="number" min="0" required></label>
          <div id="product-extra" class="extra-fields ramo-off"></div>
          <!-- Tamanhos e cores (assets/js/variants.js): cada combinação tem a sua quantidade -->
          <div id="variants-editor" class="variants-editor ramo-off" data-variants-only>
            <p class="variants-title"><b>Tamanhos e cores</b> <span class="muted">cada combinação tem o seu estoque</span></p>
            <div id="variants-rows"></div>
            <button type="button" class="button ghost small" id="variant-add">＋ Adicionar variação</button>
          </div>
          <label data-variants-only class="ramo-off"><span>Estado</span><select name="status"><option value="active">Disponível</option><option value="inactive">Inativo</option></select></label>
          <label data-variants-only class="ramo-off"><span>Imagem (endereço, opcional)</span><input name="image_url" type="url" maxlength="500" placeholder="https://…"></label>
          <p id="product-form-error" class="ap-form-error" role="alert" hidden></p>
          <button class="button primary" data-t="stockSave" id="product-save">Guardar produto</button>
          <button type="button" class="button secondary" id="product-cancel-edit" hidden>Cancelar edição</button>
        </form>
      </article>
    </div>
  </section>

  <?php if (!$isOwner): ?>
  <!-- MENSAGENS E TAREFAS (funcionário): assets/js/work.js -->
  <section id="work" class="section hidden"><div id="work-root"></div></section>
  <?php endif; ?>

  <?php if ($isOwner): ?>
  <!-- ================= EQUIPA (SÓ O DONO) ================= -->
  <!-- FUNCIONÁRIOS (só o líder): resumo, filtros, cartões e perfil de cada um. assets/js/staff.js -->
  <section id="staff" class="section hidden">
    <div id="staff-list-view">
      <div id="staff-summary"></div>
      <div id="staff-panels" class="staff-panels"></div>
      <article class="panel staff-filter-panel">
        <div class="panel-heading">
          <div><p class="eyebrow">EQUIPA · <span id="staff-count">0</span> · <span id="staff-period-note"></span></p><h2>Funcionários</h2></div>
          <div class="staff-head-actions"><button type="button" id="staff-announce" class="button secondary">📣 Enviar anúncio</button><button type="button" class="button secondary" data-go="team">Gerir equipa</button></div>
        </div>
        <form id="staff-filters" class="staff-filters" autocomplete="off" role="search" aria-label="Filtrar funcionários">
          <label class="wide2">Nome<input type="search" name="q" placeholder="Nome, cargo ou departamento" maxlength="80"></label>
          <label>Cargo<select name="job"><option value="">Todos os cargos</option></select></label>
          <label>Estado<select name="status"><option value="">Todos</option><option value="online">Online</option><option value="away">Ausente</option><option value="pause">Em pausa</option><option value="offline">Offline</option></select></label>
          <label>Produtividade mínima (%)<input type="number" name="prod_min" min="0" max="100" inputmode="numeric" placeholder="0"></label>
          <label>Desempenho mínimo (%)<input type="number" name="perf_min" min="0" max="100" inputmode="numeric" placeholder="0"></label>
          <label>Vendas mínimas (nº)<input type="number" name="sales_min" min="0" inputmode="numeric" placeholder="0"></label>
          <label>Ordenar por<select name="sort"><option value="name">Nome</option><option value="productivity">Produtividade</option><option value="performance">Desempenho</option><option value="sales">Vendas</option></select></label>
          <button type="button" id="staff-clear" class="button secondary">Limpar filtros</button>
        </form>
        <div class="staff-period-row"><strong>Período</strong><div id="staff-period" class="period-wrap"></div></div>
      </article>
      <div id="staff-cards" class="staff-grid" aria-live="polite"></div>
    </div>
    <div id="staff-profile-view" hidden></div>
  </section>

  <section id="team" class="section hidden">
    <div class="summary-grid four">
      <article class="summary-card"><span>Funcionários</span><strong id="team-total">0</strong></article>
      <article class="summary-card income"><span>Ativos</span><strong id="team-active">0</strong></article>
      <article class="summary-card expense"><span>Desativados</span><strong id="team-inactive">0</strong></article>
      <article class="summary-card"><span>Departamentos</span><strong id="team-departments">0</strong></article>
    </div>
    <div class="content-grid">
      <article class="panel">
        <div class="panel-heading">
          <div><p class="eyebrow">RECURSOS HUMANOS</p><h2>A tua equipa</h2></div>
          <input type="search" id="team-search" class="team-search" placeholder="Pesquisar nome, cargo ou departamento">
          <button type="button" class="button ghost small" id="mail-test" title="Envia um email de teste para o teu próprio endereço">✉ Testar envio de email</button>
        </div>
        <div id="team-list" class="team-list"></div>
        <h3 class="team-sub">Por departamento</h3>
        <div id="team-by-department" class="team-departments"></div>
      </article>
      <article class="panel">
        <p class="eyebrow" id="team-form-eyebrow">NOVO FUNCIONÁRIO</p>
        <h2 id="team-form-title">Adicionar funcionário</h2>
        <form id="team-form" autocomplete="off">
          <input type="hidden" name="id">
          <label>Nome<input name="name" required maxlength="150"></label>
          <label>Email (para entrar)<input name="email" type="email" required maxlength="320"></label>
          <div class="form-row">
            <label>Cargo<input name="job_title" maxlength="100" placeholder="Ex.: Vendedor"></label>
            <label>Departamento<input name="department" maxlength="100" list="team-department-list" placeholder="Ex.: Vendas"></label>
          </div>
          <datalist id="team-department-list"></datalist>
          <div class="form-row">
            <label>Telefone<input name="phone" maxlength="40"></label>
            <label>Data de admissão<input name="hired_at" type="date"></label>
          </div>
          <div id="team-password-row">
            <label>Palavra-passe provisória
              <span class="team-pass"><input name="password" minlength="8" maxlength="72" placeholder="Vazio = gerada automaticamente"><button type="button" class="button secondary small" id="team-generate">Gerar</button></span>
            </label>
            <p class="muted team-hint">No primeiro acesso o funcionário tem de a trocar.</p>
            <label class="team-invite"><input type="checkbox" name="send_invite"> Enviar convite por email (o funcionário escolhe a própria palavra-passe)</label>
          </div>
          <fieldset class="team-perms">
            <legend>Pode aceder a</legend>
            <!-- Atalhos: marcam de uma vez as áreas de um tipo de funcionário (ver team.js, PRESETS) -->
            <div class="team-presets" role="group" aria-label="Atalhos de permissões">
              <button type="button" class="button secondary small" data-preset="entry" title="Regista produtos, contas e clientes. Não vê os totais do negócio.">📝 Entrada de dados</button>
              <button type="button" class="button secondary small" data-preset="agenda" title="Só vê a sua agenda.">📅 Só agenda</button>
              <button type="button" class="button secondary small" data-preset="manager" title="Todas as áreas, menos a Equipa.">🧭 Gestor</button>
            </div>
            <div id="team-perm-list"></div>
            <p class="muted team-hint">Calculadora e Tempo estão sempre disponíveis. A Equipa e o ramo do negócio são só teus. <b>Só o chefe elimina registos</b>: o funcionário regista e consulta.</p>
          </fieldset>
          <div class="form-actions">
            <button class="button primary" id="team-save">Guardar funcionário</button>
            <button type="button" id="team-cancel" class="button secondary hidden">Cancelar edição</button>
          </div>
        </form>
      </article>
    </div>
  </section>
  <?php endif; ?>

  <!-- ================= CALCULADORA ================= -->
  <?php if ($isOwner): ?>
  <!-- ================= META ADS (assets/js/meta.js, api/meta.php) ================= -->
  <section id="meta" class="section hidden">
    <article class="panel" id="meta-panel">
      <p class="eyebrow">META ADS</p>
      <h2>Anúncios da Meta <span class="ap-chip" id="meta-chip">…</span></h2>
      <div id="meta-body" aria-live="polite"><div class="skeleton-stack"><div class="skeleton" style="height:18px;width:70%"></div><div class="skeleton" style="height:36px"></div></div></div>
    </article>
    <div id="meta-data" hidden>
      <article class="panel">
        <div class="panel-heading">
          <div><p class="eyebrow">DESEMPENHO</p><h2>Resultados dos anúncios</h2><p class="muted" id="meta-updated"></p></div>
          <div class="chart-controls no-print">
            <div class="seg" role="group" aria-label="Período" id="meta-period">
              <button type="button" data-p="last_7d" aria-pressed="false">7 dias</button><button type="button" data-p="last_30d" aria-pressed="false">30 dias</button><button type="button" data-p="last_90d" aria-pressed="false">90 dias</button>
            </div>
            <button type="button" class="button secondary" id="meta-refresh">Atualizar</button>
          </div>
        </div>
        <div class="summary-grid" id="meta-totals"></div>
      </article>
      <article class="panel">
        <div class="panel-heading">
          <div><p class="eyebrow">DETALHE</p><h2 id="meta-level-title">Campanhas</h2></div>
          <div class="seg" role="group" aria-label="Nível" id="meta-level">
            <button type="button" data-l="campaigns" aria-pressed="true" class="on">Campanhas</button><button type="button" data-l="adsets" aria-pressed="false">Conjuntos de anúncios</button><button type="button" data-l="ads" aria-pressed="false">Anúncios</button>
          </div>
        </div>
        <div class="table-wrap" id="meta-table"></div>
      </article>
    </div>
  </section>
  <?php endif; ?>

  <section id="calculator" class="section hidden">
    <div class="content-grid">
      <article class="panel">
        <p class="eyebrow">FERRAMENTA</p>
        <h2>Calculadora de preço</h2>
        <p class="muted">Calcula o preço de venda com margem de lucro e IVA. O IVA começa em 23% (valor habitual em Portugal) e pode ser alterado.</p>
        <div class="calculator-card">
          <div class="calculator-fields">
            <label><span data-t="calcCost">Custo do produto (€)</span><input id="calc-cost" type="number" value="100" min="0" step="0.01"></label>
            <label>Margem de lucro (%)<input id="calc-margin" type="number" value="30" min="0" step="1"></label>
            <label>IVA (%)<input id="calc-vat" type="number" value="23" min="0" step="1"></label>
          </div>
          <div class="calc-result">
            <span>Preço final recomendado</span>
            <strong id="calc-total">0,00 €</strong>
            <small id="calc-detail"></small>
          </div>
        </div>
      </article>

      <!-- Ferramenta do ramo (comissão, margem da viatura, ganho por hora...). Desenhada por profiles.js. -->
      <article class="panel ramo-tool ramo-off" id="ramo-tool"></article>

      <article class="panel calc-panel" id="calc-standard" tabindex="0" aria-label="Calculadora">
        <p class="eyebrow">CONTAS RÁPIDAS</p>
        <h2>Calculadora</h2>
        <div class="calc-screen"><small id="calc-expr">&nbsp;</small><output id="calc-display" aria-live="polite">0</output></div>
        <div class="calc-keys">
          <button type="button" class="key fn" data-key="clear">C</button>
          <button type="button" class="key fn" data-key="back" aria-label="Apagar">⌫</button>
          <button type="button" class="key fn" data-key="percent">%</button>
          <button type="button" class="key op" data-key="/">÷</button>
          <button type="button" class="key" data-key="7">7</button>
          <button type="button" class="key" data-key="8">8</button>
          <button type="button" class="key" data-key="9">9</button>
          <button type="button" class="key op" data-key="*">×</button>
          <button type="button" class="key" data-key="4">4</button>
          <button type="button" class="key" data-key="5">5</button>
          <button type="button" class="key" data-key="6">6</button>
          <button type="button" class="key op" data-key="-">−</button>
          <button type="button" class="key" data-key="1">1</button>
          <button type="button" class="key" data-key="2">2</button>
          <button type="button" class="key" data-key="3">3</button>
          <button type="button" class="key op" data-key="+">+</button>
          <button type="button" class="key fn" data-key="negate">±</button>
          <button type="button" class="key" data-key="0">0</button>
          <button type="button" class="key" data-key=".">,</button>
          <button type="button" class="key eq" data-key="equals">=</button>
        </div>
        <button type="button" class="button secondary full-btn" id="calc-to-cost">Usar resultado como custo →</button>
        <p class="muted calc-hint">Também podes usar o teclado.</p>
      </article>
    </div>
  </section>

  <!-- ================= TEMPO ================= -->
  <section id="weather" class="section hidden">
    <div class="content-grid">
      <article class="panel weather-main">
        <p class="eyebrow">PREVISÃO DO TEMPO</p>
        <h2 id="weather-city">A carregar…</h2>
        <div class="weather-now">
          <div class="weather-icon" id="weather-icon" aria-hidden="true">⛅</div>
          <div><strong id="weather-temp">--°</strong><span id="weather-desc"></span></div>
        </div>
        <div class="weather-stats">
          <div><span>Sensação</span><b id="weather-feels">--</b></div>
          <div><span>Humidade</span><b id="weather-humidity">--</b></div>
          <div><span>Vento</span><b id="weather-wind">--</b></div>
          <div><span>Precipitação</span><b id="weather-rain">--</b></div>
        </div>
        <p class="weather-tip" id="weather-tip"></p>
        <h3 class="weather-sub">Próximos dias</h3>
        <div class="forecast" id="forecast"></div>
      </article>
      <article class="panel">
        <p class="eyebrow">LOCALIZAÇÃO</p>
        <h2>Escolher cidade</h2>
        <form id="weather-form">
          <label>Cidade<input name="city" placeholder="Ex.: Leiria" autocomplete="off" required></label>
          <button class="button primary">Pesquisar</button>
          <button type="button" id="weather-geo" class="button secondary">📍 Usar a minha localização</button>
        </form>
        <div id="weather-results" class="weather-results"></div>
        <p class="muted weather-source">Dados do Open-Meteo (gratuito, sem chave de API).</p>
      </article>
    </div>
  </section>

  <!-- ================= RELATÓRIOS ================= -->
  <section id="reports" class="section hidden">
    <article class="panel report-panel">
      <p class="eyebrow">DOCUMENTOS</p>
      <h2>Relatório</h2>
      <p class="muted no-print">Resumo gerado a partir dos teus dados. Usa o botão para imprimir ou guardar como PDF.</p>
      <div id="report-content"></div>
      <button type="button" class="button primary no-print" id="report-print-btn">Imprimir / Guardar PDF</button>
    </article>
  </section>
</main>

<?php require_once __DIR__ . '/includes/legal.php'; site_footer('compact'); ?>   <!-- rodapé com informação legal -->

<!-- ===== PREPARAR RELATÓRIO (antes de imprimir / guardar PDF) ===== -->
<div id="print-modal" class="reminder-modal print-modal hidden" role="dialog" aria-modal="true" aria-labelledby="print-title" aria-describedby="print-status">
  <div class="reminder-card print-card" id="print-card">
    <button type="button" class="reminder-close" id="print-x" aria-label="Fechar">×</button>

    <div class="print-stage" aria-hidden="true">
      <div class="print-glow"></div>
      <div class="print-sheet">
        <div class="ps-head"><i class="ps-dot"></i><span class="ps-line" style="--w:46%;--d:.55s"></span></div>
        <div class="ps-kpis"><span style="--d:.75s"></span><span style="--d:.85s"></span><span style="--d:.95s"></span></div>
        <div class="ps-chart"><i style="--h:42%;--d:.95s"></i><i style="--h:68%;--d:1.03s"></i><i style="--h:52%;--d:1.11s"></i><i style="--h:84%;--d:1.19s"></i><i style="--h:62%;--d:1.27s"></i></div>
        <span class="ps-line" style="--w:92%;--d:1.15s"></span>
        <span class="ps-line" style="--w:78%;--d:1.25s"></span>
        <span class="ps-line" style="--w:58%;--d:1.35s"></span>
        <div class="ps-beam"></div>
      </div>
      <div class="print-badge">
        <span class="pb-ripple"></span><span class="pb-ripple r2"></span>
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
      </div>
    </div>

    <div class="print-copy">
      <p class="eyebrow">RELATÓRIO</p>
      <h2 id="print-title">Preparar relatório</h2>
      <p class="print-status" id="print-status">
        <span class="pst-working">A organizar os dados…</span>
        <span class="pst-done">O seu relatório está pronto para ser impresso ou guardado.</span>
      </p>
    </div>

    <div class="print-actions">
      <button type="button" class="button secondary" id="print-cancel" disabled>Cancelar</button>
      <button type="button" class="button primary" id="print-go" disabled>Imprimir / Guardar PDF</button>
    </div>
    <p class="sr-only" id="print-live" aria-live="polite"></p>
  </div>
</div>

<?php include __DIR__ . '/includes/partials/card_modal.php'; ?>  <!-- janela Adicionar cartão (só o dono) -->

<!-- ===== LUMINA (assistente de IA) ===== -->
<button type="button" id="ai-fab" class="ai-fab" aria-label="Abrir a Lumina" aria-expanded="false" aria-controls="ai-panel">
  <svg class="ai-ico-spark" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
  <svg class="ai-ico-close" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
  <span class="ai-fab-tip">Lumina</span>
</button>

<section id="ai-panel" class="ai-panel hidden" role="dialog" aria-label="Lumina" data-first="<?= e($firstName) ?>">
  <header class="ai-head">
    <span class="ai-avatar" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/></svg></span>
    <div class="ai-title"><strong>Lumina</strong><small id="ai-mode"><i class="ai-dot"></i>Dados do negócio</small></div>
    <div class="ai-head-actions">
      <button type="button" class="ai-iconbtn" id="ai-clear" aria-label="Limpar conversa" title="Limpar conversa"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
      <button type="button" class="ai-iconbtn" id="ai-close" aria-label="Fechar" title="Fechar"><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
    </div>
  </header>

  <div class="ai-messages" id="ai-messages" role="log" aria-live="polite" aria-relevant="additions"></div>

  <div class="ai-suggestions" id="ai-suggestions">
    <button type="button" class="ai-chip">Quanto temos para receber?</button>
    <button type="button" class="ai-chip">Quais contas estão vencidas?</button>
    <button type="button" class="ai-chip">Produtos com stock baixo</button>
    <button type="button" class="ai-chip">Total de vendas deste mês</button>
  </div>

  <form id="ai-form" class="ai-input" autocomplete="off">
    <textarea id="ai-input" rows="1" maxlength="1000" placeholder="Pergunta sobre os teus dados…" aria-label="Escreve a tua pergunta"></textarea>
    <button type="submit" id="ai-send" class="ai-send" aria-label="Enviar" disabled>
      <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
    </button>
  </form>
  <p class="ai-foot">Só vê os dados a que tens acesso. A IA pode errar: confirma valores importantes.</p>
</section>

<!-- ===== AVISO DE REUNIÃO PRÓXIMA ===== -->
<!-- Avisos: lista do sino e cartões que aparecem quando chega um aviso novo (assets/js/notifications.js) -->
<div id="notif-panel" class="notif-panel" role="dialog" aria-label="Avisos" hidden>
  <div class="notif-head"><h2>Avisos</h2><div><button type="button" id="notif-readall" class="linklike" hidden>Marcar todos como lidos</button><button type="button" id="notif-close" class="icon-btn" aria-label="Fechar avisos">×</button></div></div>
  <ul id="notif-list" class="notif-list"></ul>
</div>
<div id="notif-stack" class="notif-stack" aria-live="polite" aria-atomic="false"></div>
<div id="reminder-modal" class="reminder-modal hidden" role="dialog" aria-modal="true" aria-labelledby="reminder-title">
  <div class="reminder-card" id="reminder-card">
    <button type="button" class="reminder-close" id="reminder-x" aria-label="Fechar aviso">×</button>
    <div class="reminder-head">
      <span class="reminder-bell" aria-hidden="true">
        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
      </span>
      <div>
        <p class="eyebrow">AVISO</p>
        <h2 id="reminder-title">Reunião se aproximando</h2>
      </div>
    </div>

    <div class="reminder-event">
      <strong id="reminder-event-title"></strong>
      <ul class="reminder-meta">
        <li><span>Data</span><b id="reminder-date"></b></li>
        <li><span>Início</span><b id="reminder-time"></b></li>
        <li id="reminder-end-row" hidden><span>Fim</span><b id="reminder-end"></b></li>
        <li id="reminder-loc-row" hidden><span>Local</span><b id="reminder-loc"></b></li>
      </ul>
      <p id="reminder-desc" class="reminder-desc" hidden></p>
    </div>

    <div class="reminder-countdown" aria-hidden="true">
      <div class="rc-box"><b id="rc-h">00</b><span>HORAS</span></div>
      <div class="rc-box"><b id="rc-m">00</b><span>MINUTOS</span></div>
      <div class="rc-box"><b id="rc-s">00</b><span>SEGUNDOS</span></div>
    </div>
    <p class="sr-only" id="reminder-live" aria-live="polite"></p>
    <p class="reminder-more" id="reminder-more" hidden></p>

    <div class="reminder-actions">
      <button type="button" class="button primary" id="reminder-view">Ver reunião</button>
      <button type="button" class="button secondary" id="reminder-dismiss">Fechar</button>
    </div>
  </div>
</div>

<!-- ===== JANELA: RAMO DE ATIVIDADE ===== -->
<div id="profile-modal" class="profile-modal hidden" role="dialog" aria-modal="true" aria-labelledby="wiz-title">
  <div class="profile-dialog wizard">
    <div class="wiz-top">
      <div>
        <p class="eyebrow" id="wiz-eyebrow">CONFIGURAÇÃO INICIAL</p>
        <h2 id="wiz-title">Qual é o teu negócio?</h2>
        <p class="muted" id="wiz-sub"></p>
      </div>
      <button type="button" class="icon-btn" id="wiz-close" aria-label="Fechar sem guardar" hidden>✕</button>
    </div>
    <div class="wiz-progress" id="wiz-progress" role="progressbar" aria-label="Progresso do questionário" aria-valuemin="1" aria-valuemax="6" aria-valuenow="1"><span id="wiz-bar"></span></div>
    <!-- O questionário (assets/js/profile.js): o passo 1 está aqui; os passos 2 a 6 são desenhados em #wiz-body -->
    <form id="profile-form" novalidate>
      <input type="hidden" id="profile-type" name="business_type" value="general">
      <div id="wiz-step-1">
        <label>Nome do negócio<input name="business_name" placeholder="Ex.: Mota Importz" maxlength="160" required></label>
        <div id="profile-choices" class="profile-choices"></div>
      </div>
      <div id="wiz-body" hidden></div>
      <p id="profile-message" class="message" role="alert"></p>
      <div class="wiz-actions">
        <button type="button" class="button secondary" id="wiz-back" hidden>Voltar</button>
        <button type="button" class="button secondary" id="wiz-quick">Usar sugestões e entrar</button>
        <button type="submit" class="button primary" id="wiz-next">Continuar</button>
      </div>
    </form>
  </div>
</div>

<!-- ===== JANELA: ALTERAR PALAVRA-PASSE ===== -->
<div id="password-modal" class="profile-modal<?= $user['must_change_password'] ? '' : ' hidden' ?>" role="dialog" aria-modal="true" aria-labelledby="password-title">
  <div class="profile-dialog team-dialog">
    <p class="eyebrow"><?= $user['must_change_password'] ? 'PRIMEIRO ACESSO' : 'SEGURANÇA' ?></p>
    <h2 id="password-title">Alterar palavra-passe</h2>
    <?php if ($user['must_change_password']): ?><p class="muted">Estás a usar uma palavra-passe provisória. Escolhe uma nova para continuar.</p><?php endif; ?>
    <form id="password-form">
      <label><?= $user['must_change_password'] ? 'Palavra-passe provisória' : 'Palavra-passe atual' ?><input name="current_password" type="password" required autocomplete="current-password"></label>
      <label>Nova palavra-passe<input name="new_password" type="password" minlength="8" required autocomplete="new-password"></label>
      <label>Repetir a nova<input name="confirm_password" type="password" minlength="8" required autocomplete="new-password"></label>
      <div class="form-actions">
        <button class="button primary">Guardar</button>
        <?php if (!$user['must_change_password']): ?><button type="button" class="button secondary" id="password-cancel">Cancelar</button><?php endif; ?>
      </div>
      <p id="password-message" class="message" role="alert"></p>
    </form>
  </div>
</div>

<?php if ($isOwner): ?>
<!-- ===== JANELA: PALAVRA-PASSE PROVISÓRIA CRIADA ===== -->
<div id="temp-pass-modal" class="profile-modal hidden" role="dialog" aria-modal="true" aria-labelledby="temp-pass-title">
  <div class="profile-dialog team-dialog">
    <p class="eyebrow">ACESSO DO FUNCIONÁRIO</p>
    <h2 id="temp-pass-title">Entrega estes dados</h2>
    <p class="muted">Esta palavra-passe só é mostrada agora. No primeiro acesso, o funcionário vai ter de a trocar.</p>
    <div class="report-line"><span>Email</span><strong id="temp-pass-email"></strong></div>
    <div class="report-line"><span>Palavra-passe provisória</span><strong id="temp-pass-value" class="temp-pass"></strong></div>
    <div class="form-actions">
      <button type="button" class="button primary" id="temp-pass-copy">Copiar</button>
      <button type="button" class="button secondary" id="temp-pass-close">Fechar</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="assets/js/fundo-video.js"></script>
  <script src="assets/js/theme.js"></script>
<script src="assets/js/hearts.js"></script>
<script src="assets/js/common.js"></script>     <!-- $, esc, api, msg... (carrega primeiro) -->
<script src="assets/js/ux.js"></script>        <!-- avisos (toasts), confirmações, ripple -->
<script src="assets/js/app.js"></script>
<script src="assets/js/charts.js"></script>    <!-- gráficos: período e tipo (barras/linhas) -->
<script src="assets/js/cashflow.js"></script>
<script src="assets/js/lumina-footer.js"></script>
<script src="assets/js/verify-banner.js"></script>   <!-- aviso: confirma o teu email -->
<script src="assets/js/team-common.js"></script>      <!-- peças comuns da gestão de funcionários -->
<script src="assets/js/team-chat.js"></script>        <!-- conversa líder ⇄ funcionário -->
<script src="assets/js/notifications.js"></script>    <!-- o sino de avisos -->
<script src="assets/js/staff.js"></script>            <!-- aba Funcionários (líder) -->
<script src="assets/js/work.js"></script>             <!-- aba Mensagens e tarefas (funcionário) -->
<script src="assets/js/import.js"></script>           <!-- importar movimentos de um CSV -->
<script src="assets/js/attachments.js"></script>      <!-- recibos e anexos (📎) -->
<script src="assets/js/budgets.js"></script>          <!-- orçamentos por categoria -->
<script src="assets/js/recurring.js"></script>        <!-- contas recorrentes -->
<script src="assets/js/fiscal.js"></script>           <!-- próximas datas fiscais -->
<script src="assets/js/closing.js"></script>          <!-- fecho do dia -->
<script src="assets/js/quick-add.js"></script>        <!-- registo rápido (+) -->
<script src="assets/js/palette.js"></script>          <!-- pesquisa global e comandos (Ctrl+K) -->
<script src="assets/js/menu.js"></script>      <!-- menu lateral (☰) -->
<script src="assets/js/privacy.js"></script>
<script src="assets/js/notes.js"></script>     <!-- Bloco de notas -->
<script src="assets/js/time.js"></script>      <!-- Tempo ativo -->
<script src="assets/js/variants.js"></script>  <!-- estoque por tamanho/cor, filtros e edição -->
<script src="assets/js/insights.js"></script>  <!-- painéis inteligentes por ramo -->
<script src="assets/js/google-cal.js"></script>
<?php if ($isOwner): ?><script src="assets/js/meta.js"></script> <!-- Meta Ads --><?php endif; ?> <!-- ligação ao Google Calendar -->   <!-- ocultar/mostrar valores -->
<script src="assets/js/profiles.js"></script>   <!-- motor de ramos: vocabulário, campos, dicas -->
<script src="assets/js/profile.js"></script>    <!-- escolha e gravação do ramo -->
<script src="assets/js/calc.js"></script>
<script src="assets/js/calendar.js"></script>
<script src="assets/js/weather.js"></script>
<script src="assets/js/reminder.js"></script>
<script src="assets/js/team.js"></script>
<script src="assets/js/stock.js"></script>
  <script src="assets/js/ai-chat.js"></script>
<script src="assets/js/print-modal.js"></script>
<script src="assets/js/cards.js"></script>
<script src="assets/js/pwa.js"></script>
</body>
</html>
