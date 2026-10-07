<?php
/* =========================================================================
   API DA ÁREA PESSOAL  (api/me.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  perfil do utilizador, dados da empresa, foto, logo e preferências.
     GET                         devolve utilizador, empresa e preferências
     POST action=profile_update  nome, função e telefone (o email é só de leitura)
     POST action=avatar_upload   foto de perfil (multipart: campo "file")
     POST action=avatar_remove   remove a foto
     POST action=company_update  dados da empresa (só o dono)
     POST action=logo_upload     logo da empresa (só o dono; PNG, JPG ou SVG)
     POST action=logo_remove     remove a logo (só o dono)
     POST action=prefs_update    preferências (ex.: ocultar valores financeiros)
   REGRAS: sessão obrigatória; CSRF em tudo o que altera; a empresa só o dono
           altera; as imagens passam por includes/uploads.php (validação rigorosa).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$user = require_login();
$uid = (int)$user['id'];
$tid = (int)$user['tenant_id'];
$isOwner = ($user['role'] ?? '') === 'owner';
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_POST['action'] ?? ($_GET['action'] ?? ''));
$data = ($_SERVER['CONTENT_TYPE'] ?? '') !== '' && str_contains((string)$_SERVER['CONTENT_TYPE'], 'json') ? request_json() : $_POST;
if (isset($data['action'])) $action = (string)$data['action'];

/** Preferências permitidas (lista fechada: nada mais se guarda). */
const PREF_KEYS = ['hide_values' => 'bool'];     // (o idioma tem a sua própria ação: set_lang)

/** NIF português: 9 dígitos e algarismo de controlo válido. */
function valid_nif(string $nif): bool
{
    if (!preg_match('/^\d{9}$/', $nif) || !in_array($nif[0], ['1', '2', '3', '5', '6', '8', '9'], true)) return false;
    $sum = 0;
    for ($i = 0; $i < 8; $i++) $sum += (int)$nif[$i] * (9 - $i);
    $check = 11 - ($sum % 11);
    return (int)$nif[8] === ($check >= 10 ? 0 : $check);
}

/** Iniciais para quando não há foto ("Arthur Silva" -> "AS"). */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $first = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1));
    return count($parts) > 1 ? $first . mb_strtoupper(mb_substr(end($parts), 0, 1)) : $first;
}

/** Nome da função em linguagem simples. */
function role_label(array $user): string
{
    if (($user['role'] ?? '') === 'owner') return 'Administrador';
    return count((array)($user['permissions'] ?? [])) >= 4 ? 'Gerente' : 'Funcionário';
}

/** Lê as colunas que não vêm no utilizador da sessão. */
function load_me(int $uid, int $tid): array
{
    $st = db()->prepare('SELECT name, email, job_title, phone, avatar_path, preferences, totp_enabled FROM users WHERE id = ?');
    $st->execute([$uid]);
    $u = $st->fetch();
    $co = db()->prepare('SELECT business_name, business_type, activity, tax_number, address, company_phone, company_email, website, logo_path, brand_color FROM business_profiles WHERE user_id = ? LIMIT 1');
    $co->execute([$tid]);
    $c = $co->fetch() ?: [];
    $prefs = $u['preferences'] ? (json_decode($u['preferences'], true) ?: []) : [];
    return [$u, $c, $prefs];
}

