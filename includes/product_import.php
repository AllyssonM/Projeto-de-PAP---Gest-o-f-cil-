<?php
/* =========================================================================
   IMPORTAR E EXPORTAR PRODUTOS POR CSV  (includes/product_import.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  lê um CSV (Excel PT ou outro) com produtos e, depois de a pessoa ver a pré-visualização e confirmar, CRIA os produtos novos.
   REGRAS DE SEGURANÇA DOS DADOS:
     - NUNCA sobrescreve: um produto que já existe (mesmo nome e marca, ou mesmo SKU) é listado como «já existe» e ignorado.
     - NADA é gravado na pré-visualização. Na importação, tudo ou nada: ou entram todos os produtos válidos, ou não entra nenhum.
     - VALIDAÇÃO por linha com as mesmas regras do ecrã do Estoque (stock_lib.php): nome, preço de venda obrigatórios; preços ≥ 0;
       quantidades e stock mínimo inteiros ≥ 0; tamanho/cor com limites. Uma linha com erro tira o PRODUTO inteiro (nunca entra «meio produto»).
     - VARIAÇÕES: várias linhas com o mesmo nome e marca = um produto com várias variações (tamanho/cor). Dados do produto em conflito = erro.
     - ANULAR: cada importação tem um identificador. Anular apaga só os produtos que continuam exatamente como foram importados
       (assinatura); os que foram editados, vendidos ou ajustados ficam, e diz-se quais e porquê.
   Colunas reconhecidas (PT/EN): nome*, marca, categoria, sku, preço de custo, preço de venda*, stock mínimo, quantidade, tamanho, cor, imagem.
   Reutiliza csv_parse() de includes/csv_import.php (codificação, separador, limites de 1 MB e 2000 linhas).
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/csv_import.php';
require_once __DIR__ . '/stock_lib.php';

/** Campos importáveis: campo => [rótulo, obrigatório]. */
const PIMP_FIELDS = ['name' => ['Nome', true], 'brand' => ['Marca', false], 'category' => ['Categoria', false], 'sku' => ['SKU / referência', false],
    'cost_price' => ['Preço de custo', false], 'sale_price' => ['Preço de venda', true], 'minimum_stock' => ['Stock mínimo', false], 'quantity' => ['Quantidade', false],
    'size' => ['Tamanho', false], 'color' => ['Cor', false], 'image_url' => ['Imagem (endereço)', false]];

function pimp_norm(string $s): string
{
    $s = strtr(mb_strtolower(trim($s)), ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c']);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '');
}

/** Sugere que coluna é cada campo, pelos títulos (PT e EN). Devolve [campo => índice]. Títulos exatos primeiro. */
function pimp_guess_mapping(array $headers): array
{
    $names = [
        'cost_price' => ['preco de custo', 'preco custo', 'custo', 'cost price', 'cost', 'preco compra', 'preco de compra'],
        'sale_price' => ['preco de venda', 'preco venda', 'pvp', 'venda', 'sale price', 'price', 'preco'],
        'minimum_stock' => ['stock minimo', 'estoque minimo', 'minimo', 'minimum stock', 'min stock', 'stock min'],
        'quantity' => ['quantidade', 'qtd', 'qtde', 'stock', 'estoque', 'quantity', 'qty', 'existencias', 'unidades'],
        'name' => ['nome', 'produto', 'nome do produto', 'designacao', 'descricao', 'artigo', 'name', 'product', 'product name', 'title', 'titulo'],
        'brand' => ['marca', 'brand', 'fabricante'],
        'category' => ['categoria', 'category', 'familia', 'tipo'],
        'sku' => ['sku', 'referencia', 'ref', 'codigo', 'codigo de barras', 'code', 'barcode', 'ean'],
        'size' => ['tamanho', 'size', 'tam'],
        'color' => ['cor', 'color', 'colour'],
        'image_url' => ['imagem', 'image', 'foto', 'url da imagem', 'image url', 'photo'],
    ];
    $map = [];
    foreach ($names as $field => $list) {
        foreach ($headers as $i => $h) {
            if (!in_array($i, $map, true) && in_array(pimp_norm((string)$h), $list, true)) { $map[$field] = $i; break; }
        }
    }
    return $map;
}

/** Inteiro ≥ 0 escrito por extenso («12», «12,0», «1.000»). Decimais a sério («1,5») não são inteiros: devolve null. */
function pimp_int(string $v): ?int
{
    $s = trim(str_replace(["\xC2\xA0", ' '], '', $v));
    if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $s)) { $s = str_replace(['.', ','], '', $s); }       // 1.000 / 1,000 = mil
    elseif (preg_match('/^(\d+)[.,]0+$/', $s, $m)) { $s = $m[1]; }
    return preg_match('/^\d{1,9}$/', $s) ? (int)$s : null;
}

