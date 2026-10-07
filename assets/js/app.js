/* =========================================================
   Painel (dashboard.php) - liga a interface à API PHP/MySQL
   ========================================================= */
const money = new Intl.NumberFormat((window.LUMINA_LOCALE || 'pt-PT'), { style: 'currency', currency: 'EUR' });

let clients = [];
const state = { transactions: [], summary: { income: 0, expense: 0, balance: 0 }, accounts: [], bills: [], products: [] };

/* ---------- utilitários ---------- */
function formData(form) { return Object.fromEntries(new FormData(form).entries()); }
function date(v) { return v ? new Date(String(v).replace(' ', 'T')).toLocaleString((window.LUMINA_LOCALE || 'pt-PT'), { dateStyle: 'short', timeStyle: 'short' }) : '—'; }
function shortDate(v) { return v ? new Date(String(v).replace(' ', 'T')).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT')) : '—'; }
function nowLocal() {
  const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
  return d.toISOString().slice(0, 16);
}
const sum = (list, fn) => list.reduce((total, x) => total + Number(fn(x) || 0), 0);
const isPaid = t => (t.status || 'paid') === 'paid';

/* ---------- permissões (definidas pelo servidor em dashboard.php) ---------- */
const GF = window.GF_USER || { isOwner: true, permissions: [] };
const can = module => GF.permissions.includes(module);
// só existe separador para as áreas a que o utilizador tem acesso
const hasTab = name => !!document.querySelector(`.nav-tab[data-section="${name}"]`);

/* ---------- navegação entre abas ---------- */
function showSection(name) {
  if (!hasTab(name)) return;
  $$('.nav-tab').forEach(b => b.classList.toggle('active', b.dataset.section === name));
  try { sessionStorage.setItem('gf-tab', name); } catch (e) {}      // lembra a aba ao atualizar a página
  moveTabGlass();
  $$('.section').forEach(s => s.classList.toggle('hidden', s.id !== name));
  if (name === 'calculator') calculate();
  if (name === 'reports') renderReport();
  // calendário, tempo, etc. ouvem este evento
  document.dispatchEvent(new CustomEvent('gf:section', { detail: name }));
}
/* Destaque de vidro da aba ativa: UM só elemento que desliza para a aba selecionada. */
function moveTabGlass() {
  const tabs = $('.tabs'), tab = $('.nav-tab.active');
  if (!tabs || !tab || !tab.offsetWidth) return;
  let g = tabs.querySelector('.tab-glass');
  if (!g) { g = document.createElement('span'); g.className = 'tab-glass'; g.setAttribute('aria-hidden', 'true'); tabs.prepend(g); }
  // O menu é uma coluna (menu lateral): o vidro cobre o botão inteiro. (Na barra horizontal antiga tinha 10 px a menos de altura.)
  const vertical = getComputedStyle(tabs).flexDirection === 'column';
  g.style.width = tab.offsetWidth + 'px';
  g.style.height = (vertical ? tab.offsetHeight : tab.offsetHeight - 10) + 'px';
  g.style.transform = `translate(${tab.offsetLeft}px, ${tab.offsetTop + (vertical ? 0 : 5)}px)`;
  g.classList.add('placed');
  if (!g.classList.contains('ready')) requestAnimationFrame(() => requestAnimationFrame(() => g.classList.add('ready')));   // 1.ª posição sem animação
}

$$('[data-section]').forEach(b => b.onclick = () => showSection(b.dataset.section));
$$('[data-go]').forEach(b => {
  if (!hasTab(b.dataset.go)) { b.hidden = true; return; }
  b.onclick = () => {
    showSection(b.dataset.go);
    if (b.dataset.focus) $(b.dataset.focus)?.focus();
  };
});

/* ---------- clientes ---------- */
function fillClientSelects() {
  ['transaction-client', 'bill-client'].forEach(id => {
    const el = $('#' + id);
    if (!el) return;
    el.innerHTML = '<option value="">Sem cliente</option>' + clients.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  });
}
function renderClients() {
  const box = $('#clients-list');
  box.innerHTML = clients.length ? clients.map(c => {
    const tx = c.transactions?.length || 0, docs = c.documents?.length || 0;
    return `<article class="client-card">
      <div class="client-head"><div><h3>${esc(c.name)}</h3><span>${esc(c.category || 'Cliente')}</span></div>
      <button class="button small secondary edit-client" data-id="${c.id}">Editar</button></div>
      <p>${esc(c.email || 'Sem email')} · ${esc(c.phone || 'Sem telefone')}</p>
      <p class="client-meta">NIF: ${esc(c.tax_number || '—')} · ${tx} movimentos · ${docs} contas</p>
      ${c.notes ? `<p class="client-notes">${esc(c.notes)}</p>` : ''}
      <details><summary>Ver histórico</summary><div class="history">
        ${c.transactions?.map(x => `<div>${x.type === 'income' ? '+' : '-'} ${esc(x.description)} — ${money.format(x.amount)}</div>`).join('') || '<small>Sem transações ligadas.</small>'}
        ${c.documents?.map(x => `<div>${x.direction === 'payable' ? 'A pagar' : 'A receber'}: ${esc(x.title)} — ${money.format(x.amount)}</div>`).join('') || ''}
      </div></details></article>`;
  }).join('') : '<p>Ainda não existem clientes.</p>';
  $$('.edit-client').forEach(b => b.onclick = () => editClient(Number(b.dataset.id)));
  $('#client-count').textContent = clients.length;
}
function editClient(id) {
  const c = clients.find(x => Number(x.id) === id);
  if (!c) return;
  const f = $('#client-form');
  Object.keys(c).forEach(k => { if (f.elements[k]) f.elements[k].value = c[k] ?? ''; });
  $('#client-form-title').textContent = window.GFP?.t('clientEditTitle', 'Editar cliente') ?? 'Editar cliente';
  $('#cancel-client').classList.remove('hidden');
  showSection('clients');
}
function resetClient() {
  const f = $('#client-form');
  f.reset();
  f.elements.id.value = '';
  $('#client-form-title').textContent = window.GFP?.t('clientNewTitle', 'Adicionar cliente') ?? 'Adicionar cliente';
  $('#cancel-client').classList.add('hidden');
}

/* ---------- visão geral ---------- */
function renderSummary() {
  const s = state.summary;
  const receivable = sum(state.bills.filter(b => b.direction === 'receivable' && b.status !== 'paid'), b => b.amount);
  $('#income').textContent = money.format(s.income);
  $('#expense').textContent = money.format(s.expense);
  $('#balance').textContent = money.format(s.balance);
  $('#receivable').textContent = money.format(receivable);
  $('#business-value').textContent = money.format(Math.max(0, s.balance * 12));
}
/* O gráfico "Movimento" (período e tipo à escolha) é desenhado por assets/js/charts.js. */
function renderChart() { GFCharts.mountOverview(); GFCharts.renderOverview(); }
function renderRecent() {
  const rows = state.transactions.filter(isPaid).slice(0, 5);
  $('#recent-transactions').innerHTML = rows.length ? rows.map(x => `<tr>
    <td>${esc(x.description)}</td><td>${esc(x.category)}</td><td>${shortDate(x.occurred_at)}</td>
    <td class="${x.type === 'income' ? 'positive' : 'negative'}">${x.type === 'income' ? '+' : '-'} ${money.format(x.amount)}</td></tr>`).join('')
    : '<tr><td colspan="4">Sem movimentos registados.</td></tr>';
}

/* ---------- fluxo de caixa (tabela, filtros e previsão em cashflow.js) ---------- */
async function deleteTransaction(id) {
  if (!await UX.confirm({ title: 'Eliminar este movimento?', text: 'Esta ação não se pode desfazer.', confirmLabel: 'Eliminar', danger: true })) return;
  try {
    await api(`data.php?module=transactions&id=${encodeURIComponent(id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) });
    msg('Movimento eliminado.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}

/* ---------- contas e estoque ---------- */
function renderAccounts() {
  const types = { cash: 'Caixa', bank: 'Banco', reserve: 'Reserva', investment: 'Investimento' };
  $('#accounts-list').innerHTML = state.accounts.length ? state.accounts.map(x => {
    const target = Number(x.target_amount || 0);
    const pct = target > 0 ? Math.min(100, Number(x.balance) / target * 100) : 0;
    return `<div class="account-card"><div><strong>${esc(x.name)}</strong><br><small>${esc(types[x.type] || x.type)}</small>
      ${target > 0 ? `<div class="goal"><i style="width:${pct.toFixed(0)}%"></i></div><small>${pct.toFixed(0)}% da meta de ${money.format(target)}</small>` : ''}
      </div><strong>${money.format(x.balance)}</strong></div>`;
  }).join('') : '<p>Sem contas.</p>';
  const today = nowLocal().slice(0, 10);
  $('#bills').innerHTML = state.bills.length ? state.bills.map(x => {
    const paid = x.status === 'paid', late = !paid && x.due_date < today;
    const label = paid ? (x.direction === 'payable' ? 'Paga' : 'Recebida') : late ? 'Em atraso' : 'Pendente';
    return `<tr>
    <td>${esc(x.title)}</td><td>${esc(x.client_name || '—')}</td><td>${x.direction === 'payable' ? 'A pagar' : 'A receber'}</td>
    <td>${money.format(x.amount)}</td><td>${shortDate(x.due_date)}</td>
    <td><span class="badge ${paid ? 'ok' : late ? 'late' : 'wait'}">${label}</span></td>
    <td class="row-actions"><button type="button" class="button small secondary attach-btn" data-attach="bill" data-id="${x.id}" data-title="${esc(x.title)}" aria-label="Recibos e anexos de ${esc(x.title)}" title="Recibos e anexos">📎</button>
      ${paid ? '' : `<button class="button small secondary" data-pay="${x.id}">${x.direction === 'payable' ? 'Pagar' : 'Receber'}</button>`}
      ${GF.isOwner ? `<button class="delete-button" data-delete-bill="${x.id}" aria-label="Eliminar conta" title="Eliminar">×</button>` : ''}</td></tr>`;
  }).join('') : '<tr><td colspan="7">Sem contas registadas.</td></tr>';
  $$('[data-pay]').forEach(b => b.onclick = () => payBill(b.dataset.pay));
  $$('[data-delete-bill]').forEach(b => b.onclick = () => deleteBill(b.dataset.deleteBill));
}
// pagar/receber uma conta lança automaticamente o movimento no fluxo de caixa
async function payBill(id) {
  try {
    await api('data.php?module=bill_pay', { method: 'POST', body: JSON.stringify({ id, csrf }) });
    msg('Conta liquidada e lançada no fluxo de caixa.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}
async function deleteBill(id) {
  if (!await UX.confirm({ title: 'Eliminar esta conta?', text: 'Esta ação não se pode desfazer.', confirmLabel: 'Eliminar', danger: true })) return;
  try {
    await api(`data.php?module=bills&id=${encodeURIComponent(id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) });
    msg('Conta eliminada.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}
const isLow = p => Number(p.stock_quantity) <= Number(p.minimum_stock);
/* ---------- produtos (imóveis, viaturas, serviços, viagens... conforme o ramo) ----------
   O que aparece em cada cartão vem do motor de ramos (profiles.js):
     - os campos próprios do ramo (tipologia, marca, duração...) aparecem como etiquetas;
     - o campo de ESTADO (ex.: Disponível/Reservado/Vendido) é uma lista que se muda no próprio cartão;
     - ramos com estoque em unidades mostram as unidades e o aviso de estoque baixo. */
const slug = text => String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-');

function productCard(x, ramo) {
  if (window.GFVar && window.GFVar.active()) return window.GFVar.cardHtml(x);           // ramos com tamanhos/cores (assets/js/variants.js)
  const attrs = x.attributes || {};
  // campos "normais" -> etiquetas; campo de estado -> lista para mudar
  const chips = ramo.fields.filter(f => !f.status && attrs[f.key]).map(f =>
    `<span class="attr-chip" title="${esc(f.label)}">${esc(attrs[f.key])}${f.suffix && f.type === 'number' ? esc(f.suffix) : ''}</span>`).join('');
  const statusField = ramo.fields.find(f => f.status);
  const statusSelect = statusField ? `<select class="attr-status st-${slug(attrs[statusField.key])}" aria-label="${esc(statusField.label)}"
      data-attr-id="${x.id}" data-attr-key="${statusField.key}">${statusField.options.map(o =>
        `<option${attrs[statusField.key] === o ? ' selected' : ''}>${esc(o)}</option>`).join('')}</select>` : '';
  // Com estoque em unidades, o cartão tem um editor (assets/js/stock.js); sem ele, mostra só o valor.
  const stock = ramo.trackStock ? (window.GFStock ? window.GFStock.cardHtml(x, ramo)
    : `<p><b>${x.stock_quantity}</b> ${esc(ramo.vocab.unit)}</p>
    <p class="${isLow(x) ? 'low' : ''}">${isLow(x) ? 'Estoque baixo' : 'Estoque normal'}</p>`) : '';
  return `<div class="product-card">
    <h3>${esc(x.name)}</h3><p>${esc(x.sku || 'Sem SKU')} · ${esc(x.category || 'Sem categoria')}</p>
    ${chips ? `<div class="attr-chips">${chips}</div>` : ''}
    <p class="product-price">${esc(ramo.vocab.fPrice)}: <b>${money.format(Number(x.sale_price) || 0)}</b></p>
    ${stock}${statusSelect}</div>`;
}

function renderProducts() {
  const ramo = window.GFP.current();
  const useVar = !!(window.GFVar && window.GFVar.active());
  const shown = useVar ? window.GFVar.apply(state.products) : state.products;           // pesquisa/filtros/ordenação do estoque
  if (useVar) window.GFVar.countLine(shown.length, state.products.length);
  $('#products').innerHTML = shown.length
    ? shown.map(x => productCard(x, ramo)).join('')
    : `<p>${esc(state.products.length ? 'Nenhum produto corresponde à pesquisa.' : window.GFP.t('stockEmpty', 'Sem produtos.'))}</p>`;
  // mudar o estado de um produto diretamente no cartão (ex.: Disponível -> Reservado)
  $$('.attr-status').forEach(sel => sel.onchange = () => setProductAttr(sel.dataset.attrId, sel.dataset.attrKey, sel.value));
  document.dispatchEvent(new CustomEvent('gf:products-rendered'));
}

/* Os produtos e o ramo chegam por pedidos diferentes à API e a ordem não é garantida. Se os produtos forem desenhados ANTES de o
   ramo ser aplicado, apareciam com o vocabulário geral (sem etiquetas nem estado). Por isso redesenham-se sempre que o ramo é aplicado. */
document.addEventListener('gf:profile', () => { if (document.querySelector('#products')) renderProducts(); });

/* Atualiza um campo do produto (api/data.php, módulo product_attr) sem o recriar. */
async function setProductAttr(id, key, value) {
  try {
    await api('data.php?module=product_attr', { method: 'POST', body: JSON.stringify({ id: Number(id), attributes: { [key]: value }, csrf }) });
    msg('Estado atualizado.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}

/* ---------- calculadora ---------- */
function calculate() {
  const cost = Number($('#calc-cost').value) || 0;
  const margin = Number($('#calc-margin').value) || 0;
  const vat = Number($('#calc-vat').value) || 0;
  const profit = cost * margin / 100;
  const net = cost + profit;
  const total = net * (1 + vat / 100);
  $('#calc-total').textContent = money.format(total);
  $('#calc-detail').textContent = `Lucro: ${money.format(profit)} · Preço sem IVA: ${money.format(net)} · IVA: ${money.format(total - net)}`;
}
['calc-cost', 'calc-margin', 'calc-vat'].forEach(id => $('#' + id).addEventListener('input', calculate));

/* ---------- relatório ---------- */
function renderReport() {
  const s = state.summary;
  const receivable = sum(state.bills.filter(b => b.direction === 'receivable' && b.status !== 'paid'), b => b.amount);
  const payable = sum(state.bills.filter(b => b.direction === 'payable' && b.status !== 'paid'), b => b.amount);
  const low = state.products.filter(isLow);
  const name = window.GF_PROFILE?.business_name || 'O meu negócio';
  const today = new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { dateStyle: 'long' }).format(new Date());
  const line = (label, value, cls = '') => `<div class="report-line"><span>${label}</span><strong class="${cls}">${value}</strong></div>`;
  const co = window.GF_USER?.company || {};
  const now = new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { dateStyle: 'short', timeStyle: 'short' }).format(new Date());
  $('#report-content').innerHTML = `
    <header class="report-header" style="--brand:${/^#[0-9a-f]{6}$/i.test(co.brandColor || '') ? co.brandColor : 'var(--primary)'}">
      <div class="report-brand"><img src="assets/img/lumina-mark-96.png" alt="" width="40" height="39"><strong>Lumina</strong></div>
      <div class="report-title"><h3>Relatório financeiro</h3><p>${esc(name)} · ${today}</p></div>
      ${co.logoUrl ? `<span class="report-company-logo"><img src="${esc(co.logoUrl)}" alt="Logo de ${esc(name)}"></span>` : ''}
    </header>
    <div class="report-summary"><h3>Saldo atual: ${money.format(s.balance)}</h3>
      ${line('Entradas', money.format(s.income), 'positive')}${line('Saídas', money.format(s.expense), 'negative')}</div>
    ${window.GFCharts ? GFCharts.reportChartHtml() : ''}
    ${typeof cashflowReport === 'function' ? cashflowReport(line) : ''}
    <div class="report-grid">
    <div class="report-summary"><h3>Contas</h3>
      ${line('A receber (pendente)', money.format(receivable))}${line('A pagar (pendente)', money.format(payable))}</div>
    <div class="report-summary"><h3>Clientes e estoque</h3>
      ${line('Clientes registados', clients.length)}${line('Produtos', state.products.length)}
      ${line('Produtos com estoque baixo', low.length, low.length ? 'negative' : '')}
      ${low.length ? `<p>${low.map(p => esc(p.name)).join(', ')}</p>` : ''}</div>
    </div>
    <div class="report-summary"><h3>Últimos movimentos</h3>
      ${state.transactions.filter(isPaid).slice(0, 8).map(x => line(`${shortDate(x.occurred_at)} · ${esc(x.description)}`, `${x.type === 'income' ? '+' : '-'} ${money.format(x.amount)}`, x.type === 'income' ? 'positive' : 'negative')).join('') || '<p>Sem movimentos.</p>'}</div>
    <p class="report-foot">Relatório gerado pelo Lumina em ${now}.</p>`;
  window.GFCharts?.mountReport();                                    // período e tipo do gráfico do relatório
}

/* ---------- imprimir / guardar PDF ----------
   Função original do botão do relatório (antes era onclick="window.print()").
   Continua a ser a única que imprime; a janela de preparação (print-modal.js) chama-a. */
function printReport() { window.print(); }

/* ---------- carregar tudo ---------- */
async function loadAll() {
  try {
    msg('A carregar...');
    // só pede o que o utilizador pode ver; o resto fica vazio
    const get = (allowed, url, empty) => allowed ? api(url) : Promise.resolve(empty);
    const none = { items: [] };
    const [t, s, a, b, p, c] = await Promise.all([
      get(can('cashflow'), 'data.php?module=transactions', none),
      get(can('cashflow'), 'data.php?module=summary', { summary: state.summary }),
      get(can('accounts'), 'data.php?module=accounts', none),
      get(can('accounts'), 'data.php?module=bills', none),
      get(can('stock'), 'data.php?module=products', none),
      get(can('clients') || can('cashflow') || can('accounts'), 'clients.php', none)
    ]);
    state.transactions = t.items; state.summary = s.summary; state.accounts = a.items;
    state.bills = b.items; state.products = p.items; state.stockSummary = p.summary || null; clients = c.items;

    fillClientSelects(); renderClients(); renderSummary(); renderChart(); renderRecent();
    renderCashflow(); renderAccounts(); renderProducts(); renderReport();
    window.GFBudgets?.refresh();                     // orçamentos do mês (e os seus alertas)
    window.GFAttach?.refresh();                      // contagem de anexos (📎 2)
    msg('Dados carregados.');
  } catch (e) { msg(e.message, true); }
}

/* ---------- formulários ---------- */
async function submitForm(form, module) {
  try {
    const body = { ...formData(form), csrf };
    for (const k of Object.keys(body)) if (k.startsWith('attr_')) delete body[k];        // os campos do ramo vão agrupados em "attributes"
    if (module === 'products') body.attributes = window.GFP.collectAttrs(form);
    await api(`data.php?module=${module}`, { method: 'POST', body: JSON.stringify(body) });
    form.reset();
    if (module === 'products') window.GFP.refreshForm();                                  // repõe os valores por omissão (ex.: quantidade 1)
    if (module === 'transactions') form.elements.occurred_at.value = nowLocal();
    if (module === 'accounts' || module === 'products') window.burstHearts?.(form.querySelector('button'));
    msg('Registo guardado com sucesso.');
    loadAll();
  } catch (e) { msg(e.message, true); }
}
$('#transaction-form').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'transactions'); };
$('#account-form').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'accounts'); };
$('#bill-form').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'bills'); };
$('#product-form').onsubmit = e => { e.preventDefault(); submitForm(e.target, 'products'); };
$('#client-form').onsubmit = async e => {
  e.preventDefault();
  try {
    await api('clients.php', { method: 'POST', body: JSON.stringify({ ...formData(e.target), csrf }) });
    window.burstHearts?.(e.submitter || e.target);
    resetClient();
    msg('Cliente guardado com sucesso.');
    loadAll();
  } catch (x) { msg(x.message, true); }
};
$('#cancel-client').onclick = resetClient;

