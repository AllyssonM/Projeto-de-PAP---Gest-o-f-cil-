<?php
/* =========================================================================
   CLIENTE DO GOOGLE CALENDAR  (includes/google.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  tudo o que fala com a Google, para a api/google.php ficar simples:
     - OAuth 2.0 com PKCE (o utilizador autoriza no site da Google; nós NUNCA vemos a
       palavra-passe dele).
     - Guarda os tokens CIFRADOS (includes/crypto.php) e renova-os sozinho quando expiram.
     - Pede dados à API oficial (lista de calendários, eventos) com mensagens de erro claras.
     - Sincroniza: traz os eventos do Google para a agenda do sistema e envia os do
       sistema para o Google.
   NÃO SIMULA NADA: se não houver credenciais (config/google.php), google_configured()
   devolve false e a interface mostra "não configurado".
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/http.php';

/** Erro com mensagem para o utilizador. $reconnect = true quando é preciso ligar de novo. */
final class GoogleError extends RuntimeException
{
    public function __construct(string $message, public bool $reconnect = false, int $code = 0) { parent::__construct($message, $code); }
}

function google_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/google.php';
        $local = __DIR__ . '/../config/google.local.php';          // segredos e ajustes desta instalação (ignorado pelo Git; ver README > Segredos)
        if (is_file($local)) $cfg = array_replace($cfg, (array)require $local);
    }
    return $cfg;
}

function google_configured(): bool
{
    $c = google_cfg();
    return $c['client_id'] !== '' && $c['client_secret'] !== '';
}

/** O endereço para onde a Google devolve o utilizador (tem de coincidir com o registado no Google Cloud). */
function google_redirect_uri(): string
{
    $c = google_cfg();
    if ($c['redirect_uri'] !== '') return $c['redirect_uri'];
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['SCRIPT_NAME'] . '?action=callback';
}

function b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }

/** Começa a autorização: devolve o endereço da Google para onde enviar o utilizador. */
function google_auth_url(): string
{
    $c = google_cfg();
    $verifier = b64url(random_bytes(48));                          // PKCE: prova de que quem troca o código é quem o pediu
    $state = bin2hex(random_bytes(24));                            // anti-CSRF do OAuth
    $_SESSION['google_oauth'] = ['state' => $state, 'verifier' => $verifier, 'until' => time() + 600];
    return $c['auth_uri'] . '?' . http_build_query([
        'client_id' => $c['client_id'], 'redirect_uri' => google_redirect_uri(), 'response_type' => 'code', 'scope' => implode(' ', $c['scopes']),
        'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => b64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
    ]);
}

/** Pedido ao servidor de tokens da Google. */
function google_token_request(array $params): array
{
    $c = google_cfg();
    try {
        $r = http_request('POST', $c['token_uri'], ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query($params + ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]), 15);
    } catch (RuntimeException $e) {
        throw new GoogleError('Não foi possível contactar a Google. Verifica a ligação à internet e tenta outra vez.');
    }
    $data = json_decode($r['body'], true) ?: [];
    if ($r['status'] >= 400) {
        if (($data['error'] ?? '') === 'invalid_grant') throw new GoogleError('A autorização da Google expirou ou foi revogada. Liga o Google Calendar outra vez.', true);
        throw new GoogleError('A Google recusou o pedido (' . ($data['error'] ?? $r['status']) . ').');
    }
    return $data;
}

