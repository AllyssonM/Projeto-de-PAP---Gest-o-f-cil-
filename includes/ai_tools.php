<?php
declare(strict_types=1);

require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/env.php';

/* =========================================================
   CAMADA SEGURA DE CONSULTAS PARA O ASSISTENTE DE IA

   Regras que este ficheiro impõe (a IA não as consegue contornar):
   1. A IA nunca escreve SQL. Só pode chamar as ferramentas abaixo, por nome.
   2. Cada ferramenta tem um esquema fechado de parâmetros. Parâmetros
      desconhecidos (ex.: "user_id") são RECUSADOS, nunca ignorados.
   3. O dono dos dados (tenant_id) e as permissões vêm só da sessão (passados por quem
      chama), nunca dos parâmetros. Toda a consulta começa por "user_id = ?" com o id do
      DONO do negócio (o funcionário trabalha sobre os dados do patrão). A agenda é pessoal
      (id do próprio utilizador).
   3b. Cada ferramenta pertence a uma área (cashflow, accounts, clients, stock, calendar) e
      só é oferecida/executada se o utilizador tiver essa permissão (a mesma do painel).
   4. Valores do utilizador/da IA só entram como parâmetros preparados (PDO),
      nunca concatenados no SQL. Ordenações/agrupamentos usam listas fixas.
   5. Ligação à base de dados SEPARADA, só de leitura, com limite de tempo.
      Sem acesso à tabela `users` quando se usa o utilizador de leitura.
   6. Limites de linhas em todas as listas; todas as chamadas ficam em auditoria.
   ========================================================= */

// app_timezone() vem de config/app.php (carregado por includes/auth.php)

function ai_config(): array
{
    $file = __DIR__ . '/../config/ai.php';
    $cfg = is_file($file) ? (array)(require $file) : [];
    // Segredos (chave da IA, utilizador só de leitura): variáveis de ambiente ou config/ai.local.php (ignorado pelo Git) têm prioridade.
    $cfg = lumina_local_config('ai') + $cfg;
    foreach (['LUMINA_AI_API_KEY' => 'api_key', 'LUMINA_AI_DB_USER' => 'db_user', 'LUMINA_AI_DB_PASS' => 'db_pass'] as $env => $key) {
        if (($v = lumina_env($env)) !== null) { $cfg[$key] = $v; }
    }
    return $cfg + [
        'api_key' => '', 'model' => 'claude-sonnet-5-5', 'api_url' => 'https://api.anthropic.com/v1/messages',
        'max_tokens' => 1200, 'max_tool_rounds' => 6, 'history_messages' => 12, 'rate_limit_per_minute' => 12,
        'max_message_length' => 1000, 'timeout' => 45, 'db_user' => '', 'db_pass' => '',
    ];
}

