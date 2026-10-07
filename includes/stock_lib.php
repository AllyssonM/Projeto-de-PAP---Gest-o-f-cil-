<?php
/* =========================================================================
   ESTOQUE COM VARIAÇÕES E VENDAS LIGADAS AO ESTOQUE  (includes/stock_lib.php)
   -------------------------------------------------------------------------
   MODELO
     products           o produto (nome, referência, categoria, marca, preços, estado active|inactive|archived)
     product_variants   cada combinação tamanho+cor tem a SUA quantidade (e, se quiser, o seu preço)
                        products.stock_quantity = soma das variações ativas (mantida aqui, em sync_product)
     sale_orders        cabeçalho da venda (total, estado, quem registou, chave anti-duplicado)
     sales              itens da venda, com o RETRATO do produto no momento (nome, marca, tamanho, cor, preço)
     stock_movements    todos os movimentos (venda, anulação, ajuste), com a variação e a venda de origem

   REGRAS DE SEGURANÇA
     - Vender é SEMPRE uma transação: bloqueia a variação (FOR UPDATE), confirma que há unidades, desconta, grava a venda.
       Além disso o UPDATE só corre se "quantity >= pedido" e a base de dados recusa estoque negativo (CHECK).
     - A mesma venda não se regista duas vezes: o navegador envia uma chave única (request_key) por formulário.
     - Editar ou anular uma venda devolve/retira a diferença no estoque, também em transação. Anular duas vezes não repõe duas vezes.
     - Tudo é do negócio (tenant) de quem pede; ids de outros negócios não se alcançam.
   ========================================================================= */
declare(strict_types=1);

final class StockError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400, public array $extra = []) { parent::__construct($message); }
}

const SALE_INSUFFICIENT = 'A quantidade selecionada é superior ao stock disponível.';

function stock_text(mixed $v, int $max): ?string
{
    $t = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$v) ?? ''), 0, $max);
    return $t === '' ? null : $t;
}

function stock_int(mixed $v, string $label, int $min = 0, int $max = 1000000): int
{
    if (is_string($v)) $v = trim($v);
    if ($v === '' || $v === null || !is_numeric($v) || (float)$v != (int)(float)$v) throw new StockError("$label: indica um número inteiro.", 400);
    $n = (int)$v;
    if ($n < $min) throw new StockError($min === 0 ? "$label não pode ser negativa." : "$label tem de ser pelo menos $min.", 400);
    if ($n > $max) throw new StockError("$label é demasiado grande.", 400);
    return $n;
}

function stock_money(mixed $v, string $label, bool $required = false): ?float
{
    if ($v === null || (is_string($v) && trim($v) === '')) { if ($required) throw new StockError("$label: indica um valor.", 400); return null; }
    $s = is_string($v) ? str_replace(',', '.', trim($v)) : $v;
    if (!is_numeric($s) || (float)$s < 0 || (float)$s > 99999999.99) throw new StockError("$label não é um valor válido.", 400);
    return round((float)$s, 2);
}

/** Soma das variações ativas -> products.stock_quantity (a "cache" que o resto da aplicação lê). */
function stock_sync_product(PDO $pdo, int $tid, int $pid): int
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM product_variants WHERE product_id=? AND user_id=? AND status='active'");
    $st->execute([$pid, $tid]); $sum = (int)$st->fetchColumn();
    $pdo->prepare('UPDATE products SET stock_quantity=? WHERE id=? AND user_id=?')->execute([$sum, $pid, $tid]);
    return $sum;
}

