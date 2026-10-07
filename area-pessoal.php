<?php
require_once __DIR__ . '/includes/lang.php';
/* =========================================================================
   ÁREA PESSOAL  (area-pessoal.php)   ->  também acessível em /area-pessoal
   -------------------------------------------------------------------------
   O QUE É:  a página onde cada utilizador gere a SUA conta e (o dono) a empresa:
     1. Perfil     - foto, nome, função, email, telefone, preferências
     2. Empresa    - nome, NIF, morada, contactos, logo e cor principal
     3. Cartões    - cartões associados (só o dono)
     4. Segurança  - palavra-passe, 2 passos, sessões ativas, avisos
   COMO SE LIGA:
     - Dados:  api/me.php (perfil/empresa/fotos), api/security.php (segurança),
               api/cards.php (cartões).
     - Lógica: assets/js/area-pessoal.js.   Estilos: assets/css/area-pessoal.css.
     - Botão de entrada: o círculo (avatar) no canto superior direito do painel.
   SEGURANÇA: sem sessão volta ao login (require_login). O token CSRF vai para o
     JavaScript em window.GF_CSRF.
   ========================================================================= */
require_once __DIR__ . '/includes/seo.php';
seo_noindex_header();                 // página privada: fora do Google (cabeçalho HTTP + etiqueta)
require_once __DIR__ . '/includes/auth.php';
$user = require_login();
$isOwner = $user['role'] === 'owner';

