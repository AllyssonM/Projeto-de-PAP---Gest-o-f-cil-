/* =========================================================================
   FECHO DO DIA  (assets/js/closing.js)
   -------------------------------------------------------------------------
   O botão "Fechar o dia" (Visão geral) abre uma janela com o que entrou e saiu hoje, o dinheiro que devia estar na
   caixa (só movimentos pagos em dinheiro) e um campo para o dinheiro contado. Mostra a diferença na hora e grava
   o fecho em api/closing.php. Pode refazer-se o mesmo dia. Também mostra os últimos fechos.
   ========================================================================= */
(function () {
  'use strict';
  const btn = $('#close-day-btn');
  if (!btn) return;
  const eur = n => money.format(Number(n));
  const fmtDay = iso => new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(iso + 'T12:00:00'));

  async function open() {
    let d;
    try { d = await api('closing.php'); } catch (e) { return msg(e.message, true); }
    const t = d.totals, prev = d.closed;
    const { root, close } = UX.modal(`<h2>Fechar o dia</h2>
      <p class="muted">${esc(fmtDay(d.day))}${prev ? ' · já fechado (podes refazer)' : ''}</p>
      <ul class="closing-totals">
        <li><span>Entradas</span><b>${eur(t.income)}</b></li><li><span>Saídas</span><b>${eur(t.expense)}</b></li>
        <li><span>Dinheiro que devia estar na caixa</span><b id="cl-expected">${eur(t.expected_cash)}</b></li>
      </ul>
      ${t.without_method ? `<p class="muted closing-warn">${t.without_method} movimento(s) de hoje não têm método de pagamento e não entram na conta do dinheiro.</p>` : ''}
      <label class="ux-confirm-input">Dinheiro contado na caixa (€)<input name="counted" inputmode="decimal" autocomplete="off" placeholder="0,00" data-autofocus value="${prev ? esc(String(prev.counted_cash)) : ''}"></label>
      <p class="closing-diff" id="cl-diff" aria-live="polite"></p>
      <label class="ux-confirm-input">Nota (opcional)<input name="note" maxlength="255" autocomplete="off" value="${prev && prev.note ? esc(prev.note) : ''}"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button primary" data-yes>${prev ? 'Refazer o fecho' : 'Fechar o dia'}</button></div>
      ${d.recent.length ? `<details class="closing-recent"><summary>Últimos fechos</summary><ul>${d.recent.slice(0, 7).map(c => `<li><span>${esc(fmtDay(c.day))}</span><span>contado ${eur(c.counted_cash)}</span><b class="${Number(c.difference) === 0 ? 'ok' : 'off'}">${Number(c.difference) === 0 ? 'certo' : (Number(c.difference) > 0 ? '+' : '') + eur(c.difference)}</b></li>`).join('')}</ul></details>` : ''}`);
    const counted = root.querySelector('[name=counted]'), diff = root.querySelector('#cl-diff'), err = root.querySelector('.ap-form-error');
    const num = () => Number(String(counted.value).replace(',', '.'));
    const showDiff = () => {
      if (!counted.value.trim() || !isFinite(num())) { diff.textContent = ''; return; }
      const x = Math.round((num() - t.expected_cash) * 100) / 100;
      diff.textContent = x === 0 ? '✔ Está tudo certo.' : x > 0 ? `Sobram ${eur(x)} na caixa.` : `Faltam ${eur(-x)} na caixa.`;
      diff.className = 'closing-diff ' + (x === 0 ? 'ok' : 'off');
    };
    counted.addEventListener('input', showDiff); showDiff();
    root.querySelector('[data-no]').onclick = () => close(false);
    const save = async () => {
      if (!counted.value.trim() || !isFinite(num()) || num() < 0) { err.textContent = 'Escreve o dinheiro que contaste na caixa (pode ser 0).'; err.hidden = false; counted.focus(); return; }
      const b = root.querySelector('[data-yes]'); b.disabled = true;
      try {
        const r = await api('closing.php', { method: 'POST', body: JSON.stringify({ day: d.day, counted_cash: num(), note: root.querySelector('[name=note]').value, csrf }) });
        close(true);
        msg(Number(r.difference) === 0 ? 'Dia fechado: a caixa está certa.' : `Dia fechado. Diferença na caixa: ${eur(r.difference)}.`);
      } catch (e) { err.textContent = e.message; err.hidden = false; b.disabled = false; }
    };
    root.querySelector('[data-yes]').onclick = save;
    root.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); save(); } });
  }
  btn.addEventListener('click', open);
  window.GFClosing = { open };
})();
