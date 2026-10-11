<?php
/* =========================================================================
   RELATÓRIO DE VENDAS COM DADOS REAIS  (includes/sales_report.php)
   -------------------------------------------------------------------------
   Números extra do painel «Produtos mais vendidos» (api/insights.php, kind=sales). TUDO sai da tabela `sales` (vendas concluídas; as anuladas e os
   dados de exemplo ficam como no resto do painel) e de `products`. Nada é inventado: sem vendas devolve zeros ou listas vazias.
     avg_ticket   receita ÷ nº de vendas (uma venda com várias linhas conta 1)
     series       receita, unidades e vendas por dia (≤ 62 dias), semana (≤ 400 dias) ou mês
     slow         produtos COM estoque e SEM nenhuma venda no período («parados»), os de mais capital parado primeiro (capital = estoque × custo atual; sem custo conhecido fica vazio)
     least        os menos vendidos entre os que venderam (só se houver mais de 5 produtos vendidos)
     margin       ESTIMATIVA: receita − unidades × custo ATUAL do produto, só nas vendas cujo produto tem custo > 0. A venda não guarda o custo da data,
                  por isso se o custo mudou depois, a margem antiga muda também. `coverage` diz que parte da receita tem custo conhecido.
     sell_through unidades vendidas ÷ (vendidas + estoque atual) do produto: aproximação da rotação (não há histórico do estoque médio)
   ========================================================================= */
declare(strict_types=1);

/** Início do «balde» de uma data: o próprio dia, a segunda-feira da semana ou o 1.º do mês. */
function sales_bucket_start(string $ymd, string $grain): string
{
    $d = new DateTime($ymd);
    if ($grain === 'week') { $d->modify('monday this week'); }
    elseif ($grain === 'month') { $d->modify('first day of this month'); }
    return $d->format('Y-m-d');
}

/**
 * @param string $w     condição WHERE de sales_where() (user_id, sold_at >= ?, sold_at < ?, status, filtros)
 * @param array  $wargs argumentos dessa condição, por ordem
 * @param array  $f     filtros do pedido (category, brand: aplicados também aos produtos parados)
 */
function sales_report_extras(PDO $pdo, int $tid, DateTime $from, DateTime $to, int $days, string $w, array $wargs, array $f = []): array
{
    $q = function (string $sql, array $args) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); };

    $t = $q("SELECT COALESCE(SUM(amount),0) revenue, COALESCE(SUM(quantity),0) qty, COUNT(DISTINCT COALESCE(sale_order_id, -id)) orders FROM sales WHERE $w", $wargs)[0];
    $revenue = (float)$t['revenue']; $orders = (int)$t['orders'];
    $out = ['orders' => $orders, 'avg_ticket' => $orders > 0 ? round($revenue / $orders, 2) : null];

    // evolução
    $grain = $days <= 62 ? 'day' : ($days <= 400 ? 'week' : 'month');
    $rows = $q("SELECT DATE(sold_at) d, SUM(quantity) qty, SUM(amount) revenue, COUNT(DISTINCT COALESCE(sale_order_id, -id)) orders FROM sales WHERE $w GROUP BY DATE(sold_at)", $wargs);
    $buckets = [];
    $cursor = new DateTime($from->format('Y-m-d'));
    $end = new DateTime($to->format('Y-m-d'));
    while ($cursor < $end) { $buckets[sales_bucket_start($cursor->format('Y-m-d'), $grain)] ??= ['date' => sales_bucket_start($cursor->format('Y-m-d'), $grain), 'qty' => 0, 'revenue' => 0.0, 'orders' => 0]; $cursor->modify('+1 day'); }
    foreach ($rows as $r) {
        $k = sales_bucket_start((string)$r['d'], $grain);
        $buckets[$k] ??= ['date' => $k, 'qty' => 0, 'revenue' => 0.0, 'orders' => 0];
        $buckets[$k]['qty'] += (int)$r['qty']; $buckets[$k]['revenue'] = round($buckets[$k]['revenue'] + (float)$r['revenue'], 2); $buckets[$k]['orders'] += (int)$r['orders'];
    }
    ksort($buckets);
    $out['series'] = ['grain' => $grain, 'points' => array_values($buckets)];

    // parados: com estoque e sem vendas no período
    $pw = "p.user_id = ? AND p.status <> 'archived' AND p.stock_quantity > 0"; $pa = [$tid];
    foreach (['category', 'brand'] as $k) if (!empty($f[$k])) { $pw .= " AND p.$k = ?"; $pa[] = $f[$k]; }
    $slow = $q("SELECT p.id, p.name, p.brand, p.stock_quantity stock, p.cost_price cost,
                       (SELECT MAX(s.sold_at) FROM sales s WHERE s.product_id = p.id AND s.status = 'completed') last_sale
                  FROM products p
                 WHERE $pw AND NOT EXISTS (SELECT 1 FROM sales s WHERE s.product_id = p.id AND s.status = 'completed' AND s.sold_at >= ? AND s.sold_at < ?)
                 ORDER BY p.stock_quantity * p.cost_price DESC, p.stock_quantity DESC, p.name LIMIT 10",
        array_merge($pa, [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]));
    $out['slow'] = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'brand' => $r['brand'], 'stock' => (int)$r['stock'], 'tied_capital' => (float)$r['cost'] > 0 ? round((int)$r['stock'] * (float)$r['cost'], 2) : null, 'last_sale' => $r['last_sale']], $slow);

    // menos vendidos (entre os que venderam)
    $sold = $q("SELECT product_name name, SUM(quantity) qty, SUM(amount) revenue FROM sales WHERE $w GROUP BY product_name ORDER BY qty ASC, revenue ASC, product_name", $wargs);
    $out['least'] = count($sold) > 5 ? array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'revenue' => (float)$r['revenue']], array_slice($sold, 0, 5)) : [];

    // margem estimada
    $m = $q("SELECT COALESCE(SUM(CASE WHEN p.cost_price > 0 THEN x.a END),0) rev_cov, COALESCE(SUM(CASE WHEN p.cost_price > 0 THEN x.q * p.cost_price END),0) cost
              FROM (SELECT product_id, SUM(quantity) q, SUM(amount) a FROM sales WHERE $w GROUP BY product_id) x JOIN products p ON p.id = x.product_id", $wargs)[0];
    $revCov = (float)$m['rev_cov']; $cost = (float)$m['cost'];
    $out['margin'] = $revCov > 0
        ? ['estimate' => true, 'revenue' => round($revCov, 2), 'cost' => round($cost, 2), 'margin' => round($revCov - $cost, 2), 'margin_pct' => round(($revCov - $cost) / $revCov * 100, 1),
           'coverage' => $revenue > 0 ? round($revCov / $revenue * 100, 1) : 0.0]
        : null;
    return $out;
}

/** Rotação aproximada de cada produto da lista `top` (que já traz `stock`). */
function sales_add_sell_through(array $top): array
{
    foreach ($top as &$t) {
        $stock = $t['stock'] ?? null;
        $t['sell_through'] = ($stock !== null && ($t['qty'] + $stock) > 0) ? round($t['qty'] / ($t['qty'] + $stock) * 100, 1) : null;
    }
    return $top;
}
