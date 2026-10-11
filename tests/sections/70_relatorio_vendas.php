<?php
/* Relatório de vendas com dados reais (includes/sales_report.php + api/insights.php?kind=sales). Os números são conferidos à mão. */

require_once dirname(__DIR__, 2) . '/includes/stock_lib.php';
require_once dirname(__DIR__, 2) . '/includes/sales_report.php';

section('Relatório de vendas: ticket médio, evolução, parados, margem e rotação (dados reais)', function () {
    cleanup_test_users();
    $pdo = db();
    $tag = 'teste-rv-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Dono RV', ?, 'x', 'owner', 'active')")->execute(["$tag@lumina.test"]);
    $tid = (int)$pdo->lastInsertId();
    $mk = fn(string $n, float $price, float $cost, int $qty, ?string $cat = null) => product_create($pdo, $tid, $tid, ['name' => $n, 'category' => $cat, 'sale_price' => $price, 'cost_price' => $cost, 'minimum_stock' => 0, 'variants' => [['quantity' => $qty]]], null);
    $pA = $mk('Prod A', 10, 4, 20); $pB = $mk('Prod B', 20, 0, 5); $pC = $mk('Prod C', 5, 2, 10); $pD = $mk('Prod D', 8, 3, 7);
    $mk('Prod E zero', 9, 3, 0); $arq = $mk('Prod F arquivado', 9, 3, 4); product_delete($pdo, $tid, (int)$arq['id']);
    $vid = fn(array $p) => (int)$pdo->query('SELECT id FROM product_variants WHERE product_id = ' . (int)$p['id'])->fetchColumn();
    $sell = fn(array $p, int $qty, string $when) => sale_create($pdo, $tid, $tid, true, ['variant_id' => $vid($p), 'quantity' => $qty, 'sold_at' => $when]);
    $s1 = $sell($pA, 2, '2026-10-01 10:00'); $s2 = $sell($pB, 1, '2026-10-03 11:00'); $s3 = $sell($pC, 3, '2026-10-03 15:30'); $s4 = $sell($pA, 1, '2026-10-05 09:00');

    $w = "user_id = ? AND sold_at >= ? AND sold_at < ? AND status = 'completed'";
    $rep = function (string $from, string $to) use ($pdo, $tid, $w) {
        $f = new DateTime($from); $t = new DateTime($to);
        return sales_report_extras($pdo, $tid, $f, $t, max(1, (int)$f->diff($t)->days), $w, [$tid, $f->format('Y-m-d H:i:s'), $t->format('Y-m-d H:i:s')]);
    };
    $r = $rep('2026-10-01', '2026-10-08');

    check('4 vendas e ticket médio = 65 ÷ 4 = 16,25', $r['orders'] === 4 && $r['avg_ticket'] === 16.25, json_encode([$r['orders'], $r['avg_ticket']]));
    $pts = array_column($r['series']['points'], null, 'date');
    check('evolução diária: 7 dias, com os dias sem vendas a zero', $r['series']['grain'] === 'day' && count($pts) === 7 && $pts['2026-10-02']['revenue'] === 0.0 && $pts['2026-10-02']['orders'] === 0);
    check('dia 1: 20 € / 2 un.; dia 3: 35 € em 2 vendas; dia 5: 10 €', $pts['2026-10-01']['revenue'] === 20.0 && $pts['2026-10-01']['qty'] === 2 && $pts['2026-10-03']['revenue'] === 35.0 && $pts['2026-10-03']['orders'] === 2 && $pts['2026-10-05']['revenue'] === 10.0);
    check('a soma da evolução bate certo com a receita (65 €)', abs(array_sum(array_column($r['series']['points'], 'revenue')) - 65.0) < 0.001);
    check('parados: só o Prod D (7 un. × 3 € = 21 € parados); sem estoque e arquivados não entram', count($r['slow']) === 1 && $r['slow'][0]['name'] === 'Prod D' && $r['slow'][0]['tied_capital'] === 21.0 && $r['slow'][0]['last_sale'] === null, json_encode($r['slow']));
    $m = $r['margin'];
    check('margem estimada: só com custo conhecido (A e C): receita 45, custo 18, margem 27 = 60 %', $m['estimate'] === true && $m['revenue'] === 45.0 && $m['cost'] === 18.0 && $m['margin'] === 27.0 && $m['margin_pct'] === 60.0, json_encode($m));
    check('cobertura da margem = 45 ÷ 65 = 69,2 % (o Prod B não tem custo)', $m['coverage'] === 69.2);
    check('com 3 produtos vendidos não se mostram «menos vendidos» (seria repetir o top)', $r['least'] === []);

    $none = $rep('2026-10-06', '2026-10-08');
    check('período sem vendas: tudo a zero/vazio, nada inventado', $none['orders'] === 0 && $none['avg_ticket'] === null && $none['margin'] === null && array_sum(array_column($none['series']['points'], 'revenue')) == 0 && count($none['series']['points']) === 2);
    check('período sem vendas: todos os produtos com estoque ficam «parados», o de mais capital primeiro (A 17×4=68, D 21, C 7×2=14, B 0)', array_column($none['slow'], 'name') === ['Prod A', 'Prod D', 'Prod C', 'Prod B'], json_encode(array_column($none['slow'], 'name')));
    check('parado traz a data da última venda de sempre (A: 5 out)', str_starts_with((string)$none['slow'][0]['last_sale'], '2026-10-05'));

    sale_cancel($pdo, $tid, (int)$s4['id']);
    $r2 = $rep('2026-10-01', '2026-10-08');
    check('venda anulada deixa de contar: 3 vendas, 55 €, ticket 18,33', $r2['orders'] === 3 && $r2['avg_ticket'] === 18.33 && array_sum(array_column($r2['series']['points'], 'revenue')) == 55.0);

    // menos vendidos: só com mais de 5 produtos vendidos
    foreach (['G', 'H', 'I'] as $i => $n) { $p = $mk("Prod $n", 3, 1, 9); $sell($p, $i + 1, '2026-10-04 12:00'); }
    $r3 = $rep('2026-10-01', '2026-10-08');
    check('com 6 produtos vendidos aparecem os 5 menos vendidos, do menor para o maior', count($r3['least']) === 5 && $r3['least'][0]['qty'] <= $r3['least'][1]['qty'] && $r3['least'][0]['qty'] === 1, json_encode($r3['least']));

    // agrupamento por semana/mês
    $wk = $rep('2026-01-01', '2026-04-11');
    $mo = $rep('2025-01-01', '2026-10-08');
    check('mais de 62 dias → pontos por semana (segundas-feiras); mais de 400 → por mês',
        $wk['series']['grain'] === 'week' && (new DateTime($wk['series']['points'][1]['date']))->format('N') === '1' && $mo['series']['grain'] === 'month' && str_ends_with($mo['series']['points'][0]['date'], '-01'));
    check('na vista semanal a receita total continua certa', abs(array_sum(array_column($rep('2026-09-01', '2026-11-15')['series']['points'], 'revenue')) - (float)$pdo->query("SELECT SUM(amount) FROM sales WHERE user_id = $tid AND status = 'completed'")->fetchColumn()) < 0.001);

    // rotação
    $st = sales_add_sell_through([['qty' => 3, 'stock' => 17], ['qty' => 5, 'stock' => null], ['qty' => 0, 'stock' => 0], ['qty' => 10, 'stock' => 0]]);
    check('rotação = vendidas ÷ (vendidas + estoque): 15 %; sem estoque conhecido ou sem nada → vazio; esgotado → 100 %', $st[0]['sell_through'] === 15.0 && $st[1]['sell_through'] === null && $st[2]['sell_through'] === null && $st[3]['sell_through'] === 100.0);

    // outro negócio nunca entra nas contas
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status) VALUES ('Outro', ?, 'x', 'owner', 'active')")->execute(["$tag-o@lumina.test"]);
    $o = (int)$pdo->lastInsertId();
    $ro = sales_report_extras($pdo, $o, new DateTime('2026-10-01'), new DateTime('2026-10-08'), 7, $w, [$o, '2026-10-01 00:00:00', '2026-10-08 00:00:00']);
    check('outro negócio: sem vendas, sem produtos parados, sem margem (isolamento)', $ro['orders'] === 0 && $ro['slow'] === [] && $ro['margin'] === null);
});

