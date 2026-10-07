<?php
/* =========================================================================
   ORÇAMENTOS MENSAIS POR CATEGORIA  (api/budgets.php)
   -------------------------------------------------------------------------
   "Este mês posso gastar 300 € em combustível." Passa o Lumina de simples registo a controlo de despesas.
   GET ?month=AAAA-MM    os orçamentos com o gasto desse mês (por omissão o mês atual), a percentagem e o estado:
                         ok (< 80 %), warn (80 a 100 %), over (acima do limite). Só conta despesas REALIZADAS.
   POST                  {category, monthly_limit}: cria ou atualiza o limite dessa categoria.
   DELETE ?id=           apaga o orçamento (os movimentos ficam).
   Quem o vê: quem tem a permissão do Fluxo de caixa. Todos os cálculos são feitos aqui, no servidor.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
$user = require_login();
require_permission($user, 'cashflow');
$tid = (int)$user['tenant_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $month = (string)($_GET['month'] ?? (new DateTimeImmutable('now', app_timezone()))->format('Y-m'));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) json_response(['success' => false, 'error' => 'Mês inválido (use AAAA-MM).'], 400);
        $from = $month . '-01 00:00:00';
        $st = db()->prepare("SELECT b.id, b.category, b.monthly_limit,
                COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.user_id = b.user_id AND t.type = 'expense' AND t.status = 'paid'
                          AND t.category = b.category AND t.occurred_at >= ? AND t.occurred_at < DATE_ADD(?, INTERVAL 1 MONTH)), 0) AS spent
            FROM budgets b WHERE b.user_id = ? ORDER BY b.category LIMIT 200");
        $st->execute([$from, $from, $tid]);
        $items = array_map(function ($r) {
            $limit = (float)$r['monthly_limit']; $spent = round((float)$r['spent'], 2); $pct = $limit > 0 ? round($spent / $limit * 100, 1) : 0.0;
            // O ESTADO sai dos valores exatos, em cêntimos (não da percentagem arredondada: 79,99 % não pode passar a 80 %, nem 100,01 € de 100 € a «dentro do limite»).
            $spentC = (int)round($spent * 100); $limitC = (int)round($limit * 100);
            return ['id' => (int)$r['id'], 'category' => $r['category'], 'monthly_limit' => $limit, 'spent' => $spent, 'remaining' => round($limit - $spent, 2),
                    'percent' => $pct, 'status' => $spentC > $limitC ? 'over' : ($spentC * 100 >= $limitC * 80 ? 'warn' : 'ok')];
        }, $st->fetchAll());
        // categorias de despesa recentes que ainda não têm orçamento (sugestões para o formulário)
        $sg = db()->prepare("SELECT category, SUM(amount) total FROM transactions WHERE user_id = ? AND type = 'expense' AND status = 'paid' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND category NOT IN (SELECT category FROM budgets WHERE user_id = ?) GROUP BY category ORDER BY total DESC LIMIT 8");
        $sg->execute([$tid, $tid]);
        json_response(['success' => true, 'month' => $month, 'items' => $items, 'suggestions' => array_column($sg->fetchAll(), 'category')]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        check_csrf();
        $st = db()->prepare('DELETE FROM budgets WHERE id = ? AND user_id = ?');
        $st->execute([(int)($_GET['id'] ?? 0), $tid]);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $in = Validator::make(request_json())->text('category', 'Categoria', 80)->money('monthly_limit', 'Limite mensal')->orFail();
    $st = db()->prepare('INSERT INTO budgets (user_id, category, monthly_limit) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE monthly_limit = VALUES(monthly_limit)');
    $st->execute([$tid, $in['category'], $in['monthly_limit']]);
    json_response(['success' => true], 201);
} catch (Throwable $e) {
    internal_error($e);
}
