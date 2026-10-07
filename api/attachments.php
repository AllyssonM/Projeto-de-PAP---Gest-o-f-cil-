<?php
/* =========================================================================
   ANEXOS: FOTO OU PDF DO RECIBO / FATURA  (api/attachments.php)
   -------------------------------------------------------------------------
   GET  ?entity=transaction&id=N   lista os anexos desse registo.
   GET  ?counts=transaction        quantos anexos tem cada registo (para mostrar 📎 2 nas tabelas).
   GET  ?file=ID                   entrega o ficheiro (imagem aberta na página; PDF descarregado).
   POST (multipart: entity, entity_id, file, csrf)   anexa. JPG, PNG, WEBP ou PDF, até 5 MB.
   DELETE ?id=N                    apaga (o dono, ou quem o anexou).
   REGRAS: só se anexa a registos do PRÓPRIO negócio e para quem tem a permissão dessa área; o tipo do ficheiro
           lê-se do CONTEÚDO (não do nome); fotos são recodificadas (apaga a localização GPS e outros metadados);
           máximo 10 por registo e 300 MB por negócio; os ficheiros ficam fora de URLs públicos (storage/).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/attachments.php';
$user = require_login();
$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];

/** Valida a entidade pedida e a permissão. Devolve [tabela, permissão]. */
function attachment_entity(array $user, string $entity): array
{
    if (!isset(ATTACHMENT_ENTITIES[$entity])) json_response(['success' => false, 'error' => 'Tipo de registo inválido.'], 400);
    [$table, $perm] = ATTACHMENT_ENTITIES[$entity];
    require_permission($user, $perm);
    return [$table, $perm];
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET' && isset($_GET['file'])) {
        $st = db()->prepare('SELECT entity, path, original_name FROM attachments WHERE id = ? AND user_id = ?');
        $st->execute([(int)$_GET['file'], $tid]);
        $a = $st->fetch();
        if (!$a) { http_response_code(404); exit; }
        attachment_entity($user, $a['entity']);
        upload_send($a['path'], $a['original_name']);
    }
    if ($method === 'GET' && isset($_GET['counts'])) {
        attachment_entity($user, (string)$_GET['counts']);
        $st = db()->prepare('SELECT entity_id, COUNT(*) n FROM attachments WHERE user_id = ? AND entity = ? GROUP BY entity_id');
        $st->execute([$tid, (string)$_GET['counts']]);
        json_response(['success' => true, 'counts' => array_column($st->fetchAll(), 'n', 'entity_id')]);
    }
    if ($method === 'GET') {
        $entity = (string)($_GET['entity'] ?? ''); $id = (int)($_GET['id'] ?? 0);
        attachment_entity($user, $entity);
        $st = db()->prepare('SELECT id, original_name, mime, size, created_at, uploaded_by FROM attachments WHERE user_id = ? AND entity = ? AND entity_id = ? ORDER BY id');
        $st->execute([$tid, $entity, $id]);
        json_response(['success' => true, 'items' => array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['original_name'], 'mime' => $r['mime'], 'size' => (int)$r['size'],
            'created_at' => $r['created_at'], 'can_delete' => $user['role'] === 'owner' || (int)$r['uploaded_by'] === $uid, 'url' => 'api/attachments.php?file=' . (int)$r['id']], $st->fetchAll()),
            'max' => ATTACHMENT_MAX_PER_ITEM]);
    }
    if ($method === 'DELETE') {
        check_csrf();
        $st = db()->prepare('SELECT id, entity, path, uploaded_by FROM attachments WHERE id = ? AND user_id = ?');
        $st->execute([(int)($_GET['id'] ?? 0), $tid]);
        $a = $st->fetch();
        if (!$a) json_response(['success' => true, 'deleted' => 0]);
        attachment_entity($user, $a['entity']);
        if ($user['role'] !== 'owner' && (int)$a['uploaded_by'] !== $uid) json_response(['success' => false, 'error' => 'Só o administrador ou quem anexou o ficheiro o pode apagar.'], 403);
        upload_delete($a['path']);
        db()->prepare('DELETE FROM attachments WHERE id = ? AND user_id = ?')->execute([$a['id'], $tid]);
        json_response(['success' => true, 'deleted' => 1]);
    }
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);

    check_csrf();
    $entity = (string)($_POST['entity'] ?? ''); $entityId = (int)($_POST['entity_id'] ?? 0);
    [$table] = attachment_entity($user, $entity);
    $own = db()->prepare("SELECT 1 FROM `$table` WHERE id = ? AND user_id = ?");           // o nome da tabela vem de uma lista fixa
    $own->execute([$entityId, $tid]);
    if (!$own->fetchColumn()) json_response(['success' => false, 'error' => 'Registo não encontrado.'], 404);
    $q = db()->prepare('SELECT COUNT(*), COALESCE(SUM(CASE WHEN entity = ? AND entity_id = ? THEN 1 END), 0), COALESCE(SUM(size), 0) FROM attachments WHERE user_id = ?');
    $q->execute([$entity, $entityId, $tid]);
    [, $perItem, $bytes] = array_map('intval', $q->fetch(PDO::FETCH_NUM));
    if ($perItem >= ATTACHMENT_MAX_PER_ITEM) json_response(['success' => false, 'error' => 'Já há ' . ATTACHMENT_MAX_PER_ITEM . ' ficheiros neste registo. Apaga algum para juntar outro.'], 409);
    try {
        [$content, $mime, $ext] = upload_validate($_FILES['file'] ?? null, 'receipt');
    } catch (UploadError $e) { json_response(['success' => false, 'error' => $e->getMessage()], 400); }
    if ($bytes + strlen($content) > ATTACHMENT_MAX_BYTES_PER_BUSINESS) json_response(['success' => false, 'error' => 'O espaço de anexos do negócio está cheio. Apaga ficheiros antigos.'], 409);
    $rel = upload_store('receipt', $content, $ext);
    $name = mb_substr(trim(preg_replace('/[\x00-\x1f\\\\\/]+/u', '_', (string)($_FILES['file']['name'] ?? 'ficheiro'))), 0, 150) ?: 'ficheiro';
    try {
        db()->prepare('INSERT INTO attachments (user_id, uploaded_by, entity, entity_id, path, original_name, mime, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tid, $uid, $entity, $entityId, $rel, $name, $mime, strlen($content)]);
        $newId = (int)db()->lastInsertId();                                                  // ANTES de audit(): ele também insere uma linha e mudaria este valor
    } catch (Throwable $e) { upload_delete($rel); throw $e; }                                // se a base de dados falhar, não fica um ficheiro órfão
    audit('attachment_added', ['entity' => $entity, 'id' => $entityId], $user);
    json_response(['success' => true, 'id' => $newId], 201);
} catch (Throwable $e) {
    internal_error($e);
}
