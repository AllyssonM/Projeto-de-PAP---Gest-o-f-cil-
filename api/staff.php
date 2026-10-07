<?php
/* =========================================================================
   FUNCIONÁRIOS: ÁREA DO LÍDER  (api/staff.php)
   -------------------------------------------------------------------------
   Só o responsável do negócio (o «líder») usa esta API. Os cálculos estão em includes/team.php.
   GET  ?period=today|week|month|custom&from=&to=
        &q=nome/cargo  &job=cargo exato  &status=online|away|pause|offline  &prod_min=  &perf_min=  &sales_min=  &sort=name|productivity|performance|sales
        → resumo da equipa (sem filtros) + cartões dos funcionários (com filtros).
   GET  ?id=N&period=...   → perfil detalhado de um funcionário.
   POST {action: task_create | task_delete | goal_set | goal_delete | note_add | note_delete | announce | presence?...}
   As mensagens estão em api/messages.php e os avisos em api/notifications.php.
   REGRAS: só se mexe em funcionários DO PRÓPRIO negócio; nada de «espiar»: só dados que o Lumina já regista (vendas, tarefas, turnos, entradas, mensagens).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/team.php';
$user = require_login();
require_owner($user);
$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];

/** O funcionário (do próprio negócio) ou 404. */
function staff_employee(int $tid, int $id): array
{
    $st = db()->prepare('SELECT id, name, email, phone, job_title, department, hired_at, status, avatar_path, last_login_at, presence FROM users WHERE id = ? AND owner_id = ? LIMIT 1');
    $st->execute([$id, $tid]);
    $e = $st->fetch();
    if (!$e) json_response(['success' => false, 'error' => 'Funcionário não encontrado.'], 404);
    return $e;
}

/** O cartão de um funcionário: dados + presença + métricas. */
function staff_card(array $e, array $presence, array $m): array
{
    $la = $m['last_activity'];
    return ['id' => (int)$e['id'], 'name' => $e['name'], 'job_title' => $e['job_title'], 'department' => $e['department'], 'photo' => team_photo_url($e),
        'state' => $presence['state'], 'last_login_at' => $e['last_login_at'],
        'productivity' => $m['productivity'], 'performance' => $m['performance'], 'sales_count' => $m['sales_count'], 'sales_value' => $m['sales_value'],
        'goals_done' => $m['goals_done'], 'goals_total' => $m['goals_total'], 'goal_progress' => $m['goal_progress'], 'tasks_done' => $m['tasks_done'],
        'tasks_pending' => $m['tasks_pending'], 'tasks_overdue' => $m['tasks_overdue'], 'hours' => $m['hours'],
        'last_activity' => $la ? ['text' => TEAM_ACTIVITY_TEXT[$la['kind']] ?? $la['kind'], 'at' => $la['at']] : null];
}

