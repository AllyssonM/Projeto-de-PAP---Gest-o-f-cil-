<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/account_tokens.php';
require_once __DIR__ . '/../includes/team.php';
require_once __DIR__ . '/../includes/mail_templates.php';

/**
 * Conclui o login (depois da palavra-passe e, se estiver ativo, do 2.º passo):
 * novo id de sessão, utilizador na sessão, token CSRF, registo da sessão e auditoria.
 */
function complete_login(array $row, string $how = 'password'): never
{
    $now = (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s');
    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, $row['id']]);
    unset($_SESSION['pending_2fa']);
    session_regenerate_id(true);
    $user = $_SESSION['user'] = session_user($row);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    session_register((int)$row['id']);
    audit('login', ['via' => $how], $user);
    team_on_login($user);                                  // funcionário: regista a entrada e avisa o líder
    json_response(['success' => true, 'user' => $user, 'csrf' => $_SESSION['csrf']]);
}

$action = $_GET['action'] ?? 'me';

try {
    if ($action === 'me') {
        $user = current_user() ? require_login() : null;
        json_response(['success' => true, 'user' => $user, 'csrf' => csrf_token()]);
    }

    if ($action === 'logout') {
        if (!empty($_SESSION['user']['id'])) {                    // regista que terminou e marca a sessão como terminada
            try { db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = ? AND user_id = ?')->execute([session_hash_current(), $_SESSION['user']['id']]); } catch (Throwable $e) {}
            audit('logout', []);
        }
        session_destroy();
        json_response(['success' => true]);
    }

    $data = request_json();
    $email = strtolower(trim((string)($data['email'] ?? '')));
    $password = (string)($data['password'] ?? '');

    if ($action === 'login') {
        if (login_throttled($email)) {
            json_response(['success' => false, 'error' => 'Demasiadas tentativas falhadas. Aguarda ' . LOGIN_WINDOW_MINUTES . ' minutos e tenta outra vez.'], 429);
        }
        $stmt = db()->prepare('SELECT ' . USER_COLUMNS . ' FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        // password_verify corre sempre (mesmo sem conta) para o tempo de resposta não revelar se o email existe
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringforsalt.invalidhashinvalidhashinvalidhashx';
        $valid = password_verify($password, $hash) && $row;
        if (!$valid) {
            login_failed($email);
            json_response(['success' => false, 'error' => 'Email ou palavra-passe incorretos.'], 401);
        }
        login_succeeded($email);
        if (($row['status'] ?? 'active') !== 'active') {
            json_response(['success' => false, 'error' => 'Esta conta está desativada. Fala com o responsável do negócio.'], 403);
        }
        // Autenticação em dois passos ativa? A palavra-passe ainda não chega: guarda-se só um "pedido pendente"
        // (sem sessão iniciada) e o login só termina com o código (action=login_2fa).
        $two = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
        $two->execute([$row['id']]);
        if ((int)$two->fetchColumn() === 1) {
            $_SESSION['pending_2fa'] = ['user_id' => (int)$row['id'], 'email' => $email, 'until' => time() + 300, 'attempts' => 0];
            json_response(['success' => true, 'need_2fa' => true]);
        }
        complete_login($row);
    }

    // Segundo passo do login: código de 6 dígitos da app de autenticação, ou um código de recuperação.
    if ($action === 'login_2fa') {
        $pending = $_SESSION['pending_2fa'] ?? null;
        if (!$pending || $pending['until'] < time()) {
            unset($_SESSION['pending_2fa']);
            json_response(['success' => false, 'error' => 'O pedido expirou. Volta a iniciar sessão.'], 401);
        }
        if (login_throttled($pending['email']) || $pending['attempts'] >= 5) {
            unset($_SESSION['pending_2fa']);
            json_response(['success' => false, 'error' => 'Demasiadas tentativas. Aguarda ' . LOGIN_WINDOW_MINUTES . ' minutos e tenta outra vez.'], 429);
        }
        $stmt = db()->prepare('SELECT ' . USER_COLUMNS . ', totp_secret, totp_backup FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$pending['user_id']]);
        $row = $stmt->fetch();
        $code = trim((string)($data['code'] ?? ''));
        $valid = false;
        if ($row) {
            $secret = decrypt_secret($row['totp_secret']);
            if ($secret && totp_verify($secret, $code)) {
                $valid = true;
            } else {                                              // código de recuperação (uso único)
                $hashes = json_decode((string)$row['totp_backup'], true) ?: [];
                foreach ($hashes as $i => $h) {
                    if (password_verify(strtoupper($code), $h)) {
                        unset($hashes[$i]);
                        db()->prepare('UPDATE users SET totp_backup = ? WHERE id = ?')->execute([json_encode(array_values($hashes)), $row['id']]);
                        audit('2fa_backup_code_used', [], session_user($row));
                        $valid = true;
                        break;
                    }
                }
            }
        }
        if (!$valid) {
            $_SESSION['pending_2fa']['attempts']++;
            login_failed($pending['email']);
            json_response(['success' => false, 'error' => 'Código incorreto. Tenta outra vez.'], 401);
        }
        login_succeeded($pending['email']);
        complete_login($row, '2fa');
    }

    if ($action === 'register') {
        $name = trim((string)($data['name'] ?? ''));
        if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < PASSWORD_MIN_LENGTH) {
            json_response(['success' => false, 'error' => 'Preencha nome, email válido e palavra-passe com pelo menos ' . PASSWORD_MIN_LENGTH . ' caracteres.'], 400);
        }
        $check = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetch()) {
            json_response(['success' => false, 'error' => 'Este email já está registado.'], 409);
        }
        $stmt = db()->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'owner')");
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $user = session_user(['id' => (int)db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'owner']);
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        session_register((int)$user['id']);          // aparece em "Sessões ativas"
        try { send_account_mail('verify', $user + ['email' => $email], app_base_url() . '/verificar-email.php?token=' . token_create((int)$user['id'], 'verify')); } catch (Throwable $e) { error_log('Lumina: email de confirmação: ' . $e->getMessage()); }
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        json_response(['success' => true, 'user' => $user, 'csrf' => $_SESSION['csrf']], 201);
    }


    /* ---------- recuperar a palavra-passe (e aceitar convites) ---------- */

    // 1) "Esqueci-me": envia um link por email. A resposta é SEMPRE a mesma, exista o email ou não.
    if ($action === 'forgot') {
        $email = Validator::make($data)->email('email')->orFail()['email'];
        if (login_throttled('reset:' . $email)) {
            json_response(['success' => false, 'error' => 'Demasiados pedidos. Tenta outra vez dentro de alguns minutos.'], 429);
        }
        login_failed('reset:' . $email);                                   // conta este pedido para o limite
        $stmt = db()->prepare('SELECT ' . SESSION_COLUMNS . ' FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row && ($row['status'] ?? 'active') === 'active') {
            $link = app_base_url() . '/redefinir-palavra-passe.php?token=' . token_create((int)$row['id'], 'reset');
            send_account_mail('reset', $row, $link);
            audit('password_reset_requested', [], session_user($row));
        }
        json_response(['success' => true, 'message' => 'Se este email existir no Lumina, enviámos as instruções. Verifica também o spam.']);
    }

    // 2) Escolher a nova palavra-passe com o link do email (purpose: 'reset' = recuperação, 'invite' = convite de funcionário).
    if ($action === 'reset_password') {
        $purpose = ($data['purpose'] ?? 'reset') === 'invite' ? 'invite' : 'reset';
        $password = (string)($data['password'] ?? '');
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            json_response(['success' => false, 'error' => 'A palavra-passe precisa de pelo menos ' . PASSWORD_MIN_LENGTH . ' caracteres.'], 400);
        }
        $userId = token_consume((string)($data['token'] ?? ''), $purpose);
        if ($userId === null) {
            json_response(['success' => false, 'error' => 'Este link expirou ou já foi usado. Pede um novo.'], 400);
        }
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), token_now(), $userId]);
        sessions_revoke_all($userId, false);                              // quem tinha a conta aberta noutro sítio é desligado
        $stmt = db()->prepare('SELECT ' . SESSION_COLUMNS . ' FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        audit($purpose === 'invite' ? 'invite_accepted' : 'password_reset_done', [], session_user($stmt->fetch()));
        json_response(['success' => true]);
    }

    // 3) Confirmar o email (também usado pela página verificar-email.php).
    if ($action === 'verify_email') {
        $userId = verify_email_token((string)($data['token'] ?? ''));
        if ($userId === null) json_response(['success' => false, 'error' => 'Este link expirou ou já foi usado.'], 400);
        json_response(['success' => true]);
    }

    // 4) Reenviar o email de confirmação (máximo 3 por hora).
    // Teste de envio: o dono manda um email de teste para o PRÓPRIO endereço e vê, sem enganos, se saiu ou não.
    if ($action === 'test_mail') {
        $user = require_login(); require_owner($user); check_csrf();
        $cfg = mail_config();
        $ok = send_mail((string)$user['email'], L('Teste de email do Lumina'), mail_layout(L('O envio de email funciona'), L('Se estás a ler esta mensagem, o Lumina consegue enviar emails a partir deste servidor.'), null, null, L('Pode apagar esta mensagem.')));
        $r = mail_last_result();
        audit('mail_test', ['status' => $r['status']], $user);
        json_response(['success' => true, 'sent' => $ok, 'delivery' => $r['status'], 'driver' => $cfg['driver'], 'to' => $user['email'], 'message' => mail_result_message($r)]);
    }

    if ($action === 'resend_verification') {
        $user = require_login();
        check_csrf();
        if (!empty($user['email_verified'])) json_response(['success' => true, 'already' => true]);
        $stmt = db()->prepare("SELECT COUNT(*) FROM account_tokens WHERE user_id = ? AND purpose = 'verify' AND created_at > (NOW() - INTERVAL 1 HOUR)");
        $stmt->execute([$user['id']]);
        if ((int)$stmt->fetchColumn() >= 3) json_response(['success' => false, 'error' => 'Já enviámos 3 emails na última hora. Verifica a caixa de entrada e o spam.'], 429);
        $sent = send_account_mail('verify', $user, app_base_url() . '/verificar-email.php?token=' . token_create((int)$user['id'], 'verify'));
        json_response(['success' => true, 'sent' => $sent, 'delivery' => mail_last_result()['status']] + ($sent ? [] : ['mail_error' => mail_result_message()]));
    }

    if ($action === 'change_password') {                       // usado no 1.º acesso com palavra-passe provisória
        $user = require_login();
        check_csrf();
        $revoked = change_password_for($user, (string)($data['current_password'] ?? ''), (string)($data['new_password'] ?? ''));
        json_response(['success' => true, 'others_revoked' => $revoked]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 404);
} catch (Throwable $error) {
    internal_error($error);
}
?>
