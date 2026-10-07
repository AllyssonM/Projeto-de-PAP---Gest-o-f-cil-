<?php
/* =========================================================================
   ESQUECI-ME DA PALAVRA-PASSE  (esqueci-palavra-passe.php)
   -------------------------------------------------------------------------
   Página pública: pede o email e envia um link (válido 1 hora). A resposta é sempre a mesma,
   exista o email ou não (assim ninguém descobre que emails têm conta). A lógica está em
   api/auth.php?action=forgot; o link leva a redefinir-palavra-passe.php.
   ========================================================================= */
require_once __DIR__ . '/includes/legal.php';
legal_page_start('Recuperar a palavra-passe', false, 'CONTA');
?>
<p>Escreve o email da tua conta. Se existir, enviamos-te um link para escolheres uma palavra-passe nova.</p>
<form class="account-form" data-account-action="forgot" novalidate>
  <label>Email<input name="email" type="email" autocomplete="email" placeholder="nome@exemplo.pt" required></label>
  <button class="button primary" type="submit">Enviar link</button>
  <p class="account-msg" role="status" aria-live="polite"></p>
</form>
<?php legal_page_end('assets/js/account-pages.js');
