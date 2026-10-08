<?php
/* =========================================================================
   VERIFICAÇÃO DE SAÚDE  (includes/health.php; usada por health.php → endereço /health)
   -------------------------------------------------------------------------
   PARA QUÊ:  um endereço que um monitor (UptimeRobot, Uptime Kuma, o próprio Raspberry Pi...) consulta para saber se o Lumina está vivo
              e se consegue falar com a base de dados.

   VISTA PÚBLICA (qualquer pessoa pode pedir):
       {"status":"ok|degraded|down","checks":{"app":"ok","database":"ok","storage":"ok"}}
       HTTP 200 (ok ou degraded) ou 503 (down). Nunca mostra versões, caminhos, nome da base de dados, utilizadores nem mensagens de erro:
       o motivo verdadeiro vai só para o log de erros do PHP.

   VISTA DETALHADA (só para quem tem o segredo): cabeçalho  X-Health-Token  igual a LUMINA_HEALTH_TOKEN (ou "token" em config/health.local.php,
       com 16 ou mais carateres). Acrescenta: migrações da base de dados, idade da última cópia de segurança, canal de e-mail, espaço em disco,
       chave de cifra e extensões do PHP. Sem segredo configurado, esta vista está DESLIGADA. Um segredo errado recebe exatamente a vista pública
       (não revela que existe uma vista detalhada). O segredo nunca vai no endereço (URL), porque os URLs ficam nos registos dos servidores.

   NÃO FAZ: não escreve no disco, não escreve na base de dados, não cria chaves, não inicia sessão, não envia e-mails.
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

/** Migrações que têm de estar aplicadas (v10 regista também v1–v9). Ao criar uma migração nova, acrescente-a aqui: um teste confirma que a lista bate certo com database/. */
const HEALTH_REQUIRED_MIGRATIONS = ['v10', 'v11', 'v12', 'v13', 'v14', 'v16', 'v17', 'v18', 'v19'];
/** Migrações antigas que se aplicavam sem se registarem em schema_migrations: se esta tabela existir, a migração conta como aplicada. */
const HEALTH_UNREGISTERED_PROBES = ['v14' => 'meta_connections'];
/** Sem estas extensões do PHP o Lumina não funciona bem; as recomendadas têm alternativa no código (cURL → streams; GD → ficheiro como veio) ou só servem às cópias de segurança (zlib). */
const HEALTH_REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'sodium', 'session', 'ctype', 'json', 'openssl'];
const HEALTH_RECOMMENDED_EXTENSIONS = ['curl', 'gd', 'zlib'];
const HEALTH_BACKUP_MAX_AGE_HOURS = 36;
const HEALTH_DISK_LOW_PERCENT = 10.0;
const HEALTH_DISK_CRITICAL_PERCENT = 3.0;
const HEALTH_TOKEN_MIN_LENGTH = 16;

/** O segredo da vista detalhada (null = desligada). Prioridade: variável de ambiente > config/health.local.php. */
function health_token(?string $configDir = null): ?string
{
    $token = lumina_env('LUMINA_HEALTH_TOKEN') ?? lumina_local_config('health', $configDir)['token'] ?? null;
    return is_string($token) && strlen($token) >= HEALTH_TOKEN_MIN_LENGTH ? $token : null;
}

/** O segredo recebido bate certo com o configurado? (comparação em tempo constante; sem segredo configurado, ou curto demais, nunca) */
function health_token_ok(?string $given, ?string $expected): bool
{
    if ($expected === null || strlen($expected) < HEALTH_TOKEN_MIN_LENGTH || $given === null || $given === '') {
        return false;
    }
    return hash_equals($expected, $given);
}

/** Pastas de dados que têm de aceitar escrita: storage/ e as subpastas que já existem. */
function health_storage_dirs(): array
{
    $root = __DIR__ . '/../storage';
    return array_merge([$root], glob($root . '/*', GLOB_ONLYDIR) ?: []);
}

/** Todas as pastas existem e aceitam escrita? (não escreve nada: só pergunta ao sistema) */
function health_check_storage(array $dirs): bool
{
    foreach ($dirs as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
    }
    return $dirs !== [];
}

