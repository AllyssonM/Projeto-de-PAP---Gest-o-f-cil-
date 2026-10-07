<?php
/* =========================================================================
   API DO BLOCO DE NOTAS  (api/notes.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  criar, editar, fixar, pesquisar e apagar notas.
     GET    ?q=texto&tag=etiqueta   lista as notas que o utilizador pode ver
     POST   action=save             cria (sem id) ou edita (com id) uma nota
     POST   action=pin              fixa / desafixa
     DELETE ?id=N                   apaga
   QUEM VÊ O QUÊ (privacidade):
     - Nota PRIVADA  -> só quem a escreveu. Nem o dono lê as notas privadas dos funcionários.
     - Nota PARTILHADA com a equipa -> todos os utilizadores do mesmo negócio.
   QUEM ALTERA: o autor. O dono também pode editar/apagar notas PARTILHADAS (para moderar).
   Todos os utilizadores com sessão têm notas (não depende das permissões por área).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$uid = (int)$user['id'];
$tid = (int)$user['tenant_id'];
$isOwner = ($user['role'] ?? '') === 'owner';
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();

const NOTE_COLORS = ['teal', 'blue', 'purple', 'coral', 'amber', 'slate'];     // a paleta do sistema (lista fechada)

/** Etiquetas: no máximo 8, até 24 caracteres cada, sem repetidas, só texto simples. */
function clean_tags(mixed $raw): array
{
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $t) {
        if (!is_string($t)) continue;
        $t = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', $t) ?? ''), 0, 24);
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) $out[] = $t;
        if (count($out) >= 8) break;
    }
    return $out;
}

/** Linha da base de dados -> o que o navegador recebe. */
function note_out(array $r, int $uid): array
{
    return ['id' => (int)$r['id'], 'title' => $r['title'], 'detail' => $r['detail'] ?? '', 'color' => $r['color'] ?: 'teal',
        'tags' => $r['tags'] ? (json_decode($r['tags'], true) ?: []) : [], 'pinned' => (bool)$r['pinned'], 'shared' => (bool)$r['shared'],
        'mine' => (int)$r['author_id'] === $uid, 'author' => $r['author_name'] ?? null, 'updated_at' => $r['updated_at'], 'created_at' => $r['created_at']];
}

/** Procura uma nota que este utilizador pode alterar (autor, ou dono se for partilhada). */
function editable_note(int $id, int $uid, int $tid, bool $isOwner): ?array
{
    $st = db()->prepare('SELECT id, author_id, shared FROM notes WHERE id = ? AND user_id = ?');
    $st->execute([$id, $tid]);
    $n = $st->fetch();
    if (!$n) return null;
    return ((int)$n['author_id'] === $uid || ($isOwner && (int)$n['shared'] === 1)) ? $n : null;
}

