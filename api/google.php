<?php
/* =========================================================================
   API DA LIGAÇÃO AO GOOGLE CALENDAR  (api/google.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  liga/desliga a conta Google de CADA utilizador (a agenda é pessoal) e sincroniza.
     GET  ?action=status                 estado: configurado? ligado? calendários, modo, última sincronização
     POST action=connect                 devolve o endereço da Google para autorizar (OAuth + PKCE)
     GET  ?action=callback&code&state    a Google devolve o utilizador aqui; troca o código por tokens
     GET  ?action=calendars              lista os calendários da conta Google
     POST action=set_calendars {ids[]}   escolhe quais sincronizar
     POST action=set_mode {mode}         "manual" ou "auto" (auto = sincroniza ao abrir o calendário, no máx. a cada 5 min)
     POST action=sync                    traz os eventos do Google para a agenda
     POST action=push {event_id}         envia um evento do sistema para o Google
     POST action=disconnect              revoga o acesso e apaga os tokens e os eventos vindos do Google
   REGRAS: só com sessão; CSRF nas ações; os tokens ficam CIFRADOS e nunca vão para o navegador;
           o "state" do OAuth e o PKCE protegem o regresso da Google; sem credenciais em
           config/google.php devolve "não configurado" (nunca simula).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google.php';

$user = require_login();
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();
$action = (string)($_GET['action'] ?? $data['action'] ?? 'status');

/** Os eventos do Google só entram na agenda de quem tem acesso ao Calendário. */
require_permission($user, 'calendar');

function not_configured(): never
{
    json_response(['success' => false, 'configured' => false,
        'error' => 'A ligação ao Google Calendar ainda não está configurada neste sistema. O administrador precisa de preencher config/google.php (ver instruções nesse ficheiro).'], 503);
}

