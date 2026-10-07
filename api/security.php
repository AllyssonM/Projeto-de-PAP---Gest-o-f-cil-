<?php
/* =========================================================================
   API DE SEGURANÇA DA CONTA  (api/security.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  tudo o que está em "Área pessoal > Segurança e sessão":
     GET  ?action=overview        estado do 2.º passo, sessões ativas, avisos e últimos eventos
     POST action=change_password  muda a palavra-passe (pede a atual)
     POST action=2fa_begin        começa a ativar o 2.º passo (devolve o segredo e o endereço otpauth)
     POST action=2fa_enable       confirma com um código e ativa (devolve os códigos de recuperação UMA vez)
     POST action=2fa_disable      desativa (pede a palavra-passe e um código)
     POST action=session_revoke   termina uma sessão (outro dispositivo)
     POST action=sessions_revoke_all  termina todas (opcionalmente também esta); pede a palavra-passe
   REGRAS: sessão obrigatória; CSRF em tudo o que altera; as ações importantes
           pedem a palavra-passe atual (confirmação); tudo fica em audit_log.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();
$action = (string)($_GET['action'] ?? $data['action'] ?? 'overview');

try {
    /* ---------------------------- LER ---------------------------- */
    if ($method === 'GET') {
        $st = db()->prepare('SELECT totp_enabled, totp_backup FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        $enabled = (int)$u['totp_enabled'] === 1;
        $backupLeft = $enabled ? count(json_decode((string)$u['totp_backup'], true) ?: []) : 0;
        $sessions = sessions_list($uid);

        // avisos de segurança, em linguagem simples
        $warnings = [];
        if (!$enabled) $warnings[] = ['level' => 'warn', 'text' => 'A autenticação em dois passos está desligada. Ativa-a para proteger a conta.'];
        if ($enabled && $backupLeft <= 2) $warnings[] = ['level' => 'warn', 'text' => "Só tens $backupLeft código(s) de recuperação. Gera novos desativando e voltando a ativar o 2.º passo."];
        if (count($sessions) > 3) $warnings[] = ['level' => 'info', 'text' => 'Tens ' . count($sessions) . ' sessões abertas. Termina as que já não usas.'];
        $fails = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > (NOW() - INTERVAL 1 DAY)');
        $fails->execute([$user['email']]);
        if ((int)$fails->fetchColumn() > 0) $warnings[] = ['level' => 'warn', 'text' => 'Houve tentativas falhadas de entrar na tua conta nas últimas 24 horas.'];

        $ev = db()->prepare("SELECT action, created_at, ip FROM audit_log WHERE user_id = ? AND action IN ('login','logout','password_change','2fa_enabled','2fa_disabled','sessions_revoked','2fa_backup_code_used') ORDER BY id DESC LIMIT 10");
        $ev->execute([$uid]);
        json_response(['success' => true, 'two_factor' => ['enabled' => $enabled, 'backup_left' => $backupLeft], 'sessions' => $sessions, 'warnings' => $warnings, 'events' => $ev->fetchAll()]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();

    /* ---------------------------- ALTERAR PALAVRA-PASSE ---------------------------- */
    if ($action === 'change_password') {
        $revoked = change_password_for($user, (string)($data['current_password'] ?? ''), (string)($data['new_password'] ?? ''), !empty($data['logout_others']));
        json_response(['success' => true, 'others_revoked' => $revoked]);
    }

    /* ---------------------------- 2.º PASSO ---------------------------- */
    if ($action === '2fa_begin') {
        $st = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() === 1) json_response(['success' => false, 'error' => 'O 2.º passo já está ativo.'], 409);
        $secret = totp_generate_secret();
        $_SESSION['totp_pending'] = ['secret' => $secret, 'until' => time() + 600];     // só vale 10 minutos e só nesta sessão
        json_response(['success' => true, 'secret' => $secret, 'uri' => totp_uri($secret, $user['email'])]);
    }

    if ($action === '2fa_enable') {
        $pending = $_SESSION['totp_pending'] ?? null;
        if (!$pending || $pending['until'] < time()) json_response(['success' => false, 'error' => 'O pedido expirou. Começa outra vez.'], 400);
        if (!totp_verify($pending['secret'], (string)($data['code'] ?? ''))) json_response(['success' => false, 'error' => 'Código incorreto. Confirma na app e tenta outra vez.'], 400);
        [$plain, $hashes] = totp_backup_codes();
        db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_backup = ? WHERE id = ?')->execute([encrypt_secret($pending['secret']), json_encode($hashes), $uid]);
        unset($_SESSION['totp_pending']);
        audit('2fa_enabled', [], $user);
        json_response(['success' => true, 'backup_codes' => $plain]);                    // mostrados UMA vez; só ficam os hashes
    }

    if ($action === '2fa_disable') {
        confirm_password($user, (string)($data['password'] ?? ''));
        $check = second_factor_check($uid, (string)($data['code'] ?? ''));
        if ($check === null) json_response(['success' => false, 'error' => 'O 2.º passo não está ativo.'], 409);
        if (!$check) json_response(['success' => false, 'error' => 'Código incorreto.'], 400);
        db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_backup = NULL WHERE id = ?')->execute([$uid]);
        audit('2fa_disabled', [], $user);
        json_response(['success' => true]);
    }

    /* ---------------------------- SESSÕES ---------------------------- */
    if ($action === 'session_revoke') {
        $ok = sessions_revoke($uid, (int)($data['id'] ?? 0));
        if ($ok) audit('sessions_revoked', ['count' => 1], $user);
        json_response(['success' => $ok, 'error' => $ok ? null : 'Sessão não encontrada.'], $ok ? 200 : 404);
    }

    if ($action === 'sessions_revoke_all') {
        confirm_password($user, (string)($data['password'] ?? ''));
        $includeCurrent = !empty($data['include_current']);
        $count = sessions_revoke_all($uid, !$includeCurrent);
        audit('sessions_revoked', ['count' => $count, 'including_current' => $includeCurrent], $user);
        if ($includeCurrent) session_destroy();                          // "em todos os dispositivos": esta também fecha
        json_response(['success' => true, 'revoked' => $count, 'logged_out' => $includeCurrent]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $error) {
    internal_error($error);
}
