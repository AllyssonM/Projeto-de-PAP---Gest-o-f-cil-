<?php
/* =========================================================================
   API DOS PAINÉIS POR RAMO  (api/insights.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  os números dos três painéis inteligentes e o registo dos dados que os alimentam.
     RESTAURANTE        "Mais pedidos"                     (kind=orders)
     LOJA DE ROUPAS     "Produtos mais vendidos"           (kind=sales)
     MOTORISTA/MOTO     "Distância e custo-benefício"      (kind=trips)
   AÇÕES:
     GET  ?kind=orders|sales|trips&period=day|week|month|custom&from=&to=&...filtros
     POST action=sale_add | trip_add          registar uma venda/pedido ou uma viagem
     POST action=sale_delete | trip_delete    (só o dono)
     POST action=demo_load | demo_clear       dados de EXEMPLO (marcados is_demo=1; só o dono)
   PERMISSÕES: ver os painéis exige "Visão geral e fluxo de caixa" (são números financeiros);
     registar vendas/viagens exige "Estoque" ou "Fluxo de caixa", por isso um funcionário de
     entrada de dados regista sem ver os totais.
   SEM GPS: as viagens são registadas à mão (distância, combustível, custos). Este sistema
     NÃO recolhe localização nenhuma.
   COMPARAÇÃO: cada painel compara o período escolhido com o período anterior do mesmo tamanho.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock_lib.php';

$user = require_login();
$uid = (int)$user['id'];
$tid = (int)$user['tenant_id'];
$isOwner = ($user['role'] ?? '') === 'owner';
$canView = can($user, 'cashflow');
$canEnter = can($user, 'cashflow') || can($user, 'stock');
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();

function today_dt(): DateTime { return new DateTime('today', app_timezone()); }
function dstr(DateTime $d): string { return $d->format('Y-m-d'); }
function clean_text(mixed $v, int $max): ?string { $t = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>]+/u', ' ', (string)$v) ?? ''), 0, $max); return $t === '' ? null : $t; }

/** Período pedido -> [de, até(exclusivo), início do período anterior, rótulo]. */
function resolve_period(): array
{
    $p = (string)($_GET['period'] ?? 'week');
    $today = today_dt();
    if ($p === 'day') { $from = clone $today; $to = (clone $today)->modify('+1 day'); }
    elseif ($p === 'month') { $from = new DateTime($today->format('Y-m-01'), app_timezone()); $to = (clone $today)->modify('+1 day'); }
    elseif ($p === 'custom') {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : $today->format('Y-m-01');
        $t = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : $today->format('Y-m-d');
        if ($t < $f) [$f, $t] = [$t, $f];
        $from = new DateTime($f, app_timezone()); $to = (new DateTime($t, app_timezone()))->modify('+1 day');
    } else { $p = 'week'; $from = (clone $today)->modify('monday this week'); $to = (clone $today)->modify('+1 day'); }
    $days = max(1, (int)$from->diff($to)->days);
    $prevFrom = (clone $from)->modify("-{$days} days");
    return [$p, $from, $to, $prevFrom, $days];
}

/** Variação percentual (null se antes era zero). */
function pct_change(float $now, float $before): ?float { return $before > 0 ? round(($now - $before) / $before * 100, 1) : null; }

/* ---------------------------- VENDAS / PEDIDOS ---------------------------- */
function sales_where(array $f): array
{
    $w = "user_id = ? AND sold_at >= ? AND sold_at < ? AND status = 'completed'"; $args = [];   // vendas anuladas não contam
    foreach (['category', 'brand', 'size', 'color'] as $k) if (!empty($f[$k])) { $w .= " AND $k = ?"; $args[] = $f[$k]; }
    return [$w, $args];
}

