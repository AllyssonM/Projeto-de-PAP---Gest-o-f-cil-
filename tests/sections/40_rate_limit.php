<?php
/* Testes do limite de pedidos (includes/rate_limit.php, includes/client_ip.php) e da sua ligação à API. Carregado por tests/run.php. */
require_once __DIR__ . '/../../includes/rate_limit.php';

/** Espera, se for preciso, para que uma janela de $window segundos não acabe durante o teste (senão contava-se metade numa janela e metade noutra). */
function rl_wait_safe_window(int $window, int $need): void
{
    $left = $window - (time() % $window);
    if ($left < $need) { sleep($left + 1); }
}
section('Limite de pedidos: configuração e chaves', function () {
    foreach (['LUMINA_RATE_API', 'LUMINA_RATE_MULTIPLIER', 'LUMINA_RATE_AUTH_FORGOT_EMAIL'] as $v) { putenv($v); }
    check('todos os âmbitos têm máximo ≥ 1 e janela entre 1 s e 1 h (a limpeza guarda 2 h)', array_reduce(RATE_LIMIT_DEFAULTS, fn($ok, $d) => $ok && $d[0] >= 1 && $d[1] >= 1 && $d[1] <= 3600, true));
    check('valores por omissão: api = 300 por minuto', rate_limit_config('api') === [300, 60]);
    putenv('LUMINA_RATE_API=5/30');
    check('LUMINA_RATE_API="5/30" ajusta o âmbito', rate_limit_config('api') === [5, 30]);
    putenv('LUMINA_RATE_AUTH_FORGOT_EMAIL=7/120');
    check('o nome da variável acompanha o âmbito (AUTH_FORGOT_EMAIL)', rate_limit_config('auth_forgot_email') === [7, 120]);
    putenv('LUMINA_RATE_AUTH_FORGOT_EMAIL');
    foreach (['abc', '0/60', '10/0', '10', '-5/60', '10/100000', '99999999/60', '5/30/1', ''] as $bad) {
        putenv('LUMINA_RATE_API=' . $bad);
        check("valor inválido «{$bad}» é ignorado (fica o valor por omissão)", rate_limit_config('api') === [300, 60]);
    }
    putenv('LUMINA_RATE_API');
    putenv('LUMINA_RATE_MULTIPLIER=2');
    check('LUMINA_RATE_MULTIPLIER=2 duplica os máximos e não mexe nas janelas', rate_limit_config('api') === [600, 60] && rate_limit_config('export') === [20, 3600]);
    putenv('LUMINA_RATE_MULTIPLIER=0.001');
    check('multiplicador abaixo de 0,01 é ignorado', rate_limit_config('api') === [300, 60]);
    putenv('LUMINA_RATE_MULTIPLIER=abc');
    check('multiplicador que não é número é ignorado', rate_limit_config('api') === [300, 60]);
    putenv('LUMINA_RATE_MULTIPLIER=0.01');
    check('o máximo nunca desce abaixo de 1', rate_limit_config('export')[0] === 1);
    putenv('LUMINA_RATE_MULTIPLIER');

    $root = dirname(__DIR__, 2);
    $used = [];
    foreach (array_merge(glob($root . '/api/*.php') ?: [], glob($root . '/includes/*.php') ?: []) as $f) {
        if (preg_match_all("/rate_limit_enforce(?:_ip)?\\(\\s*'([a-z_]+)'/", (string)file_get_contents($f), $m)) { foreach ($m[1] as $scope) { $used[$scope][] = basename($f); } }
    }
    check('todo o âmbito usado no código tem limite definido', array_diff_key($used, RATE_LIMIT_DEFAULTS) === [], json_encode(array_keys(array_diff_key($used, RATE_LIMIT_DEFAULTS))));
    check('não há limites definidos que ninguém usa', array_diff_key(RATE_LIMIT_DEFAULTS, $used) === [], json_encode(array_keys(array_diff_key(RATE_LIMIT_DEFAULTS, $used))));

    check('IPv4 mantém-se; IPv4 mapeado em IPv6 é o mesmo IPv4', rate_limit_ip_key('203.0.113.7') === rate_limit_ip_key('::ffff:203.0.113.7') && rate_limit_ip_key('203.0.113.7') !== rate_limit_ip_key('203.0.113.8'));
    check('IPv6: o mesmo /64 partilha o balde, outro /64 não', rate_limit_ip_key('2001:db8:1:2::1') === rate_limit_ip_key('2001:db8:1:2:ffff:ffff:ffff:ffff') && rate_limit_ip_key('2001:db8:1:2::1') !== rate_limit_ip_key('2001:db8:1:3::1'));
    check('IP inválido ou vazio não parte nada', rate_limit_ip_key('') === 'ip:desconhecido' && rate_limit_ip_key('não-é-ip') === 'ip:desconhecido');
    $b = rate_limit_bucket('api', 'pessoa@exemplo.pt');
    check('balde: 64 caracteres hexadecimais, estável, e muda com o âmbito e a chave', (bool)preg_match('/^[0-9a-f]{64}$/', $b) && $b === rate_limit_bucket('api', 'pessoa@exemplo.pt') && $b !== rate_limit_bucket('mail', 'pessoa@exemplo.pt') && $b !== rate_limit_bucket('api', 'outra@exemplo.pt'));
    check('o balde não contém a chave em claro', !str_contains($b, 'pessoa') && !str_contains($b, 'exemplo'));
});