function stock_log(PDO $pdo, int $tid, int $pid, ?int $vid, string $type, int $qty, string $reason, ?int $saleId = null): void
{
    if ($qty === 0) return;
    $pdo->prepare('INSERT INTO stock_movements (user_id,product_id,variant_id,sale_id,type,quantity,reason,occurred_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$tid, $pid, $vid, $saleId, $type, $qty, mb_substr($reason, 0, 180)]);
}

/** Variações de vários produtos de uma vez: [product_id => [variantes...]]. */
function stock_variants_map(PDO $pdo, int $tid, array $productIds): array
{
    if (!$productIds) return [];
    $in = implode(',', array_fill(0, count($productIds), '?'));
    $st = $pdo->prepare("SELECT id,product_id,size,color,price,quantity,status,updated_at FROM product_variants WHERE user_id=? AND product_id IN ($in) ORDER BY product_id, size_key, color_key, id");
    $st->execute(array_merge([$tid], array_map('intval', $productIds)));
    $map = [];
    foreach ($st->fetchAll() as $r) {
        $map[(int)$r['product_id']][] = ['id' => (int)$r['id'], 'size' => $r['size'], 'color' => $r['color'], 'price' => $r['price'] === null ? null : (float)$r['price'],
            'quantity' => (int)$r['quantity'], 'status' => $r['status'], 'updated_at' => $r['updated_at']];
    }
    return $map;
}

/** Valida UMA variação do pedido. */
function stock_variant_in(array $v): array
{
    $size = stock_text($v['size'] ?? '', 20); $color = stock_text($v['color'] ?? '', 40);
    $status = ($v['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    return ['id' => isset($v['id']) && (int)$v['id'] > 0 ? (int)$v['id'] : null, 'size' => $size, 'color' => $color,
        'quantity' => stock_int($v['quantity'] ?? 0, 'A quantidade'), 'price' => stock_money($v['price'] ?? null, 'O preço da variação'), 'status' => $status];
}

function stock_check_combos(array $variants): void
{
    $seen = [];
    foreach ($variants as $v) {
        $k = mb_strtolower(($v['size'] ?? '') . '|' . ($v['color'] ?? ''));
        if (isset($seen[$k])) throw new StockError('Há duas variações com o mesmo tamanho e cor. Junta-as numa só.', 400);
        $seen[$k] = true;
    }
}

/** Campos do produto (comuns a criar e editar). */
function stock_product_in(array $d): array
{
    $name = stock_text($d['name'] ?? '', 160);
    if (!$name) throw new StockError('Indica o nome do produto.', 400);
    $image = stock_text($d['image_url'] ?? '', 500);
    if ($image !== null && !preg_match('#^https?://[^\s]+$#i', $image)) throw new StockError('A imagem tem de ser um endereço http(s) válido.', 400);
    $status = ($d['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    return ['name' => $name, 'sku' => stock_text($d['sku'] ?? '', 60), 'category' => stock_text($d['category'] ?? '', 80), 'brand' => stock_text($d['brand'] ?? '', 100),
        'cost_price' => stock_money($d['cost_price'] ?? 0, 'O preço de custo') ?? 0.0, 'sale_price' => stock_money($d['sale_price'] ?? 0, 'O preço de venda', true),
        'minimum_stock' => stock_int($d['minimum_stock'] ?? 0, 'O stock mínimo'), 'image_url' => $image, 'status' => $status];
}

function stock_find_duplicate(PDO $pdo, int $tid, string $name, ?string $brand, int $exceptId = 0): ?array
{
    $st = $pdo->prepare("SELECT id,name,brand FROM products WHERE user_id=? AND status<>'archived' AND name=? AND COALESCE(brand,'')=? AND id<>? LIMIT 1");
    $st->execute([$tid, $name, $brand ?? '', $exceptId]);
    return $st->fetch() ?: null;
}

function stock_catch_dup(PDOException $e): never
{
    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_variant_combo')) throw new StockError('Já existe uma variação com este tamanho e cor neste produto.', 409);
    if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'chk_variant_qty')) throw new StockError('A quantidade não pode ficar negativa.', 400);
    throw $e;
}

/** Cria um produto com as suas variações. Se já existir (mesmo nome e marca) devolve 409 com o id para o editar. */
function product_create(PDO $pdo, int $tid, int $uid, array $d, ?string $legacyAttrs): array
{
    $p = stock_product_in($d);
    $variants = [];
    if (isset($d['variants']) && is_array($d['variants']) && $d['variants']) foreach ($d['variants'] as $v) $variants[] = stock_variant_in((array)$v);
    else $variants[] = stock_variant_in(['quantity' => $d['stock_quantity'] ?? 0]);           // produto sem tamanho/cor: uma só variação
    if (count($variants) > 200) throw new StockError('Demasiadas variações (máximo 200).', 400);
    stock_check_combos($variants);
    if ($dup = stock_find_duplicate($pdo, $tid, $p['name'], $p['brand']))
        throw new StockError('Já existe um produto com este nome e marca. Podes editá-lo para atualizar a quantidade, o preço ou acrescentar tamanhos e cores.', 409, ['duplicate_id' => (int)$dup['id']]);
    $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO products (user_id,name,sku,category,brand,cost_price,sale_price,stock_quantity,minimum_stock,image_url,status,attributes) VALUES (?,?,?,?,?,?,?,0,?,?,?,?)')
            ->execute([$tid, $p['name'], $p['sku'], $p['category'], $p['brand'], $p['cost_price'], $p['sale_price'], $p['minimum_stock'], $p['image_url'], $p['status'], $legacyAttrs]);
        $pid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO product_variants (user_id,product_id,size,color,price,quantity,status) VALUES (?,?,?,?,?,?,?)');
        foreach ($variants as $v) {
            $ins->execute([$tid, $pid, $v['size'], $v['color'], $v['price'], $v['quantity'], $v['status']]);
            stock_log($pdo, $tid, $pid, (int)$pdo->lastInsertId(), 'initial', $v['quantity'], 'Estoque inicial');
        }
        stock_sync_product($pdo, $tid, $pid);
        if ($own) $pdo->commit();
    } catch (PDOException $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); stock_catch_dup($e); }
    catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['id' => $pid];
}

