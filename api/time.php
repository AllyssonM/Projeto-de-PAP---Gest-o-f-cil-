<?php
/* =========================================================================
   API DO TEMPO ATIVO  (api/time.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  registo de entrada/saída e pausas, histórico, totais, exportação e
               correção de horários.
   QUEM PODE O QUÊ:
     - TODOS (dono e funcionários) marcam o PRÓPRIO turno: entrada, pausa, saída.
     - Um funcionário só vê o seu histórico.
     - "Gestor" = o dono (administrador) ou um funcionário com a área "Tempo ativo da
       equipa": vê o histórico de todos, exporta e CORRIGE registos.
     - Corrigir exige um MOTIVO e fica registado: quem alterou, quando e porquê
       (colunas edited_by / edited_at / edit_reason + audit_log com o antes e o depois).
   AÇÕES:
     GET  ?action=status                        o meu turno agora (estado, contador, totais)
     GET  ?action=list&from=&to=&employee=      histórico + totais por funcionário
     GET  ?action=export&from=&to=&employee=    ficheiro CSV (abre no Excel)
     POST action=clock_in | pause | resume | clock_out
     POST action=edit   (gestor)  corrige entrada/saída de um registo
     POST action=create_manual (gestor)  regista um turno que não foi marcado
     POST action=delete (só o dono)
   TEMPO: tudo no fuso horário da aplicação (config/app.php). O servidor devolve a
          hora atual para o contador do navegador não depender do relógio do PC.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$uid = (int)$user['id'];
$tid = (int)$user['tenant_id'];
$isOwner = ($user['role'] ?? '') === 'owner';
$isManager = $isOwner || can($user, 'time');
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();
$action = (string)($_GET['action'] ?? $data['action'] ?? 'status');
$tz = app_timezone();
const MAX_SHIFT_SECONDS = 24 * 3600;                // um turno não passa de 24 horas

function now_dt(): DateTime { return new DateTime('now', app_timezone()); }
function ts(string $dt): int { return (new DateTime($dt, app_timezone()))->getTimestamp(); }
function fmt(int $t): string { return (new DateTime('@' . $t))->setTimezone(app_timezone())->format('Y-m-d H:i:s'); }

/** Datas "de..até" (inclusive). Por omissão: o mês atual. Devolve [início, fim exclusivo] como texto. */
function range_from_request(): array
{
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : now_dt()->format('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : now_dt()->format('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];
    return [$from . ' 00:00:00', (new DateTime($to . ' 00:00:00', app_timezone()))->modify('+1 day')->format('Y-m-d H:i:s')];
}

/** Junta as pausas a uma lista de turnos e calcula as horas trabalhadas de cada um. */
function with_pauses(array $shifts): array
{
    if (!$shifts) return [];
    $ids = array_map(fn($s) => (int)$s['id'], $shifts);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = db()->prepare("SELECT shift_id, started_at, ended_at FROM work_pauses WHERE shift_id IN ($in) ORDER BY started_at");
    $st->execute($ids);
    $byShift = [];
    foreach ($st->fetchAll() as $p) $byShift[(int)$p['shift_id']][] = $p;
    $now = time();
    foreach ($shifts as &$s) {
        $start = ts($s['started_at']);
        $end = $s['ended_at'] ? ts($s['ended_at']) : $now;
        $pauseSec = 0;
        $pauses = [];
        foreach ($byShift[(int)$s['id']] ?? [] as $p) {
            $ps = ts($p['started_at']);
            $pe = $p['ended_at'] ? ts($p['ended_at']) : $now;
            $pauseSec += max(0, min($pe, $end) - max($ps, $start));
            $pauses[] = ['started_at' => $p['started_at'], 'ended_at' => $p['ended_at']];
        }
        $s['id'] = (int)$s['id']; $s['employee_id'] = (int)$s['employee_id'];
        $s['pause_seconds'] = $pauseSec;
        $s['worked_seconds'] = max(0, ($end - $start) - $pauseSec);
        $s['pauses'] = $pauses;
        $s['open'] = $s['ended_at'] === null;
    }
    return $shifts;
}

/** Total trabalhado por um funcionário entre dois instantes (conta só a parte que cai dentro do intervalo). */
function worked_between(int $tid, int $employeeId, string $from, string $to): int
{
    $st = db()->prepare('SELECT id, employee_id, started_at, ended_at, status, source, note FROM work_shifts WHERE tenant_id = ? AND employee_id = ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)');
    $st->execute([$tid, $employeeId, $to, $from]);
    $total = 0;
    foreach (with_pauses($st->fetchAll()) as $s) {
        $total += $s['worked_seconds'];                         // simplificação: turno conta no dia em que começou (turnos não passam de 24 h)
    }
    return $total;
}

