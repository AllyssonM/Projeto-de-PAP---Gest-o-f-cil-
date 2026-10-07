<?php
/* =========================================================================
   GESTÃO DE FUNCIONÁRIOS: CÁLCULOS E AVISOS  (includes/team.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  tudo o que a aba «Funcionários» mostra é calculado AQUI, uma só vez, para todas as páginas darem os mesmos números:
                 - PERÍODO (hoje, semana, mês ou datas à escolha);
                 - PRESENÇA (online, ausente, em pausa, offline);
                 - MÉTRICAS de cada funcionário (vendas, tarefas, metas, horas, produtividade, desempenho, última atividade);
                 - AVISOS (notify / notify_employees) que aparecem no sino de cada pessoa.
   COMO SE CALCULA (e é isto que a interface explica à pessoa, sem caixas-pretas):
     PRESENÇA       online = atividade nos últimos 5 min; ausente = entre 5 e 30 min; offline = mais de 30 min ou sem sessão;
                    «em pausa» = o próprio funcionário marcou a pausa (e continua com sessão aberta).
     PRODUTIVIDADE  tarefas concluídas ÷ tarefas do período (as que têm prazo no período, ou sem prazo criadas nele, ou concluídas nele).
                    Sem tarefas = sem dados (nunca se inventa 0 %).
     METAS          progresso de cada meta do(s) mês(es) do período (valor real ÷ objetivo, no máximo 100 %); «cumprida» = objetivo atingido.
     DESEMPENHO     média da produtividade e do progresso das metas (só das que têm dados). Sem nenhuma = sem dados.
   REGRAS:     tudo é do PRÓPRIO negócio (tenant_id); vendas de demonstração nunca contam; nada aqui chama a rede.
   ========================================================================= */
declare(strict_types=1);

const TEAM_ONLINE_SECONDS = 300;      // 5 min
const TEAM_AWAY_SECONDS = 1800;       // 30 min
const TEAM_GOAL_KINDS = ['sales_count' => 'Número de vendas', 'sales_value' => 'Valor das vendas', 'tasks_done' => 'Tarefas concluídas'];

/** Hora atual do negócio (AAAA-MM-DD HH:MM:SS): a interface compara com ela para dizer «há 5 min» sem depender do relógio do computador. */
function team_now(): string { return (new DateTimeImmutable('now', app_timezone()))->format('Y-m-d H:i:s'); }

/** Placeholders (?,?,?) para uma lista de ids; lista vazia vira (NULL) e não devolve nada. */
function team_in(array $ids): string { return $ids ? '(' . implode(',', array_fill(0, count($ids), '?')) . ')' : '(NULL)'; }

