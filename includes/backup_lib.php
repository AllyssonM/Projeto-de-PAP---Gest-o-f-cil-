<?php
/* =========================================================================
   CÓPIAS DE SEGURANÇA DA BASE DE DADOS  (includes/backup_lib.php)
   -------------------------------------------------------------------------
   Usado por bin/backup_bd.php e bin/restaurar_bd.php (linha de comandos). Nunca é carregado por páginas web.

   O QUE GARANTE
     - NOMES COM DATA:   lumina_<base>_AAAA-MM-DD_HHMMSS.sql.gz   (e .sql.gz.enc se cifrada)
     - SEM SEGREDOS NO COMANDO: as credenciais vão para um ficheiro temporário 0600 (--defaults-extra-file), nunca para a
                          linha de comandos (onde qualquer utilizador da máquina as veria com "ps").
     - CIFRA OPCIONAL:   com uma frase-passe (LUMINA_BACKUP_PASSPHRASE) o ficheiro é cifrado com libsodium
                          (Argon2id + XChaCha20-Poly1305 em blocos). Quem perder a frase-passe perde a cópia.
     - ATÓMICA:          escreve para um ficheiro .parcial e só o renomeia quando está completo e verificado; uma falha nunca
                          deixa uma "cópia" a meio com cara de boa.
     - VERIFICADA:       relê a cópia (a decifragem autentica-a), confirma o fim do ficheiro e o número de tabelas.
     - RETENÇÃO:         guarda a mais recente + a mais nova de cada um dos últimos N dias, semanas e meses; apaga o resto,
                          e SÓ ficheiros com o nome das nossas cópias.
   ========================================================================= */
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const BACKUP_MAGIC = "LUMBK1\n";            // início dos ficheiros cifrados
const BACKUP_CHUNK = 65536;                 // bytes em claro por bloco cifrado

/* ------------------------------------------------------------------ configuração */

/**
 * Junta as fontes de configuração. FUNÇÃO PURA.
 * @param array<string,?string> $env   dir, user, pass, passphrase, daily, weekly, monthly (null = ausente)
 * @param array<string,mixed>   $local conteúdo de config/backup.local.php
 */
function backup_resolve_config(array $env, array $local): array
{
    $text = static function (string $key) use ($env, $local): ?string {
        if (($env[$key] ?? null) !== null && $env[$key] !== '') { return (string)$env[$key]; }
        return isset($local[$key]) && is_scalar($local[$key]) && (string)$local[$key] !== '' ? (string)$local[$key] : null;
    };
    $count = static function (string $key, int $default) use ($text): int {
        $v = $text($key);
        return $v !== null && ctype_digit($v) ? max(0, min(3650, (int)$v)) : $default;
    };
    return [
        'dir'        => $text('dir'),
        'user'       => $text('user'),
        'pass'       => $text('pass'),
        'passphrase' => $text('passphrase'),
        'daily'      => $count('daily', 7),
        'weekly'     => $count('weekly', 4),
        'monthly'    => $count('monthly', 6),
    ];
}

/** A configuração em uso (ambiente + config/backup.local.php). */
function backup_config(): array
{
    $env = [];
    foreach (['dir' => 'LUMINA_BACKUP_DIR', 'user' => 'LUMINA_BACKUP_DB_USER', 'pass' => 'LUMINA_BACKUP_DB_PASS', 'passphrase' => 'LUMINA_BACKUP_PASSPHRASE',
              'daily' => 'LUMINA_BACKUP_KEEP_DAILY', 'weekly' => 'LUMINA_BACKUP_KEEP_WEEKLY', 'monthly' => 'LUMINA_BACKUP_KEEP_MONTHLY'] as $key => $name) {
        $env[$key] = lumina_env($name);
    }
    return backup_resolve_config($env, lumina_local_config('backup'));
}

/* ------------------------------------------------------------------ nomes e retenção */

function backup_filename(string $db, DateTimeImmutable $when, bool $encrypted = false): string
{
    return 'lumina_' . $db . '_' . $when->format('Y-m-d_His') . '.sql.gz' . ($encrypted ? '.enc' : '');
}

