<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/account_tokens.php';
require_once __DIR__ . '/../includes/mail_templates.php';
$user = require_login();
require_owner($user);
$ownerId = $user['id'];
$method = $_SERVER['REQUEST_METHOD'];

/** Envia ao funcionário o email de convite (link para ele escolher a própria palavra-passe; vale 3 dias). */
function send_invite(array $employee): bool
{
    return send_account_mail('invite', $employee, app_base_url() . '/redefinir-palavra-passe.php?tipo=convite&token=' . token_create((int)$employee['id'], 'invite'));
}

/** Palavra-passe provisória fácil de ditar (sem 0/O, 1/l/I). */
function temp_password(): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/** Garante que o id é um funcionário deste dono. */
function find_employee(int $ownerId, int $id): array
{
    $stmt = db()->prepare("SELECT id, name, email FROM users WHERE id = ? AND owner_id = ? AND role = 'employee'");
    $stmt->execute([$id, $ownerId]);
    $row = $stmt->fetch();
    if (!$row) json_response(['success' => false, 'error' => 'Funcionário não encontrado.'], 404);
    return $row;
}

try {
    if ($method === 'GET') {
        $stmt = db()->prepare("SELECT id, name, email, phone, job_title, department, hired_at, status, permissions, must_change_password, last_login_at, created_at
            FROM users WHERE owner_id = ? AND role = 'employee' ORDER BY status, name");
        $stmt->execute([$ownerId]);
        $items = array_map(function (array $row) {
            $row['permissions'] = clean_permissions($row['permissions']);
            $row['must_change_password'] = (bool)$row['must_change_password'];
            return $row;
        }, $stmt->fetchAll());
        json_response(['success' => true, 'items' => $items, 'modules' => TEAM_MODULES]);
    }

    check_csrf();
    $data = request_json();

    if ($method === 'DELETE') {
        $employee = find_employee($ownerId, (int)($_GET['id'] ?? 0));
        db()->prepare('DELETE FROM users WHERE id = ?')->execute([$employee['id']]);
        json_response(['success' => true]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    $action = $data['action'] ?? 'save';

    if ($action === 'status') {
        $employee = find_employee($ownerId, (int)($data['id'] ?? 0));
        $status = ($data['status'] ?? '') === 'inactive' ? 'inactive' : 'active';
        db()->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$status, $employee['id']]);
        json_response(['success' => true, 'status' => $status]);
    }

    if ($action === 'invite') {                                     // (re)enviar o convite por email
        $employee = find_employee($ownerId, (int)($data['id'] ?? 0));
        audit('team_invite_sent', ['employee_id' => (int)$employee['id']], $user);
        $sent = send_invite($employee);
        json_response(['success' => true, 'sent' => $sent, 'delivery' => mail_last_result()['status']] + ($sent ? [] : ['mail_error' => mail_result_message()]));
    }

    if ($action === 'reset_password') {
        $employee = find_employee($ownerId, (int)($data['id'] ?? 0));
        $password = temp_password();
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $employee['id']]);
        json_response(['success' => true, 'temp_password' => $password]);
    }

    if ($action !== 'save') json_response(['success' => false, 'error' => 'Ação desconhecida.'], 404);

    $id = (int)($data['id'] ?? 0);
    $name = trim((string)($data['name'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $hired = trim((string)($data['hired_at'] ?? ''));
    if (mb_strlen($name) < 2) json_response(['success' => false, 'error' => 'Indica o nome do funcionário.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['success' => false, 'error' => 'O email não é válido.'], 400);
    if ($hired !== '' && !DateTime::createFromFormat('!Y-m-d', $hired)) json_response(['success' => false, 'error' => 'A data de admissão não é válida.'], 400);

    $check = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $check->execute([$email, $id]);
    if ($check->fetch()) json_response(['success' => false, 'error' => 'Já existe uma conta com este email.'], 409);

    $values = [
        $name, $email,
        trim((string)($data['phone'] ?? '')) ?: null,
        trim((string)($data['job_title'] ?? '')) ?: null,
        trim((string)($data['department'] ?? '')) ?: null,
        $hired ?: null,
        json_encode(clean_permissions($data['permissions'] ?? [])),
    ];

    if ($id > 0) {
        find_employee($ownerId, $id);
        db()->prepare('UPDATE users SET name = ?, email = ?, phone = ?, job_title = ?, department = ?, hired_at = ?, permissions = ? WHERE id = ?')
            ->execute([...$values, $id]);
        json_response(['success' => true, 'id' => $id]);
    }

    $password = (string)($data['password'] ?? '');
    if ($password === '') $password = temp_password();
    if (strlen($password) < PASSWORD_MIN_LENGTH) json_response(['success' => false, 'error' => 'A palavra-passe provisória precisa de pelo menos ' . PASSWORD_MIN_LENGTH . ' caracteres.'], 400);
    db()->prepare("INSERT INTO users (name, email, phone, job_title, department, hired_at, permissions, owner_id, role, must_change_password, password_hash)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'employee', 1, ?)")
        ->execute([...$values, $ownerId, password_hash($password, PASSWORD_DEFAULT)]);
    $newId = (int)db()->lastInsertId();
    // Com "enviar convite", o funcionário escolhe a própria palavra-passe e a provisória nem chega a ser mostrada.
    $wanted = !empty($data['send_invite']);
    $invited = $wanted && send_invite(['id' => $newId, 'name' => $name, 'email' => $email]);
    // Se o convite não saiu de verdade, devolve a palavra-passe provisória (o dono entrega-a em mão) e o motivo.
    json_response(['success' => true, 'id' => $newId, 'invite_sent' => $invited] + ($invited ? [] : ['temp_password' => $password]) + (($wanted && !$invited) ? ['mail_error' => mail_result_message()] : []), 201);
} catch (Throwable $error) {
    internal_error($error);
}
?>
