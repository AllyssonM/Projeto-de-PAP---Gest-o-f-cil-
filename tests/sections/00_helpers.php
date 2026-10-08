<?php
/* Ajudantes partilhados pelas secções de teste em tests/sections/ (carregado primeiro). */

/** Corre um ficheiro PHP (ou um excerto, se $code) noutro processo, com variáveis de ambiente à medida. @return array{0:string,1:string,2:int} stdout, stderr, código de saída */
function php_run(array $argv, array $env = [], ?string $stdin = null): array
{
    // LUMINA_DB_* do processo que corre os testes não podem contaminar os testes de "valores por omissão".
    $clean = array_fill_keys(['LUMINA_DB_HOST', 'LUMINA_DB_PORT', 'LUMINA_DB_NAME', 'LUMINA_DB_USER', 'LUMINA_DB_PASS'], '');
    $full = $env + $clean + (getenv() ?: []);
    $p = proc_open(array_merge([PHP_BINARY], $argv), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $full);
    if (!is_resource($p)) { return ['', 'proc_open falhou', 255]; }
    fwrite($pipes[0], $stdin ?? ''); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [(string)$out, (string)$err, proc_close($p)];
}

/** Conta de administração do MySQL para os testes que criam bases/utilizadores temporários (LUMINA_DB_ADMIN_*; por omissão root sem palavra-passe, como no XAMPP). null = indisponível. */
function test_admin_pdo(): ?PDO
{
    try {
        return new PDO('mysql:host=' . (getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST) . ';port=' . (getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT) . ';charset=utf8mb4',
            getenv('LUMINA_DB_ADMIN_USER') ?: 'root', getenv('LUMINA_DB_ADMIN_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Arranca o servidor embutido do PHP (com router.php) numa porta livre, num processo à parte. $env junta-se ao ambiente atual
 * (LUMINA_HEALTH_TOKEN vem vazio, salvo se for dado); $ini são opções -d do PHP. @return array{port:int,log:string,stop:callable}
 */
function test_server_start(array $env = [], array $ini = []): array
{
    $root = dirname(__DIR__, 2);
    $sock = stream_socket_server('tcp://127.0.0.1:0'); $port = (int)explode(':', (string)stream_socket_get_name($sock, false))[1]; fclose($sock);
    $log = sys_get_temp_dir() . '/lumina_srv_' . bin2hex(random_bytes(4)) . '.log';
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, '-d', 'log_errors=1', '-d', 'error_log=' . $log];
    foreach ($ini as $key => $value) { array_push($cmd, '-d', "$key=$value"); }
    $p = proc_open(array_merge($cmd, [$root . '/router.php']), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $root, $env + ['LUMINA_HEALTH_TOKEN' => ''] + (getenv() ?: []));
    for ($i = 0; $i < 60; $i++) { if (@fsockopen('127.0.0.1', $port, $en, $es, 0.1)) { break; } usleep(100000); }
    return ['port' => $port, 'log' => $log, 'stop' => function () use ($p, $pipes) { foreach ($pipes as $pipe) { @fclose($pipe); } proc_terminate($p); proc_close($p); }];
}

/** Pedido HTTP a um servidor de test_server_start(). @return array{0:int,1:array<string,string>,2:string} estado, cabeçalhos (minúsculas), corpo */
function test_http(int $port, string $method, string $path, array $headers = []): array
{
    $ch = curl_init("http://127.0.0.1:$port/" . ltrim($path, '/'));
    $got = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$got) { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $got[strtolower(trim($k))] = trim($v); } return strlen($line); }]);
    $body = (string)curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$status, $got, $body];
}
