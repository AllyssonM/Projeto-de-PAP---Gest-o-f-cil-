/* =========================================================================
   REGISTO RÁPIDO  (assets/js/quick-add.js)
   -------------------------------------------------------------------------
   O botão "+" (canto inferior direito) abre uma janela pequena para registar uma venda ou uma despesa em 3 toques:
   escolhe Venda ou Despesa, escreve o valor, Guardar. A descrição é opcional (fica "Venda" ou "Despesa").
   Pergunta também como foi pago (dinheiro, MB Way, cartão, transferência): o Fecho do dia usa-o para saber o que devia estar na caixa.
   Grava um movimento já realizado, com a data e hora de agora, em api/data.php?module=transactions.
   Só aparece a quem vê o Fluxo de caixa. Também se abre com window.GFQuickAdd.open() (usado pela paleta Ctrl+K).
   ========================================================================= */
(function () {
  'use strict';
  const u = window.GF_USER || {};
  if (!(u.isOwner || (u.permissions || []).includes('cashflow'))) return;
  const fab = document.createElement('button');
  fab.type = 'button'; fab.className = 'quick-fab'; fab.id = 'quick-fab';
  fab.setAttribute('aria-label', 'Registo rápido: nova venda ou despesa'); fab.dataset.tip = 'Registo rápido';
  fab.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>';
  document.body.appendChild(fab);

  const nowLocal = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };

  function open(type = 'income') {
    const { root, close } = UX.modal(`<h2>Registo rápido</h2>
      <div class="seg quick-type" role="group" aria-label="Tipo de movimento">
        <button type="button" data-type="income" class="${type === 'income' ? 'on' : ''}" aria-pressed="${type === 'income'}">Venda</button>
        <button type="button" data-type="expense" class="${type === 'expense' ? 'on' : ''}" aria-pressed="${type === 'expense'}">Despesa</button>
      </div>
      <div class="seg quick-method" role="group" aria-label="Como foi pago">
        <button type="button" data-method="cash" class="on" aria-pressed="true">Dinheiro</button><button type="button" data-method="mbway" aria-pressed="false">MB Way</button>
        <button type="button" data-method="card" aria-pressed="false">Cartão</button><button type="button" data-method="transfer" aria-pressed="false">Transf.</button>
      </div>
      <label class="ux-confirm-input">Valor (€)<input name="amount" inputmode="decimal" autocomplete="off" placeholder="0,00" data-autofocus></label>
      <label class="ux-confirm-input">Descrição (opcional)<input name="description" maxlength="200" autocomplete="off"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button primary" data-yes>Guardar</button></div>`);
    let kind = type, method = 'cash';
    const amount = root.querySelector('[name=amount]'), desc = root.querySelector('[name=description]'), err = root.querySelector('.ap-form-error');
    root.querySelectorAll('[data-type]').forEach(b => b.onclick = () => {
      kind = b.dataset.type;
      root.querySelectorAll('[data-type]').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on); });
      amount.focus();
    });
    root.querySelectorAll('[data-method]').forEach(b => b.onclick = () => {
      method = b.dataset.method;
      root.querySelectorAll('[data-method]').forEach(x => { const on = x === b; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on); });
      amount.focus();
    });
    root.querySelector('[data-no]').onclick = () => close(false);
    const save = async () => {
      const value = Number(String(amount.value).replace(',', '.'));
      if (!amount.value.trim() || !isFinite(value) || value <= 0) { err.textContent = 'Escreve um valor maior que zero.'; err.hidden = false; amount.focus(); return; }
      const btn = root.querySelector('[data-yes]'); btn.disabled = true;
      try {
        await api('data.php?module=transactions', { method: 'POST', body: JSON.stringify({
          type: kind, description: desc.value.trim() || (kind === 'income' ? 'Venda' : 'Despesa'), category: kind === 'income' ? 'Vendas' : 'Despesas',
          amount: value, status: 'paid', payment_method: method, occurred_at: nowLocal(), csrf }) });
        close(true);
        msg(`${kind === 'income' ? 'Venda' : 'Despesa'} de ${money.format(value)} registada.`);
        window.loadAll?.();
      } catch (e) { err.textContent = e.message; err.hidden = false; btn.disabled = false; }
    };
    root.querySelector('[data-yes]').onclick = save;
    root.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.tagName === 'INPUT') { e.preventDefault(); save(); } });
  }
  fab.onclick = () => open('income');
  window.GFQuickAdd = { open };
})();
