/* =========================================================================
   OCULTAR / MOSTRAR VALORES FINANCEIROS  (assets/js/privacy.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  um botão (olho) no cabeçalho, e um olho pequeno em cada cartão de
               totais, escondem os VALORES em euros e mostram "••••••" no lugar.
               Os TÍTULOS das métricas ficam sempre visíveis ("Saldo atual",
               "Entradas"...). Útil quando há alguém a ver o ecrã.
   COMO FUNCIONA:
     - Percorre o texto das zonas financeiras (Visão geral, Fluxo de caixa,
       Contas, Relatórios, Clientes, preços dos produtos) e troca só o que parece
       um valor em euros (ex.: "- 50,00 €", "1.234,50 €").
     - O texto verdadeiro fica guardado em memória e volta quando se mostra.
     - Quando a página se atualiza (novos dados), esconde de novo sozinho
       (MutationObserver).
     - Os gráficos ficam desfocados (CSS: html[data-hide-values]).
   PREFERÊNCIA: guardada no servidor (api/me.php, prefs_update), por isso vale em
     todos os dispositivos do utilizador. Em cada página, o PHP já escreve
     data-hide-values no <html> para não haver "flash" de valores a aparecer.
   NÃO ESCONDE: a Calculadora e a ferramenta do ramo (são contas do próprio
     utilizador, não dados do negócio).
   ========================================================================= */
(function () {
  'use strict';
  const MASK = '••••••';
  // um valor em euros: sinal opcional, dígitos com espaços/pontos/vírgulas, e o símbolo €
  const MONEY = /[+\-−]?\s*\d[\d\s\u00a0.,]*\s*€/g;
  const SCOPES = ['#overview', '#cashflow', '#accounts', '#reports', '#clients', '#insights', '#stock .product-price', '.cash-alerts'];
  const html = document.documentElement;
  const stored = new Map();                    // nó de texto -> { real, masked }
  let observer = null, scheduled = false;

  const isHidden = () => html.hasAttribute('data-hide-values');

  /** Percorre todos os nós de texto das zonas financeiras. */
  function eachTextNode(fn) {
    const seen = new Set();
    SCOPES.forEach(sel => document.querySelectorAll(sel).forEach(root => {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: n => n.parentElement && !n.parentElement.closest('input, textarea, script, style, .ramo-tool, .skeleton') ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT,
      });
      let n;
      while ((n = walker.nextNode())) if (!seen.has(n)) { seen.add(n); fn(n); }
    }));
  }

  function mask() {
    pause();
    eachTextNode(n => {
      const known = stored.get(n);
      if (known && n.nodeValue === known.masked) return;            // já está escondido
      MONEY.lastIndex = 0;
      if (!MONEY.test(n.nodeValue)) return;
      MONEY.lastIndex = 0;
      const masked = n.nodeValue.replace(MONEY, MASK);
      stored.set(n, { real: n.nodeValue, masked });
      n.nodeValue = masked;
      const parent = n.parentElement;
      if (parent && parent.textContent.trim() === MASK) { parent.setAttribute('aria-label', 'Valor oculto'); parent.dataset.gfMasked = '1'; }
    });
    resume();
  }

  function unmask() {
    pause();
    stored.forEach((v, n) => { if (n.isConnected && n.nodeValue === v.masked) n.nodeValue = v.real; });
    stored.clear();
    document.querySelectorAll('[data-gf-masked]').forEach(el => { el.removeAttribute('aria-label'); delete el.dataset.gfMasked; });
    resume();
  }

  /* O observador volta a esconder valores novos (depois de um carregamento). Pára-se enquanto nós mesmos alteramos o texto. */
  function pause() { observer?.disconnect(); }
  function resume() {
    if (!observer) observer = new MutationObserver(() => { if (!scheduled && isHidden()) { scheduled = true; requestAnimationFrame(() => { scheduled = false; mask(); }); } });
    observer.observe(document.querySelector('main') || document.body, { childList: true, subtree: true, characterData: true });
  }

  /* ---------- botões ---------- */
  function syncButtons() {
    const hidden = isHidden();
    document.querySelectorAll('[data-privacy-toggle]').forEach(b => {
      b.setAttribute('aria-pressed', hidden ? 'true' : 'false');
      const label = hidden ? 'Mostrar valores' : 'Ocultar valores';
      b.setAttribute('aria-label', label);
      b.title = label;
      b.classList.toggle('is-off', hidden);                          // olho riscado quando os valores estão escondidos
    });
  }

  /** Guarda a preferência no servidor (não bloqueia a interface se falhar). */
  function persist(value) {
    try { localStorage.setItem('gf-hide-values', value ? '1' : '0'); } catch (e) {}
    const token = (typeof csrf !== 'undefined' && csrf) || window.GF_CSRF || '';
    if (!token) return;
    fetch('api/me.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'prefs_update', hide_values: value, csrf: token }) }).catch(() => {});
  }

  function set(value, announce = true) {
    html.toggleAttribute('data-hide-values', value);
    value ? mask() : unmask();
    syncButtons();
    persist(value);
    if (announce) window.UX?.toast('info', value ? 'Valores ocultos' : 'Valores visíveis', { timeout: 2200 });
  }

  /* Pequeno olho dentro de cada cartão de totais (faz o mesmo que o botão do cabeçalho). */
  function addEyes() {
    document.querySelectorAll('#overview .summary-card, #cashflow .summary-card, #reports .summary-card, #clients .summary-card').forEach(card => {
      if (card.querySelector('.eye-btn')) return;
      const b = document.createElement('button');
      b.type = 'button'; b.className = 'eye-btn'; b.dataset.privacyToggle = '';
      b.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/><path class="slash" d="M4 4l16 16"/></svg>';
      card.classList.add('has-eye');
      card.appendChild(b);
    });
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-privacy-toggle]');
    if (b) set(!isHidden());
  });

  addEyes();
  syncButtons();
  if (isHidden()) { mask(); } else { resume(); }
  window.GFPrivacy = { set, isHidden, refresh: () => { addEyes(); syncButtons(); if (isHidden()) mask(); } };
})();
