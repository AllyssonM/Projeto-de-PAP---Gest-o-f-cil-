/* =========================================================================
   MENU LATERAL (☰)  (assets/js/menu.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  abre e fecha o menu com todas as abas. O menu é o <nav id="main-menu">
               do dashboard.php; os botões de aba são os mesmos de sempre
               (app.js continua a tratar da navegação com showSection()).
   COMO FECHA: botão ✕, tecla Esc, clicar no fundo escurecido, ou escolher uma aba.
   ACESSIBILIDADE: aria-expanded no botão; o menu fechado fica "inert" (não recebe foco);
     aberto, o foco fica preso lá dentro (Tab/Shift+Tab) e as setas ↑ ↓ percorrem as abas;
     ao fechar, o foco volta ao botão ☰.
   ORIENTAÇÃO: com as abas escondidas, mostra o nome da secção atual junto à marca
     ("LUMINA · Fluxo de caixa") e marca a aba ativa com aria-current.
   ========================================================================= */
(function () {
  'use strict';
  const btn = document.querySelector('#menu-btn'), nav = document.querySelector('#main-menu');
  const back = document.querySelector('#menu-backdrop'), closeBtn = document.querySelector('#menu-close'), cur = document.querySelector('#menu-current');
  if (!btn || !nav) return;
  const html = document.documentElement;
  const isOpen = () => html.classList.contains('menu-open');
  const items = () => [...nav.querySelectorAll('.nav-tab')].filter(t => t.offsetParent !== null && !t.classList.contains('ramo-off'));

  function open() {
    if (isOpen()) return;
    nav.removeAttribute('inert'); back.hidden = false;
    btn.setAttribute('aria-expanded', 'true'); btn.setAttribute('aria-label', 'Fechar menu');
    window.moveTabGlass?.();                                         // o vidro da aba ativa fica no sítio certo
    requestAnimationFrame(() => requestAnimationFrame(() => {
      html.classList.add('menu-open');
      // O foco passa para dentro do menu NO MESMO instante em que ele fica visível (não se pode focar um elemento ainda escondido).
      (nav.querySelector('.nav-tab.active') || items()[0])?.focus({ preventScroll: true });
    }));
  }
  function close(returnFocus = true) {
    if (!isOpen()) return;
    html.classList.remove('menu-open');
    btn.setAttribute('aria-expanded', 'false'); btn.setAttribute('aria-label', 'Abrir menu');
    nav.setAttribute('inert', '');
    setTimeout(() => { if (!isOpen()) back.hidden = true; }, reduced() ? 0 : 230);
    if (returnFocus) btn.focus();
  }

  btn.addEventListener('click', () => (isOpen() ? close() : open()));
  closeBtn?.addEventListener('click', () => close());
  back.addEventListener('click', () => close());
  nav.addEventListener('click', e => { if (e.target.closest('.nav-tab')) close(false); });          // escolher uma aba fecha o menu

  document.addEventListener('keydown', e => {
    if (!isOpen()) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    const list = [...nav.querySelectorAll('.nav-tab, #menu-close')].filter(x => x.offsetParent !== null);
    if (e.key === 'Tab' && list.length) {                              // foco preso dentro do menu
      const first = list[0], last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {                // setas percorrem as abas
      const tabs = items(), i = tabs.indexOf(document.activeElement);
      if (i >= 0) { e.preventDefault(); tabs[(i + (e.key === 'ArrowDown' ? 1 : -1) + tabs.length) % tabs.length].focus(); }
    }
  });

  /* secção atual junto à marca + aria-current */
  function syncCurrent() {
    const active = nav.querySelector('.nav-tab.active');
    nav.querySelectorAll('.nav-tab').forEach(t => { if (t === active) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current'); });
    if (cur) cur.textContent = active ? '· ' + active.textContent.trim() : '';
  }
  document.addEventListener('gf:section', () => setTimeout(syncCurrent, 0));
  document.addEventListener('gf:profile', () => setTimeout(syncCurrent, 0));         // o nome de uma aba pode mudar com o ramo
  window.addEventListener('load', syncCurrent);
  syncCurrent();
  window.GFMenu = { open, close, isOpen };
})();
