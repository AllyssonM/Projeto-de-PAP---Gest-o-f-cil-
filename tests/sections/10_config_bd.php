<?php
/* Secções: configuração da base de dados, utilizador de menor privilégio e procura de segredos (P0.1). */
require_once __DIR__ . '/../secret_scan.php';

section('Configuração da BD: ordem de prioridade (env > ficheiro local > omissão)', function () {
    $d = db_resolve_config([], []);
    check('sem nada configurado usa os valores de desenvolvimento do XAMPP', $d['host'] === '127.0.0.1' && $d['name'] === 'gestao_facil' && $d['user'] === 'root' && $d['pass'] === '' && $d['source'] === 'default');
    $l = db_resolve_config([], ['user' => 'app', 'pass' => 'abc', 'host' => '10.0.0.5']);
    check('config/database.local.php ganha aos valores por omissão', $l['user'] === 'app' && $l['pass'] === 'abc' && $l['host'] === '10.0.0.5' && $l['source'] === 'local');
    $e = db_resolve_config(['user' => 'envuser', 'pass' => 'envpass', 'host' => null, 'port' => '', 'name' => null], ['user' => 'app', 'pass' => 'abc', 'host' => '10.0.0.5', 'port' => '3307']);
    check('a variável de ambiente ganha ao ficheiro local', $e['user'] === 'envuser' && $e['pass'] === 'envpass' && $e['source'] === 'env');
    check('variável vazia conta como ausente (cai para o ficheiro local)', $e['port'] === '3307' && $e['host'] === '10.0.0.5');
    check('valores que não são texto/número são ignorados', db_resolve_config([], ['user' => ['x'], 'host' => new stdClass()])['user'] === 'root');
    check('palavra-passe vazia no ficheiro local é aceite (XAMPP sem palavra-passe)', db_resolve_config([], ['user' => 'u', 'pass' => ''])['pass'] === '');
    putenv('LUMINA_TESTE_VAZIA='); putenv('LUMINA_TESTE_X=valor');
    check('lumina_env: vazia = ausente, preenchida = valor', lumina_env('LUMINA_TESTE_VAZIA') === null && lumina_env('LUMINA_TESTE_X') === 'valor' && lumina_env('LUMINA_TESTE_NAO_EXISTE') === null);
    putenv('LUMINA_TESTE_VAZIA'); putenv('LUMINA_TESTE_X');
    $dir = sys_get_temp_dir() . '/lumina_cfg_' . bin2hex(random_bytes(4)); mkdir($dir);
    file_put_contents($dir . '/teste.local.php', "<?php return ['a' => 1];");
    file_put_contents($dir . '/mau.local.php', "<?php return 'isto não é um array';");
    check('lumina_local_config lê o ficheiro .local.php', lumina_local_config('teste', $dir) === ['a' => 1]);
    check('ficheiro ausente, inválido ou nome com ../ → vazio (nunca erro, nunca sai da pasta)', lumina_local_config('nao_existe', $dir) === [] && lumina_local_config('mau', $dir) === [] && lumina_local_config('../teste', $dir) === []);
    array_map('unlink', glob($dir . '/*.php')); rmdir($dir);
});

