<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = require_login();
$tid = $user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // a lista também alimenta o campo "Cliente" do fluxo de caixa e das contas
        require_permission($user, 'clients', 'cashflow', 'accounts');
        $stmt = db()->prepare('SELECT id, name, email, phone, tax_number, category, status, notes, created_at FROM clients WHERE user_id = ? ORDER BY name');
        $stmt->execute([$tid]);
        $clients = $stmt->fetchAll();
        $tx = db()->prepare('SELECT id, type, description, amount, occurred_at FROM transactions WHERE user_id = ? AND client_id = ? ORDER BY occurred_at DESC LIMIT 10');
        $docs = db()->prepare('SELECT id, direction, title, amount, due_date, status FROM financial_documents WHERE user_id = ? AND client_id = ? ORDER BY due_date DESC LIMIT 10');
        foreach ($clients as &$client) {
            // o histórico financeiro só vai para quem tem acesso às finanças
            $client['transactions'] = [];
            $client['documents'] = [];
            if (can($user, 'cashflow')) {
                $tx->execute([$tid, $client['id']]);
                $client['transactions'] = $tx->fetchAll();
            }
            if (can($user, 'accounts')) {
                $docs->execute([$tid, $client['id']]);
                $client['documents'] = $docs->fetchAll();
            }
        }
        unset($client);
        json_response(['success' => true, 'items' => $clients]);
    }

    if ($method !== 'POST') {
        json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    }
    require_permission($user, 'clients');
    check_csrf();

    $data = request_json();
    $name = trim((string)($data['name'] ?? ''));
    if ($name === '') {
        json_response(['success' => false, 'error' => 'O nome do cliente é obrigatório.'], 400);
    }
    $email = trim((string)($data['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response(['success' => false, 'error' => 'O email do cliente não é válido.'], 400);
    }
    $values = [$name, $email ?: null, trim((string)($data['phone'] ?? '')) ?: null, trim((string)($data['tax_number'] ?? '')) ?: null, trim((string)($data['category'] ?? '')) ?: null, trim((string)($data['notes'] ?? '')) ?: null];
    if (!empty($data['id'])) {
        $stmt = db()->prepare('UPDATE clients SET name = ?, email = ?, phone = ?, tax_number = ?, category = ?, notes = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([...$values, (int)$data['id'], $tid]);
        json_response(['success' => true, 'id' => (int)$data['id'], 'updated' => true]);
    }
    $stmt = db()->prepare('INSERT INTO clients (user_id, name, email, phone, tax_number, category, notes) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$tid, ...$values]);
    json_response(['success' => true, 'id' => (int)db()->lastInsertId()], 201);
} catch (Throwable $error) {
    internal_error($error);
}
?>
