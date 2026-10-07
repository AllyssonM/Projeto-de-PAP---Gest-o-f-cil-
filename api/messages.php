<?php
/* =========================================================================
   MENSAGENS ENTRE O LÍDER E OS FUNCIONÁRIOS  (api/messages.php)
   -------------------------------------------------------------------------
   O líder conversa com qualquer funcionário do negócio; cada funcionário conversa só com o líder (nunca com os colegas).
   GET  ?with=ID          a conversa (últimas 100). Ao abri-la, as mensagens recebidas ficam «lidas». (O funcionário não envia «with»: fala com o líder.)
   GET  ?summary=1        quantas mensagens por ler (por funcionário, para o líder; o total, para o funcionário).
   POST {to, body, kind}  envia (kind: general | task | performance | notice; o funcionário só envia «general»). Cria um aviso para quem recebe.
   REGRAS: até 1000 caracteres; no máximo 20 mensagens por minuto; texto sempre escapado ao desenhar; só pessoas do mesmo negócio.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/team.php';
$user = require_login();
$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];
$isOwner = $user['role'] === 'owner';

/** A outra pessoa da conversa: o líder escolhe um funcionário; o funcionário só fala com o líder. */
function message_peer(int $tid, int $uid, bool $isOwner, $with): array
{
    $id = $isOwner ? (int)$with : $tid;
    $st = db()->prepare("SELECT id, name, job_title, avatar_path FROM users WHERE id = ? AND status = 'active' AND (id = ? OR owner_id = ?) AND id <> ? LIMIT 1");
    $st->execute([$id, $tid, $tid, $uid]);
    $p = $st->fetch();
    if (!$p || ($isOwner && (int)$p['id'] === $tid)) json_response(['success' => false, 'error' => 'Destinatário não encontrado.'], 404);
    return $p;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && isset($_GET['summary'])) {
        $st = db()->prepare('SELECT from_user, COUNT(*) n FROM team_messages WHERE tenant_id = ? AND to_user = ? AND read_at IS NULL GROUP BY from_user');
        $st->execute([$tid, $uid]);
        $by = array_column($st->fetchAll(), 'n', 'from_user');
        json_response(['success' => true, 'total' => (int)array_sum($by), 'by_user' => $isOwner ? array_map('intval', $by) : new stdClass()]);
    }
    if ($method === 'GET') {
        $peer = message_peer($tid, $uid, $isOwner, $_GET['with'] ?? 0);
        $pid = (int)$peer['id'];
        $st = db()->prepare('SELECT id, from_user, kind, body, read_at, created_at FROM team_messages WHERE tenant_id = ? AND ((from_user = ? AND to_user = ?) OR (from_user = ? AND to_user = ?)) ORDER BY id DESC LIMIT 100');
        $st->execute([$tid, $uid, $pid, $pid, $uid]);
        $rows = array_reverse($st->fetchAll());
        if (($_GET['mark'] ?? '1') !== '0') {                                 // abrir a conversa = ler o que chegou
            db()->prepare('UPDATE team_messages SET read_at = NOW() WHERE tenant_id = ? AND from_user = ? AND to_user = ? AND read_at IS NULL')->execute([$tid, $pid, $uid]);
            db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND type = 'message' AND actor_id = ? AND read_at IS NULL")->execute([$uid, $pid]);
        }
        json_response(['success' => true, 'now' => team_now(), 'peer' => ['id' => $pid, 'name' => $peer['name'], 'job_title' => $peer['job_title'], 'photo' => team_photo_url($peer)],
            'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'mine' => (int)$r['from_user'] === $uid, 'kind' => $r['kind'], 'body' => $r['body'], 'read' => $r['read_at'] !== null, 'created_at' => $r['created_at']], $rows)]);
    }
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $b = request_json();
    $in = Validator::make($b)->text('body', 'Mensagem', 1000)->orFail();
    $kind = $isOwner && in_array($b['kind'] ?? 'general', ['general', 'task', 'performance', 'notice'], true) ? (string)($b['kind'] ?? 'general') : 'general';
    $peer = message_peer($tid, $uid, $isOwner, $b['to'] ?? 0);
    $rate = db()->prepare('SELECT COUNT(*) FROM team_messages WHERE from_user = ? AND created_at > (NOW() - INTERVAL 1 MINUTE)');
    $rate->execute([$uid]);
    if ((int)$rate->fetchColumn() >= 20) json_response(['success' => false, 'error' => 'Estás a enviar muitas mensagens seguidas. Espera um minuto.'], 429);
    db()->prepare('INSERT INTO team_messages (tenant_id, from_user, to_user, kind, body) VALUES (?, ?, ?, ?, ?)')->execute([$tid, $uid, $peer['id'], $kind, $in['body']]);
    $newId = (int)db()->lastInsertId();                                       // ANTES do aviso: ele também insere uma linha
    $label = ['task' => 'Tarefa', 'performance' => 'Desempenho', 'notice' => 'Aviso importante'][$kind] ?? '';
    notify($tid, (int)$peer['id'], 'message', ($label ? "$label: " : '') . 'nova mensagem de ' . $user['name'], $in['body'], $uid, $isOwner ? 'work' : 'staff');
    json_response(['success' => true, 'id' => $newId], 201);
} catch (Throwable $e) {
    internal_error($e);
}