/** @return array{db:string,when:DateTimeImmutable,encrypted:bool}|null  null = não é um nome de cópia nossa */
function backup_parse_filename(string $name, ?DateTimeZone $tz = null): ?array
{
    if (!preg_match('/^lumina_([A-Za-z0-9_]{1,64})_(\d{4}-\d{2}-\d{2}_\d{6})\.sql\.gz(\.enc)?$/', $name, $m)) { return null; }
    $when = DateTimeImmutable::createFromFormat('!Y-m-d_His', $m[2], $tz ?? new DateTimeZone(date_default_timezone_get()));
    $errors = DateTimeImmutable::getLastErrors();
    if ($when === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) { return null; }     // ex.: mês 13 ou dia 45
    return ['db' => $m[1], 'when' => $when, 'encrypted' => ($m[3] ?? '') !== ''];
}

/**
 * Que cópias apagar? Mantém: a mais recente; a mais nova de cada um dos últimos $daily dias, $weekly semanas ISO e $monthly meses.
 * Só considera nomes de cópias desta base; tudo o resto (outros ficheiros, outras bases, cópias manuais com outro nome) fica intocado.
 * FUNÇÃO PURA. @param list<string> $names nomes de ficheiros da pasta  @return list<string> nomes a apagar
 */
function backup_select_to_delete(array $names, string $db, DateTimeImmutable $now, int $daily, int $weekly, int $monthly): array
{
    $items = [];
    foreach ($names as $name) {
        $p = backup_parse_filename((string)$name, $now->getTimezone());
        if ($p !== null && $p['db'] === $db) { $items[(string)$name] = $p['when']; }
    }
    if (!$items) { return []; }
    uksort($items, static fn($a, $b) => [$items[$b]->getTimestamp(), $b] <=> [$items[$a]->getTimestamp(), $a]);   // mais recente primeiro
    // Contas em "dias civis" (independentes de horário de verão): nº de dias desde 1970 da data local.
    $civil  = static fn(DateTimeImmutable $d): int => intdiv(gmmktime(0, 0, 0, (int)$d->format('n'), (int)$d->format('j'), (int)$d->format('Y')), 86400);
    $monday = static fn(DateTimeImmutable $d): int => $civil($d) - ((int)$d->format('N') - 1);        // dia civil da segunda-feira dessa semana
    $keep = [array_key_first($items) => true];
    $seen = ['d' => [], 'w' => [], 'm' => []];
    foreach ($items as $name => $when) {
        $dayAge   = $civil($now) - $civil($when);
        $weekAge  = intdiv($monday($now) - $monday($when), 7);
        $monthAge = ((int)$now->format('Y') - (int)$when->format('Y')) * 12 + ((int)$now->format('n') - (int)$when->format('n'));
        foreach ([['d', $when->format('Y-m-d'), $dayAge, $daily], ['w', (string)$monday($when), $weekAge, $weekly], ['m', $when->format('Y-m'), $monthAge, $monthly]] as [$k, $slot, $age, $limit]) {
            if ($age >= 0 && $age < $limit && !isset($seen[$k][$slot])) { $seen[$k][$slot] = true; $keep[$name] = true; }
        }
    }
    return array_values(array_diff(array_keys($items), array_keys($keep)));
}

/* ------------------------------------------------------------------ ferramentas do MySQL/MariaDB */

/** Caminho de mysqldump/mariadb-dump (kind 'dump') ou do cliente mysql/mariadb (kind 'client'). null = não encontrado. */
function backup_find_binary(string $kind): ?string
{
    $custom = lumina_env($kind === 'dump' ? 'LUMINA_MYSQLDUMP' : 'LUMINA_MYSQL');
    if ($custom !== null) { return is_file($custom) ? $custom : null; }
    $names = $kind === 'dump' ? ['mariadb-dump', 'mysqldump'] : ['mariadb', 'mysql'];
    $dirs = array_merge(explode(PATH_SEPARATOR, (string)getenv('PATH')), ['C:\\xampp\\mysql\\bin', '/opt/lampp/bin', '/usr/local/mysql/bin', '/usr/local/bin', '/usr/bin']);
    $win = DIRECTORY_SEPARATOR === '\\';
    foreach ($names as $name) {
        foreach ($dirs as $dir) {
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . ($win ? '.exe' : '');
            if ($dir !== '' && is_file($path) && ($win || is_executable($path))) { return $path; }
        }
    }
    return null;
}

