<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/* ---------- validação ---------- */
function calendar_valid_date(string $value): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $value, app_timezone());
    return $d !== false && $d->format('Y-m-d') === $value;
}

function calendar_normalize_time(string $value): ?string
{
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $value)) {
        return null;
    }
    return substr($value, 0, 5) . ':00';
}

/** Devolve [dados limpos, erro]. */
function calendar_validate(array $data): array
{
    $title = trim((string)($data['title'] ?? ''));
    $date = trim((string)($data['event_date'] ?? ''));
    $start = calendar_normalize_time(trim((string)($data['start_time'] ?? '')));
    $end = calendar_normalize_time(trim((string)($data['end_time'] ?? '')));
    $location = trim((string)($data['location'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));

    if ($title === '' || mb_strlen($title) > 150) return [null, 'Indica um título (até 150 caracteres).'];
    if (!calendar_valid_date($date)) return [null, 'Data inválida.'];
    if ($start === null || $end === null) return [null, 'Horas inválidas.'];
    if ($end <= $start) return [null, 'A hora de fim tem de ser depois da hora de início.'];
    if (mb_strlen($location) > 200) return [null, 'O local pode ter no máximo 200 caracteres.'];
    if (mb_strlen($description) > 2000) return [null, 'A descrição pode ter no máximo 2000 caracteres.'];

    return [[
        'title' => $title,
        'event_date' => $date,
        'start_time' => $start,
        'end_time' => $end,
        'location' => $location !== '' ? $location : null,
        'description' => $description !== '' ? $description : null,
    ], null];
}

/* ---------- consultas ---------- */
function calendar_list_month(int $userId, string $month): array
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        $month = (new DateTime('now', app_timezone()))->format('Y-m');
    }
    $first = new DateTime($month . '-01', app_timezone());
    $last = (clone $first)->modify('last day of this month');
    $stmt = db()->prepare('SELECT id, title, description, event_date, start_time, end_time, location, source, google_event_id
        FROM calendar_events WHERE user_id = ? AND event_date BETWEEN ? AND ?
        ORDER BY event_date, start_time, id');
    $stmt->execute([$userId, $first->format('Y-m-d'), $last->format('Y-m-d')]);
    return $stmt->fetchAll();
}

function calendar_create(int $userId, array $event): int
{
    $stmt = db()->prepare('INSERT INTO calendar_events (user_id, title, description, event_date, start_time, end_time, location)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $event['title'], $event['description'], $event['event_date'],
        $event['start_time'], $event['end_time'], $event['location']]);
    return (int)db()->lastInsertId();
}

function calendar_delete(int $userId, int $id): int
{
    $stmt = db()->prepare('DELETE FROM calendar_events WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, $userId]);
    return $stmt->rowCount();
}

/**
 * Reuniões de HOJE que ainda não começaram, calculadas no fuso do programa.
 * Envia também a hora do servidor (em ms) para o cronómetro não depender
 * do relógio do computador.
 */
function calendar_upcoming(int $userId): array
{
    $zone = app_timezone();
    $now = new DateTime('now', $zone);
    $stmt = db()->prepare('SELECT id, title, description, event_date, start_time, end_time, location
        FROM calendar_events WHERE user_id = ? AND event_date = ? AND start_time > ?
        ORDER BY start_time, id');
    $stmt->execute([$userId, $now->format('Y-m-d'), $now->format('H:i:s')]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $start = new DateTime($row['event_date'] . ' ' . $row['start_time'], $zone);
        $row['starts_at_ms'] = (int)$start->format('U') * 1000;
        $items[] = $row;
    }
    return [
        'server_now_ms' => (int)round((float)$now->format('U.u') * 1000),
        'today' => $now->format('Y-m-d'),
        'timezone' => $zone->getName(),
        'dismissed' => !empty($_SESSION['reminder_dismissed']),
        'items' => $items,
    ];
}
?>