/** Edita o produto e as suas variações: com "id" altera, sem "id" acrescenta; as que não vêm no pedido ficam como estão. */
function product_update(PDO $pdo, int $tid, int $uid, array $d): array
{
    $pid = stock_int($d['id'] ?? 0, 'Produto', 1, 2147483647);
    $p = stock_product_in($d);
    $variants = [];
    foreach ((array)($d['variants'] ?? []) as $v) $variants[] = stock_variant_in((array)$v);
    if (count($variants) > 200) throw new StockError('Demasiadas variações (máximo 200).', 400);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM products WHERE id=? AND user_id=? AND status<>'archived' FOR UPDATE"); $st->execute([$pid, $tid]);
        if (!$st->fetch()) throw new StockError('Produto não encontrado.', 404);
        if ($dup = stock_find_duplicate($pdo, $tid, $p['name'], $p['brand'], $pid))
            throw new StockError('Já existe outro produto com este nome e marca.', 409, ['duplicate_id' => (int)$dup['id']]);
        $cur = $pdo->prepare('SELECT id,size,color,quantity FROM product_variants WHERE product_id=? AND user_id=? FOR UPDATE'); $cur->execute([$pid, $tid]);
        $existing = []; foreach ($cur->fetchAll() as $r) $existing[(int)$r['id']] = $r;
        // as combinações finais (existentes não mencionadas + pedidas) não podem repetir-se
        $final = []; $touched = [];
        foreach ($variants as $v) if ($v['id']) { if (!isset($existing[$v['id']])) throw new StockError('Variação não encontrada.', 404); $touched[$v['id']] = true; }
        foreach ($existing as $id => $r) if (!isset($touched[$id])) $final[] = ['size' => $r['size'], 'color' => $r['color']];
        foreach ($variants as $v) $final[] = $v;
        stock_check_combos($final);
        $pdo->prepare('UPDATE products SET name=?,sku=?,category=?,brand=?,cost_price=?,sale_price=?,minimum_stock=?,image_url=?,status=? WHERE id=? AND user_id=?')
            ->execute([$p['name'], $p['sku'], $p['category'], $p['brand'], $p['cost_price'], $p['sale_price'], $p['minimum_stock'], $p['image_url'], $p['status'], $pid, $tid]);
        $upd = $pdo->prepare('UPDATE product_variants SET size=?,color=?,price=?,quantity=?,status=? WHERE id=? AND product_id=? AND user_id=?');
        $ins = $pdo->prepare('INSERT INTO product_variants (user_id,product_id,size,color,price,quantity,status) VALUES (?,?,?,?,?,?,?)');
        foreach ($variants as $v) {
            if ($v['id']) {
                $before = (int)$existing[$v['id']]['quantity'];
                // para não pisar uma venda em curso: o valor "expected" (o que o ecrã mostrava) tem de ser o atual, se vier
                $exp = $d['expected'][$v['id']] ?? null;
                if ($exp !== null && (int)$exp !== $before) throw new StockError('O estoque mudou entretanto (agora tem ' . $before . '). Atualizámos os dados; confirma e tenta outra vez.', 409, ['conflict' => true]);
                $upd->execute([$v['size'], $v['color'], $v['price'], $v['quantity'], $v['status'], $v['id'], $pid, $tid]);
                stock_log($pdo, $tid, $pid, $v['id'], 'adjust', $v['quantity'] - $before, 'Edição do produto');
            } else {
                $ins->execute([$tid, $pid, $v['size'], $v['color'], $v['price'], $v['quantity'], $v['status']]);
                stock_log($pdo, $tid, $pid, (int)$pdo->lastInsertId(), 'initial', $v['quantity'], 'Nova variação');
            }
        }
        stock_sync_product($pdo, $tid, $pid);
        $pdo->commit();
    } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); stock_catch_dup($e); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['id' => $pid];
}

