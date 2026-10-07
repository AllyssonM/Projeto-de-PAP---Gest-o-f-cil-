/* =========================================================
   Fluxo de caixa: filtro por mês, saldo inicial/final,
   previsto × realizado, categorias, alertas e exportação CSV.
   Usa o estado e os utilitários de app.js.
   ========================================================= */
const DEFAULT_CATEGORIES = ['Vendas', 'Serviços', 'Fornecedores', 'Renda', 'Salários', 'Pró-labore', 'Impostos', 'Água / luz / internet', 'Marketing', 'Transporte', 'Manutenção', 'Outros'];

const cf = {
  month: $('#cf-month'), type: $('#cf-type'), status: $('#cf-status'), search: $('#cf-search')
};
cf.month.value = nowLocal().slice(0, 7);

const monthOf = v => String(v || '').slice(0, 7);
const signed = t => (t.type === 'income' ? 1 : -1) * Number(t.amount);
const pct = (a, b) => b > 0 ? Math.min(100, a / b * 100) : 0;

function monthLabel(key) {
  const [y, m] = key.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { month: 'long', year: 'numeric' });
}

/* números do mês escolhido */
function monthFigures(key) {
  const paid = state.transactions.filter(isPaid);
  const inMonth = paid.filter(t => monthOf(t.occurred_at) === key);
  const opening = sum(paid.filter(t => monthOf(t.occurred_at) < key), signed);
  const income = sum(inMonth.filter(t => t.type === 'income'), t => t.amount);
  const expense = sum(inMonth.filter(t => t.type === 'expense'), t => t.amount);
  const planned = state.transactions.filter(t => !isPaid(t) && monthOf(t.occurred_at) === key);
  const bills = state.bills.filter(b => b.status !== 'paid' && monthOf(b.due_date) === key);
  const plannedIn = sum(planned.filter(t => t.type === 'income'), t => t.amount) + sum(bills.filter(b => b.direction === 'receivable'), b => b.amount);
  const plannedOut = sum(planned.filter(t => t.type === 'expense'), t => t.amount) + sum(bills.filter(b => b.direction === 'payable'), b => b.amount);
  const closing = opening + income - expense;
  return { inMonth, opening, income, expense, closing, plannedIn, plannedOut, projected: closing + plannedIn - plannedOut };
}

function filteredRows() {
  const key = cf.month.value, type = cf.type.value, status = cf.status.value;
  const q = cf.search.value.trim().toLowerCase();
  return state.transactions.filter(t =>
    (!key || monthOf(t.occurred_at) === key) &&
    (!type || t.type === type) &&
    (!status || (t.status || 'paid') === status) &&
    (!q || [t.description, t.category, t.client_name].some(v => String(v || '').toLowerCase().includes(q))));
}

function renderCashflowTable() {
  const rows = filteredRows();
  $('#transactions').innerHTML = rows.length ? rows.map(x => `<tr class="${isPaid(x) ? '' : 'planned-row'}">
    <td>${esc(x.description)}</td><td>${esc(x.category)}</td><td>${esc(x.client_name || '—')}</td>
    <td>${x.type === 'income' ? 'Entrada' : 'Saída'}</td>
    <td><span class="badge ${isPaid(x) ? 'ok' : 'wait'}">${isPaid(x) ? 'Realizado' : 'Previsto'}</span></td>
    <td class="${x.type === 'income' ? 'positive' : 'negative'} amount">${x.type === 'income' ? '+' : '-'} ${money.format(x.amount)}</td><td>${date(x.occurred_at)}</td>
    <td class="row-actions"><button type="button" class="button small secondary attach-btn" data-attach="transaction" data-id="${x.id}" data-title="${esc(x.description)}" aria-label="Recibos e anexos de ${esc(x.description)}" title="Recibos e anexos">📎</button>
      ${isPaid(x) ? '' : `<button class="button small secondary" data-confirm="${x.id}" title="Marcar como realizado">✓</button>`}
      ${GF.isOwner ? `<button class="delete-button" data-delete="${x.id}" aria-label="Eliminar movimento" title="Eliminar">×</button>` : ''}</td></tr>`).join('')
    : '<tr><td colspan="8">Sem movimentos para estes filtros.</td></tr>';
  $$('[data-delete]').forEach(b => b.onclick = () => deleteTransaction(b.dataset.delete));
  $$('[data-confirm]').forEach(b => b.onclick = () => confirmTransaction(b.dataset.confirm));
}

function categoryBars(list, cls) {
  const groups = {};
  list.forEach(t => { groups[t.category] = (groups[t.category] || 0) + Number(t.amount); });
  const entries = Object.entries(groups).sort((a, b) => b[1] - a[1]);
  const total = sum(entries, e => e[1]);
  return entries.length ? entries.map(([name, value]) => `<div class="cat-row">
      <div class="cat-label"><span>${esc(name)}</span><b>${money.format(value)} · ${pct(value, total).toFixed(0)}%</b></div>
      <div class="cat-bar ${cls}"><i style="width:${pct(value, total)}%"></i></div></div>`).join('')
    : '<p class="muted">Sem registos neste mês.</p>';
}

