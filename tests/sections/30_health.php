<?php
/* Testes do /health (includes/health.php + health.php). Carregado por tests/run.php. */
require_once __DIR__ . '/../../includes/health.php';

section('Saúde (/health): funções puras', function () {
    // segredo
    $tok = bin2hex(random_bytes(16));
    check('segredo certo é aceite', health_token_ok($tok, $tok));
    check('segredo errado, vazio ou ausente é recusado', !health_token_ok('x' . $tok, $tok) && !health_token_ok('', $tok) && !health_token_ok(null, $tok));
    check('sem segredo configurado a vista detalhada está desligada (nem "" nem null abrem)', !health_token_ok('', null) && !health_token_ok('qualquer-coisa-comprida-123', null) && !health_token_ok('', ''));
    check('segredo configurado curto demais (< 16) nunca vale, nem enviando-o igual', !health_token_ok('curto', 'curto') && !health_token_ok('123456789012345', '123456789012345'));
    check('maiúsculas/minúsculas contam', !health_token_ok(strtoupper($tok), $tok));

    // estado global
    check('tudo ok → ok', health_overall(['app' => 'ok', 'database' => 'ok', 'storage' => 'ok']) === 'ok');
    check('base de dados em baixo → down', health_overall(['app' => 'ok', 'database' => 'fail', 'storage' => 'ok']) === 'down');
    check('armazenamento sem escrita → degraded (a app serve, mas uploads falham)', health_overall(['app' => 'ok', 'database' => 'ok', 'storage' => 'fail']) === 'degraded');
    check('cópia antiga/inexistente, disco baixo, migração em falta → degraded', health_overall(['database' => 'ok', 'backup' => 'stale']) === 'degraded' && health_overall(['database' => 'ok', 'backup' => 'none']) === 'degraded'
        && health_overall(['database' => 'ok', 'disk' => 'low']) === 'degraded' && health_overall(['database' => 'ok', 'schema' => 'fail']) === 'degraded');
    check('"unknown" (ex.: o sistema não diz o espaço em disco) não faz alarme', health_overall(['database' => 'ok', 'disk' => 'unknown']) === 'ok');
    check('down tem prioridade sobre degraded', health_overall(['database' => 'fail', 'backup' => 'stale']) === 'down');

    // migrações
    check('todas registadas → nada em falta', health_pending_migrations(['v1', 'v10', 'v11', 'v12', 'v13', 'v14', 'v16', 'v17'], []) === []);
    check('v14 sem registo mas com a tabela meta_connections → conta como aplicada (instalações antigas)', health_pending_migrations(['v10', 'v11', 'v12', 'v13', 'v16', 'v17'], ['meta_connections']) === []);
    check('v14 sem registo e sem a tabela → em falta', health_pending_migrations(['v10', 'v11', 'v12', 'v13', 'v16', 'v17'], ['users']) === ['v14']);
    check('só a v10 → faltam todas as seguintes, por ordem', health_pending_migrations(['v10'], []) === ['v11', 'v12', 'v13', 'v14', 'v16', 'v17']);
    check('a tabela de uma migração SEM sonda não a dá por aplicada', health_pending_migrations(['v10'], ['sale_orders', 'meta_connections']) === ['v11', 'v12', 'v13', 'v16', 'v17']);

    // cópias de segurança
    $now = new DateTimeImmutable('2026-10-08 12:00:00', new DateTimeZone('Europe/Lisbon'));
    $recente = health_backup_state(['last_success' => '2026-10-08T10:00:00+01:00', 'encrypted' => true], $now);
    check('cópia de há 2 h → ok, com idade e cifra', $recente['status'] === 'ok' && $recente['age_hours'] === 2.0 && $recente['encrypted'] === true, json_encode($recente));
    check('cópia de há 40 h → stale', health_backup_state(['last_success' => '2026-10-06T20:00:00+01:00'], $now)['status'] === 'stale');
    check('exatamente 36 h ainda é ok; 36 h e 1 min já é stale', health_backup_state(['last_success' => '2026-10-07T00:00:00+01:00'], $now)['status'] === 'ok' && health_backup_state(['last_success' => '2026-10-06T23:59:00+01:00'], $now)['status'] === 'stale');
    check('sem ficheiro de estado, lixo ou data inválida → none', health_backup_state(null, $now)['status'] === 'none' && health_backup_state([], $now)['status'] === 'none'
        && health_backup_state(['last_success' => 'ontem'], $now)['status'] === 'none' && health_backup_state(['last_success' => 12345], $now)['status'] === 'none');
    check('relógio adiantado (data no futuro) → idade 0, não negativa', health_backup_state(['last_success' => '2026-10-09T09:00:00+01:00'], $now)['age_hours'] === 0.0);
    $f = tempnam(sys_get_temp_dir(), 'bs');
    file_put_contents($f, '{"last_success":"2026-10-08T09:00:00+01:00","file":"x.sql.gz"}');
    check('lê o ficheiro de estado escrito por bin/backup_bd.php', (health_read_backup_status($f)['last_success'] ?? '') === '2026-10-08T09:00:00+01:00');
    file_put_contents($f, '{lixo'); check('JSON inválido → null (e depois "none")', health_read_backup_status($f) === null);
    check('ficheiro inexistente → null', health_read_backup_status($f . '.nao-existe') === null);
    @unlink($f);

    // disco
    check('espaço em disco: ok / low / critical / unknown', health_disk_state(40.0) === 'ok' && health_disk_state(10.0) === 'ok' && health_disk_state(9.9) === 'low' && health_disk_state(2.9) === 'critical' && health_disk_state(null) === 'unknown');
    $free = health_disk_free_percent(__DIR__);
    check('percentagem de espaço livre do disco real é um número entre 0 e 100', is_float($free) && $free >= 0 && $free <= 100, var_export($free, true));
    check('caminho que não existe → null (não rebenta)', health_disk_free_percent('/caminho/que/nao/existe/lumina') === null);

    // extensões
    $all = array_merge(HEALTH_REQUIRED_EXTENSIONS, HEALTH_RECOMMENDED_EXTENSIONS);
    check('extensões todas presentes → nada em falta', health_check_extensions($all) === ['missing' => [], 'recommended_missing' => []]);
    check('sem "sodium" → obrigatória em falta; sem "gd" → só recomendada', health_check_extensions(array_diff($all, ['sodium'])) === ['missing' => ['sodium'], 'recommended_missing' => []]
        && health_check_extensions(array_diff($all, ['gd'])) === ['missing' => [], 'recommended_missing' => ['gd']]);
    check('nomes em maiúsculas (PDO_MYSQL) contam', health_check_extensions(array_map('strtoupper', $all))['missing'] === []);
    check('neste PHP não falta nenhuma extensão obrigatória', health_check_extensions()['missing'] === [], json_encode(health_check_extensions()));

    // pastas de dados
    $d = sys_get_temp_dir() . '/lumina_h_' . bin2hex(random_bytes(4)); mkdir($d);
    check('pasta que existe e aceita escrita → ok', health_check_storage([$d]));
    check('pasta que não existe → falha', !health_check_storage([$d . '/nao-existe']));
    check('lista vazia → falha (nada foi verificado)', !health_check_storage([]));
    touch($d . '/ficheiro'); check('um ficheiro no lugar da pasta → falha', !health_check_storage([$d . '/ficheiro']));
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        mkdir($d . '/so-leitura'); chmod($d . '/so-leitura', 0555);
        check('pasta sem permissão de escrita → falha', !health_check_storage([$d, $d . '/so-leitura']));
        chmod($d . '/so-leitura', 0755);
    } else { echo "  (saltado: a correr como root, que escreve em qualquer pasta)\n"; }
    @unlink($d . '/ficheiro'); @rmdir($d . '/so-leitura'); @rmdir($d);

    // chave de cifra (não pode criar nada)
    $dir = sys_get_temp_dir() . '/lumina_k_' . bin2hex(random_bytes(4)); mkdir($dir);
    $valida = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    file_put_contents("$dir/boa.php", "<?php return '$valida';"); file_put_contents("$dir/ma.php", "<?php return 'abc';");
    check('chave válida em ficheiro → ok/file', health_check_crypto_key("$dir/boa.php", '') === ['status' => 'ok', 'source' => 'file']);
    check('chave inválida → fail', health_check_crypto_key("$dir/ma.php", '')['status'] === 'fail');
    check('chave em variável de ambiente → ok/env (não olha para o ficheiro)', health_check_crypto_key("$dir/nao-existe.php", 'qualquer-segredo') === ['status' => 'ok', 'source' => 'env']);
    $antes = scandir($dir);
    check('chave por criar numa pasta com escrita → ok/will_create e NÃO a cria', health_check_crypto_key("$dir/nao-existe.php", '') === ['status' => 'ok', 'source' => 'will_create'] && scandir($dir) === $antes);
    check('chave por criar numa pasta que não existe/sem escrita → fail', health_check_crypto_key("$dir/nada/nao-existe.php", '')['status'] === 'fail');
    array_map('unlink', glob("$dir/*.php")); rmdir($dir);

    // segredo: variável > ficheiro local; curto = desligado (processo isolado, para o ambiente ser exatamente o dado)
    $cfgdir = sys_get_temp_dir() . '/lumina_c_' . bin2hex(random_bytes(4)); mkdir($cfgdir);
    $doFile = 'ficheiro-' . bin2hex(random_bytes(8)); $doEnv = 'ambiente-' . bin2hex(random_bytes(8));
    file_put_contents("$cfgdir/health.local.php", "<?php return ['token' => '$doFile'];");
    $run = fn(array $env) => php_run(['-r', 'require "includes/health.php"; echo var_export(health_token($argv[1]), true);', $cfgdir], $env)[0];
    check('só ficheiro local → usa o do ficheiro', $run(['LUMINA_HEALTH_TOKEN' => '']) === "'$doFile'", $run(['LUMINA_HEALTH_TOKEN' => '']));
    check('variável de ambiente tem prioridade sobre o ficheiro', $run(['LUMINA_HEALTH_TOKEN' => $doEnv]) === "'$doEnv'");
    check('variável curta demais → desligado (não cai para o ficheiro)', $run(['LUMINA_HEALTH_TOKEN' => 'curto']) === 'NULL');
    file_put_contents("$cfgdir/health.local.php", "<?php return ['token' => 'curto'];");
    check('ficheiro com segredo curto → desligado', $run(['LUMINA_HEALTH_TOKEN' => '']) === 'NULL');
    @unlink("$cfgdir/health.local.php"); @rmdir($cfgdir);
    check('sem nada configurado → desligado', php_run(['-r', 'require "includes/health.php"; echo var_export(health_token("/nao/existe"), true);'], ['LUMINA_HEALTH_TOKEN' => ''])[0] === 'NULL');
});