function variant_delete(PDO $pdo, int $tid, int $vid): int
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT v.id,v.product_id,v.quantity FROM product_variants v WHERE v.id=? AND v.user_id=? FOR UPDATE'); $st->execute([$vid, $tid]);
        $v = $st->fetch(); if (!$v) throw new StockError('Variação não encontrada.', 404);
        $n = $pdo->prepare('SELECT COUNT(*) FROM product_variants WHERE product_id=? AND user_id=?'); $n->execute([$v['product_id'], $tid]);
        if ((int)$n->fetchColumn() <= 1) throw new StockError('Um produto precisa de pelo menos uma variação. Para o retirar, apaga o produto.', 409);
        stock_log($pdo, $tid, (int)$v['product_id'], null, 'adjust', -(int)$v['quantity'], 'Variação apagada');
        $pdo->prepare('DELETE FROM product_variants WHERE id=? AND user_id=?')->execute([$vid, $tid]);
        stock_sync_product($pdo, $tid, (int)$v['product_id']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return (int)$v['product_id'];
}

/** "Apagar" um produto = arquivá-lo: some das listas e das vendas, mas o histórico das vendas e movimentos mantém-se. */
function product_delete(PDO $pdo, int $tid, int $pid): void
{
    $st = $pdo->prepare("UPDATE products SET status='archived' WHERE id=? AND user_id=? AND status<>'archived'"); $st->execute([$pid, $tid]);
    if ($st->rowCount() === 0) throw new StockError('Produto não encontrado.', 404);
}

/* ============================== VENDAS ============================== */

function sale_variant_label(array $r): string { return trim(($r['size'] ?? '') . ' ' . ($r['color'] ?? '')); }

/**
 * Regista UMA venda (um item) e desconta o estoque, tudo numa transação.
 * $in: variant_id (ou product_id, se o produto só tem uma variação), quantity, amount (opcional), request_key (opcional), sold_at (só o dono)
 */