try {
    /* ------------------------------ ESTADO ------------------------------ */
    if ($action === 'status') {
        $conn = google_connection($uid);
        json_response(['success' => true, 'configured' => google_configured(), 'connected' => (bool)$conn && !empty($conn['refresh_token_enc']),
            'sync_mode' => $conn['sync_mode'] ?? 'manual', 'calendars' => $conn && $conn['calendars'] ? (json_decode($conn['calendars'], true) ?: []) : [],
            'now' => (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'), 'last_sync_at' => $conn['last_sync_at'] ?? null, 'last_error' => $conn['last_error'] ?? null, 'redirect_uri' => google_redirect_uri(),
            'scopes' => ['Ver a lista dos teus calendários (para escolheres quais sincronizar)', 'Ler, criar e apagar eventos nos calendários que escolheres'],
            'privacy' => 'Nunca vemos nem guardamos a tua palavra-passe da Google. Podes revogar o acesso quando quiseres.']);
    }

    /* ------------------------------ REGRESSO DA GOOGLE ------------------------------ */
    if ($action === 'callback') {
        if (!google_configured()) not_configured();
        // volta ao painel com o resultado. (Nota: tem de ser uma função com exit; header() não devolve valor, por isso "header() && exit" nunca saía.)
        $back = function (string $r): never { header('Location: ../dashboard.php?google=' . $r . '#calendar'); exit; };
        $pending = $_SESSION['google_oauth'] ?? null;
        unset($_SESSION['google_oauth']);                                           // uso único
        if (isset($_GET['error'])) $back('denied');                                // o utilizador recusou
        if (!$pending || $pending['until'] < time() || !hash_equals($pending['state'], (string)($_GET['state'] ?? ''))) $back('state');
        $code = (string)($_GET['code'] ?? '');
        if ($code === '') $back('error');
        try {
            $tok = google_token_request(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => google_redirect_uri(), 'code_verifier' => $pending['verifier']]);
        } catch (GoogleError $e) { $back('error'); }
        $existing = google_connection($uid);
        google_store_tokens($uid, $tok, $existing);
        $conn = google_connection($uid);
        if (!$conn['calendars']) {                                                  // por omissão: o calendário principal
            try {
                $cals = google_list_calendars($uid);
                $primary = array_values(array_filter($cals, fn($c) => $c['primary']))[0] ?? ($cals[0] ?? null);
                db()->prepare('UPDATE google_connections SET calendars = ?, google_email = ? WHERE user_id = ?')->execute([json_encode($primary ? [$primary['id']] : []), $primary['id'] ?? null, $uid]);
            } catch (GoogleError $e) { /* escolhe-se depois */ }
        }
        audit('google_connected', [], $user);
        $back('connected');
    }

    if ($method === 'GET' && $action === 'calendars') {
        if (!google_configured()) not_configured();
        $conn = google_connection($uid);
        $selected = $conn && $conn['calendars'] ? (json_decode($conn['calendars'], true) ?: []) : [];
        json_response(['success' => true, 'items' => array_map(fn($c) => $c + ['selected' => in_array($c['id'], $selected, true)], google_list_calendars($uid))]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();

    if ($action === 'connect') {
        if (!google_configured()) not_configured();
        json_response(['success' => true, 'url' => google_auth_url()]);
    }

    if (!google_connection($uid) && $action !== 'disconnect') json_response(['success' => false, 'error' => 'O Google Calendar não está ligado.', 'reconnect' => true], 409);

    if ($action === 'set_calendars') {
        $ids = array_values(array_unique(array_filter(array_map('strval', (array)($data['ids'] ?? [])), fn($v) => $v !== '' && strlen($v) <= 190)));
        if (!$ids) json_response(['success' => false, 'error' => 'Escolhe pelo menos um calendário.'], 400);
        $valid = array_column(google_list_calendars($uid), 'id');                  // só calendários que existem mesmo nesta conta
        $ids = array_values(array_intersect($ids, $valid));
        if (!$ids) json_response(['success' => false, 'error' => 'Esses calendários não existem nesta conta Google.'], 400);
        db()->prepare('UPDATE google_connections SET calendars = ? WHERE user_id = ?')->execute([json_encode($ids), $uid]);
        json_response(['success' => true, 'calendars' => $ids]);
    }

    if ($action === 'set_mode') {
        $mode = (string)($data['mode'] ?? '');
        if (!in_array($mode, ['manual', 'auto'], true)) json_response(['success' => false, 'error' => 'Modo inválido.'], 400);
        db()->prepare('UPDATE google_connections SET sync_mode = ? WHERE user_id = ?')->execute([$mode, $uid]);
        json_response(['success' => true, 'sync_mode' => $mode]);
    }

    if ($action === 'sync') {
        [$new, $upd, $removed] = google_sync_in($uid);
        json_response(['success' => true, 'new' => $new, 'updated' => $upd, 'removed' => $removed, 'last_sync_at' => (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s')]);
    }

    if ($action === 'push') {
        $st = db()->prepare('SELECT id, title, description, event_date, start_time, end_time, location, google_event_id FROM calendar_events WHERE id = ? AND user_id = ?');
        $st->execute([(int)($data['event_id'] ?? 0), $uid]);
        $ev = $st->fetch();
        if (!$ev) json_response(['success' => false, 'error' => 'Evento não encontrado.'], 404);
        if ($ev['google_event_id']) json_response(['success' => false, 'error' => 'Este evento já está no Google Calendar.'], 409);
        $gid = google_push_event($uid, $ev);
        json_response(['success' => true, 'google_event_id' => $gid]);
    }

    if ($action === 'disconnect') {
        if (google_disconnect($uid)) audit('google_disconnected', [], $user);
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (GoogleError $e) {
    if ($e->reconnect && !empty($uid) && $action !== 'status') {
        try { db()->prepare('UPDATE google_connections SET last_error = ? WHERE user_id = ?')->execute([mb_substr($e->getMessage(), 0, 255), $uid]); } catch (Throwable $x) {}
    }
    json_response(['success' => false, 'error' => $e->getMessage(), 'reconnect' => $e->reconnect], $e->reconnect ? 401 : 502);
} catch (Throwable $error) {
    internal_error($error);
}