// preferências e foto (para a página já nascer certa)
$st = db()->prepare('SELECT avatar_path, preferences FROM users WHERE id = ?');
$st->execute([$user['id']]);
$ex = $st->fetch() ?: [];
$prefs = !empty($ex['preferences']) ? (json_decode($ex['preferences'], true) ?: []) : [];
$hideValues = !empty($prefs['hide_values']);
$GLOBALS['lumina_account_lang'] = in_array($prefs['lang'] ?? '', ['pt', 'en', 'es'], true) ? $prefs['lang'] : null;   // idioma escolhido na conta (assets/js/i18n-boot.js)
$avatarUrl = !empty($ex['avatar_path']) ? 'api/files.php?type=avatar&id=' . (int)$user['id'] . '&v=' . substr(md5($ex['avatar_path']), 0, 8) : null;
$parts = preg_split('/\s+/u', trim($user['name'] ?? '')) ?: [];
$initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
?>
<!doctype html>
<html lang="pt-PT"<?= $hideValues ? ' data-hide-values' : '' ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">                         <!-- página privada: fora dos motores de pesquisa -->
<?php seo_head(['title' => 'Área pessoal — Lumina', 'index' => false]); ?>
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#061431">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/lumina-icon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/img/lumina-icon-64.png">
  <link rel="apple-touch-icon" href="assets/img/lumina-icon-180.png">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/fundo-video.css">
  <link rel="stylesheet" href="assets/css/effects.css">
  <link rel="stylesheet" href="assets/css/ux.css">
  <link rel="stylesheet" href="assets/css/reminder.css">     <!-- base das janelas (modais): sem isto apareciam soltas no fundo da página -->
  <link rel="stylesheet" href="assets/css/cards.css">
  <link rel="stylesheet" href="assets/css/area-pessoal.css">
  <link rel="stylesheet" href="assets/css/legal.css">
  <script>try{document.documentElement.dataset.tema=localStorage.getItem('gf-tema')||'dark'}catch(e){}</script>
  <?php include __DIR__ . '/includes/partials/i18n_head.php'; ?>
  <script>window.GF_CSRF = <?= json_encode(csrf_token()) ?>; window.GF_USER = <?= json_encode(client_user_payload($user), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
</head>
<body>
<a class="skip-link" href="#conteudo">Saltar para o conteúdo</a>

<!-- ===== TOPO (igual ao do painel, com ligação de volta) ===== -->
<header class="topbar">
  <div>
    <p class="eyebrow brand-eyebrow"><img src="assets/img/lumina-mark-96.png" alt="" width="22" height="21">LUMINA</p>
    <h1 class="ap-h1">Área pessoal</h1>
    <p class="muted">Gira a tua conta e as informações da empresa.</p>
  </div>
  <div class="top-actions">
    <a class="button secondary" href="dashboard.php">← Voltar ao painel</a>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Alternar tema">☀</button>
    <button type="button" class="icon-btn" data-privacy-toggle data-tip="Ocultar valores" aria-pressed="false" aria-label="Ocultar valores">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>
    </button>
    <button id="logout" class="button secondary" type="button">Sair</button>
  </div>
</header>


<main id="conteudo" class="container ap">

<!-- ===== SEPARADORES (o indicador teal desliza para o separador ativo) ===== -->
<nav class="ap-tabs" role="tablist" aria-label="Secções da área pessoal">
  <span class="ap-ind" aria-hidden="true"></span>
  <button type="button" role="tab" id="t-perfil" aria-controls="tab-perfil" data-tab="perfil" aria-selected="true">Perfil</button>
  <button type="button" role="tab" id="t-empresa" aria-controls="tab-empresa" data-tab="empresa" aria-selected="false" tabindex="-1">A minha empresa</button>
  <button type="button" role="tab" id="t-cartoes" aria-controls="tab-cartoes" data-tab="cartoes" aria-selected="false" tabindex="-1">Cartões</button>
  <button type="button" role="tab" id="t-seguranca" aria-controls="tab-seguranca" data-tab="seguranca" aria-selected="false" tabindex="-1">Segurança e sessão</button>
</nav>

  <!-- ================= PERFIL ================= -->
  <section id="tab-perfil" class="ap-panel" role="tabpanel" aria-labelledby="t-perfil" data-panel="perfil">
    <div class="ap-grid">
      <article class="panel ap-card" style="--i:0">
        <p class="eyebrow">PERFIL DO UTILIZADOR</p>
        <div class="ap-avatar-wrap">
          <div class="ap-avatar" id="ap-avatar">
            <img id="ap-avatar-img" alt="Foto de perfil" <?= $avatarUrl ? 'src="' . htmlspecialchars($avatarUrl, ENT_QUOTES) . '"' : 'hidden' ?>>
            <span id="ap-avatar-initials" <?= $avatarUrl ? 'hidden' : '' ?>><?= htmlspecialchars($initials, ENT_QUOTES) ?></span>
          </div>
          <div class="ap-avatar-actions">
            <input type="file" id="avatar-file" accept="image/jpeg,image/png,image/webp" hidden>
            <button type="button" class="button primary" id="avatar-add" <?= $avatarUrl ? 'hidden' : '' ?>>Adicionar foto</button>
            <button type="button" class="button secondary" id="avatar-change" <?= $avatarUrl ? '' : 'hidden' ?>>Alterar foto</button>
            <button type="button" class="button secondary" id="avatar-remove" <?= $avatarUrl ? '' : 'hidden' ?>>Remover foto</button>
          </div>
          <p class="muted ap-small">JPG, PNG ou WEBP, até 2 MB. Vês uma pré-visualização antes de guardar.</p>
        </div>
      </article>

      <article class="panel ap-card" style="--i:1">
        <div class="panel-heading">
          <div><p class="eyebrow">OS TEUS DADOS</p><h2>Dados do utilizador</h2></div>
          <button type="button" class="button secondary" id="profile-edit">Editar dados</button>
        </div>
        <div id="profile-view" aria-live="polite">
          <div class="skeleton-stack"><div class="skeleton" style="height:18px;width:60%"></div><div class="skeleton" style="height:18px;width:80%"></div><div class="skeleton" style="height:18px;width:50%"></div></div>
        </div>
        <form id="profile-form" hidden novalidate>
          <label>Nome<input name="name" required maxlength="100" autocomplete="name"></label>
          <label>Função<input name="job_title" maxlength="100" <?= $isOwner ? '' : 'disabled title="É o administrador que define a tua função."' ?>></label>
          <label>Email<input name="email" type="email" disabled title="O email não se altera aqui."></label>
          <label>Telefone<input name="phone" type="tel" maxlength="25" autocomplete="tel" placeholder="+351 912 345 678"></label>
          <p class="ap-form-error" role="alert" hidden></p>
          <div class="form-actions"><button class="button primary">Guardar dados</button><button type="button" class="button secondary" id="profile-cancel">Cancelar</button></div>
        </form>
      </article>
    </div>

    <article class="panel ap-card" style="--i:2">
      <p class="eyebrow">PREFERÊNCIAS</p>
      <h2>Privacidade</h2>
      <label class="ap-switch-row">
        <span><b>Ocultar valores financeiros</b><small>Esconde saldos, entradas, saídas, lucros e relatórios (mostra ••••••). Os títulos ficam visíveis. A preferência fica guardada para todos os teus dispositivos.</small></span>
        <input type="checkbox" id="pref-hide" role="switch" <?= $hideValues ? 'checked' : '' ?>>
        <span class="ap-switch" aria-hidden="true"></span>
      </label>
    </article>
  </section>

  <!-- ================= A MINHA EMPRESA ================= -->
  <section id="tab-empresa" class="ap-panel" role="tabpanel" aria-labelledby="t-empresa" data-panel="empresa" hidden>
    <article class="panel ap-card" style="--i:0">
      <p class="eyebrow">A MINHA EMPRESA</p>
      <h2>Dados da empresa</h2>
      <?php if (!$isOwner): ?><p class="muted">Só o administrador pode alterar os dados da empresa. Aqui vês os dados públicos.</p><?php endif; ?>
      <form id="company-form" novalidate>
        <fieldset <?= $isOwner ? '' : 'disabled' ?> class="ap-fieldset">
          <div class="ap-fields">
            <label>Nome da empresa<input name="name" required maxlength="160" autocomplete="organization"></label>
            <label>Atividade<input name="activity" maxlength="160" placeholder="Ex.: Venda de produtos importados"></label>
            <label>NIF<input name="tax_number" inputmode="numeric" maxlength="9" placeholder="9 dígitos"></label>
            <label>Telefone<input name="phone" type="tel" maxlength="25" placeholder="+351 244 000 000"></label>
            <label class="wide">Morada<input name="address" maxlength="255" autocomplete="street-address"></label>
            <label>Email profissional<input name="email" type="email" maxlength="190" placeholder="geral@empresa.pt"></label>
            <label>Website<input name="website" type="url" maxlength="190" placeholder="https://empresa.pt"></label>
          </div>

          <div class="ap-logo-row">
            <div class="ap-logo" id="ap-logo"><img id="ap-logo-img" alt="Logo da empresa" hidden><span id="ap-logo-empty">Sem logo</span></div>
            <div>
              <h3>Logo da empresa</h3>
              <p class="muted ap-small">PNG, JPG ou SVG, até 1 MB. Aparece automaticamente nos relatórios, PDFs e documentos que descarregares.</p>
              <input type="file" id="logo-file" accept="image/png,image/jpeg,image/svg+xml" hidden>
              <div class="ap-avatar-actions">
                <button type="button" class="button primary" id="logo-add">Adicionar logo</button>
                <button type="button" class="button secondary" id="logo-change" hidden>Alterar logo</button>
                <button type="button" class="button secondary" id="logo-remove" hidden>Remover logo</button>
              </div>
            </div>
          </div>

          <div class="ap-color-row">
            <div><h3>Cor principal da empresa</h3><p class="muted ap-small">Usada em pequenos destaques de relatórios e documentos. O sistema mantém sempre o tema escuro.</p></div>
            <div class="ap-color-pick">
              <input type="color" id="brand-color" value="#14b8a6" aria-label="Escolher a cor principal">
              <input type="text" id="brand-hex" name="brand_color" maxlength="7" placeholder="#14b8a6" aria-label="Código da cor">
              <button type="button" class="button secondary small" id="brand-reset">Repor</button>
            </div>
          </div>
        </fieldset>
        <p class="ap-form-error" role="alert" hidden></p>
        <?php if ($isOwner): ?><div class="form-actions"><button class="button primary">Guardar empresa</button></div><?php endif; ?>
      </form>
    </article>
    <?php if ($isOwner): ?>
    <article class="panel ap-card" style="--i:9">
      <p class="eyebrow">QUESTIONÁRIO DE ARRANQUE</p>
      <h2>Ajustar o Lumina ao teu negócio</h2>
      <p class="muted">Muda o ramo, as abas que vês, como recebes, os impostos e o teu objetivo. Não perdes nenhum dado.</p>
      <div class="form-actions"><a class="button secondary" id="redo-onboarding" href="dashboard.php?questionario=1">Refazer o questionário</a></div>
    </article>
  <?php endif; ?>
  </section>

  <!-- ================= CARTÕES ================= -->
  <section id="tab-cartoes" class="ap-panel" role="tabpanel" aria-labelledby="t-cartoes" data-panel="cartoes" hidden>
    <?php if ($isOwner): ?>
    <article class="panel cards-panel ap-card" id="cards-panel" style="--i:0">
      <div class="panel-heading">
        <div><p class="eyebrow">PAGAMENTOS</p><h2>Cartões associados</h2></div>
        <button type="button" class="button primary" id="card-add-btn">Adicionar cartão</button>
      </div>
      <p class="muted ap-small">Só se mostram os últimos 4 dígitos. O número completo e o CVV nunca são guardados neste sistema.</p>
      <div class="cards-list" id="cards-list" data-manage="true" aria-live="polite"></div>
    </article>
    <?php else: ?>
    <article class="panel ap-card"><div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">🔒</span><b>Área reservada ao administrador</b><span>Os cartões associados só são geridos pelo administrador do negócio.</span></div></article>
    <?php endif; ?>
  </section>

  <!-- ================= SEGURANÇA E SESSÃO ================= -->
  <section id="tab-seguranca" class="ap-panel" role="tabpanel" aria-labelledby="t-seguranca" data-panel="seguranca" hidden>
    <article class="panel ap-card" id="sec-warnings-card" style="--i:0" hidden>
      <p class="eyebrow">AVISOS DE SEGURANÇA</p>
      <ul class="ap-warnings" id="sec-warnings"></ul>
    </article>

    <div class="ap-grid">
      <article class="panel ap-card" style="--i:1">
        <p class="eyebrow">DEFINIÇÕES DA CONTA · SEGURANÇA</p>
        <h2>Alterar palavra-passe</h2>
        <form id="password-form" novalidate>
          <label>Palavra-passe atual<input name="current_password" type="password" required autocomplete="current-password"></label>
          <label>Nova palavra-passe<input name="new_password" type="password" required minlength="8" autocomplete="new-password"><small class="ap-meter" id="pw-meter" aria-live="polite"></small></label>
          <label>Repetir a nova palavra-passe<input name="repeat" type="password" required autocomplete="new-password"></label>
          <label class="ap-check"><input type="checkbox" name="logout_others" checked> Terminar a sessão nos outros dispositivos</label>
          <p class="ap-form-error" role="alert" hidden></p>
          <div class="form-actions"><button class="button primary">Alterar palavra-passe</button></div>
        </form>
      </article>

      <article class="panel ap-card" style="--i:2">
        <p class="eyebrow">AUTENTICAÇÃO EM DOIS PASSOS</p>
        <h2>Proteção extra <span class="ap-chip" id="tfa-chip">…</span></h2>
        <p class="muted" id="tfa-text">Com o segundo passo, além da palavra-passe é preciso um código da tua app de autenticação (Google Authenticator, Microsoft Authenticator, Authy…).</p>
        <div class="form-actions"><button type="button" class="button primary" id="tfa-toggle" disabled>A carregar…</button></div>
      </article>
    </div>

    <article class="panel ap-card" style="--i:3">
      <div class="panel-heading">
        <div><p class="eyebrow">SESSÕES</p><h2>Sessões ativas</h2></div>
        <div class="ap-avatar-actions"><button type="button" class="button secondary" id="session-here">Terminar sessão neste dispositivo</button><button type="button" class="button danger" id="session-all">Terminar em todos os dispositivos</button></div>
      </div>
      <div id="sessions" aria-live="polite"><div class="skeleton-stack"><div class="skeleton" style="height:54px"></div><div class="skeleton" style="height:54px"></div></div></div>
    </article>

    <article class="panel ap-card" style="--i:4">
      <p class="eyebrow">ATIVIDADE RECENTE</p>
      <h2>Últimos eventos da conta</h2>
      <ul class="ap-events" id="sec-events"></ul>
    </article>

    <!-- Direitos RGPD: ter uma cópia de tudo e poder apagar tudo (api/account.php) -->
    <article class="panel ap-card" id="my-data-card" style="--i:5">
      <p class="eyebrow">PRIVACIDADE E RGPD</p>
      <h2>Os meus dados</h2>
      <p class="muted">Tens direito a uma cópia de tudo o que o Lumina guarda sobre ti e a apagá-lo. Mais informação na <a href="politica-privacidade">Política de Privacidade</a>.</p>
      <div class="form-actions">
        <a class="button secondary" id="export-data" href="api/account.php?action=export" download>Descarregar os meus dados</a>
        <?php if ($isOwner): ?><button type="button" class="button danger" id="delete-account">Eliminar a minha conta</button><?php endif; ?>
      </div>
      <?php if (!$isOwner): ?><p class="muted">Para eliminar a tua conta, pede ao administrador do negócio.</p><?php endif; ?>
    </article>
  </section>
</main>

<?php require_once __DIR__ . '/includes/legal.php'; site_footer('compact'); ?>   <!-- rodapé com informação legal -->
<?php include __DIR__ . '/includes/partials/card_modal.php'; ?>   <!-- janela Adicionar cartão (só o dono) -->

<script src="assets/js/fundo-video.js"></script>
  <script src="assets/js/theme.js"></script>
<script src="assets/js/common.js"></script>     <!-- $, esc, api, msg... (carrega primeiro) -->
<script src="assets/js/ux.js"></script>
<script src="assets/js/privacy.js"></script>
<script src="assets/js/vendor/qrcode.js"></script>        <!-- QR code do 2.º passo (MIT) -->
<script src="assets/js/cards.js"></script>
<script src="assets/js/area-pessoal.js"></script>
<script src="assets/js/lumina-footer.js"></script>
<script src="assets/js/verify-banner.js"></script>
<script src="assets/js/pwa.js"></script>
</body>
</html>
