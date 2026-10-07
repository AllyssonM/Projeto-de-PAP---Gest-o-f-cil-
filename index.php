<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seo.php';
$user = current_user();
?>
<!doctype html>
<html lang="pt-PT">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php $seo = seo_config(); seo_head(['title' => $seo['home_title'], 'description' => $seo['home_description'], 'path' => '', 'schema' => seo_schema_home()]); ?>
  <link rel="preload" as="image" href="assets/video/fundo-poster.webp" type="image/webp" fetchpriority="high" media="(min-width: 901px)">
  <link rel="preload" as="image" href="assets/video/fundo-poster-m.webp" type="image/webp" fetchpriority="high" media="(max-width: 900px)">
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#061431">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/lumina-icon-32.png">
  <link rel="icon" type="image/png" sizes="64x64" href="assets/img/lumina-icon-64.png">
  <link rel="apple-touch-icon" href="assets/img/lumina-icon-180.png">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/fundo-video.css">
  <link rel="stylesheet" href="assets/css/boas-vindas.css">
  <link rel="stylesheet" href="assets/css/landing.css">
  <link rel="stylesheet" href="assets/css/effects.css">
  <link rel="stylesheet" href="assets/css/legal.css">
  <script>try{document.documentElement.dataset.tema=localStorage.getItem('gf-tema')||'dark'}catch(e){}</script>
  <?php include __DIR__ . '/includes/partials/i18n_head.php'; ?>
  <noscript><style>.reveal{opacity:1!important;transform:none!important}</style></noscript>
