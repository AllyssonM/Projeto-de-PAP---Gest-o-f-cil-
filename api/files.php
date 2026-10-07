<?php
/* =========================================================================
   ENTREGA DE IMAGENS  (api/files.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  mostra a foto de perfil (?type=avatar&id=N) e a logo da empresa
               (?type=logo). Os ficheiros estão em storage/, sem acesso direto
               por URL: passam sempre por aqui, onde se confirma a sessão.
   QUEM PODE VER: só utilizadores com sessão e DO MESMO NEGÓCIO. Outro negócio
                  recebe 404 (nem se sabe se a imagem existe).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$tid = (int)$user['tenant_id'];
$type = $_GET['type'] ?? '';

if ($type === 'avatar') {
    // a foto de um colega só se vê dentro do mesmo negócio (o próprio, o dono e os funcionários)
    $st = db()->prepare('SELECT avatar_path FROM users WHERE id = ? AND (id = ? OR owner_id = ?)');
    $id = (int)($_GET['id'] ?? 0);
    $st->execute([$id, $tid, $tid]);
    upload_send($st->fetchColumn() ?: null);
}
if ($type === 'logo') {
    $st = db()->prepare('SELECT logo_path FROM business_profiles WHERE user_id = ?');
    $st->execute([$tid]);
    upload_send($st->fetchColumn() ?: null);
}
http_response_code(404);
