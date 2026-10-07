<?php
/* =========================================================================
   ENVIO DE EMAIL  (includes/mailer.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  send_mail($para, $assunto, $html, $texto) envia um email com a configuração de
               config/mail.php (drivers: log, smtp, mail). Devolve true/false e NUNCA lança erros
               para o utilizador: se o email falhar, regista-se no log e a ação principal continua.
   SEGURANÇA:  cabeçalhos limpos de quebras de linha (evita "header injection"); o SMTP usa
               STARTTLS ou SSL e verifica o certificado do servidor.
   ========================================================================= */
declare(strict_types=1);

function mail_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = __DIR__ . '/../config/mail.php';
        $cfg = array_replace_recursive(['driver' => 'log', 'from_email' => 'nao-responder@lumina.local', 'from_name' => 'Lumina', 'smtp' => ['host' => '', 'port' => 587, 'security' => 'tls', 'username' => '', 'password' => '']], is_file($file) ? (array)require $file : []);
        // Variáveis de ambiente têm prioridade (permitem configurar o servidor sem editar ficheiros nem guardar a palavra-passe no código).
        $env = fn(string $k) => ($v = getenv($k)) === false || $v === '' ? null : $v;
        foreach (['LUMINA_MAIL_DRIVER' => ['driver'], 'LUMINA_MAIL_FROM' => ['from_email'], 'LUMINA_MAIL_FROM_NAME' => ['from_name'],
                  'LUMINA_SMTP_HOST' => ['smtp', 'host'], 'LUMINA_SMTP_PORT' => ['smtp', 'port'], 'LUMINA_SMTP_SECURITY' => ['smtp', 'security'],
                  'LUMINA_SMTP_USER' => ['smtp', 'username'], 'LUMINA_SMTP_PASS' => ['smtp', 'password']] as $name => $path) {
            if (($v = $env($name)) !== null) { if (count($path) === 1) { $cfg[$path[0]] = $v; } else { $cfg[$path[0]][$path[1]] = $v; } }
        }
    }
    return $cfg;
}

/** Endereço do remetente. Muitos servidores SMTP (Gmail, Outlook) recusam um remetente diferente do utilizador autenticado. */
function mail_from_address(array $cfg): string
{
    $from = (string)$cfg['from_email']; $user = (string)($cfg['smtp']['username'] ?? '');
    if ($cfg['driver'] === 'smtp' && filter_var($user, FILTER_VALIDATE_EMAIL) && (str_ends_with($from, '.local') || $from === '')) { return $user; }
    return $from;
}

/**
 * Resultado do ÚLTIMO envio neste pedido: ['status' => 'sent'|'logged'|'failed', 'driver' => ..., 'error' => ?string].
 *   sent   = o servidor de email aceitou a mensagem para entrega (não garante que já está na caixa do destinatário);
 *   logged = driver "log": só foi gravada em storage/mail/ (nada saiu do servidor);
 *   failed = erro (o motivo vai em 'error' e fica em storage/logs/mail-errors.log).
 */
function mail_last_result(?array $set = null): array
{
    static $last = ['status' => 'failed', 'driver' => '', 'error' => 'Nenhum email foi enviado.'];
    if ($set !== null) { $last = $set; }
    return $last;
}

/** Mensagem curta e honesta, em português, para mostrar à pessoa sobre o último envio. */
function mail_result_message(?array $r = null): string
{
    $r ??= mail_last_result();
    return match ($r['status']) {
        'sent'   => 'Email enviado.',
        'logged' => 'O email NÃO foi enviado: o servidor de email ainda não está configurado (modo de teste). Configura o SMTP em config/mail.php.',
        default  => 'Não foi possível enviar o email' . (($r['error'] ?? '') !== '' ? ': ' . $r['error'] : '.'),
    };
}

/** Regista um erro de envio em storage/logs/mail-errors.log (sem palavras-passe; o destinatário aparece mascarado). */
function mail_log_error(string $to, string $driver, string $error): void
{
    error_log('Lumina mail [' . $driver . ']: ' . $error);
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) { return; }
    $masked = preg_replace('/^(.).*(@.*)$/', '$1***$2', $to);
    @file_put_contents($dir . '/mail-errors.log', '[' . date('Y-m-d H:i:s') . "] driver=$driver para=$masked erro=" . preg_replace('/\s+/', ' ', $error) . "\n", FILE_APPEND | LOCK_EX);
}

/** Remove quebras de linha e caracteres de controlo de um valor de cabeçalho. */
function mail_header_safe(string $v): string { return trim(preg_replace('/[\r\n\x00-\x1f]+/', ' ', $v)); }

/** Constrói a mensagem completa (cabeçalhos + corpo em texto e HTML). */
function mail_build(string $to, string $subject, string $html, string $text, string &$boundary = ''): string
{
    $cfg = mail_config();
    $boundary = 'lumina_' . bin2hex(random_bytes(8));
    $from = '=?UTF-8?B?' . base64_encode(mail_header_safe($cfg['from_name'])) . '?= <' . mail_header_safe($cfg['from_email']) . '>';
    $head = [
        'From: ' . $from, 'To: ' . mail_header_safe($to), 'Subject: =?UTF-8?B?' . base64_encode(mail_header_safe($subject)) . '?=',
        'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(10)) . '@lumina>', 'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"', 'X-Auto-Response-Suppress: All',
    ];
    $part = fn(string $type, string $body) => "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($body));
    return implode("\r\n", $head) . "\r\n\r\n" . $part('text/plain', $text) . $part('text/html', $html) . "--$boundary--\r\n";
}

