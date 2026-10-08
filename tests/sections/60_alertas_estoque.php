<?php
/* Alertas de estoque baixo por email (includes/stock_alerts.php, bin/alertas_estoque.php). Sem servidor web: usa a base de dados e o comando.
   O envio «a sério» é provado com o driver «mail» e um «sendmail» falso (um ficheiro), por isso nada sai da máquina. */

require_once dirname(__DIR__, 2) . '/includes/stock_alerts.php';
require_once dirname(__DIR__, 2) . '/includes/stock_lib.php';

/** Corre bin/alertas_estoque.php com o email num «sendmail» falso. @return array{0:string,1:string,2:int,3:string} stdout, stderr, código, emails enviados */
function alertas_cli(string $driver, array $args = []): array
{
    $dir = sys_get_temp_dir() . '/lumina-alertas-' . bin2hex(random_bytes(4));
    mkdir($dir);
    $out = "$dir/enviados.txt";
    $db = [];
    foreach (['LUMINA_DB_HOST', 'LUMINA_DB_PORT', 'LUMINA_DB_NAME', 'LUMINA_DB_USER', 'LUMINA_DB_PASS'] as $k) { if (($v = getenv($k)) !== false) $db[$k] = $v; }
    [$o, $e, $c] = php_run(['-d', 'sendmail_path=cat >> ' . escapeshellarg($out), 'bin/alertas_estoque.php', ...$args], $db + ['LUMINA_MAIL_DRIVER' => $driver, 'LUMINA_MAIL_FROM' => 'avisos@lumina.test']);
    $sent = is_file($out) ? (string)file_get_contents($out) : '';
    @unlink($out); @rmdir($dir);
    return [$o, $e, $c, $sent];
}

