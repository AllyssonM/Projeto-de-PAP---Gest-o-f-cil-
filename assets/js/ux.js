/* =========================================================================
   COMPONENTES COMUNS DE INTERAÇÃO  (assets/js/ux.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  pequenas peças reutilizadas em todo o site, para que tudo se
               comporte da mesma maneira:
     UX.toast(tipo, texto)    aviso que aparece e desaparece sozinho
                              (tipo: 'success' | 'warn' | 'error' | 'info')
     UX.confirm({...})        janela de confirmação (devolve uma Promise)
     efeito "ripple"          onda ao clicar nos botões
   ACESSIBILIDADE: os avisos são anunciados por leitores de ecrã (aria-live),
     as janelas prendem o foco e fecham com Esc, e as animações respeitam
     "reduzir movimento" (prefers-reduced-motion).
   ESTILOS: assets/css/ux.css (vidro, cores do tema).
   ========================================================================= */
(function () {
  'use strict';

  /* ------------------------------ TOASTS ------------------------------ */
  const ICONS = {
    success: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    warn: '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17.5v.01"/>',
    error: '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
    info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/>',
  };
  let box = null;

  function toastBox() {
    if (box) return box;
    box = document.createElement('div');
    box.className = 'ux-toasts';
    box.setAttribute('role', 'region');
    box.setAttribute('aria-label', 'Notificações');
    document.body.appendChild(box);
    return box;
  }

  /** Mostra um aviso. Erros demoram mais e são anunciados com mais urgência (role="alert"). */
  function toast(type, text, opts = {}) {
    const t = ICONS[type] ? type : 'info';
    const el = document.createElement('div');
    el.className = `ux-toast ${t}`;
    el.setAttribute('role', t === 'error' ? 'alert' : 'status');
    el.innerHTML = `<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[t]}</svg>
      <div class="ux-toast-text">${opts.title ? `<strong>${esc(opts.title)}</strong>` : ''}<span>${esc(text)}</span></div>
      <button type="button" class="ux-toast-x" aria-label="Fechar aviso">×</button>`;
    const area = toastBox();
    while (area.children.length >= 4) area.firstChild.remove();                    // no máximo 4 ao mesmo tempo
    area.appendChild(el);
    requestAnimationFrame(() => el.classList.add('in'));

    let timer = 0;
    const close = () => { clearTimeout(timer); el.classList.remove('in'); setTimeout(() => el.remove(), reduced() ? 0 : 220); };
    const start = () => { timer = setTimeout(close, opts.timeout ?? (t === 'error' ? 7000 : 4200)); };
    el.querySelector('.ux-toast-x').onclick = close;
    el.addEventListener('mouseenter', () => clearTimeout(timer));                  // parado enquanto o rato está por cima
    el.addEventListener('mouseleave', start);
    start();
    return close;
  }

  /* ------------------------------ CONFIRMAR ------------------------------ */
  /**
   * Janela de confirmação.
   *   UX.confirm({ title, text, confirmLabel, cancelLabel, danger, input })
   *   - danger: true  -> botão de confirmar em vermelho/coral (ações destrutivas)
   *   - input: { type:'password', label:'Palavra-passe atual' } -> pede um valor
   * Devolve uma Promise: false se cancelou; true (ou o texto escrito, se houver "input") se confirmou.
   */
  function confirmDialog(o = {}) {
    return new Promise(resolve => {
      const last = document.activeElement;
      const modal = document.createElement('div');
      modal.className = 'reminder-modal ux-confirm';
      modal.setAttribute('role', 'alertdialog');
      modal.setAttribute('aria-modal', 'true');
      const id = 'ux' + Math.random().toString(36).slice(2, 8);
      modal.setAttribute('aria-labelledby', id + 't');
      modal.setAttribute('aria-describedby', id + 'd');
      modal.innerHTML = `<div class="reminder-card ux-confirm-card">
          <h2 id="${id}t">${esc(o.title || 'Tens a certeza?')}</h2>
          <p id="${id}d" class="muted">${esc(o.text || '')}</p>
          ${o.input ? `<label class="ux-confirm-input">${esc(o.input.label || '')}<input type="${o.input.type || 'text'}" autocomplete="${o.input.type === 'password' ? 'current-password' : 'off'}" ${o.input.maxlength ? `maxlength="${o.input.maxlength}"` : ''}></label>` : ''}
          <p class="ux-confirm-error" role="alert" hidden></p>
          <div class="ux-confirm-actions">
            <button type="button" class="button secondary" data-no>${esc(o.cancelLabel || 'Cancelar')}</button>
            <button type="button" class="button ${o.danger ? 'danger' : 'primary'}" data-yes>${esc(o.confirmLabel || 'Confirmar')}</button>
          </div></div>`;
      document.body.appendChild(modal);
      const input = modal.querySelector('input');
      const done = value => {
        document.removeEventListener('keydown', onKey, true);
        modal.classList.remove('show');
        setTimeout(() => { modal.remove(); last?.focus?.(); }, reduced() ? 0 : 260);
        resolve(value);
      };
      const yes = () => {
        if (o.input && !input.value.trim()) { const err = modal.querySelector('.ux-confirm-error'); err.textContent = 'Preenche este campo para continuar.'; err.hidden = false; input.focus(); return; }
        done(o.input ? input.value : true);
      };
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); done(false); }
        if (e.key === 'Enter' && e.target === input) { e.preventDefault(); yes(); }
        if (e.key === 'Tab') {                                                      // prende o foco dentro da janela
          const f = [...modal.querySelectorAll('input, button')];
          const first = f[0], lastEl = f[f.length - 1];
          if (e.shiftKey && document.activeElement === first) { e.preventDefault(); lastEl.focus(); }
          else if (!e.shiftKey && document.activeElement === lastEl) { e.preventDefault(); first.focus(); }
        }
      }
      document.addEventListener('keydown', onKey, true);
      modal.querySelector('[data-no]').onclick = () => done(false);
      modal.querySelector('[data-yes]').onclick = yes;
      modal.addEventListener('click', e => { if (e.target === modal) done(false); });
      requestAnimationFrame(() => requestAnimationFrame(() => { modal.classList.add('show'); (input || modal.querySelector(o.danger ? '[data-no]' : '[data-yes]')).focus(); }));
    });
  }

  /* ------------------------------ JANELA GENÉRICA ------------------------------ */
  /**
   * Abre uma janela de vidro com o conteúdo HTML dado (o chamador escapa o que vier de utilizadores).
   * Devolve { root, close }. Fecha com Esc ou clicando fora; prende o foco lá dentro e devolve-o ao fechar.
   * O foco inicial vai para o elemento com data-autofocus (se houver) ou para o primeiro campo/botão.
   * O nome da janela (para leitores de ecrã) é o primeiro título (h1-h3) ou a opção "label".
   */
  function modal(html, { onClose, wide, label } = {}) {
    const last = document.activeElement;
    const root = document.createElement('div');
    root.className = 'reminder-modal ux-confirm';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.innerHTML = `<div class="reminder-card ap-modal-card${wide ? ' wide' : ''}">${html}</div>`;
    // Nome acessível da janela: o primeiro título que tiver, ou o "label" dado (um leitor de ecrã diz-o ao abrir).
    const heading = root.querySelector('h1, h2, h3');
    if (heading) { heading.id ||= 'ux-dlg-' + Math.random().toString(36).slice(2, 8); root.setAttribute('aria-labelledby', heading.id); }
    else if (label) root.setAttribute('aria-label', label);
    document.body.appendChild(root);
    const close = value => {
      document.removeEventListener('keydown', onKey, true);
      root.classList.remove('show');
      setTimeout(() => { root.remove(); last?.focus?.(); }, reduced() ? 0 : 260);
      onClose?.(value);
    };
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
      if (e.key === 'Tab') {
        const f = [...root.querySelectorAll('button, input, select, textarea, [href]')].filter(x => !x.disabled && x.offsetParent !== null);
        if (!f.length) return;
        const first = f[0], end = f[f.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); end.focus(); }
        else if (!e.shiftKey && document.activeElement === end) { e.preventDefault(); first.focus(); }
      }
    }
    document.addEventListener('keydown', onKey, true);
    root.addEventListener('click', e => { if (e.target === root) close(false); });
    requestAnimationFrame(() => requestAnimationFrame(() => { root.classList.add('show'); (root.querySelector('[data-autofocus]') || root.querySelector('input, select, button'))?.focus(); }));
    return { root, close };
  }

  /* ------------------------------ RIPPLE ------------------------------ */
  // Onda de luz no ponto onde se clica (botões, teclas da calculadora, atalhos).
  document.addEventListener('pointerdown', e => {
    if (reduced()) return;
    const target = e.target.closest?.('.button, .key, .quick-action, .ai-chip');
    if (!target || target.disabled) return;
    const r = target.getBoundingClientRect();
    const dot = document.createElement('span');
    dot.className = 'ux-ripple';
    const size = Math.max(r.width, r.height) * 1.6;
    dot.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - r.left - size / 2}px;top:${e.clientY - r.top - size / 2}px`;
    if (getComputedStyle(target).position === 'static') target.style.position = 'relative';
    target.style.overflow = 'hidden';
    target.appendChild(dot);
    setTimeout(() => dot.remove(), 600);
  }, { passive: true });

  window.UX = { toast, confirm: confirmDialog, modal, esc };
})();
