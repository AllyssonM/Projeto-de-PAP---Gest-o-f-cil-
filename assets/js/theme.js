/* =========================================================
   Modo claro / escuro (guardado no navegador)
   O <head> de cada página já aplica o tema antes de desenhar,
   para não piscar.
   ========================================================= */
(() => {
  const KEY = 'gf-tema';
  const root = document.documentElement;

  function apply(theme) {
    root.dataset.tema = theme;
    document.querySelectorAll('[data-theme-toggle]').forEach(b => {
      const dark = theme === 'dark';
      b.textContent = dark ? '☀' : '☾';
      b.title = dark ? 'Mudar para o modo claro' : 'Mudar para o modo escuro';
      b.setAttribute('aria-pressed', String(!dark));
    });
  }

  function toggle() {
    const next = root.dataset.tema === 'light' ? 'dark' : 'light';
    root.classList.add('theme-anim');
    apply(next);
    try { localStorage.setItem(KEY, next); } catch {}
    setTimeout(() => root.classList.remove('theme-anim'), 450);
  }

  apply(root.dataset.tema === 'light' ? 'light' : 'dark');
  document.querySelectorAll('[data-theme-toggle]').forEach(b => b.addEventListener('click', toggle));

  /* brilho dos botões segue o rato */
  document.addEventListener('pointermove', e => {
    const b = e.target.closest?.('.button');
    if (!b) return;
    const r = b.getBoundingClientRect();
    b.style.setProperty('--mx', `${e.clientX - r.left}px`);
    b.style.setProperty('--my', `${e.clientY - r.top}px`);
  }, { passive: true });
})();
