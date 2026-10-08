<?php
/* =========================================================================
   ROBOTS.TXT  (robots.php, servido como /robots.txt pelo .htaccess ou pelo router.php)
   -------------------------------------------------------------------------
   Diz aos motores de pesquisa o que podem ler. Gerado aqui (e não um ficheiro fixo) porque a linha "Sitemap:"
   tem de ter o endereço completo de CADA instalação. As páginas privadas (painel, Área pessoal...) não aparecem
   no Google porque têm "noindex"; as pastas internas ficam fora do alcance.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\nAllow: /\n";
foreach (['api', 'storage', 'config', 'includes', 'database', 'tests', 'legal', 'docs'] as $dir) echo "Disallow: /$dir/\n";
echo "Disallow: /health\n";
echo "\nSitemap: " . app_base_url() . "/sitemap.xml\n";
