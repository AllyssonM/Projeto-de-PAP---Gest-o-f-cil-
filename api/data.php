<?php
/* =========================================================================
   API DOS DADOS DO NEGÓCIO  (api/data.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  é a "porta" por onde o painel lê e grava os dados financeiros:
               movimentos de caixa, contas a pagar/receber, contas internas e
               produtos (imóveis, viaturas, serviços, viagens... conforme o ramo).
   COMO SE USA: o JavaScript chama  api/data.php?module=NOME  com GET (ler),
               POST (criar/alterar) ou DELETE (eliminar).
   QUEM USA:   assets/js/app.js, cashflow.js (painel) e includes/ai_tools.php (IA, só leitura).

   REGRAS DE SEGURANÇA (importantes para a PAP):
   1. Só entra quem tem sessão iniciada (require_login).
   2. Cada módulo exige uma PERMISSÃO (mapa $needs): o dono tem todas; o
      funcionário só as que o dono lhe deu.
   3. Os dados são sempre os do NEGÓCIO do dono ($tid), mesmo quando quem pede
      é um funcionário. Todas as consultas filtram por user_id = $tid.
   4. Todos os valores vão como parâmetros preparados (?) -> sem SQL injection.
   5. Pedidos que alteram dados exigem o token CSRF (check_csrf).
   6. ELIMINAR é só do dono: o funcionário é de "entrada de dados" e não apaga.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validator.php';
require_once __DIR__ . '/../includes/recurring.php';
require_once __DIR__ . '/../includes/attachments.php';
require_once __DIR__ . '/../includes/stock_lib.php';
const PAYMENT_METHODS = ['cash', 'mbway', 'card', 'transfer', 'other'];

$user   = require_login();                 // sem sessão -> 401
$data   = request_json();                  // corpo do pedido (JSON)
$method = $_SERVER['REQUEST_METHOD'];
$module = $_GET['module'] ?? '';
$tid    = $user['tenant_id'];              // dono dos dados (o patrão, se quem pede é funcionário)
$now    = (new DateTime('now', app_timezone()))->format('Y-m-d H:i:s');

/* Mapa  módulo da API -> permissão necessária.
   Falha FECHADO: um módulo que não esteja aqui é recusado (nunca fica sem proteção). */
$needs = [
    'transactions'        => 'cashflow',   // movimentos de caixa
    'summary'             => 'cashflow',   // totais (entradas, saídas, saldo)
    'series'              => 'cashflow',   // totais por dia/semana/mês/ano (para os gráficos)
    'transaction_confirm' => 'cashflow',   // previsto -> realizado
    'accounts'            => 'accounts',   // contas internas (caixa, banco, reserva...)
    'bills'               => 'accounts',   // contas a pagar / a receber
    'bill_pay'            => 'accounts',   // marcar conta como paga
    'products'            => 'stock',      // produtos (imóveis, viaturas, serviços...)
    'product_attr'        => 'stock',      // atualizar os campos do produto (ex.: estado)
    'product_stock'       => 'stock',      // ajustar a quantidade em stock (e o mínimo) de um produto
    'product_update'      => 'stock',      // editar o produto e as suas variações (tamanho/cor)
    'product_delete'      => 'stock',      // apagar (arquivar) um produto — só o dono
    'variant_delete'      => 'stock',      // apagar uma variação — só o dono
];
if (!isset($needs[$module])) json_response(['success' => false, 'error' => 'Módulo desconhecido.'], 404);
require_permission($user, $needs[$module]);


/**
 * Resumo do stock calculado NA BASE DE DADOS (a mesma definição para a dashboard e para a Lumina):
 *   sem stock = quantidade 0 · stock baixo = tem unidades mas não passa do mínimo · normal = o resto.
 */
function stock_summary(int $tid): array
{
    $stmt = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(stock_quantity),0) units,
            COALESCE(SUM(stock_quantity <= 0),0) out_of_stock,
            COALESCE(SUM(stock_quantity > 0 AND stock_quantity <= minimum_stock),0) low,
            COALESCE(SUM(stock_quantity * cost_price),0) cost_value
        FROM products WHERE user_id = ? AND status <> 'archived'");
    $stmt->execute([$tid]);
    $r = $stmt->fetch();
    return ['products' => (int)$r['n'], 'units' => (int)$r['units'], 'out_of_stock' => (int)$r['out_of_stock'], 'low' => (int)$r['low'], 'cost_value' => round((float)$r['cost_value'], 2)];
}

