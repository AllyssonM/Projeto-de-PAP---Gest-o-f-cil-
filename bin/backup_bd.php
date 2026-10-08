<?php
/* =========================================================================
   CÓPIA DE SEGURANÇA DA BASE DE DADOS  (bin/backup_bd.php)
   -------------------------------------------------------------------------
   COMO USAR:    php bin/backup_bd.php --destino=/var/backups/lumina        (XAMPP: ver backup_bd.bat)
   O QUE FAZ:    1. cria  lumina_<base>_AAAA-MM-DD_HHMMSS.sql.gz  (ou .sql.gz.enc se houver frase-passe) na pasta de destino;
                 2. relê-a e verifica (fim do ficheiro, nº de tabelas; se cifrada, a decifragem também a autentica);
                 3. guarda a soma SHA-256 ao lado (.sha256);
                 4. apaga as antigas segundo a retenção (7 dias, 4 semanas, 6 meses, por omissão), SÓ se tudo correu bem.
   OPÇÕES:       --destino=PASTA (ou LUMINA_BACKUP_DIR)   --manter=D,S,M (dias,semanas,meses)   --sem-limpeza   --simular (mostra o que apagaria)
                 --verificar-restauro (restaura para uma base temporária e confirma as linhas; precisa da conta de administração)
   SEGREDOS:     credenciais do utilizador de cópias em LUMINA_BACKUP_DB_USER / LUMINA_BACKUP_DB_PASS ou config/backup.local.php
                 (crie-o com  php bin/criar_utilizador_bd.php --para=backup ); sem isso usa as da aplicação.
                 Frase-passe de cifra: LUMINA_BACKUP_PASSPHRASE (≥ 16 caracteres) ou config/backup.local.php. Guarde-a num sítio
                 seguro e SEPARADO: sem ela as cópias cifradas não se abrem.
   SAÍDA:        código 0 = tudo bem; 1 = falhou (nada foi apagado). Seguro para cron / Agendador de Tarefas.
   ========================================================================= */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.php';          // só define as constantes DB_* (não liga)
require_once __DIR__ . '/../includes/backup_lib.php';

function falhar(string $msg): never { fwrite(STDERR, "ERRO: $msg\n"); exit(1); }

function backup_human(int $b): string
{
    return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : ($b >= 1024 ? round($b / 1024, 1) . ' KB' : $b . ' B');
}

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? '1'; } else { falhar("Argumento desconhecido: $a"); }
}
foreach (array_keys($args) as $k) { if (!in_array($k, ['destino', 'manter', 'sem-limpeza', 'simular', 'verificar-restauro', 'base', 'ajuda'], true)) { falhar("Opção desconhecida: --$k"); } }
if (isset($args['ajuda'])) { echo "Uso: php bin/backup_bd.php --destino=PASTA [--manter=7,4,6] [--sem-limpeza] [--simular] [--verificar-restauro]\n"; exit(0); }

$cfg = backup_config();
$dir = $args['destino'] ?? $cfg['dir'] ?? '';
$db  = $args['base'] ?? DB_NAME;
[$daily, $weekly, $monthly] = [$cfg['daily'], $cfg['weekly'], $cfg['monthly']];
if (isset($args['manter'])) {
    if (!preg_match('/^(\d{1,4}),(\d{1,3}),(\d{1,3})$/', $args['manter'], $m)) { falhar('--manter tem de ser dias,semanas,meses (ex.: 7,4,6).'); }
    [$daily, $weekly, $monthly] = [(int)$m[1], (int)$m[2], (int)$m[3]];
}
$usingAppCreds = $cfg['user'] === null;
$user = $cfg['user'] ?? DB_USER;
$pass = $cfg['user'] === null ? DB_PASS : (string)$cfg['pass'];

try {
    $r = backup_create(['host' => DB_HOST, 'port' => DB_PORT, 'db' => $db, 'user' => $user, 'pass' => $pass, 'dir' => $dir,
                        'passphrase' => $cfg['passphrase'], 'when' => new DateTimeImmutable('now', app_timezone())]);
} catch (Throwable $e) {
    falhar($e->getMessage());
}
echo 'Cópia criada: ' . $r['path'] . "\n";
echo sprintf("  %d tabelas, %s (SQL %s), %s, SHA-256 %s…, %.1fs\n", $r['tables'], backup_human($r['bytes']), backup_human($r['sql_bytes']), $r['encrypted'] ? 'CIFRADA' : 'sem cifra', substr($r['sha256'], 0, 16), $r['seconds']);
if (!$r['encrypted']) { echo "  AVISO: sem frase-passe a cópia não está cifrada (contém dados pessoais e hashes de palavras-passe). Defina LUMINA_BACKUP_PASSPHRASE.\n"; }
if ($usingAppCreds) { echo "  Nota: a usar as credenciais da aplicação. Recomendado: php bin/criar_utilizador_bd.php --para=backup (utilizador só de leitura).\n"; }

if (isset($args['verificar-restauro'])) {
    $admin = ['host' => getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST, 'port' => getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT, 'user' => getenv('LUMINA_DB_ADMIN_USER') ?: 'root', 'pass' => getenv('LUMINA_DB_ADMIN_PASS') ?: ''];
    $tmp = 'lumina_verif_' . bin2hex(random_bytes(4));
    try {
        $rest = backup_restore($r['path'], $tmp, $admin, $cfg['passphrase']);
        $src = backup_pdo(DB_HOST, DB_PORT, $db, $user, $pass);
        $diff = [];
        foreach ($rest['rows'] as $t => $n) {
            $live = (int)$src->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $t) . '`')->fetchColumn();
            if ($live !== $n) { $diff[] = "$t: cópia $n, base agora $live"; }
        }
        echo "  Restauro de teste OK: {$rest['tables']} tabelas repostas numa base temporária.\n";
        if ($diff) { echo "  Diferenças de linhas (normal se houve escritas durante a cópia): " . implode('; ', $diff) . "\n"; }
    } catch (Throwable $e) {
        falhar('O restauro de teste falhou: ' . $e->getMessage());
    } finally {
        try { backup_pdo($admin['host'], $admin['port'], null, $admin['user'], $admin['pass'])->exec('DROP DATABASE IF EXISTS `' . $tmp . '`'); } catch (Throwable $e) { /* melhor esforço */ }
    }
}

if (!isset($args['sem-limpeza'])) {
    $gone = backup_prune(dirname($r['path']), $db, new DateTimeImmutable('now', app_timezone()), $daily, $weekly, $monthly, isset($args['simular']));
    echo $gone ? ('  Retenção (' . "$daily dias, $weekly semanas, $monthly meses" . '): ' . (isset($args['simular']) ? 'apagaria ' : 'apagadas ') . count($gone) . ' cópia(s) antiga(s): ' . implode(', ', $gone) . "\n")
               : "  Retenção ($daily dias, $weekly semanas, $monthly meses): nada para apagar.\n";
}

// Estado para o /health: só datas e tamanho, nunca segredos.
$status = ['last_success' => date('c'), 'file' => $r['name'], 'bytes' => $r['bytes'], 'encrypted' => $r['encrypted'], 'tables' => $r['tables']];
$statusFile = __DIR__ . '/../storage/backup-status.json';
if (is_dir(dirname($statusFile)) && is_writable(dirname($statusFile))) { @file_put_contents($statusFile, json_encode($status, JSON_UNESCAPED_SLASHES), LOCK_EX); }
exit(0);
