<?php
/* =========================================================================
   API DA META ADS  (api/meta.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  liga/desliga a conta de anúncios da Meta do NEGÓCIO e lê campanhas, conjuntos, anúncios e métricas.
     GET  ?action=status                 configurado? ligado? conta escolhida, período, última sincronização, validade
     POST action=connect                 devolve o endereço da Meta para autorizar (OAuth)
     GET  ?action=callback&code&state    a Meta devolve a pessoa aqui; troca o código por um token de longa duração
     GET  ?action=accounts               contas de anúncios a que a pessoa tem acesso
     POST action=select_account {id}     escolhe a conta de anúncios
     POST action=set_period {preset}     last_7d | last_30d | last_90d
     POST action=sync                    pede os dados à Meta e guarda a última leitura
     GET  ?action=data                   a última leitura guardada (abre de imediato)
     POST action=disconnect              revoga o acesso na Meta e apaga o token e os dados guardados
   REGRAS: só o dono do negócio; CSRF nas ações; o token fica CIFRADO e nunca vai para o navegador;
           o "state" do OAuth protege o regresso; sem credenciais (config/meta.php) diz "não configurado".
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/meta_ads.php';

$user = require_login();
require_owner($user);
$uid = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];
$data = request_json();
$action = (string)($_GET['action'] ?? $data['action'] ?? 'status');

function meta_not_configured(): never
{
    json_response(['success' => false, 'configured' => false,
        'error' => 'A ligação à Meta Ads ainda não está configurada neste sistema. O administrador precisa de preencher config/meta.php (ID e chave secreta da app Meta).'], 503);
}

/** Dias que faltam até o token expirar (null = sem data). */
function meta_days_left(?string $expires): ?int
{
    if (!$expires) return null;
    return (int)floor(((new DateTime($expires, app_timezone()))->getTimestamp() - time()) / 86400);
}

