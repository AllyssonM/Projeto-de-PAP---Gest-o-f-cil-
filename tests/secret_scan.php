<?php
/* =========================================================================
   PROCURA DE SEGREDOS NO CÓDIGO  (tests/secret_scan.php)
   -------------------------------------------------------------------------
   Regra do Lumina: chaves, palavras-passe e tokens NUNCA ficam no código nem no GitHub. Este verificador procura:
     1. ficheiros que nunca devem estar no repositório (config/*.local.php, config/app_key.php, .env, chaves privadas, cópias .sql.gz);
     2. formatos conhecidos de chaves (GitHub, Google, Meta, Anthropic/OpenAI, AWS, Slack, chaves privadas PEM);
     3. palavras-passe/segredos escritos à mão em ficheiros de código de produção (config/, includes/, api/, raiz).
   NUNCA imprime o valor encontrado, só o ficheiro, a linha e a regra (para não espalhar o segredo pelos registos).

   COMO CORRER:  php tests/secret_scan.php          (sai com código 1 se encontrar algo; usado no GitHub Actions)
   ========================================================================= */
declare(strict_types=1);

/** Ficheiros a verificar: os que o Git acompanha (ou, sem Git, todos menos pastas de dados). @return list<string> */
function secret_scan_files(string $root): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files = [];
    if (is_dir($root . '/.git') && function_exists('exec')) {
        @exec('git -C ' . escapeshellarg($root) . ' ls-files -z 2>/dev/null', $lines, $rc);
        if ($rc === 0 && $lines) { $files = array_values(array_filter(explode("\0", implode("\0", $lines)))); }
    }
    if (!$files) {                                                                  // sem git: percorre a pasta
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(str_replace($root, '', str_replace('\\', '/', $f->getPathname())), '/');
            if (!preg_match('#^(\.git|node_modules|storage/(mail|logs|avatars|logos|receipts))/#', $rel) && $f->isFile()) { $files[] = $rel; }
        }
    }
    return $files;
}

/** @return list<array{file:string,line:int,rule:string}> */
function secret_scan(string $root, ?array $files = null): array
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $files ??= secret_scan_files($root);
    $out = [];
    $forbidden = '#(^|/)(config/[^/]+\.local\.php|config/app_key\.php|\.env(\..+)?|.*\.pem|id_rsa|id_ed25519|.*\.sql\.gz(\.enc|\.parcial|\.sha256)?|storage/backup-status\.json)$#';
    $known = [
        'chave privada PEM'       => '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |PGP )?PRIVATE KEY/',
        'token do GitHub'         => '/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b|\bgithub_pat_[A-Za-z0-9_]{40,}\b/',
        'chave da Google'         => '/\bAIza[0-9A-Za-z_\-]{35}\b/',
        'token da Meta (Graph)'   => '/\bEAA[A-Za-z0-9]{50,}\b/',
        'chave Anthropic/OpenAI'  => '/\bsk-(?:ant-)?[A-Za-z0-9_\-]{30,}\b/',
        'chave AWS'               => '/\bAKIA[0-9A-Z]{16}\b/',
        'token do Slack'          => '/\bxox[baprs]-[A-Za-z0-9\-]{10,}\b/',
    ];
    $assign = '/[\'"]?(?:password|passwd|pass|secret|api_?key|app_?secret|client_?secret|access_?token|token)[\'"]?\s*(?:=>|=|:)\s*([\'"])([^\'"\s$][^\'"\s]{7,})\1/i';
    $placeholder = '/troca|exemplo|example|changeme|xxxx|\*\*\*|placeholder|your[_-]|o-teu|seu[_-]|sua[_-]|<|\.\.\./i';
    foreach ($files as $rel) {
        if ($rel === 'tests/secret_scan.php') { continue; }                          // este ficheiro descreve os padrões
        if (preg_match($forbidden, $rel)) { $out[] = ['file' => $rel, 'line' => 0, 'rule' => 'ficheiro que nunca deve estar no repositório']; continue; }
        $path = $root . '/' . $rel;
        if (!is_file($path) || filesize($path) > 1_500_000 || preg_match('/\.(png|jpe?g|webp|gif|ico|mp4|webm|woff2?|zip|pdf|docx?|pptx?|xlsx?)$/i', $rel)) { continue; }
        $prod = (bool)preg_match('#^(config|includes|api|bin)/.+\.php$|^[^/]+\.php$#', $rel);   // código de produção
        $lineNo = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $lineNo++;
            foreach ($known as $name => $re) { if (preg_match($re, $line)) { $out[] = ['file' => $rel, 'line' => $lineNo, 'rule' => $name]; } }
            if ($prod && preg_match($assign, $line, $m) && !preg_match($placeholder, $m[2]) && !preg_match('/getenv|lumina_env|\$_|random_|password_hash/i', $line)) {
                $out[] = ['file' => $rel, 'line' => $lineNo, 'rule' => 'palavra-passe/segredo escrito no código'];
            }
        }
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $files = secret_scan_files(dirname(__DIR__));
    $found = secret_scan(dirname(__DIR__), $files);
    foreach ($found as $f) { echo "SEGREDO? {$f['file']}:{$f['line']}  {$f['rule']}\n"; }
    echo $found ? "\n" . count($found) . " ocorrência(s). Remova o valor, rode a chave (ela já pode estar comprometida) e use variáveis de ambiente ou config/*.local.php.\n"
                : 'Nenhum segredo encontrado em ' . count($files) . " ficheiros verificados.\n";
    exit($found ? 1 : 0);
}
