<?php
/* =========================================================================
   SITEMAP.XML  (sitemap.php, servido como /sitemap.xml pelo .htaccess ou pelo router.php)
   -------------------------------------------------------------------------
   A lista das páginas PÚBLICAS que queremos no Google: o início e a informação legal. As páginas privadas
   (painel, Área pessoal, recuperar palavra-passe) NÃO entram. "lastmod" é a data em que o texto mudou de verdade.
   Submete-o no Google Search Console (ver docs/SEO.md).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/seo.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$entries = [['loc' => seo_url(), 'file' => __DIR__ . '/index.php', 'priority' => '1.0']];
foreach (SEO_PUBLIC_PAGES as $slug) $entries[] = ['loc' => seo_url($slug), 'file' => __DIR__ . '/legal/documentos/' . $slug . '.md', 'priority' => '0.5'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($entries as $e) {
    echo "  <url><loc>" . htmlspecialchars($e['loc'], ENT_XML1) . "</loc><lastmod>" . date('Y-m-d', (int)filemtime($e['file'])) . "</lastmod><priority>{$e['priority']}</priority></url>\n";
}
echo "</urlset>\n";
