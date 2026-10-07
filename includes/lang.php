<?php
/* =========================================================================
   IDIOMA NO SERVIDOR  (includes/lang.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  diz em que idioma (pt, en, es) responder quando o TEXTO é feito no servidor: respostas da Lumina,
               emails, documentos legais. O resto da interface traduz-se no navegador (assets/js/i18n.js).
   COMO SABE O IDIOMA:  cookie "lumina_lang" (escrito por assets/js/i18n-boot.js: escolha manual ou deteção);
                        sem cookie, usa o cabeçalho Accept-Language do navegador; senão, português.
   TRADUÇÕES:  includes/lang/en.php e includes/lang/es.php: listas  "texto em português" => "tradução".
               L('Texto em português %s', $valor) devolve o texto no idioma atual (sem tradução: o original).
   NOVO IDIOMA: acrescentar a sigla em LUMINA_LANGS, criar includes/lang/<sigla>.php e assets/i18n/<sigla>.json.
   ========================================================================= */
declare(strict_types=1);

const LUMINA_LANGS = ['pt' => 'pt-PT', 'en' => 'en-GB', 'es' => 'es-ES'];

/** Idioma atual do pedido: 'pt' | 'en' | 'es'. */
function lumina_lang(): string
{
    if (isset($GLOBALS['__lumina_lang_override'])) return $GLOBALS['__lumina_lang_override'];
    $g = (string)($_GET['lang'] ?? '');                       // ligações dos idiomas no rodapé (funcionam também sem JavaScript)
    if (PHP_SAPI !== 'cli' && isset(LUMINA_LANGS[$g])) return $g;
    $c = (string)($_COOKIE['lumina_lang'] ?? '');
    if (isset(LUMINA_LANGS[$c])) return $c;
    foreach (explode(',', strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $part) {
        $code = substr(trim(explode(';', $part)[0]), 0, 2);
        if (isset(LUMINA_LANGS[$code])) return $code;
    }
    return 'pt';
}

/** Fixa o idioma deste pedido (a Lumina usa-o quando o navegador envia "lang"; os testes também). */
function lumina_set_lang(string $lang): void
{
    if (isset(LUMINA_LANGS[$lang])) $GLOBALS['__lumina_lang_override'] = $lang;
}

function lumina_locale(): string { return LUMINA_LANGS[lumina_lang()]; }

/** Tradução de um texto em português para o idioma atual. Com argumentos, usa sprintf. */
function L(string $pt, ...$args): string
{
    static $dicts = [];
    $lang = lumina_lang();
    $out = $pt;
    if ($lang !== 'pt') {
        if (!isset($dicts[$lang])) { $f = __DIR__ . '/lang/' . $lang . '.php'; $dicts[$lang] = is_file($f) ? (array)require $f : []; }
        $out = $dicts[$lang][$pt] ?? $pt;
    }
    return $args ? vsprintf($out, $args) : $out;
}
