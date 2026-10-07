<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

/* =========================================================
   CARTÕES ASSOCIADOS (demonstração, sem integração de pagamentos)

   - O número completo e o CVV/CVC NUNCA chegam aqui: o navegador valida-os e envia só
     bandeira + últimos 4 dígitos + validade + titular. Qualquer outro campo é RECUSADO
     (por exemplo "number", "cvv", "cvc", "pan"), por isso não há como guardá-los por engano.
   - O utilizador vem sempre da sessão. Só o dono do negócio vê e altera os seus cartões (funcionários: 403).
   - Não faz nenhuma cobrança nem aprovação bancária.
   - COMO LIGAR UM FORNECEDOR REAL (ex.: Stripe, Adyen, SIBS): o navegador envia os dados do
     cartão DIRETAMENTE ao fornecedor (campos hospedados por ele), que devolve um TOKEN. Este ficheiro
     passaria a receber só o token (e a bandeira/últimos 4 dígitos que o fornecedor devolve) e guardaria
     o token numa nova coluna. O número completo e o CVV nunca chegam ao nosso servidor.
   ========================================================= */

const CARD_BRANDS = ['visa', 'mastercard', 'amex'];
const CARD_MAX_PER_USER = 10;

$user = require_login();
require_owner($user);                       // cartões do negócio: só o responsável (os funcionários nunca os vêem nem os alteram)
$uid = (int)$user['id'];

/** Estado calculado a partir da validade (no fuso horário do programa). */
function card_status(int $month, int $year): string
{
    $lastDay = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), app_timezone());
    $lastDay = $lastDay->modify('last day of this month')->setTime(23, 59, 59);
    return $lastDay < new DateTimeImmutable('now', app_timezone()) ? 'expired' : 'active';
}

function card_public(array $r): array
{
    return [
        'id' => (int)$r['id'], 'brand' => $r['brand'], 'last4' => $r['last4'], 'holder' => $r['holder_name'],
        'exp_month' => (int)$r['exp_month'], 'exp_year' => (int)$r['exp_year'], 'is_primary' => (bool)$r['is_primary'],
        // estado: "expired" (validade passada), "disabled" (desativado pelo dono) ou "active"
        'status' => card_status((int)$r['exp_month'], (int)$r['exp_year']) === 'expired' ? 'expired' : ($r['status'] === 'disabled' ? 'disabled' : 'active'),
    ];
}

function list_cards(int $uid): array
{
    $st = db()->prepare('SELECT id, brand, last4, holder_name, exp_month, exp_year, is_primary, status FROM payment_cards WHERE user_id = ? ORDER BY is_primary DESC, id DESC');
    $st->execute([$uid]);
    return array_map('card_public', $st->fetchAll());
}

