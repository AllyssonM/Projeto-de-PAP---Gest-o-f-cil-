<?php
/* =========================================================================
   OS MEUS DADOS (RGPD)  (api/account.php)
   -------------------------------------------------------------------------
   O QUE FAZ:  os dois direitos que o RGPD dá a cada pessoa e que antes só se exerciam por pedido:
     GET  ?action=export        descarrega TUDO o que o Lumina guarda sobre a pessoa, num ficheiro JSON
                                (direito de acesso e de portabilidade).
     POST action=delete_account elimina a conta e todos os dados (direito ao apagamento). Só o administrador
                                do negócio; pede a palavra-passe, o código do 2.º passo (se ativo) e a palavra ELIMINAR.
   NOTAS:      - O funcionário descarrega os seus próprios dados. Para eliminar a conta de um funcionário,
                 o administrador usa a aba Equipa.
               - As notas privadas de outras pessoas nunca entram na exportação (nem o dono as lê).
               - Os segredos (hash da palavra-passe, segredo do 2.º passo, tokens do Google) NUNCA são exportados.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google.php';

$user   = require_login();
$uid    = (int)$user['id'];
$tid    = (int)$user['tenant_id'];
$owner  = $user['role'] === 'owner';
$data   = request_json();
$method = $_SERVER['REQUEST_METHOD'];
$action = (string)($_GET['action'] ?? $data['action'] ?? '');

/** Lê várias linhas com parâmetros preparados. */
function rows(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

try {
    /* ---------------------- EXPORTAR ---------------------- */
    if ($method === 'GET' && $action === 'export') {
        $out = ['sistema' => 'Lumina', 'gerado_em' => (new DateTime('now', app_timezone()))->format('c'), 'tipo_de_conta' => $owner ? 'administrador' : 'funcionário'];
        $out['conta'] = rows('SELECT id, name, email, phone, job_title, department, hired_at, role, status, created_at, last_login_at, email_verified_at, preferences FROM users WHERE id = ?', [$uid])[0] ?? [];
        $out['sessoes'] = rows('SELECT created_at, ip, user_agent, last_seen_at, revoked_at FROM user_sessions WHERE user_id = ? ORDER BY id DESC', [$uid]);
        $out['registo_de_seguranca'] = rows('SELECT action, detail, ip, created_at FROM audit_log WHERE user_id = ? ORDER BY id DESC', [$uid]);
        $out['consentimentos'] = rows('SELECT kind, granted, created_at FROM consent_log WHERE user_id = ? ORDER BY id', [$uid]);
        $out['notas_minhas'] = rows('SELECT title, detail, tags, color, pinned, shared, created_at, updated_at FROM notes WHERE author_id = ? ORDER BY id', [$uid]);
        $out['agenda'] = rows('SELECT title, description, event_date, start_time, end_time, location, source FROM calendar_events WHERE user_id = ? ORDER BY event_date', [$uid]);
        $out['google_calendar'] = rows('SELECT google_email, scope, sync_mode, connected_at, last_sync_at FROM google_connections WHERE user_id = ?', [$uid]);
        $out['meta_ads'] = rows('SELECT fb_user_name, ad_account_id, ad_account_name, currency, date_preset, connected_at, last_sync_at, expires_at FROM meta_connections WHERE user_id = ?', [$uid]);
        $out['lumina_conversas'] = array_map(fn($c) => $c + ['mensagens' => rows('SELECT role, content, created_at FROM ai_messages WHERE conversation_id = ? ORDER BY id', [$c['id']])],
            rows('SELECT id, title, created_at FROM ai_conversations WHERE user_id = ? ORDER BY id', [$uid]));
        $out['mensagens_internas'] = rows('SELECT from_user, to_user, kind, body, read_at, created_at FROM team_messages WHERE from_user = ? OR to_user = ? ORDER BY id', [$uid, $uid]);
        $out['avisos'] = rows('SELECT type, title, body, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id', [$uid]);
        $out['tarefas_atribuidas'] = rows('SELECT title, detail, due_date, status, created_at, completed_at FROM employee_tasks WHERE employee_id = ? ORDER BY id', [$uid]);
        $out['metas'] = rows('SELECT kind, target, month, created_at FROM employee_goals WHERE employee_id = ? ORDER BY id', [$uid]);
        $out['comentarios_do_lider_partilhados'] = rows('SELECT body, created_at FROM leader_notes WHERE employee_id = ? AND shared = 1 ORDER BY id', [$uid]);   // as observações privadas do líder não saem daqui
        $out['entradas_no_sistema'] = rows('SELECT logged_at FROM login_log WHERE user_id = ? ORDER BY id', [$uid]);
        $out['tempo_de_trabalho'] = rows('SELECT s.started_at, s.ended_at, s.status, s.note, s.edit_reason FROM work_shifts s WHERE s.employee_id = ? ORDER BY s.started_at', [$uid]);

        if ($owner) {                                                         // dados do negócio: só o administrador
            $out['empresa'] = rows('SELECT business_name, business_type, activity, tax_number, address, company_phone, company_email, website, brand_color, currency, enabled_modules, onboarding, created_at FROM business_profiles WHERE user_id = ?', [$uid])[0] ?? null;
            $out['equipa'] = rows("SELECT name, email, phone, job_title, department, hired_at, status, permissions, created_at, last_login_at FROM users WHERE owner_id = ? ORDER BY id", [$uid]);
            $out['tempo_da_equipa'] = rows('SELECT employee_id, started_at, ended_at, status, note FROM work_shifts WHERE tenant_id = ? ORDER BY started_at', [$uid]);
            $out['notas_partilhadas'] = rows('SELECT title, detail, tags, created_at FROM notes WHERE user_id = ? AND shared = 1 ORDER BY id', [$uid]);
            // tabelas do negócio: lista fixa (o nome da tabela nunca vem do pedido)
            foreach (['accounts', 'transactions', 'financial_documents', 'recurring_items', 'clients', 'products', 'stock_movements', 'transfers', 'reserve_funds', 'investment_accounts',
                      'payment_cards', 'sales', 'trips', 'day_closings'] as $table) {
                $out['negocio'][$table] = rows("SELECT * FROM `$table` WHERE user_id = ?", [$uid]);
            }
            // gestão de funcionários: tudo o que o líder registou (inclui as observações privadas)
            $out['negocio']['funcionarios_tarefas'] = rows('SELECT employee_id, title, detail, due_date, status, created_at, completed_at FROM employee_tasks WHERE tenant_id = ?', [$uid]);
            $out['negocio']['funcionarios_metas'] = rows('SELECT employee_id, kind, target, month FROM employee_goals WHERE tenant_id = ?', [$uid]);
            $out['negocio']['funcionarios_observacoes'] = rows('SELECT employee_id, body, shared, created_at FROM leader_notes WHERE tenant_id = ?', [$uid]);
            $out['negocio']['funcionarios_mensagens'] = rows('SELECT from_user, to_user, kind, body, read_at, created_at FROM team_messages WHERE tenant_id = ?', [$uid]);
            $out['negocio']['funcionarios_entradas'] = rows('SELECT user_id, logged_at FROM login_log WHERE tenant_id = ?', [$uid]);
            $out['negocio']['anexos'] = rows('SELECT entity, entity_id, original_name, mime, size, created_at FROM attachments WHERE user_id = ?', [$uid]);
        }
        audit('data_exported', [], $user);
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="lumina-os-meus-dados-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /* ---------------------- ELIMINAR A CONTA ---------------------- */
    if ($method === 'POST' && $action === 'delete_account') {
        check_csrf();
        if (!$owner) {
            json_response(['success' => false, 'error' => 'Só o administrador do negócio pode eliminar a conta. Pede-lhe que te elimine na aba Equipa.'], 403);
        }
        if (!in_array(strtoupper(trim((string)($data['confirm'] ?? ''))), ['ELIMINAR', 'DELETE'], true)) {      // a palavra aparece no idioma da interface (ELIMINAR / DELETE)
            json_response(['success' => false, 'error' => 'Escreve a palavra ELIMINAR para confirmar.'], 400);
        }
        confirm_password($user, (string)($data['password'] ?? ''));
        $second = second_factor_check($uid, (string)($data['code'] ?? ''));
        if ($second === false) {
            login_failed($user['email']);
            json_response(['success' => false, 'error' => 'Código do 2.º passo incorreto.'], 403);
        }

        // 1) tudo o que há a apagar: o administrador e a equipa
        $people = rows('SELECT id, email, avatar_path FROM users WHERE id = ? OR owner_id = ?', [$uid, $uid]);
        $ids    = array_map(fn($p) => (int)$p['id'], $people);
        $in     = implode(',', array_fill(0, count($ids), '?'));
        $files  = array_filter(array_column($people, 'avatar_path'));
        $logo   = rows('SELECT logo_path FROM business_profiles WHERE user_id = ?', [$uid])[0]['logo_path'] ?? null;
        if ($logo) $files[] = $logo;
        foreach (rows('SELECT path FROM attachments WHERE user_id = ?', [$uid]) as $a) $files[] = $a['path'];
        $emails = [];
        foreach ($people as $p) { $emails[] = $p['email']; $emails[] = 'reset:' . $p['email']; }

        // 2) desliga o Google de cada pessoa (revoga o acesso na Google)
        foreach ($ids as $id) { try { google_disconnect($id); } catch (Throwable $e) { error_log('Lumina: google_disconnect: ' . $e->getMessage()); } }

        // 3) apaga na base de dados, tudo ou nada. A chave estrangeira em cascata apaga o resto (movimentos, clientes, notas, sessões...).
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM audit_log WHERE tenant_id = ? OR user_id IN ($in)")->execute([$uid, ...$ids]);
        $pdo->prepare("DELETE FROM ai_audit_log WHERE tenant_id = ? OR user_id IN ($in)")->execute([$uid, ...$ids]);
        $pdo->prepare('DELETE FROM login_attempts WHERE email IN (' . implode(',', array_fill(0, count($emails), '?')) . ')')->execute($emails);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
        $pdo->commit();

        // 4) ficheiros em disco e sessão
        foreach ($files as $f) upload_delete((string)$f);
        error_log('Lumina: conta eliminada (id ' . $uid . ')');                 // sem dados pessoais no log
        $_SESSION = [];
        session_destroy();
        json_response(['success' => true]);
    }

    json_response(['success' => false, 'error' => 'Pedido inválido.'], 400);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    internal_error($error);
}
