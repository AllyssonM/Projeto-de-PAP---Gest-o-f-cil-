<?php
/* =========================================================================
   CONFIRMAR O EMAIL  (verificar-email.php)
   -------------------------------------------------------------------------
   Página pública aberta pelo link do email de boas-vindas. Confirma o email e mostra o resultado.
   ========================================================================= */
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/account_tokens.php';
$ok = verify_email_token((string)($_GET['token'] ?? '')) !== null;
legal_page_start($ok ? 'Email confirmado' : 'Link inválido', false, 'CONTA');
if ($ok): ?>
<div class="account-result" role="status"><strong>Obrigado!</strong> O teu email ficou confirmado.</div>
<?php else: ?>
<div class="account-result" role="alert"><strong>Este link já não é válido.</strong><br>Pode ter expirado ou já ter sido usado. Entra no Lumina e pede um novo na tua Área pessoal.</div>
<?php endif; ?>
<p><a class="button primary" href="dashboard.php">Ir para o painel</a></p>
<?php legal_page_end();
