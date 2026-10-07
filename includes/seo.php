<?php
/* =========================================================================
   SEO  (includes/seo.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  gera tudo o que os motores de pesquisa e as partilhas leem no <head>:
                 título, meta descrição, endereço canónico, Open Graph e Twitter (imagem de partilha),
                 dados estruturados (JSON-LD) e a etiqueta noindex das páginas privadas.
   COMO SE USA:
       seo_head(['title' => '...', 'description' => '...', 'path' => 'politica-privacidade', 'schema' => [...]]);   // página PÚBLICA
       seo_head(['title' => '...', 'index' => false]);                                                             // página PRIVADA (noindex)
   REGRA:      páginas públicas (início, informação legal) são indexáveis; tudo o que tem dados de uma conta
               (painel, Área pessoal, recuperar palavra-passe...) leva noindex (etiqueta + cabeçalho HTTP).
   O endereço público vem de config/app.php (APP_URL).
   ========================================================================= */
declare(strict_types=1);

/** Páginas públicas com endereço limpo (sem .php). Tem de coincidir com as regras do .htaccess e com o router.php (há um teste que o confirma). */
const SEO_PUBLIC_PAGES = ['politica-privacidade', 'politica-cookies', 'termos-e-condicoes', 'informacao-legal'];

function seo_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/seo.php';
        $cfg = array_replace(['site_name' => 'Lumina', 'home_title' => 'Lumina', 'home_description' => '', 'og_image' => 'assets/img/og-lumina.jpg', 'locale' => 'pt_PT',
            'google_site_verification' => '', 'bing_site_verification' => ''], is_file($file) ? (array)require $file : []);
    }
    return $cfg;
}

/** Endereço absoluto de uma página pública ('' = início). */
function seo_url(string $path = ''): string { return app_base_url() . '/' . ltrim($path, '/'); }

function seo_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** Cabeçalho HTTP que diz aos motores de pesquisa para NÃO indexar esta resposta (páginas privadas e API). */
function seo_noindex_header(): void
{
    if (!headers_sent()) header('X-Robots-Tag: noindex, nofollow');
}

/** Corta um texto em palavras inteiras, no máximo $max carateres (para as meta descrições). */
function seo_trim(string $s, int $max = 158): string
{
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    return rtrim(mb_substr($cut, 0, (int)mb_strrpos($cut, ' ') ?: $max - 1), " ,.;:") . '…';
}

/**
 * Escreve as etiquetas do <head> (menos <meta charset>, viewport e ícones).
 * Opções: title, description, path (só páginas públicas), index (true por omissão), type ('website'), schema (lista de objetos JSON-LD).
 */
function seo_head(array $o): void
{
    $cfg = seo_config();
    $index = $o['index'] ?? true;
    $title = (string)($o['title'] ?? $cfg['site_name']);
    echo '  <title>' . seo_e($title) . "</title>\n";
    if (!$index) {                                                    // página privada: só o necessário, e nunca partilhável
        echo "  <meta name=\"robots\" content=\"noindex, nofollow\">\n";
        return;
    }
    $desc = seo_trim((string)($o['description'] ?? $cfg['home_description']));
    $url = seo_url((string)($o['path'] ?? ''));
    $img = seo_url((string)($o['image'] ?? $cfg['og_image']));
    echo '  <meta name="description" content="' . seo_e($desc) . "\">\n";
    echo "  <meta name=\"robots\" content=\"index, follow, max-image-preview:large\">\n";
    echo '  <link rel="canonical" href="' . seo_e($url) . "\">\n";
    echo '  <link rel="alternate" hreflang="pt-PT" href="' . seo_e($url) . "\">\n";
    foreach ([['property', 'og:type', $o['type'] ?? 'website'], ['property', 'og:site_name', $cfg['site_name']], ['property', 'og:locale', $cfg['locale']], ['property', 'og:title', $title],
              ['property', 'og:description', $desc], ['property', 'og:url', $url], ['property', 'og:image', $img], ['property', 'og:image:width', '1200'], ['property', 'og:image:height', '630'],
              ['property', 'og:image:alt', 'Lumina: gestão simples e clara para microempresários'], ['name', 'twitter:card', 'summary_large_image'], ['name', 'twitter:title', $title],
              ['name', 'twitter:description', $desc], ['name', 'twitter:image', $img]] as [$attr, $name, $val]) {
        echo "  <meta $attr=\"$name\" content=\"" . seo_e((string)$val) . "\">\n";
    }
    if ($cfg['google_site_verification'] !== '') echo '  <meta name="google-site-verification" content="' . seo_e($cfg['google_site_verification']) . "\">\n";
    if ($cfg['bing_site_verification'] !== '')   echo '  <meta name="msvalidate.01" content="' . seo_e($cfg['bing_site_verification']) . "\">\n";
    if (!empty($o['schema'])) {
        echo '  <script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $o['schema']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . "</script>\n";
    }
}

/** Dados estruturados da página inicial: organização, site e aplicação. Só factos verdadeiros (sem preços nem avaliações inventadas). */
function seo_schema_home(): array
{
    $cfg = seo_config(); $home = seo_url(); $org = $home . '#organizacao';
    return [
        ['@type' => 'Organization', '@id' => $org, 'name' => $cfg['site_name'], 'url' => $home, 'logo' => ['@type' => 'ImageObject', 'url' => seo_url('assets/img/lumina-icon-180.png'), 'width' => 180, 'height' => 180]],
        ['@type' => 'WebSite', '@id' => $home . '#site', 'url' => $home, 'name' => $cfg['site_name'], 'inLanguage' => 'pt-PT', 'publisher' => ['@id' => $org]],
        ['@type' => 'SoftwareApplication', 'name' => $cfg['site_name'], 'url' => $home, 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web',
         'inLanguage' => 'pt-PT', 'description' => $cfg['home_description'], 'image' => seo_url($cfg['og_image']), 'publisher' => ['@id' => $org]],
    ];
}

/** Dados estruturados de uma página de informação legal: a página e o caminho (Início > página). */
function seo_schema_page(string $title, string $path, string $description): array
{
    $cfg = seo_config(); $url = seo_url($path);
    return [
        ['@type' => 'WebPage', '@id' => $url . '#pagina', 'url' => $url, 'name' => $title, 'description' => $description, 'inLanguage' => 'pt-PT', 'isPartOf' => ['@id' => seo_url() . '#site']],
        ['@type' => 'BreadcrumbList', 'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => seo_url()],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $title, 'item' => $url]]],
    ];
}