function fail(string $message, int $status = 400): never { json_response(['success' => false, 'error' => $message], $status); }

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        json_response(['success' => true, 'items' => list_cards($uid)]);
    }

    if ($method !== 'POST') { fail('Método não permitido.', 405); }

    $data = request_json();
    check_csrf();

    /* ---------- GESTÃO DE UM CARTÃO JÁ ASSOCIADO ----------
       action = set_status (active | disabled), set_primary, delete.
       Tudo filtrado pelo dono (user_id da sessão): ninguém mexe nos cartões de outro negócio. */
    $action = (string)($data['action'] ?? 'add');
    if ($action !== 'add') {
        $cardId = (int)($data['id'] ?? 0);
        $find = db()->prepare('SELECT id, is_primary, status FROM payment_cards WHERE id = ? AND user_id = ?');
        $find->execute([$cardId, $uid]);
        $card = $find->fetch();
        if (!$card) fail('Cartão não encontrado.', 404);

        if ($action === 'set_status') {
            $status = (string)($data['status'] ?? '');
            if (!in_array($status, ['active', 'disabled'], true)) fail('Estado inválido.');
            // um cartão desativado não pode ser o principal
            db()->prepare('UPDATE payment_cards SET status = ?, is_primary = IF(? = \'disabled\', 0, is_primary) WHERE id = ? AND user_id = ?')->execute([$status, $status, $cardId, $uid]);
            audit($status === 'disabled' ? 'card_disabled' : 'card_enabled', ['card_id' => $cardId]);
            json_response(['success' => true, 'items' => list_cards($uid)]);
        }
        if ($action === 'set_primary') {
            if ($card['status'] === 'disabled') fail('Ativa o cartão antes de o tornar principal.', 409);
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE payment_cards SET is_primary = 0 WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('UPDATE payment_cards SET is_primary = 1 WHERE id = ? AND user_id = ?')->execute([$cardId, $uid]);
            $pdo->commit();
            audit('card_primary', ['card_id' => $cardId]);
            json_response(['success' => true, 'items' => list_cards($uid)]);
        }
        if ($action === 'delete') {
            db()->prepare('DELETE FROM payment_cards WHERE id = ? AND user_id = ?')->execute([$cardId, $uid]);
            if ($card['is_primary']) {                                   // se era o principal, o cartão ativo mais recente passa a sê-lo
                db()->prepare("UPDATE payment_cards SET is_primary = 1 WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1")->execute([$uid]);
            }
            audit('card_removed', ['card_id' => $cardId]);
            json_response(['success' => true, 'items' => list_cards($uid)]);
        }
        fail('Ação desconhecida.');
    }

    // Só estes campos são aceites. Tudo o resto (número completo, CVV...) é recusado, nunca ignorado.
    $allowed = ['holder', 'brand', 'last4', 'exp_month', 'exp_year', 'is_primary', 'csrf', 'action'];
    foreach (array_keys($data) as $key) {
        if (!in_array((string)$key, $allowed, true)) { fail('Campo não permitido. Só são aceites a bandeira, os últimos 4 dígitos e a validade.'); }
    }

    $holder = trim((string)($data['holder'] ?? ''));
    $holder = preg_replace('/\s+/u', ' ', $holder) ?? '';
    if (mb_strlen($holder) < 2 || mb_strlen($holder) > 40 || !preg_match('/^[\p{L}][\p{L} .\'\-]*$/u', $holder)) {
        fail('Indica o nome do titular (apenas letras, entre 2 e 40 caracteres).');
    }
    $brand = (string)($data['brand'] ?? '');
    if (!in_array($brand, CARD_BRANDS, true)) { fail('Bandeira de cartão não suportada.'); }
    $last4 = (string)($data['last4'] ?? '');
    if (!preg_match('/^\d{4}$/', $last4)) { fail('Os últimos 4 dígitos são inválidos.'); }

    $month = $data['exp_month'] ?? null; $year = $data['exp_year'] ?? null;
    if (!is_int($month) || !is_int($year) || $month < 1 || $month > 12) { fail('Validade inválida.'); }
    $thisYear = (int)(new DateTimeImmutable('now', app_timezone()))->format('Y');
    if ($year < $thisYear || $year > $thisYear + 20) { fail('Validade inválida.'); }
    if (card_status($month, $year) === 'expired') { fail('Este cartão já expirou.'); }
    $primary = ($data['is_primary'] ?? false) === true;

    $pdo = db();
    $count = $pdo->prepare('SELECT COUNT(*) FROM payment_cards WHERE user_id = ?'); $count->execute([$uid]);
    $existing = (int)$count->fetchColumn();
    if ($existing >= CARD_MAX_PER_USER) { fail('Atingiste o máximo de ' . CARD_MAX_PER_USER . ' cartões associados.'); }
    if ($existing === 0) { $primary = true; }                        // o primeiro cartão fica como principal

    $pdo->beginTransaction();
    try {
        if ($primary) { $pdo->prepare('UPDATE payment_cards SET is_primary = 0 WHERE user_id = ?')->execute([$uid]); }
        $pdo->prepare('INSERT INTO payment_cards (user_id, brand, last4, holder_name, exp_month, exp_year, is_primary) VALUES (?,?,?,?,?,?,?)')
            ->execute([$uid, $brand, $last4, mb_strtoupper($holder), $month, $year, $primary ? 1 : 0]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ($e->getCode() === '23000') { fail('Este cartão já está associado à tua conta.', 409); }
        throw $e;
    }

    $st = $pdo->prepare('SELECT id, brand, last4, holder_name, exp_month, exp_year, is_primary, status FROM payment_cards WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    json_response(['success' => true, 'item' => card_public($st->fetch()), 'items' => list_cards($uid)], 201);
} catch (Throwable $e) {
    error_log('[cartões] ' . $e->getMessage());                      // nunca regista o corpo do pedido
    fail('Não foi possível processar o pedido agora.', 500);
}