/** Que opções esta versão do mysqldump/mariadb-dump aceita? (MariaDB e MySQL diferem.) */
function backup_dump_capabilities(string $bin): array
{
    $p = proc_open([$bin, '--help'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['no_tablespaces' => false, 'column_statistics' => false]; }
    $help = (string)stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return ['no_tablespaces' => str_contains($help, '--no-tablespaces'), 'column_statistics' => str_contains($help, '--column-statistics')];
}

/**
 * Cria o ficheiro temporário de opções com as credenciais (0600). Quem chama TEM de o apagar (backup_remove_file) quando acabar.
 * Os valores vão entre aspas e com \ e " escapados, para aceitar qualquer palavra-passe. Caracteres de controlo são recusados.
 */
function backup_write_option_file(string $host, string $port, string $user, string $pass): string
{
    foreach ([$host, $port, $user, $pass] as $v) {
        if (preg_match('/[\x00-\x1f\x7f]/', $v)) { throw new InvalidArgumentException('As credenciais não podem ter caracteres de controlo (quebras de linha, etc.).'); }
    }
    $q = static fn(string $v): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    $path = tempnam(sys_get_temp_dir(), 'lumina_my');
    if ($path === false) { throw new RuntimeException('Não consegui criar um ficheiro temporário.'); }
    @chmod($path, 0600);
    $body = "[client]\nhost=" . $q($host) . "\nport=" . $q($port) . "\nuser=" . $q($user) . "\npassword=" . $q($pass) . "\nprotocol=tcp\n";
    if (file_put_contents($path, $body) === false) { @unlink($path); throw new RuntimeException('Não consegui escrever o ficheiro temporário de credenciais.'); }
    return $path;
}

/** Apaga um ficheiro temporário, sobrescrevendo-o primeiro (credenciais). */
function backup_remove_file(?string $path): void
{
    if ($path !== null && is_file($path)) { @file_put_contents($path, str_repeat("\0", (int)@filesize($path))); @unlink($path); }
}

/** Comando (array, sem shell) do dump. NÃO contém a palavra-passe: ela vai no ficheiro de opções. */
function backup_dump_command(string $bin, string $optionFile, string $db, array $caps): array
{
    $cmd = [$bin, '--defaults-extra-file=' . $optionFile, '--single-transaction', '--quick', '--routines', '--triggers', '--hex-blob', '--default-character-set=utf8mb4'];
    if (!empty($caps['no_tablespaces'])) { $cmd[] = '--no-tablespaces'; }
    if (!empty($caps['column_statistics'])) { $cmd[] = '--column-statistics=0'; }
    $cmd[] = $db;
    return $cmd;
}

/* ------------------------------------------------------------------ escrita: gzip e cifra em fluxo */

/** Destino de escrita em fluxo (ficheiro simples ou cifrado). */
interface BackupSink { public function write(string $data): void; public function close(): void; }

final class BackupFileSink implements BackupSink
{
    /** @var resource */ private $fh;
    public function __construct(string $path)
    {
        $fh = @fopen($path, 'xb');                                       // 'x': nunca sobrescreve
        if ($fh === false) { throw new RuntimeException('Não consegui criar ' . basename($path) . ' (sem permissão ou já existe).'); }
        @chmod($path, 0600);
        $this->fh = $fh;
    }
    public function write(string $data): void
    {
        for ($done = 0, $n = strlen($data); $done < $n;) {
            $w = fwrite($this->fh, substr($data, $done));
            if ($w === false || $w === 0) { throw new RuntimeException('Falha a escrever a cópia (disco cheio?).'); }
            $done += $w;
        }
    }
    public function close(): void { fflush($this->fh); if (function_exists('fsync')) { @fsync($this->fh); } fclose($this->fh); }
}

/** Cifra em blocos (libsodium secretstream). Formato: MAGIC | opslimit u32 | memlimit u32 | salt 16 | cabeçalho do fluxo 24 | [u32 tamanho | bloco]... */
final class BackupEncryptSink implements BackupSink
{
    private $state; private string $buffer = ''; private string $ad; private bool $closed = false;
    public function __construct(private BackupSink $out, string $passphrase)
    {
        if (mb_strlen($passphrase) < 16) { throw new InvalidArgumentException('A frase-passe das cópias tem de ter pelo menos 16 caracteres.'); }
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE; $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        [$this->state, $streamHeader] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        sodium_memzero($key);
        $this->ad = BACKUP_MAGIC . pack('NN', $ops, $mem) . $salt;       // protegido como "dados associados": alterar o cabeçalho invalida tudo
        $this->out->write($this->ad . $streamHeader);
    }
    public function write(string $data): void
    {
        $this->buffer .= $data;
        while (strlen($this->buffer) > BACKUP_CHUNK) {                    // '>' (e não '>='): o último bloco leva sempre a marca FINAL
            $this->emit(substr($this->buffer, 0, BACKUP_CHUNK), SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
            $this->buffer = substr($this->buffer, BACKUP_CHUNK);
        }
    }
    private function emit(string $plain, int $tag): void
    {
        $c = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, $this->ad, $tag);
        $this->out->write(pack('N', strlen($c)) . $c);
    }
    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->emit($this->buffer, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
        $this->buffer = '';
        $this->out->close();
    }
}

/** Comprime em gzip em fluxo e entrega ao destino. */
final class BackupGzipSink implements BackupSink
{
    private $ctx; private bool $closed = false;
    public function __construct(private BackupSink $out) { $this->ctx = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]); }
    public function write(string $data): void { $z = deflate_add($this->ctx, $data, ZLIB_NO_FLUSH); if ($z !== '') { $this->out->write($z); } }
    public function close(): void
    {
        if ($this->closed) { return; }
        $this->closed = true;
        $this->out->write(deflate_add($this->ctx, '', ZLIB_FINISH));
        $this->out->close();
    }
}

