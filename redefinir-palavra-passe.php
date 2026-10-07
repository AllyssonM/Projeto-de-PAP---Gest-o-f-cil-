<?php
/* =========================================================================
   ESCOLHER A PALAVRA-PASSE COM O LINK DO EMAIL  (redefinir-palavra-passe.php)
   -------------------------------------------------------------------------
   Página pública. ?token=...  (recuperação) ou  ?token=...&tipo=convite  (funcionário convidado).
   Aqui só se VÊ se o link vale (sem o gastar); o link é gasto quando a nova palavra-passe é guardada
   (api/auth.php?action=reset_password).
   ========================================================================= */
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/account_tokens.php';
$invite = ($_GET['tipo'] ?? '') === 'convite';
$token  = (string)($_GET['token'] ?? '');
$valid  = token_peek($token, $invite ? 'invite' : 'reset') !== null;
legal_page_start($invite ? 'Bem-vindo ao Lumina' : 'Nova palavra-passe', false, 'CONTA');
if ($valid): ?>
<p><?= $invite ? 'Escolhe a palavra-passe com que vais entrar no Lumina.' : 'Escolhe uma palavra-passe nova para a tua conta.' ?> Mínimo de <?= PASSWORD_MIN_LENGTH ?> caracteres.</p>
<form class="account-form" data-account-action="reset_password" novalidate>
  <input type="hidden" name="token" value="<?= lh($token) ?>">
  <input type="hidden" name="purpose" value="<?= $invite ? 'invite' : 'reset' ?>">
  <label>Palavra-passe nova<input name="password" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
  <label>Repete a palavra-passe<input name="confirm" type="password" minlength="<?= PASSWORD_MIN_LENGTH ?>" autocomplete="new-password" required></label>
  <button class="button primary" type="submit">Guardar palavra-passe</button>
  <p class="account-msg" role="status" aria-live="polite"></p>
</form>
<?php else: ?>
<div class="account-result" role="alert"><strong>Este link já não é válido.</strong><br>Pode ter expirado ou já ter sido usado.</div>
<p><a class="button primary" href="esqueci-palavra-passe.php">Pedir um link novo</a></p>
<?php endif;
legal_page_end('assets/js/account-pages.js');