/** Envia um email. Devolve true SÓ quando o servidor de email aceitou a mensagem (status "sent"); o motivo de qualquer outro resultado está em mail_last_result(). */
function send_mail(string $to, string $subject, string $html, ?string $text = null): bool
{
    $cfg = mail_config(); $driver = (string)$cfg['driver'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_last_result(['status' => 'failed', 'driver' => $driver, 'error' => 'O endereço de email do destinatário não é válido.']);
        return false;
    }
    $text ??= trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/li)>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
    try {
        $message = mail_build($to, $subject, $html, $text);
        switch ($driver) {
            case 'smtp': $ok = smtp_send($cfg, $to, $message); $status = 'sent'; break;
            case 'mail': $ok = mail_function_send($to, $subject, $html, $text); $status = 'sent'; break;
            default:     $ok = mail_log_send($to, $message); $status = 'logged';
        }
        if (!$ok) { throw new RuntimeException($driver === 'mail' ? 'A função mail() do PHP recusou o envio (servidor de email não configurado no PHP).' : 'Não foi possível gravar o email em storage/mail/.'); }
        mail_last_result(['status' => $status, 'driver' => $driver, 'error' => null]);
        return $status === 'sent';
    } catch (Throwable $e) {
        mail_log_error($to, $driver, $e->getMessage());
        mail_last_result(['status' => 'failed', 'driver' => $driver, 'error' => $e->getMessage()]);
        return false;
    }
}

/** Driver "log": grava o email em storage/mail/. */
function mail_log_send(string $to, string $message): bool
{
    $dir = __DIR__ . '/../storage/mail';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return false;
    $file = $dir . '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
    return file_put_contents($file, $message, LOCK_EX) !== false;
}

/** Driver "mail": função mail() do PHP. */
function mail_function_send(string $to, string $subject, string $html, string $text): bool
{
    $cfg = mail_config(); $b = '';
    $full = mail_build($to, $subject, $html, $text, $b);
    [$headers, $body] = explode("\r\n\r\n", $full, 2);
    $headers = implode("\r\n", array_filter(explode("\r\n", $headers), fn($l) => !preg_match('/^(To|Subject):/i', $l)));
    return mail($to, '=?UTF-8?B?' . base64_encode(mail_header_safe($subject)) . '?=', $body, $headers);
}

/** Driver "smtp": cliente SMTP mínimo (EHLO, STARTTLS/SSL, AUTH LOGIN, MAIL FROM, RCPT TO, DATA). */
function smtp_send(array $cfg, string $to, string $message): bool
{
    $s = $cfg['smtp'];
    if ($s['host'] === '') throw new RuntimeException('SMTP sem servidor configurado.');
    $secure = $s['security'] === 'ssl';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $s['security'] !== 'none', 'verify_peer_name' => $s['security'] !== 'none']]);
    $fp = @stream_socket_client(($secure ? 'ssl://' : 'tcp://') . $s['host'] . ':' . (int)$s['port'], $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("SMTP: sem ligação ($errstr)");
    stream_set_timeout($fp, 10);
    $read = function () use ($fp): array {                       // lê uma resposta (pode ter várias linhas "250-...")
        $code = 0; $text = '';
        while (($line = fgets($fp, 1024)) !== false) { $text .= $line; $code = (int)substr($line, 0, 3); if (($line[3] ?? ' ') === ' ') break; }
        return [$code, $text];
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        if ($c !== '') fwrite($fp, $c . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $ok, true)) throw new RuntimeException("SMTP: resposta inesperada $code para '" . (stripos($c, 'AUTH') === 0 || $c === '' ? 'passo' : substr($c, 0, 20)) . "' (" . trim(preg_replace('/\s+/', ' ', substr($text, 0, 160))) . ')');
        return $text;
    };
    try {
        $cmd('', [220]);
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        $ehlo = $cmd("EHLO $host", [250]);
        if ($s['security'] === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP: falhou o TLS (certificado inválido ou porta/segurança erradas; STARTTLS usa a porta 587).');
            $ehlo = $cmd("EHLO $host", [250]);
        }
        if ($s['username'] !== '') {
            try {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') === false && stripos($ehlo, 'PLAIN') !== false) { throw new RuntimeException('plain'); }
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($s['username']), [334]);
                $cmd(base64_encode($s['password']), [235]);
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'plain' || str_contains($e->getMessage(), '504') || str_contains($e->getMessage(), '502')) {
                    $cmd('AUTH PLAIN ' . base64_encode("\0" . $s['username'] . "\0" . $s['password']), [235]);
                } else { throw new RuntimeException('SMTP: autenticação recusada — verifica o utilizador e a palavra-passe (no Gmail é preciso uma "palavra-passe de aplicação").'); }
            }
        }
        $cmd('MAIL FROM:<' . mail_header_safe(mail_from_address($cfg)) . '>', [250]);
        $cmd('RCPT TO:<' . mail_header_safe($to) . '>', [250, 251]);
        $cmd('DATA', [354]);
        $body = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $message));            // "dot-stuffing"
        fwrite($fp, str_replace("\n", "\r\n", $body) . "\r\n.\r\n");
        [$code, $text] = $read();
        if ($code !== 250) throw new RuntimeException('SMTP: o servidor recusou a mensagem (' . trim(preg_replace('/\s+/', ' ', $text)) . ')');
        try { $cmd('QUIT', [221, 250, 0]); } catch (Throwable $e) { /* a mensagem já foi aceite */ }
        return true;
    } finally {
        fclose($fp);
    }
}