/* ------------------------------------------------------------------ leitura: decifra e descomprime em fluxo */

/** Lê o conteúdo SQL de uma cópia (decifra se for preciso e descomprime), por blocos. Lança exceção se a cópia estiver adulterada ou truncada. @return Generator<string> */
function backup_read_sql(string $path, ?string $passphrase = null): Generator
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) { throw new RuntimeException('Não consegui abrir ' . basename($path) . '.'); }
    try {
        $head = (string)fread($fh, strlen(BACKUP_MAGIC));
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        if ($head === BACKUP_MAGIC) {
            if ($passphrase === null || $passphrase === '') { throw new RuntimeException('Esta cópia está cifrada: indique a frase-passe (LUMINA_BACKUP_PASSPHRASE).'); }
            $params = (string)fread($fh, 8); $salt = (string)fread($fh, SODIUM_CRYPTO_PWHASH_SALTBYTES); $sh = (string)fread($fh, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($params) !== 8 || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES || strlen($sh) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) { throw new RuntimeException('Cópia cifrada incompleta (cabeçalho cortado).'); }
            ['o' => $ops, 'm' => $mem] = unpack('No/Nm', $params);
            if ($ops < 1 || $ops > 16 || $mem < 8192 || $mem > 536870912) { throw new RuntimeException('Cópia cifrada com parâmetros inválidos.'); }
            $ad = BACKUP_MAGIC . $params . $salt;
            $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($sh, $key);
            sodium_memzero($key);
            $final = false;
            while (!$final) {
                $len = (string)fread($fh, 4);
                if (strlen($len) !== 4) { throw new RuntimeException('Cópia cifrada truncada (faltam blocos).'); }
                $n = unpack('N', $len)[1];
                if ($n < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $n > BACKUP_CHUNK + 1024) { throw new RuntimeException('Cópia cifrada corrompida (tamanho de bloco inválido).'); }
                $c = (string)fread($fh, $n);
                if (strlen($c) !== $n) { throw new RuntimeException('Cópia cifrada truncada (bloco incompleto).'); }
                $r = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $c, $ad);
                if ($r === false) { throw new RuntimeException('Frase-passe errada ou cópia adulterada (a autenticação falhou).'); }
                [$plain, $tag] = $r;
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
                $sql = inflate_add($inflate, $plain, ZLIB_NO_FLUSH);
                if ($sql === false) { throw new RuntimeException('Conteúdo comprimido inválido.'); }
                if ($sql !== '') { yield $sql; }
            }
            $extra = fread($fh, 1);
            if ($extra !== '' && $extra !== false) { throw new RuntimeException('Cópia cifrada com dados a mais depois do fim.'); }
        } else {
            rewind($fh);
            while (!feof($fh)) {
                $z = fread($fh, BACKUP_CHUNK);
                if ($z === false) { throw new RuntimeException('Erro a ler a cópia.'); }
                if ($z === '') { continue; }
                $sql = @inflate_add($inflate, $z, ZLIB_NO_FLUSH);
                if ($sql === false) { throw new RuntimeException('Ficheiro .gz inválido ou corrompido.'); }
                if ($sql !== '') { yield $sql; }
            }
        }
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) { throw new RuntimeException('Cópia truncada: o fluxo comprimido não chegou ao fim.'); }
    } finally {
        fclose($fh);
    }
}

