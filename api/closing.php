<?php
/* =========================================================================
   FECHO DO DIA  (api/closing.php)
   -------------------------------------------------------------------------
   O ritual diário do pequeno comércio: ver o que entrou e saiu hoje, contar o dinheiro da caixa e ver se bate certo.
   GET  ?day=AAAA-MM-DD   totais do dia (só movimentos REALIZADOS), o dinheiro que devia estar na caixa e os últimos fechos.
   POST action=save       grava o fecho: {day, counted_cash, note}. Pode refazer-se o mesmo dia (substitui).
   O DINHEIRO ESPERADO conta só os movimentos pagos em dinheiro (uma venda por MB Way não está na gaveta):
       esperado = entradas em dinheiro - saídas em dinheiro do dia.
   Quem o vê: quem tem a permissão do Fluxo de caixa. Os cálculos são SEMPRE feitos aqui, no servidor.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
$user = require_login();
require_permission($user, 'cashflow');
$tid = (int)$user['tenant_id'];

/** Totais de um dia (data local do negócio). */
function day_totals(int $tid, string $day): array
{
    $st = db()->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) expense,
        COALESCE(SUM(CASE WHEN type='income' AND payment_method='cash' THEN amount END),0) cash_in, COALESCE(SUM(CASE WHEN type='expense' AND payment_method='cash' THEN amount END),0) cash_out,
        COUNT(*) n, SUM(payment_method IS NULL) n_unknown
        FROM transactions WHERE user_id = ? AND status = 'paid' AND occurred_at >= ? AND occurred_at < DATE_ADD(?, INTERVAL 1 DAY)");
    $st->execute([$tid, $day . ' 00:00:00', $day]);
    $r = $st->fetch();
    return ['income' => (float)$r['income'], 'expense' => (float)$r['expense'], 'cash_in' => (float)$r['cash_in'], 'cash_out' => (float)$r['cash_out'],
            'expected_cash' => round((float)$r['cash_in'] - (float)$r['cash_out'], 2), 'count' => (int)$r['n'], 'without_method' => (int)$r['n_unknown']];
}

try {
    $today = (new DateTimeImmutable('today', app_timezone()))->format('Y-m-d');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $day = Validator::make(['day' => $_GET['day'] ?? $today])->date('day', 'Dia')->orFail()['day'];
        $st = db()->prepare('SELECT day, income_total, expense_total, expected_cash, counted_cash, difference, note FROM day_closings WHERE user_id = ? ORDER BY day DESC LIMIT 14');
        $st->execute([$tid]);
        $recent = $st->fetchAll();
        $closed = null;
        foreach ($recent as $c) if ($c['day'] === $day) $closed = $c;
        json_response(['success' => true, 'day' => $day, 'totals' => day_totals($tid, $day), 'closed' => $closed, 'recent' => $recent]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $in = Validator::make(request_json())->date('day', 'Dia')->money('counted_cash', 'Dinheiro contado', false)->text('note', 'Nota', 255, false, null)->orFail();
    if ($in['day'] > $today) json_response(['success' => false, 'error' => 'Não se pode fechar um dia que ainda não chegou.'], 400);
    $t = day_totals($tid, $in['day']);
    $diff = round($in['counted_cash'] - $t['expected_cash'], 2);
    db()->prepare('INSERT INTO day_closings (user_id, closed_by, day, income_total, expense_total, expected_cash, counted_cash, difference, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE closed_by = VALUES(closed_by), income_total = VALUES(income_total), expense_total = VALUES(expense_total), expected_cash = VALUES(expected_cash),
        counted_cash = VALUES(counted_cash), difference = VALUES(difference), note = VALUES(note), created_at = NOW()')
        ->execute([$tid, (int)$user['id'], $in['day'], $t['income'], $t['expense'], $t['expected_cash'], $in['counted_cash'], $diff, $in['note']]);
    audit('day_closed', ['day' => $in['day'], 'difference' => $diff], $user);
    json_response(['success' => true, 'difference' => $diff, 'expected_cash' => $t['expected_cash'], 'counted_cash' => $in['counted_cash']], 201);
} catch (Throwable $e) {
    internal_error($e);
}
