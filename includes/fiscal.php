<?php
/* =========================================================================
   CALENDÁRIO FISCAL  (includes/fiscal.php)
   -------------------------------------------------------------------------
   fiscal_deadlines($respostas, $desde, $dias) devolve as próximas datas fiscais que se aplicam a
   ESTE negócio, a partir das respostas do questionário (regime de IVA, Segurança Social).
   As regras estão em config/fiscal.php. São datas INDICATIVAS.
   ========================================================================= */
declare(strict_types=1);

/** @return array{deadlines: list<array>, disclaimer: string} */
function fiscal_deadlines(array $answers, DateTimeImmutable $from, int $days = 75): array
{
    $cfg = require __DIR__ . '/../config/fiscal.php';
    $to = $from->modify("+$days days");
    $out = [];
    for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
        foreach ($cfg['rules'] as $r) {
            foreach ($r['when'] as $key => $allowed) {                          // a regra só vale para quem respondeu assim
                if (!in_array($answers[$key] ?? '', $allowed, true)) continue 2;
            }
            foreach ($r['months'] as $m) {
                $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $y, $m), $from->getTimezone());
                $date = $r['day'] === 'last' ? $first->modify('last day of this month') : $first->setDate($y, $m, min((int)$r['day'], (int)$first->format('t')));
                if ($date < $from->setTime(0, 0) || $date > $to) continue;
                $out[] = ['date' => $date->format('Y-m-d'), 'title' => $r['title'], 'detail' => $r['detail'], 'kind' => $r['kind'],
                          'days' => (int)$from->setTime(0, 0)->diff($date)->format('%r%a')];
            }
        }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['title']] <=> [$b['date'], $b['title']]);
    return ['deadlines' => $out, 'disclaimer' => $cfg['disclaimer']];
}
