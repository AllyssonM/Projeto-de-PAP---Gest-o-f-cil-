<?php
/* =========================================================================
   MODELOS DE EMAIL  (includes/mail_templates.php)
   -------------------------------------------------------------------------
   Um único desenho para todos os emails do Lumina (as cores do sistema).
   send_account_mail('reset'|'verify'|'invite', $utilizador, $link) escolhe o texto certo.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/lang.php';       // os emails saem no idioma do pedido (L() traduz; o português é o original)

function mail_layout(string $title, string $intro, ?string $url, ?string $button, string $outro): string
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $btn = $url ? '<p style="margin:26px 0"><a href="' . $h($url) . '" style="display:inline-block;padding:13px 24px;border-radius:14px;background:#43d9b0;color:#052127;font-weight:700;text-decoration:none">' . $h((string)$button) . '</a></p>'
        . '<p style="font-size:13px;color:#5b6b7a">' . L('Se o botão não funcionar, copia este endereço para o navegador:') . '<br><span style="word-break:break-all">' . $h($url) . '</span></p>' : '';
    return '<!doctype html><html lang="' . lumina_locale() . '"><body style="margin:0;background:#eef3f6;font-family:Segoe UI,Arial,sans-serif;color:#0b1f3a">'
        . '<div style="max-width:560px;margin:0 auto;padding:28px 16px"><div style="background:#ffffff;border-radius:20px;padding:30px;border-top:5px solid #43d9b0">'
        . '<p style="margin:0 0 18px;font-size:22px;font-weight:800;letter-spacing:-.01em">Lumina</p>'
        . '<h1 style="margin:0 0 12px;font-size:20px">' . $h($title) . '</h1><p style="line-height:1.6;margin:0">' . $intro . '</p>' . $btn
        . '<p style="line-height:1.6;font-size:14px;color:#5b6b7a;margin:0">' . $outro . '</p></div>'
        . '<p style="text-align:center;font-size:12px;color:#7b8a99;margin-top:14px">' . L('Este é um email automático do Lumina. Não respondas a esta mensagem.') . '</p></div></body></html>';
}

/** Envia o email da conta. $kind: reset | verify | invite. Devolve true se foi entregue ao driver de email. */
function send_account_mail(string $kind, array $user, string $url): bool
{
    $name = htmlspecialchars(explode(' ', trim((string)$user['name']))[0] ?: L('olá'), ENT_QUOTES, 'UTF-8');
    $m = match ($kind) {
        'reset'  => [L('Recuperar a palavra-passe do Lumina'), L('Olá, %s', $name), L('Recebemos um pedido para criar uma nova palavra-passe. Carrega no botão para a escolher. O link vale <strong>1 hora</strong> e só funciona uma vez.'), L('Criar nova palavra-passe'), L('Se não foste tu, ignora este email: a tua palavra-passe atual continua a funcionar.')],
        'verify' => [L('Confirma o teu email no Lumina'), L('Bem-vindo ao Lumina, %s', $name), L('Confirma que este email é teu para receberes avisos e poderes recuperar a palavra-passe. O link vale <strong>3 dias</strong>.'), L('Confirmar o meu email'), L('Se não criaste uma conta no Lumina, ignora este email.')],
        'invite' => [L('Foste convidado para o Lumina'), L('Olá, %s', $name), L('Foi criada uma conta para ti no Lumina. Carrega no botão para escolheres a tua palavra-passe e entrares. O link vale <strong>3 dias</strong>.'), L('Escolher a minha palavra-passe'), L('Se não esperavas este convite, ignora este email.')],
    };
    return send_mail((string)$user['email'], $m[0], mail_layout($m[1], $m[2], $url, $m[3], $m[4]));
}