section('Saúde (/health): migrações da base de dados batem certo com database/', function () {
    $dir = dirname(__DIR__, 2) . '/database';
    $versions = [];
    foreach (glob($dir . '/migracao_v*.sql') ?: [] as $file) {
        if (preg_match('/migracao_(v\d+)_/', basename($file), $m)) { $versions[$file] = $m[1]; }
    }
    check('encontrei as migrações em database/', count($versions) >= 12, (string)count($versions));
    $v10 = (string)file_get_contents((string)array_search('v10', $versions, true));
    $unregistered = array_keys(HEALTH_UNREGISTERED_PROBES);
    foreach ($versions as $file => $v) {
        $n = (int)substr($v, 1);
        if ($n < 10) { check("$v: registada pela migração v10", str_contains($v10, "'$v'")); continue; }
        check("$v: está em HEALTH_REQUIRED_MIGRATIONS (acrescente-a em includes/health.php)", in_array($v, HEALTH_REQUIRED_MIGRATIONS, true));
        check("$v: regista-se em schema_migrations", (bool)preg_match("/INSERT\\s+IGNORE\\s+INTO\\s+schema_migrations[^;]*'$v'/i", (string)file_get_contents($file)));
    }
    check('HEALTH_REQUIRED_MIGRATIONS não lista migrações que não existem', array_diff(HEALTH_REQUIRED_MIGRATIONS, $versions) === [], json_encode(array_diff(HEALTH_REQUIRED_MIGRATIONS, $versions)));
    check('as sondas de migrações antigas apontam para migrações que existem', array_diff($unregistered, $versions) === []);
    // A base de desenvolvimento pode ter sido migrada antes de a v14 se registar: a sonda reconhece-a.
    $r = health_check_schema(db());
    check('esta base de dados está com as migrações em dia', $r === ['status' => 'ok', 'pending' => []], json_encode($r));
});