/**
 * Relê a cópia por inteiro e confirma que serve: decifra/descomprime sem erros, termina com "-- Dump completed" e (se souber)
 * tem tantas tabelas como a base de origem. @return array{ok:bool,tables:int,bytes:int,error:?string}
 */
function backup_verify(string $path, ?string $passphrase, ?int $expectedTables = null): array
{
    $tables = 0; $bytes = 0; $tail = ''; $carry = '';
    try {
        foreach (backup_read_sql($path, $passphrase) as $chunk) {
            $bytes += strlen($chunk);
            $buf = $carry . $chunk;
            $lines = explode("\n", $buf);
            $carry = array_pop($lines);
            foreach ($lines as $line) { if (str_starts_with($line, 'CREATE TABLE ')) { $tables++; } }
            $tail = substr($tail . $chunk, -4096);
        }
        if (str_starts_with($carry, 'CREATE TABLE ')) { $tables++; }
    } catch (Throwable $e) {
        return ['ok' => false, 'tables' => $tables, 'bytes' => $bytes, 'error' => $e->getMessage()];
    }
    if (!str_contains($tail, '-- Dump completed')) { return ['ok' => false, 'tables' => $tables, 'bytes' => $bytes, 'error' => 'A cópia não termina com "-- Dump completed": o dump foi interrompido.']; }
    if ($expectedTables !== null && $tables !== $expectedTables) { return ['ok' => false, 'tables' => $tables, 'bytes' => $bytes, 'error' => "A cópia tem $tables tabelas mas a base tem $expectedTables."]; }
    return ['ok' => true, 'tables' => $tables, 'bytes' => $bytes, 'error' => null];
}

/** SHA-256 de um ficheiro, no formato do ficheiro .sha256 ("hash  nome"). */
function backup_checksum_line(string $path): string
{
    return hash_file('sha256', $path) . '  ' . basename($path) . "\n";
}

/* ------------------------------------------------------------------ criar, apagar as antigas, restaurar */

/** Ligação PDO simples com credenciais explícitas (para contar tabelas, estimar tamanho, restaurar). */
function backup_pdo(string $host, string $port, ?string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host=$host;port=$port;charset=utf8mb4" . ($db !== null ? ";dbname=$db" : '');
    return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

/** Caminho absoluto "real" mesmo que a pasta ainda não exista: resolve o antepassado mais próximo que existe e junta o resto. */
function backup_abs_path(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $rest = [];
    $cur = $path;
    while ($cur !== '' && $cur !== '/' && ($real = realpath($cur)) === false) {
        array_unshift($rest, basename($cur));
        $parent = dirname($cur);
        if ($parent === $cur) { break; }
        $cur = $parent;
    }
    $base = realpath($cur !== '' ? $cur : '.') ?: $cur;
    return rtrim(str_replace('\\', '/', $base), '/') . ($rest ? '/' . implode('/', array_filter($rest, fn($x) => $x !== '.' && $x !== '..')) : '');
}

/** Valida e prepara a pasta das cópias (criada com 0700). Recusa pastas dentro do Lumina. @return string caminho real */
function backup_prepare_dir(string $dir): string
{
    if ($dir === '') { throw new InvalidArgumentException('Indique a pasta das cópias (--destino=... ou LUMINA_BACKUP_DIR).'); }
    $project = realpath(dirname(__DIR__));
    $norm = static fn(string $p): string => rtrim(str_replace('\\', '/', strtolower($p)), '/') . '/';
    if ($project !== false && str_starts_with($norm(backup_abs_path($dir)), $norm($project))) {          // antes de criar nada
        throw new RuntimeException('A pasta das cópias não pode ficar dentro da pasta do Lumina (iria nos ZIP do projeto e podia ficar acessível pela Internet). Escolha uma pasta fora do site.');
    }
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException("Não consegui criar a pasta das cópias: $dir"); }
    $real = realpath($dir);
    if ($real === false || !is_writable($real)) { throw new RuntimeException("Sem permissão de escrita na pasta das cópias: $dir"); }
    return $real;
}

