<?php
/* Testes da importação/exportação de produtos por CSV (includes/product_import.php + api/product_import.php). Carregado por tests/run.php. */
require_once __DIR__ . '/../../includes/product_import.php';

section('Produtos CSV: colunas, números e linhas (unidades)', function () {
    $pt = pimp_guess_mapping(['Nome', 'Marca', 'Categoria', 'SKU', 'Preço de custo', 'Preço de venda', 'Stock mínimo', 'Quantidade', 'Tamanho', 'Cor', 'Imagem']);
    check('títulos em português (exportação do Lumina) reconhecidos', $pt === ['cost_price' => 4, 'sale_price' => 5, 'minimum_stock' => 6, 'quantity' => 7, 'name' => 0, 'brand' => 1, 'category' => 2, 'sku' => 3, 'size' => 8, 'color' => 9, 'image_url' => 10], json_encode($pt));
    $en = pimp_guess_mapping(['Product Name', 'Brand', 'Price', 'Cost', 'Qty', 'Size', 'Colour', 'EAN']);
    check('títulos em inglês: «Price» é o preço de venda; «Cost» o de custo', ($en['name'] ?? -1) === 0 && ($en['sale_price'] ?? -1) === 2 && ($en['cost_price'] ?? -1) === 3 && ($en['quantity'] ?? -1) === 4 && ($en['color'] ?? -1) === 6 && ($en['sku'] ?? -1) === 7, json_encode($en));
    check('maiúsculas, acentos e espaços não atrapalham («PREÇO  de Venda», «Estoque Mínimo»)', (pimp_guess_mapping(['  PREÇO  de Venda ', 'Estoque Mínimo'])['sale_price'] ?? -1) === 0 && (pimp_guess_mapping(['x', 'Estoque Mínimo'])['minimum_stock'] ?? -1) === 1);
    check('uma coluna só serve um campo', count(array_unique(pimp_guess_mapping(['Preço', 'Preço de venda']))) === count(pimp_guess_mapping(['Preço', 'Preço de venda'])));

    foreach (['12' => 12, '0' => 0, '12,0' => 12, '12.00' => 12, '1.000' => 1000, ' 7 ' => 7, '1 000' => 1000] as $in => $want) { check("quantidade «$in» = $want", pimp_int((string)$in) === $want); }
    foreach (['1,5', '-3', 'abc', '', '1e3', '12,5', '0x10', '99999999999', '1 x 2'] as $bad) { check("quantidade inválida «$bad» é recusada", pimp_int($bad) === null); }
    foreach (['12,50' => 12.5, '1.234,56' => 1234.56, '€ 3,40' => 3.4, '0' => 0.0, '19.9' => 19.9, '10 €' => 10.0] as $in => $want) { check("preço «$in» = $want", pimp_price((string)$in) === $want); }
    foreach (['-5', '(5)', 'abc', '', '1,2,3x'] as $bad) { check("preço inválido «$bad» é recusado (nunca negativo)", pimp_price($bad) === null); }

    check('exportação: células que o Excel executaria como fórmula levam apóstrofo', pimp_csv_cell('=SUM(A1)') === "'=SUM(A1)" && pimp_csv_cell('+1') === "'+1" && pimp_csv_cell('-1+2') === "'-1+2" && pimp_csv_cell('@cmd') === "'@cmd" && pimp_csv_cell("\tx") === "'\tx");
    check('exportação: texto normal, vazio e nulo ficam como estão', pimp_csv_cell('Camisa') === 'Camisa' && pimp_csv_cell('') === '' && pimp_csv_cell(null) === '' && pimp_csv_cell('a=b') === 'a=b');

    // construção de produtos a partir de linhas
    $m = ['name' => 0, 'brand' => 1, 'sale_price' => 2, 'cost_price' => 3, 'quantity' => 4, 'size' => 5, 'color' => 6, 'sku' => 7, 'minimum_stock' => 8];
    $b = pimp_build([
        ['Camisa', 'Zeta', '29,90', '12', '5', 'M', 'Azul', 'CAM-1', '2'],        // linha 2
        ['camisa', 'zeta', '', '', '3', 'L', 'Azul', '', ''],                    // 3: mesmo produto (maiúsculas não contam), campos vazios herdam
        ['Camisa', 'Zeta', '29,90', '', '2', 'M', 'Verde', '', ''],              // 4
        ['Calça', '', '49,90', '', '10', '', '', 'CAL-1', ''],                   // 5: produto sem tamanho/cor
        ['Boné', '', 'abc', '', '1', '', '', '', ''],                            // 6: preço inválido
        ['Meia', '', '5', '', '1,5', '', '', '', ''],                            // 7: quantidade com decimais
        ['', '', '5', '', '1', '', '', '', ''],                                  // 8: sem nome
        ['Casaco', '', '', '', '1', '', '', '', ''],                             // 9: sem preço de venda
        ['Cinto', '', '10', '', '-3', '', '', '', ''],                           // 10: quantidade negativa
        ['Luva', '', '10', '', '1', '', '', 'CAL-1', ''],                        // 11: SKU repetido de outro produto do ficheiro
    ], $m);
    $names = array_column($b['products'], 'name');
    check('só entram os produtos sem nenhuma linha com erro (Camisa, Calça)', $names === ['Camisa', 'Calça'], json_encode($names));
    check('Camisa: 3 variações (M/Azul, L/Azul, M/Verde) e dados herdados (preço, custo, SKU, stock mínimo)', count($b['products']['camisa|zeta']['variants']) === 3 && $b['products']['camisa|zeta']['sale_price'] === 29.9
        && $b['products']['camisa|zeta']['cost_price'] === 12.0 && $b['products']['camisa|zeta']['sku'] === 'CAM-1' && $b['products']['camisa|zeta']['minimum_stock'] === 2);
    check('Calça: uma só variação sem tamanho nem cor, quantidade 10', count($b['products']['calça|']['variants']) === 1 && $b['products']['calça|']['variants'][0]['quantity'] === 10 && $b['products']['calça|']['variants'][0]['size'] === null);
    $lines = array_column($b['errors'], 'line');
    check('cada erro aponta a linha certa do ficheiro (6, 7, 8, 9, 10, 11)', $lines === [6, 7, 8, 9, 10, 11], json_encode($b['errors'], JSON_UNESCAPED_UNICODE));
    check('os erros dizem o motivo em português', str_contains($b['errors'][0]['reason'], 'preço de venda inválido') && str_contains($b['errors'][1]['reason'], 'número inteiro') && str_contains($b['errors'][2]['reason'], 'nome')
        && str_contains($b['errors'][3]['reason'], 'preço de venda') && str_contains($b['errors'][4]['reason'], 'número inteiro') && str_contains($b['errors'][5]['reason'], 'SKU'), json_encode($b['errors'], JSON_UNESCAPED_UNICODE));
    check('produtos ignorados por erro são contados (Boné, Meia, Casaco, Cinto, Luva = 5; a linha sem nome não tem produto)', $b['skipped'] === 5, (string)$b['skipped']);

    $c = pimp_build([['Saia', 'A', '10', '', '1', 'M', 'Rosa', '', ''], ['Saia', 'A', '12', '', '1', 'L', 'Rosa', '', ''], ['Saia', 'A', '10', '', '1', 'M', 'Rosa', '', '']], $m);
    check('mesmo produto com preço diferente → erro na linha, produto inteiro ignorado (nada de meio produto)', $c['products'] === [] && $c['errors'][0]['line'] === 3 && str_contains($c['errors'][0]['reason'], 'preço de venda é diferente do da linha 2'), json_encode($c['errors'], JSON_UNESCAPED_UNICODE));
    check('mesmo tamanho e cor repetidos → erro', str_contains($c['errors'][1]['reason'] ?? '', 'repetem os da linha 2'), json_encode($c['errors'], JSON_UNESCAPED_UNICODE));
    $d = pimp_build([['=1+1', '', '10', '', '1', '', '', '', '']], $m);
    check('texto de fórmula entra como TEXTO (não é executado nem alterado)', ($d['products']['=1+1|']['name'] ?? '') === '=1+1');
    $e = pimp_build([['Tinta', '', '10', '', '1', 'M', '', '', ''], ['Tinta', '', '10', '', '1', 'M', '', '', '']], $m);
    check('duas linhas iguais do mesmo produto (sem variação distinta) → erro', $e['products'] === [] && count($e['errors']) === 1);
});

