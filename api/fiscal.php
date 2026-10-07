<?php
/* =========================================================================
   PRÓXIMAS DATAS FISCAIS  (api/fiscal.php)
   -------------------------------------------------------------------------
   GET devolve as datas fiscais indicativas dos próximos 75 dias, de acordo com as respostas do
   questionário de arranque (regime de IVA e Segurança Social). Só quem vê o Fluxo de caixa.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal.php';
$user = require_login();
require_permission($user, 'cashflow');
try {
    $st = db()->prepare('SELECT onboarding FROM business_profiles WHERE user_id = ? LIMIT 1');
    $st->execute([$user['tenant_id']]);
    $answers = json_decode((string)$st->fetchColumn(), true);
    $answered = is_array($answers) && (isset($answers['vat']) || isset($answers['ss']));
    $res = fiscal_deadlines(is_array($answers) ? $answers : [], new DateTimeImmutable('now', app_timezone()));
    json_response(['success' => true, 'answered' => $answered, 'deadlines' => $res['deadlines'], 'disclaimer' => $res['disclaimer']]);
} catch (Throwable $e) {
    internal_error($e);
}