/** Período pedido. @return array{key:string,from:string,to:string} datas AAAA-MM-DD, from <= to, no máximo 366 dias. */
function team_period(?string $key, ?string $from = null, ?string $to = null): array
{
    $tz = app_timezone();
    $today = new DateTimeImmutable('today', $tz);
    $key = in_array($key, ['today', 'week', 'month', 'custom'], true) ? $key : 'month';
    if ($key === 'today') return ['key' => $key, 'from' => $today->format('Y-m-d'), 'to' => $today->format('Y-m-d')];
    if ($key === 'week') {
        $monday = $today->modify('monday this week');
        return ['key' => $key, 'from' => $monday->format('Y-m-d'), 'to' => $monday->modify('+6 days')->format('Y-m-d')];
    }
    if ($key === 'custom') {
        $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && ($dt = DateTimeImmutable::createFromFormat('Y-m-d', $d, $tz)) && $dt->format('Y-m-d') === $d;
        if ($ok($from) && $ok($to) && $from <= $to && (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days <= 365) return ['key' => 'custom', 'from' => $from, 'to' => $to];
    }
    return ['key' => 'month', 'from' => $today->format('Y-m-01'), 'to' => $today->modify('last day of this month')->format('Y-m-d')];
}

/** Meses (AAAA-MM) entre duas datas. */
function team_months(string $from, string $to): array
{
    $out = []; $d = new DateTimeImmutable(substr($from, 0, 7) . '-01'); $end = substr($to, 0, 7);
    while ($d->format('Y-m') <= $end && count($out) < 24) { $out[] = $d->format('Y-m'); $d = $d->modify('+1 month'); }
    return $out;
}

/** Presença de cada utilizador. @return array<int,array{state:string,idle:?int}> state: online|away|pause|offline */
function team_presence(array $users): array
{
    $ids = array_map('intval', array_column($users, 'id'));
    $idle = [];
    if ($ids) {
        $st = db()->prepare('SELECT user_id, TIMESTAMPDIFF(SECOND, MAX(last_seen_at), NOW()) AS idle FROM user_sessions WHERE revoked_at IS NULL AND expires_at > NOW() AND user_id IN ' . team_in($ids) . ' GROUP BY user_id');
        $st->execute($ids);
        foreach ($st->fetchAll() as $r) $idle[(int)$r['user_id']] = max(0, (int)$r['idle']);
    }
    $out = [];
    foreach ($users as $u) {
        $i = $idle[(int)$u['id']] ?? null;
        if ($i === null || $i > TEAM_AWAY_SECONDS) $state = 'offline';
        elseif (($u['presence'] ?? 'auto') === 'pause') $state = 'pause';
        elseif ($i <= TEAM_ONLINE_SECONDS) $state = 'online';
        else $state = 'away';
        $out[(int)$u['id']] = ['state' => $state, 'idle' => $i];
    }
    return $out;
}

/** Valor real de cada meta (vendas e tarefas do mês da meta). Acrescenta 'actual', 'percent' (0 a 100) e 'achieved' a cada meta. */
function team_goal_progress(int $tenant, array $goals): array
{
    if (!$goals) return [];
    $ids = array_values(array_unique(array_map(fn($g) => (int)$g['employee_id'], $goals)));
    $months = array_column($goals, 'month'); $min = min($months); $max = max($months);
    $end = (new DateTimeImmutable($max . '-01'))->modify('+1 month')->format('Y-m-d');
    $sales = []; $tasks = [];
    $st = db()->prepare("SELECT created_by e, DATE_FORMAT(sold_at, '%Y-%m') m, COUNT(*) n, COALESCE(SUM(amount), 0) v FROM sales WHERE user_id = ? AND is_demo = 0 AND status = 'completed' AND sold_at >= ? AND sold_at < ? AND created_by IN " . team_in($ids) . ' GROUP BY e, m');
    $st->execute(array_merge([$tenant, $min . '-01', $end], $ids));
    foreach ($st->fetchAll() as $r) $sales[$r['e'] . '|' . $r['m']] = [(int)$r['n'], (float)$r['v']];
    $st = db()->prepare("SELECT employee_id e, DATE_FORMAT(completed_at, '%Y-%m') m, COUNT(*) n FROM employee_tasks WHERE tenant_id = ? AND status = 'done' AND completed_at >= ? AND completed_at < ? AND employee_id IN " . team_in($ids) . ' GROUP BY e, m');
    $st->execute(array_merge([$tenant, $min . '-01', $end], $ids));
    foreach ($st->fetchAll() as $r) $tasks[$r['e'] . '|' . $r['m']] = (int)$r['n'];
    foreach ($goals as &$g) {
        $k = $g['employee_id'] . '|' . $g['month'];
        $actual = match ($g['kind']) { 'sales_count' => (float)($sales[$k][0] ?? 0), 'sales_value' => (float)($sales[$k][1] ?? 0), default => (float)($tasks[$k] ?? 0) };
        $target = (float)$g['target'];
        $g['actual'] = round($actual, 2); $g['target'] = $target;
        $g['percent'] = $target > 0 ? (int)min(100, floor($actual / $target * 100)) : 0;
        $g['achieved'] = $target > 0 && $actual >= $target;
    }
    return $goals;
}

/**
 * Métricas de cada funcionário no período. @return array<int,array>
 * Chaves: sales_count, sales_value, tasks_done, tasks_pending, tasks_overdue, goals_total, goals_done, goal_progress, hours, productivity, performance, last_activity.
 */
function team_metrics(int $tenant, array $ids, string $from, string $to): array
{
    $ids = array_values(array_map('intval', $ids));
    $m = [];
    foreach ($ids as $id) $m[$id] = ['sales_count' => 0, 'sales_value' => 0.0, 'tasks_done' => 0, 'tasks_pending' => 0, 'tasks_overdue' => 0, 'goals_total' => 0, 'goals_done' => 0,
                                     'goal_progress' => null, 'hours' => 0.0, 'productivity' => null, 'performance' => null, 'last_activity' => null];
    if (!$ids) return $m;
    $t0 = $from . ' 00:00:00'; $t1 = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
    $in = team_in($ids);

    $st = db()->prepare("SELECT created_by e, COUNT(*) n, COALESCE(SUM(amount), 0) v FROM sales WHERE user_id = ? AND is_demo = 0 AND status = 'completed' AND sold_at >= ? AND sold_at < ? AND created_by IN $in GROUP BY e");
    $st->execute(array_merge([$tenant, $t0, $t1], $ids));
    foreach ($st->fetchAll() as $r) { $m[(int)$r['e']]['sales_count'] = (int)$r['n']; $m[(int)$r['e']]['sales_value'] = round((float)$r['v'], 2); }

    // tarefas do período: com prazo no período, ou sem prazo e criadas nele, ou concluídas nele
    $st = db()->prepare("SELECT employee_id e, COUNT(*) total, SUM(status = 'done') done_all, SUM(status = 'done' AND completed_at >= ? AND completed_at < ?) done_in FROM employee_tasks
        WHERE tenant_id = ? AND employee_id IN $in AND ((due_date BETWEEN ? AND ?) OR (due_date IS NULL AND created_at >= ? AND created_at < ?) OR (completed_at >= ? AND completed_at < ?)) GROUP BY e");
    $st->execute(array_merge([$t0, $t1, $tenant], $ids, [$from, $to, $t0, $t1, $t0, $t1]));
    foreach ($st->fetchAll() as $r) {
        $m[(int)$r['e']]['tasks_done'] = (int)$r['done_in'];
        $m[(int)$r['e']]['productivity'] = (int)$r['total'] > 0 ? (int)round((int)$r['done_all'] / (int)$r['total'] * 100) : null;
    }
    $st = db()->prepare("SELECT employee_id e, SUM(status = 'pending') p, SUM(status = 'pending' AND due_date IS NOT NULL AND due_date < CURDATE()) o FROM employee_tasks WHERE tenant_id = ? AND employee_id IN $in GROUP BY e");
    $st->execute(array_merge([$tenant], $ids));
    foreach ($st->fetchAll() as $r) { $m[(int)$r['e']]['tasks_pending'] = (int)$r['p']; $m[(int)$r['e']]['tasks_overdue'] = (int)$r['o']; }

    $st = db()->prepare("SELECT employee_id e, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW()))), 0) mins FROM work_shifts WHERE tenant_id = ? AND started_at >= ? AND started_at < ? AND employee_id IN $in GROUP BY e");
    $st->execute(array_merge([$tenant, $t0, $t1], $ids));
    foreach ($st->fetchAll() as $r) $m[(int)$r['e']]['hours'] = round((int)$r['mins'] / 60, 1);

    // metas dos meses do período
    $months = team_months($from, $to);
    $st = db()->prepare("SELECT id, employee_id, kind, target, month FROM employee_goals WHERE tenant_id = ? AND employee_id IN $in AND month BETWEEN ? AND ?");
    $st->execute(array_merge([$tenant], $ids, [substr($from, 0, 7), substr($to, 0, 7)]));
    $prog = [];
    foreach (team_goal_progress($tenant, $st->fetchAll()) as $g) {
        $e = (int)$g['employee_id']; $m[$e]['goals_total']++; if ($g['achieved']) $m[$e]['goals_done']++; $prog[$e][] = $g['percent'];
    }
    foreach ($prog as $e => $list) $m[$e]['goal_progress'] = (int)round(array_sum($list) / count($list));

    foreach ($m as &$x) {
        $parts = array_values(array_filter([$x['productivity'], $x['goal_progress']], fn($v) => $v !== null));
        $x['performance'] = $parts ? (int)round(array_sum($parts) / count($parts)) : null;
    }
    unset($x);

    // última atividade (a mais recente entre vendas, tarefas, turnos, mensagens e entradas)
    $parts = [
        ["SELECT created_by w, 'sale' k, MAX(sold_at) at FROM sales WHERE user_id = ? AND is_demo = 0 AND status = 'completed' AND created_by IN $in GROUP BY w", array_merge([$tenant], $ids)],
        ["SELECT employee_id w, 'task' k, MAX(completed_at) at FROM employee_tasks WHERE tenant_id = ? AND status = 'done' AND employee_id IN $in GROUP BY w", array_merge([$tenant], $ids)],
        ["SELECT employee_id w, 'shift_in' k, MAX(started_at) at FROM work_shifts WHERE tenant_id = ? AND employee_id IN $in GROUP BY w", array_merge([$tenant], $ids)],
        ["SELECT employee_id w, 'shift_out' k, MAX(ended_at) at FROM work_shifts WHERE tenant_id = ? AND ended_at IS NOT NULL AND employee_id IN $in GROUP BY w", array_merge([$tenant], $ids)],
        ["SELECT from_user w, 'message' k, MAX(created_at) at FROM team_messages WHERE tenant_id = ? AND from_user IN $in GROUP BY w", array_merge([$tenant], $ids)],
        ["SELECT user_id w, 'login' k, MAX(logged_at) at FROM login_log WHERE tenant_id = ? AND user_id IN $in GROUP BY w", array_merge([$tenant], $ids)],
    ];
    foreach ($parts as [$sql, $args]) {
        $st = db()->prepare($sql); $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $e = (int)$r['w'];
            if ($r['at'] !== null && ($m[$e]['last_activity'] === null || $r['at'] > $m[$e]['last_activity']['at'])) $m[$e]['last_activity'] = ['kind' => $r['k'], 'at' => $r['at']];
        }
    }
    return $m;
}

const TEAM_ACTIVITY_TEXT = ['sale' => 'Registou uma venda', 'task' => 'Concluiu uma tarefa', 'shift_in' => 'Iniciou o turno', 'shift_out' => 'Terminou o turno', 'message' => 'Enviou uma mensagem', 'login' => 'Entrou no sistema'];

/** Atividades recentes de toda a equipa (para o painel do líder). */
function team_recent_activity(int $tenant, int $limit = 8): array
{
    $sql = "SELECT x.w, x.k, x.at, u.name, u.avatar_path FROM (
        SELECT s.created_by w, 'sale' k, s.sold_at at FROM sales s WHERE s.user_id = ? AND s.is_demo = 0 AND s.status = 'completed' AND s.created_by IS NOT NULL
        UNION ALL SELECT t.employee_id, 'task', t.completed_at FROM employee_tasks t WHERE t.tenant_id = ? AND t.status = 'done' AND t.completed_at IS NOT NULL
        UNION ALL SELECT w.employee_id, 'shift_in', w.started_at FROM work_shifts w WHERE w.tenant_id = ?
        UNION ALL SELECT w.employee_id, 'shift_out', w.ended_at FROM work_shifts w WHERE w.tenant_id = ? AND w.ended_at IS NOT NULL
        UNION ALL SELECT l.user_id, 'login', l.logged_at FROM login_log l WHERE l.tenant_id = ?
      ) x JOIN users u ON u.id = x.w AND u.owner_id = ? WHERE x.at IS NOT NULL ORDER BY x.at DESC LIMIT " . (int)$limit;
    $st = db()->prepare($sql);
    $st->execute([$tenant, $tenant, $tenant, $tenant, $tenant, $tenant]);
    return array_map(fn($r) => ['user_id' => (int)$r['w'], 'name' => $r['name'], 'kind' => $r['k'], 'text' => TEAM_ACTIVITY_TEXT[$r['k']] ?? $r['k'], 'at' => $r['at'], 'photo' => !empty($r['avatar_path']) ? 'api/files.php?type=avatar&id=' . (int)$r['w'] : null], $st->fetchAll());
}

