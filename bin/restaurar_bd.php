<?php
/* =========================================================================
   RESTAURAR UMA CÓPIA DE SEGURANÇA  (bin/restaurar_bd.php)
   -------------------------------------------------------------------------
   COMO USAR:    php bin/restaurar_bd.php /var/backups/lumina/lumina_gestao_facil_2026-10-08_030000.sql.gz
   O QUE FAZ:    - confirma a soma SHA-256 e relê a cópia INTEIRA antes de tocar em qualquer base de dados;
                 - por omissão restaura para uma base NOVA (gestao_facil_restauro_AAAAMMDD_HHMMSS): a base em uso não é tocada.
                   Abra-a, confira os dados e, se estiver certa, aponte o Lumina para ela (LUMINA_DB_NAME) ou copie o que precisar.
                 - para SUBSTITUIR uma base existente é preciso  --base=NOME --sobrescrever --confirmo=NOME ; antes é feita
                   automaticamente uma cópia dessa base (etiqueta "antes-do-restauro") na pasta de segurança.
   OPÇÕES:       --base=NOME   --sobrescrever   --confirmo=NOME   --pasta-seguranca=PASTA (por omissão a pasta da própria cópia)
   ADMIN:        precisa de uma conta que possa criar bases: LUMINA_DB_ADMIN_USER / LUMINA_DB_ADMIN_PASS (por omissão root sem palavra-passe).
   CIFRADAS:     a frase-passe vem de LUMINA_BACKUP_PASSPHRASE ou config/backup.local.php.
   ========================================================================= */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/backup_lib.php';

function falhar(string $msg): never { fwrite(STDERR, "ERRO: $msg\n"); exit(1); }

$file = null; $args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? '1'; }
    elseif ($file === null) { $file = $a; }
    else { falhar("Argumento a mais: $a"); }
}
foreach (array_keys($args) as $k) { if (!in_array($k, ['base', 'sobrescrever', 'confirmo', 'pasta-seguranca', 'ajuda'], true)) { falhar("Opção desconhecida: --$k"); } }
if ($file === null || isset($args['ajuda'])) { echo "Uso: php bin/restaurar_bd.php FICHEIRO [--base=NOME] [--sobrescrever --confirmo=NOME] [--pasta-seguranca=PASTA]\n"; exit($file === null && !isset($args['ajuda']) ? 1 : 0); }

$parsed = backup_parse_filename(basename($file));
$target = $args['base'] ?? (($parsed['db'] ?? DB_NAME) . '_restauro_' . date('Ymd_His'));
$admin = ['host' => getenv('LUMINA_DB_ADMIN_HOST') ?: DB_HOST, 'port' => getenv('LUMINA_DB_ADMIN_PORT') ?: DB_PORT, 'user' => getenv('LUMINA_DB_ADMIN_USER') ?: 'root', 'pass' => getenv('LUMINA_DB_ADMIN_PASS') ?: ''];
$cfg = backup_config();

try {
    $r = backup_restore($file, $target, $admin, $cfg['passphrase'], isset($args['sobrescrever']), $args['confirmo'] ?? null, $args['pasta-seguranca'] ?? dirname(realpath($file) ?: $file));
} catch (Throwable $e) {
    falhar($e->getMessage());
}
echo "Restauro concluído na base '{$r['db']}': {$r['tables']} tabelas.\n";
foreach ($r['rows'] as $t => $n) { if ($n > 0) { echo "  $t: $n linha(s)\n"; } }
if ($r['safety'] !== null) { echo "A base anterior ficou guardada em: {$r['safety']}\n"; }
if ($r['db'] !== DB_NAME) { echo "O Lumina continua a usar '" . DB_NAME . "'. Para usar a restaurada: LUMINA_DB_NAME={$r['db']} (e dê-lhe permissões, ver bin/criar_utilizador_bd.php --base={$r['db']}).\n"; }
exit(0);
