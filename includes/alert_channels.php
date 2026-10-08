<?php
/* =========================================================================
   CANAIS DE AVISO  (includes/alert_channels.php)
   -------------------------------------------------------------------------
   Um único sítio por onde saem os avisos automáticos do Lumina para o DONO do negócio (estoque baixo hoje; cópia de segurança falhada no futuro).
   alert_send($canal, $dono, $assunto, $html, $texto) devolve:
     'sent'        o servidor aceitou o aviso;
     'logged'      (reservado a outros canais de teste) nada saiu do servidor;
     'failed'      erro ao enviar (o motivo fica em mail_last_result());
     'unavailable' o canal não existe, o destinatário não é válido, ou o email ainda está em modo de teste (driver "log").
   Só o canal «email» existe. Para acrescentar outro (ex.: WhatsApp, Telegram), acrescenta uma função em alert_channels() com a mesma forma;
   quem chama não muda. Nenhum canal envia mensagens a clientes: só ao dono.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/mail_templates.php';

/** @return array<string, callable(array,string,string,string):string> */
function alert_channels(): array
{
    return [
        'email' => function (array $owner, string $subject, string $html, string $text): string {
            if (!filter_var($owner['email'] ?? '', FILTER_VALIDATE_EMAIL)) return 'unavailable';
            send_mail((string)$owner['email'], $subject, $html, $text);
            return mail_last_result()['status'];
        },
    ];
}

/** O email só "existe" para avisos quando o servidor de email foi mesmo configurado (smtp ou mail). Com o driver «log» nada sai. */
function alert_email_ready(): bool { return in_array((string)mail_config()['driver'], ['smtp', 'mail'], true); }

function alert_send(string $channel, array $owner, string $subject, string $html, string $text): string
{
    $fn = alert_channels()[$channel] ?? null;
    if ($fn === null) return 'unavailable';
    if ($channel === 'email' && !alert_email_ready()) return 'unavailable';       // driver «log»: nem sequer grava o aviso em disco
    return $fn($owner, $subject, $html, $text);
}