/** Dá a um funcionário o objeto "cartão" (sem métricas), com a foto como endereço protegido. */
function team_photo_url(array $u): ?string { return !empty($u['avatar_path']) ? 'api/files.php?type=avatar&id=' . (int)$u['id'] : null; }

/** Cria um aviso para uma pessoa. Nunca interrompe a operação principal se falhar. */
function notify(int $tenant, int $to, string $type, string $title, string $body = '', ?int $actor = null, ?string $section = null): void
{
    try {
        db()->prepare('INSERT INTO notifications (tenant_id, user_id, type, title, body, actor_id, section) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$tenant, $to, mb_substr($type, 0, 16), mb_substr($title, 0, 160), $body !== '' ? mb_substr($body, 0, 300) : null, $actor, $section]);
    } catch (Throwable $e) { error_log('[lumina] aviso não criado: ' . $e->getMessage()); }
}

/** Aviso para TODOS os funcionários ativos do negócio. @return int quantos receberam */
function notify_employees(int $tenant, string $type, string $title, string $body = '', ?int $actor = null, ?string $section = null): int
{
    $st = db()->prepare("SELECT id FROM users WHERE owner_id = ? AND status = 'active'");
    $st->execute([$tenant]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { notify($tenant, (int)$id, $type, $title, $body, $actor, $section); $n++; }
    return $n;
}

/** Chamado quando um FUNCIONÁRIO conclui o login: regista a entrada, termina uma pausa antiga e avisa o líder (no máximo 1 aviso por funcionário em 2 minutos). */
function team_on_login(array $user): void
{
    if (($user['role'] ?? '') === 'owner') return;
    try {
        $tid = (int)$user['tenant_id']; $uid = (int)$user['id'];
        db()->prepare('INSERT INTO login_log (tenant_id, user_id) VALUES (?, ?)')->execute([$tid, $uid]);
        db()->prepare("UPDATE users SET presence = 'auto' WHERE id = ?")->execute([$uid]);
        $dup = db()->prepare("SELECT 1 FROM notifications WHERE user_id = ? AND type = 'login' AND actor_id = ? AND created_at > (NOW() - INTERVAL 2 MINUTE) LIMIT 1");
        $dup->execute([$tid, $uid]);
        if (!$dup->fetchColumn()) notify($tid, $tid, 'login', $user['name'] . ' entrou no sistema', '', $uid, 'staff');
        if (random_int(1, 50) === 1) team_cleanup();                                                                      // retenção (limpeza ocasional)
    } catch (Throwable $e) { error_log('[lumina] entrada não registada: ' . $e->getMessage()); }
}

/** Retenção: entradas no sistema com mais de 90 dias; avisos lidos há mais de 60 dias e quaisquer com mais de 180 dias. Chamada de vez em quando (login e sino). */
function team_cleanup(): array
{
    $a = db()->exec('DELETE FROM login_log WHERE logged_at < (NOW() - INTERVAL 90 DAY)');
    $b = db()->exec('DELETE FROM notifications WHERE (read_at IS NOT NULL AND read_at < (NOW() - INTERVAL 60 DAY)) OR created_at < (NOW() - INTERVAL 180 DAY)');
    return ['login_log' => (int)$a, 'notifications' => (int)$b];
}