function open_shift(int $tid, int $employeeId): ?array
{
    $st = db()->prepare("SELECT id, status, started_at FROM work_shifts WHERE tenant_id = ? AND employee_id = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$tid, $employeeId]);
    return $st->fetch() ?: null;
}

function open_pause(int $shiftId): ?array
{
    $st = db()->prepare('SELECT id, started_at FROM work_pauses WHERE shift_id = ? AND ended_at IS NULL LIMIT 1');
    $st->execute([$shiftId]);
    return $st->fetch() ?: null;
}

/** O estado do turno do utilizador, tudo o que o navegador precisa para desenhar o contador. */
function my_status(int $tid, int $uid): array
{
    $shift = open_shift($tid, $uid);
    $now = now_dt();
    $out = ['server_now' => $now->format('c'), 'server_ts' => $now->getTimestamp(), 'state' => 'off', 'shift' => null];
    if ($shift) {
        $row = with_pauses([['id' => $shift['id'], 'employee_id' => $uid, 'started_at' => $shift['started_at'], 'ended_at' => null]])[0];
        $p = $shift['status'] === 'paused' ? open_pause((int)$shift['id']) : null;
        $out['state'] = $shift['status'] === 'paused' ? 'paused' : 'on';
        $out['shift'] = ['id' => (int)$shift['id'], 'started_at' => $shift['started_at'], 'started_ts' => ts($shift['started_at']), 'worked_seconds' => $row['worked_seconds'],
                         'pause_seconds' => $row['pause_seconds'], 'paused_since_ts' => $p ? ts($p['started_at']) : null];
    }
    $day = $now->format('Y-m-d');
    $week = (clone $now)->modify('monday this week')->format('Y-m-d');
    $month = $now->format('Y-m-01');
    $tomorrow = (clone $now)->modify('+1 day')->format('Y-m-d');
    $out['today_seconds'] = worked_between($tid, $uid, "$day 00:00:00", "$tomorrow 00:00:00");
    $out['week_seconds'] = worked_between($tid, $uid, "$week 00:00:00", "$tomorrow 00:00:00");
    $out['month_seconds'] = worked_between($tid, $uid, "$month 00:00:00", "$tomorrow 00:00:00");
    return $out;
}

/** Quem se pode ver no histórico: o gestor vê todos os do negócio; os outros só a si. */
function allowed_employee_ids(int $tid, int $uid, bool $isManager, ?int $filter): array
{
    if (!$isManager) return [$uid];
    $st = db()->prepare("SELECT id, name, job_title, status FROM users WHERE id = ? OR owner_id = ? ORDER BY name");
    $st->execute([$tid, $tid]);
    $all = $st->fetchAll();
    $ids = array_map(fn($u) => (int)$u['id'], $all);
    return $filter && in_array($filter, $ids, true) ? [$filter] : $ids;
}

function hms(int $s): string { return sprintf('%d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60)); }

/** Confirma que um turno é do negócio e devolve-o (ou 404). */
function find_shift(int $tid, int $id): array
{
    $st = db()->prepare('SELECT * FROM work_shifts WHERE id = ? AND tenant_id = ?');
    $st->execute([$id, $tid]);
    $s = $st->fetch();
    if (!$s) json_response(['success' => false, 'error' => 'Registo não encontrado.'], 404);
    return $s;
}

/** Valida entrada/saída numa correção. Devolve [início, fim] em texto. */
function validate_times(array $data, int $tid, int $employeeId, ?int $ignoreId): array
{
    $re = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/';
    foreach (['started_at', 'ended_at'] as $k) if (!preg_match($re, (string)($data[$k] ?? ''))) json_response(['success' => false, 'error' => 'Indica a entrada e a saída (data e hora).'], 400);
    $a = ts(str_replace('T', ' ', $data['started_at'])); $b = ts(str_replace('T', ' ', $data['ended_at']));
    if ($b <= $a) json_response(['success' => false, 'error' => 'A saída tem de ser depois da entrada.'], 400);
    if ($b - $a > MAX_SHIFT_SECONDS) json_response(['success' => false, 'error' => 'Um turno não pode passar de 24 horas.'], 400);
    if ($b > time() + 60) json_response(['success' => false, 'error' => 'A saída não pode estar no futuro.'], 400);
    $ov = db()->prepare('SELECT COUNT(*) FROM work_shifts WHERE tenant_id = ? AND employee_id = ? AND id <> ? AND started_at < ? AND (ended_at IS NULL OR ended_at > ?)');
    $ov->execute([$tid, $employeeId, $ignoreId ?? 0, fmt($b), fmt($a)]);
    if ((int)$ov->fetchColumn() > 0) json_response(['success' => false, 'error' => 'Este horário sobrepõe-se a outro registo do mesmo funcionário.'], 409);
    return [fmt($a), fmt($b)];
}

try {
    /* ------------------------------ LER ------------------------------ */
    if ($method === 'GET') {
        if ($action === 'status') json_response(['success' => true] + my_status($tid, $uid) + ['is_manager' => $isManager]);

        if ($action === 'list' || $action === 'export') {
            [$from, $toExcl] = range_from_request();
            $filter = (int)($_GET['employee'] ?? 0) ?: null;
            $ids = allowed_employee_ids($tid, $uid, $isManager, $filter);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = db()->prepare("SELECT s.id, s.employee_id, s.started_at, s.ended_at, s.status, s.source, s.note, s.edited_at, s.edit_reason, u.name AS employee_name, e.name AS edited_by_name
                FROM work_shifts s JOIN users u ON u.id = s.employee_id LEFT JOIN users e ON e.id = s.edited_by
                WHERE s.tenant_id = ? AND s.employee_id IN ($in) AND s.started_at >= ? AND s.started_at < ? ORDER BY s.started_at DESC LIMIT 1000");
            $st->execute(array_merge([$tid], $ids, [$from, $toExcl]));
            $shifts = with_pauses($st->fetchAll());

            if ($action === 'export') {
                // CSV com ";" e BOM para abrir bem no Excel português
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="tempo-ativo-' . substr($from, 0, 10) . '.csv"');
                header('X-Content-Type-Options: nosniff');
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Funcionário', 'Data', 'Entrada', 'Saída', 'Pausas (min)', 'Total trabalhado (h:mm)', 'Origem', 'Corrigido por', 'Motivo'], ';');
                $cell = fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : $v;          // evita fórmulas do Excel em texto escrito por utilizadores
                foreach (array_reverse($shifts) as $s) {
                    fputcsv($out, [$cell($s['employee_name']), substr($s['started_at'], 0, 10), substr($s['started_at'], 11, 5), $s['ended_at'] ? substr($s['ended_at'], 11, 5) : 'em curso',
                        (int)round($s['pause_seconds'] / 60), hms($s['worked_seconds']), $s['source'] === 'manual' ? 'Corrigido' : 'Relógio', $cell($s['edited_by_name'] ?? ''), $cell($s['edit_reason'] ?? '')], ';');
                }
                fclose($out);
                exit;
            }

            $totals = [];
            foreach ($shifts as $s) {
                $t = &$totals[$s['employee_id']];
                $t ??= ['employee_id' => $s['employee_id'], 'name' => $s['employee_name'], 'days' => [], 'seconds' => 0, 'shifts' => 0];
                $t['seconds'] += $s['worked_seconds']; $t['shifts']++; $t['days'][substr($s['started_at'], 0, 10)] = true;
                unset($t);
            }
            $totals = array_values(array_map(fn($t) => ['employee_id' => $t['employee_id'], 'name' => $t['name'], 'days' => count($t['days']), 'seconds' => $t['seconds'], 'shifts' => $t['shifts']], $totals));
            $employees = [];
            if ($isManager) {
                $eu = db()->prepare('SELECT id, name FROM users WHERE id = ? OR owner_id = ? ORDER BY name');
                $eu->execute([$tid, $tid]);
                $employees = $eu->fetchAll();
            }
            json_response(['success' => true, 'items' => $shifts, 'totals' => $totals, 'total_seconds' => array_sum(array_column($totals, 'seconds')), 'employees' => $employees, 'is_manager' => $isManager,
                           'range' => ['from' => substr($from, 0, 10), 'to' => (new DateTime($toExcl))->modify('-1 day')->format('Y-m-d')]]);
        }
        json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $pdo = db();

    /* ------------------------------ O MEU TURNO ------------------------------ */
    if ($action === 'clock_in') {
        if (open_shift($tid, $uid)) json_response(['success' => false, 'error' => 'Já tens um turno em curso.'], 409);
        $pdo->prepare("INSERT INTO work_shifts (tenant_id, employee_id, started_at, status, source) VALUES (?,?,?, 'active', 'clock')")->execute([$tid, $uid, now_dt()->format('Y-m-d H:i:s')]);
        json_response(['success' => true] + my_status($tid, $uid), 201);
    }
    $shift = open_shift($tid, $uid);
    if (in_array($action, ['pause', 'resume', 'clock_out'], true)) {
        if (!$shift) json_response(['success' => false, 'error' => 'Não tens nenhum turno em curso.'], 409);
        $now = now_dt()->format('Y-m-d H:i:s');
        if ($action === 'pause') {
            if ($shift['status'] === 'paused') json_response(['success' => false, 'error' => 'O turno já está em pausa.'], 409);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO work_pauses (shift_id, started_at) VALUES (?,?)')->execute([$shift['id'], $now]);
            $pdo->prepare("UPDATE work_shifts SET status = 'paused' WHERE id = ?")->execute([$shift['id']]);
            $pdo->commit();
        } elseif ($action === 'resume') {
            if ($shift['status'] !== 'paused') json_response(['success' => false, 'error' => 'O turno não está em pausa.'], 409);
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE work_pauses SET ended_at = ? WHERE shift_id = ? AND ended_at IS NULL')->execute([$now, $shift['id']]);
            $pdo->prepare("UPDATE work_shifts SET status = 'active' WHERE id = ?")->execute([$shift['id']]);
            $pdo->commit();
        } else {                                                    // saída: fecha também uma pausa que esteja aberta
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE work_pauses SET ended_at = ? WHERE shift_id = ? AND ended_at IS NULL')->execute([$now, $shift['id']]);
            $pdo->prepare("UPDATE work_shifts SET status = 'closed', ended_at = ? WHERE id = ?")->execute([$now, $shift['id']]);
            $pdo->commit();
        }
        json_response(['success' => true] + my_status($tid, $uid));
    }

    /* ------------------------------ CORREÇÕES (gestor) ------------------------------ */
    if (in_array($action, ['edit', 'create_manual', 'delete'], true)) {
        if (!$isManager) json_response(['success' => false, 'error' => 'Sem permissão para corrigir horários.'], 403);
        $reason = trim(mb_substr((string)($data['reason'] ?? ''), 0, 255));
        if ($action !== 'delete' && mb_strlen($reason) < 3) json_response(['success' => false, 'error' => 'Indica o motivo da correção.'], 400);

        if ($action === 'edit') {
            $s = find_shift($tid, (int)($data['id'] ?? 0));
            if ($s['ended_at'] === null) json_response(['success' => false, 'error' => 'Termina o turno antes de o corrigir.'], 409);
            [$a, $b] = validate_times($data, $tid, (int)$s['employee_id'], (int)$s['id']);
            $pdo->prepare("UPDATE work_shifts SET started_at = ?, ended_at = ?, source = 'manual', edited_by = ?, edited_at = ?, edit_reason = ? WHERE id = ?")
                ->execute([$a, $b, $uid, now_dt()->format('Y-m-d H:i:s'), $reason, $s['id']]);
            $pdo->prepare('DELETE FROM work_pauses WHERE shift_id = ? AND (started_at < ? OR started_at > ?)')->execute([$s['id'], $a, $b]);       // pausas fora do novo horário deixam de valer
            audit('shift_edit', ['shift' => (int)$s['id'], 'employee' => (int)$s['employee_id'], 'before' => [$s['started_at'], $s['ended_at']], 'after' => [$a, $b], 'reason' => $reason], $user);
            json_response(['success' => true]);
        }
        if ($action === 'create_manual') {
            $emp = (int)($data['employee_id'] ?? 0);
            $chk = $pdo->prepare('SELECT COUNT(*) FROM users WHERE id = ? AND (id = ? OR owner_id = ?)');
            $chk->execute([$emp, $tid, $tid]);
            if (!(int)$chk->fetchColumn()) json_response(['success' => false, 'error' => 'Funcionário não encontrado.'], 404);
            [$a, $b] = validate_times($data, $tid, $emp, null);
            $pdo->prepare("INSERT INTO work_shifts (tenant_id, employee_id, started_at, ended_at, status, source, edited_by, edited_at, edit_reason) VALUES (?,?,?,?, 'closed', 'manual', ?, ?, ?)")
                ->execute([$tid, $emp, $a, $b, $uid, now_dt()->format('Y-m-d H:i:s'), $reason]);
            $newId = (int)$pdo->lastInsertId();                    // ANTES de audit(): ele também insere uma linha e mudaria este valor
            audit('shift_create_manual', ['employee' => $emp, 'after' => [$a, $b], 'reason' => $reason], $user);
            json_response(['success' => true, 'id' => $newId], 201);
        }
        if ($action === 'delete') {
            if (!$isOwner) json_response(['success' => false, 'error' => 'Só o administrador elimina registos.'], 403);
            $s = find_shift($tid, (int)($data['id'] ?? 0));
            $pdo->prepare('DELETE FROM work_shifts WHERE id = ?')->execute([$s['id']]);
            audit('shift_delete', ['shift' => (int)$s['id'], 'employee' => (int)$s['employee_id'], 'before' => [$s['started_at'], $s['ended_at']]], $user);
            json_response(['success' => true]);
        }
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    internal_error($error);
}
