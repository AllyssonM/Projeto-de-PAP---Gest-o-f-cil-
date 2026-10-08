<?php
/* Secções: cópias de segurança da base de dados (P0.2): nomes, retenção, cifra, criação, verificação e restauro. */
require_once __DIR__ . '/../../includes/backup_lib.php';

/** Pasta temporária única (apagada pelo chamador com bk_rm). */
function bk_tmpdir(): string { $d = sys_get_temp_dir() . '/lumina_bk_' . bin2hex(random_bytes(5)); mkdir($d, 0700, true); return $d; }
function bk_rm(string $dir): void { foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } } @rmdir($dir); }
/** Corre bin/backup_bd.php ou bin/restaurar_bd.php com o ambiente de teste. @return array{0:string,1:string,2:int} */
function bk_cli(string $script, array $args, array $env = []): array
{
    $admin = ['LUMINA_DB_ADMIN_USER' => getenv('LUMINA_DB_ADMIN_USER') ?: 'root', 'LUMINA_DB_ADMIN_PASS' => getenv('LUMINA_DB_ADMIN_PASS') ?: ''];
    $base = ['LUMINA_DB_HOST' => DB_HOST, 'LUMINA_DB_PORT' => DB_PORT, 'LUMINA_BACKUP_DB_USER' => $admin['LUMINA_DB_ADMIN_USER'], 'LUMINA_BACKUP_DB_PASS' => $admin['LUMINA_DB_ADMIN_PASS'],
             'LUMINA_BACKUP_PASSPHRASE' => '', 'LUMINA_BACKUP_DIR' => '', 'LUMINA_BACKUP_KEEP_DAILY' => '', 'LUMINA_BACKUP_KEEP_WEEKLY' => '', 'LUMINA_BACKUP_KEEP_MONTHLY' => ''];
    return php_run(array_merge(['bin/' . $script], $args), $env + $base + $admin);
}
function bk_files(string $dir): array { $o = []; foreach (scandir($dir) ?: [] as $f) { if ($f[0] !== '.' && is_file("$dir/$f")) { $o[] = $f; } } sort($o); return $o; }
function bk_sql(string $path, ?string $pass = null): string { $s = ''; foreach (backup_read_sql($path, $pass) as $c) { $s .= $c; } return $s; }

section('Backups: nomes de ficheiro e configuração', function () {
    $tz = new DateTimeZone('Europe/Lisbon');
    $when = new DateTimeImmutable('2026-10-08 03:15:09', $tz);
    check('nome com data e hora, ordenável', backup_filename('gestao_facil', $when) === 'lumina_gestao_facil_2026-10-08_031509.sql.gz');
    check('nome da cópia cifrada acaba em .enc', backup_filename('gestao_facil', $when, true) === 'lumina_gestao_facil_2026-10-08_031509.sql.gz.enc');
    $p = backup_parse_filename('lumina_gestao_facil_2026-10-08_031509.sql.gz.enc', $tz);
    check('o nome lê-se de volta (base, data, cifrada)', $p && $p['db'] === 'gestao_facil' && $p['when']->format('c') === $when->format('c') && $p['encrypted'] === true);
    foreach (['notas.txt', 'lumina_x_2026-10-08_031509.sql', 'lumina_2026-10-08_031509.sql.gz', 'lumina_gestao_facil_2026-13-45_999999.sql.gz', 'lumina_a-b_2026-10-08_031509.sql.gz', '../lumina_x_2026-10-08_031509.sql.gz', 'lumina_gestao_facil_2026-10-08_031509.sql.gz.sha256'] as $bad) {
        check("não é uma cópia nossa: $bad", backup_parse_filename($bad, $tz) === null);
    }
    $c = backup_resolve_config([], []);
    check('por omissão: 7 dias, 4 semanas, 6 meses, sem pasta, sem cifra', $c['daily'] === 7 && $c['weekly'] === 4 && $c['monthly'] === 6 && $c['dir'] === null && $c['passphrase'] === null);
    $c = backup_resolve_config(['dir' => '/x', 'daily' => '3', 'weekly' => null, 'pass' => 'a'], ['dir' => '/local', 'weekly' => '9', 'monthly' => 'abc', 'user' => 'u', 'passphrase' => 'frase']);
    check('ambiente > ficheiro local; valores inválidos voltam ao padrão', $c['dir'] === '/x' && $c['daily'] === 3 && $c['weekly'] === 9 && $c['monthly'] === 6 && $c['user'] === 'u' && $c['pass'] === 'a' && $c['passphrase'] === 'frase');
});

