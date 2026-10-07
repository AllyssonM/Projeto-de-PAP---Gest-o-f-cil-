<?php
/* =========================================================================
   ÁREA DO FUNCIONÁRIO: TAREFAS, METAS E PAUSA  (api/work.php)
   -------------------------------------------------------------------------
   O funcionário vê o que o líder lhe atribuiu e o que o líder pode ver sobre ele (nada é escondido):
   GET                      tarefas por fazer e concluídas, metas deste mês e do próximo (com o progresso), comentários partilhados, métricas do mês.
   POST {action:'task_done'|'task_reopen', id}   conclui ou reabre UMA tarefa SUA.
   POST {action:'presence', state:'pause'|'auto'} marca ou termina a pausa.
   O líder usa api/staff.php (esta API é só para funcionários).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/team.php';
$user = require_login();
if ($user['role'] === 'owner') json_response(['success' => false, 'error' => 'Esta área é dos funcionários. O responsável usa «Funcionários».'], 403);
$tid = (int)$user['tenant_id'];
$uid = (int)$user['id'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = db()->prepare("SELECT id, title, detail, due_date, status, completed_at FROM employee_tasks WHERE tenant_id = ? AND employee_id = ? AND status = 'pending' ORDER BY due_date IS NULL, due_date, id LIMIT 50");
        $q->execute([$tid, $uid]); $pending = $q->fetchAll();
        $q = db()->prepare("SELECT id, title, completed_at FROM employee_tasks WHERE tenant_id = ? AND employee_id = ? AND status = 'done' ORDER BY completed_at DESC LIMIT 10");
        $q->execute([$tid, $uid]); $done = $q->fetchAll();
        $now = new DateTimeImmutable('first day of this month', app_timezone());
        $q = db()->prepare('SELECT id, employee_id, kind, target, month FROM employee_goals WHERE tenant_id = ? AND employee_id = ? AND month BETWEEN ? AND ? ORDER BY month, kind');
        $q->execute([$tid, $uid, $now->format('Y-m'), $now->modify('+1 month')->format('Y-m')]);
        $goals = array_map(fn($g) => ['id' => (int)$g['id'], 'kind_label' => TEAM_GOAL_KINDS[$g['kind']] ?? $g['kind'], 'kind' => $g['kind'], 'month' => $g['month'], 'target' => $g['target'], 'actual' => $g['actual'], 'percent' => $g['percent'], 'achieved' => $g['achieved']],
            team_goal_progress($tid, $q->fetchAll()));
        $q = db()->prepare('SELECT id, body, created_at FROM leader_notes WHERE tenant_id = ? AND employee_id = ? AND shared = 1 ORDER BY id DESC LIMIT 10');
        $q->execute([$tid, $uid]);
        $p = team_period('month');
        $m = team_metrics($tid, [$uid], $p['from'], $p['to'])[$uid];
        $self = db()->prepare('SELECT presence FROM users WHERE id = ?'); $self->execute([$uid]);
        $presence = team_presence([['id' => $uid, 'presence' => $self->fetchColumn() ?: 'auto']])[$uid];
        json_response(['success' => true, 'now' => team_now(), 'tasks_pending' => $pending, 'tasks_done' => $done, 'goals' => $goals, 'comments' => $q->fetchAll(),
            'month' => ['sales_count' => $m['sales_count'], 'sales_value' => $m['sales_value'], 'tasks_done' => $m['tasks_done'], 'hours' => $m['hours'], 'productivity' => $m['productivity'], 'performance' => $m['performance']],
            'state' => $presence['state']]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $b = request_json();
    $action = (string)($b['action'] ?? '');
    if ($action === 'task_done' || $action === 'task_reopen') {
        $st = $action === 'task_done'
            ? db()->prepare("UPDATE employee_tasks SET status = 'done', completed_at = NOW() WHERE id = ? AND tenant_id = ? AND employee_id = ? AND status = 'pending'")
            : db()->prepare("UPDATE employee_tasks SET status = 'pending', completed_at = NULL WHERE id = ? AND tenant_id = ? AND employee_id = ? AND status = 'done'");
        $st->execute([(int)($b['id'] ?? 0), $tid, $uid]);
        json_response(['success' => true, 'updated' => $st->rowCount()]);
    }
    if ($action === 'presence') {
        $state = ($b['state'] ?? '') === 'pause' ? 'pause' : 'auto';
        db()->prepare('UPDATE users SET presence = ? WHERE id = ?')->execute([$state, $uid]);
        json_response(['success' => true, 'state' => $state]);
    }
    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (Throwable $e) {
    internal_error($e);
}