section('Configuração da BD: recusa root/sem palavra-passe num site público', function () {
    check('"root" é inseguro (também em maiúsculas)', db_config_unsafe(['user' => 'root', 'pass' => 'x']) && db_config_unsafe(['user' => 'ROOT', 'pass' => 'x']));
    check('sem palavra-passe é inseguro', db_config_unsafe(['user' => 'app', 'pass' => '']));
    check('utilizador dedicado com palavra-passe é seguro', !db_config_unsafe(['user' => 'lumina_app', 'pass' => 'abc']));
    $priv = ['localhost', '127.0.0.1', '::1', '[::1]', '10.1.2.3', '172.16.0.9', '172.31.255.1', '192.168.1.50', 'raspberrypi', 'raspberrypi.local', ''];
    $pub  = ['8.8.8.8', '1.1.1.1', '172.32.0.1', 'lumina.exemplo.pt', 'www.google.com'];
    check('redes locais e loopback são "privadas"', array_filter($priv, fn($h) => !db_host_is_private($h)) === [], json_encode(array_filter($priv, fn($h) => !db_host_is_private($h))));
    check('endereços da Internet são "públicos"', array_filter($pub, fn($h) => db_host_is_private($h)) === [], json_encode(array_filter($pub, fn($h) => db_host_is_private($h))));
    check('pedido web num IP público → site público', db_is_public_request('8.8.8.8', '', false));
    check('APP_URL com domínio público → site público (mesmo atrás de um proxy em 127.0.0.1)', db_is_public_request('127.0.0.1', 'https://lumina.exemplo.pt', false));
    check('localhost / rede local → não é público', !db_is_public_request('127.0.0.1', '', false) && !db_is_public_request('192.168.1.20', 'http://192.168.1.20/lumina', false));
    check('linha de comandos (testes, cópias de segurança) nunca é "pedido público"', !db_is_public_request('8.8.8.8', 'https://lumina.exemplo.pt', true));
    $root = ['user' => 'root', 'pass' => ''];
    check('root + site público → recusado, com a instrução para o corrigir', str_contains((string)db_safety_error($root, true), 'criar_utilizador_bd'));
    check('root + localhost → permitido (XAMPP continua a funcionar)', db_safety_error($root, false) === null);
    check('utilizador dedicado + site público → permitido', db_safety_error(['user' => 'lumina_app', 'pass' => 'abc'], true) === null);
});

section('Configuração da BD: processo isolado (constantes, erros sem palavra-passe)', function () {
    [$out] = php_run(['-r', 'require "config/database.php"; echo DB_USER, "|", DB_NAME, "|", DB_HOST, "|", db_config()["source"];'], ['LUMINA_DB_USER' => 'abc', 'LUMINA_DB_PASS' => 'def', 'LUMINA_DB_NAME' => 'outra', 'LUMINA_DB_HOST' => '127.0.0.1']);
    check('LUMINA_DB_* chega às constantes DB_*', $out === 'abc|outra|127.0.0.1|env', $out);
    $segredo = 'palavra-secreta-' . bin2hex(random_bytes(4));
    [$out, $err] = php_run(['-r', 'require "config/database.php"; try { db(); echo "ligou"; } catch (Throwable $e) { echo get_class($e), "|", $e->getMessage(), "|", var_export($e->getPrevious() === null, true), "|", $e->getTraceAsString(); }'],
        ['LUMINA_DB_USER' => 'utilizador_que_nao_existe', 'LUMINA_DB_PASS' => $segredo, 'LUMINA_DB_HOST' => '127.0.0.1']);
    check('login falhado: mensagem útil em português', str_contains($out, 'RuntimeException') && str_contains($out, 'utilizador ou palavra-passe incorretos'), $out);
    check('a palavra-passe não aparece na mensagem, no rasto nem no ecrã', !str_contains($out . $err, $segredo));
    check('a exceção original não é encadeada (o rasto levaria os argumentos da ligação)', str_contains($out, '|true|'));
    [$out] = php_run(['-r', 'require "config/database.php"; try { db(); echo "ligou"; } catch (Throwable $e) { echo $e->getMessage(); }'], ['LUMINA_DB_PORT' => '1', 'LUMINA_DB_HOST' => '127.0.0.1', 'LUMINA_DB_USER' => 'x', 'LUMINA_DB_PASS' => 'y']);
    check('servidor MySQL desligado → mensagem a dizê-lo', str_contains($out, 'não responde'), $out);
});

