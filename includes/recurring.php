<?php
/* =========================================================================
   CONTAS E DESPESAS RECORRENTES  (includes/recurring.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  uma "conta recorrente" (renda, salário, seguro, subscrição...) gera sozinha as contas a pagar/receber
               pendentes, a cada semana/mês/trimestre/ano. As contas geradas aparecem na aba Contas como qualquer outra.
   COMO:       recurring_materialize($negócio) é chamada sempre que se leem as contas. Cria as ocorrências que vencem
               nos próximos 30 dias e avança "next_due". Não precisa de tarefas agendadas.
   SEGURANÇA DOS DADOS: um índice único (recurring_id + due_date) garante que a mesma conta nunca é criada duas vezes,
               mesmo com dois pedidos ao mesmo tempo.
   DATAS:      mensal/trimestral/anual mantêm o dia de início; se o mês não tem esse dia (31 em fevereiro), usa o
               último dia do mês, e no mês seguinte volta ao dia certo (sem "derivar" para 28).
   ========================================================================= */
declare(strict_types=1);

const RECURRING_HORIZON_DAYS = 30;
const RECURRING_FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'yearly'];

/** A ocorrência seguinte a $due, mantendo o dia de âncora ($anchorDay = dia do mês da data de início). */
function recurring_next(DateTimeImmutable $due, string $frequency, int $anchorDay): DateTimeImmutable
{
    if ($frequency === 'weekly') return $due->modify('+7 days');
    $months = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12][$frequency] ?? 1;
    $first = $due->modify('first day of this month')->modify("+$months months");
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($anchorDay, (int)$first->format('t')));
}

/** Gera as contas pendentes das recorrentes ativas deste negócio. Devolve quantas criou. */
function recurring_materialize(int $tenantId, ?DateTimeImmutable $today = null): int
{
    $today ??= new DateTimeImmutable('today', app_timezone());
    $horizon = $today->modify('+' . RECURRING_HORIZON_DAYS . ' days');
    $st = db()->prepare("SELECT id, direction, title, counterparty, amount, frequency, start_date, end_date, next_due FROM recurring_items WHERE user_id = ? AND active = 1 AND next_due <= ?");
    $st->execute([$tenantId, $horizon->format('Y-m-d')]);
    $created = 0;
    foreach ($st->fetchAll() as $r) {
        $due = new DateTimeImmutable($r['next_due'], app_timezone());
        $anchor = (int)(new DateTimeImmutable($r['start_date']))->format('j');
        $end = $r['end_date'] ? new DateTimeImmutable($r['end_date'], app_timezone()) : null;
        $active = 1;
        for ($guard = 0; $due <= $horizon && $guard < 60; $guard++) {          // 60 = limite de segurança contra ciclos infinitos
            if ($end && $due > $end) { $active = 0; break; }
            $ins = db()->prepare("INSERT IGNORE INTO financial_documents (user_id, direction, title, counterparty, amount, due_date, status, recurring_id) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)");
            $ins->execute([$tenantId, $r['direction'], $r['title'], $r['counterparty'] ?: '', $r['amount'], $due->format('Y-m-d'), $r['id']]);
            $created += $ins->rowCount();
            $due = recurring_next($due, $r['frequency'], $anchor);
        }
        if ($end && $due > $end) $active = 0;                                 // acabou o período definido
        db()->prepare('UPDATE recurring_items SET next_due = ?, active = ? WHERE id = ? AND user_id = ?')->execute([$due->format('Y-m-d'), $active, $r['id'], $tenantId]);
    }
    return $created;
}