/** Série de um funcionário: tarefas concluídas, vendas e horas por dia, semana (segunda-feira) ou mês. */
function staff_series(int $tid, int $emp, string $unit, int $count): array
{
    $tz = app_timezone(); $today = new DateTimeImmutable('today', $tz);
    $keyOf = fn(string $col) => match ($unit) { 'day' => "DATE($col)", 'week' => "DATE_SUB(DATE($col), INTERVAL WEEKDAY($col) DAY)", default => "DATE_FORMAT($col, '%Y-%m')" };
    $buckets = [];
    for ($i = $count - 1; $i >= 0; $i--) {
        $d = match ($unit) { 'day' => $today->modify("-$i day"), 'week' => $today->modify('monday this week')->modify("-$i week"), default => $today->modify('first day of this month')->modify("-$i month") };
        $key = $unit === 'month' ? $d->format('Y-m') : $d->format('Y-m-d');
        $label = match ($unit) { 'day' => $d->format('d/m'), 'week' => $d->format('d/m'), default => $d->format('m/Y') };
        $buckets[$key] = ['label' => $label, 'tasks' => 0, 'sales' => 0, 'sales_value' => 0.0, 'hours' => 0.0];
    }
    $firstKey = array_key_first($buckets); $from = (strlen($firstKey) === 7 ? $firstKey . '-01' : $firstKey) . ' 00:00:00';
    $st = db()->prepare('SELECT ' . $keyOf('sold_at') . ' k, COUNT(*) n, COALESCE(SUM(amount), 0) v FROM sales WHERE user_id = ? AND is_demo = 0 AND status = \'completed\' AND created_by = ? AND sold_at >= ? GROUP BY k');
    $st->execute([$tid, $emp, $from]);
    foreach ($st->fetchAll() as $r) if (isset($buckets[$r['k']])) { $buckets[$r['k']]['sales'] = (int)$r['n']; $buckets[$r['k']]['sales_value'] = round((float)$r['v'], 2); }
    $st = db()->prepare("SELECT " . $keyOf('completed_at') . " k, COUNT(*) n FROM employee_tasks WHERE tenant_id = ? AND employee_id = ? AND status = 'done' AND completed_at >= ? GROUP BY k");
    $st->execute([$tid, $emp, $from]);
    foreach ($st->fetchAll() as $r) if (isset($buckets[$r['k']])) $buckets[$r['k']]['tasks'] = (int)$r['n'];
    $st = db()->prepare('SELECT ' . $keyOf('started_at') . ' k, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW()))), 0) mins FROM work_shifts WHERE tenant_id = ? AND employee_id = ? AND started_at >= ? GROUP BY k');
    $st->execute([$tid, $emp, $from]);
    foreach ($st->fetchAll() as $r) if (isset($buckets[$r['k']])) $buckets[$r['k']]['hours'] = round((int)$r['mins'] / 60, 1);
    return array_values($buckets);
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    /* ======================= LEITURA ======================= */
    if ($method === 'GET') {
        $p = team_period($_GET['period'] ?? 'month', $_GET['from'] ?? null, $_GET['to'] ?? null);
        $st = db()->prepare("SELECT id, name, email, phone, job_title, department, hired_at, status, avatar_path, last_login_at, presence FROM users WHERE owner_id = ? AND status = 'active' ORDER BY name");
        $st->execute([$tid]);
        $emps = $st->fetchAll();
        $ids = array_map('intval', array_column($emps, 'id'));

        /* ---- perfil detalhado ---- */
        if (isset($_GET['id'])) {
            $e = staff_employee($tid, (int)$_GET['id']);
            $id = (int)$e['id'];
            $presence = team_presence([$e])[$id]; $m = team_metrics($tid, [$id], $p['from'], $p['to'])[$id];
            $goals = db()->prepare('SELECT id, employee_id, kind, target, month FROM employee_goals WHERE tenant_id = ? AND employee_id = ? AND month >= ? ORDER BY month DESC, kind');
            $goals->execute([$tid, $id, (new DateTimeImmutable('first day of this month', app_timezone()))->modify('-2 month')->format('Y-m')]);
            $goalRows = array_map(fn($g) => ['id' => (int)$g['id'], 'kind' => $g['kind'], 'kind_label' => TEAM_GOAL_KINDS[$g['kind']] ?? $g['kind'], 'month' => $g['month'], 'target' => $g['target'], 'actual' => $g['actual'], 'percent' => $g['percent'], 'achieved' => $g['achieved']],
                team_goal_progress($tid, $goals->fetchAll()));
            $q = db()->prepare("SELECT id, title, detail, due_date, status, created_at, completed_at FROM employee_tasks WHERE tenant_id = ? AND employee_id = ? AND status = 'pending' ORDER BY due_date IS NULL, due_date, id LIMIT 50");
            $q->execute([$tid, $id]); $pending = $q->fetchAll();
            $q = db()->prepare("SELECT id, title, completed_at FROM employee_tasks WHERE tenant_id = ? AND employee_id = ? AND status = 'done' ORDER BY completed_at DESC LIMIT 10");
            $q->execute([$tid, $id]); $done = $q->fetchAll();
            $q = db()->prepare('SELECT started_at, ended_at, source, TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW())) mins FROM work_shifts WHERE tenant_id = ? AND employee_id = ? ORDER BY started_at DESC LIMIT 15');
            $q->execute([$tid, $id]); $shifts = $q->fetchAll();
            $q = db()->prepare('SELECT logged_at FROM login_log WHERE tenant_id = ? AND user_id = ? ORDER BY id DESC LIMIT 10');
            $q->execute([$tid, $id]); $logins = $q->fetchAll(PDO::FETCH_COLUMN);
            $q = db()->prepare('SELECT id, body, shared, created_at FROM leader_notes WHERE tenant_id = ? AND employee_id = ? ORDER BY id DESC LIMIT 30');
            $q->execute([$tid, $id]); $notes = array_map(fn($n) => ['id' => (int)$n['id'], 'body' => $n['body'], 'shared' => (bool)$n['shared'], 'created_at' => $n['created_at']], $q->fetchAll());
            $q = db()->prepare('SELECT COUNT(*) FROM team_messages WHERE tenant_id = ? AND from_user = ? AND to_user = ? AND read_at IS NULL');
            $q->execute([$tid, $id, $uid]); $unread = (int)$q->fetchColumn();
            json_response(['success' => true, 'now' => team_now(), 'period' => $p, 'employee' => ['id' => $id, 'name' => $e['name'], 'email' => $e['email'], 'phone' => $e['phone'], 'job_title' => $e['job_title'], 'department' => $e['department'],
                'hired_at' => $e['hired_at'], 'photo' => team_photo_url($e)] + staff_card($e, $presence, $m),
                'series' => ['day' => staff_series($tid, $id, 'day', 14), 'week' => staff_series($tid, $id, 'week', 8), 'month' => staff_series($tid, $id, 'month', 6)],
                'goals' => $goalRows, 'tasks_pending' => $pending, 'tasks_done' => $done, 'shifts' => $shifts, 'logins' => $logins, 'notes' => $notes, 'unread_messages' => $unread]);
        }

        /* ---- lista + resumo ---- */
        $presence = team_presence($emps); $metrics = team_metrics($tid, $ids, $p['from'], $p['to']);
        $all = [];
        foreach ($emps as $e) $all[] = staff_card($e, $presence[(int)$e['id']], $metrics[(int)$e['id']]);

        // resumo (da equipa toda, sem filtros)
        $count = fn(string $s) => count(array_filter($all, fn($c) => $c['state'] === $s));
        $prods = array_values(array_filter(array_column($all, 'productivity'), fn($v) => $v !== null));
        $best = null; foreach ($all as $c) if ($c['performance'] !== null && ($best === null || $c['performance'] > $best['performance'])) $best = $c;
        $q = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL'); $q->execute([$uid]); $notifUnread = (int)$q->fetchColumn();
        $q = db()->prepare('SELECT COUNT(*) FROM team_messages WHERE tenant_id = ? AND to_user = ? AND read_at IS NULL'); $q->execute([$tid, $uid]); $msgUnread = (int)$q->fetchColumn();
        $q = db()->prepare('SELECT l.user_id, u.name, u.avatar_path, l.logged_at FROM login_log l JOIN users u ON u.id = l.user_id AND u.owner_id = ? WHERE l.tenant_id = ? ORDER BY l.id DESC LIMIT 6');
        $q->execute([$tid, $tid]);
        $logins = array_map(fn($r) => ['user_id' => (int)$r['user_id'], 'name' => $r['name'], 'photo' => team_photo_url(['id' => $r['user_id'], 'avatar_path' => $r['avatar_path']]), 'at' => $r['logged_at']], $q->fetchAll());
        $summary = ['total' => count($all), 'online' => $count('online'), 'away' => $count('away'), 'pause' => $count('pause'), 'offline' => $count('offline'),
            'avg_productivity' => $prods ? (int)round(array_sum($prods) / count($prods)) : null, 'sales_count' => array_sum(array_column($all, 'sales_count')),
            'sales_value' => round(array_sum(array_column($all, 'sales_value')), 2), 'best' => $best ? ['id' => $best['id'], 'name' => $best['name'], 'performance' => $best['performance'], 'photo' => $best['photo']] : null,
            'goals_done' => array_sum(array_column($all, 'goals_done')), 'goals_total' => array_sum(array_column($all, 'goals_total')),
            'recent_activity' => team_recent_activity($tid, 8), 'recent_logins' => $logins, 'notifications_unread' => $notifUnread, 'messages_unread' => $msgUnread];

        // filtros
        $q = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $job = trim((string)($_GET['job'] ?? '')); $status = (string)($_GET['status'] ?? '');
        $num = fn(string $k) => isset($_GET[$k]) && $_GET[$k] !== '' && is_numeric($_GET[$k]) ? (float)$_GET[$k] : null;
        $pm = $num('prod_min'); $fm = $num('perf_min'); $sm = $num('sales_min');
        $list = array_values(array_filter($all, function ($c) use ($q, $job, $status, $pm, $fm, $sm) {
            if ($q !== '' && !str_contains(mb_strtolower(($c['name'] ?? '') . ' ' . ($c['job_title'] ?? '') . ' ' . ($c['department'] ?? '')), $q)) return false;
            if ($job !== '' && ($c['job_title'] ?? '') !== $job) return false;
            if (in_array($status, ['online', 'away', 'pause', 'offline'], true) && $c['state'] !== $status) return false;
            if ($pm !== null && ($c['productivity'] === null || $c['productivity'] < $pm)) return false;
            if ($fm !== null && ($c['performance'] === null || $c['performance'] < $fm)) return false;
            if ($sm !== null && $c['sales_count'] < $sm) return false;
            return true;
        }));
        $sort = (string)($_GET['sort'] ?? 'name');
        $key = ['productivity' => 'productivity', 'performance' => 'performance', 'sales' => 'sales_count'][$sort] ?? null;
        if ($key) usort($list, fn($a, $b) => ($b[$key] ?? -1) <=> ($a[$key] ?? -1) ?: strcmp($a['name'], $b['name']));
        $jobs = array_values(array_unique(array_filter(array_column($all, 'job_title'))));
        sort($jobs);
        json_response(['success' => true, 'now' => team_now(), 'period' => $p, 'summary' => $summary, 'employees' => $list, 'jobs' => $jobs, 'filtered' => count($list) !== count($all)]);
    }

    /* ======================= AÇÕES ======================= */
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $body = request_json();
    $action = (string)($body['action'] ?? '');

    if ($action === 'task_create') {
        $in = Validator::make($body)->id('employee_id', 'Funcionário', true)->text('title', 'Tarefa', 200)->text('detail', 'Detalhe', 1000, false, null)->date('due_date', 'Prazo', false)->orFail();
        $e = staff_employee($tid, (int)$in['employee_id']);
        db()->prepare('INSERT INTO employee_tasks (tenant_id, employee_id, title, detail, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?)')->execute([$tid, $e['id'], $in['title'], $in['detail'], $in['due_date'] ?: null, $uid]);
        $newId = (int)db()->lastInsertId();
        notify($tid, (int)$e['id'], 'task', 'Nova tarefa: ' . $in['title'], $in['due_date'] ? 'Prazo: ' . date('d/m/Y', strtotime($in['due_date'])) : '', $uid, 'work');
        audit('staff_task_created', ['employee' => (int)$e['id']], $user);
        json_response(['success' => true, 'id' => $newId], 201);
    }
    if ($action === 'task_delete') {
        $st = db()->prepare('DELETE FROM employee_tasks WHERE id = ? AND tenant_id = ?');
        $st->execute([(int)($body['id'] ?? 0), $tid]);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if ($action === 'goal_set') {
        $in = Validator::make($body)->id('employee_id', 'Funcionário', true)->enum('kind', 'Tipo de meta', array_keys(TEAM_GOAL_KINDS))->money('target', 'Objetivo')->orFail();
        $month = (string)($body['month'] ?? '');
        $now = new DateTimeImmutable('first day of this month', app_timezone());
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month < $now->modify('-12 month')->format('Y-m') || $month > $now->modify('+12 month')->format('Y-m')) {
            json_response(['success' => false, 'error' => 'Mês inválido (use AAAA-MM, no máximo 12 meses para cada lado).'], 400);
        }
        $e = staff_employee($tid, (int)$in['employee_id']);
        $had = db()->prepare('SELECT id FROM employee_goals WHERE employee_id = ? AND kind = ? AND month = ?'); $had->execute([$e['id'], $in['kind'], $month]);
        $existed = (bool)$had->fetchColumn();
        db()->prepare('INSERT INTO employee_goals (tenant_id, employee_id, kind, target, month) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE target = VALUES(target)')->execute([$tid, $e['id'], $in['kind'], $in['target'], $month]);
        $label = TEAM_GOAL_KINDS[$in['kind']];
        notify($tid, (int)$e['id'], 'goal', $existed ? 'A tua meta foi alterada' : 'Tens uma nova meta', $label . ' em ' . substr($month, 5, 2) . '/' . substr($month, 0, 4) . ': ' . rtrim(rtrim(number_format($in['target'], 2, ',', ' '), '0'), ','), $uid, 'work');
        audit('staff_goal_set', ['employee' => (int)$e['id'], 'kind' => $in['kind'], 'month' => $month], $user);
        json_response(['success' => true, 'changed' => $existed], 201);
    }
    if ($action === 'goal_delete') {
        $st = db()->prepare('DELETE FROM employee_goals WHERE id = ? AND tenant_id = ?');
        $st->execute([(int)($body['id'] ?? 0), $tid]);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if ($action === 'note_add') {
        $in = Validator::make($body)->id('employee_id', 'Funcionário', true)->text('body', 'Observação', 1000)->orFail();
        $shared = !empty($body['shared']);
        $e = staff_employee($tid, (int)$in['employee_id']);
        db()->prepare('INSERT INTO leader_notes (tenant_id, employee_id, author_id, body, shared) VALUES (?, ?, ?, ?, ?)')->execute([$tid, $e['id'], $uid, $in['body'], $shared ? 1 : 0]);
        $newId = (int)db()->lastInsertId();
        if ($shared) notify($tid, (int)$e['id'], 'feedback', 'Novo comentário sobre o teu desempenho', $in['body'], $uid, 'work');
        audit('staff_note_added', ['employee' => (int)$e['id'], 'shared' => $shared], $user);
        json_response(['success' => true, 'id' => $newId], 201);
    }
    if ($action === 'note_delete') {
        $st = db()->prepare('DELETE FROM leader_notes WHERE id = ? AND tenant_id = ?');
        $st->execute([(int)($body['id'] ?? 0), $tid]);
        json_response(['success' => true, 'deleted' => $st->rowCount()]);
    }
    if ($action === 'announce') {
        $in = Validator::make($body)->text('title', 'Título', 100)->text('body', 'Mensagem', 300, false, null)->orFail();
        $n = notify_employees($tid, 'announcement', $in['title'], (string)$in['body'], $uid, 'work');
        audit('staff_announcement', ['recipients' => $n], $user);
        json_response(['success' => true, 'recipients' => $n], 201);
    }
    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $e) {
    internal_error($e);
}