section('Produtos CSV: pré-visualizar, importar, nunca sobrescrever, anular (API)', function () {
    cleanup_test_users();
    $tag = 'teste-pi-' . bin2hex(random_bytes(3));
    $o = new Client(); $o->register('Teste Produtos', "$tag-dono@lumina.test", 'palavra-passe-1');
    $tid = (int)db()->query("SELECT id FROM users WHERE email='$tag-dono@lumina.test'")->fetchColumn();
    db()->prepare("INSERT INTO business_profiles (user_id,business_name,business_type,onboarding_done_at) VALUES (?, 'Loja QA', 'general', NOW()) ON DUPLICATE KEY UPDATE onboarding_done_at = NOW()")->execute([$tid]);
    $count = fn(string $sql, array $p = []) => (function () use ($sql, $p) { $s = db()->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
    $products = fn() => $count('SELECT COUNT(*) FROM products WHERE user_id = ?', [$tid]);
    $moves = fn() => $count('SELECT COUNT(*) FROM stock_movements WHERE user_id = ?', [$tid]);

    // um produto que já existe: a importação nunca lhe pode tocar
    $existing = product_create(db(), $tid, $tid, ['name' => 'Calça', 'sale_price' => 99.0, 'cost_price' => 50.0, 'variants' => [['quantity' => 4]]], null);
    $before = db()->query('SELECT * FROM products WHERE id = ' . (int)$existing['id'])->fetch();

    $csv = "Nome;Marca;Categoria;SKU;Preço de custo;Preço de venda;Stock mínimo;Quantidade;Tamanho;Cor\r\n"
        . "Camisa Azul;Zeta;Roupa;CAM-1;12,00;29,90;2;5;M;Azul\r\n"
        . "Camisa Azul;Zeta;;;;;;3;L;Azul\r\n"
        . "Camisa Azul;Zeta;;;;;;2;M;Verde\r\n"
        . "Calça;;Roupa;;20,00;49,90;1;10;;\r\n"                    // já existe → ignorada
        . "Boné;;Acessórios;;5;abc;;4;;\r\n"                         // erro
        . "Cachecol;;Acessórios;;5;15,5;;1,5;;\r\n"                 // erro (quantidade)
        . "Meias Crianças;;Roupa;MEI-1;1,10;3,50;5;40;;\r\n";
    $latin1 = mb_convert_encoding($csv, 'Windows-1252', 'UTF-8');   // o Excel português grava assim

    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'preview'], 'produtos.csv', $latin1, 'text/csv');
    check('pré-visualizar (Windows-1252 com «;») → 200 e colunas reconhecidas', $s === 200 && ($d['needs_mapping'] ?? true) === false && ($d['mapping']['sale_price'] ?? -1) === 5, json_encode($d, JSON_UNESCAPED_UNICODE));
    $sum = $d['summary'] ?? [];
    check('resumo: 2 produtos novos (Camisa com 3 variações, Meias), 1 já existe, 2 linhas com erro, 2 produtos ignorados', ($sum['products'] ?? -1) === 2 && ($sum['variants'] ?? -1) === 4 && ($sum['duplicates'] ?? -1) === 1 && ($sum['errors'] ?? -1) === 2 && ($sum['skipped_products'] ?? -1) === 2, json_encode($sum));
    check('os acentos chegam certos («Meias Crianças», «Preço» lido em 1,10)', in_array('Meias Crianças', array_column($d['sample'] ?? [], 'name'), true));
    check('os erros trazem linha e motivo; o duplicado diz porquê', in_array(6, array_column($d['errors'] ?? [], 'line'), true) && in_array(7, array_column($d['errors'] ?? [], 'line'), true) && str_contains(json_encode($d['duplicates'] ?? [], JSON_UNESCAPED_UNICODE), 'já existe'));
    check('pré-visualizar NÃO grava nada (produtos e movimentos iguais)', $products() === 1 && $moves() === 1);

    [$s] = (function () use ($o, $latin1) { $c = $o->csrf; $o->csrf = 'errado'; $r = $o->upload('api/product_import.php', ['action' => 'import'], 'p.csv', $latin1); $o->csrf = $c; return $r; })();
    check('sem o token CSRF certo → 419 e nada gravado', $s === 419 && $products() === 1);
    [$s] = (new Client())->req('POST', 'api/product_import.php', ['action' => 'preview']);
    check('sem sessão → 401', $s === 401);

    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'import'], 'produtos.csv', $latin1, 'text/csv');
    $batch = (string)($d['batch'] ?? '');
    check('importar → 201 com identificador, 2 produtos e 4 variações', $s === 201 && preg_match('/^[a-f0-9]{16}$/', $batch) === 1 && ($d['created'] ?? -1) === 2 && ($d['variants'] ?? -1) === 4 && ($d['duplicates'] ?? -1) === 1, json_encode($d, JSON_UNESCAPED_UNICODE));
    check('3 produtos no total (1 que já existia + 2 novos)', $products() === 3);
    $camisa = db()->query("SELECT * FROM products WHERE user_id = $tid AND name = 'Camisa Azul'")->fetch();
    check('Camisa Azul: marca, categoria, SKU, preços, stock mínimo e total 10 (5+3+2) certos', $camisa && $camisa['brand'] === 'Zeta' && $camisa['category'] === 'Roupa' && $camisa['sku'] === 'CAM-1' && (float)$camisa['sale_price'] === 29.9 && (float)$camisa['cost_price'] === 12.0
        && (int)$camisa['minimum_stock'] === 2 && (int)$camisa['stock_quantity'] === 10 && $camisa['import_batch'] === $batch, json_encode($camisa));
    check('3 variações da camisa com as quantidades certas', $count('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$camisa['id']]) === 3 && $count("SELECT quantity FROM product_variants WHERE product_id = ? AND size = 'L' AND color = 'Azul'", [$camisa['id']]) === 3);
    check('cada variação tem o seu movimento «initial» no histórico do estoque', $count("SELECT COUNT(*) FROM stock_movements WHERE user_id = ? AND product_id = ? AND type = 'initial'", [$tid, $camisa['id']]) === 3);
    $after = db()->query('SELECT * FROM products WHERE id = ' . (int)$existing['id'])->fetch();
    check('o produto que JÁ existia (Calça) ficou exatamente igual: preço 99, custo 50, quantidade 4, sem lote', $after == $before && $after['import_batch'] === null && (int)$after['stock_quantity'] === 4 && (float)$after['sale_price'] === 99.0);
    check('o resumo do estoque conta tudo (3 produtos)', $count('SELECT COUNT(*) FROM products WHERE user_id = ? AND status = ?', [$tid, 'active']) === 3);
    check('fica registado na auditoria', $count("SELECT COUNT(*) FROM audit_log WHERE tenant_id = ? AND action = 'product_import'", [$tid]) === 1);

    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'import'], 'produtos.csv', $latin1, 'text/csv');
    check('importar o MESMO ficheiro outra vez → 409, nada duplicado', $s === 409 && $products() === 3, json_encode($d, JSON_UNESCAPED_UNICODE));

    // tudo ou nada: se um produto falhar a meio, o primeiro também não fica
    $marks = ['a|' => ['status' => 'ok', 'reason' => null], 'b|' => ['status' => 'ok', 'reason' => null]];
    $mk = fn(string $n, $price) => ['name' => $n, 'brand' => null, 'category' => null, 'sku' => null, 'sale_price' => $price, 'cost_price' => 0.0, 'minimum_stock' => 0, 'image_url' => null, 'variants' => [['size' => null, 'color' => null, 'quantity' => 1, 'line' => 2]]];
    $thrown = false;
    try { pimp_run(db(), $tid, $tid, ['a|' => $mk('Produto A tudo-ou-nada', 5.0), 'b|' => $mk('Produto B tudo-ou-nada', -1.0)], $marks); } catch (StockError $e) { $thrown = true; }
    check('falha no 2.º produto → o 1.º não fica gravado (tudo ou nada)', $thrown && $products() === 3 && $count("SELECT COUNT(*) FROM products WHERE user_id = ? AND name LIKE 'Produto % tudo-ou-nada'", [$tid]) === 0);

    // outro negócio
    $x = new Client(); $x->register('Teste Outro', "$tag-outro@lumina.test", 'palavra-passe-1');
    [$s] = $x->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => $batch]);
    check('outro negócio não consegue anular a importação (404) nem mexe nos produtos', $s === 404 && $products() === 3);
    [$s, , $rawX] = $x->req('GET', 'api/product_import.php?action=export');
    check('...e a exportação dele não traz produtos do outro negócio', $s === 200 && !str_contains($rawX, 'Camisa') && !str_contains($rawX, 'Calça') && substr_count(trim($rawX), "\n") === 0);

    // anular: o que foi mexido depois fica
    $pid2 = (int)$count("SELECT id FROM products WHERE user_id = ? AND name = 'Meias Crianças'", [$tid]);
    product_update(db(), $tid, $tid, ['id' => $pid2, 'name' => 'Meias Crianças', 'brand' => null, 'sku' => 'MEI-1', 'category' => 'Roupa', 'cost_price' => 1.10, 'sale_price' => 4.00, 'minimum_stock' => 5, 'variants' => []]);      // preço mudou
    [$s, $d] = $o->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => $batch]);
    check('anular → apaga a Camisa (intacta) e mantém as Meias (editadas), dizendo porquê', $s === 200 && ($d['deleted'] ?? -1) === 1 && count($d['kept'] ?? []) === 1 && ($d['kept'][0]['name'] ?? '') === 'Meias Crianças' && str_contains($d['kept'][0]['reason'] ?? '', 'editado'), json_encode($d, JSON_UNESCAPED_UNICODE));
    check('a Camisa e as suas variações desapareceram; a Calça (anterior) e as Meias continuam', $products() === 2 && $count('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$camisa['id']]) === 0 && $count('SELECT COUNT(*) FROM products WHERE id = ?', [$existing['id']]) === 1);
    [$s] = $o->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => $batch]);
    check('anular outra vez → continua a dizer o que ficou (Meias), sem apagar mais nada', $s === 200 && $products() === 2);
    [$s] = $o->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => 'não-é-um-lote']);
    check('identificador inválido → 400', $s === 400);

    // vendas travam a anulação
    $csv2 = "Nome;Preço de venda;Quantidade\nVendido;10;5\nNaoVendido;10;5\n";
    [, $d2] = $o->upload('api/product_import.php', ['action' => 'import'], 'p2.csv', $csv2, 'text/csv');
    $vid = (int)$count("SELECT v.id FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.user_id = ? AND p.name = 'Vendido'", [$tid]);
    $ss = 'ok'; sale_create(db(), $tid, $tid, true, ['variant_id' => $vid, 'quantity' => 1]);
    [$s, $d] = $o->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => $d2['batch'] ?? '']);
    check('um produto com vendas não é apagado; o outro sim', $s === 200 && ($d['deleted'] ?? -1) === 1 && ($d['kept'][0]['name'] ?? '') === 'Vendido' && str_contains($d['kept'][0]['reason'] ?? '', 'vendas'), "venda=$ss " . json_encode($d, JSON_UNESCAPED_UNICODE));

    // erros de ficheiro e de mapeamento
    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'preview'], 'v.csv', '', 'text/csv');
    check('ficheiro vazio → 400 com mensagem', $s === 400 && !empty($d['error']));
    [$s] = $o->upload('api/product_import.php', ['action' => 'preview'], 'b.csv', "\x00\x01\x02binario\x00", 'text/csv');
    check('ficheiro binário → 400', $s === 400);
    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'preview'], 'sem.csv', "Coisa;Outra\nx;y\n", 'text/csv');
    check('sem coluna de nome reconhecida → pede para escolher (needs_mapping), sem erro', $s === 200 && ($d['needs_mapping'] ?? false) === true && str_contains($d['message'] ?? '', 'nome'));
    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'preview', 'mapping' => json_encode(['name' => 0, 'sale_price' => 1])], 'sem.csv', "Coisa;Outra\nCaneca;7,50\n", 'text/csv');
    check('com as colunas escolhidas à mão funciona', $s === 200 && ($d['summary']['products'] ?? -1) === 1);
    [$s] = $o->upload('api/product_import.php', ['action' => 'preview', 'mapping' => json_encode(['name' => 0, 'sale_price' => 9])], 'sem.csv', "Coisa;Outra\nCaneca;7,50\n", 'text/csv');
    check('coluna fora do ficheiro no mapeamento → 400', $s === 400);
    $big = "Nome;Preço de venda\n" . str_repeat("P;1\n", 2001);
    [$s, $d] = $o->upload('api/product_import.php', ['action' => 'preview'], 'big.csv', $big, 'text/csv');
    check('mais de 2000 linhas → 400', $s === 400 && str_contains($d['error'] ?? '', '2000'));
    [$s] = $o->req('GET', 'api/product_import.php?action=preview');
    check('GET sem exportação → 405', $s === 405);

    cleanup_test_users();
});

