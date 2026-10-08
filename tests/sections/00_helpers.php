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
