<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/crypto.php';     // cifra de segredos (TOTP, Google)
require_once __DIR__ . '/totp.php';       // autenticação em dois passos
require_once __DIR__ . '/uploads.php';    // fotos e logos
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/client_ip.php';  // client_ip()
require_once __DIR__ . '/rate_limit.php'; // limite de pedidos (429)

if (session_status() === PHP_SESSION_NONE) {
    // Cookie de sessão mais seguro: o JavaScript não o lê (HttpOnly), não é enviado em pedidos
    // vindos de outros sites (SameSite) e o PHP só aceita ids de sessão que ele próprio criou.
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/* ---------- equipa: módulos que o dono pode dar a um funcionário ---------- */
const TEAM_MODULES = [
    'cashflow' => 'Visão geral, fluxo de caixa e relatórios',
    'accounts' => 'Contas e contas a pagar/receber',
    'clients'  => 'Clientes',
    'stock'    => 'Estoque',
    'calendar' => 'Calendário',
    'time'     => 'Tempo ativo da equipa (ver e corrigir horários)',   // quem tem isto é "gerente"; cada pessoa marca sempre o seu próprio turno
];

function clean_permissions(mixed $value): array
{
    $list = is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
    return array_values(array_intersect(array_keys(TEAM_MODULES), $list));
}

/* Colunas do utilizador lidas da base de dados. SESSION_COLUMNS = o que a sessão precisa (sem a palavra-passe);
   USER_COLUMNS = o mesmo + o hash da palavra-passe (só o login precisa). Uma só lista para todo o sistema. */
const SESSION_COLUMNS = 'id, owner_id, name, email, role, permissions, job_title, department, status, must_change_password, email_verified_at';
const USER_COLUMNS = SESSION_COLUMNS . ', password_hash';
const PASSWORD_MIN_LENGTH = 8;      // tamanho mínimo da palavra-passe (registo, mudança, recuperação, convites)

/** Monta o utilizador da sessão a partir de uma linha da tabela users. */
function session_user(array $row): array
{
    $owner = $row['role'] !== 'employee';
    return [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'role' => $owner ? 'owner' : 'employee',
        // dono dos dados do negócio: o próprio (dono) ou o patrão (funcionário)
        'tenant_id' => $owner ? (int)$row['id'] : (int)$row['owner_id'],
        'permissions' => $owner ? array_keys(TEAM_MODULES) : clean_permissions($row['permissions'] ?? null),
        'job_title' => $row['job_title'] ?? null,
        'department' => $row['department'] ?? null,
        'must_change_password' => !empty($row['must_change_password']),
        'email_verified' => !empty($row['email_verified_at']),
    ];
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_api_request(): bool
{
    return str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/');
}

function require_login(): array
{
    $session = current_user();
    $user = null;
    if ($session) {
        // relê da base em cada pedido: permissões alteradas ou conta desativada contam logo
        $stmt = db()->prepare('SELECT ' . SESSION_COLUMNS . ' FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$session['id']]);
        $row = $stmt->fetch();
        if ($row && ($row['status'] ?? 'active') === 'active') {
            $user = $_SESSION['user'] = session_user($row);
        } else {
            unset($_SESSION['user']);
        }
    }
    // sessão terminada noutro dispositivo (ou em "terminar todas")? Deixa de valer já.
    if ($user && !session_is_valid((int)$user['id'])) {
        unset($_SESSION['user']);
        $user = null;
    }
    if (!$user) {
        if (is_api_request()) {
            json_response(['success' => false, 'error' => 'Sessão necessária.'], 401);
        }
        header('Location: index.php');
        exit;
    }
    // limite de pedidos por utilizador (só a API: as páginas não contam). Passou do máximo: 429 e nada mais é processado.
    if (is_api_request()) {
        rate_limit_enforce('api', (string)$user['id']);
    }
    // palavra-passe provisória: só pode usar a API de autenticação até a trocar
    if ($user['must_change_password'] && is_api_request() && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/auth.php')) {
        json_response(['success' => false, 'error' => 'Altera a tua palavra-passe provisória para continuar.'], 403);
    }
    return $user;
}

function can(array $user, string $module): bool
{
    return in_array($module, $user['permissions'], true);
}

/** Termina com 403 se o utilizador não tiver nenhum dos módulos indicados. */
function require_permission(array $user, string ...$modules): void
{
    foreach ($modules as $module) {
        if (can($user, $module)) return;
    }
    json_response(['success' => false, 'error' => 'Não tens permissão para esta área. Fala com o responsável.'], 403);
}

function require_owner(array $user): void
{
    if ($user['role'] !== 'owner') {
        json_response(['success' => false, 'error' => 'Só o responsável do negócio pode fazer isto.'], 403);
    }
}

/* ---------- limite de tentativas de login ---------- */
const LOGIN_MAX_PER_EMAIL = 8;     // falhas por conta, no período
const LOGIN_MAX_PER_IP = 40;       // falhas por IP (alto, para não bloquear uma rede partilhada, como uma escola)
const LOGIN_WINDOW_MINUTES = 10;

/* client_ip() (o IP do cliente, com suporte opcional a proxies de confiança) está em includes/client_ip.php */

/** True se esta conta ou este IP já falharam demasiadas vezes. Se a tabela não existir, não bloqueia (o login nunca deixa de funcionar). */
function login_throttled(string $email): bool
{
    try {
        $st = db()->prepare('SELECT SUM(email = ?) AS by_email, COUNT(*) AS by_ip FROM login_attempts WHERE ip = ? AND created_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
        $st->execute([$email, client_ip()]);
        $ip = $st->fetch();
        $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MINUTES . ' MINUTE)');
        $st->execute([$email]);
        return (int)$st->fetchColumn() >= LOGIN_MAX_PER_EMAIL || (int)($ip['by_ip'] ?? 0) >= LOGIN_MAX_PER_IP;
    } catch (Throwable $e) {
        return false;
    }
}

function login_failed(string $email): void
{
    try {
        db()->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([mb_substr($email, 0, 190), client_ip()]);
        if (random_int(1, 50) === 1) {                              // limpeza ocasional das linhas antigas
            db()->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) { /* sem tabela: segue sem limite */ }
}

function login_succeeded(string $email): void
{
    try { db()->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]); } catch (Throwable $e) {}
}

/**
 * Erro inesperado: regista os detalhes no log do servidor e devolve uma mensagem genérica.
 * Nunca mostra ao utilizador mensagens internas (erros SQL, caminhos de ficheiros...).
 * Para ver os detalhes no ecrã durante o desenvolvimento, põe APP_DEBUG a true em config/app.php.
 */
function internal_error(Throwable $error): never
{
    error_log('[lumina] ' . get_class($error) . ': ' . $error->getMessage() . ' em ' . basename($error->getFile()) . ':' . $error->getLine());
    $debug = defined('APP_DEBUG') && APP_DEBUG;
    json_response(['success' => false, 'error' => $debug ? $error->getMessage() : 'Não foi possível processar o pedido agora.'], 500);
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');                     // respostas da API nunca entram nos motores de pesquisa
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(): void
{
    $token = $_POST['csrf'] ?? request_json()['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['success' => false, 'error' => 'Token de segurança inválido.'], 419);
    }
}

require_once __DIR__ . '/sessions.php';   // sessões ativas e auditoria

/* ======================= PALAVRA-PASSE E 2.º PASSO (uma só implementação) ======================= */

/** Confirma a palavra-passe atual (ações importantes). Se estiver errada conta como tentativa falhada. */
function confirm_password(array $user, string $password): void
{
    if (login_throttled($user['email'])) {
        json_response(['success' => false, 'error' => 'Demasiadas tentativas. Aguarda ' . LOGIN_WINDOW_MINUTES . ' minutos.'], 429);
    }
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([(int)$user['id']]);
    if (!password_verify($password, (string)$st->fetchColumn())) {
        login_failed($user['email']);
        audit('password_confirm_failed', [], $user);
        json_response(['success' => false, 'error' => 'A palavra-passe atual está incorreta.'], 403);
    }
}

/**
 * Muda a palavra-passe de quem está autenticado. É A ÚNICA implementação (auth.php e security.php chamam-na).
 * Pede a atual, dá um id de sessão novo e (por omissão) termina as sessões abertas noutros dispositivos.
 * Devolve quantas outras sessões foram terminadas.
 */
function change_password_for(array $user, string $current, string $new, bool $logoutOthers = true): int
{
    if (strlen($new) < PASSWORD_MIN_LENGTH) json_response(['success' => false, 'error' => 'A nova palavra-passe precisa de pelo menos ' . PASSWORD_MIN_LENGTH . ' caracteres.'], 400);
    if ($new === $current) json_response(['success' => false, 'error' => 'A nova palavra-passe tem de ser diferente da atual.'], 400);
    confirm_password($user, $current);
    $uid = (int)$user['id'];
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
    $old = session_hash_current();
    session_regenerate_id(true);                                    // id novo: o antigo deixa de servir
    db()->prepare('UPDATE user_sessions SET session_hash = ?, session_token = ? WHERE session_hash = ? AND user_id = ?')->execute([session_hash_current(), session_hash_current(), $old, $uid]);
    $revoked = $logoutOthers ? sessions_revoke_all($uid, true) : 0;
    audit('password_change', ['others_revoked' => $revoked], $user);
    return $revoked;
}

/**
 * Verifica o código do 2.º passo. Devolve null se a pessoa NÃO tem o 2.º passo ativo (não há nada a pedir),
 * true se o código está certo, false se está errado.
 */
function second_factor_check(int $userId, string $code): ?bool
{
    $st = db()->prepare('SELECT totp_secret FROM users WHERE id = ? AND totp_enabled = 1');
    $st->execute([$userId]);
    $secret = decrypt_secret($st->fetchColumn() ?: null);
    return $secret ? totp_verify($secret, $code) : null;
}

/** O que o JavaScript pode saber sobre quem está autenticado (window.GF_USER). Uma só definição para o painel e a Área pessoal. */
function client_user_payload(array $user): array
{
    return ['isOwner' => $user['role'] === 'owner', 'permissions' => $user['permissions'], 'mustChangePassword' => $user['must_change_password'],
            'name' => $user['name'], 'email' => $user['email'], 'emailVerified' => !empty($user['email_verified']),
            'jobTitle' => $user['job_title'], 'department' => $user['department']];
}