/** Ligação de LEITURA, separada da ligação principal (nunca altera a sessão da aplicação). */
function ai_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) { return $pdo; }
    $c = ai_config();
    $user = trim((string)$c['db_user']) !== '' ? (string)$c['db_user'] : DB_USER;
    $pass = trim((string)$c['db_user']) !== '' ? (string)$c['db_pass'] : DB_PASS;
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    try { $pdo->exec('SET SESSION TRANSACTION READ ONLY'); } catch (Throwable $e) { /* melhor esforço */ }
    try { $pdo->exec('SET SESSION max_statement_time = 5'); }                       // MariaDB
    catch (Throwable $e) { try { $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000'); } catch (Throwable $e2) {} }   // MySQL
    return $pdo;
}

/* ---------------- utilitários de formatação ---------------- */

function ai_money($v): string { return lumina_lang() === 'en' ? '€' . number_format((float)$v, 2, '.', ',') : number_format((float)$v, 2, ',', '.') . ' €'; }
function ai_num($v): float { return round((float)$v, 2); }
function ai_date_pt(?string $ymd): ?string
{
    if (!$ymd) { return null; }
    $d = DateTime::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    return $d ? $d->format('d/m/Y') : null;
}
/** Texto vindo da base de dados: sem caracteres de controlo e com tamanho limitado (é DADOS, nunca instruções). */
function ai_text($s, int $max = 160): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$s) ?? '';
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}
/** Escapa % _ e | para LIKE (usar sempre com ESCAPE '|'). */
function ai_like(string $s): string { return '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $s) . '%'; }
function ai_today(): string { return (new DateTimeImmutable('now', app_timezone()))->format('Y-m-d'); }

/* ---------------- definição das ferramentas ---------------- */

const AI_LIMIT_DEFAULT = 20;
const AI_LIMIT_MAX = 50;

function ai_p_date(string $d): array { return ['type' => 'date', 'desc' => $d . ' (formato AAAA-MM-DD)']; }
function ai_p_enum(array $v, string $d): array { return ['type' => 'enum', 'values' => $v, 'desc' => $d]; }
function ai_p_str(string $d, int $max = 80): array { return ['type' => 'string', 'max' => $max, 'desc' => $d]; }
function ai_p_limit(): array { return ['type' => 'int', 'min' => 1, 'max' => AI_LIMIT_MAX, 'desc' => 'Máximo de linhas (1 a ' . AI_LIMIT_MAX . ', por omissão ' . AI_LIMIT_DEFAULT . ')']; }

function ai_tool_specs(): array
{
    return [
        'financial_summary' => [
            'perm' => 'cashflow',
            'desc' => 'Resumo dos movimentos de caixa: total de entradas (receitas/vendas), de saídas (despesas) e saldo, opcionalmente num intervalo de datas.',
            'params' => ['date_from' => ai_p_date('Data inicial'), 'date_to' => ai_p_date('Data final')],
        ],
        'list_transactions' => [
            'perm' => 'cashflow',
            'desc' => 'Movimentos de caixa (entradas e saídas). Serve para vendas, receitas, despesas e gastos. Devolve sempre o total e a contagem de TODOS os movimentos que correspondem ao filtro, mesmo que só liste alguns. Pode agrupar por categoria, mês, cliente ou tipo.',
            'params' => [
                'type' => ai_p_enum(['income', 'expense'], 'income = entrada/venda/receita; expense = saída/despesa'),
                'status' => ai_p_enum(['paid', 'planned', 'all'], 'paid = realizados (por omissão, os que contam para o saldo); planned = previstos; all = todos'),
                'date_from' => ai_p_date('Data inicial'), 'date_to' => ai_p_date('Data final'),
                'category' => ai_p_str('Parte do nome da categoria'), 'client_name' => ai_p_str('Parte do nome do cliente'),
                'search' => ai_p_str('Texto na descrição'),
                'group_by' => ai_p_enum(['category', 'month', 'client', 'type'], 'Agrupar os totais'),
                'order' => ai_p_enum(['newest', 'oldest', 'largest'], 'Ordem da lista (por omissão: newest)'),
                'limit' => ai_p_limit(),
            ],
        ],
        'list_bills' => [
            'perm' => 'accounts',
            'desc' => 'Contas a pagar (payable, ex.: fornecedores) e a receber (receivable, ex.: clientes), também chamadas faturas/valores em aberto. Estado: pending = em aberto, overdue = em aberto e já vencidas, paid = pagas. Devolve o total e a contagem de TODAS as contas que correspondem ao filtro. Pode agrupar por entidade (fornecedor/cliente), mês, estado ou direção.',
            'params' => [
                'direction' => ai_p_enum(['payable', 'receivable'], 'payable = a pagar (devemos); receivable = a receber (nos devem)'),
                'status' => ai_p_enum(['pending', 'overdue', 'paid', 'all'], 'Por omissão: pending'),
                'due_from' => ai_p_date('Vencimento a partir de'), 'due_to' => ai_p_date('Vencimento até'),
                'entity' => ai_p_str('Parte do nome do fornecedor, cliente ou entidade'),
                'group_by' => ai_p_enum(['entity', 'month', 'status', 'direction'], 'Agrupar os totais'),
                'limit' => ai_p_limit(),
            ],
        ],
        'list_products' => [
            'perm' => 'stock',
            'desc' => 'Produtos do negócio (conforme o ramo: imóveis, viaturas, serviços, viagens, peças...), com preços, estoque e os campos próprios do ramo (em "attributes": tipologia, área, marca, ano, estado...). Permite ver só os produtos com stock baixo (quantidade <= mínimo).',
            'params' => [
                'search' => ai_p_str('Parte do nome, SKU ou dos campos do ramo (ex.: T2, Leiria, Renault, Reservado)'), 'category' => ai_p_str('Parte do nome da categoria'),
                'low_stock_only' => ['type' => 'bool', 'desc' => 'true = produtos que precisam de atenção: com stock baixo OU sem stock (quantidade <= mínimo)'],
                'stock_status' => ai_p_enum(['low', 'out', 'ok', 'all'], 'low = stock baixo (quantidade > 0 e <= mínimo); out = SEM stock (quantidade 0); ok = acima do mínimo; all = todos (por omissão). Os números coincidem com o cartão «Estado do estoque» do painel'),
                'order' => ai_p_enum(['name', 'stock_asc', 'stock_desc', 'value_desc'], 'Ordem (por omissão: name)'),
                'limit' => ai_p_limit(),
            ],
        ],
        'list_clients' => [
            'perm' => 'clients',
            'desc' => 'Clientes, com o valor em aberto a receber, o valor vencido e o total de entradas de cada um.',
            'params' => [
                'search' => ai_p_str('Parte do nome ou e-mail'),
                'order' => ai_p_enum(['name', 'open_desc', 'overdue_desc', 'income_desc'], 'Ordem (por omissão: name)'),
                'limit' => ai_p_limit(),
            ],
        ],
        'list_accounts' => [
            'perm' => 'accounts',
            'desc' => 'Contas internas (caixa, banco, reserva, investimento) e os seus saldos.',
            'params' => [],
        ],
        'stock_movements_summary' => [
            'perm' => 'stock',
            'desc' => 'Movimentos de stock agrupados por produto e tipo (ex.: saídas). Só existe se o utilizador registou movimentos de stock. Útil para produtos com maior saída.',
            'params' => [
                'date_from' => ai_p_date('Data inicial'), 'date_to' => ai_p_date('Data final'),
                'type' => ai_p_str('Tipo de movimento (texto exato)', 30), 'limit' => ai_p_limit(),
            ],
        ],
        'list_calendar_events' => [
            'perm' => 'calendar',
            'desc' => 'Eventos/reuniões do calendário. Por omissão, de hoje até daqui a 30 dias.',
            'params' => ['date_from' => ai_p_date('Data inicial'), 'date_to' => ai_p_date('Data final'), 'limit' => ai_p_limit()],
        ],
    ];
}

/**
 * Permissões: que ferramentas este utilizador pode usar. O dono tem todas; um funcionário só as das
 * áreas que o dono lhe deu (TEAM_MODULES em includes/auth.php). O chat nunca aumenta privilégios.
 * Nenhum papel dá acesso a dados de outro negócio através do chat.
 */
function ai_allowed_tools(array $user): array
{
    $perms = (array)($user['permissions'] ?? []);
    $allowed = [];
    foreach (ai_tool_specs() as $name => $spec) {
        if (in_array($spec['perm'], $perms, true)) { $allowed[] = $name; }     // mesma regra do painel: can($user, módulo)
    }
    return $allowed;
}

/** Definições no formato da API de IA (nome, descrição e JSON Schema fechado). */
function ai_tool_definitions(array $user): array
{
    $specs = ai_tool_specs(); $out = [];
    foreach (ai_allowed_tools($user) as $name) {
        $props = [];
        foreach ($specs[$name]['params'] as $p => $def) {
            $prop = ['description' => $def['desc']];
            switch ($def['type']) {
                case 'date':   $prop += ['type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$']; break;
                case 'enum':   $prop += ['type' => 'string', 'enum' => $def['values']]; break;
                case 'int':    $prop += ['type' => 'integer', 'minimum' => $def['min'], 'maximum' => $def['max']]; break;
                case 'bool':   $prop += ['type' => 'boolean']; break;
                default:       $prop += ['type' => 'string', 'maxLength' => $def['max']];
            }
            $props[$p] = $prop;
        }
        $out[] = ['name' => $name, 'description' => $specs[$name]['desc'],
                  'input_schema' => ['type' => 'object', 'properties' => $props === [] ? new stdClass() : $props, 'additionalProperties' => false]];
    }
    return $out;
}

/** Valida os argumentos contra o esquema. Devolve [argumentos limpos, erro|null]. Recusa parâmetros desconhecidos. */
function ai_validate_args(array $params, $args): array
{
    if ($args === null || $args === '') { $args = []; }
    if (!is_array($args)) { return [[], 'Os argumentos têm de ser um objeto.']; }
    foreach (array_keys($args) as $k) {
        if (!is_string($k) || !array_key_exists($k, $params)) {
            return [[], 'Parâmetro não permitido: ' . ai_text((string)$k, 40) . '.'];
        }
    }
    $clean = [];
    foreach ($params as $name => $def) {
        if (!array_key_exists($name, $args) || $args[$name] === null || $args[$name] === '') { continue; }
        $v = $args[$name];
        switch ($def['type']) {
            case 'date':
                $d = is_string($v) ? DateTime::createFromFormat('!Y-m-d', $v) : false;
                if (!$d || $d->format('Y-m-d') !== $v) { return [[], "«{$name}» tem de ser uma data AAAA-MM-DD."]; }
                $clean[$name] = $v; break;
            case 'enum':
                if (!is_string($v) || !in_array($v, $def['values'], true)) { return [[], "«{$name}» tem de ser um de: " . implode(', ', $def['values']) . '.']; }
                $clean[$name] = $v; break;
            case 'int':
                if (!is_int($v) && !(is_string($v) && ctype_digit($v)) && !(is_float($v) && floor($v) == $v)) { return [[], "«{$name}» tem de ser um número inteiro."]; }
                $clean[$name] = max($def['min'], min($def['max'], (int)$v)); break;        // limite sempre aplicado
            case 'bool':
                if (!is_bool($v)) { return [[], "«{$name}» tem de ser verdadeiro ou falso."]; }
                $clean[$name] = $v; break;
            default:
                if (!is_string($v)) { return [[], "«{$name}» tem de ser texto."]; }
                $t = ai_text($v, (int)$def['max']);
                if ($t !== '') { $clean[$name] = $t; }
        }
    }
    if (isset($clean['date_from'], $clean['date_to']) && $clean['date_to'] < $clean['date_from']) {
        return [[], 'A data final não pode ser anterior à inicial.'];
    }
    return [$clean, null];
}

/* ---------------- execução ---------------- */

/**
 * Executa uma ferramenta em nome do utilizador $uid (que vem SEMPRE da sessão, nunca da IA).
 * Nunca lança exceções. Devolve ['ok'=>true, 'source'=>..., ...dados] ou ['ok'=>false, 'error'=>...].
 */
function ai_run_tool(array $user, string $name, $args, ?int $conversationId = null): array
{
    $uid = (int)($user['id'] ?? 0);                              // quem fez o pedido (agenda pessoal, auditoria)
    $tenant = (int)($user['tenant_id'] ?? 0);                    // dono dos dados do negócio
    $started = microtime(true);
    $status = 'ok'; $rows = null; $error = null; $reason = null; $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';

    try {
        // Defesa em profundidade: o utilizador e o negócio da chamada têm de ser os da sessão ativa.
        if ($uid <= 0 || $tenant <= 0
            || (isset($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] !== $uid)
            || (isset($_SESSION['user']['tenant_id']) && (int)$_SESSION['user']['tenant_id'] !== $tenant)) {
            throw new AiBlocked('Utilizador não autenticado.');
        }
        $specs = ai_tool_specs();
        if (!isset($specs[$name])) {
            throw new AiBlocked('Ferramenta não disponível.');
        }
        if (!in_array($name, ai_allowed_tools($user), true)) {
            $reason = 'permission';
            throw new AiBlocked('Sem permissão para esta área.');
        }
        [$clean, $err] = ai_validate_args($specs[$name]['params'], $args);
        if ($err) { throw new AiBlocked($err); }

        $ctx = ['user' => $uid, 'tenant' => $tenant, 'perms' => (array)($user['permissions'] ?? [])];
        $result = ('ai_tool_' . $name)($ctx, $clean);
        $rows = (int)($result['_rows'] ?? 0); unset($result['_rows']);
        $out = ['ok' => true] + $result;
    } catch (AiBlocked $e) {
        $status = 'blocked'; $error = $e->getMessage();
        $out = ['ok' => false, 'error' => $error] + ($reason ? ['reason' => $reason] : []);
    } catch (Throwable $e) {
        $status = 'error'; $error = mb_substr($e->getMessage(), 0, 240);
        $out = ['ok' => false, 'error' => 'Não foi possível consultar esses dados agora.'];
    }

    try {                                                     // auditoria (ligação principal, que permite escrita)
        db()->prepare('INSERT INTO ai_audit_log (user_id, tenant_id, conversation_id, tool_name, arguments, status, row_count, error, duration_ms) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$uid ?: 0, $tenant ?: null, $conversationId, mb_substr($name, 0, 60), mb_substr($argsJson, 0, 2000), $status, $rows, $error ? mb_substr($error, 0, 250) : null, (int)round((microtime(true) - $started) * 1000)]);
        if (random_int(1, 200) === 1) {                          // limpeza ocasional: a auditoria não cresce sem limite
            db()->exec('DELETE FROM ai_audit_log WHERE created_at < (NOW() - INTERVAL 180 DAY)');
        }
    } catch (Throwable $e) { /* a auditoria nunca deve impedir a resposta */ }

    return $out;
}

final class AiBlocked extends RuntimeException {}

/* ---------------- filtros auxiliares (sempre com parâmetros preparados) ---------------- */

function ai_range(array $a, string $col, string $fromKey = 'date_from', string $toKey = 'date_to', bool $dateTime = true): array
{
    $sql = ''; $p = [];
    if (isset($a[$fromKey])) { $sql .= " AND {$col} >= ?"; $p[] = $a[$fromKey] . ($dateTime ? ' 00:00:00' : ''); }
    if (isset($a[$toKey])) {
        if ($dateTime) { $sql .= " AND {$col} < ?"; $p[] = (new DateTimeImmutable($a[$toKey]))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
        else { $sql .= " AND {$col} <= ?"; $p[] = $a[$toKey]; }
    }
    return [$sql, $p];
}
function ai_fetch(string $sql, array $params): array
{
    $st = ai_db()->prepare($sql); $st->execute($params); return $st->fetchAll();
}
function ai_fetch_one(string $sql, array $params): array { return ai_fetch($sql, $params)[0] ?? []; }

/** Campos próprios do ramo (JSON de products.attributes) como pares texto -> texto, já limpos (são DADOS, nunca instruções). */
function ai_attributes($json): array
{
    $raw = $json ? json_decode((string)$json, true) : [];
    if (!is_array($raw)) { return []; }
    $out = [];
    foreach (array_slice($raw, 0, 12, true) as $k => $v) {
        if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,29}$/', $k) && is_scalar($v)) { $out[$k] = ai_text($v, 120); }
    }
    return $out;
}

/* ---------------- ferramentas ---------------- */

function ai_tool_financial_summary(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    [$rs, $rp] = ai_range($a, 'occurred_at');
    // Como no painel: só os movimentos REALIZADOS (status 'paid') contam para entradas, saídas e saldo.
    $r = ai_fetch_one("SELECT COALESCE(SUM(CASE WHEN status='paid' AND type='income' THEN amount END),0) inc,
                              COALESCE(SUM(CASE WHEN status='paid' AND type='expense' THEN amount END),0) exp,
                              COALESCE(SUM(CASE WHEN status='planned' AND type='income' THEN amount END),0) pinc,
                              COALESCE(SUM(CASE WHEN status='planned' AND type='expense' THEN amount END),0) pexp,
                              COALESCE(SUM(status='paid'),0) n, COALESCE(SUM(status='planned'),0) pn
                       FROM transactions WHERE user_id = ?{$rs}", [$uid, ...$rp]);
    $inc = ai_num($r['inc'] ?? 0); $exp = ai_num($r['exp'] ?? 0); $pinc = ai_num($r['pinc'] ?? 0); $pexp = ai_num($r['pexp'] ?? 0);
    return ['source' => L('Movimentos de caixa'), 'period' => [$a['date_from'] ?? null, $a['date_to'] ?? null],
            'note' => 'Entradas, saídas e saldo contam só os movimentos realizados. Os previstos aparecem à parte.',
            'income_total' => $inc, 'income_formatted' => ai_money($inc), 'expense_total' => $exp, 'expense_formatted' => ai_money($exp),
            'balance' => round($inc - $exp, 2), 'balance_formatted' => ai_money($inc - $exp), 'movements_count' => (int)($r['n'] ?? 0),
            'planned_income_formatted' => ai_money($pinc), 'planned_expense_formatted' => ai_money($pexp), 'planned_count' => (int)($r['pn'] ?? 0), '_rows' => 1];
}

function ai_tool_list_transactions(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $where = 't.user_id = ?'; $p = [$uid];
    if (isset($a['type'])) { $where .= ' AND t.type = ?'; $p[] = $a['type']; }
    $tstatus = $a['status'] ?? 'paid';                                   // por omissão: só realizados (como o painel)
    if ($tstatus !== 'all') { $where .= ' AND t.status = ?'; $p[] = $tstatus; }
    [$rs, $rp] = ai_range($a, 't.occurred_at'); $where .= $rs; array_push($p, ...$rp);
    if (isset($a['category']))    { $where .= " AND t.category LIKE ? ESCAPE '|'"; $p[] = ai_like($a['category']); }
    if (isset($a['client_name'])) { $where .= " AND c.name LIKE ? ESCAPE '|'"; $p[] = ai_like($a['client_name']); }
    if (isset($a['search']))      { $where .= " AND t.description LIKE ? ESCAPE '|'"; $p[] = ai_like($a['search']); }
    $from = 'transactions t LEFT JOIN clients c ON c.id = t.client_id AND c.user_id = t.user_id';

    $tot = ai_fetch_one("SELECT COUNT(*) n, COALESCE(SUM(t.amount),0) total FROM {$from} WHERE {$where}", $p);
    $out = ['source' => L('Movimentos de caixa'), 'status_filter' => $tstatus, 'matching_count' => (int)$tot['n'], 'total_amount' => ai_num($tot['total']), 'total_formatted' => ai_money($tot['total'])];

    $groups = ['category' => 't.category', 'month' => "DATE_FORMAT(t.occurred_at,'%Y-%m')", 'client' => "COALESCE(c.name,'(sem cliente)')", 'type' => 't.type'];
    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    if (isset($a['group_by'])) {
        $g = $groups[$a['group_by']];                         // expressão vinda de uma lista fixa
        $rows = ai_fetch("SELECT {$g} label, COUNT(*) n, SUM(t.amount) total FROM {$from} WHERE {$where} GROUP BY label ORDER BY total DESC LIMIT {$limit}", $p);
        $out['groups'] = array_map(fn($r) => ['label' => ai_text($r['label'], 80), 'count' => (int)$r['n'], 'total' => ai_num($r['total']), 'total_formatted' => ai_money($r['total'])], $rows);
        $out['_rows'] = count($rows); return $out;
    }
    $orders = ['newest' => 't.occurred_at DESC, t.id DESC', 'oldest' => 't.occurred_at ASC, t.id ASC', 'largest' => 't.amount DESC, t.id DESC'];
    $rows = ai_fetch("SELECT t.type, t.status, t.description, t.category, t.amount, t.occurred_at, c.name client FROM {$from} WHERE {$where} ORDER BY " . $orders[$a['order'] ?? 'newest'] . " LIMIT {$limit}", $p);
    $out['items'] = array_map(fn($r) => ['type' => $r['type'], 'status' => $r['status'], 'description' => ai_text($r['description']), 'category' => ai_text($r['category'], 80),
        'client' => $r['client'] ? ai_text($r['client'], 80) : null, 'amount' => ai_num($r['amount']), 'amount_formatted' => ai_money($r['amount']), 'date' => ai_date_pt($r['occurred_at'])], $rows);
    $out['returned'] = count($rows); $out['_rows'] = count($rows);
    return $out;
}

function ai_tool_list_bills(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $today = ai_today();
    $where = 'f.user_id = ?'; $p = [$uid];
    if (isset($a['direction'])) { $where .= ' AND f.direction = ?'; $p[] = $a['direction']; }
    $status = $a['status'] ?? 'pending';
    if ($status === 'pending')      { $where .= " AND f.status = 'pending'"; }
    elseif ($status === 'paid')     { $where .= " AND f.status = 'paid'"; }
    elseif ($status === 'overdue')  { $where .= " AND f.status = 'pending' AND f.due_date < ?"; $p[] = $today; }
    else                            { $where .= " AND f.status <> 'cancelled'"; }              // 'all': tudo menos as canceladas
    [$rs, $rp] = ai_range($a, 'f.due_date', 'due_from', 'due_to', false); $where .= $rs; array_push($p, ...$rp);
    $label = "COALESCE(NULLIF(f.counterparty,''), c.name, '(sem entidade)')";
    if (isset($a['entity'])) { $where .= " AND {$label} LIKE ? ESCAPE '|'"; $p[] = ai_like($a['entity']); }
    $from = 'financial_documents f LEFT JOIN clients c ON c.id = f.client_id AND c.user_id = f.user_id';

    $tot = ai_fetch_one("SELECT COUNT(*) n, COALESCE(SUM(f.amount),0) total, COALESCE(SUM(CASE WHEN f.status='pending' AND f.due_date < ? THEN f.amount END),0) overdue_total, COALESCE(SUM(CASE WHEN f.status='pending' AND f.due_date < ? THEN 1 END),0) overdue_n FROM {$from} WHERE {$where}", [$today, $today, ...$p]);
    $out = ['source' => L('Contas a pagar e a receber'), 'today' => ai_date_pt($today), 'status_filter' => $status,
            'matching_count' => (int)$tot['n'], 'total_amount' => ai_num($tot['total']), 'total_formatted' => ai_money($tot['total']),
            'overdue_count' => (int)$tot['overdue_n'], 'overdue_amount' => ai_num($tot['overdue_total']), 'overdue_formatted' => ai_money($tot['overdue_total'])];

    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    if (isset($a['group_by'])) {
        $groups = ['entity' => $label, 'month' => "DATE_FORMAT(f.due_date,'%Y-%m')", 'status' => 'f.status', 'direction' => 'f.direction'];
        $g = $groups[$a['group_by']];
        $rows = ai_fetch("SELECT {$g} label, COUNT(*) n, SUM(f.amount) total, SUM(CASE WHEN f.status='pending' AND f.due_date < ? THEN f.amount ELSE 0 END) overdue FROM {$from} WHERE {$where} GROUP BY label ORDER BY total DESC LIMIT {$limit}", [$today, ...$p]);
        $out['groups'] = array_map(fn($r) => ['label' => ai_text($r['label'], 80), 'count' => (int)$r['n'], 'total' => ai_num($r['total']), 'total_formatted' => ai_money($r['total']),
            'overdue' => ai_num($r['overdue']), 'overdue_formatted' => ai_money($r['overdue'])], $rows);
        $out['_rows'] = count($rows); return $out;
    }
    $rows = ai_fetch("SELECT f.direction, f.title, {$label} entity, f.amount, f.due_date, f.status FROM {$from} WHERE {$where} ORDER BY f.due_date ASC, f.id ASC LIMIT {$limit}", $p);
    $todayDt = new DateTimeImmutable($today);
    $out['items'] = array_map(function ($r) use ($today, $todayDt) {
        $overdue = $r['status'] === 'pending' && $r['due_date'] < $today;
        return ['direction' => $r['direction'], 'title' => ai_text($r['title']), 'entity' => ai_text($r['entity'], 80), 'amount' => ai_num($r['amount']), 'amount_formatted' => ai_money($r['amount']),
            'due_date' => ai_date_pt($r['due_date']), 'status' => $r['status'], 'overdue' => $overdue,
            'days_overdue' => $overdue ? (int)$todayDt->diff(new DateTimeImmutable($r['due_date']))->days : 0];
    }, $rows);
    $out['returned'] = count($rows); $out['_rows'] = count($rows);
    return $out;
}

function ai_tool_list_products(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $where = "p.user_id = ? AND p.status <> 'archived'"; $p = [$uid];
    if (isset($a['search']))   { $where .= " AND (p.name LIKE ? ESCAPE '|' OR p.sku LIKE ? ESCAPE '|' OR p.attributes LIKE ? ESCAPE '|')"; $p[] = ai_like($a['search']); $p[] = ai_like($a['search']); $p[] = ai_like($a['search']); }
    if (isset($a['category'])) { $where .= " AND p.category LIKE ? ESCAPE '|'"; $p[] = ai_like($a['category']); }
    if (!empty($a['low_stock_only'])) { $where .= ' AND p.stock_quantity <= p.minimum_stock'; }
    $ss = $a['stock_status'] ?? 'all';
    if ($ss === 'low') { $where .= ' AND p.stock_quantity > 0 AND p.stock_quantity <= p.minimum_stock'; }
    elseif ($ss === 'out') { $where .= ' AND p.stock_quantity <= 0'; }
    elseif ($ss === 'ok') { $where .= ' AND p.stock_quantity > p.minimum_stock'; }
    $tot = ai_fetch_one("SELECT COUNT(*) n, COALESCE(SUM(p.stock_quantity),0) units, COALESCE(SUM(p.stock_quantity*p.cost_price),0) cost_value, COALESCE(SUM(p.stock_quantity*p.sale_price),0) sale_value, COALESCE(SUM(CASE WHEN p.stock_quantity > 0 AND p.stock_quantity <= p.minimum_stock THEN 1 END),0) low, COALESCE(SUM(CASE WHEN p.stock_quantity <= 0 THEN 1 END),0) out_n FROM products p WHERE {$where}", $p);
    $orders = ['name' => 'p.name ASC', 'stock_asc' => 'p.stock_quantity ASC, p.name ASC', 'stock_desc' => 'p.stock_quantity DESC, p.name ASC', 'value_desc' => '(p.stock_quantity*p.cost_price) DESC'];
    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    $rows = ai_fetch("SELECT p.name, p.sku, p.category, p.stock_quantity, p.minimum_stock, p.cost_price, p.sale_price, p.attributes FROM products p WHERE {$where} ORDER BY " . $orders[$a['order'] ?? 'name'] . " LIMIT {$limit}", $p);
    return ['source' => L('Produtos e stock'), 'matching_count' => (int)$tot['n'], 'total_units' => (int)$tot['units'], 'low_stock_count' => (int)$tot['low'], 'out_of_stock_count' => (int)$tot['out_n'], 'note' => 'low_stock_count = stock baixo (>0 e <= mínimo); out_of_stock_count = sem stock; ambos só dentro do filtro pedido',
        'stock_cost_value' => ai_num($tot['cost_value']), 'stock_cost_formatted' => ai_money($tot['cost_value']), 'stock_sale_value' => ai_num($tot['sale_value']), 'stock_sale_formatted' => ai_money($tot['sale_value']),
        'items' => array_map(fn($r) => ['name' => ai_text($r['name'], 100), 'sku' => $r['sku'] ? ai_text($r['sku'], 40) : null, 'category' => $r['category'] ? ai_text($r['category'], 60) : null,
            'stock_quantity' => (int)$r['stock_quantity'], 'minimum_stock' => (int)$r['minimum_stock'], 'stock_status' => (int)$r['stock_quantity'] <= 0 ? 'out' : ((int)$r['stock_quantity'] <= (int)$r['minimum_stock'] ? 'low' : 'ok'), 'low_stock' => (int)$r['stock_quantity'] <= (int)$r['minimum_stock'],
            'cost_price_formatted' => ai_money($r['cost_price']), 'sale_price_formatted' => ai_money($r['sale_price']),
            'attributes' => ai_attributes($r['attributes'])], $rows),
        'returned' => count($rows), '_rows' => count($rows)];
}

function ai_tool_list_clients(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $today = ai_today();
    $where = 'c.user_id = ?'; $p = [$uid];
    if (isset($a['search'])) { $where .= " AND (c.name LIKE ? ESCAPE '|' OR c.email LIKE ? ESCAPE '|')"; $p[] = ai_like($a['search']); $p[] = ai_like($a['search']); }
    $seeDebts = in_array('accounts', $ctx['perms'], true);              // contas a receber
    $seeIncome = in_array('cashflow', $ctx['perms'], true);             // entradas de caixa
    $orders = ['name' => 'c.name ASC'] + ($seeDebts ? ['open_desc' => 'open_receivable DESC', 'overdue_desc' => 'overdue_receivable DESC'] : []) + ($seeIncome ? ['income_desc' => 'income_total DESC'] : []);
    if (isset($a['order']) && !isset($orders[$a['order']])) { throw new AiBlocked('Sem permissão para ordenar por valores financeiros.'); }
    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    $n = ai_fetch_one("SELECT COUNT(*) n FROM clients c WHERE {$where}", $p);
    // cada subconsulta repete user_id = c.user_id (nunca junta dados de outro utilizador)
    $rows = ai_fetch("SELECT c.name, c.email, c.phone, c.category, c.status,
        (SELECT COALESCE(SUM(f.amount),0) FROM financial_documents f WHERE f.user_id = c.user_id AND f.client_id = c.id AND f.direction='receivable' AND f.status='pending') open_receivable,
        (SELECT COALESCE(SUM(f.amount),0) FROM financial_documents f WHERE f.user_id = c.user_id AND f.client_id = c.id AND f.direction='receivable' AND f.status='pending' AND f.due_date < ?) overdue_receivable,
        (SELECT COALESCE(SUM(t.amount),0) FROM transactions t WHERE t.user_id = c.user_id AND t.client_id = c.id AND t.type='income' AND t.status='paid') income_total
        FROM clients c WHERE {$where} ORDER BY " . $orders[$a['order'] ?? 'name'] . " LIMIT {$limit}", [$today, ...$p]);
    return ['source' => L('Clientes'), 'matching_count' => (int)$n['n'],
        'note' => $seeDebts || $seeIncome ? 'Os valores a receber por cliente só incluem contas associadas a esse cliente.' : 'Este utilizador não tem acesso aos valores financeiros dos clientes.',
        'items' => array_map(function ($r) use ($seeDebts, $seeIncome) {
            $item = ['name' => ai_text($r['name'], 100), 'email' => $r['email'] ? ai_text($r['email'], 80) : null, 'phone' => $r['phone'] ? ai_text($r['phone'], 30) : null,
                     'category' => $r['category'] ? ai_text($r['category'], 60) : null, 'status' => $r['status']];
            if ($seeDebts) { $item += ['open_receivable_formatted' => ai_money($r['open_receivable']), 'overdue_receivable_formatted' => ai_money($r['overdue_receivable']),
                                       'open_receivable' => ai_num($r['open_receivable']), 'overdue_receivable' => ai_num($r['overdue_receivable'])]; }
            if ($seeIncome) { $item += ['income_total_formatted' => ai_money($r['income_total'])]; }
            return $item;                                           // sem permissão, os valores financeiros nunca chegam à IA
        }, $rows),
        'returned' => count($rows), '_rows' => count($rows)];
}

function ai_tool_list_accounts(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $rows = ai_fetch('SELECT name, type, balance, target_amount FROM accounts WHERE user_id = ? ORDER BY name LIMIT ' . AI_LIMIT_MAX, [$uid]);
    $total = array_sum(array_map(fn($r) => (float)$r['balance'], $rows));
    return ['source' => L('Contas internas'), 'total_balance' => ai_num($total), 'total_formatted' => ai_money($total),
        'items' => array_map(fn($r) => ['name' => ai_text($r['name'], 80), 'type' => $r['type'], 'balance' => ai_num($r['balance']), 'balance_formatted' => ai_money($r['balance']),
            'target_formatted' => $r['target_amount'] !== null ? ai_money($r['target_amount']) : null], $rows), '_rows' => count($rows)];
}

function ai_tool_stock_movements_summary(array $ctx, array $a): array
{
    $uid = $ctx['tenant'];                      // dados do negócio: do dono (mesmo quando é um funcionário a perguntar)
    $where = 'm.user_id = ?'; $p = [$uid];
    [$rs, $rp] = ai_range($a, 'm.occurred_at'); $where .= $rs; array_push($p, ...$rp);
    if (isset($a['type'])) { $where .= ' AND m.type = ?'; $p[] = $a['type']; }
    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    $rows = ai_fetch("SELECT p.name, m.type, SUM(m.quantity) qty, COUNT(*) n FROM stock_movements m JOIN products p ON p.id = m.product_id AND p.user_id = m.user_id WHERE {$where} GROUP BY p.id, p.name, m.type ORDER BY qty DESC LIMIT {$limit}", $p);
    $types = ai_fetch('SELECT DISTINCT type FROM stock_movements WHERE user_id = ? LIMIT 20', [$uid]);
    return ['source' => L('Movimentos de stock'), 'movement_types_found' => array_map(fn($r) => ai_text($r['type'], 30), $types),
        'note' => $rows ? null : 'Não existem movimentos de stock registados para este filtro.',
        'items' => array_map(fn($r) => ['product' => ai_text($r['name'], 100), 'type' => ai_text($r['type'], 30), 'quantity' => (int)$r['qty'], 'movements' => (int)$r['n']], $rows), '_rows' => count($rows)];
}

function ai_tool_list_calendar_events(array $ctx, array $a): array
{
    $uid = $ctx['user'];                        // a agenda é pessoal: só os eventos do próprio utilizador
    $today = ai_today();
    $from = $a['date_from'] ?? $today;
    $to = $a['date_to'] ?? (new DateTimeImmutable($from))->modify('+30 days')->format('Y-m-d');
    $limit = (int)($a['limit'] ?? AI_LIMIT_DEFAULT);
    $rows = ai_fetch("SELECT title, description, event_date, start_time, end_time, location FROM calendar_events WHERE user_id = ? AND event_date BETWEEN ? AND ? ORDER BY event_date, start_time LIMIT {$limit}", [$uid, $from, $to]);
    return ['source' => L('Calendário'), 'period' => [ai_date_pt($from), ai_date_pt($to)],
        'items' => array_map(fn($r) => ['title' => ai_text($r['title']), 'date' => ai_date_pt($r['event_date']), 'start' => substr((string)$r['start_time'], 0, 5), 'end' => substr((string)$r['end_time'], 0, 5),
            'location' => $r['location'] ? ai_text($r['location'], 80) : null], $rows), '_rows' => count($rows)];
}