/**
 * Campos próprios do ramo (ex.: tipologia, área, marca, km...). Vêm do navegador, por isso
 * NUNCA se confia neles: só ficam chaves simples (a-z, 0-9, _), valores de texto curtos e
 * no máximo 12 campos. Devolve o JSON a guardar, ou null se não houver nada.
 */
function clean_attributes(mixed $raw): ?string
{
    if (!is_array($raw)) return null;
    $clean = [];
    foreach ($raw as $key => $value) {
        if (count($clean) >= 12) break;                                        // limite de campos
        // chave segura: começa por letra (nada de "__proto__"), só a-z, 0-9 e _; e nunca nomes reservados do JavaScript
        if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,29}$/', $key) || in_array($key, ['constructor', 'prototype'], true)) continue;
        if (!is_scalar($value)) continue;                                      // só valores simples
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value) ?? '');
        if ($text === '') continue;                                            // campos vazios não se guardam
        $clean[$key] = mb_substr($text, 0, 120);
    }
    return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
}

/** Confirma que um registo (conta interna, cliente) pertence a ESTE negócio. Evita ligar dados a registos de outro negócio. */
function owned(string $table, ?int $id, int $tid): bool
{
    if ($id === null) return true;
    static $allowed = ['accounts', 'clients'];                       // lista fixa: o nome da tabela nunca vem do pedido
    if (!in_array($table, $allowed, true)) return false;
    $st = db()->prepare("SELECT 1 FROM `$table` WHERE id = ? AND user_id = ?");
    $st->execute([$id, $tid]);
    return (bool)$st->fetchColumn();
}

/** Limite de linhas por pedido (proteção: um negócio com anos de dados não pode travar o painel). */
function page_limits(int $default): array
{
    return [max(1, min($default, (int)($_GET['limit'] ?? $default))), max(0, (int)($_GET['offset'] ?? 0))];
}

/**
 * Totais realizados (entradas e saídas) por período, calculados na base de dados.
 * $period: day (14 dias) | week (8 semanas) | month (6 meses) | months12 | year (5 anos).
 * Devolve ['labels'=>[...], 'income'=>[...], 'expense'=>[...]] com um valor por "balde", incluindo os vazios.
 */