/** Texto de todas as partes base64 do email (assunto não incluído). */
function alertas_corpo(string $mail): string
{
    preg_match_all('/base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $mail, $m);
    return implode("\n", array_map(fn($b) => (string)base64_decode(preg_replace('/\s+/', '', $b)), $m[1] ?? []));
}

section('Alertas de estoque: quem, o quê e sem repetir (unidades)', function () {
    cleanup_test_users();
    $pdo = db();
    $tag = 'teste-al-' . bin2hex(random_bytes(3));
    $mk = function (string $email, bool $verified = true) use ($pdo) {
        $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, email_verified_at) VALUES ('Dona Teste', ?, 'x', 'owner', 'active', " . ($verified ? 'NOW()' : 'NULL') . ')')->execute([$email]);
        return (int)$pdo->lastInsertId();
    };
    $tid = $mk("$tag-a@lumina.test");
    $prod = fn(string $n, int $qty, int $min, ?string $brand = null) => (int)product_create($pdo, $tid, $tid, ['name' => $n, 'brand' => $brand, 'sale_price' => 10, 'minimum_stock' => $min, 'variants' => [['quantity' => $qty]]], null)['id'];

    check('por omissão ninguém recebe avisos (desligado)', !in_array($tid, array_column(stock_alert_owners($pdo), 'id'), true));
    check('ativar para um email que não existe → false', stock_alert_set($pdo, "$tag-nao@lumina.test", true) === false);
    check('ativar para o dono → fica na lista', stock_alert_set($pdo, "$tag-a@lumina.test", true) && in_array($tid, array_column(stock_alert_owners($pdo), 'id'), true));
    $pdo->prepare("UPDATE users SET preferences = JSON_SET(COALESCE(preferences,'{}'), '$.theme', 'dark') WHERE id = ?")->execute([$tid]);
    stock_alert_set($pdo, "$tag-a@lumina.test", true);
    check('ativar não apaga as outras preferências', str_contains((string)$pdo->query("SELECT preferences FROM users WHERE id = $tid")->fetchColumn(), 'dark'));
    $semConf = $mk("$tag-b@lumina.test", false); stock_alert_set($pdo, "$tag-b@lumina.test", true);
    check('dono com o email por confirmar não recebe', !in_array($semConf, array_column(stock_alert_owners($pdo), 'id'), true));
    $pdo->prepare("INSERT INTO users (owner_id, name, email, password_hash, role, status, email_verified_at, preferences) VALUES (?, 'Func', ?, 'x', 'employee', 'active', NOW(), '{\"alerts\":{\"stock_email\":true}}')")->execute([$tid, "$tag-f@lumina.test"]);
    check('funcionário nunca recebe (mesmo com a preferência)', count(array_filter(stock_alert_owners($pdo), fn($o) => str_contains($o['email'], "$tag-f@"))) === 0);

    $pBaixo = $prod('Camisa Baixa', 2, 5, 'Zeta'); $pZero = $prod('Calça Zero', 0, 3); $pOk = $prod('Meias Ok', 20, 5); $pSemMin = $prod('Sem mínimo', 0, 0); $pArq = $prod('Arquivada', 0, 4);
    product_delete($pdo, $tid, $pArq);
    $cur = stock_alert_products($pdo, $tid);
    $byName = array_column($cur, 'level', 'name');
    check('em alerta: só «baixo» e «sem estoque» com mínimo definido; ok, sem mínimo e arquivados ficam de fora', $byName === ['Calça Zero' => 'out', 'Camisa Baixa' => 'low'], json_encode($byName));
    check('todos pendentes à primeira', count(stock_alert_pending($pdo, $tid, $cur)) === 2);

    [$subject, $html, $text] = stock_alert_message(['name' => 'Ana <b>x</b> Silva', 'email' => 'a@b.pt'], array_merge($cur, [['id' => 9, 'name' => '<script>alert(1)</script>', 'brand' => null, 'quantity' => 1, 'minimum' => 2, 'level' => 'low']]));
    check('a mensagem lista os produtos, escapa HTML e não traz preços nem dados de clientes', str_contains($html, 'Camisa Baixa') && str_contains($html, 'Zeta') && !str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;') && !str_contains($text . $html, '10,00') && str_contains($subject, '3'));

    // simular não grava nada
    $o = ['id' => $tid, 'name' => 'Dona Teste', 'email' => "$tag-a@lumina.test"];
    check('simular → «would_send» com 2 e nada gravado', stock_alert_run_owner($pdo, $o, true) === ['status' => 'would_send', 'items' => 2] && (int)$pdo->query("SELECT COUNT(*) FROM stock_alert_state WHERE user_id = $tid")->fetchColumn() === 0);
    $emlBefore = count(glob(dirname(__DIR__, 2) . '/storage/mail/*.eml') ?: []);
    // sem email configurado (driver log nos testes): não envia e NÃO grava
    $r = stock_alert_run_owner($pdo, $o);
    check('email em modo de teste → «unavailable», nada gravado (o aviso fica para quando o SMTP existir)', $r['status'] === 'unavailable' && (int)$pdo->query("SELECT COUNT(*) FROM stock_alert_state WHERE user_id = $tid")->fetchColumn() === 0, json_encode($r));
    check('e nada foi gravado em storage/mail em modo de teste', count(glob(dirname(__DIR__, 2) . '/storage/mail/*.eml') ?: []) === $emlBefore);
});

section('Alertas de estoque: comando, envio, sem repetir e repor (sendmail falso)', function () {
    cleanup_test_users();
    $pdo = db();
    $tag = 'teste-ac-' . bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO users (name, email, password_hash, role, status, email_verified_at) VALUES ('Dona Teste', ?, 'x', 'owner', 'active', NOW())")->execute(["$tag@lumina.test"]);
    $tid = (int)$pdo->lastInsertId();
    $make = fn(string $n, int $qty, int $min) => (int)product_create($pdo, $tid, $tid, ['name' => $n, 'sale_price' => 10, 'minimum_stock' => $min, 'variants' => [['quantity' => $qty]]], null)['id'];
    $a = $make('Produto A', 1, 5); $b = $make('Produto B', 3, 5);
    $states = fn() => $pdo->query("SELECT product_id, level FROM stock_alert_state WHERE user_id = $tid ORDER BY product_id")->fetchAll(PDO::FETCH_KEY_PAIR);

    [$o, $e, $c] = alertas_cli('log', ["--ativar=$tag@lumina.test"]);
    check('--ativar liga o aviso do dono', $c === 0 && str_contains($o, 'ativado'), $o . $e);
    [$o, $e, $c, $sent] = alertas_cli('log');
    check('driver «log»: diz que nada foi enviado, sai com 0 e não grava avisos', $c === 0 && str_contains($o, 'nada foi enviado') && $sent === '' && $states() === [], $o . $e);
    [$o, $e, $c, $sent] = alertas_cli('mail', ['--simular']);
    check('--simular: mostra que avisaria 2 produtos, sem enviar nem gravar', $c === 0 && str_contains($o, 'would_send (') && $sent === '' && $states() === [], $o . $e);

    [$o, $e, $c, $sent] = alertas_cli('mail');
    $body = alertas_corpo($sent);
    check('envio a sério (sendmail falso): 1 email com os 2 produtos', $c === 0 && str_contains($o, 'sent (2 produtos)') && substr_count($sent, 'Message-ID:') === 1 && str_contains($body, 'Produto A') && str_contains($body, 'Produto B'), $o . $e . substr($sent, 0, 200));
    check('o email saiu mascarado no ecrã (sem o endereço completo)', !str_contains($o, "$tag@lumina.test"));
    check('estado gravado: A «low», B «low»', array_values($states()) === ['low', 'low']);

    [$o, $e, $c, $sent] = alertas_cli('mail');
    check('correr outra vez → nada de novo, nenhum email repetido', $c === 0 && $sent === '' && str_contains($o, 'none (0'), $o . $e);

    $pdo->prepare('UPDATE product_variants SET quantity = 0 WHERE product_id = ?')->execute([$a]); $pdo->prepare('UPDATE products SET stock_quantity = 0 WHERE id = ?')->execute([$a]);
    [$o, , $c, $sent] = alertas_cli('mail');
    check('A piorou (baixo → sem estoque) → novo aviso só com ela', $c === 0 && str_contains($o, 'sent (1 produtos)') && $sent !== '' && ($states()[$a] ?? '') === 'out');

    $pdo->prepare('UPDATE products SET stock_quantity = 50 WHERE id = ?')->execute([$a]);
    [, , $c, $sent] = alertas_cli('mail');
    check('A reposta → sem email, e o estado dela é limpo', $c === 0 && $sent === '' && !isset($states()[$a]) && isset($states()[$b]));
    $pdo->prepare('UPDATE products SET stock_quantity = 2 WHERE id = ?')->execute([$a]);
    [$o, , , $sent] = alertas_cli('mail');
    check('A volta a baixar → avisa outra vez', str_contains($o, 'sent (1 produtos)') && $sent !== '');

    [$o, , $c] = alertas_cli('mail', ["--desativar=$tag@lumina.test"]);
    check('--desativar desliga', $c === 0 && str_contains($o, 'desativado'));
    $pdo->prepare('UPDATE products SET stock_quantity = 0 WHERE id = ?')->execute([$b]);
    [$o, , $c, $sent] = alertas_cli('mail');
    check('desligado → ninguém é avisado', $c === 0 && $sent === '' && str_contains($o, 'Nenhum dono ativou'));
    [, $e, $c] = alertas_cli('mail', ['--ativar=nao-existe@lumina.test']);
    check('--ativar com email inexistente → erro (1)', $c === 1);
});
