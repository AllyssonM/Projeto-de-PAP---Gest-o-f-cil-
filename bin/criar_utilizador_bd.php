<?php
/* =========================================================================
   CRIAR UTILIZADORES DA BASE DE DADOS COM O MÍNIMO DE PODERES  (bin/criar_utilizador_bd.php)
   -------------------------------------------------------------------------
   PARA QUÊ:  o Lumina não deve falar com o MySQL como "root". Este script cria utilizadores com palavra-passe aleatória e só os
              poderes de que cada função precisa. A palavra-passe fica APENAS num ficheiro local (ignorado pelo Git, permissões 0600);
              nunca é mostrada no ecrã nem enviada para o GitHub.

                --para=aplicacao (por omissão)  utilizador "lumina_app":    SELECT, INSERT, UPDATE, DELETE nesta base
                                                  → guarda em config/database.local.php   (não cria nem apaga tabelas)
                --para=backup                   utilizador "lumina_backup": SELECT, SHOW VIEW nesta base
                                                  → guarda em config/backup.local.php     (só lê; usado por bin/backup_bd.php)

   COMO:      php bin/criar_utilizador_bd.php [--para=backup]       (XAMPP: C:\xampp\php\php.exe bin\criar_utilizador_bd.php)
   OPÇÕES:    --utilizador=NOME   --base=gestao_facil   --rodar (troca a palavra-passe)   --ficheiro=caminho (onde guardar os dados)
   ADMIN:     a conta com poderes para criar utilizadores vem de LUMINA_DB_ADMIN_USER / LUMINA_DB_ADMIN_PASS (por omissão root sem
              palavra-passe, como no XAMPP). Nunca fica guardada.
   ========================================================================= */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function falhar(string $msg): never { fwrite(STDERR, "ERRO: $msg\n"); exit(1); }

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) { $args[$m[1]] = $m[2] ?? '1'; } else { falhar("Argumento desconhecido: $a (use --ajuda)"); }
}
if (isset($args['ajuda'])) { echo "Uso: php bin/criar_utilizador_bd.php [--para=aplicacao|backup] [--utilizador=NOME] [--base=gestao_facil] [--rodar] [--ficheiro=caminho]\n"; exit(0); }
foreach (array_keys($args) as $k) { if (!in_array($k, ['para', 'utilizador', 'base', 'rodar', 'ficheiro', 'ajuda'], true)) { falhar("Opção desconhecida: --$k"); } }

$perfis = [
    'aplicacao' => ['utilizador' => 'lumina_app',    'privilegios' => 'SELECT, INSERT, UPDATE, DELETE', 'ficheiro' => __DIR__ . '/../config/database.local.php', 'completo' => true,
                    'mostra' => 'só SELECT, INSERT, UPDATE e DELETE', 'agora' => 'Agora o Lumina deixa de usar o root.'],
    'backup'    => ['utilizador' => 'lumina_backup', 'privilegios' => 'SELECT, SHOW VIEW',              'ficheiro' => __DIR__ . '/../config/backup.local.php',   'completo' => false,
                    'mostra' => 'só SELECT e SHOW VIEW (leitura)', 'agora' => 'bin/backup_bd.php passa a usar este utilizador (defina também a pasta e a frase-passe, ver README).'],
];
$para = $args['para'] ?? 'aplicacao';
if (!isset($perfis[$para])) { falhar('--para tem de ser "aplicacao" ou "backup".'); }
$perfil = $perfis[$para];

$utilizador = $args['utilizador'] ?? $perfil['utilizador'];
$base       = $args['base'] ?? 'gestao_facil';
$ficheiro   = $args['ficheiro'] ?? $perfil['ficheiro'];
$rodar      = isset($args['rodar']);
// Os nomes entram em instruções GRANT (não aceitam parâmetros): só letras, números e "_".
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $utilizador)) { falhar('O nome do utilizador só pode ter letras, números e "_" (máx. 32).'); }
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) { falhar('O nome da base de dados só pode ter letras, números e "_" (máx. 64).'); }
if (strtolower($utilizador) === 'root') { falhar('Escolha um nome diferente de "root".'); }
if (is_file($ficheiro) && !$rodar && $para === 'aplicacao') { falhar("O ficheiro $ficheiro já existe. Para trocar a palavra-passe use --rodar; para o recriar apague-o primeiro."); }
if ($para === 'backup' && is_file($ficheiro) && !$rodar) {
    $existente = (array)(require $ficheiro);
    if (isset($existente['user'])) { falhar("O ficheiro $ficheiro já tem um utilizador de cópias. Para trocar a palavra-passe use --rodar."); }
}

