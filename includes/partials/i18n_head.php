<?php
/* Idioma: ligações do <head> (assets/js/i18n-boot.js corre primeiro e decide o idioma; assets/js/i18n.js traduz a página). */
require_once __DIR__ . '/../lang.php';
$__v = fn(string $f) => (int)@filemtime(__DIR__ . '/../../' . $f);
if (!empty($GLOBALS['lumina_account_lang'])) echo '<script>window.LUMINA_ACCOUNT_LANG=' . json_encode($GLOBALS['lumina_account_lang']) . '</script>', "\n";
?>
  <script src="assets/js/i18n-boot.js?v=<?= $__v('assets/js/i18n-boot.js') ?>" data-v="<?= $__v('assets/i18n/en.json') . $__v('assets/i18n/es.json') ?>"></script>
  <script src="assets/js/i18n.js?v=<?= $__v('assets/js/i18n.js') ?>" defer></script>
  <link rel="stylesheet" href="assets/css/i18n.css?v=<?= $__v('assets/css/i18n.css') ?>">