function sale_create(PDO $pdo, int $tid, int $uid, bool $isOwner, array $in): array
{
    $qty = stock_int($in['quantity'] ?? 0, 'A quantidade', 1, 10000);
    $key = isset($in['request_key']) && preg_match('/^[0-9a-f-]{16,36}$/i', (string)$in['request_key']) ? strtolower((string)$in['request_key']) : null;
    $manual = array_key_exists('amount', $in) && $in['amount'] !== null && $in['amount'] !== '';
    $amountIn = $manual ? stock_money($in['amount'], 'O valor total', true) : null;
    $soldAt = (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s');
    if ($isOwner && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string)($in['sold_at'] ?? ''))) $soldAt = str_replace('T', ' ', substr((string)$in['sold_at'], 0, 16)) . ':00';

    $find = function () use ($pdo, $tid, $key): ?array {
        if (!$key) return null;
        $st = $pdo->prepare('SELECT o.id order_id, o.total, s.id sale_id FROM sale_orders o LEFT JOIN sales s ON s.sale_order_id=o.id WHERE o.user_id=? AND o.request_key=? LIMIT 1');
        $st->execute([$tid, $key]); return $st->fetch() ?: null;
    };
    if ($dup = $find()) return ['id' => (int)$dup['sale_id'], 'order_id' => (int)$dup['order_id'], 'duplicate' => true];

    $pdo->beginTransaction();
    try {
        $vid = (int)($in['variant_id'] ?? 0);
        if ($vid <= 0 && (int)($in['product_id'] ?? 0) > 0) {                      // produto sem tamanho/cor: a única variação
            $c = $pdo->prepare("SELECT id FROM product_variants WHERE product_id=? AND user_id=? AND status='active'"); $c->execute([(int)$in['product_id'], $tid]);
            $ids = $c->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) throw new StockError('Escolhe o tamanho e a cor do produto.', 400);
            $vid = (int)$ids[0];
        }
        if ($vid <= 0) throw new StockError('Escolhe um produto da lista.', 400);
        $st = $pdo->prepare('SELECT v.id vid,v.size,v.color,v.price vprice,v.quantity,v.status vstatus,p.id pid,p.name,p.brand,p.category,p.sale_price,p.status pstatus
            FROM product_variants v JOIN products p ON p.id=v.product_id WHERE v.id=? AND v.user_id=? AND p.user_id=? FOR UPDATE');
        $st->execute([$vid, $tid, $tid]); $r = $st->fetch();
        if (!$r || $r['pstatus'] === 'archived') throw new StockError('Este produto não existe. Escolhe um da lista.', 404);
        if ($r['pstatus'] !== 'active' || $r['vstatus'] !== 'active') throw new StockError('Este produto está inativo e não pode ser vendido.', 409);
        if ((int)$r['quantity'] <= 0) throw new StockError('Este produto está esgotado.', 409, ['available' => 0]);
        if ($qty > (int)$r['quantity']) throw new StockError(SALE_INSUFFICIENT, 409, ['available' => (int)$r['quantity']]);
        $unit = $r['vprice'] !== null ? (float)$r['vprice'] : (float)$r['sale_price'];
        $amount = $amountIn ?? round($unit * $qty, 2);

        $u = $pdo->prepare('UPDATE product_variants SET quantity=quantity-? WHERE id=? AND user_id=? AND quantity>=?'); $u->execute([$qty, $vid, $tid, $qty]);
        if ($u->rowCount() !== 1) throw new StockError(SALE_INSUFFICIENT, 409);
        $pdo->prepare('INSERT INTO sale_orders (user_id,total,status,sold_at,created_by,request_key) VALUES (?,?,?,?,?,?)')->execute([$tid, $amount, 'completed', $soldAt, $uid, $key]);
        $oid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO sales (user_id,sale_order_id,product_id,variant_id,product_name,category,brand,size,color,quantity,unit_price,amount,status,sold_at,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $oid, $r['pid'], $vid, $r['name'], $r['category'], $r['brand'], $r['size'], $r['color'], $qty, $unit, $amount, 'completed', $soldAt, $uid]);
        $sid = (int)$pdo->lastInsertId();
        stock_log($pdo, $tid, (int)$r['pid'], $vid, 'sale', -$qty, 'Venda #' . $sid, $sid);
        $left = stock_sync_product($pdo, $tid, (int)$r['pid']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_sale_request') && ($dup = $find())) return ['id' => (int)$dup['sale_id'], 'order_id' => (int)$dup['order_id'], 'duplicate' => true];
        if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'chk_variant_qty')) throw new StockError(SALE_INSUFFICIENT, 409);
        throw $e;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['id' => $sid, 'order_id' => $oid, 'total' => $amount, 'unit_price' => $unit, 'product_id' => (int)$r['pid'], 'variant_id' => $vid, 'remaining' => (int)$pdo->query('SELECT quantity FROM product_variants WHERE id=' . $vid)->fetchColumn(), 'product_stock' => $left];
}

/** Carrega e bloqueia um item de venda do negócio. */
function sale_lock(PDO $pdo, int $tid, int $sid): array
{
    $st = $pdo->prepare('SELECT * FROM sales WHERE id=? AND user_id=? FOR UPDATE'); $st->execute([$sid, $tid]);
    $s = $st->fetch(); if (!$s) throw new StockError('Venda não encontrada.', 404);
    return $s;
}