/**
 * Cria uma cópia de segurança completa e verificada.
 * @param array{host:string,port:string,db:string,user:string,pass:string,dir:string,passphrase?:?string,when?:DateTimeImmutable,label?:?string} $o
 * @return array{path:string,name:string,bytes:int,sql_bytes:int,tables:int,sha256:string,encrypted:bool,seconds:float}
 */
function backup_create(array $o): array
{
    $started = microtime(true);
    $db = $o['db'];
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db)) { throw new InvalidArgumentException('Nome de base de dados inválido.'); }
    $label = $o['label'] ?? null;
    if ($label !== null && !preg_match('/^[A-Za-z0-9\-]{1,40}$/', $label)) { throw new InvalidArgumentException('Etiqueta inválida.'); }
    $pass = $o['passphrase'] ?? null;
    $pass = $pass === '' ? null : $pass;
    if ($pass !== null && mb_strlen($pass) < 16) { throw new InvalidArgumentException('A frase-passe das cópias tem de ter pelo menos 16 caracteres.'); }
    $dir = backup_prepare_dir($o['dir']);

    $lock = fopen($dir . DIRECTORY_SEPARATOR . '.lumina-backup.lock', 'c');
    if ($lock !== false) { @chmod($dir . DIRECTORY_SEPARATOR . '.lumina-backup.lock', 0600); }
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Já há outra cópia de segurança em curso nesta pasta.'); }
    $optionFile = null; $errFile = null; $partial = null;
    try {
        $dump = backup_find_binary('dump');
        if ($dump === null) { throw new RuntimeException('Não encontrei o mysqldump/mariadb-dump. No XAMPP está em C:\\xampp\\mysql\\bin; noutros sistemas defina LUMINA_MYSQLDUMP.'); }
        $caps = backup_dump_capabilities($dump);

        // Ligação de teste: valida as credenciais com uma mensagem clara, conta as tabelas e estima o espaço necessário.
        try {
            $pdo = backup_pdo($o['host'], $o['port'], $db, $o['user'], $o['pass']);
            $row = $pdo->prepare('SELECT COUNT(*) AS t, COALESCE(SUM(data_length + index_length), 0) AS b FROM information_schema.tables WHERE table_schema = ? AND table_type = \'BASE TABLE\'');
            $row->execute([$db]);
            $info = $row->fetch();
        } catch (PDOException $e) {
            throw new RuntimeException('Não consegui ligar à base de dados para a cópia: ' . preg_replace('/\s+/', ' ', $e->getMessage()));
        }
        $expectedTables = (int)$info['t'];
        if ($expectedTables === 0) { throw new RuntimeException("A base de dados $db não tem tabelas (ou o utilizador não as vê): não há nada para copiar."); }
        $free = @disk_free_space($dir);
        if ($free !== false && $free < (float)$info['b'] + 20 * 1048576) {
            throw new RuntimeException('Pouco espaço livre na pasta das cópias (' . round($free / 1048576) . ' MB livres; a base ocupa ~' . round((float)$info['b'] / 1048576) . ' MB).');
        }

        $when = $o['when'] ?? new DateTimeImmutable('now');
        $make = static fn(DateTimeImmutable $w): string => $label === null ? backup_filename($db, $w, $pass !== null)
            : 'lumina_' . $db . '_' . $label . '_' . $w->format('Y-m-d_His') . '.sql.gz' . ($pass !== null ? '.enc' : '');
        while (file_exists($dir . DIRECTORY_SEPARATOR . $make($when))) { $when = $when->modify('+1 second'); }    // nunca sobrescreve
        $name = $make($when);
        $final = $dir . DIRECTORY_SEPARATOR . $name;
        $partial = $final . '.parcial';

        $optionFile = backup_write_option_file($o['host'], $o['port'], $o['user'], $o['pass']);
        $errFile = tempnam(sys_get_temp_dir(), 'lumina_err');
        $cmd = backup_dump_command($dump, $optionFile, $db, $caps);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Não consegui iniciar o ' . basename($dump) . '.'); }
        fclose($pipes[0]);

        $file = new BackupFileSink($partial);
        $gz = new BackupGzipSink($pass !== null ? new BackupEncryptSink($file, $pass) : $file);
        $sqlBytes = 0;
        try {
            while (!feof($pipes[1])) {
                $buf = fread($pipes[1], BACKUP_CHUNK);
                if ($buf === false) { break; }
                if ($buf !== '') { $sqlBytes += strlen($buf); $gz->write($buf); }
            }
            $gz->close();
        } finally {
            fclose($pipes[1]);
            $code = proc_close($proc);
        }
        $stderr = trim((string)@file_get_contents($errFile));
        if ($code !== 0) { throw new RuntimeException('O ' . basename($dump) . ' falhou (código ' . $code . ')' . ($stderr !== '' ? ': ' . substr(preg_replace('/\s+/', ' ', $stderr), 0, 400) : '.')); }

        $check = backup_verify($partial, $pass, $expectedTables);
        if (!$check['ok']) { throw new RuntimeException('A cópia criada não passou na verificação: ' . $check['error']); }
        if (!rename($partial, $final)) { throw new RuntimeException('Não consegui guardar a cópia final.'); }
        $partial = null;
        @chmod($final, 0600);
        file_put_contents($final . '.sha256', backup_checksum_line($final));
        @chmod($final . '.sha256', 0600);
        return ['path' => $final, 'name' => $name, 'bytes' => (int)filesize($final), 'sql_bytes' => $sqlBytes, 'tables' => $check['tables'],
                'sha256' => hash_file('sha256', $final), 'encrypted' => $pass !== null, 'seconds' => round(microtime(true) - $started, 2)];
    } finally {
        backup_remove_file($optionFile);
        if ($errFile !== null) { @unlink($errFile); }
        if ($partial !== null) { @unlink($partial); }                    // falhou a meio: nunca deixa uma "cópia" parcial
        flock($lock, LOCK_UN); fclose($lock);
    }
}

