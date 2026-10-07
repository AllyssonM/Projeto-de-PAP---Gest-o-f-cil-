<?php
/* =========================================================================
   ROUTER PARA O SERVIDOR DE DESENVOLVIMENTO DO PHP  (router.php)
   -------------------------------------------------------------------------
   No Apache/XAMPP os endereços limpos (/politica-privacidade, /robots.txt, /sitemap.xml) funcionam graças ao .htaccess.
   O servidor embutido do PHP não lê o .htaccess; para testar assim, usa:
       php -S 127.0.0.1:8080 router.php
   (ou  php -S 127.0.0.1:8080 -t . router.php). Não é preciso no Apache.
   ========================================================================= */
require_once __DIR__ . '/includes/seo.php';
$path = ltrim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$route = in_array($path, SEO_PUBLIC_PAGES, true) ? $path . '.php' : ['robots.txt' => 'robots.php', 'sitemap.xml' => 'sitemap.php'][$path] ?? null;
if ($route === null) return false;                         // tudo o resto: o servidor trata como habitualmente
$_SERVER['SCRIPT_NAME'] = '/' . $route;
require __DIR__ . '/' . $route;
return true;
