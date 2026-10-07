/* =========================================================================
   CONTAS RECORRENTES  (assets/js/recurring.js)
   -------------------------------------------------------------------------
   Na aba Contas: lista, cria, pausa e elimina contas recorrentes (renda, salários, seguros...).
   O servidor (api/recurring.php) gera as contas pendentes sozinho; depois de mexer, recarregamos as contas.
   ========================================================================= */
(function () {
  'use strict';
  const card = $('#recurring-card');
  const gu = window.GF_USER || {};
  if (!card || !(gu.isOwner || (gu.permissions || []).includes('accounts'))) return;      // sem a permissão de Contas nem se pergunta ao servidor (evita pedidos recusados)
  const FREQ = { weekly: 'todas as semanas', monthly: 'todos os meses', quarterly: 'todos os trimestres', yearly: 'todos os anos' };
  const fmtDate = iso => iso ? new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(iso + 'T12:00:00')) : '';
  const isOwner = window.GF_USER?.isOwner;
  let items = [];

  function render() {
    $('#recurring-list').innerHTML = items.length ? items.map(r => `<li class="recurring-item${Number(r.active) ? '' : ' paused'}">
      <span class="recurring-main"><strong>${esc(r.title)}</strong><small>${r.direction === 'payable' ? 'A pagar' : 'A receber'} ${esc(FREQ[r.frequency])} · ${money.format(Number(r.amount))}${r.counterparty ? ' · ' + esc(r.counterparty) : ''}</small></span>
      <span class="recurring-next">${Number(r.active) ? `Próxima: <b>${esc(fmtDate(r.next_due))}</b>` : (r.end_date && r.next_due > r.end_date ? 'Terminada' : 'Em pausa')}</span>
      <span class="recurring-actions"><button type="button" class="button small secondary" data-toggle="${r.id}">${Number(r.active) ? 'Pausar' : 'Retomar'}</button>${isOwner ? `<button type="button" class="button small danger" data-del="${r.id}">Eliminar</button>` : ''}</span></li>`).join('')
      : '<li class="muted">Ainda não tens contas recorrentes.</li>';
    $$('#recurring-list [data-toggle]').forEach(b => b.onclick = async () => {
      const r = items.find(x => String(x.id) === b.dataset.toggle);
      try { await api('recurring.php', { method: 'POST', body: JSON.stringify({ action: 'toggle', id: r.id, active: Number(r.active) ? 0 : 1, csrf }) }); await load(); } catch (e) { msg(e.message, true); }
    });
    $$('#recurring-list [data-del]').forEach(b => b.onclick = async () => {
      const r = items.find(x => String(x.id) === b.dataset.del);
      if (!await UX.confirm({ title: `Eliminar «${r.title}»?`, text: 'Deixa de gerar contas novas. As contas já criadas ficam.', confirmLabel: 'Eliminar', danger: true })) return;
      try { await api(`recurring.php?id=${encodeURIComponent(r.id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) }); msg('Conta recorrente eliminada.'); await load(); } catch (e) { msg(e.message, true); }
    });
  }

  async function load() {
    try { items = (await api('recurring.php')).items; render(); window.loadAll?.(); } catch (e) { $('#recurring-list').innerHTML = `<li class="muted">${esc(e.message)}</li>`; }
  }

  $('#recurring-form').addEventListener('submit', async e => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(e.target)), out = $('#recurring-msg');
    out.textContent = '';
    try {
      const r = await api('recurring.php', { method: 'POST', body: JSON.stringify({ ...f, action: 'save', csrf }) });
      msg(r.generated ? `Conta recorrente criada. Já geramos ${r.generated} conta(s) pendente(s).` : 'Conta recorrente criada.');
      e.target.reset(); await load();
    } catch (x) { out.textContent = x.message; }
  });
  document.addEventListener('gf:section', ev => { if (ev.detail === 'accounts') load(); });
  load();
})();