/** Edita a quantidade e/ou o valor total de uma venda concluída; a diferença de unidades sai/volta ao estoque. */
function sale_update(PDO $pdo, int $tid, int $uid, array $in): array
{
    $sid = stock_int($in['id'] ?? 0, 'Venda', 1, 2147483647);
    $pdo->beginTransaction();
    try {
        $s = sale_lock($pdo, $tid, $sid);
        if ($s['status'] !== 'completed') throw new StockError('Esta venda está anulada e não pode ser editada.', 409);
        $newQty = array_key_exists('quantity', $in) ? stock_int($in['quantity'], 'A quantidade', 1, 10000) : (int)$s['quantity'];
        $delta = $newQty - (int)$s['quantity'];
        if ($s['variant_id'] && $delta !== 0) {
            $v = $pdo->prepare('SELECT id,quantity,product_id FROM product_variants WHERE id=? AND user_id=? FOR UPDATE'); $v->execute([(int)$s['variant_id'], $tid]);
            $var = $v->fetch();
            if ($var) {
                if ($delta > 0 && $delta > (int)$var['quantity']) throw new StockError(SALE_INSUFFICIENT, 409, ['available' => (int)$var['quantity']]);
                $pdo->prepare('UPDATE product_variants SET quantity=quantity-? WHERE id=? AND user_id=?')->execute([$delta, (int)$var['id'], $tid]);
                stock_log($pdo, $tid, (int)$var['product_id'], (int)$var['id'], 'sale_edit', -$delta, 'Venda #' . $sid . ' editada', $sid);
                stock_sync_product($pdo, $tid, (int)$var['product_id']);
            } elseif ($delta > 0) throw new StockError('O produto desta venda já não existe: só podes reduzir a quantidade.', 409);
        }
        if (array_key_exists('amount', $in) && $in['amount'] !== null && $in['amount'] !== '') $amount = stock_money($in['amount'], 'O valor total', true);
        else $amount = $delta !== 0 && $s['unit_price'] !== null ? round((float)$s['unit_price'] * $newQty, 2) : (float)$s['amount'];
        $pdo->prepare('UPDATE sales SET quantity=?, amount=? WHERE id=? AND user_id=?')->execute([$newQty, $amount, $sid, $tid]);
        if ($s['sale_order_id']) $pdo->prepare('UPDATE sale_orders SET total=? WHERE id=? AND user_id=?')->execute([$amount, (int)$s['sale_order_id'], $tid]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['id' => $sid, 'quantity' => $newQty, 'amount' => $amount, 'product_id' => (int)$s['product_id']];
}

/** Anula uma venda concluída e devolve as unidades ao estoque. Repetir o pedido não repõe duas vezes. */
function sale_cancel(PDO $pdo, int $tid, int $sid, bool $thenDelete = false): array
{
    $pdo->beginTransaction();
    try {
        $s = sale_lock($pdo, $tid, $sid);
        $restored = 0;
        if ($s['status'] === 'completed') {
            if ($s['variant_id']) {
                $v = $pdo->prepare('SELECT id,product_id FROM product_variants WHERE id=? AND user_id=? FOR UPDATE'); $v->execute([(int)$s['variant_id'], $tid]);
                if ($var = $v->fetch()) {
                    $pdo->prepare('UPDATE product_variants SET quantity=quantity+? WHERE id=? AND user_id=?')->execute([(int)$s['quantity'], (int)$var['id'], $tid]);
                    stock_log($pdo, $tid, (int)$var['product_id'], (int)$var['id'], 'sale_cancel', (int)$s['quantity'], 'Venda #' . $sid . ' anulada', $sid);
                    stock_sync_product($pdo, $tid, (int)$var['product_id']);
                    $restored = (int)$s['quantity'];
                }
            }
            $pdo->prepare("UPDATE sales SET status='cancelled' WHERE id=? AND user_id=?")->execute([$sid, $tid]);
            if ($s['sale_order_id']) $pdo->prepare("UPDATE sale_orders SET status='cancelled' WHERE id=? AND user_id=?")->execute([(int)$s['sale_order_id'], $tid]);
        }
        if ($thenDelete) {
            $pdo->prepare('DELETE FROM sales WHERE id=? AND user_id=?')->execute([$sid, $tid]);
            if ($s['sale_order_id']) $pdo->prepare('DELETE FROM sale_orders WHERE id=? AND user_id=?')->execute([(int)$s['sale_order_id'], $tid]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return ['id' => $sid, 'restored' => $restored, 'already' => $s['status'] !== 'completed', 'product_id' => (int)$s['product_id']];
}
