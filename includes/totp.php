<?php
/* =========================================================================
   AUTENTICAÇÃO EM DOIS PASSOS  (includes/totp.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  implementa o TOTP (RFC 6238), o mesmo sistema do Google
               Authenticator, Microsoft Authenticator, Authy, etc. Depois da
               palavra-passe, o utilizador escreve o código de 6 dígitos que a
               app mostra (muda de 30 em 30 segundos).
   QUEM USA:   api/security.php (ativar/desativar) e api/auth.php (login).
   ========================================================================= */
declare(strict_types=1);

const TOTP_STEP = 30;        // segundos de cada código
const TOTP_DIGITS = 6;

/** Segredo aleatório de 20 bytes em base32 (o formato que as apps leem). */
function totp_generate_secret(): string
{
    return base32_encode(random_bytes(20));
}

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $text): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $text))) as $c) {
        $bits .= str_pad(decbin((int)strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

/** O código válido para um instante (por omissão, agora). */
function totp_code(string $secretBase32, ?int $time = null): string
{
    $counter = intdiv($time ?? time(), TOTP_STEP);
    $hash = hash_hmac('sha1', pack('J', $counter), base32_decode($secretBase32), true);   // HMAC-SHA1 do contador (8 bytes)
    $offset = ord($hash[19]) & 0x0F;
    $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
    return str_pad((string)($value % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/** Confere o código escrito. Aceita o período anterior e o seguinte (±30 s), por causa de relógios ligeiramente desfasados. */
function totp_verify(string $secretBase32, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secretBase32, $now + $i * TOTP_STEP), $code)) return true;
    }
    return false;
}

/** Endereço otpauth:// que as apps de autenticação leem (por QR code ou colado à mão). */
function totp_uri(string $secretBase32, string $account, string $issuer = 'Lumina'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretBase32
        . '&issuer=' . rawurlencode($issuer) . '&digits=' . TOTP_DIGITS . '&period=' . TOTP_STEP;
}

/** 8 códigos de recuperação (para quem perder o telemóvel). Devolve [códigos em claro, hashes a guardar]. */
function totp_backup_codes(): array
{
    $plain = $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        $code = strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));   // ex.: 9F3A-12BC
        $plain[] = $code;
        $hashes[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return [$plain, $hashes];
}
