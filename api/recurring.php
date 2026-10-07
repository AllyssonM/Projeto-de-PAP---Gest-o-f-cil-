<?php
/* =========================================================================
   CONTAS RECORRENTES  (api/recurring.php)
   -------------------------------------------------------------------------
   GET                  lista as recorrentes do negócio (e gera as contas em falta).
   POST action=save     cria (sem id) ou altera (com id) uma recorrente.
   POST action=toggle   pausa ou retoma (active 0/1).
   DELETE ?id=          elimina (só o dono). As contas já geradas ficam.
   Quem a vê: quem tem a permissão "Contas". As regras e as datas estão em includes/recurring.php.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/recurring.php';
$user = require_login();
require_permission($user, 'accounts');
$tid = (int)$user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        recurring_materialize($tid);
        $st = db()->prepare('SELECT id, direction, title, counterparty, amount, frequency, start_date, end_date, next_due, active FROM recurring_items WHERE user_id = ? ORDER BY active DESC, next_due ASC LIMIT 500');
        $st->execute([$tid]);
        json_response(['success' => true, 'items' => $st->fetchAll()]);
    }
    if ($method === 'DELETE') {
        require_owner($user);
        check_csrf();
        $st = db()->prepare('DELETE FROM recurring_items WHERE id = ? AND user_id = ?');
        $st->execute([(int)($_GET['id'] ?? 0), $tid]);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $data = request_json();
    $action = (string)($data['action'] ?? 'save');

    if ($action === 'toggle') {
        $st = db()->prepare('UPDATE recurring_items SET active = ? WHERE id = ? AND user_id = ?');
        $st->execute([empty($data['active']) ? 0 : 1, (int)($data['id'] ?? 0), $tid]);
        json_response(['success' => true, 'updated' => $st->rowCount()]);
    }

    $in = Validator::make($data)->id('id')->enum('direction', 'Tipo', ['payable', 'receivable'])->text('title', 'Título', 160)->text('counterparty', 'Contraparte', 160, false, '')
        ->money('amount', 'Valor')->enum('frequency', 'Frequência', RECURRING_FREQUENCIES, 'monthly')->date('start_date', 'Data de início')->date('end_date', 'Data de fim', false)->orFail();
    if ($in['end_date'] !== null && $in['end_date'] < $in['start_date']) json_response(['success' => false, 'error' => 'A data de fim é anterior à de início.'], 400);

    if ($in['id']) {                                                    // alterar: a próxima data só muda se a de início mudou
        $own = db()->prepare('SELECT start_date FROM recurring_items WHERE id = ? AND user_id = ?');
        $own->execute([$in['id'], $tid]);
        $old = $own->fetchColumn();
        if ($old === false) json_response(['success' => false, 'error' => 'Conta recorrente não encontrada.'], 404);
        $st = db()->prepare('UPDATE recurring_items SET direction = ?, title = ?, counterparty = ?, amount = ?, frequency = ?, start_date = ?, end_date = ?' . ($old !== $in['start_date'] ? ', next_due = ?' : '') . ' WHERE id = ? AND user_id = ?');
        $params = [$in['direction'], $in['title'], $in['counterparty'], $in['amount'], $in['frequency'], $in['start_date'], $in['end_date']];
        if ($old !== $in['start_date']) $params[] = $in['start_date'];
        $st->execute([...$params, $in['id'], $tid]);
        json_response(['success' => true, 'id' => $in['id']]);
    }
    $st = db()->prepare('INSERT INTO recurring_items (user_id, direction, title, counterparty, amount, frequency, start_date, end_date, next_due) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$tid, $in['direction'], $in['title'], $in['counterparty'], $in['amount'], $in['frequency'], $in['start_date'], $in['end_date'], $in['start_date']]);
    $newId = (int)db()->lastInsertId();                    // ANTES de gerar as contas: gerar insere outras linhas e mudaria este valor
    $created = recurring_materialize($tid);
    json_response(['success' => true, 'id' => $newId, 'generated' => $created], 201);
} catch (Throwable $e) {
    internal_error($e);
}