section('Configuração da BD: site "público" com root é recusado (pedido web real)', function () {
    $root = dirname(__DIR__, 2);
    $prepend = sys_get_temp_dir() . '/lumina_fake_addr_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($prepend, "<?php \$_SERVER['SERVER_ADDR'] = '8.8.8.8';");           // o servidor "escuta" num IP público
    $run = function (?string $prependFile) use ($root): array {
        $sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1]; fclose($sock);
        $log = sys_get_temp_dir() . '/lumina_srv_' . bin2hex(random_bytes(4)) . '.log';
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, '-d', 'log_errors=1', '-d', 'error_log=' . $log];
        if ($prependFile) { array_push($cmd, '-d', 'auto_prepend_file=' . $prependFile); }
        $env = array_fill_keys(['LUMINA_DB_HOST', 'LUMINA_DB_PORT', 'LUMINA_DB_NAME', 'LUMINA_DB_USER', 'LUMINA_DB_PASS'], '') + (getenv() ?: []);
        $p = proc_open(array_merge($cmd, [$root . '/router.php']), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env);
        for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port, $en, $es, 0.1)) { break; } usleep(100000); }
        // "me" não toca na base de dados; o login sim. Duas chamadas com o mesmo cookie de sessão.
        $jar = tempnam(sys_get_temp_dir(), 'lj');
        $call = function (string $method, string $path, ?array $body = null) use ($port, $jar): array {
            $ch = curl_init("http://127.0.0.1:$port/$path");
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'], CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null]);
            $r = (string)curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            return [$st, $r];
        };
        [, $me] = $call('GET', 'api/auth.php?action=me');
        $csrf = (string)(json_decode($me, true)['csrf'] ?? '');
        [$status, $body] = $call('POST', 'api/auth.php?action=login', ['email' => 'ninguem@teste.pt', 'password' => 'errada123', 'csrf' => $csrf]);
        @unlink($jar);
        proc_terminate($p); proc_close($p);
        $logText = is_file($log) ? (string)file_get_contents($log) : ''; @unlink($log);
        return [$status, $body, $logText];
    };
    [$s1, $b1, $l1] = $run($prepend);
    check('root + servidor num IP público → 500 (a aplicação recusa arrancar)', $s1 === 500, "estado $s1");
    check('o motivo fica no log do PHP, em português', str_contains($l1, 'não se liga à base de dados como "root"'), $l1);
    check('...e o cliente NÃO vê o motivo (resposta genérica)', !str_contains($b1, 'root') && !str_contains($b1, 'criar_utilizador_bd'), $b1);
    [$s2, $b2] = $run(null);
    check('o mesmo servidor em localhost funciona normalmente (login errado → 401, não 500)', $s2 === 401, "estado $s2: " . substr($b2, 0, 120));
    @unlink($prepend);
});