/** Preço ≥ 0 à portuguesa. Devolve null se não for um número válido ou for negativo. */
function pimp_price(string $v): ?float
{
    $n = csv_parse_amount($v);
    return $n === null || $n < 0 ? null : $n;
}

/** Chave do produto dentro do ficheiro: nome + marca, sem distinguir maiúsculas (como a verificação de duplicados do Estoque). */
function pimp_key(string $name, ?string $brand): string
{
    return mb_strtolower($name) . '|' . mb_strtolower($brand ?? '');
}

/**
 * Converte as linhas em produtos (com variações). Uma linha com erro tira o produto inteiro.
 * @param list<list<string>> $rows  @param array<string,int> $mapping campo => índice da coluna
 * @return array{products: array<string,array>, errors: list<array{line:int,reason:string}>, skipped: int}
 *   products[chave] = [name, brand, category, sku, cost_price, sale_price, minimum_stock, image_url, line, variants: list<[size,color,quantity,line]>]
 */
function pimp_build(array $rows, array $mapping): array
{
    $get = fn(array $r, string $f): string => isset($mapping[$f]) ? trim((string)($r[(int)$mapping[$f]] ?? '')) : '';
    $same = fn($a, $b): bool => is_float($a) || is_float($b) ? abs((float)$a - (float)$b) < 0.005 : mb_strtolower((string)$a) === mb_strtolower((string)$b);
    $products = []; $errors = []; $bad = []; $skus = [];
    foreach ($rows as $i => $r) {
        $line = $i + 2;                                                        // linha no ficheiro (1 = títulos)
        $fail = function (string $reason) use (&$errors, $line) { $errors[] = ['line' => $line, 'reason' => $reason]; };
        $name = stock_text($get($r, 'name'), 160);
        if ($name === null) { $fail('falta o nome do produto'); continue; }
        $brand = stock_text($get($r, 'brand'), 100);
        $key = pimp_key($name, $brand);
        $in = ['name' => $name, 'brand' => $brand, 'category' => stock_text($get($r, 'category'), 80), 'sku' => stock_text($get($r, 'sku'), 60), 'image_url' => stock_text($get($r, 'image_url'), 500),
            'sale_price' => null, 'cost_price' => null, 'minimum_stock' => null];
        $reasons = []; $quantity = 0;
        foreach (['sale_price' => ['preço de venda', 'pimp_price'], 'cost_price' => ['preço de custo', 'pimp_price']] as $f => [$label, $parse]) {
            if (($raw = $get($r, $f)) === '') { continue; }
            $in[$f] = $parse($raw);
            if ($in[$f] === null) { $reasons[] = "$label inválido («" . mb_substr($raw, 0, 20) . '»)'; }
        }
        if (($raw = $get($r, 'minimum_stock')) !== '') {
            $in['minimum_stock'] = pimp_int($raw);
            if ($in['minimum_stock'] === null) { $reasons[] = 'o stock mínimo tem de ser um número inteiro (não «' . mb_substr($raw, 0, 20) . '»)'; }
        }
        if (($raw = $get($r, 'quantity')) !== '') {
            $q = pimp_int($raw);
            if ($q === null) { $reasons[] = 'a quantidade tem de ser um número inteiro (não «' . mb_substr($raw, 0, 20) . '»)'; } else { $quantity = $q; }
        }
        $variant = ['size' => stock_text($get($r, 'size'), 20), 'color' => stock_text($get($r, 'color'), 40), 'quantity' => $quantity, 'line' => $line];
        if ($reasons) { $fail(implode('; ', $reasons)); $bad[$key] = true; continue; }

        if (!isset($products[$key])) {                                         // 1.ª linha deste produto: valida com as regras do ecrã do Estoque
            if ($in['sale_price'] === null) { $fail('falta o preço de venda'); $bad[$key] = true; continue; }
            try {
                stock_product_in($in + ['status' => 'active']);                // (custo e stock mínimo vazios valem 0 ao criar)
                stock_variant_in($variant);
            } catch (StockError $e) { $fail($e->getMessage()); $bad[$key] = true; continue; }
            if ($in['sku'] !== null) {
                $sk = mb_strtolower($in['sku']);
                if (isset($skus[$sk]) && $skus[$sk] !== $key) { $fail('o SKU «' . $in['sku'] . '» já foi usado noutro produto do ficheiro (linha ' . $products[$skus[$sk]]['line'] . ')'); $bad[$key] = true; continue; }
                $skus[$sk] = $key;
            }
            $products[$key] = $in + ['line' => $line, 'variants' => [$variant]];
            continue;
        }
        // mais uma linha do MESMO produto = mais uma variação; os dados do produto não podem contradizer-se (vazio = herda)
        $p = &$products[$key];
        $conflict = null;
        foreach (['sale_price' => 'preço de venda', 'cost_price' => 'preço de custo', 'minimum_stock' => 'stock mínimo', 'category' => 'categoria', 'sku' => 'SKU', 'image_url' => 'imagem'] as $f => $label) {
            if ($in[$f] !== null && $p[$f] !== null && !$same($in[$f], $p[$f])) { $conflict = $label; break; }
        }
        $problem = null;
        if ($conflict !== null) { $problem = "o $conflict é diferente do da linha {$p['line']} (mesmo produto: «{$name}»)"; }
        else {
            try { stock_variant_in($variant); } catch (StockError $e) { $problem = $e->getMessage(); }
            $combo = mb_strtolower(($variant['size'] ?? '') . '|' . ($variant['color'] ?? ''));
            foreach ($p['variants'] as $v) {
                if ($problem === null && mb_strtolower(($v['size'] ?? '') . '|' . ($v['color'] ?? '')) === $combo) { $problem = "o tamanho e a cor repetem os da linha {$v['line']} (mesmo produto)"; }
            }
            if ($problem === null && count($p['variants']) >= 200) { $problem = 'demasiadas variações para este produto (máximo 200)'; }
        }
        if ($problem !== null) { $fail($problem); $bad[$key] = true; unset($p); continue; }
        foreach (['cost_price', 'minimum_stock', 'category', 'sku', 'image_url'] as $f) { if ($p[$f] === null && $in[$f] !== null) { $p[$f] = $in[$f]; } }     // as linhas seguintes completam o que faltava
        if ($p['sku'] !== null) { $skus[mb_strtolower($p['sku'])] ??= $key; }
        $p['variants'][] = $variant;
        unset($p);
    }
    $skipped = 0;
    foreach (array_keys($bad) as $k) { unset($products[$k]); $skipped++; }
    return ['products' => $products, 'errors' => $errors, 'skipped' => $skipped];
}

