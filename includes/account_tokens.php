<?php
/* =========================================================================
   TOKENS DE CONTA  (includes/account_tokens.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  links de uso único enviados por email: recuperar a palavra-passe ('reset'),
               confirmar o email ('verify') e convidar funcionários ('invite').
   SEGURANÇA:  o token é aleatório (256 bits) e na base de dados fica só o seu resumo (SHA-256);
               quem lê a base de dados não consegue usar o link. Cada token expira e só funciona
               UMA vez (a marcação como usado é atómica). Pedir um token novo invalida os anteriores.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';          // db(), app_timezone(), client_ip()

const TOKEN_TTL_MINUTES = ['reset' => 60, 'verify' => 60 * 24 * 3, 'invite' => 60 * 24 * 3];

function token_now(): string { return (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'); }
function token_valid_format(string $t): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{43}$/', $t); }

/** Cria um token novo para o utilizador e devolve-o em claro (para ir no link do email). Os anteriores do mesmo tipo deixam de valer. */
function token_create(int $userId, string $purpose): string
{
    $ttl = TOKEN_TTL_MINUTES[$purpose] ?? 60;
    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $now = token_now();
    db()->prepare('UPDATE account_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL')->execute([$now, $userId, $purpose]);
    if (random_int(1, 10) === 1) db()->exec('DELETE FROM account_tokens WHERE expires_at < (NOW() - INTERVAL 7 DAY)');     // retenção: links expirados apagam-se ao fim de 7 dias
    db()->prepare('INSERT INTO account_tokens (user_id, purpose, token_hash, expires_at, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, $purpose, hash('sha256', $raw), (new DateTime("+$ttl minutes", app_timezone()))->format('Y-m-d H:i:s'), client_ip()]);
    return $raw;
}

/** Vê se o token é válido SEM o gastar (para mostrar o formulário). Devolve o id do utilizador ou null. */
function token_peek(string $raw, string $purpose): ?int
{
    if (!token_valid_format($raw)) return null;
    $st = db()->prepare('SELECT user_id FROM account_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ? LIMIT 1');
    $st->execute([hash('sha256', $raw), $purpose, token_now()]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

/** Gasta o token (uso único). Devolve o id do utilizador, ou null se for inválido, expirado ou já usado. */
function token_consume(string $raw, string $purpose): ?int
{
    $userId = token_peek($raw, $purpose);
    if ($userId === null) return null;
    $st = db()->prepare('UPDATE account_tokens SET used_at = ? WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?');
    $st->execute([token_now(), hash('sha256', $raw), $purpose, token_now()]);
    return $st->rowCount() === 1 ? $userId : null;           // se dois pedidos chegarem ao mesmo tempo, só um ganha
}

/** Confirma o email com o link recebido. Devolve o id do utilizador ou null se o link não valer. */
function verify_email_token(string $raw): ?int
{
    $userId = token_consume($raw, 'verify');
    if ($userId === null) return null;
    db()->prepare('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?')->execute([token_now(), $userId]);
    return $userId;
}

