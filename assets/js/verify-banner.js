/* =========================================================================
   AVISO "CONFIRMA O TEU EMAIL"  (assets/js/verify-banner.js)
   -------------------------------------------------------------------------
   Mostra, no topo da página, um aviso a quem ainda não confirmou o email (sem bloquear nada).
   "Reenviar email" chama api/auth.php?action=resend_verification (máximo 3 por hora).
   "Agora não" esconde o aviso até fechares o separador do navegador.
   ========================================================================= */
(function () {
  'use strict';
  const u = window.GF_USER;
  if (!u || u.emailVerified !== false) return;
  try { if (sessionStorage.getItem('lumina-verify-hidden')) return; } catch (e) {}
  const csrfToken = () => (typeof csrf !== 'undefined' ? csrf : window.GF_CSRF);
  const bar = document.createElement('div');
  bar.className = 'verify-banner';
  bar.setAttribute('role', 'region');
  bar.setAttribute('aria-label', 'Confirmar o email');
  bar.innerHTML = '<p>✉ Confirma o teu email <strong>' + esc(u.email || '') + '</strong> para poderes recuperar a palavra-passe se um dia te esqueceres.</p>'
    + '<div><button type="button" class="button secondary small" data-resend>Reenviar email</button> <button type="button" class="button small" data-hide>Agora não</button></div>';
  (document.querySelector('main') || document.body).prepend(bar);
  bar.querySelector('[data-hide]').onclick = () => { try { sessionStorage.setItem('lumina-verify-hidden', '1'); } catch (e) {} bar.remove(); };
  bar.querySelector('[data-resend]').onclick = async ev => {
    ev.target.disabled = true;
    try {
      const r = await api('auth.php?action=resend_verification', { method: 'POST', body: JSON.stringify({ csrf: csrfToken() }) });
      if (r.already) { msg('O teu email já está confirmado.'); bar.remove(); }
      else if (r.sent) msg('Email enviado. Verifica a caixa de entrada e o spam.');
      else { msg(r.mail_error || 'Não foi possível enviar o email.', true); ev.target.disabled = false; }
    } catch (e) { msg(e.message, true); ev.target.disabled = false; }
  };
})();