try {
    /* ------------------------------ LER ------------------------------ */
    if ($method === 'GET') {
        [$u, $c, $prefs] = load_me($uid, $tid);
        json_response(['success' => true,
            'user' => ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'job_title' => $u['job_title'], 'phone' => $u['phone'], 'role_label' => role_label($user),
                'is_owner' => $isOwner, 'initials' => initials($u['name']), 'two_factor' => (int)$u['totp_enabled'] === 1,
                'avatar_url' => $u['avatar_path'] ? 'api/files.php?type=avatar&id=' . $uid . '&v=' . substr(md5($u['avatar_path']), 0, 8) : null],
            'company' => $c ? [
                'name' => $c['business_name'], 'activity' => $c['activity'], 'tax_number' => $isOwner ? $c['tax_number'] : null, 'address' => $isOwner ? $c['address'] : null,
                'phone' => $c['company_phone'], 'email' => $c['company_email'], 'website' => $c['website'], 'brand_color' => $c['brand_color'],
                'logo_url' => $c['logo_path'] ? 'api/files.php?type=logo&v=' . substr(md5($c['logo_path']), 0, 8) : null] : null,
            'prefs' => $prefs + ['hide_values' => false]]);
    }

    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();

    /* ------------------------------ PERFIL ------------------------------ */
    if ($action === 'profile_update') {
        $name = trim(preg_replace('/\s+/u', ' ', (string)($data['name'] ?? '')) ?? '');
        $phone = trim((string)($data['phone'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) json_response(['success' => false, 'error' => 'Indica o teu nome (entre 2 e 100 caracteres).'], 400);
        if ($phone !== '' && !preg_match('/^\+?[0-9 ()\-]{6,25}$/', $phone)) json_response(['success' => false, 'error' => 'O telefone não parece válido (ex.: +351 912 345 678).'], 400);
        $job = $isOwner ? mb_substr(trim((string)($data['job_title'] ?? '')), 0, 100) : null;      // o funcionário não altera o seu cargo: é o chefe que define
        db()->prepare('UPDATE users SET name = ?, phone = ?' . ($isOwner ? ', job_title = ?' : '') . ' WHERE id = ?')
            ->execute($isOwner ? [$name, $phone ?: null, $job ?: null, $uid] : [$name, $phone ?: null, $uid]);
        audit('profile_update', [], $user);
        json_response(['success' => true]);
    }

    /* ------------------------------ FOTO ------------------------------ */
    if ($action === 'avatar_upload') {
        [$bytes, , $ext] = upload_validate($_FILES['file'] ?? null, 'avatar');
        $old = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $old->execute([$uid]);
        $path = upload_store('avatar', $bytes, $ext);
        db()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?')->execute([$path, $uid]);
        upload_delete($old->fetchColumn() ?: null);                          // apaga a foto anterior
        audit('avatar_change', [], $user);
        json_response(['success' => true, 'avatar_url' => 'api/files.php?type=avatar&id=' . $uid . '&v=' . substr(md5($path), 0, 8)]);
    }
    if ($action === 'avatar_remove') {
        $old = db()->prepare('SELECT avatar_path FROM users WHERE id = ?');
        $old->execute([$uid]);
        upload_delete($old->fetchColumn() ?: null);
        db()->prepare('UPDATE users SET avatar_path = NULL WHERE id = ?')->execute([$uid]);
        json_response(['success' => true]);
    }

    /* ------------------------------ EMPRESA (só o dono) ------------------------------ */
    if (in_array($action, ['company_update', 'logo_upload', 'logo_remove'], true)) {
        require_owner($user);
        $exists = db()->prepare('SELECT COUNT(*) FROM business_profiles WHERE user_id = ?');
        $exists->execute([$tid]);
        if (!(int)$exists->fetchColumn()) {                                  // a empresa ainda não tem perfil: cria o básico
            db()->prepare("INSERT INTO business_profiles (user_id, business_name, business_type, currency) VALUES (?, 'O meu negócio', 'general', 'EUR')")->execute([$tid]);
        }
    }

    if ($action === 'company_update') {
        $v = fn(string $k, int $max = 190) => mb_substr(trim((string)($data[$k] ?? '')), 0, $max);
        $name = $v('name', 160); $nif = preg_replace('/\s+/', '', $v('tax_number', 20)); $mail = $v('email'); $site = $v('website'); $color = $v('brand_color', 7); $phone = $v('phone', 40);
        if ($name === '') json_response(['success' => false, 'error' => 'Indica o nome da empresa.'], 400);
        if ($nif !== '' && !valid_nif($nif)) json_response(['success' => false, 'error' => 'O NIF não é válido (9 dígitos).'], 400);
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) json_response(['success' => false, 'error' => 'O email profissional não é válido.'], 400);
        if ($site !== '' && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $site)) json_response(['success' => false, 'error' => 'O website tem de começar por http:// ou https://.'], 400);
        if ($color !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) json_response(['success' => false, 'error' => 'A cor tem de estar no formato #rrggbb.'], 400);
        if ($phone !== '' && !preg_match('/^\+?[0-9 ()\-]{6,25}$/', $phone)) json_response(['success' => false, 'error' => 'O telefone da empresa não parece válido.'], 400);
        db()->prepare('UPDATE business_profiles SET business_name = ?, activity = ?, tax_number = ?, address = ?, company_phone = ?, company_email = ?, website = ?, brand_color = ? WHERE user_id = ?')
            ->execute([$name, $v('activity', 160) ?: null, $nif ?: null, $v('address', 255) ?: null, $phone ?: null, $mail ?: null, $site ?: null, $color ? strtolower($color) : null, $tid]);
        audit('company_update', [], $user);
        json_response(['success' => true]);
    }

    if ($action === 'logo_upload') {
        [$bytes, , $ext] = upload_validate($_FILES['file'] ?? null, 'logo');
        $old = db()->prepare('SELECT logo_path FROM business_profiles WHERE user_id = ?');
        $old->execute([$tid]);
        $path = upload_store('logo', $bytes, $ext);
        db()->prepare('UPDATE business_profiles SET logo_path = ? WHERE user_id = ?')->execute([$path, $tid]);
        upload_delete($old->fetchColumn() ?: null);
        audit('logo_change', [], $user);
        json_response(['success' => true, 'logo_url' => 'api/files.php?type=logo&v=' . substr(md5($path), 0, 8)]);
    }
    if ($action === 'logo_remove') {
        $old = db()->prepare('SELECT logo_path FROM business_profiles WHERE user_id = ?');
        $old->execute([$tid]);
        upload_delete($old->fetchColumn() ?: null);
        db()->prepare('UPDATE business_profiles SET logo_path = NULL WHERE user_id = ?')->execute([$tid]);
        json_response(['success' => true]);
    }

    /* ------------------------------ PREFERÊNCIAS ------------------------------ */
    if ($action === 'prefs_update') {
        [, , $prefs] = load_me($uid, $tid);
        foreach (PREF_KEYS as $key => $type) {                                // só chaves da lista fechada
            if (array_key_exists($key, $data)) $prefs[$key] = filter_var($data[$key], FILTER_VALIDATE_BOOLEAN);
        }
        db()->prepare('UPDATE users SET preferences = ? WHERE id = ?')->execute([json_encode($prefs), $uid]);
        json_response(['success' => true, 'prefs' => $prefs + ['hide_values' => false]]);
    }

    /* ------------------------------ IDIOMA ------------------------------ */
    if ($action === 'set_lang') {                                              // guarda o idioma escolhido na conta (vale em todos os aparelhos)
        $lang = (string)($data['lang'] ?? '');
        if (!in_array($lang, ['pt', 'en', 'es'], true)) json_response(['success' => false, 'error' => 'Idioma não suportado.'], 400);
        [, , $prefs] = load_me($uid, $tid);
        $prefs['lang'] = $lang;
        db()->prepare('UPDATE users SET preferences = ? WHERE id = ?')->execute([json_encode($prefs), $uid]);
        json_response(['success' => true, 'lang' => $lang]);
    }

    json_response(['success' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (UploadError $e) {
    json_response(['success' => false, 'error' => $e->getMessage()], 400);        // mensagens amigáveis do upload
} catch (Throwable $error) {
    internal_error($error);
}
