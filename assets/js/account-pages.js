/* =========================================================================
   FORMULÁRIOS DAS PÁGINAS DE CONTA  (assets/js/account-pages.js)
   -------------------------------------------------------------------------
   Usado por esqueci-palavra-passe.php e redefinir-palavra-passe.php (páginas públicas).
   Cada <form data-account-action="forgot|reset_password"> é enviado para api/auth.php.
   Os campos opcionais (token, purpose) vão em <input type="hidden">.
   ========================================================================= */
(function () {
  'use strict';
  document.querySelectorAll('form[data-account-action]').forEach(form => {
    const box = form.querySelector('.account-msg');
    const show = (text, ok) => { box.textContent = text; box.className = 'account-msg ' + (ok ? 'ok' : 'error'); };
    form.addEventListener('submit', async e => {
      e.preventDefault();
      const f = Object.fromEntries(new FormData(form));
      if (f.password !== undefined && f.password !== f.confirm) return show('As palavras-passe não coincidem.', false);
      delete f.confirm;
      const btn = form.querySelector('[type=submit]'), label = btn.textContent;
      btn.disabled = true; btn.textContent = 'A enviar…'; box.textContent = '';
      try {
        const r = await fetch('api/auth.php?action=' + form.dataset.accountAction, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(f) });
        const d = await r.json().catch(() => ({}));
        if (!r.ok || !d.success) return show(d.error || 'Não foi possível concluir. Tenta outra vez.', false);
        if (form.dataset.accountAction === 'reset_password') {
          show('Palavra-passe guardada. A levar-te para o login…', true);
          setTimeout(() => { location.href = 'index.php#autenticacao'; }, 1500);
        } else {
          show(d.message || 'Pedido enviado.', true); form.reset();
        }
      } catch (err) { show('Sem ligação ao servidor. Tenta outra vez.', false); }
      finally { btn.disabled = false; btn.textContent = label; }
    });
  });
})();
