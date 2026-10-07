/* =========================================================================
   ORÇAMENTOS POR CATEGORIA  (assets/js/budgets.js)
   -------------------------------------------------------------------------
   No Fluxo de caixa: define quanto queres gastar por mês em cada categoria e vê quanto já gastaste (barra de progresso).
   Verde até 80 %, amarelo de 80 a 100 %, vermelho acima do limite. Os que estão a acabar ou ultrapassados aparecem
   também nos alertas da Visão geral. Os valores vêm de api/budgets.php (cálculo no servidor, só despesas realizadas).
   ========================================================================= */
(function () {
  'use strict';
  const card = $('#budgets-card');
  if (!card) return;
  const u = window.GF_USER || {};
  if (!(u.isOwner || (u.permissions || []).includes('cashflow'))) return;
  card.hidden = false;
  const label = { ok: 'Dentro do orçamento', warn: 'A acabar', over: 'Ultrapassado' };
  const api_ = { alerts: [], items: [] };

  function render(d) {
    $('#budget-list').innerHTML = d.items.length ? d.items.map(b => `<li class="budget-item budget-${esc(b.status)}">
      <div class="budget-top"><strong>${esc(b.category)}</strong><span>${money.format(b.spent)} de ${money.format(b.monthly_limit)}</span></div>
      <div class="budget-bar" role="progressbar" aria-label="${esc(b.category)}: ${Math.round(b.percent)}% do orçamento gasto" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.min(100, Math.round(b.percent))}"><span style="width:${Math.min(100, b.percent)}%"></span></div>
      <div class="budget-bottom"><small>${esc(label[b.status])} · ${Math.round(b.percent)}%</small><small>${b.remaining >= 0 ? 'Restam ' + money.format(b.remaining) : 'Passaste ' + money.format(-b.remaining)}</small>
        <button type="button" class="button small danger" data-bdel="${b.id}" aria-label="Apagar orçamento de ${esc(b.category)}">Apagar</button></div></li>`).join('')
      : '<li class="muted">Ainda não definiste orçamentos. Escolhe uma categoria e um limite mensal.</li>';
    $('#budget-suggestions').innerHTML = d.suggestions.map(s => `<option value="${esc(s)}">`).join('');
    $$('#budget-list [data-bdel]').forEach(b => b.onclick = async () => {
      const it = d.items.find(x => String(x.id) === b.dataset.bdel);
      if (!await UX.confirm({ title: `Apagar o orçamento «${it.category}»?`, text: 'Os movimentos não são apagados.', confirmLabel: 'Apagar', danger: true })) return;
      try { await api(`budgets.php?id=${encodeURIComponent(it.id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) }); msg('Orçamento apagado.'); refresh(); } catch (e) { msg(e.message, true); }
    });
  }

  async function refresh() {
    try {
      const d = await api('budgets.php');
      api_.items = d.items;
      api_.alerts = d.items.filter(b => b.status !== 'ok').map(b => [b.status === 'over' ? 'late' : 'wait',
        b.status === 'over' ? `💸 Orçamento «${b.category}» ultrapassado: ${money.format(b.spent)} de ${money.format(b.monthly_limit)}.` : `⚠ Orçamento «${b.category}» a ${Math.round(b.percent)}%: restam ${money.format(b.remaining)}.`, 'cashflow']);
      render(d);
      window.renderCashAlerts?.();                       // atualiza os alertas da Visão geral
    } catch (e) { $('#budget-list').innerHTML = `<li class="muted">${esc(e.message)}</li>`; }
  }

  $('#budget-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(e.target)), out = $('#budget-msg');
    out.textContent = '';
    try { await api('budgets.php', { method: 'POST', body: JSON.stringify({ ...f, csrf }) }); msg('Orçamento guardado.'); e.target.reset(); refresh(); }
    catch (x) { out.textContent = x.message; }
  });

  window.GFBudgets = { get alerts() { return api_.alerts; }, refresh };
})();