function renderCashflow() {
  const key = cf.month.value || nowLocal().slice(0, 7);
  const f = monthFigures(key);
  $('#cf-opening').textContent = money.format(f.opening);
  $('#cf-in').textContent = money.format(f.income);
  $('#cf-out').textContent = money.format(f.expense);
  $('#cf-closing').textContent = money.format(f.closing);
  $('#cf-closing').classList.toggle('negative', f.closing < 0);

  const total = $('#cf-forecast-total');
  total.textContent = 'Saldo previsto: ' + money.format(f.projected);
  total.classList.toggle('negative', f.projected < 0);
  const cmp = (label, done, todo, cls) => `<div class="cmp-row">
      <div class="cat-label"><span>${label}</span><b>${money.format(done)} de ${money.format(done + todo)}</b></div>
      <div class="cat-bar ${cls}"><i style="width:${pct(done, done + todo)}%"></i></div></div>`;
  $('#cf-compare').innerHTML = cmp('Entradas (realizado / previsto)', f.income, f.plannedIn, 'in')
    + cmp('Saídas (realizado / previsto)', f.expense, f.plannedOut, 'out');

  $('#cf-cat-income').innerHTML = categoryBars(f.inMonth.filter(t => t.type === 'income'), 'in');
  $('#cf-cat-expense').innerHTML = categoryBars(f.inMonth.filter(t => t.type === 'expense'), 'out');

  // Sugestões = categorias do RAMO (profiles.js) + as que já foram usadas em movimentos.
  const base = window.GFP ? window.GFP.current().categories : DEFAULT_CATEGORIES;
  const cats = new Set([...base, ...state.transactions.map(t => t.category)]);
  $('#category-list').innerHTML = [...cats].map(c => `<option value="${esc(c)}">`).join('');

  renderCashflowTable();
  renderCashAlerts();
}

/* alertas na visão geral */
function renderCashAlerts() {
  const today = nowLocal().slice(0, 10);
  const inAWeek = new Date(); inAWeek.setDate(inAWeek.getDate() + 7);
  const week = inAWeek.toLocaleDateString('sv-SE');
  const pending = state.bills.filter(b => b.status !== 'paid');
  const late = pending.filter(b => b.due_date < today);
  const soon = pending.filter(b => b.due_date >= today && b.due_date <= week);
  const f = monthFigures(today.slice(0, 7));
  const alerts = [];
  if (late.length) alerts.push(['late', `⚠ ${late.length} conta(s) em atraso, num total de ${money.format(sum(late, b => b.amount))}.`, 'accounts']);
  if (soon.length) alerts.push(['wait', `⏰ ${soon.length} conta(s) vencem nos próximos 7 dias (${money.format(sum(soon, b => b.amount))}).`, 'accounts']);
  if (f.projected < 0) alerts.push(['late', `📉 O saldo previsto para este mês é negativo (${money.format(f.projected)}). Revê as saídas previstas.`, 'cashflow']);
  alerts.push(...(window.GFBudgets?.alerts || []));                         // orçamentos a acabar ou ultrapassados (budgets.js)
  $('#cash-alerts').innerHTML = alerts.map(([cls, text, go]) => `<button type="button" class="cash-alert ${cls}" data-alert-go="${go}">${esc(text)}</button>`).join('');
  $$('[data-alert-go]').forEach(b => b.onclick = () => showSection(b.dataset.alertGo));
}

async function confirmTransaction(id) {
  try {
    await api('data.php?module=transaction_confirm', { method: 'POST', body: JSON.stringify({ id, csrf }) });
    msg('Movimento marcado como realizado.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}

/* exportar o que está filtrado (abre no Excel) */
function exportCsv() {
  const rows = filteredRows();
  const cell = v => `"${String(v ?? '').replace(/"/g, '""')}"`;
  // Texto escrito por pessoas (ou importado de um banco) que comece por = + - @ seria executado como FÓRMULA no Excel
  // ("=HYPERLINK(...)"). Um apóstrofo à frente torna-o texto. Só nas colunas de texto: o valor (-12,50) tem de continuar a ser número.
  const text = v => cell(/^[=+\-@\t\r]/.test(String(v ?? '')) ? "'" + v : v);
  const lines = [['Data', 'Descrição', 'Categoria', 'Cliente', 'Tipo', 'Estado', 'Valor'].map(cell).join(';')]
    .concat(rows.map(t => [cell(t.occurred_at), text(t.description), text(t.category), text(t.client_name || ''), cell(t.type === 'income' ? 'Entrada' : 'Saída'),
      cell(isPaid(t) ? 'Realizado' : 'Previsto'), cell((signed(t)).toFixed(2).replace('.', ','))].join(';')));
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `fluxo-de-caixa-${cf.month.value || 'todos'}.csv`;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  msg(`${rows.length} movimento(s) exportado(s).`);
}

/* secção do relatório (chamada por renderReport em app.js) */
function cashflowReport(line) {
  const key = nowLocal().slice(0, 7), f = monthFigures(key);
  return `<div class="report-summary"><h3>Fluxo de caixa — ${esc(monthLabel(key))}</h3>
    ${line('Saldo inicial', money.format(f.opening))}
    ${line('Entradas realizadas', money.format(f.income), 'positive')}
    ${line('Saídas realizadas', money.format(f.expense), 'negative')}
    ${line('Saldo final', money.format(f.closing), f.closing < 0 ? 'negative' : '')}
    ${line('Ainda previsto a entrar', money.format(f.plannedIn))}
    ${line('Ainda previsto a sair', money.format(f.plannedOut))}
    ${line('Saldo previsto no fim do mês', money.format(f.projected), f.projected < 0 ? 'negative' : 'positive')}</div>`;
}

[cf.month, cf.type, cf.status].forEach(el => el.addEventListener('change', renderCashflow));
cf.search.addEventListener('input', renderCashflowTable);
$('#cf-export').onclick = exportCsv;
