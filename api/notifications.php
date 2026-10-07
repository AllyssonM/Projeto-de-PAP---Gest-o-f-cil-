<?php
/* =========================================================================
   AVISOS (O SINO)  (api/notifications.php)
   -------------------------------------------------------------------------
   Cada pessoa vê só os SEUS avisos. O líder recebe: entrada de funcionário, nova mensagem. O funcionário recebe: nova mensagem, nova tarefa,
   meta alterada, comentário sobre o desempenho e anúncios da equipa.
   GET                  os últimos 30 avisos e quantos estão por ler. (É também o «batimento» que mantém o estado online: o painel pergunta de tempos a tempos.)
   POST {action:'read', id}  |  {action:'read_all'}   marca como lido.
   RETENÇÃO: avisos lidos há mais de 60 dias e quaisquer com mais de 180 dias são apagados (limpeza ocasional).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/team.php';
$user = require_login();
$uid = (int)$user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (random_int(1, 40) === 1) team_cleanup();                                  // retenção ocasional (ver includes/team.php)
        $st = db()->prepare('SELECT n.id, n.type, n.title, n.body, n.actor_id, n.section, n.read_at, n.created_at, a.name actor_name, a.avatar_path FROM notifications n
            LEFT JOIN users a ON a.id = n.actor_id WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 30');
        $st->execute([$uid]);
        $items = array_map(fn($r) => ['id' => (int)$r['id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['body'], 'section' => $r['section'], 'read' => $r['read_at'] !== null, 'created_at' => $r['created_at'],
            'actor' => $r['actor_id'] ? ['id' => (int)$r['actor_id'], 'name' => $r['actor_name'], 'photo' => team_photo_url(['id' => $r['actor_id'], 'avatar_path' => $r['avatar_path']])] : null], $st->fetchAll());
        $c = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $c->execute([$uid]);
        json_response(['success' => true, 'now' => team_now(), 'unread' => (int)$c->fetchColumn(), 'items' => $items]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $b = request_json();
    if (($b['action'] ?? '') === 'read_all') {
        $st = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $st->execute([$uid]);
        json_response(['success' => true, 'updated' => $st->rowCount()]);
    }
    if (($b['action'] ?? '') === 'read') {
        $st = db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
        $st->execute([(int)($b['id'] ?? 0), $uid]);
        json_response(['success' => true, 'updated' => $st->rowCount()]);
    }
    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $e) {
    internal_error($e);
}