/**
 * Marca cada produto como 'ok' ou 'duplicate' (já existe: mesmo nome e marca, ou mesmo SKU, entre os produtos não arquivados). Nunca altera o que existe.
 * @return array<string,array{status:string,reason:?string}>
 */
function pimp_mark_duplicates(PDO $pdo, int $tid, array $products): array
{
    $st = $pdo->prepare("SELECT name, brand, sku FROM products WHERE user_id = ? AND status <> 'archived'");
    $st->execute([$tid]);
    $names = []; $skus = [];
    foreach ($st->fetchAll() as $r) {
        $names[pimp_key((string)$r['name'], $r['brand'] !== null && $r['brand'] !== '' ? (string)$r['brand'] : null)] = $r['name'];
        if ($r['sku'] !== null && $r['sku'] !== '') { $skus[mb_strtolower((string)$r['sku'])] = $r['name']; }
    }
    $out = [];
    foreach ($products as $key => $p) {
        if (isset($names[$key])) { $out[$key] = ['status' => 'duplicate', 'reason' => 'já existe um produto com este nome e marca']; }
        elseif ($p['sku'] !== null && isset($skus[mb_strtolower($p['sku'])])) { $out[$key] = ['status' => 'duplicate', 'reason' => 'o SKU «' . $p['sku'] . '» já existe em «' . $skus[mb_strtolower($p['sku'])] . '»']; }
        else { $out[$key] = ['status' => 'ok', 'reason' => null]; }
    }
    return $out;
}

