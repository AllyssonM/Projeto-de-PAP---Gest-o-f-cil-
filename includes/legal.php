<?php
/* =========================================================================
   PÁGINAS LEGAIS E RODAPÉ  (includes/legal.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  peças partilhadas pelas páginas politica-privacidade,
               politica-cookies e termos-e-condicoes, e o rodapé usado em todo o
               sistema.
     legal_cfg()                   dados de config/legal.php (com valores por omissão)
     legal_page_start($titulo)     abre uma página legal (cabeçalho com logo e voltar)
     legal_page_end()              fecha a página (rodapé e scripts)
     site_footer('full'|'compact') o rodapé: completo (página inicial) ou compacto (painel)
   As páginas legais são PÚBLICAS: não exigem login.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/markdown.php';
require_once __DIR__ . '/seo.php';
require_once __DIR__ . '/lang.php';

function legal_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/legal.php';
        $cfg = array_replace(['name' => 'Lumina', 'controller' => '', 'tax_number' => '', 'address' => '', 'email' => '', 'phone' => '', 'updated' => '', 'needs_review' => true, 'complaints_book' => false],
            is_file($file) ? (array)require $file : []);
    }
    return $cfg;
}

/** Marcadores {{...}} dos documentos em legal/documentos/*.md, já seguros para pôr no HTML. */
function legal_vars(): array
{
    $c = legal_cfg();
    $mail = $c['email'] ? '<a href="mailto:' . lh($c['email']) . '">' . lh($c['email']) . '</a>' : '';
    return [
        'nome' => lh($c['name']), 'responsavel' => lh($c['controller']), 'atualizado' => lh($c['updated']),
        'nif_linha' => $c['tax_number'] ? ', ' . L('NIF') . ' ' . lh($c['tax_number']) : '', 'morada_linha' => $c['address'] ? ', ' . lh($c['address']) : '',
        'contacto_privacidade' => $mail ? L('em %s', $mail) : L('através do administrador do sistema'),
        'contacto_termos' => $mail ?: L('fala com o administrador do sistema'),
        'livro_frase' => !empty($c['complaints_book']) ? ' ' . L('Podes também apresentar reclamação no %s.', '<a href="https://www.livroreclamacoes.pt" target="_blank" rel="noopener noreferrer">' . L('Livro de Reclamações Eletrónico') . '</a>') : '',
    ];
}

/** Mostra uma página pública a partir de legal/documentos/<slug>.md (a primeira linha "# Título" é o título da página). */
function legal_document(string $slug): void
{
    $file = __DIR__ . '/../legal/documentos/' . basename($slug) . '.md';
    $lang = lumina_lang();
    $tr = __DIR__ . '/../legal/documentos/' . $lang . '/' . basename($slug) . '.md';        // tradução (en, es); sem ela, mostra o original em português
    $translated = $lang !== 'pt' && is_file($tr);
    if ($translated) $file = $tr;
    if (!preg_match('/^[a-z0-9-]+$/', $slug) || !is_file($file)) { http_response_code(404); echo L('Documento não encontrado.'); return; }
    $md = (string)file_get_contents($file);
    $vars = legal_vars();
    legal_page_start(md_title($md, $slug), true, 'INFORMAÇÃO LEGAL', ['path' => $slug, 'description' => md_description($md, $vars)]);
    if ($translated) echo '<p class="legal-note" role="note" data-no-i18n>' . lh(L('Tradução para facilitar a leitura. Em caso de divergência, prevalece a versão em português.')) . '</p>';
    echo md_render($md, $vars);
    legal_page_end();
}