</head>
<body class="landing">
  <canvas id="reptile" aria-hidden="true"></canvas>

  <!-- ===== CABEÇALHO ===== -->
  <header class="site-header">
    <a class="brand" href="index.php"><img class="brand-logo" src="assets/img/lumina-mark-96.png" alt="" width="34" height="33"><span>Lumina</span></a>
    <nav class="main-nav" aria-label="Navegação principal">
      <a href="#funcionalidades">Funcionalidades</a>
      <a href="#ramos">Ramos</a>
      <a href="#como-funciona">Como funciona</a>
    </nav>
    <div class="header-actions">
      <button type="button" class="theme-toggle reptile-toggle" data-reptile-toggle aria-pressed="true" title="Réptil que segue o cursor" aria-label="Ativar/desativar o réptil">🦎</button>
      <span class="lang-pop-host"><button type="button" class="theme-toggle" data-lang-toggle aria-haspopup="true" aria-expanded="false" aria-label="Idioma" title="Idioma">🌐</button>
        <span class="lang-pop" hidden role="group" aria-label="Idioma"><button type="button" data-lang="pt" lang="pt" data-no-i18n>Português</button><button type="button" data-lang="en" lang="en" data-no-i18n>English</button><button type="button" data-lang="es" lang="es" data-no-i18n>Español</button></span></span>
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Alternar tema">☀</button>
      <?php if ($user): ?>
        <a class="button primary" href="dashboard.php">Ir para o painel →</a>
      <?php else: ?>
        <a class="button secondary" href="#autenticacao" data-open-mode="login">Entrar</a>
        <a class="button primary" href="#autenticacao" data-open-mode="register">Começar</a>
      <?php endif; ?>
    </div>
    <button class="menu-toggle" data-menu-toggle aria-label="Abrir menu">☰</button>
  </header>

  <main>
    <!-- ===== HERO ===== -->
    <section class="hero section-wrap">
      <div class="hero-copy reveal">
        <p class="eyebrow">GESTÃO SIMPLES PARA NEGÓCIOS REAIS</p>
        <h1>Organiza o teu negócio. <span class="text-gradient">Cresce com confiança.</span></h1>
        <p class="hero-text">Caixa, clientes, contas e estoque numa só plataforma, pensada para microempresários e preparada para qualquer ramo.</p>
        <div class="hero-actions">
          <?php if ($user): ?>
            <a class="button primary button-large" href="dashboard.php">Abrir o meu painel <span>→</span></a>
          <?php else: ?>
            <a class="button primary button-large" href="#autenticacao" data-open-mode="register">Começar agora <span>→</span></a>
          <?php endif; ?>
          <a class="button secondary button-large" href="#funcionalidades">Conhecer a plataforma</a>
        </div>
        <div class="hero-proof"><span>✓ Sem complicação</span><span>✓ Dados organizados</span><span>✓ Preparado para crescer</span></div>
      </div>

      <!-- SPLINE 3D: cola em data-spline-url o URL publicado da tua cena
           (Spline > Export > Code > Viewer). Ex.: data-spline-url="https://prod.spline.design/XXXX/scene.splinecode"
           Enquanto estiver vazio, aparece a animação em CSS. -->
      <div class="hero-visual reveal" data-spline-url="">
        <div class="orb orb-one"></div><div class="orb orb-two"></div>
        <div class="spline-fallback" aria-label="Demonstração visual">
          <div class="mini-window">
            <div class="mini-window-top"><i></i><i></i><i></i></div>
            <div class="mini-chart"><span style="height:38%"></span><span style="height:58%"></span><span style="height:48%"></span><span style="height:76%"></span><span style="height:66%"></span><span style="height:92%"></span></div>
            <div class="mini-cards"><b>+ 2.840 €</b><b>12 clientes</b></div>
          </div>
          <div class="floating-chip chip-a">↗ +18,4% este mês</div>
          <div class="floating-chip chip-b">◎ Caixa controlado</div>
        </div>
      </div>
    </section>

    <!-- ===== FUNCIONALIDADES ===== -->
    <section id="funcionalidades" class="section-wrap section-space">
      <div class="section-heading reveal">
        <p class="eyebrow">TUDO NUM SÓ LUGAR</p>
        <h2>Uma visão clara para cada decisão.</h2>
        <p>O Lumina transforma tarefas espalhadas em informação simples de entender.</p>
      </div>
      <div class="feature-grid">
        <article class="glass-card reveal"><span class="feature-icon">↗</span><h3>Fluxo de caixa</h3><p>Regista entradas e saídas, separa o previsto do realizado e vê o saldo de cada mês.</p></article>
        <article class="glass-card reveal"><span class="feature-icon">◎</span><h3>Clientes</h3><p>Guarda fichas, histórico e informação importante para cuidar melhor da relação.</p></article>
        <article class="glass-card reveal"><span class="feature-icon">▦</span><h3>Estoque inteligente</h3><p>Conhece quantidades, produtos em falta e alertas de estoque baixo.</p></article>
        <article class="glass-card reveal"><span class="feature-icon">▤</span><h3>Relatórios</h3><p>Consulta resumos, exporta para Excel e imprime documentos para apoiar a tua gestão.</p></article>
      </div>
    </section>

    <!-- ===== ANIMAÇÃO 3D COM SCROLL ===== -->
    <section id="showcase" class="showcase" aria-label="Demonstração 3D da plataforma">
      <div class="showcase-sticky">
        <div class="showcase-copy">
          <p class="eyebrow">DESLIZA PARA VER</p>
          <h2 id="showcase-title">Tudo espalhado.</h2>
          <p id="showcase-text">Folhas de cálculo, papéis e mensagens soltas dificultam qualquer decisão.</p>
          <ul class="showcase-steps" aria-hidden="true">
            <li data-step="0">Caos</li><li data-step="1">Organização</li><li data-step="2">Painel único</li>
          </ul>
        </div>
        <div class="showcase-stage">
          <div class="scene">
            <div class="layer layer-base"><div class="dots"><i></i><i></i><i></i></div></div>
            <div class="layer layer-chart"><span style="height:40%"></span><span style="height:62%"></span><span style="height:50%"></span><span style="height:82%"></span><span style="height:68%"></span><span style="height:95%"></span></div>
            <div class="layer layer-kpis"><b>Saldo<em>3.200 €</em></b><b>Entradas<em>3.820 €</em></b><b>Clientes<em>24</em></b></div>
            <div class="layer layer-list"><p><i></i>Venda de perfumes<span>+ 2.840 €</span></p><p><i></i>Compra de mercadoria<span>- 620 €</span></p></div>
            <div class="layer layer-badge">✓ Estoque em dia</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===== RAMOS ===== -->
    <section id="ramos" class="section-wrap section-space">
      <div class="split-section">
        <div class="reveal">
          <p class="eyebrow">ADAPTA-SE AO TEU NEGÓCIO</p>
          <h2>Uma plataforma, vários ramos.</h2>
          <p>Escolhe o teu perfil e vê uma experiência ajustada ao que realmente precisas.</p>
          <a class="text-link" href="#autenticacao" data-open-mode="register">Escolher o meu ramo →</a>
        </div>
        <div class="profile-pills reveal">
          <span>🛒 Loja online</span><span>🍽️ Restaurante / café</span><span>🚗 Venda de carros</span>
          <span>🏠 Imobiliária</span><span>👤 Gestão pessoal</span><span>🚘 TVDE / Uber</span>
          <span>💻 Freelancer</span><span>👕 Loja de roupas</span><span>📊 Negócio geral</span>
        </div>
      </div>
    </section>

    <!-- ===== COMO FUNCIONA ===== -->
    <section id="como-funciona" class="section-wrap section-space">
      <div class="steps-grid">
        <article class="glass-card reveal"><span class="step-number">1</span><h3>Cria a tua conta</h3><p>Em poucos segundos, com nome, email e palavra-passe.</p></article>
        <article class="glass-card reveal"><span class="step-number">2</span><h3>Escolhe o teu ramo</h3><p>A plataforma adapta textos e atalhos ao teu tipo de negócio.</p></article>
        <article class="glass-card reveal"><span class="step-number">3</span><h3>Gere tudo num só sítio</h3><p>Regista movimentos, clientes e produtos e acompanha os resultados.</p></article>
      </div>
      <div class="cta-panel reveal">
        <div><p class="eyebrow">PRONTO PARA COMEÇAR?</p><h2>Menos tempo a procurar. Mais tempo a decidir.</h2></div>
        <?php if ($user): ?>
          <a class="button primary button-large" href="dashboard.php">Abrir o painel →</a>
        <?php else: ?>
          <a class="button primary button-large" href="#autenticacao" data-open-mode="register">Criar conta grátis →</a>
        <?php endif; ?>
      </div>
    </section>

    <!-- ===== LOGIN / REGISTO (animado) ===== -->
    <section id="autenticacao" class="auth-section section-wrap">
      <?php if ($user): ?>
      <div class="auth-card reveal">
        <p class="eyebrow">SESSÃO ATIVA</p>
        <h2>Olá, <?= e($user['name'] ?? '') ?> 👋</h2>
        <p class="muted">Já tens sessão iniciada. Continua onde ficaste.</p>
        <a class="button primary full" href="dashboard.php">Ir para o painel →</a>
      </div>
      <?php else: ?>
      <div class="auth-box reveal" id="auth-box">

        <!-- Criar conta -->
        <div class="form-panel register-panel">
          <form id="register-form" novalidate>
            <p class="eyebrow" data-i18n-ctx="auth">NOVA CONTA</p>
            <h2>Criar conta</h2>
            <label>Nome<span class="field"><i>👤</i><input name="name" minlength="2" autocomplete="name" placeholder="O teu nome" required></span></label>
            <label>Email<span class="field"><i>✉</i><input name="email" type="email" autocomplete="email" placeholder="nome@exemplo.pt" required></span></label>
            <label>Palavra-passe<span class="field"><i>🔒</i><input name="password" type="password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required><button type="button" class="pw-toggle" aria-label="Mostrar palavra-passe">👁</button></span></label>
            <label>Confirmar palavra-passe<span class="field"><i>🔒</i><input name="confirm" type="password" minlength="8" autocomplete="new-password" placeholder="Repete a palavra-passe" required></span></label>
            <button class="button primary" type="submit">Criar conta</button>
            <p class="message" role="alert"></p>
            <p class="mobile-switch">Já tens conta? <button type="button" data-mode="login">Entrar</button></p>
          </form>
        </div>

        <!-- Entrar -->
        <div class="form-panel login-panel">
          <form id="login-form" novalidate>
            <p class="eyebrow">BEM-VINDO</p>
            <h2>Entrar</h2>
            <label>Email<span class="field"><i>✉</i><input name="email" type="email" autocomplete="email" placeholder="nome@exemplo.pt" required></span></label>
            <label>Palavra-passe<span class="field"><i>🔒</i><input name="password" type="password" autocomplete="current-password" placeholder="A tua palavra-passe" required><button type="button" class="pw-toggle" aria-label="Mostrar palavra-passe">👁</button></span></label>
            <button class="button primary" type="submit">Entrar</button>
            <p class="message" role="alert"></p>
            <p class="forgot-link"><a class="text-link" href="esqueci-palavra-passe.php">Esqueci-me da palavra-passe</a></p>
            <p class="mobile-switch">Ainda não tens conta? <button type="button" data-mode="register">Criar conta</button></p>
          </form>
        </div>

        <!-- Painel deslizante -->
        <div class="overlay-container" aria-hidden="false">
          <div class="overlay">
            <div class="overlay-panel overlay-left">
              <h2>Já tens conta?</h2>
              <p>Entra para continuares a gerir o teu negócio.</p>
              <button type="button" class="button ghost-light" data-mode="login">Entrar</button>
            </div>
            <div class="overlay-panel overlay-right">
              <h2>Novo por aqui?</h2>
              <p>Cria a tua conta em segundos e organiza o teu negócio.</p>
              <button type="button" class="button ghost-light" data-mode="register">Criar conta</button>
            </div>
          </div>
        </div>
      </div>
      <p class="form-note">Os teus dados ficam guardados na base de dados MySQL do projeto.</p>
      <?php endif; ?>
    </section>
  </main>

  <?php require_once __DIR__ . '/includes/legal.php'; site_footer('full'); ?>   <!-- rodapé com informação legal -->

  <script src="assets/js/fundo-video.js" defer></script>
  <script src="assets/js/boas-vindas.js" defer></script>
  <script src="assets/js/theme.js" defer></script>
  <script src="assets/js/landing.js" defer></script>
  <script src="assets/js/scroll3d.js" defer></script>
  <script src="assets/js/reptile.js" defer></script>
  <script src="assets/js/lumina-footer.js" defer></script>
<script src="assets/js/pwa.js" defer></script>
</body>
</html>