try {
    /* ------------------------------ LISTAR ------------------------------ */
    if ($method === 'GET') {
        $where = 'n.user_id = ? AND (n.shared = 1 OR n.author_id = ?)';           // só as que pode ver
        $params = [$tid, $uid];
        $q = trim((string)($_GET['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], mb_substr($q, 0, 80)) . '%';
            $where .= " AND (n.title LIKE ? ESCAPE '|' OR n.detail LIKE ? ESCAPE '|' OR n.tags LIKE ? ESCAPE '|')";
            array_push($params, $like, $like, $like);
        }
        $tag = trim((string)($_GET['tag'] ?? ''));
        if ($tag !== '') { $where .= " AND n.tags LIKE ? ESCAPE '|'"; $params[] = '%"' . str_replace(['|', '%', '_', '"'], ['||', '|%', '|_', ''], mb_substr($tag, 0, 24)) . '"%'; }
        $st = db()->prepare("SELECT n.id, n.title, n.detail, n.color, n.tags, n.pinned, n.shared, n.author_id, n.created_at, n.updated_at, u.name AS author_name
                             FROM notes n LEFT JOIN users u ON u.id = n.author_id WHERE $where ORDER BY n.pinned DESC, n.updated_at DESC, n.id DESC LIMIT 300");
        $st->execute($params);
        $items = array_map(fn($r) => note_out($r, $uid), $st->fetchAll());
        $allTags = [];
        foreach ($items as $i) foreach ($i['tags'] as $t) $allTags[$t] = ($allTags[$t] ?? 0) + 1;
        arsort($allTags);
        json_response(['success' => true, 'items' => $items, 'tags' => array_keys($allTags)]);
    }

    /* ------------------------------ APAGAR ------------------------------ */
    if ($method === 'DELETE') {
        check_csrf();
        $n = editable_note((int)($_GET['id'] ?? 0), $uid, $tid, $isOwner);
        if (!$n) json_response(['success' => false, 'error' => 'Nota não encontrada.'], 404);
        db()->prepare('DELETE FROM notes WHERE id = ? AND user_id = ?')->execute([$n['id'], $tid]);
        json_response(['success' => true]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $action = (string)($data['action'] ?? 'save');

    /* ------------------------------ FIXAR ------------------------------ */
    if ($action === 'pin') {
        $n = editable_note((int)($data['id'] ?? 0), $uid, $tid, $isOwner);
        if (!$n) json_response(['success' => false, 'error' => 'Nota não encontrada.'], 404);
        db()->prepare('UPDATE notes SET pinned = ? WHERE id = ? AND user_id = ?')->execute([!empty($data['pinned']) ? 1 : 0, $n['id'], $tid]);
        json_response(['success' => true]);
    }

    /* ------------------------------ GUARDAR (criar ou editar) ------------------------------ */
    if ($action === 'save') {
        $title = trim(mb_substr((string)($data['title'] ?? ''), 0, 160));
        $detail = mb_substr((string)($data['detail'] ?? ''), 0, 20000);
        if ($title === '' && trim($detail) === '') json_response(['success' => false, 'error' => 'A nota está vazia.'], 400);
        if ($title === '') $title = mb_substr(trim(strtok($detail, "\n") ?: 'Nota'), 0, 60);            // sem título: usa o início do texto
        $color = in_array($data['color'] ?? '', NOTE_COLORS, true) ? $data['color'] : 'teal';
        $tags = clean_tags($data['tags'] ?? []);
        $tagsJson = $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
        $pinned = !empty($data['pinned']) ? 1 : 0;
        $shared = !empty($data['shared']) ? 1 : 0;

        if (!empty($data['id'])) {
            $n = editable_note((int)$data['id'], $uid, $tid, $isOwner);
            if (!$n) json_response(['success' => false, 'error' => 'Nota não encontrada.'], 404);
            // só o autor decide se é privada ou partilhada; o dono a moderar não a torna privada nem altera quem a escreveu
            $shareSql = (int)$n['author_id'] === $uid ? ', shared = ?' : '';
            $args = [$title, $detail, $color, $tagsJson, $pinned];
            if ($shareSql) $args[] = $shared;
            array_push($args, $n['id'], $tid);
            db()->prepare("UPDATE notes SET title = ?, detail = ?, color = ?, tags = ?, pinned = ?{$shareSql} WHERE id = ? AND user_id = ?")->execute($args);
            $id = (int)$n['id'];
        } else {
            // "tag" é a coluna antiga (uma só etiqueta, obrigatória); fica vazia: as etiquetas novas vão em "tags" (JSON)
            db()->prepare("INSERT INTO notes (user_id, author_id, title, detail, tag, color, tags, pinned, shared) VALUES (?,?,?,?,'',?,?,?,?)")
                ->execute([$tid, $uid, $title, $detail, $color, $tagsJson, $pinned, $shared]);
            $id = (int)db()->lastInsertId();
        }
        $st = db()->prepare('SELECT n.id, n.title, n.detail, n.color, n.tags, n.pinned, n.shared, n.author_id, n.created_at, n.updated_at, u.name AS author_name FROM notes n LEFT JOIN users u ON u.id = n.author_id WHERE n.id = ?');
        $st->execute([$id]);
        json_response(['success' => true, 'item' => note_out($st->fetch(), $uid)], empty($data['id']) ? 201 : 200);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $error) {
    internal_error($error);
}