section('Backups: política de retenção (dias, semanas, meses)', function () {
    $tz = new DateTimeZone('Europe/Lisbon');
    $now = new DateTimeImmutable('2026-10-08 12:00:00', $tz);                                  // quinta-feira
    $names = [];
    for ($d = new DateTimeImmutable('2026-08-30 03:00:00', $tz); $d <= $now; $d = $d->modify('+1 day')) { $names[] = backup_filename('gestao_facil', $d); }
    check('cenário: 40 cópias diárias', count($names) === 40);
    $delete = backup_select_to_delete($names, 'gestao_facil', $now, 7, 4, 6);
    $kept = array_values(array_diff($names, $delete));
    $expected = array_map(fn($d) => backup_filename('gestao_facil', new DateTimeImmutable("$d 03:00:00", $tz)),
        ['2026-08-31', '2026-09-20', '2026-09-27', '2026-09-30', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);
    check('guarda os últimos 7 dias, a mais nova de cada uma das últimas 4 semanas e de cada mês (11 de 40)', $kept === $expected, implode(' ', array_map(fn($n) => substr($n, 21, 10), $kept)));
    $mixed = array_merge($names, ['notas.txt', 'lumina_outra_base_2020-01-01_000000.sql.gz', 'lumina_gestao_facil_antes-do-restauro_2026-01-01_000000.sql.gz', 'lumina_gestao_facil_2026-13-45_999999.sql.gz']);
    $d2 = backup_select_to_delete($mixed, 'gestao_facil', $now, 7, 4, 6);
    check('outros ficheiros, outras bases, cópias manuais e nomes inválidos NUNCA são apagados', array_diff($d2, $names) === [] && count($d2) === 29);
    check('com tudo a 0 fica sempre a cópia mais recente', array_values(array_diff($names, backup_select_to_delete($names, 'gestao_facil', $now, 0, 0, 0))) === [end($names)]);
    check('lista vazia → nada a apagar', backup_select_to_delete([], 'gestao_facil', $now, 7, 4, 6) === []);
    $enc = [backup_filename('gestao_facil', new DateTimeImmutable('2026-10-07 03:00', $tz), true), backup_filename('gestao_facil', new DateTimeImmutable('2026-10-08 03:00', $tz), true)];
    check('cópias cifradas contam como as outras', backup_select_to_delete($enc, 'gestao_facil', $now, 1, 0, 0) === [$enc[0]]);
    $same = [backup_filename('gestao_facil', new DateTimeImmutable('2026-10-08 03:00:00', $tz)), backup_filename('gestao_facil', new DateTimeImmutable('2026-10-08 15:00:00', $tz)), backup_filename('gestao_facil', new DateTimeImmutable('2026-10-08 09:00:00', $tz))];
    check('várias no mesmo dia: fica só a mais nova', backup_select_to_delete($same, 'gestao_facil', $now, 7, 0, 0) === [$same[2], $same[0]] || backup_select_to_delete($same, 'gestao_facil', $now, 7, 0, 0) === [$same[0], $same[2]]);
});

section('Backups: cifra (libsodium) e gzip em fluxo', function () {
    $dir = bk_tmpdir(); $pass = 'frase-passe-de-teste-123';
    $marker = 'SEGREDO-MARCADOR-' . bin2hex(random_bytes(6));
    $sizes = [0, 1, 65535, 65536, 65537, 200000, 3 * 65536];
    foreach ($sizes as $size) {
        $plain = $size === 0 ? '' : substr($marker . random_bytes($size), 0, $size);                  // dados aleatórios = incompressíveis → vários blocos cifrados
        $path = "$dir/t$size.gz.enc";
        $gz = new BackupGzipSink(new BackupEncryptSink(new BackupFileSink($path), $pass));
        for ($o = 0, $step = [1000, 65536, 65537, 3]; $o < strlen($plain); $o += $step[($o + 1) % 4]) { $gz->write(substr($plain, $o, $step[($o + 1) % 4])); }
        $gz->close();
        $back = bk_sql($path, $pass);
        if ($back !== $plain) { check("ida e volta cifrada com $size bytes", false, 'conteúdo diferente'); continue; }
        check("ida e volta cifrada com $size bytes", true);
    }
    $path = "$dir/t200000.gz.enc"; $raw = file_get_contents($path);
    check('o ficheiro cifrado começa pela marca LUMBK1 e não contém o texto em claro', str_starts_with($raw, BACKUP_MAGIC) && !str_contains($raw, $marker));
    $try = function (string $file, ?string $p) { try { bk_sql($file, $p); return null; } catch (Throwable $e) { return $e->getMessage(); } };
    check('frase-passe errada é recusada, com mensagem clara', str_contains((string)$try($path, 'outra-frase-passe-qualquer'), 'Frase-passe errada'));
    check('sem frase-passe pede-a', str_contains((string)$try($path, null), 'cifrada'));
    $mut = function (callable $f) use ($dir, $raw) { $x = "$dir/mut.bin"; file_put_contents($x, $f($raw)); return $x; };
    check('um byte alterado no meio é detetado (adulteração)', $try($mut(function ($r) { $r[(int)(strlen($r) / 2)] = chr(ord($r[(int)(strlen($r) / 2)]) ^ 1); return $r; }), $pass) !== null);
    check('um byte alterado no cabeçalho (sal) é detetado', $try($mut(function ($r) { $r[20] = chr(ord($r[20]) ^ 1); return $r; }), $pass) !== null);
    check('ficheiro truncado é detetado', $try($mut(fn($r) => substr($r, 0, -40)), $pass) !== null);
    check('ficheiro truncado no cabeçalho é detetado', $try($mut(fn($r) => substr($r, 0, 30)), $pass) !== null);
    check('dados acrescentados no fim são detetados', $try($mut(fn($r) => $r . 'lixo'), $pass) !== null);
    check('blocos trocados de ordem são detetados', (function () use ($dir, $pass, $try) {
        $plain = random_bytes(200000); $f = "$dir/ord.gz.enc";
        $g = new BackupGzipSink(new BackupEncryptSink(new BackupFileSink($f), $pass)); $g->write($plain); $g->close();
        $r = file_get_contents($f); $head = 7 + 8 + 16 + 24; $len = unpack('N', substr($r, $head, 4))[1]; $len2 = unpack('N', substr($r, $head + 4 + $len, 4))[1];
        $b1 = substr($r, $head, 4 + $len); $b2 = substr($r, $head + 4 + $len, 4 + $len2);
        file_put_contents("$dir/ord2.bin", substr($r, 0, $head) . $b2 . $b1 . substr($r, $head + strlen($b1) + strlen($b2)));
        return $try("$dir/ord2.bin", $pass) !== null;
    })());
    $refused = false; try { new BackupEncryptSink(new BackupFileSink("$dir/curta.enc"), 'curta'); } catch (InvalidArgumentException $e) { $refused = true; }
    check('frase-passe com menos de 16 caracteres é recusada', $refused);
    $f = "$dir/s.gz"; $g = new BackupGzipSink(new BackupFileSink($f)); $g->write("SELECT 1;\n"); $g->close();
    check('gzip simples: ida e volta e é um .gz válido', bk_sql($f) === "SELECT 1;\n" && gzdecode((string)file_get_contents($f)) === "SELECT 1;\n");
    file_put_contents("$dir/trunc.gz", substr((string)file_get_contents($f), 0, -6));
    check('gzip truncado é detetado', $try("$dir/trunc.gz", null) !== null);
    $exists = false; try { new BackupFileSink($f); } catch (RuntimeException $e) { $exists = true; }
    check('nunca sobrescreve um ficheiro que já existe', $exists);
    bk_rm($dir);
});

section('Backups: credenciais fora da linha de comandos', function () {
    $pw = 'p"a\\ss#w ord\'x;=' . bin2hex(random_bytes(4));
    $f = backup_write_option_file('127.0.0.1', '3306', 'utilizador', $pw);
    $txt = (string)file_get_contents($f);
    if (DIRECTORY_SEPARATOR === '/') { check('ficheiro temporário só legível pelo dono (0600)', (fileperms($f) & 0777) === 0600, decoct(fileperms($f) & 0777)); }
    check('aspas e barras escapadas (aceita qualquer palavra-passe)', str_contains($txt, 'password="p\\"a\\\\ss#w ord\'x;='), $txt);
    $cmd = backup_dump_command('/usr/bin/mysqldump', $f, 'gestao_facil', ['no_tablespaces' => true, 'column_statistics' => true]);
    check('o comando do dump NÃO leva a palavra-passe nem o utilizador', array_filter($cmd, fn($a) => str_contains($a, $pw) || str_contains($a, '-p') && !str_contains($a, '--')) === [] && !in_array('utilizador', $cmd, true));
    check('o comando usa --single-transaction e o ficheiro de opções', in_array('--single-transaction', $cmd, true) && in_array('--defaults-extra-file=' . $f, $cmd, true) && end($cmd) === 'gestao_facil');
    backup_remove_file($f);
    check('o ficheiro temporário é apagado', !file_exists($f));
    $rej = false; try { backup_write_option_file('h', '1', "u\nuser=root", 'x'); } catch (InvalidArgumentException $e) { $rej = true; }
    check('quebras de linha nas credenciais são recusadas (injeção no ficheiro de opções)', $rej);
});

section('Backups: criar, verificar e restaurar (base temporária)', function () {
    $admin = test_admin_pdo();
    if (!$admin || backup_find_binary('dump') === null || backup_find_binary('client') === null) { echo "  (saltado: sem conta de administração do MySQL ou sem mysqldump/mysql)\n"; return; }
    $src = 'lumina_teste_bk'; $user = 'lumina_teste_bku'; $upass = 'Seg-' . bin2hex(random_bytes(8));
    $dest = bk_tmpdir() . '/copias'; $dbs = [$src, $src . '_rest', $src . '_rest2', $src . '_rest3'];
    $cleanup = function () use ($admin, $dbs, $user, $dest) {
        foreach ($dbs as $d) { $admin->exec("DROP DATABASE IF EXISTS `$d`"); }
        foreach (['localhost', '127.0.0.1'] as $h) { $admin->exec("DROP USER IF EXISTS '$user'@'$h'"); }
        bk_rm($dest); @rmdir(dirname($dest));
    };
    $cleanup();
    $admin->exec("CREATE DATABASE `$src` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE TABLE `$src`.pessoas (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(100) NOT NULL, nota LONGTEXT NULL, foto BLOB NULL, criado DATETIME NULL, preco DECIMAL(10,2) NULL) ENGINE=InnoDB");
    $admin->exec("CREATE TABLE `$src`.itens (id INT AUTO_INCREMENT PRIMARY KEY, pessoa_id INT NOT NULL, texto VARCHAR(50), FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE) ENGINE=InnoDB");
    $ins = $admin->prepare("INSERT INTO `$src`.pessoas (nome, nota, foto, criado, preco) VALUES (?, ?, ?, ?, ?)");
    $ins->execute(['João Müller 😀', 'Ação & "emoção" — O\'Brien; DROP TABLE x; --', random_bytes(300), '2026-10-08 03:15:09', '19.90']);
    $ins->execute(['NULOS', null, null, null, null]);
    $ins->execute(['Texto grande', str_repeat("linha de texto com acentuação ãéõç\n", 8000), random_bytes(70000 > 65535 ? 60000 : 1), '2000-01-01 00:00:00', '0.01']);
    $admin->exec("INSERT INTO `$src`.itens (pessoa_id, texto) VALUES (1, 'um'), (1, 'dois'), (3, 'três')");
    $admin->exec("CREATE USER '$user'@'127.0.0.1' IDENTIFIED BY '$upass'"); $admin->exec("CREATE USER '$user'@'localhost' IDENTIFIED BY '$upass'");
    $admin->exec("GRANT SELECT, SHOW VIEW ON `$src`.* TO '$user'@'127.0.0.1', '$user'@'localhost'");
    $snapshot = function (string $db) use ($admin): string {
        $o = [];
        foreach (['pessoas', 'itens'] as $t) { $o[$t] = array_map(fn($r) => array_map(fn($v) => is_string($v) ? base64_encode($v) : $v, $r), $admin->query("SELECT * FROM `$db`.`$t` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)); }
        return json_encode($o);
    };
    $tmpBefore = count(glob(sys_get_temp_dir() . '/lumina_my*') ?: []);
    $env = ['LUMINA_BACKUP_DB_USER' => $user, 'LUMINA_BACKUP_DB_PASS' => $upass];
    try {
        // ---- criar
        [$out, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src"], $env);
        check('cria a cópia com sucesso (utilizador só de leitura)', $rc === 0, $err . $out);
        check('a palavra-passe do utilizador não aparece no ecrã', !str_contains($out . $err, $upass));
        $files = bk_files($dest); $gz = array_values(array_filter($files, fn($f) => str_ends_with($f, '.sql.gz')))[0] ?? '';
        check('nome com a data e a base; ao lado a soma SHA-256', preg_match('/^lumina_lumina_teste_bk_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/', $gz) === 1 && in_array($gz . '.sha256', $files, true), implode(',', $files));
        $path = "$dest/$gz";
        if (DIRECTORY_SEPARATOR === '/') { check('pasta 0700 e ficheiros 0600', (fileperms($dest) & 0777) === 0700 && (fileperms($path) & 0777) === 0600 && (fileperms("$path.sha256") & 0777) === 0600, decoct(fileperms($dest) & 0777) . '/' . decoct(fileperms($path) & 0777)); }
        check('a soma SHA-256 guardada coincide com o ficheiro', backup_check_sidecar($path)['ok'] && backup_check_sidecar($path)['checked']);
        check('nenhum ficheiro temporário com credenciais ficou para trás', count(glob(sys_get_temp_dir() . '/lumina_my*') ?: []) === $tmpBefore);
        $sql = bk_sql($path);
        check('o SQL tem as duas tabelas, os dados com acentos/emoji e termina como deve', str_contains($sql, 'CREATE TABLE `pessoas`') && str_contains($sql, 'CREATE TABLE `itens`') && str_contains($sql, 'João Müller 😀') && str_contains($sql, '-- Dump completed'));
        check('o resumo diz 2 tabelas', str_contains($out, '2 tabelas'), $out);
        // ---- restaurar para uma base nova: dados idênticos
        [$out, $err, $rc] = bk_cli('restaurar_bd.php', [$path, "--base={$src}_rest"]);
        check('restaura para uma base nova com sucesso', $rc === 0, $err . $out);
        check('os dados restaurados são IDÊNTICOS (acentos, emoji, NULL, binário, texto grande, decimais, datas)', $snapshot($src) === $snapshot($src . '_rest'));
        // ---- proteção da base existente
        $admin->exec("DELETE FROM `$src`.itens WHERE id = 3");
        $changed = $snapshot($src);
        [, $err, $rc] = bk_cli('restaurar_bd.php', [$path, "--base=$src"]);
        check('recusa substituir uma base existente sem --sobrescrever', $rc === 1 && str_contains($err, '--sobrescrever') && $snapshot($src) === $changed, $err);
        [, $err, $rc] = bk_cli('restaurar_bd.php', [$path, "--base=$src", '--sobrescrever', '--confirmo=outro_nome']);
        check('recusa se a confirmação não for o nome exato da base', $rc === 1 && $snapshot($src) === $changed, $err);
        [$out, $err, $rc] = bk_cli('restaurar_bd.php', [$path, "--base=$src", '--sobrescrever', "--confirmo=$src", "--pasta-seguranca=$dest"]);
        check('com --sobrescrever e a confirmação certa, substitui', $rc === 0 && $snapshot($src) === $snapshot($src . '_rest'), $err . $out);
        $safety = array_values(array_filter(bk_files($dest), fn($f) => str_contains($f, 'antes-do-restauro') && str_ends_with($f, '.sql.gz')));
        check('antes de substituir, guardou uma cópia da base anterior (etiqueta antes-do-restauro)', count($safety) === 1 && str_contains(bk_sql("$dest/{$safety[0]}"), "'dois'") && !str_contains(bk_sql("$dest/{$safety[0]}"), "'três'"), implode(',', $safety));
        // ---- cópia corrompida / adulterada: recusa sem tocar em nada
        $raw = (string)file_get_contents($path); $bad = "$dest/corrompida.sql.gz"; $mid = (int)(strlen($raw) / 2); $raw[$mid] = chr(ord($raw[$mid]) ^ 0xff); file_put_contents($bad, $raw);
        [, $err, $rc] = bk_cli('restaurar_bd.php', [$bad, "--base={$src}_rest2"]);
        $has2 = (int)$admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '{$src}_rest2'")->fetchColumn();
        check('cópia corrompida é recusada ANTES de criar qualquer base', $rc === 1 && $has2 === 0, $err);
        file_put_contents("$path.sha256", str_replace(substr(hash_file('sha256', $path), 0, 8), 'deadbeef', (string)file_get_contents("$path.sha256")));
        [, $err, $rc] = bk_cli('restaurar_bd.php', [$path, "--base={$src}_rest2"]);
        check('soma SHA-256 que não coincide é recusada', $rc === 1 && str_contains($err, 'SHA-256'), $err);
        file_put_contents("$path.sha256", backup_checksum_line($path));
        // ---- cifrada
        $pp = 'frase-longa-de-teste-' . bin2hex(random_bytes(4));
        [$out, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src", '--sem-limpeza'], $env + ['LUMINA_BACKUP_PASSPHRASE' => $pp]);
        check('com frase-passe cria uma cópia cifrada (.enc)', $rc === 0 && str_contains($out, 'CIFRADA') && !str_contains($out . $err, $pp), $err . $out);
        $enc = array_values(array_filter(bk_files($dest), fn($f) => str_ends_with($f, '.sql.gz.enc')))[0] ?? '';
        $rawEnc = (string)@file_get_contents("$dest/$enc");
        check('o ficheiro cifrado não deixa ver nem as tabelas nem os dados', $enc !== '' && !str_contains($rawEnc, 'CREATE TABLE') && !str_contains($rawEnc, 'Müller') && !str_contains($rawEnc, 'pessoas'));
        [, $err, $rc] = bk_cli('restaurar_bd.php', ["$dest/$enc", "--base={$src}_rest3"], ['LUMINA_BACKUP_PASSPHRASE' => 'frase-errada-123456789']);
        $has3 = (int)$admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '{$src}_rest3'")->fetchColumn();
        check('frase-passe errada: recusa e não cria nenhuma base', $rc === 1 && $has3 === 0, $err);
        [, $err, $rc] = bk_cli('restaurar_bd.php', ["$dest/$enc", "--base={$src}_rest3"], ['LUMINA_BACKUP_PASSPHRASE' => $pp]);
        check('frase-passe certa: restaura com dados idênticos', $rc === 0 && $snapshot($src) === $snapshot($src . '_rest3'), $err);
        // ---- falhas não estragam nada
        $before = bk_files($dest);
        [, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src"], ['LUMINA_BACKUP_DB_PASS' => 'palavra-errada']);
        check('credenciais erradas: falha (código 1), sem cópia nova, sem .parcial e sem apagar nada', $rc === 1 && bk_files($dest) === $before && !str_contains($err, 'palavra-errada'), $err);
        [, $err, $rc] = bk_cli('backup_bd.php', ["--destino=" . dirname(__DIR__, 2) . '/storage/copias', "--base=$src"], $env);
        check('recusa uma pasta dentro do Lumina (e não chega a criá-la)', $rc === 1 && str_contains($err, 'dentro da pasta do Lumina') && !is_dir(dirname(__DIR__, 2) . '/storage/copias'), $err);
        [, $err, $rc] = bk_cli('backup_bd.php', ['--base=' . $src], $env);
        check('sem pasta de destino: pede-a', $rc === 1 && str_contains($err, '--destino'), $err);
        [, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", '--base=base_que_nao_existe_xyz'], $env);
        check('base inexistente: erro claro', $rc === 1 && $err !== '', $err);
        $lock = fopen("$dest/.lumina-backup.lock", 'c'); flock($lock, LOCK_EX | LOCK_NB);
        [, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src"], $env);
        check('duas cópias ao mesmo tempo: a segunda recusa', $rc === 1 && str_contains($err, 'outra cópia'), $err);
        flock($lock, LOCK_UN); fclose($lock);
        // ---- retenção a sério (ficheiros antigos + manuais ao lado)
        $tz = app_timezone(); $old = [];
        for ($i = 1; $i <= 12; $i++) { $n = backup_filename($src, (new DateTimeImmutable('now', $tz))->modify("-$i day")->setTime(3, 0)); copy($path, "$dest/$n"); copy("$path.sha256", "$dest/$n.sha256"); $old[] = $n; }
        file_put_contents("$dest/notas.txt", 'minhas notas'); copy($path, "$dest/lumina_outra_base_2020-01-01_000000.sql.gz");
        [$out, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src", '--manter=3,0,0', '--simular'], $env);
        check('--simular mostra o que apagaria mas não apaga nada', $rc === 0 && str_contains($out, 'apagaria') && count(array_filter(bk_files($dest), fn($f) => in_array($f, $old, true))) === 12, $err . $out);
        [$out, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src", '--manter=3,0,0'], $env);
        $left = bk_files($dest);
        $oldLeft = array_values(array_filter($left, fn($f) => in_array($f, $old, true)));
        check('retenção 3 dias: ficam hoje, ontem e anteontem, as mais velhas e as outras de hoje vão (com as suas .sha256)', $rc === 0 && $oldLeft === [$old[1], $old[0]] && !in_array($old[5] . '.sha256', $left, true) && in_array($old[0] . '.sha256', $left, true), implode(',', $oldLeft) . ' | ' . $out);
        check('notas, outras bases e a cópia de segurança antes-do-restauro ficaram intactas', in_array('notas.txt', $left, true) && in_array('lumina_outra_base_2020-01-01_000000.sql.gz', $left, true) && count(array_filter($left, fn($f) => str_contains($f, 'antes-do-restauro'))) >= 2);
        // ---- verificação profunda
        [$out, $err, $rc] = bk_cli('backup_bd.php', ["--destino=$dest", "--base=$src", '--sem-limpeza', '--verificar-restauro'], $env);
        check('--verificar-restauro repõe a cópia numa base temporária e apaga-a no fim', $rc === 0 && str_contains($out, 'Restauro de teste OK') && (int)$admin->query("SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'lumina\\_verif\\_%'")->fetchColumn() === 0, $err . $out);
    } finally {
        $cleanup();
    }
});
