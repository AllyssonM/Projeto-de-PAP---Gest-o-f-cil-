<?php
/* =========================================================================
   SESSÕES ATIVAS E AUDITORIA  (includes/sessions.php)
   -------------------------------------------------------------------------
   O QUE FAZ:
     - Regista cada sessão iniciada (dispositivo, IP, hora) na tabela user_sessions,
       para o utilizador ver "onde tem a conta aberta" e poder terminar sessões.
     - Verifica, em cada pedido, se a sessão não foi terminada noutro dispositivo.
     - Guarda um registo de auditoria (audit_log) das ações sensíveis.
   PRIVACIDADE: nunca se guarda o id de sessão, só o seu SHA-256 (não dá para o
   reutilizar). O IP e o dispositivo só são mostrados ao próprio utilizador.
   QUEM USA: includes/auth.php (verificação), api/auth.php (login/logout),
             api/security.php (listar e terminar sessões).
   ========================================================================= */
declare(strict_types=1);

const SESSION_LIFETIME_DAYS = 14;        // validade registada de uma sessão
const SESSION_RECORD_KEEP_DAYS = 30;     // depois de uma sessão terminar (expirou ou foi revogada), o registo guarda-se mais 30 dias e é apagado
const SESSION_TOUCH_SECONDS = 60;        // "última atividade" atualiza no máximo 1 vez por minuto

function session_hash_current(): string
{
    return hash('sha256', session_id());
}

/** Regista um registo de auditoria. Nunca guardar aqui palavras-passe, números de cartão ou códigos. */
function audit(string $action, array $detail = [], ?array $user = null): void
{
    try {
        $user ??= $_SESSION['user'] ?? null;
        db()->prepare('INSERT INTO audit_log (tenant_id, user_id, action, detail, ip) VALUES (?,?,?,?,?)')
            ->execute([$user['tenant_id'] ?? null, $user['id'] ?? null, mb_substr($action, 0, 60), $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, client_ip()]);
    } catch (Throwable $e) { /* a auditoria nunca deve impedir a ação */ }
}

/** Texto curto do dispositivo, ex.: "Chrome em Windows". */
function device_label(?string $ua): string
{
    $ua = (string)$ua;
    $browser = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome',
        str_contains($ua, 'Safari/') => 'Safari', default => 'Navegador',
    };
    $os = match (true) {
        str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Android') => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS', str_contains($ua, 'Mac OS') => 'macOS',
        str_contains($ua, 'Linux') => 'Linux', default => 'dispositivo desconhecido',
    };
    return "$browser em $os";
}

/** Regista a sessão atual (chamar logo depois de session_regenerate_id no login/registo). */
function session_register(int $userId): void
{
    try {
        $hash = session_hash_current();
        db()->prepare('INSERT INTO user_sessions (user_id, session_token, session_hash, expires_at, ip, user_agent, last_seen_at)
                       VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . SESSION_LIFETIME_DAYS . ' DAY), ?, ?, NOW())')
            ->execute([$userId, $hash, $hash, client_ip(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
        $_SESSION['seen_at'] = time();
        if (random_int(1, 20) === 1) {                                  // limpeza ocasional (política de retenção)
            db()->exec('DELETE FROM user_sessions WHERE COALESCE(revoked_at, expires_at) < (NOW() - INTERVAL ' . SESSION_RECORD_KEEP_DAYS . ' DAY)');
        }
    } catch (Throwable $e) { /* sem tabela: continua sem registo de sessões */ }
}

/**
 * A sessão atual ainda é válida? False se foi terminada noutro dispositivo.
 * Sessões antigas (criadas antes desta funcionalidade) são registadas na hora.
 */
function session_is_valid(int $userId): bool
{
    try {
        $hash = session_hash_current();
        $st = db()->prepare('SELECT id, revoked_at FROM user_sessions WHERE session_hash = ? AND user_id = ? LIMIT 1');
        $st->execute([$hash, $userId]);
        $row = $st->fetch();
        if (!$row) { session_register($userId); return true; }
        if ($row['revoked_at'] !== null) return false;
        if (time() - (int)($_SESSION['seen_at'] ?? 0) >= SESSION_TOUCH_SECONDS) {
            db()->prepare('UPDATE user_sessions SET last_seen_at = NOW(), ip = ? WHERE id = ?')->execute([client_ip(), $row['id']]);
            $_SESSION['seen_at'] = time();
        }
        return true;
    } catch (Throwable $e) { return true; }
}

/** Sessões ativas do utilizador (não terminadas e dentro da validade). */
function sessions_list(int $userId): array
{
    $st = db()->prepare('SELECT id, session_hash, ip, user_agent, created_at, last_seen_at FROM user_sessions
                         WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY last_seen_at DESC LIMIT 50');
    $st->execute([$userId]);
    $current = session_hash_current();
    return array_map(fn(array $r) => [
        'id' => (int)$r['id'], 'device' => device_label($r['user_agent']), 'ip' => $r['ip'],
        'created_at' => $r['created_at'], 'last_seen_at' => $r['last_seen_at'], 'current' => hash_equals($current, (string)$r['session_hash']),
    ], $st->fetchAll());
}

/** Termina uma sessão do utilizador pelo id do registo. */
function sessions_revoke(int $userId, int $id): bool
{
    $st = db()->prepare('UPDATE user_sessions SET revoked_at = NOW() WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
    $st->execute([$id, $userId]);
    return $st->rowCount() > 0;
}

/** Termina as sessões do utilizador. $keepCurrent = true deixa a atual aberta. Devolve quantas terminou. */
function sessions_revoke_all(int $userId, bool $keepCurrent): int
{
    $sql = 'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL' . ($keepCurrent ? ' AND session_hash <> ?' : '');
    $st = db()->prepare($sql);
    $st->execute($keepCurrent ? [$userId, session_hash_current()] : [$userId]);
    return $st->rowCount();
}