function lh(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** O rodapé. "full" = página inicial (colunas); "compact" = painel e Área pessoal (uma linha). */
function site_footer(string $variant = 'full', string $base = ''): void
{
    $c = legal_cfg();
    $year = (new DateTime('now', new DateTimeZone('Europe/Lisbon')))->format('Y');
    $legal = [
        [$base . 'politica-privacidade', 'Política de Privacidade'],
        [$base . 'politica-cookies', 'Política de Cookies'],
        [$base . 'termos-e-condicoes', 'Termos e Condições'],
        [$base . 'informacao-legal', 'Informação legal'],
    ];
    $complaints = !empty($c['complaints_book']) ? '<a href="https://www.livroreclamacoes.pt" target="_blank" rel="noopener noreferrer">Livro de Reclamações Eletrónico</a>' : '';
    $top = '<button type="button" class="lf-top" data-back-to-top aria-label="Voltar ao topo da página"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>';
    $copy = '© ' . $year . ' ' . lh($c['name']) . ' · Projeto PAP de ' . lh($c['controller'] ? 'Alisson Miguel Mota Madalena, Arthur Siqueira e Arthur Silva' : '');
    $copy = '© ' . $year . ' ' . lh($c['name']) . ' · Projeto PAP de Alisson Miguel Mota Madalena, Arthur Siqueira e Arthur Silva';

    $langs = '<span class="lf-lang" role="group" aria-label="Idioma"><a href="?lang=pt" data-lang="pt" lang="pt" data-no-i18n>Português</a><a href="?lang=en" data-lang="en" lang="en" data-no-i18n>English</a><a href="?lang=es" data-lang="es" lang="es" data-no-i18n>Español</a></span>';
    if ($variant === 'compact') {
        echo '<footer class="lum-footer compact page-footer no-print"><div class="lf-bar"><span>' . $copy . '</span><nav class="lf-legal" aria-label="Informação legal">';
        foreach ($legal as [$href, $label]) echo '<a href="' . lh($href) . '">' . lh($label) . '</a>';
        echo $complaints . '</nav>' . $langs . $top . '</div></footer>';
        return;
    }
    echo '<footer class="lum-footer full no-print"><div class="lf-grid">';
    echo '<div class="lf-brand"><span class="lf-logo"><img src="' . lh($base) . 'assets/img/lumina-mark-96.png" alt="" width="40" height="39"><strong>' . lh($c['name']) . '</strong></span><p>Gestão simples e clara para microempresários.</p></div>';
    echo '<nav class="lf-col" aria-label="Links úteis"><p class="lf-title">Links úteis</p><ul><li><a href="' . lh($base) . 'index.php">Página inicial</a></li><li><a href="' . lh($base) . 'dashboard.php">Painel</a></li><li><a href="' . lh($base) . 'area-pessoal.php">Área pessoal</a></li></ul></nav>';
    echo '<nav class="lf-col" aria-label="Informação legal"><p class="lf-title">Informação legal</p><ul>';
    foreach ($legal as [$href, $label]) echo '<li><a href="' . lh($href) . '">' . lh($label) . '</a></li>';
    if ($complaints) echo '<li>' . $complaints . '</li>';
    echo '</ul></nav>';
    if ($c['email'] || $c['phone'] || $c['address']) {
        echo '<div class="lf-col"><p class="lf-title">Contactos</p><ul>';
        if ($c['email']) echo '<li><a href="mailto:' . lh($c['email']) . '">' . lh($c['email']) . '</a></li>';
        if ($c['phone']) echo '<li>' . lh($c['phone']) . '</li>';
        if ($c['address']) echo '<li>' . lh($c['address']) . '</li>';
        echo '</ul></div>';
    }
    echo '</div><div class="lf-bar"><span>' . $copy . '</span>' . $langs . $top . '</div></footer>';
}

/** Abre uma página pública, com o mesmo aspeto do resto do sistema. $legal=false (recuperar palavra-passe, confirmar email...) não mostra a data de revisão nem o aviso de texto-modelo. */
function legal_page_start(string $title, bool $legal = true, string $eyebrow = 'INFORMAÇÃO LEGAL', array $seo = []): void
{
    require_once __DIR__ . '/auth.php';                                  // só para saber se há sessão (mostra "Voltar ao painel")
    $logged = !empty($_SESSION['user']);
    $c = legal_cfg();
    if (!$legal) seo_noindex_header();                              // páginas de conta (recuperar palavra-passe...): nunca no Google
    ?>
<!doctype html>
<html lang="<?= lh(lumina_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php
  $full = $title . ' — ' . $c['name'];
  seo_head($legal ? ['title' => $full, 'description' => $seo['description'] ?? ($title . ' do ' . $c['name'] . '.'), 'path' => $seo['path'] ?? '',
                     'schema' => isset($seo['path']) ? seo_schema_page($title, $seo['path'], $seo['description'] ?? '') : []]
                  : ['title' => $full, 'index' => false]);
?>
  <link rel="icon" type="image/png" sizes="32x32" href="assets/img/lumina-icon-32.png">
  <link rel="apple-touch-icon" href="assets/img/lumina-icon-180.png">
  <link rel="manifest" href="manifest.webmanifest">
  <meta name="theme-color" content="#061431">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/fundo-video.css">
  <link rel="stylesheet" href="assets/css/effects.css">
  <link rel="stylesheet" href="assets/css/ux.css">
  <link rel="stylesheet" href="assets/css/legal.css">
  <script>try{document.documentElement.dataset.tema=localStorage.getItem('gf-tema')||'dark'}catch(e){}</script>
  <?php include __DIR__ . '/partials/i18n_head.php'; ?>
</head>
<body>
<a class="skip-link" href="#conteudo">Saltar para o conteúdo</a>
<header class="topbar">
  <a class="brand-eyebrow lf-home" href="index.php" aria-label="<?= lh($c['name']) ?> — página inicial"><img src="assets/img/lumina-mark-96.png" alt="" width="34" height="33"><strong><?= lh($c['name']) ?></strong></a>
  <div class="top-actions">
    <a class="button secondary" href="<?= $logged ? 'dashboard.php' : 'index.php' ?>">← <?= $logged ? 'Voltar ao painel' : 'Voltar ao início' ?></a>
    <button type="button" class="theme-toggle" data-theme-toggle aria-label="Alternar tema">☀</button>
  </div>
</header>
<main id="conteudo" class="container legal">
  <article class="panel legal-doc">
    <?php if ($legal): ?><nav class="legal-crumbs" aria-label="Caminho"><a href="index.php">Início</a><span aria-hidden="true"> › </span><span aria-current="page"><?= lh($title) ?></span></nav><?php endif; ?>
    <p class="eyebrow"><?= lh($eyebrow) ?></p>
    <h1><?= lh($title) ?></h1>
    <?php if ($legal): ?><p class="muted">Última atualização: <?= lh($c['updated']) ?></p><?php endif; ?>
    <?php if ($legal && !empty($c['needs_review'])): ?>
    <p class="legal-note" role="note"><strong>Texto-modelo.</strong> Este documento descreve o que o <?= lh($c['name']) ?> faz de facto com os dados, mas deve ser revisto por um jurista antes de ser usado com clientes reais.</p>
    <?php endif; ?>
<?php
}

function legal_page_end(string $script = ''): void
{
    ?>
  </article>
</main>
<?php site_footer('compact'); ?>
<script src="assets/js/fundo-video.js" defer></script>
<script src="assets/js/theme.js" defer></script>
<script src="assets/js/lumina-footer.js" defer></script>
<script src="assets/js/pwa.js" defer></script>
<?php if ($script !== ''): ?><script src="<?= lh($script) ?>" defer></script><?php endif; ?>
</body>
</html>
<?php
}