section('Limite de pedidos: IP atrás de proxies de confiança', function () {
    $t = trusted_proxy_list('127.0.0.1, 10.0.0.0/8 ; 2001:db8::/32  lixo 1.2.3.4/99 0.0.0.0/0 ::/0 8.8.8.8/0 5.6.7.8/abc');
    check('lista: aceita IPs e redes CIDR, ignora lixo, prefixos inválidos e /0 (toda a Internet)', count($t) === 3, (string)count($t));
    check('lista vazia ou nula → ninguém é de confiança', trusted_proxy_list(null) === [] && trusted_proxy_list('') === [] && !ip_is_trusted('127.0.0.1', []));
    check('pertença a redes: 10.1.2.3 ∈ 10.0.0.0/8; 11.0.0.1 ∉; 127.0.0.1 ∈; 127.0.0.2 ∉', ip_is_trusted('10.1.2.3', $t) && !ip_is_trusted('11.0.0.1', $t) && ip_is_trusted('127.0.0.1', $t) && !ip_is_trusted('127.0.0.2', $t));
    check('IPv6 e IPv4 mapeado: 2001:db8:ffff::1 ∈ /32; ::ffff:127.0.0.1 conta como 127.0.0.1', ip_is_trusted('2001:db8:ffff::1', $t) && !ip_is_trusted('2001:db9::1', $t) && ip_is_trusted('::ffff:127.0.0.1', $t));
    $net = trusted_proxy_list('192.168.4.0/22');
    check('prefixo que não é múltiplo de 8 (/22): 192.168.7.255 ∈; 192.168.8.0 ∉', ip_is_trusted('192.168.7.255', $net) && !ip_is_trusted('192.168.8.0', $net));

    $p = trusted_proxy_list('10.0.0.1,10.0.0.2');
    check('sem proxies configurados o X-Forwarded-For é ignorado (não se pode fingir um IP)', forwarded_client_ip('203.0.113.9', '1.1.1.1', []) === '203.0.113.9');
    check('o pedido NÃO vem de um proxy de confiança → X-Forwarded-For ignorado', forwarded_client_ip('203.0.113.9', '1.1.1.1', $p) === '203.0.113.9');
    check('vem de um proxy de confiança → cliente = o do cabeçalho', forwarded_client_ip('10.0.0.1', '198.51.100.20', $p) === '198.51.100.20');
    check('vários saltos: percorre da direita, saltando proxies de confiança', forwarded_client_ip('10.0.0.1', '198.51.100.20, 10.0.0.2', $p) === '198.51.100.20');
    check('o cliente tenta fingir (escreve outro IP à esquerda): conta o que o proxy viu', forwarded_client_ip('10.0.0.1', '9.9.9.9, 198.51.100.20', $p) === '198.51.100.20');
    check('cabeçalho vazio, só proxies, lixo ou porta → devolve $remote', forwarded_client_ip('10.0.0.1', '', $p) === '10.0.0.1' && forwarded_client_ip('10.0.0.1', '10.0.0.2', $p) === '10.0.0.1'
        && forwarded_client_ip('10.0.0.1', 'olá', $p) === '10.0.0.1' && forwarded_client_ip('10.0.0.1', '1.2.3.4:5678', $p) === '10.0.0.1' && forwarded_client_ip('10.0.0.1', '198.51.100.20, lixo', $p) === '10.0.0.1');
    check('IPv6 no cabeçalho', forwarded_client_ip('10.0.0.1', '2001:db8:1::5', $p) === '2001:db8:1::5');
});