/** Assinatura (16 hex) do produto TAL COMO ESTÁ na base de dados agora: dados do produto + variações. Serve para saber se foi mexido depois de importado. */
function pimp_signature(PDO $pdo, int $tid, int $pid): ?string
{
    $st = $pdo->prepare('SELECT name, brand, category, sku, cost_price, sale_price, minimum_stock, image_url, status FROM products WHERE id = ? AND user_id = ?');
    $st->execute([$pid, $tid]);
    $p = $st->fetch();
    if (!$p) { return null; }
    $v = $pdo->prepare('SELECT size, color, price, quantity, status FROM product_variants WHERE product_id = ? AND user_id = ? ORDER BY size_key, color_key');
    $v->execute([$pid, $tid]);
    $variants = array_map(fn($r) => [$r['size'], $r['color'], $r['price'] === null ? null : number_format((float)$r['price'], 2, '.', ''), (int)$r['quantity'], $r['status']], $v->fetchAll());
    $data = [$p['name'], $p['brand'], $p['category'], $p['sku'], number_format((float)$p['cost_price'], 2, '.', ''), number_format((float)$p['sale_price'], 2, '.', ''), (int)$p['minimum_stock'], $p['image_url'], $p['status'], $variants];
    return substr(hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE)), 0, 16);
}

/**
 * Cria os produtos 'ok', tudo ou nada. Devolve o identificador da importação e o que foi feito.
 * @return array{batch:string,created:int,variants:int,duplicates:int,ids:list<int>}
 */
