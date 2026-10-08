<?php
/* =========================================================================
   IMPORTAR MOVIMENTOS DE UM CSV  (api/import.php)
   -------------------------------------------------------------------------
   POST multipart (file, action, mapping, options, csrf):
     action=preview   lê o ficheiro e mostra o que ia acontecer SEM gravar nada: colunas, o mapeamento sugerido, as primeiras linhas já
                      convertidas (ok / duplicado / erro) e os totais. Se vier "mapping", usa-o (a pessoa corrigiu as colunas).
     action=import    grava os movimentos válidos e que ainda não existem. Cada importação leva um identificador (batch).
   POST JSON {action:'undo', batch}   apaga os movimentos dessa importação (só os desse negócio).
   Quem o usa: quem tem a permissão do Fluxo de caixa. Tudo é validado e calculado aqui, no servidor.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/csv_import.php';
$user = require_login();
require_permission($user, 'cashflow');
$tid = (int)$user['tenant_id'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $isUpload = isset($_FILES['file']);
    $action = (string)($_POST['action'] ?? request_json()['action'] ?? '');

    if ($action === 'undo') {
        $batch = (string)($_POST['batch'] ?? request_json()['batch'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $batch)) json_response(['success' => false, 'error' => 'Importação inválida.'], 400);
        $st = db()->prepare('DELETE FROM transactions WHERE user_id = ? AND import_batch = ?');
        $st->execute([$tid, $batch]);
        audit('csv_import_undone', ['batch' => $batch, 'deleted' => $st->rowCount()], $user);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if (!in_array($action, ['preview', 'import'], true) || !$isUpload) json_response(['success' => false, 'error' => 'Escolhe um ficheiro CSV.'], 400);
    rate_limit_enforce('import', (string)$user['id']);

    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        json_response(['success' => false, 'error' => in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'O ficheiro é demasiado grande (máximo 1 MB).' : 'Não foi possível receber o ficheiro. Tenta outra vez.'], 400);
    }
    try {
        $csv = csv_parse((string)file_get_contents($f['tmp_name']));
    } catch (ImportError $e) { json_response(['success' => false, 'error' => $e->getMessage()], 400); }

    // mapeamento: o que a pessoa escolheu (se veio) ou o sugerido pelos títulos
    $guess = csv_guess_mapping($csv['headers']);
    $given = json_decode((string)($_POST['mapping'] ?? ''), true);
    $mapping = [];
    foreach (['date', 'description', 'amount', 'debit', 'credit', 'category'] as $field) {
        $v = is_array($given) ? ($given[$field] ?? null) : ($guess[$field] ?? null);
        if ($v === null || $v === '' || $v === -1 || $v === '-1') continue;
        if (!is_numeric($v) || (int)$v < 0 || (int)$v >= count($csv['headers'])) json_response(['success' => false, 'error' => 'Coluna inválida no mapeamento.'], 400);
        $mapping[$field] = (int)$v;
    }
    $opts = json_decode((string)($_POST['options'] ?? ''), true) ?: [];
    if (!isset($mapping['date'])) json_response(['success' => true, 'needs_mapping' => true, 'headers' => $csv['headers'], 'mapping' => $mapping, 'total' => $csv['total'], 'delimiter' => $csv['delimiter'],
        'summary' => ['valid' => 0, 'duplicates' => 0, 'errors' => $csv['total']], 'sample' => [], 'errors' => [], 'message' => 'Escolhe qual é a coluna da data.']);
    if (!isset($mapping['amount']) && !isset($mapping['debit']) && !isset($mapping['credit'])) json_response(['success' => true, 'needs_mapping' => true, 'headers' => $csv['headers'], 'mapping' => $mapping, 'total' => $csv['total'], 'delimiter' => $csv['delimiter'],
        'summary' => ['valid' => 0, 'duplicates' => 0, 'errors' => $csv['total']], 'sample' => [], 'errors' => [], 'message' => 'Escolhe a coluna do valor (ou as de débito e crédito).']);

    $built = csv_build_rows($csv['rows'], $mapping, $opts);
    $status = csv_mark_duplicates($tid, $built['items']);
    $valid = array_keys(array_filter($status, fn($s) => $s === 'ok'));
    $summary = ['valid' => count($valid), 'duplicates' => count($status) - count($valid), 'errors' => count($built['errors'])];

    if ($action === 'preview') {
        $sample = [];
        foreach (array_slice($built['items'], 0, 8, true) as $k => $it) $sample[] = $it + ['status' => $status[$k]];
        json_response(['success' => true, 'needs_mapping' => false, 'headers' => $csv['headers'], 'mapping' => $mapping, 'total' => $csv['total'], 'delimiter' => $csv['delimiter'],
            'summary' => $summary, 'sample' => $sample, 'errors' => array_slice($built['errors'], 0, 10)]);
    }

    // importar: tudo ou nada, num bloco só
    if (!$valid) json_response(['success' => false, 'error' => 'Não há movimentos novos para importar (' . $summary['duplicates'] . ' já existem, ' . $summary['errors'] . ' com erro).'], 409);
    $batch = bin2hex(random_bytes(8));
    $pdo = db(); $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO transactions (user_id, type, description, category, amount, status, occurred_at, import_batch) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($valid as $k) { $it = $built['items'][$k]; $ins->execute([$tid, $it['type'], $it['description'], $it['category'], $it['amount'], 'paid', $it['occurred_at'], $batch]); }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    audit('csv_import', ['batch' => $batch, 'imported' => count($valid), 'duplicates' => $summary['duplicates'], 'errors' => $summary['errors']], $user);
    json_response(['success' => true, 'batch' => $batch, 'imported' => count($valid), 'duplicates' => $summary['duplicates'], 'errors' => $summary['errors'], 'error_list' => array_slice($built['errors'], 0, 10)], 201);
} catch (Throwable $e) {
    internal_error($e);
}
