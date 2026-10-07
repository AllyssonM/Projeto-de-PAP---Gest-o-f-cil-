<?php
declare(strict_types=1);

/* Fuso horário do programa (datas, horas e aviso de reuniões).
   Muda este valor se as reuniões forem marcadas noutro fuso. */
const APP_TIMEZONE = 'Europe/Lisbon';

/* Mostrar os detalhes técnicos dos erros no ecrã? Só para desenvolvimento: deixa a false em uso normal
   (os detalhes ficam sempre no log de erros do PHP). */
const APP_DEBUG = false;

/* Endereço público do Lumina, sem barra no fim (ex.: 'https://lumina.exemplo.pt' ou 'https://exemplo.pt/lumina').
   Usado nos links dos emails, no endereço canónico, no sitemap e nas partilhas (Open Graph).
   DEIXA VAZIO em localhost (é deduzido do pedido). Em produção PREENCHE-O: é o endereço que os motores de pesquisa vão guardar. */
const APP_URL = '';

/** Endereço base do Lumina (sem barra no fim). Usa APP_URL; se estiver vazio, deduz-o do pedido (com o host limpo de carateres estranhos). */
function app_base_url(): string
{
    if (APP_URL !== '') return rtrim(APP_URL, '/');
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host   = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
    $dir    = preg_replace('#/(api|includes)(/[^/]*)?$#', '', dirname($script));
    return ($https ? 'https' : 'http') . '://' . $host . (in_array($dir, ['\\', '.', '/'], true) ? '' : rtrim($dir, '/'));
}

function app_timezone(): DateTimeZone
{
    static $zone = null;
    if ($zone instanceof DateTimeZone) {
        return $zone;
    }
    try {
        $zone = new DateTimeZone(APP_TIMEZONE);
    } catch (Throwable $error) {
        $zone = new DateTimeZone('Europe/Lisbon');   // valor inválido: volta ao padrão
    }
    return $zone;
}

date_default_timezone_set(app_timezone()->getName());
?>
