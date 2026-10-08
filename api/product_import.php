<?php
/* =========================================================================
   IMPORTAR / EXPORTAR PRODUTOS POR CSV  (api/product_import.php)
   -------------------------------------------------------------------------
   POST multipart (file, action, mapping, csrf):
     action=preview   lê o ficheiro e mostra o que ia acontecer SEM gravar nada: colunas, mapeamento sugerido, produtos novos / já existentes / com erro.
     action=import    cria os produtos novos (tudo ou nada). Nunca altera produtos que já existem. Devolve o identificador da importação (batch).
   POST JSON {action:'undo', batch}   anula a importação: apaga só os produtos que continuam como foram importados (só o responsável do negócio).
   GET  ?action=export   descarrega todos os produtos em CSV (o mesmo formato que a importação lê).
   Quem o usa: quem tem a permissão do Estoque. A lógica está em includes/product_import.php. Nada aqui é desenhado no ecrã: é só a API.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/product_import.php';
$user = require_login();
require_permission($user, 'stock');
$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET' && ($_GET['action'] ?? '') === 'export') {
        rate_limit_enforce('export', (string)$uid);
        $csv = pimp_export_csv(db(), $tid);
        audit('product_export', ['bytes' => strlen($csv)], $user);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="produtos-lumina-' . date('Y-m-d') . '.csv"');
        header('Cache-Control: no-store');
        echo $csv;
        exit;
    }
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $action = (string)($_POST['action'] ?? request_json()['action'] ?? '');

    if ($action === 'undo') {
        require_owner($user);
        $batch = (string)($_POST['batch'] ?? request_json()['batch'] ?? '');
        if (!preg_match('/^[a-f0-9]{16}$/', $batch)) json_response(['success' => false, 'error' => 'Importação inválida.'], 400);
        $r = pimp_undo(db(), $tid, $batch);
        if ($r['found'] === 0) json_response(['success' => false, 'error' => 'Não encontrei produtos desta importação (talvez já tenha sido anulada).'], 404);
        audit('product_import_undone', ['batch' => $batch, 'deleted' => $r['deleted'], 'kept' => count($r['kept'])], $user);
        json_response(['success' => true, 'deleted' => $r['deleted'], 'kept' => $r['kept']]);
    }

    if (!in_array($action, ['preview', 'import'], true) || !isset($_FILES['file'])) json_response(['success' => false, 'error' => 'Escolhe um ficheiro CSV.'], 400);
    rate_limit_enforce('import', (string)$uid);
    $f = $_FILES['file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        json_response(['success' => false, 'error' => in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'O ficheiro é demasiado grande (máximo 1 MB).' : 'Não foi possível receber o ficheiro. Tenta outra vez.'], 400);
    }
    try {
        $csv = csv_parse((string)file_get_contents($f['tmp_name']));
    } catch (ImportError $e) { json_response(['success' => false, 'error' => $e->getMessage()], 400); }

    $guess = pimp_guess_mapping($csv['headers']);
    $given = json_decode((string)($_POST['mapping'] ?? ''), true);
    $mapping = [];
    foreach (array_keys(PIMP_FIELDS) as $field) {
        $v = is_array($given) ? ($given[$field] ?? null) : ($guess[$field] ?? null);
        if ($v === null || $v === '' || $v === -1 || $v === '-1') continue;
        if (!is_numeric($v) || (int)$v < 0 || (int)$v >= count($csv['headers'])) json_response(['success' => false, 'error' => 'Coluna inválida no mapeamento.'], 400);
        $mapping[$field] = (int)$v;
    }
    $base = ['success' => true, 'headers' => $csv['headers'], 'mapping' => $mapping, 'total' => $csv['total'], 'delimiter' => $csv['delimiter'],
        'fields' => array_map(fn($k, $v) => ['key' => $k, 'label' => $v[0], 'required' => $v[1]], array_keys(PIMP_FIELDS), array_values(PIMP_FIELDS))];
    foreach (['name' => 'Escolhe qual é a coluna do nome do produto.', 'sale_price' => 'Escolhe qual é a coluna do preço de venda.'] as $need => $msg) {
        if (!isset($mapping[$need])) {
            if ($action === 'import') json_response(['success' => false, 'error' => $msg], 400);
            json_response($base + ['needs_mapping' => true, 'message' => $msg, 'summary' => ['products' => 0, 'variants' => 0, 'duplicates' => 0, 'errors' => $csv['total'], 'skipped_products' => 0], 'sample' => [], 'errors' => [], 'duplicates' => []]);
        }
    }

    $built = pimp_build($csv['rows'], $mapping);
    $marks = pimp_mark_duplicates(db(), $tid, $built['products']);
    $newOnes = array_keys(array_filter($marks, fn($m) => $m['status'] === 'ok'));
    $summary = ['products' => count($newOnes), 'variants' => array_sum(array_map(fn($k) => count($built['products'][$k]['variants']), $newOnes)),
        'duplicates' => count($marks) - count($newOnes), 'errors' => count($built['errors']), 'skipped_products' => $built['skipped']];

    if ($action === 'preview') {
        $sample = [];
        foreach (array_slice($built['products'], 0, 10, true) as $k => $p) {
            $sample[] = ['line' => $p['line'], 'name' => $p['name'], 'brand' => $p['brand'], 'category' => $p['category'], 'sku' => $p['sku'], 'sale_price' => $p['sale_price'], 'cost_price' => $p['cost_price'],
                'variants' => count($p['variants']), 'quantity' => array_sum(array_column($p['variants'], 'quantity')), 'status' => $marks[$k]['status'], 'reason' => $marks[$k]['reason']];
        }
        $dups = [];
        foreach ($marks as $k => $m) if ($m['status'] === 'duplicate' && count($dups) < 10) $dups[] = ['line' => $built['products'][$k]['line'], 'name' => $built['products'][$k]['name'], 'reason' => $m['reason']];
        json_response($base + ['needs_mapping' => false, 'summary' => $summary, 'sample' => $sample, 'errors' => array_slice($built['errors'], 0, 20), 'duplicates' => $dups]);
    }

    if (!$newOnes) json_response(['success' => false, 'error' => 'Não há produtos novos para importar (' . $summary['duplicates'] . ' já existem, ' . $summary['errors'] . ' linhas com erro).'], 409);
    $r = pimp_run(db(), $tid, $uid, $built['products'], $marks);
    audit('product_import', ['batch' => $r['batch'], 'created' => $r['created'], 'variants' => $r['variants'], 'duplicates' => $summary['duplicates'], 'errors' => $summary['errors']], $user);
    json_response(['success' => true, 'batch' => $r['batch'], 'created' => $r['created'], 'variants' => $r['variants'], 'duplicates' => $r['duplicates'],
        'errors' => $summary['errors'], 'skipped_products' => $built['skipped'], 'error_list' => array_slice($built['errors'], 0, 20)], 201);
} catch (Throwable $e) {
    internal_error($e);
}
