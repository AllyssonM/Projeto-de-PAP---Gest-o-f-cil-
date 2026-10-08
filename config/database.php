<?php
/* =========================================================================
   LIGAÇÃO À BASE DE DADOS  (config/database.php)
   -------------------------------------------------------------------------
   Este ficheiro NÃO tem palavras-passe e vai para o GitHub. Os dados da ligação vêm de (por ordem de prioridade):

     1. variáveis de ambiente  LUMINA_DB_HOST  LUMINA_DB_PORT  LUMINA_DB_NAME  LUMINA_DB_USER  LUMINA_DB_PASS
     2. config/database.local.php   (só nesta máquina, fora do GitHub; é criado por:  php bin/criar_utilizador_bd.php)
     3. valores de desenvolvimento do XAMPP (127.0.0.1, gestao_facil, root, sem palavra-passe)

   O utilizador que a aplicação usa só precisa de SELECT, INSERT, UPDATE e DELETE nesta base de dados. Quem cria tabelas
   (instalar_base_dados.bat, migrações) é uma conta de administração, usada à parte e nunca pela aplicação.

   SEGURANÇA: num site acessível pela Internet o Lumina RECUSA ligar-se como "root" ou sem palavra-passe
   (ver db_config_unsafe e db_is_public_request). Em localhost e em redes locais o XAMPP continua a funcionar sem configurar nada.
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../includes/env.php';

/**
 * Junta as fontes de configuração da ligação. FUNÇÃO PURA (não lê o ambiente nem ficheiros): recebe tudo por parâmetro.
 * @param array<string,?string> $env   chaves host, port, name, user, pass (null = variável ausente)
 * @param array<string,mixed>   $local conteúdo de config/database.local.php
 * @return array{host:string,port:string,name:string,user:string,pass:string,source:string}
 */
function db_resolve_config(array $env, array $local): array
{
    $defaults = ['host' => '127.0.0.1', 'port' => '3306', 'name' => 'gestao_facil', 'user' => 'root', 'pass' => ''];
    $out = [];
    $fromEnv = $fromLocal = false;
    foreach ($defaults as $key => $default) {
        if (($env[$key] ?? null) !== null && $env[$key] !== '') {
            $out[$key] = (string)$env[$key];
            $fromEnv = true;
        } elseif (array_key_exists($key, $local) && is_scalar($local[$key]) && ((string)$local[$key] !== '' || $key === 'pass')) {
            $out[$key] = (string)$local[$key];
            $fromLocal = true;
        } else {
            $out[$key] = $default;
        }
    }
    $out['source'] = $fromEnv ? 'env' : ($fromLocal ? 'local' : 'default');
    return $out;
}

/** Os dados da ligação em uso neste pedido (lidos uma só vez). */
function db_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $env = [];
        foreach (['host', 'port', 'name', 'user', 'pass'] as $key) {
            $env[$key] = lumina_env('LUMINA_DB_' . strtoupper($key));
        }
        $cfg = db_resolve_config($env, lumina_local_config('database'));
    }
    return $cfg;
}

/** Uma ligação com "root" ou sem palavra-passe nunca deve ser usada num site público. */
function db_config_unsafe(array $cfg): bool
{
    return strtolower($cfg['user']) === 'root' || $cfg['pass'] === '';
}

/** O endereço (de APP_URL ou do servidor) é de rede local/loopback? (localhost, 127.x, 10.x, 172.16-31.x, 192.168.x, *.local) */
function db_host_is_private(string $host): bool
{
    $host = strtolower(trim($host, '[] '));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
    return !str_contains($host, '.');          // nome sem ponto (ex.: "raspberrypi") só existe na rede local
}

/**
 * Este pedido vem de um site acessível pela Internet? Sim se APP_URL (endereço público escolhido em config/app.php) não é local,
 * ou se o IP em que o servidor está a escutar é público. Na linha de comandos (testes, cópias de segurança) é sempre não.
 */
function db_is_public_request(?string $serverAddr = null, ?string $appUrl = null, ?bool $cli = null): bool
{
    if ($cli ?? (PHP_SAPI === 'cli')) {
        return false;
    }
    $appUrl ??= APP_URL;
    if ($appUrl !== '') {
        $host = (string)parse_url($appUrl, PHP_URL_HOST);
        if ($host !== '' && !db_host_is_private($host)) {
            return true;
        }
    }
    $serverAddr ??= (string)($_SERVER['SERVER_ADDR'] ?? '');
    return $serverAddr !== '' && !db_host_is_private($serverAddr);
}

define('DB_HOST', db_config()['host']);
define('DB_PORT', db_config()['port']);
define('DB_NAME', db_config()['name']);
define('DB_USER', db_config()['user']);
define('DB_PASS', db_config()['pass']);

/** Mensagem de recusa se esta configuração não pode ser usada neste pedido (null = pode). */
function db_safety_error(array $cfg, ?bool $publicRequest = null): ?string
{
    if (!db_config_unsafe($cfg) || !($publicRequest ?? db_is_public_request())) {
        return null;
    }
    return 'Por segurança, o Lumina não se liga à base de dados como "root" ou sem palavra-passe num site acessível pela Internet. '
        . 'Crie um utilizador só para a aplicação: php bin/criar_utilizador_bd.php (ver README > Base de dados).';
}

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }
    if (($unsafe = db_safety_error(db_config())) !== null) {
        throw new RuntimeException($unsafe);
    }
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $connection = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // O relógio do MySQL (NOW(), CURRENT_TIMESTAMP) passa a ser o da aplicação (config/app.php). Sem isto, se o servidor de base de dados
        // estiver noutro fuso, as horas escritas pelo PHP e pelo MySQL ficariam trocadas (ex.: uma entrada às 09:39 apareceria às 08:39).
        $connection->exec("SET time_zone = '" . (new DateTimeImmutable('now', app_timezone()))->format('P') . "'");
    } catch (PDOException $error) {
        if (str_contains($error->getMessage(), 'Unknown database')) {
            throw new RuntimeException('A base de dados gestao_facil ainda não foi criada. Execute instalar_base_dados.bat ou importe database/gestao_facil.sql no phpMyAdmin.');
        }
        // Não repassa a exceção original: o seu rasto podia levar os argumentos da ligação (a palavra-passe) para os registos.
        // A mensagem do MySQL (sem a palavra-passe: só diz "using password: YES/NO") fica no log do PHP.
        error_log('Lumina BD: ' . $error->getMessage());
        $why = match ((int)($error->errorInfo[1] ?? 0)) {
            1045 => 'utilizador ou palavra-passe incorretos',
            1044 => 'o utilizador não tem acesso a esta base de dados',
            2002, 2003, 2006 => 'o servidor MySQL não responde (está ligado?)',
            default => 'erro ' . (int)($error->errorInfo[1] ?? 0),
        };
        throw new RuntimeException('Não foi possível ligar à base de dados: ' . $why . '. Confirme LUMINA_DB_* ou config/database.local.php.');
    }
    return $connection;
}
?>
