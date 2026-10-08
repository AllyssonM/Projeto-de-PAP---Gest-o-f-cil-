<?php
/* =========================================================================
   ALERTAS DE ESTOQUE BAIXO POR EMAIL  (includes/stock_alerts.php)
   -------------------------------------------------------------------------
   Corre pelo agendador (bin/alertas_estoque.php), nunca num pedido do site.
   QUEM RECEBE:  só o DONO do negócio, com o email confirmado e que ATIVOU o aviso (preferences.alerts.stock_email = true; desligado por omissão).
   O QUE AVISA:  produtos com estoque mínimo definido (> 0) e quantidade <= mínimo: «baixo» (> 0) ou «sem estoque» (0). Arquivados não contam.
   SEM REPETIR:  guarda o nível já avisado por produto (stock_alert_state). Só volta a avisar se o produto piorar (baixo → sem estoque)
                 ou se for reposto e voltar a baixar.
   HONESTO:      com o email em modo de teste («log») NÃO envia, NÃO grava avisos e diz que não enviou; o estado só é gravado quando o servidor
                 de email aceitou a mensagem, por isso nada se perde: o aviso sai quando o SMTP for configurado.
   ========================================================================= */
declare(strict_types=1);
require_once __DIR__ . '/alert_channels.php';

const STOCK_ALERT_MAX_LISTED = 50;

/** Donos que ativaram o aviso. @return list<array{id:int,name:string,email:string}> */
function stock_alert_owners(PDO $pdo): array
{
    $rows = $pdo->query("SELECT id, name, email, preferences FROM users WHERE owner_id IS NULL AND status = 'active' AND email_verified_at IS NOT NULL AND preferences IS NOT NULL")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $p = json_decode((string)$r['preferences'], true);
        if (is_array($p) && ($p['alerts']['stock_email'] ?? false) === true) $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'email' => (string)$r['email']];
    }
    return $out;
}

/** Liga/desliga o aviso de um dono (mantém as restantes preferências). Devolve false se o utilizador não existir ou não for dono. */
function stock_alert_set(PDO $pdo, string $email, bool $on): bool
{
    $st = $pdo->prepare('SELECT id, preferences FROM users WHERE email = ? AND owner_id IS NULL LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u) return false;
    $p = $u['preferences'] ? (json_decode((string)$u['preferences'], true) ?: []) : [];
    $p['alerts']['stock_email'] = $on;
    $pdo->prepare('UPDATE users SET preferences = ? WHERE id = ?')->execute([json_encode($p, JSON_UNESCAPED_UNICODE), $u['id']]);
    return true;
}

/** Produtos a precisar de reposição. @return list<array{id:int,name:string,brand:?string,quantity:int,minimum:int,level:string}> */
function stock_alert_products(PDO $pdo, int $tid): array
{
    $st = $pdo->prepare("SELECT id, name, brand, stock_quantity, minimum_stock FROM products WHERE user_id = ? AND status <> 'archived' AND minimum_stock > 0 AND stock_quantity <= minimum_stock ORDER BY stock_quantity ASC, name ASC");
    $st->execute([$tid]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'brand' => $r['brand'], 'quantity' => max(0, (int)$r['stock_quantity']),
        'minimum' => (int)$r['minimum_stock'], 'level' => (int)$r['stock_quantity'] <= 0 ? 'out' : 'low'], $st->fetchAll());
}

/** Do que está em alerta, o que ainda NÃO foi avisado (novo ou pior). */
function stock_alert_pending(PDO $pdo, int $tid, array $current): array
{
    $st = $pdo->prepare('SELECT product_id, level FROM stock_alert_state WHERE user_id = ?');
    $st->execute([$tid]);
    $known = array_column($st->fetchAll(), 'level', 'product_id');
    return array_values(array_filter($current, fn($p) => !isset($known[$p['id']]) || ($known[$p['id']] === 'low' && $p['level'] === 'out')));
}

function stock_alert_message(array $owner, array $items): array
{
    $h = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $out = count(array_filter($items, fn($p) => $p['level'] === 'out'));
    $subject = L('Lumina: %s produto(s) a precisar de reposição', count($items));
    $lines = []; $text = [];
    foreach (array_slice($items, 0, STOCK_ALERT_MAX_LISTED) as $p) {
        $label = $p['name'] . ($p['brand'] ? ' (' . $p['brand'] . ')' : '');
        $state = $p['level'] === 'out' ? L('sem estoque') : L('%s em estoque, mínimo %s', $p['quantity'], $p['minimum']);
        $lines[] = '<li style="margin:4px 0"><strong>' . $h($label) . '</strong> — ' . $h($state) . '</li>';
        $text[] = '- ' . $label . ' — ' . $state;
    }
    $more = count($items) - STOCK_ALERT_MAX_LISTED;
    if ($more > 0) { $lines[] = '<li>' . $h(L('… e mais %s.', $more)) . '</li>'; $text[] = L('… e mais %s.', $more); }
    $first = $h(explode(' ', trim($owner['name']))[0] ?: L('olá'));
    $intro = L('Olá, %s', $first) . '<br>' . $h($out > 0 ? L('Estes produtos chegaram ao estoque mínimo ou acabaram (%s sem estoque):', $out) : L('Estes produtos chegaram ao estoque mínimo:'))
        . '<ul style="padding-left:20px;line-height:1.5">' . implode('', $lines) . '</ul>';
    $outro = $h(L('Só avisamos uma vez por produto, até ser reposto. Para deixar de receber estes avisos, desativa-os nas preferências da conta.'));
    $html = mail_layout(L('Estoque a precisar de reposição'), $intro, null, null, $outro);
    return [$subject, $html, L('Olá, %s', $owner['name']) . "\n\n" . implode("\n", $text) . "\n\n" . L('Só avisamos uma vez por produto, até ser reposto.')];
}

/**
 * Verifica um dono e avisa se houver novidades. Devolve ['status' => ..., 'items' => n]:
 *   none (nada de novo), sent, unavailable (email não configurado), failed.
 */
function stock_alert_run_owner(PDO $pdo, array $owner, bool $dry = false): array
{
    $tid = $owner['id'];
    $current = stock_alert_products($pdo, $tid);
    // limpar o estado dos produtos que já não estão em alerta (repostos, arquivados ou sem mínimo): voltam a avisar se baixarem outra vez
    $keep = array_column($current, 'id');
    $clean = $pdo->prepare('DELETE FROM stock_alert_state WHERE user_id = ?' . ($keep ? ' AND product_id NOT IN (' . implode(',', array_map('intval', $keep)) . ')' : ''));
    if (!$dry) $clean->execute([$tid]);
    $pending = stock_alert_pending($pdo, $tid, $current);
    if (!$pending) return ['status' => 'none', 'items' => 0];
    if ($dry) return ['status' => 'would_send', 'items' => count($pending)];
    [$subject, $html, $text] = stock_alert_message($owner, $pending);
    $status = alert_send('email', $owner, $subject, $html, $text);
    if ($status !== 'sent') return ['status' => $status === 'failed' ? 'failed' : 'unavailable', 'items' => count($pending)];
    $up = $pdo->prepare('INSERT INTO stock_alert_state (user_id, product_id, level, notified_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE level = VALUES(level), notified_at = NOW()');
    foreach ($pending as $p) $up->execute([$tid, $p['id'], $p['level']]);
    return ['status' => 'sent', 'items' => count($pending)];
}