/**
 * Quais das migrações obrigatórias faltam? FUNÇÃO PURA.
 * @param string[] $recorded versões registadas em schema_migrations
 * @param string[] $tables   tabelas que existem (só para as migrações de HEALTH_UNREGISTERED_PROBES)
 * @return string[]
 */
function health_pending_migrations(array $recorded, array $tables, array $required = HEALTH_REQUIRED_MIGRATIONS, array $probes = HEALTH_UNREGISTERED_PROBES): array
{
    $pending = [];
    foreach ($required as $version) {
        if (in_array($version, $recorded, true)) {
            continue;
        }
        if (isset($probes[$version]) && in_array($probes[$version], $tables, true)) {
            continue;
        }
        $pending[] = $version;
    }
    return $pending;
}

/** @return array{status:string,pending:string[]} */
function health_check_schema(PDO $pdo): array
{
    $recorded = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $tables = [];
    if (array_diff(array_intersect(HEALTH_REQUIRED_MIGRATIONS, array_keys(HEALTH_UNREGISTERED_PROBES)), $recorded)) {
        $tables = $pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    }
    $pending = health_pending_migrations(array_map('strval', $recorded), array_map('strval', $tables));
    return ['status' => $pending === [] ? 'ok' : 'fail', 'pending' => $pending];
}

/**
 * Estado da última cópia de segurança, a partir do que bin/backup_bd.php escreveu em storage/backup-status.json. FUNÇÃO PURA.
 * @return array{status:string,age_hours:?float,encrypted:?bool}   status: none (nunca houve) | ok | stale (mais antiga que $maxHours)
 */
function health_backup_state(?array $status, DateTimeImmutable $now, int $maxHours = HEALTH_BACKUP_MAX_AGE_HOURS): array
{
    $when = is_array($status) && is_string($status['last_success'] ?? null) ? date_create_immutable($status['last_success']) : false;
    if ($when === false) {
        return ['status' => 'none', 'age_hours' => null, 'encrypted' => null];
    }
    $age = max(0.0, ($now->getTimestamp() - $when->getTimestamp()) / 3600);          // relógio adiantado → idade 0, não negativa
    return ['status' => $age <= $maxHours ? 'ok' : 'stale', 'age_hours' => round($age, 1), 'encrypted' => (bool)($status['encrypted'] ?? false)];
}