function google_connection(int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM google_connections WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

/** Guarda os tokens (cifrados). O refresh_token só vem na 1.ª autorização: se não vier, mantém-se o anterior. */
function google_store_tokens(int $userId, array $tok, ?array $existing = null): void
{
    $refresh = $tok['refresh_token'] ?? ($existing ? decrypt_secret($existing['refresh_token_enc']) : null);
    $expires = (new DateTime('now', app_timezone()))->modify('+' . max(60, (int)($tok['expires_in'] ?? 3600)) . ' seconds')->format('Y-m-d H:i:s');
    if ($existing) {
        db()->prepare('UPDATE google_connections SET access_token_enc = ?, refresh_token_enc = ?, expires_at = ?, scope = COALESCE(?, scope), last_error = NULL WHERE user_id = ?')
            ->execute([encrypt_secret($tok['access_token']), $refresh ? encrypt_secret($refresh) : null, $expires, $tok['scope'] ?? null, $userId]);
    } else {
        db()->prepare('INSERT INTO google_connections (user_id, access_token_enc, refresh_token_enc, expires_at, scope) VALUES (?,?,?,?,?)')
            ->execute([$userId, encrypt_secret($tok['access_token']), $refresh ? encrypt_secret($refresh) : null, $expires, $tok['scope'] ?? null]);
    }
}

/** Um access token válido (renova com o refresh token se estiver a expirar). */
function google_access_token(int $userId, bool $force = false): string
{
    $conn = google_connection($userId);
    if (!$conn) throw new GoogleError('O Google Calendar não está ligado.', true);
    $expiresTs = $conn['expires_at'] ? (new DateTime($conn['expires_at'], app_timezone()))->getTimestamp() : 0;
    $access = decrypt_secret($conn['access_token_enc']);
    if (!$force && $access && $expiresTs > time() + 60) return $access;          // $force: a Google recusou este token, renova já
    $refresh = decrypt_secret($conn['refresh_token_enc']);
    if (!$refresh) throw new GoogleError('Falta a autorização permanente da Google. Liga o Google Calendar outra vez.', true);
    try {
        $tok = google_token_request(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    } catch (GoogleError $e) {
        db()->prepare('UPDATE google_connections SET last_error = ? WHERE user_id = ?')->execute([mb_substr($e->getMessage(), 0, 255), $userId]);
        throw $e;
    }
    google_store_tokens($userId, $tok, $conn);
    return $tok['access_token'];
}

/** Chamada à API do Calendar. Devolve o JSON (ou [] se vazio). */
function google_api(int $userId, string $method, string $path, array $query = [], ?array $body = null): array
{
    $c = google_cfg();
    $url = $c['api_base'] . $path . ($query ? '?' . http_build_query($query) : '');
    $send = function (string $token) use ($method, $url, $body): array {
        $headers = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        try { return http_request($method, $url, $headers, $body !== null ? json_encode($body) : null, 20); }
        catch (RuntimeException $e) { throw new GoogleError('Não foi possível contactar a Google. Verifica a ligação à internet e tenta outra vez.'); }
    };
    $r = $send(google_access_token($userId));
    // O token pode ter sido invalidado pela Google antes de expirar: renova UMA vez e repete. Só se isso também falhar é que se pede nova ligação.
    if ($r['status'] === 401) $r = $send(google_access_token($userId, true));
    if ($r['status'] === 401) throw new GoogleError('A Google não aceitou a ligação. Liga o Google Calendar outra vez.', true);
    if ($r['status'] === 403) throw new GoogleError('Sem permissão para esta operação no Google Calendar (ou limite de pedidos atingido).');
    if ($r['status'] === 404 || $r['status'] === 410) throw new GoogleError('Esse evento já não existe no Google Calendar.', false, 404);
    if ($r['status'] === 429 || $r['status'] >= 500) throw new GoogleError('A Google está indisponível ou com demasiados pedidos. Tenta daqui a pouco.');
    if ($r['status'] >= 400) throw new GoogleError('A Google recusou o pedido (' . $r['status'] . ').');
    return json_decode($r['body'], true) ?: [];
}

/** Lista de calendários da conta Google (para escolher quais sincronizar). */
function google_list_calendars(int $userId): array
{
    $d = google_api($userId, 'GET', '/users/me/calendarList', ['minAccessRole' => 'reader', 'maxResults' => 100]);
    return array_map(fn($c) => ['id' => $c['id'], 'name' => $c['summaryOverride'] ?? $c['summary'] ?? $c['id'], 'primary' => !empty($c['primary']),
        'can_write' => in_array($c['accessRole'] ?? '', ['owner', 'writer'], true)], $d['items'] ?? []);
}

/** Evento do Google -> linha da agenda local (null se não for possível representar). */
function google_event_to_local(array $ev): ?array
{
    if (($ev['status'] ?? '') === 'cancelled' || empty($ev['id'])) return null;
    $tz = app_timezone();
    if (isset($ev['start']['dateTime'])) {
        $s = (new DateTime($ev['start']['dateTime']))->setTimezone($tz);
        $e = isset($ev['end']['dateTime']) ? (new DateTime($ev['end']['dateTime']))->setTimezone($tz) : (clone $s)->modify('+1 hour');
        if ($e->format('Y-m-d') !== $s->format('Y-m-d')) $e = new DateTime($s->format('Y-m-d') . ' 23:59:00', $tz);        // a agenda é por dia: corta ao fim do dia
        return ['title' => mb_substr((string)($ev['summary'] ?? '(sem título)'), 0, 150), 'description' => isset($ev['description']) ? mb_substr((string)$ev['description'], 0, 2000) : null,
                'event_date' => $s->format('Y-m-d'), 'start_time' => $s->format('H:i:s'), 'end_time' => $e->format('H:i:s'), 'location' => isset($ev['location']) ? mb_substr((string)$ev['location'], 0, 200) : null];
    }
    if (isset($ev['start']['date'])) {                              // evento de dia inteiro
        return ['title' => mb_substr((string)($ev['summary'] ?? '(sem título)'), 0, 150), 'description' => isset($ev['description']) ? mb_substr((string)$ev['description'], 0, 2000) : null,
                'event_date' => $ev['start']['date'], 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'location' => isset($ev['location']) ? mb_substr((string)$ev['location'], 0, 200) : null];
    }
    return null;
}

/** Traz os eventos dos calendários escolhidos (de -30 a +180 dias) para a agenda local. Devolve [novos, atualizados, removidos]. */
function google_sync_in(int $userId): array
{
    $conn = google_connection($userId);
    $ids = $conn && $conn['calendars'] ? (json_decode($conn['calendars'], true) ?: []) : [];
    if (!$ids) throw new GoogleError('Escolhe pelo menos um calendário para sincronizar.');
    $tz = app_timezone();
    $from = (new DateTime('today', $tz))->modify('-30 days'); $to = (new DateTime('today', $tz))->modify('+180 days');
    $seen = [];
    $new = $upd = 0;
    foreach ($ids as $calId) {
        $page = null; $pages = 0;
        do {
            $d = google_api($userId, 'GET', '/calendars/' . rawurlencode($calId) . '/events', array_filter([
                'timeMin' => $from->format('c'), 'timeMax' => $to->format('c'), 'singleEvents' => 'true', 'orderBy' => 'startTime', 'maxResults' => 250, 'pageToken' => $page]));
            foreach ($d['items'] ?? [] as $ev) {
                $local = google_event_to_local($ev);
                if (!$local) continue;
                $seen[$calId . '|' . $ev['id']] = true;
                $st = db()->prepare("SELECT id FROM calendar_events WHERE user_id = ? AND google_event_id = ? AND google_calendar_id = ?");
                $st->execute([$userId, $ev['id'], $calId]);
                if ($row = $st->fetch()) {
                    db()->prepare('UPDATE calendar_events SET title=?, description=?, event_date=?, start_time=?, end_time=?, location=? WHERE id = ?')
                        ->execute([$local['title'], $local['description'], $local['event_date'], $local['start_time'], $local['end_time'], $local['location'], $row['id']]); $upd++;
                } else {
                    db()->prepare("INSERT INTO calendar_events (user_id, title, description, event_date, start_time, end_time, location, source, google_event_id, google_calendar_id) VALUES (?,?,?,?,?,?,?, 'google', ?, ?)")
                        ->execute([$userId, $local['title'], $local['description'], $local['event_date'], $local['start_time'], $local['end_time'], $local['location'], $ev['id'], $calId]); $new++;
                }
            }
            $page = $d['nextPageToken'] ?? null;
        } while ($page && ++$pages < 4);
    }
    // eventos que vieram do Google e já não existem lá (ou cujo calendário deixou de estar escolhido) saem da agenda
    $removed = 0;
    $st = db()->prepare("SELECT id, google_event_id, google_calendar_id FROM calendar_events WHERE user_id = ? AND source = 'google' AND event_date BETWEEN ? AND ?");
    $st->execute([$userId, $from->format('Y-m-d'), $to->format('Y-m-d')]);
    foreach ($st->fetchAll() as $row) {
        if (!isset($seen[$row['google_calendar_id'] . '|' . $row['google_event_id']])) { db()->prepare('DELETE FROM calendar_events WHERE id = ?')->execute([$row['id']]); $removed++; }
    }
    db()->prepare('UPDATE google_connections SET last_sync_at = NOW(), last_error = NULL WHERE user_id = ?')->execute([$userId]);
    return [$new, $upd, $removed];
}

/** Envia um evento local para o Google (calendário principal ou o primeiro escolhido com escrita). Devolve o id do evento Google. */
function google_push_event(int $userId, array $local): string
{
    $conn = google_connection($userId);
    $ids = $conn && $conn['calendars'] ? (json_decode($conn['calendars'], true) ?: []) : [];
    $calId = $ids[0] ?? 'primary';
    $tz = app_timezone()->getName();
    $body = ['summary' => $local['title'], 'description' => $local['description'] ?? null, 'location' => $local['location'] ?? null,
             'start' => ['dateTime' => $local['event_date'] . 'T' . substr($local['start_time'], 0, 5) . ':00', 'timeZone' => $tz],
             'end'   => ['dateTime' => $local['event_date'] . 'T' . substr($local['end_time'], 0, 5) . ':00', 'timeZone' => $tz]];
    $d = google_api($userId, 'POST', '/calendars/' . rawurlencode($calId) . '/events', [], array_filter($body, fn($v) => $v !== null));
    db()->prepare("UPDATE calendar_events SET google_event_id = ?, google_calendar_id = ? WHERE id = ? AND user_id = ?")->execute([$d['id'], $calId, $local['id'], $userId]);
    return (string)$d['id'];
}

/** Apaga um evento no Google. Um evento que já não existe lá conta como apagado. */
function google_delete_remote(int $userId, string $calId, string $eventId): void
{
    try { google_api($userId, 'DELETE', '/calendars/' . rawurlencode($calId) . '/events/' . rawurlencode($eventId)); }
    catch (GoogleError $e) { if ($e->getCode() !== 404) throw $e; }
}

/**
 * Desliga o Google Calendar de um utilizador: revoga o acesso na Google (se falhar, o acesso local acaba na mesma),
 * apaga a ligação e os eventos vindos do Google, e solta os eventos locais que tinham sido enviados.
 * Devolve true se havia uma ligação. (Usada ao desligar e ao eliminar a conta.)
 */
function google_disconnect(int $userId): bool
{
    $conn = google_connection($userId);
    if (!$conn) return false;
    $tok = decrypt_secret($conn['refresh_token_enc']) ?: decrypt_secret($conn['access_token_enc']);
    if ($tok && google_configured()) {
        try { http_request('POST', google_cfg()['revoke_uri'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['token' => $tok]), 8); } catch (Throwable $e) {}
    }
    db()->prepare('DELETE FROM google_connections WHERE user_id = ?')->execute([$userId]);
    db()->prepare("DELETE FROM calendar_events WHERE user_id = ? AND source = 'google'")->execute([$userId]);
    db()->prepare('UPDATE calendar_events SET google_event_id = NULL, google_calendar_id = NULL WHERE user_id = ?')->execute([$userId]);
    return true;
}