section('Limite de pedidos: contagem na base de dados', function () {
    putenv('LUMINA_RATE_API=3/60');
    $key = 'teste-rl-' . bin2hex(random_bytes(6));
    $w = intdiv(time(), 60) * 60 + 600;                                  // uma janela futura e alinhada: não depende do relógio real nem de outros testes
    $r = [];
    for ($i = 1; $i <= 4; $i++) { $r[$i] = rate_limit_hit('api', $key, $w + 10); }
    check('os 3 primeiros pedidos passam e o 4.º é recusado', $r[1]['allowed'] && $r[2]['allowed'] && $r[3]['allowed'] && !$r[4]['allowed'], json_encode($r));
    check('contagem exata (1, 2, 3, 4)', array_column($r, 'count') === [1, 2, 3, 4]);
    check('Retry-After = segundos até acabar a janela (60 − 10 = 50)', $r[4]['retry_after'] === 50 && $r[1]['retry_after'] === 0);
    check('o último segundo da janela dá Retry-After 1, nunca 0', rate_limit_hit('api', $key, $w + 59)['retry_after'] === 1);
    check('janela seguinte: volta a passar, contagem recomeça', ($n = rate_limit_hit('api', $key, $w + 60))['allowed'] && $n['count'] === 1);
    check('outra chave (outra pessoa) não é afetada', rate_limit_hit('api', $key . 'x', $w + 10)['allowed']);
    putenv('LUMINA_RATE_MAIL=3/60');
    check('o mesmo utilizador noutro âmbito tem balde próprio', rate_limit_hit('mail', $key, $w + 10)['count'] === 1);
    putenv('LUMINA_RATE_MAIL');
    putenv('LUMINA_RATE_API');

    $bucket = rate_limit_bucket('api', $key);
    $rows = db()->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?'); $rows->execute([$bucket]);
    check('a tabela guarda o balde (HMAC) e não a chave em claro', (int)$rows->fetchColumn() === 2 && (int)db()->query("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE '%teste-rl%'")->fetchColumn() === 0);
    check('a tabela só tem as colunas bucket, window_start e hits', db()->query("SELECT GROUP_CONCAT(column_name ORDER BY ordinal_position) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'rate_limits'")->fetchColumn() === 'bucket,window_start,hits');

    // limpeza das janelas antigas
    $old = rate_limit_bucket('api', 'antigo-' . $key);
    db()->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 5), (?, ?, 5)')->execute([$old, time() - RATE_LIMIT_KEEP_SECONDS - 100, $old, time() - 60]);
    $deleted = rate_limit_cleanup();
    $left = db()->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ?'); $left->execute([$old]);
    check('limpeza: apaga a janela com mais de 2 h e guarda a recente', $deleted >= 1 && (int)$left->fetchColumn() === 1);
    db()->prepare('DELETE FROM rate_limits WHERE bucket IN (?, ?)')->execute([$bucket, $old]);
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([rate_limit_bucket('mail', $key)]);
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([rate_limit_bucket('api', $key . 'x')]);
});