section('Produtos CSV: exportar, ida e volta e permissões', function () {
    cleanup_test_users();
    $tag = 'teste-pe-' . bin2hex(random_bytes(3));
    $a = new Client(); $a->register('Teste Exporta', "$tag-a@lumina.test", 'palavra-passe-1');
    $tidA = (int)db()->query("SELECT id FROM users WHERE email='$tag-a@lumina.test'")->fetchColumn();
    product_create(db(), $tidA, $tidA, ['name' => 'Camisa Linho', 'brand' => 'Zeta', 'category' => 'Roupa', 'sku' => 'CL-1', 'cost_price' => 12.5, 'sale_price' => 1234.56, 'minimum_stock' => 3,
        'variants' => [['size' => 'M', 'color' => 'Azul', 'quantity' => 7], ['size' => 'L', 'color' => 'Verde', 'quantity' => 2]]], null);
    product_create(db(), $tidA, $tidA, ['name' => '=HYPERLINK("http://mau.example","clica")', 'sale_price' => 5, 'variants' => [['quantity' => 1]]], null);
    product_create(db(), $tidA, $tidA, ['name' => 'Ação "especial"; com ponto e vírgula', 'brand' => 'Café & Cª', 'sale_price' => 2.5, 'variants' => [['quantity' => 0]]], null);
    $arch = product_create(db(), $tidA, $tidA, ['name' => 'Arquivado', 'sale_price' => 1, 'variants' => [['quantity' => 1]]], null);
    product_delete(db(), $tidA, (int)$arch['id']);

    [$s, , $raw] = $a->req('GET', 'api/product_import.php?action=export');
    check('exportar → 200, UTF-8 com BOM, separador «;» e cabeçalho', $s === 200 && str_starts_with($raw, "\xEF\xBB\xBFNome;Marca;Categoria;SKU;\"Preço de custo\";\"Preço de venda\";\"Stock mínimo\";Quantidade;Tamanho;Cor;Imagem"), substr($raw, 0, 120));
    check('uma linha por variação (2 + 1 + 1 = 4) e os arquivados ficam de fora', substr_count(trim($raw), "\n") === 4 && !str_contains($raw, 'Arquivado'));
    check('preços à portuguesa («1234,56», «12,50»)', str_contains($raw, ';12,50;1234,56;3;7;M;Azul;'));
    check('a fórmula no nome sai com apóstrofo (o Excel não a executa)', str_contains($raw, "\"'=HYPERLINK(") && !preg_match('/^=HYPERLINK/m', $raw));
    $hdr = http_get_headers_with($a, 'api/product_import.php?action=export');
    check('cabeçalhos da resposta: text/csv, anexo, sem cache', str_contains($hdr[1]['content-type'] ?? '', 'text/csv') && str_contains($hdr[1]['content-disposition'] ?? '', 'attachment') && str_contains($hdr[1]['cache-control'] ?? '', 'no-store'));

    // ida e volta: o que sai pode entrar noutro negócio e fica igual
    $b = new Client(); $b->register('Teste Importa', "$tag-b@lumina.test", 'palavra-passe-1');
    $tidB = (int)db()->query("SELECT id FROM users WHERE email='$tag-b@lumina.test'")->fetchColumn();
    [$s, $d] = $b->upload('api/product_import.php', ['action' => 'import'], 'export.csv', $raw, 'text/csv');
    check('importar a exportação noutro negócio → 201 com 3 produtos e 4 variações', $s === 201 && ($d['created'] ?? -1) === 3 && ($d['variants'] ?? -1) === 4, json_encode($d, JSON_UNESCAPED_UNICODE));
    $snap = function (int $tid) {
        $rows = db()->prepare("SELECT p.name, p.brand, p.category, p.sku, p.cost_price, p.sale_price, p.minimum_stock, v.size, v.color, v.quantity FROM products p JOIN product_variants v ON v.product_id = p.id WHERE p.user_id = ? AND p.status <> 'archived' ORDER BY p.name, v.size, v.color");
        $rows->execute([$tid]);
        return array_map(fn($r) => array_map(fn($x) => is_string($x) ? ltrim($x, "'") : $x, $r), $rows->fetchAll());
    };
    check('ida e volta: mesmos nomes, marcas, preços, quantidades e variações (acentos, aspas e «;» incluídos)', $snap($tidA) === $snap($tidB), json_encode([$snap($tidA), $snap($tidB)], JSON_UNESCAPED_UNICODE));

    // permissões
    [, $t] = $a->req('POST', 'api/team.php', ['action' => 'save', 'name' => 'Func Estoque', 'email' => "$tag-f1@lumina.test", 'permissions' => ['stock']]);
    [, $t2] = $a->req('POST', 'api/team.php', ['action' => 'save', 'name' => 'Func Calendário', 'email' => "$tag-f2@lumina.test", 'permissions' => ['calendar']]);
    $emp = function (string $email, string $temp) {
        $c = new Client(); $c->login($email, $temp);
        $c->req('POST', 'api/auth.php?action=change_password', ['current_password' => $temp, 'new_password' => 'palavra-passe-9']);
        return $c;
    };
    $f1 = $emp("$tag-f1@lumina.test", $t['temp_password'] ?? 'x'); $f2 = $emp("$tag-f2@lumina.test", $t2['temp_password'] ?? 'x');
    [$s] = $f1->req('GET', 'api/product_import.php?action=export');
    check('funcionário com a permissão do Estoque pode exportar', $s === 200);
    [$s] = $f2->req('GET', 'api/product_import.php?action=export');
    check('funcionário SEM a permissão do Estoque → 403', $s === 403);
    [$s] = $f2->upload('api/product_import.php', ['action' => 'import'], 'p.csv', "Nome;Preço de venda\nX;1\n", 'text/csv');
    check('...nem importa (403) e nada é criado', $s === 403 && (int)db()->query("SELECT COUNT(*) FROM products WHERE user_id = $tidA AND name = 'X'")->fetchColumn() === 0);
    [$s, $d] = $f1->upload('api/product_import.php', ['action' => 'import'], 'p.csv', "Nome;Preço de venda\nProduto do funcionário;1\n", 'text/csv');
    check('funcionário com Estoque importa para o negócio do patrão (201)', $s === 201 && (int)db()->query("SELECT COUNT(*) FROM products WHERE user_id = $tidA AND name = 'Produto do funcionário'")->fetchColumn() === 1);
    [$s] = $f1->req('POST', 'api/product_import.php', ['action' => 'undo', 'batch' => $d['batch'] ?? '']);
    check('mas só o responsável do negócio pode anular (403)', $s === 403);
    cleanup_test_users();
});
