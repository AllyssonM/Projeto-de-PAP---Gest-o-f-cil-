<?php
/* =========================================================================
   /health  (health.php; o .htaccess e o router.php servem-no também sem ".php")
   -------------------------------------------------------------------------
   Para monitores de disponibilidade. Vista pública mínima; a detalhada só com o cabeçalho X-Health-Token (ver includes/health.php).
   Sem sessão, sem cookies, sem escrita, sem texto de erro na resposta.
   ========================================================================= */
declare(strict_types=1);

@ini_set('display_errors', '0');                         // nunca mostrar avisos do PHP numa resposta que um monitor lê
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');                           // não anuncia a versão do PHP

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    echo '{"status":"error","error":"method_not_allowed"}';
    exit;
}

try {
    require_once __DIR__ . '/includes/health.php';
    $given = isset($_SERVER['HTTP_X_HEALTH_TOKEN']) ? (string)$_SERVER['HTTP_X_HEALTH_TOKEN'] : null;
    $result = health_collect(health_token_ok($given, health_token()));
} catch (Throwable $e) {
    error_log('Lumina saúde: erro inesperado (' . get_class($e) . '): ' . $e->getMessage());
    $result = ['http' => 503, 'body' => ['status' => 'down', 'checks' => ['app' => 'fail']]];
}

http_response_code($result['http']);
if ($method !== 'HEAD') {
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