section('Saúde (/health): migrações em falta numa base de dados à parte', function () {
    $admin = test_admin_pdo();
    if (!$admin) { echo "  (saltado: sem conta de administração do MySQL; defina LUMINA_DB_ADMIN_USER / LUMINA_DB_ADMIN_PASS)\n"; return; }
    $base = 'lumina_teste_saude';
    $admin->exec("DROP DATABASE IF EXISTS `$base`");
    try {
        $admin->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4");
        $admin->exec("CREATE TABLE `$base`.schema_migrations (version VARCHAR(40) PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $admin->exec("INSERT INTO `$base`.schema_migrations (version) VALUES ('v1'),('v2'),('v3'),('v4'),('v5'),('v6'),('v7'),('v8'),('v9'),('v10')");
        $pdo = new PDO('mysql:host=' . (getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST) . ';port=' . (getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT) . ";dbname=$base;charset=utf8mb4",
            getenv('LUMINA_DB_ADMIN_USER') ?: 'root', getenv('LUMINA_DB_ADMIN_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $r = health_check_schema($pdo);
        check('só até à v10 → fail com v11, v12, v13, v14, v16 e v17 em falta', $r === ['status' => 'fail', 'pending' => ['v11', 'v12', 'v13', 'v14', 'v16', 'v17']], json_encode($r));
        $admin->exec("CREATE TABLE `$base`.meta_connections (id INT)");
        $r = health_check_schema($pdo);
        check('com a tabela meta_connections, a v14 já não falta (migração antiga sem registo)', $r['pending'] === ['v11', 'v12', 'v13', 'v16', 'v17'], json_encode($r));
        $admin->exec("INSERT INTO `$base`.schema_migrations (version) VALUES ('v11'),('v12'),('v13'),('v16'),('v17')");
        check('com todas registadas → ok', health_check_schema($pdo) === ['status' => 'ok', 'pending' => []]);

        // Pedido web real contra esta base: vista pública não revela nada; a detalhada (com segredo) lista o que falta.
        $admin->exec("DELETE FROM `$base`.schema_migrations WHERE version IN ('v12','v16')");
        $token = bin2hex(random_bytes(16));
        $srv = test_server_start(['LUMINA_DB_NAME' => $base, 'LUMINA_DB_HOST' => getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST, 'LUMINA_DB_PORT' => getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT,
            'LUMINA_DB_USER' => getenv('LUMINA_DB_ADMIN_USER') ?: 'root', 'LUMINA_DB_PASS' => getenv('LUMINA_DB_ADMIN_PASS') ?: '', 'LUMINA_HEALTH_TOKEN' => $token]);
        try {
            [$st, , $body] = test_http($srv['port'], 'GET', 'health');
            $j = json_decode($body, true);
            check('pública: continua 200/ok (a base responde) e não menciona migrações', $st === 200 && ($j['status'] ?? '') === 'ok' && !str_contains($body, 'v12') && !str_contains($body, 'migra') && !str_contains($body, 'schema'), $body);
            [$st, , $body] = test_http($srv['port'], 'GET', 'health', ["X-Health-Token: $token"]);
            $j = json_decode($body, true);
            check('detalhada: 200 mas "degraded", schema=fail e as migrações em falta', $st === 200 && ($j['status'] ?? '') === 'degraded' && ($j['checks']['schema'] ?? '') === 'fail'
                && ($j['details']['migrations_pending'] ?? null) === ['v12', 'v16'], $body);
        } finally { ($srv['stop'])(); @unlink($srv['log']); }
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `$base`");
    }
});

section('Saúde (/health): pedido web real (servidor PHP à parte)', function () {
    $srv = test_server_start();
    try {
        [$st, $h, $body] = test_http($srv['port'], 'GET', 'health');
        $j = json_decode($body, true);
        check('GET /health → 200', $st === 200, "estado $st");
        check('JSON válido: status ok e só app/database/storage = ok', $j === ['status' => 'ok', 'checks' => ['app' => 'ok', 'database' => 'ok', 'storage' => 'ok']], $body);
        check('cabeçalhos: JSON, sem cache, noindex, nosniff', str_starts_with($h['content-type'] ?? '', 'application/json') && str_contains($h['cache-control'] ?? '', 'no-store')
            && str_contains($h['x-robots-tag'] ?? '', 'noindex') && ($h['x-content-type-options'] ?? '') === 'nosniff', json_encode($h));
        check('não anuncia a versão do PHP (X-Powered-By) nem cria cookie de sessão', !isset($h['x-powered-by']) && !isset($h['set-cookie']), json_encode($h));
        $proibido = [DB_NAME, 'mysql', 'maria', 'sql', dirname(__DIR__, 2), 'PHP/', 'password', 'palavra', 'Exception', 'Fatal', 'Warning', 'Notice', '/', '\\'];
        $achado = array_values(array_filter($proibido, fn($s) => $s !== '' && stripos($body, $s) !== false));
        check('a resposta pública não revela nomes da BD, caminhos, versões nem erros', $achado === [], 'encontrei: ' . implode(', ', $achado));
        [$st2, , $b2] = test_http($srv['port'], 'GET', 'health.php');
        check('/health.php (sem rewrite) dá a mesma resposta', $st2 === 200 && $b2 === $body);
        [$st3, $h3, $b3] = test_http($srv['port'], 'HEAD', 'health');
        check('HEAD → 200 sem corpo', $st3 === 200 && $b3 === '' && str_starts_with($h3['content-type'] ?? '', 'application/json'));
        foreach (['POST', 'PUT', 'DELETE', 'PATCH'] as $m) {
            [$s4, $h4, $b4] = test_http($srv['port'], $m, 'health');
            check("$m → 405 com Allow: GET, HEAD, sem tocar na base de dados", $s4 === 405 && ($h4['allow'] ?? '') === 'GET, HEAD' && !str_contains($b4, 'checks'), "estado $s4");
        }
        // segredo: sem segredo configurado, nada abre a vista detalhada
        foreach (['', 'qualquer-coisa-comprida-123456', 'null', '0'] as $enviado) {
            [, , $b5] = test_http($srv['port'], 'GET', 'health', ["X-Health-Token: $enviado"]);
            check('sem segredo configurado, "' . $enviado . '" não abre a vista detalhada', $b5 === $body, $b5);
        }
    } finally { ($srv['stop'])(); @unlink($srv['log']); }

    $token = bin2hex(random_bytes(16));
    $srv = test_server_start(['LUMINA_HEALTH_TOKEN' => $token]);
    try {
        [, , $pub] = test_http($srv['port'], 'GET', 'health');
        check('com segredo configurado, quem não o envia continua a ver só a vista pública', json_decode($pub, true) === ['status' => 'ok', 'checks' => ['app' => 'ok', 'database' => 'ok', 'storage' => 'ok']], $pub);
        [$s1, , $errado] = test_http($srv['port'], 'GET', 'health', ['X-Health-Token: ' . strrev($token)]);
        check('segredo ERRADO → exatamente a vista pública (200, sem pistas, sem 401/403)', $s1 === 200 && $errado === $pub, $errado);
        [, , $naQuery] = test_http($srv['port'], 'GET', "health?token=$token&X-Health-Token=$token");
        check('o segredo no endereço (?token=) é ignorado: só vale no cabeçalho', $naQuery === $pub);
        [, , $maiusc] = test_http($srv['port'], 'GET', 'health', ['X-Health-Token: ' . strtoupper($token)]);
        check('segredo com outras maiúsculas → vista pública', $maiusc === $pub);
        [$s2, , $det] = test_http($srv['port'], 'GET', 'health', ["X-Health-Token: $token"]);
        $j = json_decode($det, true);
        check('segredo CERTO → vista detalhada', $s2 === 200 && isset($j['details'], $j['checks']['schema'], $j['checks']['backup'], $j['checks']['disk'], $j['checks']['crypto_key'], $j['checks']['php_extensions']), $det);
        check('detalhada: base de dados em dia, extensões completas, chave de cifra válida', ($j['checks']['schema'] ?? '') === 'ok' && ($j['details']['migrations_pending'] ?? null) === []
            && ($j['checks']['php_extensions'] ?? '') === 'ok' && ($j['details']['php_extensions_missing'] ?? null) === [] && ($j['checks']['crypto_key'] ?? '') === 'ok', $det);
        check('detalhada: o estado da cópia é um dos três valores possíveis', in_array($j['checks']['backup'] ?? '', ['ok', 'stale', 'none'], true));
        check('detalhada: canal de e-mail conhecido e espaço em disco é um número', in_array($j['details']['mail_driver'] ?? '', ['log', 'smtp', 'mail'], true) && (is_float($j['details']['disk_free_percent'] ?? null) || is_int($j['details']['disk_free_percent'] ?? null)), $det);
        check('detalhada: NÃO inclui palavras-passe, chaves, o segredo, caminhos nem nomes de ficheiros de cópias',
            !str_contains($det, $token) && !str_contains($det, dirname(__DIR__, 2)) && !preg_match('/\.sql|\.gz|app_key|smtp_pass|password/i', $det), $det);
    } finally { ($srv['stop'])(); @unlink($srv['log']); }

    // segredo configurado mas curto: a vista detalhada fica desligada mesmo que o monitor o envie
    $srv = test_server_start(['LUMINA_HEALTH_TOKEN' => 'curto123']);
    try {
        [, , $b] = test_http($srv['port'], 'GET', 'health', ['X-Health-Token: curto123']);
        check('segredo configurado com menos de 16 carateres → vista detalhada desligada', !str_contains($b, 'details'), $b);
    } finally { ($srv['stop'])(); @unlink($srv['log']); }
});

section('Saúde (/health): base de dados em baixo ou configuração recusada', function () {
    $segredo = 'segredo-bd-' . bin2hex(random_bytes(6));
    $srv = test_server_start(['LUMINA_DB_HOST' => '127.0.0.1', 'LUMINA_DB_PORT' => '1', 'LUMINA_DB_USER' => 'utilizador_x', 'LUMINA_DB_PASS' => $segredo]);
    try {
        $t0 = microtime(true);
        [$st, , $body] = test_http($srv['port'], 'GET', 'health');
        $demora = microtime(true) - $t0;
        $j = json_decode($body, true);
        check('MySQL inalcançável → 503 e status "down"', $st === 503 && ($j['status'] ?? '') === 'down', "estado $st: $body");
        check('...com database=fail e as outras verificações a continuar a responder', ($j['checks']['database'] ?? '') === 'fail' && ($j['checks']['app'] ?? '') === 'ok' && ($j['checks']['storage'] ?? '') === 'ok', $body);
        check('...e responde depressa (ligação recusada, não fica pendurado)', $demora < 6, round($demora, 1) . ' s');
        check('...sem texto de erro nem a palavra-passe na resposta', !str_contains($body, $segredo) && !preg_match('/n[aã]o foi poss|utilizador|MySQL|servidor|127\.0\.0\.1|SQLSTATE|Exception/i', $body), $body);
        $log = (string)file_get_contents($srv['log']);
        check('o motivo real fica no log do PHP (sem a palavra-passe)', str_contains($log, 'Lumina saúde: base de dados indisponível') && !str_contains($log, $segredo), $log);
        [$sh] = test_http($srv['port'], 'HEAD', 'health');
        check('HEAD também dá 503', $sh === 503);
    } finally { ($srv['stop'])(); @unlink($srv['log']); }

    // O /health não contorna a proteção "root em site público": mesmo servidor, mas a escutar num IP público.
    $prepend = sys_get_temp_dir() . '/lumina_fake_addr_h_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($prepend, "<?php \$_SERVER['SERVER_ADDR'] = '8.8.8.8';");
    $srv = test_server_start(['LUMINA_DB_USER' => 'root', 'LUMINA_DB_PASS' => 'qualquer-coisa'], ['auto_prepend_file' => $prepend]);
    try {
        [$st, , $body] = test_http($srv['port'], 'GET', 'health.php');      // (o servidor embutido só aplica o auto_prepend_file a ficheiros pedidos diretamente, não às rotas do router.php)
        check('root num site "público" → 503 (a proteção da BD também vale aqui)', $st === 503 && str_contains($body, '"database":"fail"'), "estado $st: $body");
        check('...o cliente não vê o motivo; o log sim', !str_contains($body, 'root') && str_contains((string)file_get_contents($srv['log']), 'não se liga à base de dados como "root"'));
    } finally { ($srv['stop'])(); @unlink($srv['log']); @unlink($prepend); }
});

section('Saúde (/health): ligações com o resto do site', function () {
    $root = dirname(__DIR__, 2);
    check('.htaccess: /health reescrito para health.php', (bool)preg_match('/^RewriteRule \^health\$ health\.php \[L\]/m', (string)file_get_contents($root . '/.htaccess')));
    check('router.php (servidor de desenvolvimento) conhece /health', str_contains((string)file_get_contents($root . '/router.php'), "'health' => 'health.php'"));
    check('/health não é uma página pública do SEO (não entra no sitemap)', !in_array('health', SEO_PUBLIC_PAGES, true));
    [, , $robots] = http_get('robots.txt');
    check('robots.txt pede aos motores de pesquisa para ignorarem /health', str_contains($robots, "Disallow: /health\n"));
    [, , $sitemap] = http_get('sitemap.xml');
    check('o sitemap não lista /health', !str_contains($sitemap, 'health'));
    check('config/database.php: a ligação à BD tem tempo limite', str_contains((string)file_get_contents($root . '/config/database.php'), 'PDO::ATTR_TIMEOUT'));
    check('README documenta o /health e o segredo LUMINA_HEALTH_TOKEN', str_contains((string)file_get_contents($root . '/README.md'), 'LUMINA_HEALTH_TOKEN'));
});
