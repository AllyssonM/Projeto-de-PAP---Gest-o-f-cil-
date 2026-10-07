<?php
/* =========================================================================
   PESQUISA GLOBAL  (api/search.php)
   -------------------------------------------------------------------------
   GET ?q=texto  procura em clientes, produtos, movimentos, contas a pagar/receber, notas e agenda.
   REGRAS:  cada fonte só é pesquisada se a pessoa tiver a permissão dessa área (um funcionário só com o
            calendário só encontra eventos); só dados do NEGÓCIO da pessoa; notas privadas de outros nunca
            aparecem; máximo 5 resultados por fonte; os carateres especiais do LIKE (% _) são escapados.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2 || mb_strlen($q) > 80) json_response(['success' => true, 'results' => []]);

$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];
$like = '%' . addcslashes($q, '%_\\') . '%';
$out = [];

/** Executa uma pesquisa e junta os resultados já no formato final. */
function add_results(array &$out, string $type, string $section, string $sql, array $params, callable $map): void
{
    $st = db()->prepare($sql . ' LIMIT 5');
    $st->execute($params);
    foreach ($st->fetchAll() as $r) $out[] = ['type' => $type, 'section' => $section] + $map($r);
}

try {
    if (can($user, 'clients')) {
        add_results($out, 'clients', 'clients', "SELECT id, name, email, phone FROM clients WHERE user_id = ? AND (name LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\' OR phone LIKE ? ESCAPE '\\\\') ORDER BY name",
            [$tid, $like, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['name'], 'sub' => trim(($r['email'] ?: '') . ' ' . ($r['phone'] ?: ''))]);
    }
    if (can($user, 'stock')) {
        add_results($out, 'products', 'stock', "SELECT id, name, sku, category FROM products WHERE user_id = ? AND status <> 'archived' AND (name LIKE ? ESCAPE '\\\\' OR sku LIKE ? ESCAPE '\\\\' OR category LIKE ? ESCAPE '\\\\') ORDER BY name",
            [$tid, $like, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['name'], 'sub' => trim(($r['category'] ?: '') . ' ' . ($r['sku'] ?: ''))]);
    }
    if (can($user, 'cashflow')) {
        add_results($out, 'transactions', 'cashflow', "SELECT id, description, category, amount, type, occurred_at FROM transactions WHERE user_id = ? AND (description LIKE ? ESCAPE '\\\\' OR category LIKE ? ESCAPE '\\\\') ORDER BY occurred_at DESC",
            [$tid, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['description'], 'sub' => substr($r['occurred_at'], 0, 10) . ' · ' . ($r['type'] === 'income' ? '+' : '-') . number_format((float)$r['amount'], 2, ',', ' ') . ' €']);
    }
    if (can($user, 'accounts')) {
        add_results($out, 'bills', 'accounts', "SELECT id, title, counterparty, amount, due_date, direction FROM financial_documents WHERE user_id = ? AND (title LIKE ? ESCAPE '\\\\' OR counterparty LIKE ? ESCAPE '\\\\') ORDER BY due_date DESC",
            [$tid, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'sub' => ($r['direction'] === 'payable' ? 'A pagar ' : 'A receber ') . $r['due_date'] . ' · ' . number_format((float)$r['amount'], 2, ',', ' ') . ' €']);
    }
    // notas: as minhas e as partilhadas do negócio (as privadas dos outros nunca)
    add_results($out, 'notes', 'notes', "SELECT id, title FROM notes WHERE user_id = ? AND (author_id = ? OR shared = 1) AND (title LIKE ? ESCAPE '\\\\' OR detail LIKE ? ESCAPE '\\\\' OR tags LIKE ? ESCAPE '\\\\') ORDER BY updated_at DESC",
        [$tid, $uid, $like, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'] ?: 'Nota sem título', 'sub' => '']);
    if (can($user, 'calendar')) {
        add_results($out, 'events', 'calendar', "SELECT id, title, event_date, start_time FROM calendar_events WHERE user_id = ? AND (title LIKE ? ESCAPE '\\\\' OR location LIKE ? ESCAPE '\\\\') ORDER BY event_date DESC",
            [$uid, $like, $like], fn($r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'sub' => $r['event_date'] . ' ' . substr($r['start_time'], 0, 5)]);
    }
    json_response(['success' => true, 'results' => $out]);
} catch (Throwable $e) {
    internal_error($e);
}
