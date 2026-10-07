<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/calendar.php';
require_once __DIR__ . '/../includes/google.php';

$user = require_login();
require_permission($user, 'calendar');
$userId = (int)$user['id']; // a agenda é pessoal: cada pessoa (dono ou funcionário) tem a sua
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (isset($_GET['upcoming'])) {
            json_response(['success' => true] + calendar_upcoming($userId));
        }
        json_response(['success' => true, 'items' => calendar_list_month($userId, (string)($_GET['month'] ?? ''))]);
    }

    check_csrf();

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) json_response(['success' => false, 'error' => 'Evento inválido.'], 400);
        // Evento ligado ao Google Calendar: apaga-se também lá (se falhar, avisa-se, mas o evento local sai na mesma)
        $warning = null;
        $st = db()->prepare('SELECT google_event_id, google_calendar_id FROM calendar_events WHERE id = ? AND user_id = ?');
        $st->execute([$id, $userId]);
        if (($g = $st->fetch()) && $g['google_event_id'] && google_connection($userId)) {
            try { google_delete_remote($userId, (string)$g['google_calendar_id'], (string)$g['google_event_id']); }
            catch (GoogleError $e) { $warning = 'O evento foi apagado aqui, mas não no Google Calendar: ' . $e->getMessage(); }
        }
        json_response(['success' => true, 'deleted' => calendar_delete($userId, $id), 'warning' => $warning]);
    }

    if ($method === 'POST') {
        // o aviso de reunião foi fechado: só volta numa nova sessão
        if (isset($_GET['dismiss'])) {
            $_SESSION['reminder_dismissed'] = true;
            json_response(['success' => true]);
        }
        [$event, $error] = calendar_validate(request_json());
        if ($error) json_response(['success' => false, 'error' => $error], 400);
        $event['id'] = calendar_create($userId, $event);
        // "Enviar também para o Google Calendar" (só se a pessoa marcou e a conta está ligada)
        $warning = null;
        if (!empty(request_json()['send_to_google'])) {
            try { $event['google_event_id'] = google_push_event($userId, $event); }
            catch (GoogleError $e) { $warning = 'O evento foi guardado aqui, mas não foi enviado ao Google Calendar: ' . $e->getMessage(); }
        }
        json_response(['success' => true, 'item' => $event, 'warning' => $warning], 201);
    }

    json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
} catch (PDOException $error) {
    if (str_contains($error->getMessage(), 'calendar_events')) {
        json_response(['success' => false, 'error' => 'Falta a tabela do calendário. Importa database/migracao_calendario.sql ou corre instalar_base_dados.bat.'], 500);
    }
    internal_error($error);
} catch (Throwable $error) {
    internal_error($error);
}
?>
