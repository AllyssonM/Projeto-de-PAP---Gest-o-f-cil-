/* =========================================================
   JANELA "PREPARAR RELATÓRIO"
   Clique em "Imprimir / Guardar PDF" -> abre a janela -> animação -> Cancelar | Imprimir / Guardar PDF.
   A impressão em si continua a ser feita pela função ORIGINAL printReport() (app.js),
   exatamente como antes: esta janela só muda o que acontece entre o clique e a impressão.
   ========================================================= */
(function () {
  'use strict';
  const trigger = $('#report-print-btn'), modal = $('#print-modal');
  if (!trigger || !modal) return;

  const card = $('#print-card'), goBtn = $('#print-go'), cancelBtn = $('#print-cancel'), xBtn = $('#print-x'), live = $('#print-live');
  const READY_MS = 2600;                                   // fim da animação (ver print-modal.css)

  let isOpen = false, timer = 0, closeTimer = 0, lastFocus = null;

  function showReady() {
    if (!isOpen) return;
    card.classList.add('is-ready');
    goBtn.disabled = false; cancelBtn.disabled = false;
    live.textContent = 'O seu relatório está pronto para ser impresso ou guardado.';
    goBtn.focus({ preventScroll: true });                  // a ação principal fica pronta com o teclado
  }

  function open() {
    if (isOpen) return;
    clearTimeout(closeTimer);
    lastFocus = document.activeElement;
    isOpen = true;
    card.classList.remove('is-ready', 'is-playing');
    goBtn.disabled = true; cancelBtn.disabled = true;
    live.textContent = 'A preparar o relatório…';
    modal.classList.remove('hidden');
    void card.offsetWidth;                                 // reinicia a animação se já tiver corrido antes
    card.classList.add('is-playing');
    requestAnimationFrame(() => requestAnimationFrame(() => { modal.classList.add('show'); xBtn.focus({ preventScroll: true }); }));
    timer = setTimeout(showReady, reduced() ? 60 : READY_MS);
  }

  /** Fecha a janela (com a saída suave) e, no fim, corre o callback. */
  function close(afterClosed) {
    if (!isOpen) return;
    isOpen = false;
    clearTimeout(timer);                                   // interrompe a animação
    modal.classList.remove('show');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    closeTimer = setTimeout(() => {
      modal.classList.add('hidden');                       // escondida ANTES de imprimir
      card.classList.remove('is-playing', 'is-ready');
      if (afterClosed) afterClosed();
    }, reduced() ? 0 : 280);
  }

  // Abrir: se algo falhar, a impressão original continua a funcionar (a página nunca fica bloqueada).
  trigger.addEventListener('click', () => {
    try { open(); }
    catch (e) { console.error(e); modal.classList.add('hidden'); isOpen = false; printReport(); }
  });

  cancelBtn.addEventListener('click', () => close());      // não imprime nada
  xBtn.addEventListener('click', () => close());
  modal.addEventListener('click', e => { if (e.target === modal) close(); });
  goBtn.addEventListener('click', () => { if (!goBtn.disabled) close(() => printReport()); });   // a função original

  // Esc e foco preso dentro da janela (captura: passa à frente do chat e do aviso de reunião)
  document.addEventListener('keydown', e => {
    if (!isOpen) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); return; }
    if (e.key === 'Tab') {
      const f = [...card.querySelectorAll('button')].filter(b => !b.disabled && b.offsetParent !== null);
      if (!f.length) { e.preventDefault(); return; }
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || !card.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || !card.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    }
  }, true);
})();
