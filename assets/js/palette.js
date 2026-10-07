/* =========================================================================
   PESQUISA GLOBAL E COMANDOS  (assets/js/palette.js)
   -------------------------------------------------------------------------
   Ctrl+K (ou ⌘+K, ou o botão da lupa) abre uma caixa para:
     - ir diretamente a qualquer aba (escreve "caixa", "clientes"...);
     - pesquisar em clientes, produtos, movimentos, contas, notas e agenda (api/search.php);
     - ações rápidas ("Nova venda", "Nova despesa").
   Teclado: ↑ ↓ percorrem, Enter escolhe, Esc fecha. Só mostra o que a pessoa pode ver (as permissões vêm do servidor).
   ========================================================================= */
(function () {
  'use strict';
  const ICON = { clients: '👤', products: '📦', transactions: '💶', bills: '🧾', notes: '📝', events: '📅', section: '➜', action: '＋' };
  const GROUP = { section: 'Ir para', action: 'Ações', clients: 'Clientes', products: 'Produtos', transactions: 'Movimentos', bills: 'Contas', notes: 'Notas', events: 'Agenda' };
  const norm = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  let root = null, close = null, items = [], active = 0, timer = 0, token = 0;

  const sections = () => $$('#main-menu .nav-tab').filter(t => getComputedStyle(t).display !== 'none' && !t.classList.contains('ramo-off') && !t.classList.contains('module-off'))
    .map(t => ({ type: 'section', section: t.dataset.section, title: t.textContent.trim(), sub: '' }));
  const actions = () => (window.GFQuickAdd ? [{ type: 'action', run: () => GFQuickAdd.open('income'), title: 'Nova venda', sub: 'Registo rápido' }, { type: 'action', run: () => GFQuickAdd.open('expense'), title: 'Nova despesa', sub: 'Registo rápido' }] : []);

  function render() {
    const list = root.querySelector('#pal-list');
    list.innerHTML = items.length ? items.map((it, i) => `${i === 0 || it.type !== items[i - 1].type ? `<li class="pal-group" role="presentation">${esc(GROUP[it.type] || '')}</li>` : ''}
      <li role="option" id="pal-${i}" class="pal-item${i === active ? ' on' : ''}" aria-selected="${i === active}" data-i="${i}"><span class="pal-ico" aria-hidden="true">${ICON[it.type] || ''}</span>
      <span class="pal-text"><strong>${esc(it.title)}</strong>${it.sub ? `<small>${esc(it.sub)}</small>` : ''}</span></li>`).join('') : '<li class="pal-empty">Sem resultados.</li>';
    root.querySelector('#pal-input').setAttribute('aria-activedescendant', items.length ? 'pal-' + active : '');
    list.querySelector('.pal-item.on')?.scrollIntoView({ block: 'nearest' });
  }

  async function search(q) {
    const mine = ++token, text = norm(q);
    const local = [...actions(), ...sections()].filter(x => !text || norm(x.title).includes(text));
    items = text ? local.slice(0, 6) : local;
    active = 0; render();
    if (text.length < 2) return;
    try {
      const d = await api('search.php?q=' + encodeURIComponent(q));
      if (mine !== token) return;                                   // já há uma pesquisa mais recente
      items = [...local.slice(0, 6), ...d.results]; active = 0; render();
    } catch (e) { /* sem rede: ficam os comandos locais */ }
  }

  function choose(it) {
    if (!it) return;
    close();
    if (it.run) return setTimeout(it.run, 260);
    window.showSection?.(it.section);
  }

  function open() {
    if (root) return;
    ({ root, close } = UX.modal(`<div class="pal"><input id="pal-input" data-autofocus type="search" role="combobox" aria-expanded="true" aria-controls="pal-list" aria-autocomplete="list" placeholder="Pesquisar ou ir para…" autocomplete="off" aria-label="Pesquisar">
      <ul id="pal-list" class="pal-list" role="listbox" aria-label="Resultados"></ul><p class="pal-hint muted">↑ ↓ para escolher · Enter para abrir · Esc para fechar</p></div>`, { label: 'Pesquisa e comandos', onClose: () => { root = null; } }));
    const input = root.querySelector('#pal-input');
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => search(input.value), 180); });
    root.addEventListener('keydown', e => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); if (items.length) { active = (active + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length; render(); } }
      else if (e.key === 'Enter') { e.preventDefault(); choose(items[active]); }
    });
    root.querySelector('#pal-list').addEventListener('click', e => { const li = e.target.closest('.pal-item'); if (li) choose(items[Number(li.dataset.i)]); });
    root.querySelector('#pal-list').addEventListener('mousemove', e => { const li = e.target.closest('.pal-item'); if (li && Number(li.dataset.i) !== active) { active = Number(li.dataset.i); render(); } });
    search('');
  }

  document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); root ? close() : open(); }
  });
  $('#palette-btn')?.addEventListener('click', open);
  window.GFPalette = { open };
})();