section('Limite de pedidos: simultâneos e falhas da base de dados', function () {
    $root = dirname(__DIR__, 2);
    // 12 processos em paralelo, 5 pedidos cada, ao mesmo balde, máximo 20: têm de passar EXATAMENTE 20 e contar 60 (nada se perde).
    $key = 'teste-rl-par-' . bin2hex(random_bytes(6));
    $code = '<?php require ' . var_export($root . '/includes/auth.php', true) . '; $ok = 0; for ($i = 0; $i < 5; $i++) { if (rate_limit_hit("api", $argv[1], intdiv(time(), 3600) * 3600 + 7200)["allowed"]) { $ok++; } } echo $ok;';
    $script = tempnam(sys_get_temp_dir(), 'rlpar') . '.php'; file_put_contents($script, $code);
    $procs = [];
    for ($i = 0; $i < 12; $i++) {
        $p = proc_open([PHP_BINARY, $script, $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['LUMINA_RATE_API' => '20/3600'] + (getenv() ?: []));
        $procs[] = [$p, $pipes];
    }
    $allowed = 0; $errors = '';
    foreach ($procs as [$p, $pipes]) { $allowed += (int)stream_get_contents($pipes[1]); $errors .= stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p); }
    @unlink($script);
    $row = db()->prepare('SELECT hits FROM rate_limits WHERE bucket = ?'); $row->execute([rate_limit_bucket('api', $key)]);
    check('12 processos em paralelo: passam exatamente 20 de 60', $allowed === 20, "passaram $allowed " . $errors);
    check('...e a contagem na base de dados é exatamente 60 (atómica)', (int)$row->fetchColumn() === 60);
    db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([rate_limit_bucket('api', $key)]);

    // falhas «abertas»: nunca deitam o Lumina abaixo
    $probe = '<?php require ' . var_export($root . '/includes/auth.php', true) . '; $t = microtime(true); $r = rate_limit_hit("api", "x"); echo json_encode([$r["allowed"], round(microtime(true) - $t, 1) < 4]);';
    $script = tempnam(sys_get_temp_dir(), 'rlfail') . '.php'; file_put_contents($script, $probe);
    [$out, $err] = php_run([$script], ['LUMINA_DB_HOST' => '127.0.0.1', 'LUMINA_DB_PORT' => '1']);
    check('base de dados inalcançável → o pedido passa, depressa, e fica registo no log', $out === '[true,true]' && str_contains($err, 'limite de pedidos desligado'), $out . ' | ' . $err);
    $admin = test_admin_pdo();
    if ($admin) {
        $base = 'lumina_teste_rl';
        $admin->exec("DROP DATABASE IF EXISTS `$base`");
        try {
            $admin->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4");   // existe, mas sem a tabela rate_limits (migração v17 por aplicar)
            [$out, $err] = php_run([$script], ['LUMINA_DB_NAME' => $base, 'LUMINA_DB_HOST' => getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST, 'LUMINA_DB_PORT' => getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT,
                'LUMINA_DB_USER' => getenv('LUMINA_DB_ADMIN_USER') ?: 'root', 'LUMINA_DB_PASS' => getenv('LUMINA_DB_ADMIN_PASS') ?: '']);
            check('tabela rate_limits em falta → o pedido passa e fica registo no log', $out === '[true,true]' && str_contains($err, 'limite de pedidos desligado'), $out . ' | ' . $err);
        } finally { $admin->exec("DROP DATABASE IF EXISTS `$base`"); }
    } else { echo "  (saltado: sem conta de administração do MySQL)\n"; }
    @unlink($script);
});

