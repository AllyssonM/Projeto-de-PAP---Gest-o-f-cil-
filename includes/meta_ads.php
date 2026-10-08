<?php
/* =========================================================================
   INTEGRAÇÃO COM A META ADS  (includes/meta_ads.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  liga o negócio à conta de anúncios da Meta através da Graph API OFICIAL (OAuth 2.0)
               e lê campanhas, conjuntos de anúncios, anúncios e métricas. SÓ LEITURA (permissão ads_read).
   SEGURANÇA:  o token fica CIFRADO na base de dados (crypto.php) e nunca vai para o navegador; o "state"
               do OAuth (anti-CSRF) fica na sessão e é de uso único; cada pedido à Meta leva o
               "appsecret_proof" (HMAC do token com o segredo da app), como a Meta recomenda.
   NÃO SIMULA:  sem credenciais em config/meta.php diz "não configurado".
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/http.php';

/** Erro da Meta com mensagem pronta para a pessoa. $reconnect = é preciso ligar outra vez. */
class MetaError extends RuntimeException
{
    public function __construct(string $message, public bool $reconnect = false, public string $kind = 'error', int $code = 0) { parent::__construct($message, $code); }
}

const META_PRESETS = ['last_7d', 'last_30d', 'last_90d'];

function meta_cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config/meta.php';
        $local = __DIR__ . '/../config/meta.local.php';             // segredos e ajustes desta instalação (ignorado pelo Git; ver README > Segredos)
        if (is_file($local)) $cfg = array_replace($cfg, (array)require $local);
    }
    return $cfg;
}

function meta_configured(): bool { $c = meta_cfg(); return $c['app_id'] !== '' && $c['app_secret'] !== ''; }

function meta_redirect_uri(): string
{
    $c = meta_cfg();
    if ($c['redirect_uri'] !== '') return $c['redirect_uri'];
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['SCRIPT_NAME'] . '?action=callback';
}

/** Endereço da Meta para onde enviar a pessoa autorizar (guarda o "state" na sessão). */
function meta_auth_url(): string
{
    $c = meta_cfg();
    $state = bin2hex(random_bytes(24));
    $_SESSION['meta_oauth'] = ['state' => $state, 'until' => time() + 600];
    return rtrim($c['auth_base'], '/') . '/' . $c['api_version'] . '/dialog/oauth?' . http_build_query([
        'client_id' => $c['app_id'], 'redirect_uri' => meta_redirect_uri(), 'state' => $state, 'response_type' => 'code', 'scope' => implode(',', $c['scopes']),
    ]);
}

/** Traduz um erro da Graph API para uma mensagem clara. */
function meta_error_from(array $body, int $status): MetaError
{
    $e = $body['error'] ?? [];
    $code = (int)($e['code'] ?? 0); $sub = (int)($e['error_subcode'] ?? 0); $msg = (string)($e['message'] ?? '');
    if ($code === 190) {
        return new MetaError('A autorização da Meta expirou ou foi revogada. Liga a Meta Ads outra vez.', true, 'auth', $code);
    }
    if ($code === 10 || ($code >= 200 && $code <= 299)) {
        return new MetaError('A Meta recusou o acesso: faltam permissões para ler estes anúncios (' . 'ads_read' . '). Liga outra vez e aceita todas as permissões, ou confirma que a tua conta tem acesso à conta de anúncios.', false, 'permission', $code);
    }
    if (in_array($code, [4, 17, 32, 613, 80000, 80003, 80004], true) || $status === 429) {
        return new MetaError('A Meta limitou temporariamente os pedidos (demasiados pedidos). Espera alguns minutos e atualiza outra vez.', false, 'rate_limit', $code);
    }
    if ($code === 100) {
        return new MetaError('A Meta não aceitou o pedido (' . mb_substr($msg, 0, 120) . ').', false, 'invalid', $code);
    }
    if ($code === 1 || $code === 2 || $status >= 500) {
        return new MetaError('A Meta está com problemas neste momento. Tenta outra vez dentro de alguns minutos.', false, 'unavailable', $code);
    }
    return new MetaError('A Meta devolveu um erro' . ($code ? " ($code)" : '') . ($msg !== '' ? ': ' . mb_substr($msg, 0, 140) : '.'), false, 'error', $code);
}