$host  = getenv('LUMINA_DB_ADMIN_HOST') ?: '127.0.0.1';
$porta = getenv('LUMINA_DB_ADMIN_PORT') ?: '3306';
$adminUser = getenv('LUMINA_DB_ADMIN_USER') ?: 'root';
$adminPass = getenv('LUMINA_DB_ADMIN_PASS') ?: '';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];

try {
    $admin = new PDO("mysql:host=$host;port=$porta;charset=utf8mb4", $adminUser, $adminPass, $opts);
} catch (PDOException $e) {
    falhar('Não consegui ligar como administrador (' . $adminUser . '). Se o root tem palavra-passe, defina LUMINA_DB_ADMIN_PASS. Detalhe: ' . preg_replace('/\s+/', ' ', $e->getMessage()));
}
$existe = $admin->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
$existe->execute([$base]);
if ((int)$existe->fetchColumn() === 0) { falhar("A base de dados $base não existe. Execute primeiro instalar_base_dados.bat (ou importe database/gestao_facil.sql)."); }

$palavraPasse = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');         // 32 caracteres, só A-Z a-z 0-9 - _
$lit = $admin->quote($palavraPasse);
foreach (['localhost', '127.0.0.1'] as $h) {
    $conta = "'$utilizador'@'$h'";
    $admin->exec("CREATE USER IF NOT EXISTS $conta IDENTIFIED BY $lit");
    $admin->exec("ALTER USER $conta IDENTIFIED BY $lit");
    $admin->exec("REVOKE ALL PRIVILEGES, GRANT OPTION FROM $conta");                  // se já existia com mais poderes, volta ao mínimo
    $admin->exec("GRANT {$perfil['privilegios']} ON `$base`.* TO $conta");
}
$admin->exec('FLUSH PRIVILEGES');

// Verifica de verdade: o novo utilizador lê; e NÃO tem os poderes que não deve ter.
try {
    $app = new PDO("mysql:host=$host;port=$porta;dbname=$base;charset=utf8mb4", $utilizador, $palavraPasse, $opts);
    $app->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (PDOException $e) {
    falhar('O utilizador foi criado mas não consegue ler a base de dados: ' . preg_replace('/\s+/', ' ', $e->getMessage()));
}
$consegue = static function (PDO $pdo, string $sql): bool { try { $pdo->exec($sql); return true; } catch (PDOException $e) { return false; } };
if ($consegue($app, 'CREATE TABLE lumina__prova_permissoes (id INT)')) {
    $admin->exec("DROP TABLE IF EXISTS `$base`.lumina__prova_permissoes");
    falhar('O utilizador conseguiu criar tabelas: as permissões não ficaram no mínimo. Nada foi guardado.');
}
if ($perfil['completo'] ? !$consegue($app, 'DELETE FROM users WHERE 1 = 0') : $consegue($app, 'DELETE FROM users WHERE 1 = 0')) {
    falhar($perfil['completo'] ? 'O utilizador da aplicação não consegue escrever dados: verifique as permissões.' : 'O utilizador de cópias conseguiu escrever dados: as permissões não ficaram só de leitura. Nada foi guardado.');
}

// Ficheiros que o utilizador já personalizou (ex.: pasta e frase-passe das cópias) mantêm as outras chaves.
$atual = is_file($ficheiro) ? (array)(require $ficheiro) : [];
$novo = $perfil['completo'] ? ['host' => $host, 'port' => $porta, 'name' => $base, 'user' => $utilizador, 'pass' => $palavraPasse] : ['user' => $utilizador, 'pass' => $palavraPasse];
$conteudo = "<?php\n// Gerado por bin/criar_utilizador_bd.php em " . date('Y-m-d H:i') . ".\n"
          . "// FICHEIRO SECRETO: está no .gitignore. Nunca o envie para o GitHub nem o partilhe.\n"
          . 'return ' . var_export(array_merge($atual, $novo), true) . ";\n";
$tmp = $ficheiro . '.tmp' . getmypid();
if (file_put_contents($tmp, $conteudo, LOCK_EX) === false) { falhar("Não consegui escrever $ficheiro (sem permissão?)."); }
@chmod($tmp, 0600);
if (!rename($tmp, $ficheiro)) { @unlink($tmp); falhar("Não consegui guardar $ficheiro."); }

echo "Utilizador '$utilizador' pronto na base '$base' ({$perfil['mostra']}).\n";
echo "Os dados da ligação foram guardados em $ficheiro (fora do GitHub). A palavra-passe não é mostrada.\n";
if ($para === 'aplicacao' && (getenv('LUMINA_DB_USER') || getenv('LUMINA_DB_PASS'))) { echo "ATENÇÃO: há variáveis LUMINA_DB_* definidas; elas têm prioridade sobre este ficheiro.\n"; }
echo $perfil['agora'] . " Guarde uma cópia deste ficheiro num local seguro.\n";
