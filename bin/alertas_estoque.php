<?php
/* =========================================================================
   ALERTAS DE ESTOQUE BAIXO  (bin/alertas_estoque.php)
   -------------------------------------------------------------------------
   COMO USAR:  php bin/alertas_estoque.php                       envia os avisos pendentes (agendar 1x por dia, ex.: cron 08:00)
               php bin/alertas_estoque.php --simular             mostra a quem e quantos produtos avisaria, sem enviar nada
               php bin/alertas_estoque.php --ativar=dono@email   liga o aviso para esse dono (desligado por omissão)
               php bin/alertas_estoque.php --desativar=dono@email
   SÓ ENVIA SE:  o email do Lumina estiver configurado (driver smtp/mail; em «log» não envia nada), o dono tiver o email confirmado e ter ativado o aviso.
   NÃO envia mensagens a clientes. Detalhes em includes/stock_alerts.php.
   SAÍDA:      0 = tudo bem (inclui «nada para avisar» e «email por configurar»); 1 = erro ao enviar ou na base de dados.
   ========================================================================= */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/stock_alerts.php';

$opts = getopt('', ['simular', 'ativar:', 'desativar:']);
try {
    $pdo = db();
    foreach (['ativar' => true, 'desativar' => false] as $k => $on) {
        if (isset($opts[$k])) {
            $ok = stock_alert_set($pdo, (string)$opts[$k], $on);
            fwrite($ok ? STDOUT : STDERR, $ok ? "Aviso " . ($on ? 'ativado' : 'desativado') . " para " . $opts[$k] . ".\n" : "Não encontrei nenhum dono com esse email.\n");
            exit($ok ? 0 : 1);
        }
    }
    $dry = isset($opts['simular']);
    if (!alert_email_ready() && !$dry) { echo "O email do Lumina ainda está em modo de teste (driver «log»): nada foi enviado. Configura o SMTP (config/mail.local.php ou LUMINA_SMTP_*).\n"; exit(0); }
    $owners = stock_alert_owners($pdo);
    if (!$owners) { echo "Nenhum dono ativou o aviso de estoque (usa --ativar=email).\n"; exit(0); }
    $bad = 0;
    foreach ($owners as $o) {
        $r = stock_alert_run_owner($pdo, $o, $dry);
        $mask = preg_replace('/^(.).*(@.*)$/', '$1***$2', $o['email']);
        echo "$mask: {$r['status']} ({$r['items']} produtos)\n";
        if ($r['status'] === 'failed') $bad++;
    }
    exit($bad ? 1 : 0);
} catch (Throwable $e) { fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n"); exit(1); }