section('Limite de pedidos: na API (servidor PHP à parte)', function () {
    $migrated = (int)db()->query("SELECT COUNT(*) FROM schema_migrations WHERE version = 'v17'")->fetchColumn();
    check('a migração v17 está aplicada nesta base de dados', $migrated === 1);
    $env = ['LUMINA_TRUSTED_PROXIES' => '127.0.0.1', 'LUMINA_RATE_API' => '4/3600', 'LUMINA_RATE_AUTH_REGISTER' => '3/3600', 'LUMINA_RATE_AUTH_LOGIN' => '5/3600', 'LUMINA_RATE_AUTH_FORGOT' => '4/3600',
        'LUMINA_RATE_AUTH_FORGOT_EMAIL' => '2/3600', 'LUMINA_RATE_MAIL' => '2/3600', 'LUMINA_RATE_EXPORT' => '2/3600', 'LUMINA_RATE_IMPORT' => '2/3600', 'LUMINA_RATE_AUTH_TOKEN' => '3/3600'];
    $srv = test_server_start($env);
    $oldBase = $GLOBALS['BASE'];
    $GLOBALS['BASE'] = 'http://127.0.0.1:' . $srv['port'] . '/';
    $touched = [];
    /** pedido JSON com um IP de cliente próprio (X-Forwarded-For: o servidor confia em 127.0.0.1 como proxy) */
    $call = function (string $ip, string $path, array $body, ?Client $c = null) {
        $ch = curl_init($GLOBALS['BASE'] . $path); $hdr = [];
        $jar = $c ? (new ReflectionProperty($c, 'jar'))->getValue($c) : tempnam(sys_get_temp_dir(), 'rl');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Forwarded-For: ' . $ip], CURLOPT_POSTFIELDS => json_encode($body + ($c && $c->csrf ? ['csrf' => $c->csrf] : [])),
            CURLOPT_HEADERFUNCTION => function ($x, $l) use (&$hdr) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hdr[strtolower(trim($k))] = trim($v); } return strlen($l); }]);
        $raw = (string)curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$st, json_decode($raw, true) ?: [], $hdr, $raw];
    };
    try {
        $ipOf = fn() => '198.51.100.' . random_int(1, 254);
        rl_wait_safe_window(3600, 20);
        $tag = 'teste-rl-' . bin2hex(random_bytes(3));

        // registo: 3 por IP e hora
        $ip = $ipOf(); $codes = [];
        for ($i = 1; $i <= 4; $i++) { [$st, $j, $h, $raw] = $call($ip, 'api/auth.php?action=register', ['name' => 'Teste RL', 'email' => "$tag-r$i@lumina.test", 'password' => 'palavra-passe-1']); $codes[] = $st; }
        check('registo: 3 contas passam (201) e a 4.ª do mesmo IP leva 429', $codes === [201, 201, 201, 429], json_encode($codes));
        check('429: JSON com success=false, mensagem clara, retry_after e cabeçalho Retry-After coerentes', ($j['success'] ?? null) === false && ($j['error'] ?? '') !== '' && ($j['retry_after'] ?? 0) >= 1 && ($j['retry_after'] ?? 0) <= 3600
            && (string)($j['retry_after'] ?? '') === ($h['retry-after'] ?? '?'), $raw . json_encode($h));
        check('429: sem detalhes internos (SQL, caminhos, ficheiros)', !preg_match('#/home|/var/|\.php|SQL|PDO|rate_limits|bucket#i', $raw), $raw);
        $made = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?'); $made->execute(["$tag-r4@lumina.test"]);
        check('a conta do pedido recusado NÃO foi criada', (int)$made->fetchColumn() === 0);
        [$st] = $call($ipOf(), 'api/auth.php?action=register', ['name' => 'Teste RL', 'email' => "$tag-r5@lumina.test", 'password' => 'palavra-passe-1']);
        check('outro IP não é afetado (201)', $st === 201);
        [$st] = $call($ip, 'api/auth.php?action=register', ['name' => 'x', 'email' => 'inválido', 'password' => '1']);
        check('o limite conta também os pedidos inválidos (429 antes de validar)', $st === 429);

        // login: inundação por IP (mesmo com a palavra-passe certa)
        $ip = $ipOf(); $codes = [];
        for ($i = 1; $i <= 5; $i++) { [$st] = $call($ip, 'api/auth.php?action=login', ['email' => "$tag-nao-existe@lumina.test", 'password' => 'errada']); $codes[] = $st; }
        [$st6] = $call($ip, 'api/auth.php?action=login', ['email' => "$tag-r1@lumina.test", 'password' => 'palavra-passe-1']);
        check('login: 5 tentativas passam (401) e a 6.ª leva 429, mesmo com a palavra-passe certa', $codes === [401, 401, 401, 401, 401] && $st6 === 429, json_encode($codes) . " $st6");
        [$stOther] = $call($ipOf(), 'api/auth.php?action=login', ['email' => "$tag-r1@lumina.test", 'password' => 'palavra-passe-1']);
        check('outro IP entra normalmente (200)', $stOther === 200);

        // recuperar palavra-passe: por email-alvo (igual exista a conta ou não) e por IP
        $seq = function (string $email) use ($call, $ipOf) { $o = []; for ($i = 1; $i <= 3; $i++) { [$st, $j] = $call($ipOf(), 'api/auth.php?action=forgot', ['email' => $email]); $o[] = [$st, $j['success'] ?? null]; } return $o; };
        $exists = $seq("$tag-r1@lumina.test"); $absent = $seq("$tag-fantasma@lumina.test");
        check('esqueci-me: 2 por hora por email, de IPs diferentes; o 3.º leva 429', $exists === [[200, true], [200, true], [429, false]], json_encode($exists));
        check('...e é IGUAL para uma conta que existe e para uma que não existe (não revela contas)', $exists === $absent, json_encode([$exists, $absent]));
        $ip = $ipOf(); $codes = [];
        for ($i = 1; $i <= 5; $i++) { [$st] = $call($ip, 'api/auth.php?action=forgot', ['email' => "$tag-f$i@lumina.test"]); $codes[] = $st; }
        check('esqueci-me: 4 por IP e hora; o 5.º (outro email) leva 429', $codes === [200, 200, 200, 200, 429], json_encode($codes));
        [$st] = $call($ipOf(), 'api/auth.php?action=reset_password', ['token' => 'x', 'password' => 'palavra-passe-1']);
        check('link inválido continua a responder 400 (dentro do limite)', $st === 400);

        // API autenticada: 4 por hora por utilizador (valor de teste)
        $a = new Client(); $ipA = $ipOf();
        [$st, $d] = $call($ipA, 'api/auth.php?action=login', ['email' => "$tag-r2@lumina.test", 'password' => 'palavra-passe-1'], $a); $a->csrf = $d['csrf'] ?? '';
        $codes = []; for ($i = 1; $i <= 5; $i++) { [$st] = $call($ipA, 'api/auth.php?action=me', [], $a); $codes[] = $st; }
        check('API: 4 pedidos passam e o 5.º do mesmo utilizador leva 429', $codes === [200, 200, 200, 200, 429], json_encode($codes));
        $b = new Client(); [$st, $d] = $call($ipOf(), 'api/auth.php?action=login', ['email' => "$tag-r3@lumina.test", 'password' => 'palavra-passe-1'], $b); $b->csrf = $d['csrf'] ?? '';
        [$st] = $call($ipOf(), 'api/auth.php?action=me', [], $b);
        check('outro utilizador (mesmo servidor) não é afetado', $st === 200);
        $jar = (new ReflectionProperty($a, 'jar'))->getValue($a);
        $ch = curl_init($GLOBALS['BASE'] . 'dashboard.php'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false]); curl_exec($ch); $page = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        check('as páginas não contam: o painel do utilizador bloqueado abre (200)', $page === 200, (string)$page);
        [$stHealth] = (function () use ($srv) { for ($i = 0; $i < 8; $i++) { $r = test_http($srv['port'], 'GET', 'health'); if ($r[0] !== 200) { return $r; } } return [200]; })();
        check('/health nunca é limitado (8 pedidos seguidos, 200)', $stHealth === 200);

    } finally {
        ($srv['stop'])(); @unlink($srv['log']);
        $GLOBALS['BASE'] = $oldBase;
    }

    $srv = test_server_start(['LUMINA_TRUSTED_PROXIES' => '127.0.0.1', 'LUMINA_RATE_API' => '500/3600', 'LUMINA_RATE_MAIL' => '2/3600', 'LUMINA_RATE_EXPORT' => '2/3600', 'LUMINA_RATE_IMPORT' => '2/3600', 'LUMINA_RATE_AUTH_REGISTER' => '50/3600']);
    $GLOBALS['BASE'] = 'http://127.0.0.1:' . $srv['port'] . '/';
    try {
        $tag = 'teste-rl-' . bin2hex(random_bytes(3));
        $o = new Client(); $ip = '198.51.100.' . random_int(1, 254);
        [$st, $d] = $call($ip, 'api/auth.php?action=register', ['name' => 'Teste RL dono', 'email' => "$tag-dono@lumina.test", 'password' => 'palavra-passe-1'], $o); $o->csrf = $d['csrf'] ?? '';
        $codes = []; foreach ([1, 2, 3] as $i) { [$st] = $o->req('GET', 'api/account.php?action=export'); $codes[] = $st; }
        check('exportar os dados da conta: 2 por hora (valor de teste), a 3.ª leva 429', $codes === [200, 200, 429], json_encode($codes));
        $codes = []; $emails = [];
        foreach ([1, 2, 3] as $i) { $emails[$i] = "$tag-func$i@lumina.test"; [$st] = $o->req('POST', 'api/team.php', ['action' => 'save', 'name' => "Func $i", 'email' => $emails[$i], 'permissions' => ['calendar'], 'send_invite' => true]); $codes[] = $st; }
        check('convites de equipa: 2 passam (201) e o 3.º leva 429', $codes === [201, 201, 429], json_encode($codes));
        $made = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?'); $made->execute([$emails[3]]);
        check('o funcionário do convite recusado NÃO foi criado (nada a meio)', (int)$made->fetchColumn() === 0);
        [$st] = $o->req('POST', 'api/auth.php?action=test_mail', []);
        check('o teste de envio partilha o limite de emails do utilizador (429)', $st === 429, (string)$st);
        $codes = []; foreach ([1, 2, 3] as $i) { [$st] = $o->upload('api/import.php', ['action' => 'preview'], 'm.csv', "data,descricao,valor\n2026-01-01,Teste,10\n", 'text/csv'); $codes[] = $st; }
        check('importar CSV: 2 por hora (valor de teste), o 3.º leva 429', $codes[2] === 429 && $codes[0] !== 429 && $codes[1] !== 429, json_encode($codes));
    } finally {
        ($srv['stop'])(); @unlink($srv['log']);
        $GLOBALS['BASE'] = $oldBase;
    }

    // Sem LUMINA_TRUSTED_PROXIES, X-Forwarded-For não serve para fugir ao limite: conta sempre o IP real da ligação.
    $srv = test_server_start(['LUMINA_RATE_AUTH_REGISTER' => '2/3600', 'LUMINA_TRUSTED_PROXIES' => '']);
    $GLOBALS['BASE'] = 'http://127.0.0.1:' . $srv['port'] . '/';
    $local = rate_limit_bucket('auth_register', rate_limit_ip_key('127.0.0.1'));
    try {
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$local]);
        rl_wait_safe_window(3600, 20);
        $tag = 'teste-rl-' . bin2hex(random_bytes(3)); $codes = [];
        for ($i = 1; $i <= 3; $i++) { [$st] = $call('198.51.100.' . (10 + $i), 'api/auth.php?action=register', ['name' => 'Teste RL', 'email' => "$tag-s$i@lumina.test", 'password' => 'palavra-passe-1']); $codes[] = $st; }
        check('IP falsificado em X-Forwarded-For não escapa ao limite (sem proxies de confiança): 201, 201, 429', $codes === [201, 201, 429], json_encode($codes));
    } finally {
        ($srv['stop'])(); @unlink($srv['log']);
        $GLOBALS['BASE'] = $oldBase;
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$local]);      // não deixar o IP local penalizado para as outras secções
    }
    db()->exec("DELETE FROM login_attempts WHERE email LIKE 'teste-rl-%'");
});

section('Limite de pedidos: o balde repõe-se com o tempo (servidor PHP à parte)', function () {
    $srv = test_server_start(['LUMINA_TRUSTED_PROXIES' => '127.0.0.1', 'LUMINA_RATE_AUTH_LOGIN' => '2/4']);
    $ip = '198.51.100.' . random_int(1, 254);
    try {
        $try = fn() => test_http($srv['port'], 'POST', 'api/auth.php?action=login', ['X-Forwarded-For: ' . $ip, 'Content-Type: application/json']);
        while (time() % 4 !== 0) { usleep(20000); }                    // começa no início de uma janela de 4 s
        $codes = [$try()[0], $try()[0], $try()[0]];
        check('2 pedidos passam e o 3.º leva 429 (janela de 4 s)', $codes[2] === 429 && $codes[0] !== 429 && $codes[1] !== 429, json_encode($codes));
        sleep(4);
        check('passada a janela, volta a passar', $try()[0] !== 429);
    } finally { ($srv['stop'])(); @unlink($srv['log']); }
});