function sales_overview(int $tid, DateTime $from, DateTime $to, DateTime $prevFrom, int $days, array $f, string $kind): array
{
    [$w, $extra] = sales_where($f);
    $a = [$tid, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];
    $b = [$tid, $prevFrom->format('Y-m-d H:i:s'), $from->format('Y-m-d H:i:s')];
    $pdo = db();
    $q = fn(string $sql, array $args) => (function () use ($pdo, $sql, $args) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); })();

    $tot = $q("SELECT COALESCE(SUM(quantity),0) qty, COALESCE(SUM(amount),0) revenue, COUNT(*) n_lines, SUM(is_demo) demo FROM sales WHERE $w", array_merge($a, $extra))[0];
    $prev = $q("SELECT COALESCE(SUM(quantity),0) qty, COALESCE(SUM(amount),0) revenue FROM sales WHERE $w", array_merge($b, $extra))[0];
    $top = $q("SELECT product_name name, SUM(quantity) qty, SUM(amount) revenue FROM sales WHERE $w GROUP BY product_name ORDER BY qty DESC, revenue DESC LIMIT 5", array_merge($a, $extra));
    $prevByName = [];
    foreach ($q("SELECT product_name name, SUM(quantity) qty FROM sales WHERE $w GROUP BY product_name", array_merge($b, $extra)) as $r) $prevByName[$r['name']] = (float)$r['qty'];

    // estoque atual dos mais vendidos (por nome do produto) e recomendações de reposição
    $recs = [];
    foreach ($top as &$t) {
        $t['qty'] = (int)$t['qty']; $t['revenue'] = (float)$t['revenue']; $t['prev_qty'] = (int)($prevByName[$t['name']] ?? 0); $t['change'] = pct_change($t['qty'], $t['prev_qty']);
        $s = $q('SELECT stock_quantity, minimum_stock FROM products WHERE user_id = ? AND name = ? AND status <> \'archived\' LIMIT 1', [$tid, $t['name']])[0] ?? null;
        $t['stock'] = $s ? (int)$s['stock_quantity'] : null;
        $perDay = $t['qty'] / $days;
        $t['days_left'] = ($s && $perDay > 0) ? round($s['stock_quantity'] / $perDay, 1) : null;
        if ($s && ($s['stock_quantity'] <= $s['minimum_stock'] || ($t['days_left'] !== null && $t['days_left'] < 7))) {
            $recs[] = ['product' => $t['name'], 'stock' => (int)$s['stock_quantity'], 'days_left' => $t['days_left'], 'text' => $t['days_left'] !== null
                ? "Repor {$t['name']}: restam {$s['stock_quantity']} un. (≈ {$t['days_left']} dias ao ritmo atual)." : "Repor {$t['name']}: está abaixo do estoque mínimo."];
        }
    }
    unset($t);

    $hour = $q("SELECT HOUR(sold_at) h, SUM(quantity) qty, COUNT(*) orders FROM sales WHERE $w GROUP BY HOUR(sold_at) ORDER BY qty DESC, h LIMIT 1", array_merge($a, $extra))[0] ?? null;
    $by = fn(string $col) => array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']],
        $q("SELECT $col name, SUM(quantity) qty, SUM(amount) revenue FROM sales WHERE $w AND $col IS NOT NULL GROUP BY $col ORDER BY qty DESC, revenue DESC LIMIT 5", array_merge($a, $extra)));
    $out = [
        'totals' => ['qty' => (int)$tot['qty'], 'revenue' => (float)$tot['revenue'], 'lines' => (int)$tot['n_lines'], 'prev_qty' => (int)$prev['qty'], 'prev_revenue' => (float)$prev['revenue'],
                     'qty_change' => pct_change((float)$tot['qty'], (float)$prev['qty']), 'revenue_change' => pct_change((float)$tot['revenue'], (float)$prev['revenue'])],
        'top' => $top, 'top_product' => $top[0] ?? null,
        'peak_hour' => $hour ? ['hour' => (int)$hour['h'], 'qty' => (int)$hour['qty'], 'orders' => (int)$hour['orders']] : null,
        'categories' => $by('category'), 'recommendations' => $recs, 'has_demo' => (int)$tot['demo'] > 0,
    ];
    if ($kind === 'sales') {
        $out['brands'] = $by('brand'); $out['sizes'] = $by('size'); $out['colors'] = $by('color');
        // "Produtos mais vendidos": cada variação (nome + marca + tamanho + cor) separada, da que mais saiu para a que menos saiu
        $out['ranking'] = array_map(fn($r) => ['name' => $r['name'], 'brand' => $r['brand'], 'size' => $r['size'], 'color' => $r['color'], 'qty' => (int)$r['qty'],
                'revenue' => (float)$r['revenue'], 'last_sale' => $r['last_sale']],
            $q("SELECT product_name name, brand, size, color, SUM(quantity) qty, SUM(amount) revenue, MAX(sold_at) last_sale FROM sales WHERE $w
                GROUP BY product_name, brand, size, color ORDER BY qty DESC, revenue DESC, last_sale DESC LIMIT 50", array_merge($a, $extra)));
    }
    return $out;
}

/* ---------------------------- VIAGENS ---------------------------- */
function trips_overview(int $tid, DateTime $from, DateTime $to, DateTime $prevFrom, array $f): array
{
    $pdo = db();
    $w = 'user_id = ? AND trip_date >= ? AND trip_date < ?'; $extra = [];
    if (!empty($f['vehicle'])) { $w .= ' AND vehicle = ?'; $extra[] = $f['vehicle']; }
    $a = [$tid, $from->format('Y-m-d'), $to->format('Y-m-d')]; $b = [$tid, $prevFrom->format('Y-m-d'), $from->format('Y-m-d')];
    $q = function (string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };
    $agg = "COUNT(*) trips, COALESCE(SUM(distance_km),0) km, COALESCE(SUM(fuel_liters),0) liters, COALESCE(SUM(fuel_cost),0) fuel, COALESCE(SUM(other_costs),0) other, COALESCE(SUM(revenue),0) revenue,
            COALESCE(SUM(CASE WHEN fuel_liters > 0 THEN distance_km END),0) km_fuel, SUM(is_demo) demo";
    $t = $q("SELECT $agg FROM trips WHERE $w", array_merge($a, $extra))[0];
    $p = $q("SELECT $agg FROM trips WHERE $w", array_merge($b, $extra))[0];
    $km = (float)$t['km']; $cost = (float)$t['fuel'] + (float)$t['other'];
    $pkm = (float)$p['km']; $pcost = (float)$p['fuel'] + (float)$p['other'];
    $costKm = $km > 0 ? $cost / $km : null; $pCostKm = $pkm > 0 ? $pcost / $pkm : null;

    $routes = array_map(function ($r) {
        $km = (float)$r['km']; $cost = (float)$r['fuel'] + (float)$r['other']; $rev = (float)$r['revenue'];
        return ['route' => $r['route'], 'trips' => (int)$r['trips'], 'km' => $km, 'cost' => round($cost, 2), 'revenue' => round($rev, 2), 'net' => round($rev - $cost, 2),
                'cost_km' => $km > 0 ? round($cost / $km, 3) : null, 'net_km' => $km > 0 ? round(($rev - $cost) / $km, 3) : null];
    }, $q("SELECT route, COUNT(*) trips, SUM(distance_km) km, SUM(fuel_cost) fuel, SUM(other_costs) other, SUM(revenue) revenue FROM trips WHERE $w GROUP BY route", array_merge($a, $extra)));
    $withCost = array_values(array_filter($routes, fn($r) => $r['cost_km'] !== null));
    usort($withCost, fn($x, $y) => $x['cost_km'] <=> $y['cost_km']);
    $withNet = array_values(array_filter($routes, fn($r) => $r['revenue'] > 0));
    usort($withNet, fn($x, $y) => $y['net_km'] <=> $x['net_km']);

    $recent = array_map(function ($r) {
        $km = (float)$r['distance_km']; $cost = (float)$r['fuel_cost'] + (float)$r['other_costs'];
        return ['id' => (int)$r['id'], 'date' => $r['trip_date'], 'vehicle' => $r['vehicle'], 'route' => $r['route'], 'km' => $km, 'cost' => round($cost, 2), 'revenue' => (float)$r['revenue'],
                'net' => round((float)$r['revenue'] - $cost, 2), 'cost_km' => $km > 0 ? round($cost / $km, 3) : null, 'demo' => (bool)$r['is_demo']];
    }, $q("SELECT id, trip_date, vehicle, route, distance_km, fuel_cost, other_costs, revenue, is_demo FROM trips WHERE $w ORDER BY trip_date DESC, id DESC LIMIT 12", array_merge($a, $extra)));
    $vehicles = array_column($q('SELECT DISTINCT vehicle FROM trips WHERE user_id = ? ORDER BY vehicle', [$tid]), 'vehicle');

    return ['totals' => ['trips' => (int)$t['trips'], 'km' => round($km, 1), 'liters' => round((float)$t['liters'], 1), 'fuel_cost' => round((float)$t['fuel'], 2), 'other_costs' => round((float)$t['other'], 2),
                         'total_cost' => round($cost, 2), 'revenue' => round((float)$t['revenue'], 2), 'net' => round((float)$t['revenue'] - $cost, 2),
                         'avg_consumption' => (float)$t['km_fuel'] > 0 ? round((float)$t['liters'] / (float)$t['km_fuel'] * 100, 2) : null,       // L/100 km (só viagens com combustível registado)
                         'cost_per_km' => $costKm !== null ? round($costKm, 3) : null, 'revenue_per_trip' => (int)$t['trips'] > 0 ? round((float)$t['revenue'] / (int)$t['trips'], 2) : null,
                         'prev_km' => round($pkm, 1), 'km_change' => pct_change($km, $pkm), 'cost_km_change' => ($costKm !== null && $pCostKm !== null) ? pct_change($costKm, $pCostKm) : null],
            'best_cost_route' => $withCost[0] ?? null, 'best_net_route' => $withNet[0] ?? null, 'routes' => array_slice($withCost, 0, 6), 'recent' => $recent, 'vehicles' => $vehicles, 'has_demo' => (int)$t['demo'] > 0];
}

/* ---------------------------- DADOS DE EXEMPLO ---------------------------- */
function demo_load(int $tid, int $uid, string $kind): int
{
    $pdo = db(); $n = 0; $now = new DateTime('now', app_timezone());
    mt_srand(2026);                                         // sempre os mesmos dados de exemplo
    if ($kind === 'trips') {
        $routes = [['Leiria–Coimbra', 72, 5.1], ['Leiria–Lisboa', 142, 6.2], ['Leiria–Marinha Grande', 18, 4.6], ['Leiria–Porto', 235, 6.0], ['Leiria–Fátima', 26, 4.8]];
        $ins = $pdo->prepare('INSERT INTO trips (user_id, vehicle, route, distance_km, fuel_liters, fuel_cost, other_costs, revenue, trip_date, is_demo, created_by) VALUES (?,?,?,?,?,?,?,?,?,1,?)');
        for ($i = 0; $i < 28; $i++) {
            [$route, $km, $cons] = $routes[$i % 5]; $km += mt_rand(-3, 6); $liters = round($km * ($cons + mt_rand(-4, 4) / 10) / 100, 2);
            $ins->execute([$tid, $i % 4 === 3 ? 'Mota Honda PCX' : 'Toyota Prius', $route, $km, $liters, round($liters * 1.74, 2), mt_rand(0, 4) ? 0 : 6.5, round($km * (0.42 + mt_rand(0, 14) / 100), 2), (clone $now)->modify('-' . intdiv($i * 25, 28) . ' days')->format('Y-m-d'), $uid]);
            $n++;
        }
        return $n;
    }
    $catalog = $kind === 'orders'
        ? [['Hambúrguer Clássico', 'Pratos', 9.5, 5], ['Francesinha', 'Pratos', 11.9, 3], ['Salada Caesar', 'Saladas', 8.5, 2], ['Cheesecake', 'Sobremesas', 4.5, 2], ['Sumo Natural', 'Bebidas', 3.2, 4], ['Café', 'Bebidas', 1.2, 6]]
        : [['T-shirt básica preta', 'T-shirts', 14.9, 5, 'Nike', 'M', 'Preto'], ['T-shirt básica branca', 'T-shirts', 14.9, 4, 'Nike', 'M', 'Branco'], ['Calças de ganga', 'Calças', 39.9, 2, 'Levi\'s', 'L', 'Azul'],
           ['Camisola de malha', 'Camisolas', 29.9, 2, 'Zara', 'M', 'Cinzento'], ['Ténis urbanos', 'Calçado', 59.9, 1, 'Adidas', '42', 'Branco'], ['Casaco corta-vento', 'Casacos', 49.9, 1, 'Nike', 'S', 'Preto']];
    $ins = $pdo->prepare('INSERT INTO sales (user_id, product_name, category, brand, size, color, quantity, amount, sold_at, is_demo, created_by) VALUES (?,?,?,?,?,?,?,?,?,1,?)');
    $weights = [];
    foreach ($catalog as $i => $c) for ($k = 0; $k < $c[3]; $k++) $weights[] = $i;
    for ($i = 0; $i < 90; $i++) {
        $c = $catalog[$weights[mt_rand(0, count($weights) - 1)]];
        $qty = mt_rand(1, $kind === 'orders' ? 3 : 2);
        $when = (clone $now)->modify('-' . mt_rand(0, 24) . ' days')->setTime($kind === 'orders' ? [12, 13, 13, 19, 20, 20, 21][mt_rand(0, 6)] : mt_rand(10, 19), mt_rand(0, 59));
        $ins->execute([$tid, $c[0], $c[1], $c[4] ?? null, $c[5] ?? null, $c[6] ?? null, $qty, round($qty * $c[2], 2), $when->format('Y-m-d H:i:s'), $uid]);
        $n++;
    }
    return $n;
}

try {
    $kind = (string)($_GET['kind'] ?? $data['kind'] ?? '');

    /* ------------------------------ LER ------------------------------ */
    if ($method === 'GET') {
        if (!$canView) json_response(['success' => false, 'error' => 'Sem permissão para ver este painel.'], 403);
        if (!in_array($kind, ['orders', 'sales', 'trips'], true)) json_response(['success' => false, 'error' => 'Painel desconhecido.'], 400);
        [$period, $from, $to, $prevFrom, $days] = resolve_period();
        $f = array_filter(['category' => clean_text($_GET['category'] ?? '', 100), 'brand' => clean_text($_GET['brand'] ?? '', 100), 'size' => clean_text($_GET['size'] ?? '', 20), 'color' => clean_text($_GET['color'] ?? '', 40), 'vehicle' => clean_text($_GET['vehicle'] ?? '', 80)]);
        $body = $kind === 'trips' ? trips_overview($tid, $from, $to, $prevFrom, $f) : sales_overview($tid, $from, $to, $prevFrom, $days, $f, $kind);
        // listas para os filtros (loja de roupas)
        $filters = [];
        if ($kind === 'sales') foreach (['category', 'brand', 'size'] as $col) { $st = db()->prepare("SELECT DISTINCT $col v FROM sales WHERE user_id = ? AND $col IS NOT NULL ORDER BY $col"); $st->execute([$tid]); $filters[$col] = array_column($st->fetchAll(), 'v'); }
        if ($kind === 'sales') {                                       // últimas vendas (para editar/anular), com as anuladas marcadas
            $st = db()->prepare("SELECT id,product_name,brand,size,color,quantity,amount,status,sold_at,(variant_id IS NOT NULL) tracked FROM sales WHERE user_id=? AND is_demo=0 ORDER BY sold_at DESC, id DESC LIMIT 15");
            $st->execute([$tid]); $body['recent'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['product_name'], 'brand' => $r['brand'], 'size' => $r['size'], 'color' => $r['color'],
                'qty' => (int)$r['quantity'], 'amount' => (float)$r['amount'], 'status' => $r['status'], 'sold_at' => $r['sold_at'], 'tracked' => (bool)$r['tracked']], $st->fetchAll());
        }
        json_response(['success' => true, 'kind' => $kind, 'period' => $period, 'range' => ['from' => dstr($from), 'to' => dstr((clone $to)->modify('-1 day')), 'days' => $days], 'filters' => $filters] + $body);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $action = (string)($data['action'] ?? '');
    if (!$canEnter) json_response(['success' => false, 'error' => 'Sem permissão.'], 403);

    /* ------------------------------ REGISTAR UMA VENDA / PEDIDO ------------------------------ */
    if ($action === 'sale_add' && $kind === 'sales') {
        // Loja: só se vendem produtos que existem e têm estoque. O estoque desce na mesma transação (includes/stock_lib.php).
        try {
            $r = sale_create(db(), (int)$tid, $uid, $isOwner, $data);
        } catch (StockError $e) { json_response(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status); }
        if (empty($r['duplicate'])) audit('sale_created', ['sale_id' => $r['id'], 'variant_id' => $r['variant_id'], 'qty' => (int)$data['quantity']], $user);
        json_response(['success' => true] + $r, empty($r['duplicate']) ? 201 : 200);
    }
    if ($action === 'sale_update' || $action === 'sale_cancel') {
        require_owner($user);
        try {
            $r = $action === 'sale_update' ? sale_update(db(), (int)$tid, $uid, $data) : sale_cancel(db(), (int)$tid, (int)($data['id'] ?? 0));
        } catch (StockError $e) { json_response(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status); }
        audit($action, ['sale_id' => $r['id']], $user);
        json_response(['success' => true] + $r);
    }
    if ($action === 'sale_add') {                                    // restaurante (pedidos): texto livre, sem estoque
        $name = clean_text($data['product_name'] ?? '', 160); $cat = clean_text($data['category'] ?? '', 100); $brand = clean_text($data['brand'] ?? '', 100); $size = clean_text($data['size'] ?? '', 20); $color = clean_text($data['color'] ?? '', 40);
        $pid = (int)($data['product_id'] ?? 0) ?: null;
        if ($pid) {                                                    // produto do catálogo: nome, categoria e campos vêm de lá (só do próprio negócio)
            $st = db()->prepare('SELECT name, category, attributes FROM products WHERE id = ? AND user_id = ?'); $st->execute([$pid, $tid]); $p = $st->fetch();
            if (!$p) json_response(['success' => false, 'error' => 'Produto não encontrado.'], 404);
            $attrs = $p['attributes'] ? (json_decode($p['attributes'], true) ?: []) : [];
            $name = $p['name']; $cat = $cat ?? ($p['category'] ?: null); $brand = $brand ?? ($attrs['marca'] ?? null); $size = $size ?? ($attrs['tamanho'] ?? null); $color = $color ?? ($attrs['cor'] ?? null);
        }
        $qty = (int)($data['quantity'] ?? 0); $amount = $data['amount'] ?? null;
        if (!$name) json_response(['success' => false, 'error' => 'Indica o produto.'], 400);
        if ($qty < 1 || $qty > 10000) json_response(['success' => false, 'error' => 'A quantidade tem de ser entre 1 e 10 000.'], 400);
        if (!is_numeric($amount) || (float)$amount < 0 || (float)$amount > 10000000) json_response(['success' => false, 'error' => 'O valor total não é válido.'], 400);
        $when = preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', (string)($data['sold_at'] ?? '')) ? str_replace('T', ' ', substr($data['sold_at'], 0, 16)) . ':00' : (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s');
        db()->prepare('INSERT INTO sales (user_id, product_id, product_name, category, brand, size, color, quantity, amount, sold_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $pid, $name, $cat, $brand, $size, $color, $qty, round((float)$amount, 2), $when, $uid]);
        json_response(['success' => true, 'id' => (int)db()->lastInsertId()], 201);
    }

    /* ------------------------------ REGISTAR UMA VIAGEM ------------------------------ */
    if ($action === 'trip_add') {
        $route = clean_text($data['route'] ?? '', 160); $vehicle = clean_text($data['vehicle'] ?? '', 80) ?? 'Veículo';
        $km = $data['distance_km'] ?? null; $date = (string)($data['trip_date'] ?? '');
        if (!$route) json_response(['success' => false, 'error' => 'Indica a rota (ex.: Leiria–Coimbra).'], 400);
        if (!is_numeric($km) || (float)$km <= 0 || (float)$km > 5000) json_response(['success' => false, 'error' => 'A distância tem de ser entre 0,1 e 5 000 km.'], 400);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = today_dt()->format('Y-m-d');
        $num = function (string $k, float $max): float { $v = $GLOBALS['data'][$k] ?? 0; return is_numeric($v) && $v >= 0 && $v <= $max ? round((float)$v, 2) : 0.0; };
        db()->prepare('INSERT INTO trips (user_id, vehicle, route, distance_km, fuel_liters, fuel_cost, other_costs, revenue, trip_date, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$tid, $vehicle, $route, round((float)$km, 1), $num('fuel_liters', 1000), $num('fuel_cost', 100000), $num('other_costs', 100000), $num('revenue', 100000), $date, $uid]);
        json_response(['success' => true, 'id' => (int)db()->lastInsertId()], 201);
    }

    /* ------------------------------ APAGAR / EXEMPLOS (só o dono) ------------------------------ */
    if (in_array($action, ['sale_delete', 'trip_delete', 'demo_load', 'demo_clear'], true)) {
        require_owner($user);
        if ($action === 'sale_delete' || $action === 'trip_delete') {
            if ($action === 'sale_delete') {                           // apagar uma venda devolve as unidades ao estoque (uma só vez)
                try { $r = sale_cancel(db(), (int)$tid, (int)($data['id'] ?? 0), true); } catch (StockError $e) { json_response(['success' => true, 'deleted' => 0]); }
                json_response(['success' => true, 'deleted' => 1, 'restored' => $r['restored']]);
            }
            $tbl = $action === 'sale_delete' ? 'sales' : 'trips';
            $st = db()->prepare("DELETE FROM $tbl WHERE id = ? AND user_id = ?"); $st->execute([(int)($data['id'] ?? 0), $tid]);
            json_response(['success' => true, 'deleted' => $st->rowCount()]);
        }
        if (!in_array($kind, ['orders', 'sales', 'trips'], true)) json_response(['success' => false, 'error' => 'Painel desconhecido.'], 400);
        $tbl = $kind === 'trips' ? 'trips' : 'sales';
        if ($action === 'demo_load') {
            $has = db()->prepare("SELECT COUNT(*) FROM $tbl WHERE user_id = ? AND is_demo = 1"); $has->execute([$tid]);
            if ((int)$has->fetchColumn() > 0) json_response(['success' => false, 'error' => 'Já tens dados de exemplo carregados.'], 409);
            $n = demo_load($tid, $uid, $kind);
            audit('demo_load', ['kind' => $kind, 'rows' => $n], $user);
            json_response(['success' => true, 'rows' => $n], 201);
        }
        $st = db()->prepare("DELETE FROM $tbl WHERE user_id = ? AND is_demo = 1"); $st->execute([$tid]);
        audit('demo_clear', ['kind' => $kind, 'rows' => $st->rowCount()], $user);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $error) {
    internal_error($error);
}