/**
 * Apaga as cópias antigas segundo a política de retenção (só ficheiros com o nome das nossas cópias desta base).
 * @return list<string> nomes apagados (ou a apagar, se $dryRun)
 */
function backup_prune(string $dir, string $db, DateTimeImmutable $now, int $daily, int $weekly, int $monthly, bool $dryRun = false): array
{
    $names = [];
    foreach (scandir($dir) ?: [] as $f) { if ($f !== '.' && $f !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $f)) { $names[] = $f; } }
    $delete = backup_select_to_delete($names, $db, $now, $daily, $weekly, $monthly);
    if (!$dryRun) {
        foreach ($delete as $f) { @unlink($dir . DIRECTORY_SEPARATOR . $f); @unlink($dir . DIRECTORY_SEPARATOR . $f . '.sha256'); }
    }
    return $delete;
}

/** Confirma o ficheiro .sha256 que acompanha a cópia (se existir). @return array{ok:bool,error:?string,checked:bool} */
function backup_check_sidecar(string $path): array
{
    $side = $path . '.sha256';
    if (!is_file($side)) { return ['ok' => true, 'error' => null, 'checked' => false]; }
    $expected = strtolower(substr(trim((string)file_get_contents($side)), 0, 64));
    $actual = hash_file('sha256', $path);
    return $expected === $actual ? ['ok' => true, 'error' => null, 'checked' => true]
        : ['ok' => false, 'error' => 'A soma de verificação (SHA-256) não coincide: o ficheiro foi alterado ou está corrompido.', 'checked' => true];
}

/**
 * Restaura uma cópia para a base $target. Cria a base se não existir. Se já existir, só avança com $overwrite e $confirm === $target,
 * e antes faz uma cópia de segurança dela (etiqueta "antes-do-restauro") na pasta $safetyDir.
 * @param array{host:string,port:string,user:string,pass:string} $admin conta com poderes para criar/apagar bases
 * @return array{db:string,tables:int,rows:array<string,int>,safety:?string}
 */
