/* =========================================================================
   RODAPÉ E AVISO DE COOKIES  (assets/js/lumina-footer.js)
   -------------------------------------------------------------------------
   O QUE FAZ:
     1. O botão ↑ do rodapé volta ao topo da página (suave; sem animação se a pessoa
        pediu "reduzir movimento").
     2. Mostra, na primeira visita, um aviso INFORMATIVO sobre cookies. O Lumina só usa
        armazenamento essencial (ver politica-cookies), por isso não pede
        consentimento: basta "Entendi". A escolha fica em localStorage ('lumina-aviso-cookies').
   ========================================================================= */
(function () {
  'use strict';
  document.addEventListener('click', e => {
    if (!e.target.closest('[data-back-to-top]')) return;
    window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    document.querySelector('a, button, [tabindex]')?.focus?.({ preventScroll: true });
  });

  const KEY = 'lumina-aviso-cookies';
  try { if (localStorage.getItem(KEY)) return; } catch (e) { return; }
  const box = document.createElement('div');
  box.className = 'cookie-note';
  box.setAttribute('role', 'region');
  box.setAttribute('aria-label', 'Aviso sobre cookies');
  box.innerHTML = '<p>O <strong>Lumina</strong> usa apenas cookies e armazenamento <strong>essenciais</strong> (sessão, tema e preferências). Não usamos cookies de publicidade nem de análise.</p>'
    + '<div class="cookie-note-actions"><a href="politica-cookies">Saber mais</a><button type="button" class="button primary small">Entendi</button></div>';
  box.querySelector('button').onclick = () => {
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
    box.classList.remove('in'); setTimeout(() => box.remove(), 260);
  };
  setTimeout(() => { document.body.appendChild(box); requestAnimationFrame(() => requestAnimationFrame(() => box.classList.add('in'))); }, 600);
})();