function health_read_backup_status(?string $file = null): ?array
{
    $file ??= __DIR__ . '/../storage/backup-status.json';
    $raw = is_file($file) && is_readable($file) ? file_get_contents($file) : false;
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

/** Espaço livre (%) no disco onde está o Lumina; null se o sistema não o diz. */
function health_disk_free_percent(?string $path = null): ?float
{
    $path ??= dirname(__DIR__);
    $free = @disk_free_space($path);
    $total = @disk_total_space($path);
    return is_float($free) && is_float($total) && $total > 0 ? round($free / $total * 100, 1) : null;
}

/** unknown | ok | low (< 10 %) | critical (< 3 %). FUNÇÃO PURA. */
function health_disk_state(?float $freePercent): string
{
    return match (true) {
        $freePercent === null => 'unknown',
        $freePercent < HEALTH_DISK_CRITICAL_PERCENT => 'critical',
        $freePercent < HEALTH_DISK_LOW_PERCENT => 'low',
        default => 'ok',
    };
}

/** @param ?string[] $loaded extensões carregadas (por omissão as do PHP em uso). @return array{missing:string[],recommended_missing:string[]} */
function health_check_extensions(?array $loaded = null): array
{
    $loaded = array_map('strtolower', $loaded ?? get_loaded_extensions());
    return ['missing' => array_values(array_diff(HEALTH_REQUIRED_EXTENSIONS, $loaded)), 'recommended_missing' => array_values(array_diff(HEALTH_RECOMMENDED_EXTENSIONS, $loaded))];
}

/**
 * A chave de cifra existe e é válida? NÃO a cria (app_key() cria-a ao primeiro uso; uma verificação de saúde não escreve ficheiros).
 * @return array{status:string,source:string}  source: env | file | will_create (ainda não existe, mas a pasta aceita escrita)
 */
function health_check_crypto_key(?string $keyFile = null, ?string $envValue = null): array
{
    $keyFile ??= __DIR__ . '/../config/app_key.php';
    $envValue ??= (string)(getenv('GF_APP_KEY') ?: '');
    if ($envValue !== '') {
        return ['status' => 'ok', 'source' => 'env'];
    }
    if (!is_file($keyFile)) {
        return ['status' => is_writable(dirname($keyFile)) ? 'ok' : 'fail', 'source' => 'will_create'];
    }
    $key = base64_decode((string)(@include $keyFile), true);
    return ['status' => $key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? 'ok' : 'fail', 'source' => 'file'];
}

/**
 * Junta os estados num só: down (a aplicação não serve: sem base de dados) | degraded (serve, mas algo precisa de atenção) | ok.
 * @param array<string,string> $checks  valores: ok, unknown (não conta), ou qualquer outro (fail, stale, none, low, critical) = atenção
 */
function health_overall(array $checks): string
{
    if (($checks['app'] ?? 'ok') === 'fail' || ($checks['database'] ?? 'ok') === 'fail') {
        return 'down';
    }
    foreach ($checks as $state) {
        if (!in_array($state, ['ok', 'unknown'], true)) {
            return 'degraded';
        }
    }
    return 'ok';
}

/** @return array{http:int,body:array} */
function health_collect(bool $detailed, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', app_timezone());
    $checks = ['app' => 'ok', 'database' => 'fail', 'storage' => health_check_storage(health_storage_dirs()) ? 'ok' : 'fail'];
    $details = [];
    $pdo = null;
    try {
        $started = microtime(true);
        $pdo = db();                                                             // db() recusa "root" em sites públicos e já limpa as mensagens de erro
        $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();    // prova ligação, permissão de leitura e que o Lumina está instalado
        $checks['database'] = 'ok';
        $details['database_ms'] = (int)round((microtime(true) - $started) * 1000);
    } catch (Throwable $e) {
        $pdo = null;
        error_log('Lumina saúde: base de dados indisponível (' . get_class($e) . '): ' . $e->getMessage());
    }

    if ($detailed) {
        if ($pdo instanceof PDO) {
            try {
                $schema = health_check_schema($pdo);
                $checks['schema'] = $schema['status'];
                $details['migrations_pending'] = $schema['pending'];
            } catch (Throwable $e) {
                $checks['schema'] = 'fail';
                error_log('Lumina saúde: não consegui ler as migrações (' . get_class($e) . '): ' . $e->getMessage());
            }
        } else {
            $checks['schema'] = 'unknown';
        }
        $backup = health_backup_state(health_read_backup_status(), $now);
        $checks['backup'] = $backup['status'];
        $details['backup_age_hours'] = $backup['age_hours'];
        $details['backup_encrypted'] = $backup['encrypted'];
        $disk = health_disk_free_percent();
        $checks['disk'] = health_disk_state($disk);
        $details['disk_free_percent'] = $disk;
        $key = health_check_crypto_key();
        $checks['crypto_key'] = $key['status'];
        $details['crypto_key_source'] = $key['source'];
        $ext = health_check_extensions();
        $checks['php_extensions'] = $ext['missing'] === [] ? 'ok' : 'fail';
        $details['php_extensions_missing'] = $ext['missing'];
        $details['php_extensions_recommended_missing'] = $ext['recommended_missing'];
        $driver = (string)(mail_config()['driver'] ?? '');
        $details['mail_driver'] = in_array($driver, ['log', 'smtp', 'mail'], true) ? $driver : 'unknown';     // "log" = não envia e-mails reais
    }

    $status = health_overall($checks);
    $body = ['status' => $status, 'checks' => $checks];
    if ($detailed) {
        $body['details'] = $details;
    }
    return ['http' => $status === 'down' ? 503 : 200, 'body' => $body];
}
