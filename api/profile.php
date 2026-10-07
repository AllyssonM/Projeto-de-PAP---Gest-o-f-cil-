<?php
/* =========================================================================
   PERFIL DO NEGÓCIO E QUESTIONÁRIO DE ARRANQUE  (api/profile.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  GET  devolve o ramo, o nome do negócio, os módulos ligados e as respostas do questionário.
               POST (só o dono) grava tudo isso. É chamado no primeiro acesso (questionário de 6 passos),
               por "Alterar ramo" e por "Refazer o questionário".
   O QUE SE GUARDA:
     business_type    o ramo (tem de coincidir com as chaves de RAMOS em assets/js/profiles.js)
     enabled_modules  as abas ligadas (ex.: ["overview","cashflow","clients",...]); as 3 essenciais ficam sempre
     onboarding       as respostas (equipa, local, pagamentos, regime de IVA, objetivo...) em JSON
   EXTRA:      se a pessoa deu um objetivo com valor ("juntar para o IVA: 1500 €"), cria uma conta de reserva
               com essa meta (uma só vez).
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
$user = require_login();

// Ramos aceites. TÊM de coincidir com as chaves de RAMOS em assets/js/profiles.js.
const PROFILE_TYPES = ['real_estate', 'cars', 'uber', 'rider', 'barber', 'online_store', 'clothing', 'restaurant', 'mechanic', 'freelancer', 'personal', 'general'];
// Abas que se podem ligar/desligar (ids de data-section no dashboard.php). As ESSENCIAIS não se desligam.
const PROFILE_MODULES   = ['overview', 'cashflow', 'calendar', 'notes', 'time', 'insights', 'clients', 'accounts', 'stock', 'team', 'calculator', 'weather', 'reports'];
const PROFILE_ESSENTIAL = ['overview', 'cashflow', 'reports'];
// Respostas do questionário: só estes valores são aceites.
const ONBOARDING_CHOICES = [
    'team'    => ['solo', 'small', 'large'],                       // só eu / 2 a 5 pessoas / 6 ou mais
    'place'   => ['fixed', 'mobile', 'online', 'both'],            // espaço fixo / itinerante / online / ambos
    'vat'     => ['exempt', 'quarterly', 'monthly', 'unknown'],    // regime de IVA
    'ss'      => ['independent', 'company', 'unknown'],            // Segurança Social
    'revenue' => ['lt1k', '1to3k', '3to10k', 'gt10k', 'unknown'],  // faturação média por mês
    'goal'    => ['vat', 'rent', 'grow', 'expenses', 'none'],
];
const ONBOARDING_PAY = ['cash', 'mbway', 'card', 'transfer', 'platforms'];
const GOAL_ACCOUNT_NAMES = ['vat' => 'Reserva para o IVA', 'rent' => 'Reserva para a renda', 'grow' => 'Meta de crescimento'];

/** Módulos lidos da base de dados. Registos antigos guardavam outra coisa (os "atalhos" do ramo): nesse caso, devolve null = todas as abas. */
function profile_modules_from_db(?string $json): ?array
{
    $list = $json ? json_decode($json, true) : null;
    if (!is_array($list)) return null;
    $known = array_values(array_intersect(PROFILE_MODULES, $list));
    return count($known) >= count(PROFILE_ESSENTIAL) ? $known : null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = db()->prepare('SELECT business_name, business_type, currency, enabled_modules, labels, onboarding, onboarding_done_at FROM business_profiles WHERE user_id = ? LIMIT 1');
        $stmt->execute([$user['tenant_id']]);
        $profile = $stmt->fetch();
        if ($profile) {
            $profile['enabled_modules'] = profile_modules_from_db($profile['enabled_modules']);
            $profile['labels'] = $profile['labels'] ? json_decode($profile['labels'], true) : [];
            $profile['onboarding'] = $profile['onboarding'] ? (json_decode($profile['onboarding'], true) ?: new stdClass()) : new stdClass();
        }
        json_response(['success' => true, 'profile' => $profile]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    }
    require_owner($user);
    check_csrf();
    $data = request_json();

    $in = Validator::make($data)->text('business_name', 'Nome do negócio', 160, false, 'O meu negócio')
        ->enum('business_type', 'Ramo de atividade', PROFILE_TYPES, 'general')->orFail();

    // módulos: só os conhecidos + as essenciais. Sem lista (ou lista vazia = "sem preferência") = todas as abas.
    // O questionário nunca envia a lista vazia: as 3 essenciais vão sempre.
    $modules = null;
    if (is_array($data['enabled_modules'] ?? null) && count($data['enabled_modules']) > 0) {
        $modules = array_values(array_unique(array_merge(PROFILE_ESSENTIAL, array_intersect(PROFILE_MODULES, array_map('strval', $data['enabled_modules'])))));
    }

    // respostas do questionário
    $answers = is_array($data['onboarding'] ?? null) ? $data['onboarding'] : [];
    $ob = [];
    foreach (ONBOARDING_CHOICES as $key => $allowed) {
        if (isset($answers[$key]) && in_array($answers[$key], $allowed, true)) $ob[$key] = $answers[$key];
    }
    $ob['pay'] = array_values(array_intersect(ONBOARDING_PAY, is_array($answers['pay'] ?? null) ? $answers['pay'] : []));
    $goalAmount = Validator::make($answers)->money('goal_amount', 'Valor do objetivo', true, false, 10000000.0)->orFail()['goal_amount'];
    if ($goalAmount !== null) $ob['goal_amount'] = $goalAmount;

    $labels = is_array($data['labels'] ?? null) ? json_encode($data['labels'], JSON_UNESCAPED_UNICODE) : null;
    $now = (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('INSERT INTO business_profiles (user_id, business_name, business_type, currency, enabled_modules, labels, onboarding, onboarding_done_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE business_name = VALUES(business_name), business_type = VALUES(business_type), enabled_modules = VALUES(enabled_modules), labels = VALUES(labels),
        onboarding = VALUES(onboarding), onboarding_done_at = VALUES(onboarding_done_at)');
    $stmt->execute([$user['id'], $in['business_name'], $in['business_type'], 'EUR', $modules === null ? null : json_encode($modules), $labels,
        $ob === ['pay' => []] ? null : json_encode($ob, JSON_UNESCAPED_UNICODE), $now]);

    // objetivo com valor: conta de reserva com meta (só se ainda não existir uma com esse nome)
    $created = false;
    if ($goalAmount !== null && isset(GOAL_ACCOUNT_NAMES[$ob['goal'] ?? ''])) {
        $name = GOAL_ACCOUNT_NAMES[$ob['goal']];
        $has = db()->prepare('SELECT id FROM accounts WHERE user_id = ? AND name = ? LIMIT 1');
        $has->execute([$user['tenant_id'], $name]);
        if (!$has->fetch()) {
            db()->prepare("INSERT INTO accounts (user_id, name, type, balance, target_amount) VALUES (?, ?, 'reserve', 0, ?)")->execute([$user['tenant_id'], $name, $goalAmount]);
            $created = true;
        }
    }
    json_response(['success' => true, 'goal_account_created' => $created, 'profile' => ['business_name' => $in['business_name'], 'business_type' => $in['business_type'],
        'enabled_modules' => $modules, 'labels' => $data['labels'] ?? [], 'onboarding' => $ob ?: new stdClass()]]);
} catch (Throwable $error) {
    internal_error($error);
}