$('#logout').onclick = async () => {
  try { sessionStorage.removeItem('gf-tab'); } catch (e) {}
  try { await api('auth.php?action=logout', { method: 'POST', body: '{}' }); } catch {}
  window.location.href = 'index.php';
};

/* ---------- abas ficam coladas por baixo do topo, seja qual for a altura dele ---------- */
function syncStickyOffsets() {
  document.documentElement.style.setProperty('--topbar-h', document.querySelector('.topbar').offsetHeight + 'px');
}
window.addEventListener('resize', syncStickyOffsets);
syncStickyOffsets();

/* ---------- aba ativa: restaurar ao atualizar e manter o destaque alinhado ---------- */
document.addEventListener('DOMContentLoaded', () => {
  let saved = null; try { saved = sessionStorage.getItem('gf-tab'); } catch (e) {}
  const tab = saved && [...$$('.nav-tab')].find(b => b.dataset.section === saved);
  if (tab && !tab.classList.contains('active')) {
    showSection(saved);
    const bar = $('.tabs');                                   // no telemóvel, mostra a aba ativa na barra
    if (bar && (tab.offsetLeft < bar.scrollLeft || tab.offsetLeft + tab.offsetWidth > bar.scrollLeft + bar.clientWidth)) bar.scrollLeft = tab.offsetLeft - 16;
  } else moveTabGlass();
  if ('ResizeObserver' in window && $('.tabs')) { const ro = new ResizeObserver(moveTabGlass); ro.observe($('.tabs')); $$('.nav-tab').forEach(t => ro.observe(t)); }
  window.addEventListener('resize', moveTabGlass);
  document.fonts?.ready.then(moveTabGlass);
});

/* ---------- arranque ---------- */
const todayText = new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
$('#today-message').textContent = todayText.charAt(0).toUpperCase() + todayText.slice(1);
$('#transaction-form').elements.occurred_at.value = nowLocal();
calculate();
api('auth.php?action=me').then(d => {
  if (!d.user) { window.location.href = 'index.php#autenticacao'; return; }
  csrf = d.csrf;
  if (!GF.mustChangePassword) loadAll();  // com palavra-passe provisória, só depois de a trocar
}).catch(e => msg(e.message, true));
