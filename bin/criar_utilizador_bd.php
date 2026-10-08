<?php
/* =========================================================================
   CRIAR O UTILIZADOR DA BASE DE DADOS DA APLICAÇÃO  (bin/criar_utilizador_bd.php)
   -------------------------------------------------------------------------
   PARA QUÊ:  o Lumina não deve falar com o MySQL como "root". Este script cria um utilizador (por omissão "lumina_app") que SÓ pode
              ler e escrever dados nesta base (SELECT, INSERT, UPDATE, DELETE): não cria nem apaga tabelas, não gere utilizadores.
              A palavra-passe é aleatória e fica APENAS em config/database.local.php (ignorado pelo Git, permissões 0600).
              Nunca é mostrada no ecrã nem enviada para o GitHub.
   COMO:      php bin/criar_utilizador_bd.php                 (XAMPP: C:\xampp\php\php.exe bin\criar_utilizador_bd.php)
   OPÇÕES:    --utilizador=lumina_app   --base=gestao_facil   --rodar (troca a palavra-passe)   --ficheiro=caminho (onde guardar os dados)
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
if (isset($args['ajuda'])) { echo "Uso: php bin/criar_utilizador_bd.php [--utilizador=lumina_app] [--base=gestao_facil] [--rodar] [--ficheiro=config/database.local.php]\n"; exit(0); }
foreach (array_keys($args) as $k) { if (!in_array($k, ['utilizador', 'base', 'rodar', 'ficheiro', 'ajuda'], true)) { falhar("Opção desconhecida: --$k"); } }

$utilizador = $args['utilizador'] ?? 'lumina_app';
$base       = $args['base'] ?? 'gestao_facil';
$ficheiro   = $args['ficheiro'] ?? (__DIR__ . '/../config/database.local.php');
$rodar      = isset($args['rodar']);
// Os nomes entram em instruções GRANT (não aceitam parâmetros): só letras, números e "_".
if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $utilizador)) { falhar('O nome do utilizador só pode ter letras, números e "_" (máx. 32).'); }
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) { falhar('O nome da base de dados só pode ter letras, números e "_" (máx. 64).'); }
if (strtolower($utilizador) === 'root') { falhar('Escolha um nome diferente de "root".'); }
if (is_file($ficheiro) && !$rodar) { falhar("O ficheiro $ficheiro já existe. Para trocar a palavra-passe use --rodar; para o recriar apague-o primeiro."); }

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
    $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `$base`.* TO $conta");
}
$admin->exec('FLUSH PRIVILEGES');

// Verifica de verdade: o novo utilizador lê e escreve, mas NÃO consegue criar tabelas.
try {
    $app = new PDO("mysql:host=$host;port=$porta;dbname=$base;charset=utf8mb4", $utilizador, $palavraPasse, $opts);
    $app->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (PDOException $e) {
    falhar('O utilizador foi criado mas não consegue ler a base de dados: ' . preg_replace('/\s+/', ' ', $e->getMessage()));
}
$consegueCriar = true;
try { $app->exec('CREATE TABLE lumina__prova_permissoes (id INT)'); $app->exec('DROP TABLE lumina__prova_permissoes'); }
catch (PDOException $e) { $consegueCriar = false; }
if ($consegueCriar) { $admin->exec("DROP TABLE IF EXISTS `$base`.lumina__prova_permissoes"); falhar('O utilizador conseguiu criar tabelas: as permissões não ficaram no mínimo. Nada foi guardado.'); }

$conteudo = "<?php\n// Gerado por bin/criar_utilizador_bd.php em " . date('Y-m-d H:i') . ".\n"
          . "// FICHEIRO SECRETO: está no .gitignore. Nunca o envie para o GitHub nem o partilhe.\n"
          . 'return ' . var_export(['host' => $host, 'port' => $porta, 'name' => $base, 'user' => $utilizador, 'pass' => $palavraPasse], true) . ";\n";
$tmp = $ficheiro . '.tmp' . getmypid();
if (file_put_contents($tmp, $conteudo, LOCK_EX) === false) { falhar("Não consegui escrever $ficheiro (sem permissão?)."); }
@chmod($tmp, 0600);
if (!rename($tmp, $ficheiro)) { @unlink($tmp); falhar("Não consegui guardar $ficheiro."); }

echo "Utilizador '$utilizador' pronto na base '$base' (só SELECT, INSERT, UPDATE e DELETE).\n";
echo "Os dados da ligação foram guardados em $ficheiro (fora do GitHub). A palavra-passe não é mostrada.\n";
if (getenv('LUMINA_DB_USER') || getenv('LUMINA_DB_PASS')) { echo "ATENÇÃO: há variáveis LUMINA_DB_* definidas; elas têm prioridade sobre este ficheiro.\n"; }
echo "Agora o Lumina deixa de usar o root. Guarde uma cópia de segurança do ficheiro num local seguro.\n";