function pimp_run(PDO $pdo, int $tid, int $uid, array $products, array $marks): array
{
    $batch = bin2hex(random_bytes(8));
    $created = 0; $variants = 0; $dups = 0; $ids = [];
    $pdo->beginTransaction();
    try {
        foreach ($products as $key => $p) {
            if (($marks[$key]['status'] ?? '') !== 'ok') { $dups++; continue; }
            $d = ['name' => $p['name'], 'brand' => $p['brand'], 'category' => $p['category'], 'sku' => $p['sku'], 'cost_price' => $p['cost_price'] ?? 0.0, 'sale_price' => $p['sale_price'],
                'minimum_stock' => $p['minimum_stock'] ?? 0, 'image_url' => $p['image_url'], 'status' => 'active',
                'variants' => array_map(fn($v) => ['size' => $v['size'], 'color' => $v['color'], 'quantity' => $v['quantity']], $p['variants'])];
            try { $res = product_create($pdo, $tid, $uid, $d, null); }
            catch (StockError $e) { if ($e->status === 409) { $dups++; continue; } throw $e; }       // criado entretanto por outra pessoa: conta como já existente
            $pid = (int)$res['id'];
            $pdo->prepare('UPDATE products SET import_batch = ?, import_sig = ? WHERE id = ? AND user_id = ?')->execute([$batch, pimp_signature($pdo, $tid, $pid), $pid, $tid]);
            $created++; $variants += count($p['variants']); $ids[] = $pid;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['batch' => $batch, 'created' => $created, 'variants' => $variants, 'duplicates' => $dups, 'ids' => $ids];
}

/**
 * Anula uma importação: apaga os produtos dela que continuam EXATAMENTE como foram importados e sem nenhuma venda nem ajuste de estoque.
 * Os outros ficam e são devolvidos com o motivo. @return array{deleted:int,kept:list<array{name:string,reason:string}>,found:int}
 */
function pimp_undo(PDO $pdo, int $tid, string $batch): array
{
    $st = $pdo->prepare('SELECT id, name, import_sig FROM products WHERE user_id = ? AND import_batch = ? ORDER BY id');
    $st->execute([$tid, $batch]);
    $rows = $st->fetchAll();
    $deleted = 0; $kept = [];
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $pid = (int)$r['id'];
            $sold = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE user_id = ? AND product_id = ?'); $sold->execute([$tid, $pid]);
            $moves = $pdo->prepare("SELECT COUNT(*) FROM stock_movements WHERE user_id = ? AND product_id = ? AND type <> 'initial'"); $moves->execute([$tid, $pid]);
            if ((int)$sold->fetchColumn() > 0) { $kept[] = ['name' => (string)$r['name'], 'reason' => 'já tem vendas']; continue; }
            if ((int)$moves->fetchColumn() > 0) { $kept[] = ['name' => (string)$r['name'], 'reason' => 'o estoque foi ajustado depois de importar']; continue; }
            if ($r['import_sig'] === null || pimp_signature($pdo, $tid, $pid) !== $r['import_sig']) { $kept[] = ['name' => (string)$r['name'], 'reason' => 'foi editado depois de importar']; continue; }
            $pdo->prepare('DELETE FROM products WHERE id = ? AND user_id = ?')->execute([$pid, $tid]);
            $deleted++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return ['deleted' => $deleted, 'kept' => $kept, 'found' => count($rows)];
}

/** Texto de uma célula CSV exportada: sem células que o Excel executaria como fórmula (=, +, -, @, tab, CR no início). */
function pimp_csv_cell(?string $v): string
{
    $v = (string)$v;
    return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
}

/** Todos os produtos (não arquivados) deste negócio em CSV: UTF-8 com BOM, separador «;», uma linha por variação. Pode voltar a ser importado noutro negócio. */
function pimp_export_csv(PDO $pdo, int $tid): string
{
    $st = $pdo->prepare("SELECT p.id, p.name, p.brand, p.category, p.sku, p.cost_price, p.sale_price, p.minimum_stock, p.image_url, v.size, v.color, v.quantity
        FROM products p LEFT JOIN product_variants v ON v.product_id = p.id AND v.user_id = p.user_id AND v.status = 'active'
        WHERE p.user_id = ? AND p.status <> 'archived' ORDER BY p.name, p.id, v.size_key, v.color_key LIMIT 50000");
    $st->execute([$tid]);
    $fh = fopen('php://temp', 'r+');
    $money = fn($n) => number_format((float)$n, 2, ',', '');
    fputcsv($fh, ['Nome', 'Marca', 'Categoria', 'SKU', 'Preço de custo', 'Preço de venda', 'Stock mínimo', 'Quantidade', 'Tamanho', 'Cor', 'Imagem'], ';', '"', '');
    foreach ($st->fetchAll() as $r) {
        fputcsv($fh, [pimp_csv_cell($r['name']), pimp_csv_cell($r['brand']), pimp_csv_cell($r['category']), pimp_csv_cell($r['sku']), $money($r['cost_price']), $money($r['sale_price']),
            (int)$r['minimum_stock'], (int)($r['quantity'] ?? 0), pimp_csv_cell($r['size']), pimp_csv_cell($r['color']), pimp_csv_cell($r['image_url'])], ';', '"', '');
    }
    rewind($fh);
    return "\xEF\xBB\xBF" . stream_get_contents($fh);
}