section('bin/criar_utilizador_bd.php: utilizador só com SELECT/INSERT/UPDATE/DELETE', function () {
    $admin = test_admin_pdo();
    if (!$admin) { echo "  (saltado: sem conta de administração do MySQL; defina LUMINA_DB_ADMIN_USER / LUMINA_DB_ADMIN_PASS)\n"; return; }
    $base = 'lumina_teste_cfg'; $user = 'lumina_teste_app'; $file = sys_get_temp_dir() . '/lumina_teste_db_' . bin2hex(random_bytes(4)) . '.php';
    $cleanup = function () use ($admin, $base, $user, $file) {
        $admin->exec("DROP DATABASE IF EXISTS `$base`");
        foreach (['localhost', '127.0.0.1'] as $h) { $admin->exec("DROP USER IF EXISTS '$user'@'$h'"); }
        @unlink($file);
    };
    $cleanup();
    $admin->exec("CREATE DATABASE `$base` CHARACTER SET utf8mb4"); $admin->exec("CREATE TABLE `$base`.users (id INT)"); $admin->exec("INSERT INTO `$base`.users VALUES (1)");
    $connect = fn(string $u, string $p) => new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ";dbname=$base;charset=utf8mb4", $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $can = function (PDO $pdo, string $sql): bool { try { $pdo->exec($sql); return true; } catch (PDOException $e) { return false; } };
    $args = ["--base=$base", "--utilizador=$user", "--ficheiro=$file"];
    $adminEnv = ['LUMINA_DB_ADMIN_HOST' => getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST, 'LUMINA_DB_ADMIN_PORT' => getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT];
    try {
        [$out, $err, $rc] = php_run(array_merge(['bin/criar_utilizador_bd.php'], $args), $adminEnv);
        check('o script termina com sucesso', $rc === 0, $err . $out);
        $cfg = is_file($file) ? (array)require $file : [];
        $pw = (string)($cfg['pass'] ?? '');
        check('guardou utilizador, base e palavra-passe (≥ 32 caracteres) no ficheiro local', ($cfg['user'] ?? '') === $user && ($cfg['name'] ?? '') === $base && strlen($pw) >= 32);
        check('a palavra-passe NÃO é mostrada no ecrã', $pw !== '' && !str_contains($out . $err, $pw));
        if (DIRECTORY_SEPARATOR === '/') { check('ficheiro só legível pelo dono (0600)', (fileperms($file) & 0777) === 0600, decoct(fileperms($file) & 0777)); }
        $app = $connect($user, $pw);
        check('o novo utilizador lê e escreve dados', $can($app, 'INSERT INTO users VALUES (2)') && $can($app, 'UPDATE users SET id = 3 WHERE id = 2') && $can($app, 'DELETE FROM users WHERE id = 3') && (int)$app->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1);
        check('...mas não cria, altera nem apaga tabelas', !$can($app, 'CREATE TABLE t (id INT)') && !$can($app, 'ALTER TABLE users ADD COLUMN x INT') && !$can($app, 'DROP TABLE users'));
        check('...nem cria utilizadores nem dá permissões', !$can($app, "CREATE USER 'intruso'@'localhost'") && !$can($app, "GRANT ALL ON `$base`.* TO '$user'@'localhost'"));
        check('...nem vê a base de dados "mysql" (palavras-passe dos utilizadores)', !$can($app, 'SELECT 1 FROM mysql.user LIMIT 1'));
        [, , $rc2] = php_run(array_merge(['bin/criar_utilizador_bd.php'], $args), $adminEnv);
        check('repetir sem --rodar recusa (não troca a palavra-passe por engano)', $rc2 === 1);
        [, $err3, $rc3] = php_run(array_merge(['bin/criar_utilizador_bd.php', '--rodar'], $args), $adminEnv);
        $cfg2 = (array)require $file;
        check('--rodar troca a palavra-passe', $rc3 === 0 && ($cfg2['pass'] ?? '') !== $pw, $err3);
        $oldWorks = true; try { $connect($user, $pw); } catch (PDOException $e) { $oldWorks = false; }
        check('a palavra-passe antiga deixa de funcionar', !$oldWorks);
        [, $err4, $rc4] = php_run(['bin/criar_utilizador_bd.php', "--utilizador=x'; DROP DATABASE $base; --", "--ficheiro=$file.x"], $adminEnv);
        check('nome de utilizador com SQL é recusado antes de tocar na base de dados', $rc4 === 1 && (int)$admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$base'")->fetchColumn() === 1, $err4);
        [, , $rc5] = php_run(['bin/criar_utilizador_bd.php', '--utilizador=root', "--ficheiro=$file.y"], $adminEnv);
        check('não aceita "root" como utilizador da aplicação', $rc5 === 1);
        // perfil "backup": só leitura, e mantém o que o utilizador já pôs no ficheiro (pasta, frase-passe)
        $bkFile = $file . '.backup.php'; $bkUser = 'lumina_teste_bk';
        file_put_contents($bkFile, "<?php return ['dir' => '/var/backups/lumina', 'passphrase' => 'frase-do-utilizador-123'];");
        [$o6, $e6, $rc6] = php_run(['bin/criar_utilizador_bd.php', '--para=backup', "--base=$base", "--utilizador=$bkUser", "--ficheiro=$bkFile"], $adminEnv);
        $bk = $rc6 === 0 ? (array)require $bkFile : [];
        check('--para=backup cria um utilizador de cópias e guarda-o no ficheiro, sem apagar a pasta e a frase-passe já lá escritas', $rc6 === 0 && ($bk['user'] ?? '') === $bkUser && strlen((string)($bk['pass'] ?? '')) >= 32 && ($bk['dir'] ?? '') === '/var/backups/lumina' && ($bk['passphrase'] ?? '') === 'frase-do-utilizador-123', $e6 . $o6);
        $bkp = $connect($bkUser, (string)($bk['pass'] ?? ''));
        check('o utilizador de cópias lê mas NÃO escreve', (int)$bkp->query('SELECT COUNT(*) FROM users')->fetchColumn() === 1 && !$can($bkp, 'INSERT INTO users VALUES (9)') && !$can($bkp, 'DELETE FROM users WHERE 1 = 0') && !$can($bkp, 'CREATE TABLE t2 (id INT)'));
        foreach (['localhost', '127.0.0.1'] as $h) { $admin->exec("DROP USER IF EXISTS '$bkUser'@'$h'"); }
        @unlink($bkFile);
    } finally {
        $cleanup();
    }
});

section('Segredos: IA e e-mail aceitam variáveis de ambiente', function () {
    [$out] = php_run(['-r', 'require "includes/auth.php"; require "includes/ai_tools.php"; $c = ai_config(); echo $c["api_key"], "|", $c["db_user"], "|", $c["db_pass"];'], ['LUMINA_AI_API_KEY' => 'chave-ia-teste', 'LUMINA_AI_DB_USER' => 'ia_leitura', 'LUMINA_AI_DB_PASS' => 'ia-pass']);
    check('LUMINA_AI_API_KEY / LUMINA_AI_DB_USER / LUMINA_AI_DB_PASS chegam a ai_config()', $out === 'chave-ia-teste|ia_leitura|ia-pass', $out);
    [$out] = php_run(['-r', 'require "includes/auth.php"; require "includes/ai_tools.php"; $c = ai_config(); echo var_export($c["api_key"] === "", true);'], ['LUMINA_AI_API_KEY' => '', 'LUMINA_AI_DB_USER' => '', 'LUMINA_AI_DB_PASS' => '']);
    check('sem variável nem ficheiro local, a chave da IA fica vazia (modo básico)', $out === 'true', $out);
    [$out] = php_run(['-r', 'require "includes/mailer.php"; echo mail_config()["smtp"]["password"];'], ['LUMINA_SMTP_PASS' => 'smtp-pass-teste']);
    check('LUMINA_SMTP_PASS chega à configuração de e-mail', $out === 'smtp-pass-teste', $out);
});

section('Segredos: o repositório não tem chaves, palavras-passe nem tokens', function () {
    $found = secret_scan(dirname(__DIR__, 2));
    check('nenhum segredo escrito no código nem ficheiros que nunca devem ir para o GitHub', $found === [], json_encode(array_slice($found, 0, 5)));
    $dir = sys_get_temp_dir() . '/lumina_scan_' . bin2hex(random_bytes(4));
    mkdir($dir . '/config', 0777, true); mkdir($dir . '/includes');
    $token = 'gh' . 'p_' . str_repeat('A', 36);                                       // montado em tempo de execução: este ficheiro não pode conter um token completo
    $senha = 'minha-senha-' . 'real-123';
    file_put_contents($dir . '/includes/a.php', "<?php\n\$x = '$token';\n");
    file_put_contents($dir . '/config/b.php', "<?php\nreturn ['smtp_password' => '$senha'];\n");
    file_put_contents($dir . '/config/c.php', "<?php\nreturn ['password' => getenv('X') ?: '', 'api_key' => 'TROCA_ESTA_CHAVE_AQUI', 'token' => \$_SESSION['t']];\n");
    file_put_contents($dir . '/includes/d.php', "<?php\nreturn ['pass' => 'LUMINA_BACKUP_DB_PASS', 'password' => 'GF_SMTP_PASSWORD'];\n");   // nomes de variáveis de ambiente: não são segredos
    file_put_contents($dir . '/config/database.local.php', '<?php return [];');
    file_put_contents($dir . '/config/app_key.php', '<?php return "x";');
    file_put_contents($dir . '/lumina_gestao_facil_2026-10-08_031509.sql.gz', 'x');                                  // uma cópia de segurança esquecida no projeto
    file_put_contents($dir . '/schema.sql', 'CREATE TABLE t (id INT);');                                              // SQL de estrutura é legítimo
    $f = secret_scan($dir);
    $names = array_map(fn($r) => $r['file'], $f); sort($names);
    check('o verificador apanha o token, a palavra-passe, as cópias de segurança e os ficheiros proibidos (e só esses; nomes de variáveis de ambiente como LUMINA_DB_PASS não contam)', $names === ['config/app_key.php', 'config/b.php', 'config/database.local.php', 'includes/a.php', 'lumina_gestao_facil_2026-10-08_031509.sql.gz'], json_encode($names));
    check('o resultado nunca contém o valor do segredo', !str_contains(json_encode($f), $token) && !str_contains(json_encode($f), $senha));
    array_map('unlink', array_merge(glob($dir . '/config/*.php'), glob($dir . '/includes/*.php'), glob($dir . '/*.*'))); rmdir($dir . '/config'); rmdir($dir . '/includes'); rmdir($dir);
});