function money_series(int $tid, string $period): array
{
    static $monthNames = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    $today = new DateTime('today', app_timezone());
    $buckets = [];                                                  // chave => etiqueta
    switch ($period) {
        case 'day':
            $from = (clone $today)->modify('-13 days'); $expr = 'DATE(occurred_at)';
            for ($d = clone $from; $d <= $today; $d->modify('+1 day')) $buckets[$d->format('Y-m-d')] = $d->format('d/m');
            break;
        case 'week':
            $from = (clone $today)->modify('monday this week')->modify('-49 days'); $expr = 'DATE(DATE_SUB(occurred_at, INTERVAL WEEKDAY(occurred_at) DAY))';
            for ($d = clone $from; $d <= $today; $d->modify('+7 days')) $buckets[$d->format('Y-m-d')] = $d->format('d/m');
            break;
        case 'year':
            $from = new DateTime(($today->format('Y') - 4) . '-01-01', app_timezone()); $expr = 'YEAR(occurred_at)';
            for ($y = (int)$from->format('Y'); $y <= (int)$today->format('Y'); $y++) $buckets[(string)$y] = (string)$y;
            break;
        default:                                                    // month (6) e months12 (12)
            $n = $period === 'months12' ? 12 : 6;
            $from = (new DateTime($today->format('Y-m-01'), app_timezone()))->modify('-' . ($n - 1) . ' months'); $expr = "DATE_FORMAT(occurred_at, '%Y-%m')";
            for ($d = clone $from; $d <= $today; $d->modify('+1 month')) $buckets[$d->format('Y-m')] = $monthNames[(int)$d->format('n') - 1];
    }
    $st = db()->prepare("SELECT $expr k, SUM(CASE WHEN type='income' THEN amount ELSE 0 END) i, SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) e
        FROM transactions WHERE user_id = ? AND status = 'paid' AND occurred_at >= ? GROUP BY k");
    $st->execute([$tid, $from->format('Y-m-d 00:00:00')]);
    $sum = [];
    foreach ($st->fetchAll() as $r) $sum[(string)$r['k']] = [(float)$r['i'], (float)$r['e']];
    return ['labels' => array_values($buckets), 'income' => array_map(fn($k) => $sum[$k][0] ?? 0.0, array_keys($buckets)), 'expense' => array_map(fn($k) => $sum[$k][1] ?? 0.0, array_keys($buckets))];
}

try {

    /* ======================= LER (GET) ======================= */
    if ($method === 'GET') {

        // Movimentos de caixa, do mais recente para o mais antigo (com o nome do cliente, se houver).
        if ($module === 'transactions') {
            [$limit, $offset] = page_limits(5000);
            $stmt = db()->prepare("SELECT t.id,t.type,t.description,t.category,t.amount,t.status,t.occurred_at,t.client_id,t.payment_method, c.name client_name
                FROM transactions t LEFT JOIN clients c ON c.id=t.client_id AND c.user_id=t.user_id
                WHERE t.user_id=? ORDER BY t.occurred_at DESC,t.id DESC LIMIT $limit OFFSET $offset");
            $stmt->execute([$tid]);
            $items = $stmt->fetchAll();
            $total = db()->prepare('SELECT COUNT(*) FROM transactions WHERE user_id=?');
            $total->execute([$tid]);
            json_response(['success' => true, 'items' => $items, 'total' => (int)$total->fetchColumn(), 'limit' => $limit, 'offset' => $offset]);
        }

        // Totais por dia/semana/mês/ano para os gráficos (calculados aqui, não no navegador).
        if ($module === 'series') {
            $period = in_array($_GET['period'] ?? '', ['day', 'week', 'month', 'months12', 'year'], true) ? $_GET['period'] : 'month';
            json_response(['success' => true, 'period' => $period, 'series' => money_series($tid, $period)]);
        }

        // Totais: só os movimentos REALIZADOS (pagos/recebidos) contam para o saldo.
        if ($module === 'summary') {
            $stmt = db()->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) income,
                COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) expense
                FROM transactions WHERE user_id=? AND status='paid'");
            $stmt->execute([$tid]);
            $r = $stmt->fetch();
            json_response(['success' => true, 'summary' => ['income' => (float)$r['income'], 'expense' => (float)$r['expense'],
                'balance' => (float)$r['income'] - (float)$r['expense']]]);
        }

        // Contas internas (caixa, banco, reserva, investimento) e respetivas metas.
        if ($module === 'accounts') {
            $stmt = db()->prepare('SELECT id,name,type,balance,target_amount FROM accounts WHERE user_id=? ORDER BY name');
            $stmt->execute([$tid]);
            json_response(['success' => true, 'items' => $stmt->fetchAll()]);
        }

        // Contas a pagar e a receber, por data de vencimento.
        if ($module === 'bills') {
            recurring_materialize($tid);                       // gera as contas das recorrentes que já vencem em breve
            $stmt = db()->prepare('SELECT f.id,f.direction,f.title,f.counterparty,f.amount,f.due_date,f.status,f.paid_at,f.client_id,c.name client_name
                FROM financial_documents f LEFT JOIN clients c ON c.id=f.client_id AND c.user_id=f.user_id
                WHERE f.user_id=? ORDER BY f.due_date ASC,f.id DESC LIMIT 3000');
            $stmt->execute([$tid]);
            json_response(['success' => true, 'items' => $stmt->fetchAll()]);
        }

        // Produtos do negócio (o "produto" depende do ramo: imóvel, viatura, serviço, viagem...).
        // "attributes" são os campos próprios do ramo, guardados em JSON.
        if ($module === 'products') {
            $stmt = db()->prepare("SELECT id,name,sku,category,brand,cost_price,sale_price,stock_quantity,minimum_stock,image_url,status,attributes,created_at,updated_at
                FROM products WHERE user_id=? AND status<>'archived' ORDER BY name LIMIT 3000");
            $stmt->execute([$tid]);
            $rows = $stmt->fetchAll();
            $vmap = stock_variants_map(db(), (int)$tid, array_column($rows, 'id'));          // tamanho/cor/quantidade de cada produto
            $items = array_map(function (array $row) use ($vmap) {
                $row['attributes'] = $row['attributes'] ? (json_decode($row['attributes'], true) ?: new stdClass()) : new stdClass();
                $row['variants'] = $vmap[(int)$row['id']] ?? [];
                return $row;
            }, $rows);
            json_response(['success' => true, 'items' => $items, 'summary' => stock_summary((int)$tid)]);
        }

        json_response(['success' => false, 'error' => 'Módulo desconhecido.'], 404);
    }

    /* ======================= ELIMINAR (DELETE) ======================= */
    if ($method === 'DELETE') {
        require_owner($user);              // o funcionário (entrada de dados) NÃO elimina: só o dono
        check_csrf();
        $tables = ['transactions' => 'transactions', 'bills' => 'financial_documents'];   // lista fixa de tabelas
        if (!isset($tables[$module]) || empty($_GET['id'])) json_response(['success' => false, 'error' => 'Pedido inválido.'], 400);
        // o nome da tabela vem da lista fixa acima; o id e o dono vão como parâmetros
        $stmt = db()->prepare("DELETE FROM {$tables[$module]} WHERE id=? AND user_id=?");
        $stmt->execute([(int)$_GET['id'], $tid]);
        if ($stmt->rowCount() > 0) attachments_purge($tid, $module === 'bills' ? 'bill' : 'transaction', (int)$_GET['id']);   // sem recibos órfãos no disco
        json_response(['success' => true, 'deleted' => $stmt->rowCount()]);
    }

    /* ======================= CRIAR / ALTERAR (POST) ======================= */
    if ($method !== 'POST') json_response(['success' => false, 'error' => 'Método não permitido.'], 405);
    check_csrf();
    $pdo = db();

    // Novo movimento de caixa (entrada ou saída), realizado ou previsto.
    if ($module === 'transactions') {
        $in = Validator::make($data)->enum('type', 'Tipo', ['income', 'expense'])->text('description', 'Descrição', 200)->text('category', 'Categoria', 80)
            ->money('amount', 'Valor')->datetime('occurred_at', 'Data')->enum('status', 'Estado', ['paid', 'planned'], 'paid')->enumOptional('payment_method', 'Método de pagamento', PAYMENT_METHODS)->id('account_id')->id('client_id')->orFail();
        if (!owned('accounts', $in['account_id'], $tid) || !owned('clients', $in['client_id'], $tid)) json_response(['success' => false, 'error' => 'Conta ou cliente inválido.'], 400);
        $stmt = $pdo->prepare('INSERT INTO transactions (user_id,account_id,client_id,type,description,category,amount,status,occurred_at,payment_method) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$tid, $in['account_id'], $in['client_id'], $in['type'], $in['description'], $in['category'], $in['amount'], $in['status'], $in['occurred_at'], $in['payment_method']]);
        json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    // Movimento previsto passa a realizado (passa a contar para o saldo).
    if ($module === 'transaction_confirm') {
        $stmt = $pdo->prepare("UPDATE transactions SET status='paid' WHERE id=? AND user_id=?");
        $stmt->execute([(int)($data['id'] ?? 0), $tid]);
        json_response(['success' => true, 'updated' => $stmt->rowCount()]);
    }

    // Pagar/receber uma conta: marca-a como paga E lança o movimento no caixa (tudo numa transação:
    // ou acontece tudo, ou nada).
    if ($module === 'bill_pay') {
        $stmt = $pdo->prepare('SELECT id,direction,title,amount,client_id,status FROM financial_documents WHERE id=? AND user_id=?');
        $stmt->execute([(int)($data['id'] ?? 0), $tid]);
        $bill = $stmt->fetch();
        if (!$bill) json_response(['success' => false, 'error' => 'Conta não encontrada.'], 404);
        if ($bill['status'] === 'paid') json_response(['success' => false, 'error' => 'Esta conta já foi paga.'], 409);
        $receivable = $bill['direction'] === 'receivable';
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE financial_documents SET status='paid',paid_at=? WHERE id=? AND user_id=?")->execute([$now, $bill['id'], $tid]);
        $pdo->prepare("INSERT INTO transactions (user_id,client_id,type,description,category,amount,status,occurred_at) VALUES (?,?,?,?,?,?,'paid',?)")
            ->execute([$tid, $bill['client_id'], $receivable ? 'income' : 'expense', $bill['title'],
                $receivable ? 'Contas a receber' : 'Contas a pagar', $bill['amount'], $now]);
        $pdo->commit();
        json_response(['success' => true]);
    }

    // Nova conta interna (caixa, banco, reserva ou investimento), com meta opcional.
    if ($module === 'accounts') {
        $in = Validator::make($data)->text('name', 'Nome da conta', 120)->enum('type', 'Tipo de conta', ['cash', 'bank', 'reserve', 'investment'])
            ->money('balance', 'Saldo', false, false, 99999999.99, 0.0)->money('target_amount', 'Meta', true, false)->orFail();
        $stmt = $pdo->prepare('INSERT INTO accounts (user_id,name,type,balance,target_amount) VALUES (?,?,?,?,?)');
        $stmt->execute([$tid, $in['name'], $in['type'], $in['balance'], $in['target_amount']]);
        json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    // Nova conta a pagar ou a receber (começa sempre "pendente").
    if ($module === 'bills') {
        $in = Validator::make($data)->enum('direction', 'Tipo', ['payable', 'receivable'])->text('title', 'Título', 160)->text('counterparty', 'Contraparte', 160, false, '')
            ->money('amount', 'Valor')->date('due_date', 'Data de vencimento')->id('client_id')->orFail();
        if (!owned('clients', $in['client_id'], $tid)) json_response(['success' => false, 'error' => 'Cliente inválido.'], 400);
        $stmt = $pdo->prepare('INSERT INTO financial_documents (user_id,client_id,direction,title,counterparty,amount,due_date,status) VALUES (?,?,?,?,?,?,?,?)');
        $stmt->execute([$tid, $in['client_id'], $in['direction'], $in['title'], $in['counterparty'], $in['amount'], $in['due_date'], 'pending']);
        json_response(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
    }

    // Novo produto (imóvel, viatura, serviço, viagem...). Os campos próprios do ramo vão em "attributes"; tamanho/cor/quantidade vão em "variants".
    if ($module === 'products') {
        try {
            $r = product_create($pdo, (int)$tid, (int)$user['id'], $data, clean_attributes($data['attributes'] ?? null));
        } catch (StockError $e) { json_response(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status); }
        audit('product_created', ['product_id' => $r['id']], $user);
        json_response(['success' => true, 'id' => $r['id']], 201);
    }

    // Editar um produto e as suas variações (o dono e o funcionário com permissão de estoque).
    if ($module === 'product_update') {
        try {
            $r = product_update($pdo, (int)$tid, (int)$user['id'], $data);
        } catch (StockError $e) { json_response(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status); }
        audit('product_updated', ['product_id' => $r['id']], $user);
        json_response(['success' => true, 'id' => $r['id']]);
    }

    // Apagar (arquivar) um produto ou uma variação: só o dono; o histórico das vendas mantém-se.
    if ($module === 'product_delete' || $module === 'variant_delete') {
        require_owner($user);
        try {
            if ($module === 'product_delete') { product_delete($pdo, (int)$tid, (int)($data['id'] ?? 0)); audit('product_deleted', ['product_id' => (int)$data['id']], $user); }
            else { $pid = variant_delete($pdo, (int)$tid, (int)($data['id'] ?? 0)); audit('variant_deleted', ['variant_id' => (int)$data['id'], 'product_id' => $pid], $user); }
        } catch (StockError $e) { json_response(['success' => false, 'error' => $e->getMessage()] + $e->extra, $e->status); }
        json_response(['success' => true]);
    }

    // Ajustar o stock de um produto (aumentar ou reduzir). A quantidade é o valor FINAL pretendido.
    // Tudo numa transação com o produto bloqueado: duas pessoas a mexer ao mesmo tempo nunca se sobrepõem em silêncio.
    // "expected" (opcional) é o valor que a pessoa estava a ver: se já mudou entretanto, devolve 409 com o valor atual.
    if ($module === 'product_stock') {
        $in = Validator::make($data)->int('id', 'Produto', 1, 2147483647)->int('stock_quantity', 'Quantidade', 0, 1000000)
            ->int('minimum_stock', 'Stock mínimo', 0, 1000000, false)->text('reason', 'Motivo', 180, false, '')->orFail();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT id,name,stock_quantity,minimum_stock FROM products WHERE id=? AND user_id=? AND status<>'archived' FOR UPDATE");
        $stmt->execute([$in['id'], $tid]);
        $row = $stmt->fetch();
        if (!$row) { $pdo->rollBack(); json_response(['success' => false, 'error' => 'Produto não encontrado.'], 404); }
        $before = (int)$row['stock_quantity'];
        if (isset($data['expected']) && is_numeric($data['expected']) && (int)$data['expected'] !== $before) {
            $pdo->rollBack();
            json_response(['success' => false, 'conflict' => true, 'current' => $before, 'error' => "O stock de «{$row['name']}» mudou entretanto (agora tem $before). Atualizámos o valor; confirma e tenta outra vez."], 409);
        }
        $min = $in['minimum_stock'] ?? (int)$row['minimum_stock'];
        // produtos com tamanhos/cores têm o estoque por variação: aqui só se ajusta o de produtos com UMA variação
        $vs = $pdo->prepare('SELECT id FROM product_variants WHERE product_id=? AND user_id=? FOR UPDATE'); $vs->execute([$in['id'], $tid]); $vids = $vs->fetchAll(PDO::FETCH_COLUMN);
        if (count($vids) > 1) { $pdo->rollBack(); json_response(['success' => false, 'error' => 'Este produto tem tamanhos/cores: edita o produto para alterar o estoque de cada variação.'], 409); }
        if ($vids) $pdo->prepare('UPDATE product_variants SET quantity=? WHERE id=? AND user_id=?')->execute([$in['stock_quantity'], (int)$vids[0], $tid]);
        $pdo->prepare('UPDATE products SET stock_quantity=?, minimum_stock=? WHERE id=? AND user_id=?')->execute([$in['stock_quantity'], $min, $in['id'], $tid]);
        $delta = $in['stock_quantity'] - $before;
        if ($delta !== 0) {
            $pdo->prepare("INSERT INTO stock_movements (user_id,product_id,variant_id,type,quantity,reason,occurred_at) VALUES (?,?,?,?,?,?,?)")
                ->execute([$tid, $in['id'], $vids ? (int)$vids[0] : null, 'adjustment', $delta, $in['reason'] !== '' ? $in['reason'] : 'Ajuste manual', $now]);
        }
        $pdo->commit();
        audit('stock_adjusted', ['product_id' => $in['id'], 'from' => $before, 'to' => $in['stock_quantity'], 'minimum' => $min], $user);
        $stmt = $pdo->prepare('SELECT id,name,sku,category,cost_price,sale_price,stock_quantity,minimum_stock,status,updated_at FROM products WHERE id=? AND user_id=?');
        $stmt->execute([$in['id'], $tid]);
        json_response(['success' => true, 'item' => $stmt->fetch(), 'previous' => $before, 'delta' => $delta, 'summary' => stock_summary((int)$tid)]);
    }

    // Atualizar os campos do produto sem o recriar (ex.: imóvel "Disponível" -> "Reservado").
    // Junta os campos novos aos que já existem; só mexe em produtos do próprio negócio.
    if ($module === 'product_attr') {
        $stmt = $pdo->prepare('SELECT attributes FROM products WHERE id=? AND user_id=?');
        $stmt->execute([(int)($data['id'] ?? 0), $tid]);
        $row = $stmt->fetch();
        if (!$row) json_response(['success' => false, 'error' => 'Produto não encontrado.'], 404);
        $current = $row['attributes'] ? (json_decode($row['attributes'], true) ?: []) : [];
        $merged  = clean_attributes(array_merge($current, is_array($data['attributes'] ?? null) ? $data['attributes'] : []));
        $pdo->prepare('UPDATE products SET attributes=? WHERE id=? AND user_id=?')->execute([$merged, (int)$data['id'], $tid]);
        json_response(['success' => true, 'attributes' => $merged ? json_decode($merged, true) : new stdClass()]);
    }

    json_response(['success' => false, 'error' => 'Módulo desconhecido.'], 404);

} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();     // desfaz tudo se algo correu mal a meio
    internal_error($error);                                          // detalhes só no log; o utilizador vê uma mensagem genérica
}