section('Relatório de vendas: API (api/insights.php?kind=sales)', function () {
    cleanup_test_users();
    $tag = 'teste-ra-' . bin2hex(random_bytes(3));
    $c = new Client(); $c->register('Teste Relatorio', "$tag@lumina.test", 'palavra-passe-1');
    $tid = (int)db()->query("SELECT id FROM users WHERE email='$tag@lumina.test'")->fetchColumn();
    db()->prepare("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) VALUES (?, 'Loja QA', 'general', NOW()) ON DUPLICATE KEY UPDATE onboarding_done_at = NOW()")->execute([$tid]);
    $p = product_create(db(), $tid, $tid, ['name' => 'Camisa API', 'sale_price' => 30, 'cost_price' => 10, 'variants' => [['quantity' => 10]]], null);
    $v = (int)db()->query('SELECT id FROM product_variants WHERE product_id = ' . (int)$p['id'])->fetchColumn();
    sale_create(db(), $tid, $tid, true, ['variant_id' => $v, 'quantity' => 2]);
    product_create(db(), $tid, $tid, ['name' => 'Parada API', 'sale_price' => 5, 'cost_price' => 1, 'variants' => [['quantity' => 3]]], null);

    [$s, $d] = $c->req('GET', 'api/insights.php?kind=sales&period=month');
    $rep = $d['report'] ?? [];
    check('a API devolve o relatório: 1 venda, ticket 60 €, margem 40 € (66,7 %), 1 produto parado', $s === 200 && ($rep['orders'] ?? 0) === 1 && ($rep['avg_ticket'] ?? 0) == 60 && ($rep['margin']['margin'] ?? 0) == 40 && ($rep['margin']['margin_pct'] ?? 0) == 66.7 && count($rep['slow'] ?? []) === 1 && ($rep['slow'][0]['name'] ?? '') === 'Parada API', json_encode($rep, JSON_UNESCAPED_UNICODE));
    check('o top traz a rotação: 2 vendidas e 8 em estoque = 20 %', ($d['top'][0]['sell_through'] ?? null) == 20, json_encode($d['top'][0] ?? null));
    check('a evolução do mês tem um ponto por dia desde o dia 1', ($rep['series']['grain'] ?? '') === 'day' && ($rep['series']['points'][0]['date'] ?? '') === date('Y-m-01'));
    [$s2, $d2] = $c->req('GET', 'api/insights.php?kind=orders&period=month');
    check('o painel de pedidos (restaurante) não leva o relatório de vendas', $s2 === 200 && !isset($d2['report']));
    $f = new Client(); $f->register('Teste Sem Perm', "$tag-x@lumina.test", 'palavra-passe-1');
    [$s3, $d3] = $f->req('GET', 'api/insights.php?kind=sales&period=month');
    check('outro negócio vê o seu relatório vazio, não o de outro', $s3 === 200 && ($d3['totals']['lines'] ?? -1) === 0 && !str_contains(json_encode($d3), 'Camisa API'));
});