/** Pedido GET à Graph API. Devolve o JSON. $token vazio = sem autenticação (troca de código). */
function meta_graph(string $path, array $query = [], ?string $token = null): array
{
    $c = meta_cfg();
    if ($token !== null) {
        $query['access_token'] = $token;
        $query['appsecret_proof'] = hash_hmac('sha256', $token, $c['app_secret']);
    }
    $url = str_starts_with($path, 'http') ? $path : rtrim($c['graph_base'], '/') . '/' . $c['api_version'] . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
    try { $r = http_request('GET', $url, ['Accept: application/json'], null, 25); }
    catch (RuntimeException $e) { throw new MetaError('Não foi possível contactar a Meta. Verifica a ligação à internet e tenta outra vez.', false, 'network'); }
    $body = json_decode($r['body'], true);
    if (!is_array($body)) throw new MetaError('A Meta devolveu uma resposta inesperada. Tenta outra vez.', false, 'unavailable');
    if ($r['status'] >= 400 || isset($body['error'])) throw meta_error_from($body, $r['status']);
    return $body;
}

/** Troca o código (curto) por um token e depois por um token de LONGA duração (~60 dias). */
function meta_exchange_code(string $code): array
{
    $c = meta_cfg();
    $short = meta_graph('oauth/access_token', ['client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'redirect_uri' => meta_redirect_uri(), 'code' => $code]);
    if (empty($short['access_token'])) throw new MetaError('A Meta não devolveu uma autorização válida. Tenta ligar outra vez.');
    try {
        $long = meta_graph('oauth/access_token', ['grant_type' => 'fb_exchange_token', 'client_id' => $c['app_id'], 'client_secret' => $c['app_secret'], 'fb_exchange_token' => $short['access_token']]);
        if (!empty($long['access_token'])) return $long;
    } catch (MetaError $e) { /* fica com o token curto: funciona, mas expira mais cedo */ }
    return $short;
}

function meta_connection(int $userId): ?array
{
    $st = db()->prepare('SELECT * FROM meta_connections WHERE user_id = ?');
    $st->execute([$userId]);
    return $st->fetch() ?: null;
}

function meta_store_connection(int $userId, array $tok, array $me): void
{
    $expires = !empty($tok['expires_in']) ? (new DateTime('now', app_timezone()))->modify('+' . (int)$tok['expires_in'] . ' seconds')->format('Y-m-d H:i:s') : null;
    db()->prepare('INSERT INTO meta_connections (user_id, fb_user_id, fb_user_name, access_token_enc, expires_at, scope) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE fb_user_id = VALUES(fb_user_id), fb_user_name = VALUES(fb_user_name), access_token_enc = VALUES(access_token_enc),
                   expires_at = VALUES(expires_at), scope = VALUES(scope), last_error = NULL, connected_at = NOW()')
        ->execute([$userId, $me['id'] ?? null, isset($me['name']) ? mb_substr((string)$me['name'], 0, 190) : null, encrypt_secret($tok['access_token']), $expires, implode(',', meta_cfg()['scopes'])]);
}

/** O token desta ligação. Lança MetaError(reconnect) se não há ou já expirou. */
function meta_token(int $userId): string
{
    $conn = meta_connection($userId);
    if (!$conn || empty($conn['access_token_enc'])) throw new MetaError('A Meta Ads não está ligada.', true, 'auth');
    if ($conn['expires_at'] && (new DateTime($conn['expires_at'], app_timezone()))->getTimestamp() < time()) {
        throw new MetaError('A autorização da Meta expirou. Liga a Meta Ads outra vez.', true, 'auth');
    }
    $t = decrypt_secret($conn['access_token_enc']);
    if (!$t) throw new MetaError('A autorização guardada é inválida. Liga a Meta Ads outra vez.', true, 'auth');
    return $t;
}

/** Segue a paginação da Graph API (no máximo $maxPages páginas). */
function meta_graph_all(string $path, array $query, string $token, int $maxPages = 4): array
{
    $rows = []; $page = meta_graph($path, $query, $token);
    for ($i = 0; ; $i++) {
        foreach ($page['data'] ?? [] as $r) $rows[] = $r;
        $next = $page['paging']['next'] ?? null;
        if (!$next || $i + 1 >= $maxPages) break;
        // o endereço "next" já traz o token e a prova; só a base pode ter sido simulada nos testes
        $page = meta_graph($next);
    }
    return $rows;
}

/** Contas de anúncios a que esta pessoa tem acesso. */
function meta_list_ad_accounts(int $userId): array
{
    $rows = meta_graph_all('me/adaccounts', ['fields' => 'id,account_id,name,account_status,currency,timezone_name,business_name', 'limit' => 50], meta_token($userId), 2);
    $status = [1 => 'ACTIVE', 2 => 'DISABLED', 3 => 'UNSETTLED', 7 => 'PENDING_RISK_REVIEW', 8 => 'PENDING_SETTLEMENT', 9 => 'IN_GRACE_PERIOD', 100 => 'PENDING_CLOSURE', 101 => 'CLOSED', 201 => 'ANY_ACTIVE', 202 => 'ANY_CLOSED'];
    return array_map(fn($a) => [
        'id' => (string)($a['account_id'] ?? preg_replace('/^act_/', '', (string)$a['id'])), 'name' => (string)($a['name'] ?? ''), 'currency' => (string)($a['currency'] ?? ''),
        'status' => $status[(int)($a['account_status'] ?? 0)] ?? 'UNKNOWN', 'business' => (string)($a['business_name'] ?? ''), 'timezone' => (string)($a['timezone_name'] ?? ''),
    ], $rows);
}

/** Moedas sem casas decimais: a Meta devolve o orçamento já na unidade principal. */
function meta_minor_divisor(string $currency): int
{
    return in_array(strtoupper($currency), ['JPY', 'KRW', 'VND', 'CLP', 'ISK', 'HUF', 'TWD', 'UGX', 'PYG', 'XAF', 'XOF'], true) ? 1 : 100;
}

/** Valor de uma lista "actions": soma os tipos pedidos (o primeiro que existir, para não contar duas vezes o mesmo evento). */
function meta_action_value($list, array $types): float
{
    if (!is_array($list)) return 0.0;
    $by = [];
    foreach ($list as $a) { if (isset($a['action_type'])) $by[$a['action_type']] = (float)($a['value'] ?? 0); }
    foreach ($types as $t) { if (isset($by[$t])) return $by[$t]; }
    return 0.0;
}

/** Converte uma linha de "insights" nas métricas do Lumina. */
function meta_metrics(array $r): array
{
    $spend = (float)($r['spend'] ?? 0);
    $purchase = ['omni_purchase', 'purchase', 'offsite_conversion.fb_pixel_purchase'];
    $lead = ['lead', 'onsite_conversion.lead_grouped', 'offsite_conversion.fb_pixel_lead'];
    $purchases = meta_action_value($r['actions'] ?? null, $purchase);
    $leads = meta_action_value($r['actions'] ?? null, $lead);
    $roas = null;
    if (!empty($r['purchase_roas']) && is_array($r['purchase_roas'])) $roas = meta_action_value($r['purchase_roas'], ['omni_purchase', 'purchase']) ?: (float)($r['purchase_roas'][0]['value'] ?? 0);
    elseif ($spend > 0) { $rev = meta_action_value($r['action_values'] ?? null, $purchase); if ($rev > 0) $roas = $rev / $spend; }
    return [
        'spend' => round($spend, 2), 'impressions' => (int)($r['impressions'] ?? 0), 'clicks' => (int)($r['clicks'] ?? 0), 'reach' => (int)($r['reach'] ?? 0),
        'cpc' => isset($r['cpc']) ? round((float)$r['cpc'], 4) : null, 'cpm' => isset($r['cpm']) ? round((float)$r['cpm'], 4) : null, 'ctr' => isset($r['ctr']) ? round((float)$r['ctr'], 4) : null,
        'conversions' => (int)round($purchases > 0 ? $purchases : $leads), 'conversion_type' => $purchases > 0 ? 'purchase' : ($leads > 0 ? 'lead' : null),
        'roas' => $roas !== null ? round($roas, 2) : null,
    ];
}

function meta_budget($v, string $currency): ?float
{
    return ($v === null || $v === '' || !is_numeric($v)) ? null : round((float)$v / meta_minor_divisor($currency), 2);
}

/** Lê tudo da Meta para a conta e o período: campanhas, conjuntos, anúncios e métricas (totais e por objeto). */
function meta_fetch_overview(int $userId, string $accountId, string $currency, string $preset): array
{
    if (!preg_match('/^\d{5,20}$/', $accountId)) throw new MetaError('Conta de anúncios inválida.');
    if (!in_array($preset, META_PRESETS, true)) $preset = 'last_30d';
    $token = meta_token($userId); $act = 'act_' . $accountId;
    $limits = ['limit' => 200];
    $campaigns = meta_graph_all("$act/campaigns", $limits + ['fields' => 'id,name,status,effective_status,objective,daily_budget,lifetime_budget,start_time,stop_time'], $token);
    $adsets = meta_graph_all("$act/adsets", $limits + ['fields' => 'id,name,campaign_id,status,effective_status,daily_budget,lifetime_budget,optimization_goal'], $token);
    $ads = meta_graph_all("$act/ads", $limits + ['fields' => 'id,name,adset_id,campaign_id,status,effective_status'], $token);
    $fields = 'spend,impressions,clicks,reach,cpc,cpm,ctr,actions,action_values,purchase_roas';
    $totals = meta_graph("$act/insights", ['fields' => $fields, 'date_preset' => $preset, 'level' => 'account'], $token)['data'][0] ?? [];
    $byLevel = [];
    foreach (['campaign' => 'campaign_id', 'adset' => 'adset_id', 'ad' => 'ad_id'] as $level => $idKey) {
        $byLevel[$level] = [];
        foreach (meta_graph_all("$act/insights", ['fields' => "$idKey,$fields", 'date_preset' => $preset, 'level' => $level, 'limit' => 500], $token) as $row) {
            if (isset($row[$idKey])) $byLevel[$level][(string)$row[$idKey]] = meta_metrics($row);
        }
    }
    $empty = meta_metrics([]);
    $shape = function (array $rows, string $level, bool $budget) use ($byLevel, $currency, $empty): array {
        return array_map(fn($r) => [
            'id' => (string)$r['id'], 'name' => (string)($r['name'] ?? ''), 'status' => (string)($r['status'] ?? ''), 'effective_status' => (string)($r['effective_status'] ?? $r['status'] ?? ''),
            'campaign_id' => isset($r['campaign_id']) ? (string)$r['campaign_id'] : null, 'adset_id' => isset($r['adset_id']) ? (string)$r['adset_id'] : null,
            'objective' => $r['objective'] ?? null, 'optimization_goal' => $r['optimization_goal'] ?? null,
            'daily_budget' => $budget ? meta_budget($r['daily_budget'] ?? null, $currency) : null, 'lifetime_budget' => $budget ? meta_budget($r['lifetime_budget'] ?? null, $currency) : null,
            'metrics' => $byLevel[$level][(string)$r['id']] ?? $empty,
        ], $rows);
    };
    return [
        'account_id' => $accountId, 'currency' => $currency, 'date_preset' => $preset, 'fetched_at' => (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s'),
        'totals' => meta_metrics($totals),
        'campaigns' => $shape($campaigns, 'campaign', true), 'adsets' => $shape($adsets, 'adset', true), 'ads' => $shape($ads, 'ad', false),
    ];
}

function meta_save_snapshot(int $userId, array $data): void
{
    db()->prepare('INSERT INTO meta_snapshots (user_id, ad_account_id, date_preset, payload, fetched_at) VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = VALUES(fetched_at)')
        ->execute([$userId, $data['account_id'], $data['date_preset'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $data['fetched_at']]);
}

function meta_load_snapshot(int $userId, string $accountId, string $preset): ?array
{
    $st = db()->prepare('SELECT payload FROM meta_snapshots WHERE user_id = ? AND ad_account_id = ? AND date_preset = ?');
    $st->execute([$userId, $accountId, $preset]);
    $p = $st->fetchColumn();
    return $p ? (json_decode((string)$p, true) ?: null) : null;
}

/** Desliga: revoga as permissões na Meta (melhor esforço) e apaga o token e os dados guardados. */
function meta_disconnect(int $userId): bool
{
    $conn = meta_connection($userId);
    if (!$conn) return false;
    try { $t = decrypt_secret($conn['access_token_enc'] ?? null); if ($t) { $c = meta_cfg(); http_request('DELETE', rtrim($c['graph_base'], '/') . '/' . $c['api_version'] . '/me/permissions?' . http_build_query(['access_token' => $t, 'appsecret_proof' => hash_hmac('sha256', $t, $c['app_secret'])]), [], null, 10); } }
    catch (Throwable $e) { /* se a Meta não responder, apaga na mesma do nosso lado */ }
    db()->prepare('DELETE FROM meta_snapshots WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM meta_connections WHERE user_id = ?')->execute([$userId]);
    return true;
}