try {
    if ($action === 'status') {
        $conn = meta_connection($uid);
        $connected = $conn && !empty($conn['access_token_enc']);
        $days = $connected ? meta_days_left($conn['expires_at']) : null;
        json_response(['success' => true, 'configured' => meta_configured(), 'connected' => $connected,
            'expired' => $connected && $days !== null && $days < 0, 'days_left' => $days,
            'account' => $connected && $conn['ad_account_id'] ? ['id' => $conn['ad_account_id'], 'name' => $conn['ad_account_name'], 'currency' => $conn['currency']] : null,
            'fb_user' => $connected ? $conn['fb_user_name'] : null, 'date_preset' => $conn['date_preset'] ?? 'last_30d',
            'last_sync_at' => $conn['last_sync_at'] ?? null, 'last_error' => $conn['last_error'] ?? null,
            'now' => (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'), 'redirect_uri' => meta_redirect_uri(),
            'scopes' => ['Ler as tuas contas de anúncios, campanhas, conjuntos de anúncios e anúncios (ads_read)', 'Ler as métricas: investimento, impressões, cliques, alcance, conversões e ROAS'],
            'privacy' => 'O Lumina só lê dados: não cria, edita nem pausa anúncios, e nunca vê a tua palavra-passe do Facebook. Podes revogar o acesso quando quiseres.']);
    }

    /* ------------------------------ REGRESSO DA META ------------------------------ */
    if ($action === 'callback') {
        if (!meta_configured()) meta_not_configured();
        $back = function (string $r): never { header('Location: ../dashboard.php?meta=' . $r . '#meta'); exit; };
        $pending = $_SESSION['meta_oauth'] ?? null;
        unset($_SESSION['meta_oauth']);                                            // uso único
        if (isset($_GET['error']) || isset($_GET['error_reason'])) $back('denied');  // a pessoa recusou
        if (!$pending || $pending['until'] < time() || !hash_equals($pending['state'], (string)($_GET['state'] ?? ''))) $back('state');
        $code = (string)($_GET['code'] ?? '');
        if ($code === '') $back('error');
        try {
            $tok = meta_exchange_code($code);
            $me = meta_graph('me', ['fields' => 'id,name'], $tok['access_token']);
            meta_store_connection($uid, $tok, $me);
        } catch (MetaError $e) { $back($e->kind === 'permission' ? 'permission' : 'error'); }
        // por omissão: a primeira conta de anúncios (a pessoa pode mudar)
        try {
            $accs = meta_list_ad_accounts($uid);
            if (count($accs) >= 1) {
                $a = $accs[0];
                db()->prepare('UPDATE meta_connections SET ad_account_id = ?, ad_account_name = ?, currency = ? WHERE user_id = ?')->execute([$a['id'], mb_substr($a['name'], 0, 190), $a['currency'], $uid]);
            }
        } catch (MetaError $e) { /* escolhe-se depois */ }
        audit('meta_connected', [], $user);
        $back('connected');
    }

    if ($method === 'GET' && $action === 'accounts') {
        if (!meta_configured()) meta_not_configured();
        $conn = meta_connection($uid);
        $items = meta_list_ad_accounts($uid);
        json_response(['success' => true, 'items' => array_map(fn($a) => $a + ['selected' => $conn && $conn['ad_account_id'] === $a['id']], $items)]);
    }

    if ($method === 'GET' && $action === 'data') {
        $conn = meta_connection($uid);
        if (!$conn || empty($conn['access_token_enc'])) json_response(['success' => false, 'error' => 'A Meta Ads não está ligada.', 'reconnect' => true], 409);
        $snap = $conn['ad_account_id'] ? meta_load_snapshot($uid, $conn['ad_account_id'], $conn['date_preset']) : null;
        json_response(['success' => true, 'data' => $snap]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();

    if ($action === 'connect') {
        if (!meta_configured()) meta_not_configured();
        json_response(['success' => true, 'url' => meta_auth_url()]);
    }

    if (!meta_connection($uid) && $action !== 'disconnect') json_response(['success' => false, 'error' => 'A Meta Ads não está ligada.', 'reconnect' => true], 409);

    if ($action === 'select_account') {
        $id = preg_replace('/^act_/', '', trim((string)($data['id'] ?? '')));
        $acc = null;
        foreach (meta_list_ad_accounts($uid) as $a) { if ($a['id'] === $id) { $acc = $a; break; } }      // só contas que existem mesmo nesta ligação
        if (!$acc) json_response(['success' => false, 'error' => 'Essa conta de anúncios não está disponível nesta ligação.'], 400);
        db()->prepare('UPDATE meta_connections SET ad_account_id = ?, ad_account_name = ?, currency = ?, last_error = NULL WHERE user_id = ?')->execute([$acc['id'], mb_substr($acc['name'], 0, 190), $acc['currency'], $uid]);
        json_response(['success' => true, 'account' => ['id' => $acc['id'], 'name' => $acc['name'], 'currency' => $acc['currency']]]);
    }

    if ($action === 'set_period') {
        $p = (string)($data['preset'] ?? '');
        if (!in_array($p, META_PRESETS, true)) json_response(['success' => false, 'error' => 'Período inválido.'], 400);
        db()->prepare('UPDATE meta_connections SET date_preset = ? WHERE user_id = ?')->execute([$p, $uid]);
        $conn = meta_connection($uid);
        $snap = $conn['ad_account_id'] ? meta_load_snapshot($uid, $conn['ad_account_id'], $p) : null;
        json_response(['success' => true, 'date_preset' => $p, 'data' => $snap]);
    }

    if ($action === 'sync') {
        $conn = meta_connection($uid);
        if (!$conn['ad_account_id']) json_response(['success' => false, 'error' => 'Escolhe primeiro uma conta de anúncios.'], 400);
        $overview = meta_fetch_overview($uid, $conn['ad_account_id'], (string)$conn['currency'], (string)$conn['date_preset']);
        meta_save_snapshot($uid, $overview);
        db()->prepare('UPDATE meta_connections SET last_sync_at = ?, last_error = NULL WHERE user_id = ?')->execute([$overview['fetched_at'], $uid]);
        json_response(['success' => true, 'data' => $overview, 'last_sync_at' => $overview['fetched_at']]);
    }

    if ($action === 'disconnect') {
        if (meta_disconnect($uid)) audit('meta_disconnected', [], $user);
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (MetaError $e) {
    if (!empty($uid) && $action !== 'status') {
        try { db()->prepare('UPDATE meta_connections SET last_error = ? WHERE user_id = ?')->execute([mb_substr($e->getMessage(), 0, 255), $uid]); } catch (Throwable $x) {}
    }
    json_response(['success' => false, 'error' => $e->getMessage(), 'reconnect' => $e->reconnect, 'kind' => $e->kind], $e->reconnect ? 409 : ($e->kind === 'permission' ? 403 : ($e->kind === 'rate_limit' ? 429 : 502)));
} catch (Throwable $error) {
    internal_error($error);
}