function backup_restore(string $file, string $target, array $admin, ?string $passphrase, bool $overwrite = false, ?string $confirm = null, ?string $safetyDir = null, ?string $sourceDbName = null): array
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $target)) { throw new InvalidArgumentException('Nome de base de dados inválido (só letras, números e "_").'); }
    if (!is_file($file)) { throw new RuntimeException('Cópia não encontrada: ' . $file); }
    $side = backup_check_sidecar($file);
    if (!$side['ok']) { throw new RuntimeException($side['error']); }
    $pre = backup_verify($file, $passphrase);                              // antes de tocar em qualquer base: a cópia tem de estar íntegra
    if (!$pre['ok']) { throw new RuntimeException('A cópia não está íntegra, nada foi alterado: ' . $pre['error']); }
    $client = backup_find_binary('client');
    if ($client === null) { throw new RuntimeException('Não encontrei o cliente mysql/mariadb. No XAMPP está em C:\\xampp\\mysql\\bin; noutros sistemas defina LUMINA_MYSQL.'); }

    $pdo = backup_pdo($admin['host'], $admin['port'], null, $admin['user'], $admin['pass']);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?');
    $exists->execute([$target]);
    $safety = null;
    if ((int)$exists->fetchColumn() > 0) {
        if (!$overwrite || $confirm !== $target) {
            throw new RuntimeException("A base de dados $target já existe. Para a SUBSTITUIR use --sobrescrever --confirmo=$target (é feita antes uma cópia de segurança dela). Ou restaure para outro nome com --base=...");
        }
        if ($safetyDir === null) { throw new RuntimeException('Falta a pasta para a cópia de segurança prévia.'); }
        $made = backup_create(['host' => $admin['host'], 'port' => $admin['port'], 'db' => $target, 'user' => $admin['user'], 'pass' => $admin['pass'],
                               'dir' => $safetyDir, 'passphrase' => $passphrase, 'label' => 'antes-do-restauro']);
        $safety = $made['path'];
        $pdo->exec('DROP DATABASE `' . $target . '`');
    }
    $pdo->exec('CREATE DATABASE `' . $target . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    $optionFile = backup_write_option_file($admin['host'], $admin['port'], $admin['user'], $admin['pass']);
    $errFile = tempnam(sys_get_temp_dir(), 'lumina_err');
    try {
        $proc = proc_open([$client, '--defaults-extra-file=' . $optionFile, '--default-character-set=utf8mb4', $target], [0 => ['pipe', 'r'], 1 => ['file', $errFile . '.out', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
        if (!is_resource($proc)) { throw new RuntimeException('Não consegui iniciar o cliente ' . basename($client) . '.'); }
        try {
            foreach (backup_read_sql($file, $passphrase) as $chunk) {
                for ($done = 0, $n = strlen($chunk); $done < $n;) {
                    $w = @fwrite($pipes[0], substr($chunk, $done));
                    if ($w === false || $w === 0) { break 2; }                 // o cliente terminou (erro de SQL): o motivo está no stderr
                    $done += $w;
                }
            }
        } finally {
            @fclose($pipes[0]);
            $code = proc_close($proc);
        }
        $stderr = trim((string)@file_get_contents($errFile));
        if ($code !== 0) {
            throw new RuntimeException('A restauração falhou (código ' . $code . '): ' . substr(preg_replace('/\s+/', ' ', $stderr), 0, 400)
                . ($safety !== null ? " A base original foi guardada em $safety." : ''));
        }
    } finally {
        backup_remove_file($optionFile);
        @unlink($errFile); @unlink($errFile . '.out');
    }
    $app = backup_pdo($admin['host'], $admin['port'], $target, $admin['user'], $admin['pass']);
    $rows = [];
    foreach ($app->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll() as $r) {
        $rows[$r['t']] = (int)$app->query('SELECT COUNT(*) FROM `' . str_replace('`', '', $r['t']) . '`')->fetchColumn();
    }
    return ['db' => $target, 'tables' => count($rows), 'rows' => $rows, 'safety' => $safety];
}
