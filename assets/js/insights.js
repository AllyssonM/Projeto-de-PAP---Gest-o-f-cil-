/* =========================================================================
   PAINÉIS INTELIGENTES POR RAMO  (assets/js/insights.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  desenha o painel do ramo do utilizador na aba "Painel do ramo":
     RESTAURANTE       "Mais pedidos"
     LOJA DE ROUPAS    "Produtos mais vendidos"
     MOTORISTA / MOTO  "Distância e custo-benefício"
   COMO ESCOLHE O PAINEL: o motor de ramos (profiles.js) diz qual é (insights.kind) e
     dá-lhe o título; sem painel no ramo, a aba nem aparece.
   O QUE MOSTRA: números do período escolhido (dia / semana / mês / datas à escolha)
     comparados com o período anterior do mesmo tamanho, um gráfico simples e, quando
     faz sentido, recomendações (ex.: repor um produto que vai acabar).
   OS DADOS vêm do que a pessoa REGISTA (vendas, pedidos, viagens) em api/insights.php.
     NÃO HÁ GPS: nenhuma localização é recolhida. Pode carregar-se dados de EXEMPLO
     (marcados como tal) para ver o painel a funcionar; removem-se de uma vez.
   PERMISSÕES: ver os números exige "Visão geral e fluxo de caixa"; registar exige
     "Estoque" ou "Fluxo de caixa" (um funcionário de entrada de dados regista sem ver totais).
   ========================================================================= */
(function () {
  'use strict';
  const root = document.querySelector('#insights');
  if (!root) return;
  const $i = s => root.querySelector(s);
  const eur = v => new Intl.NumberFormat((window.LUMINA_LOCALE || 'pt-PT'), { style: 'currency', currency: 'EUR' }).format(Number(v) || 0);
  const num = (v, d = 0) => new Intl.NumberFormat((window.LUMINA_LOCALE || 'pt-PT'), { maximumFractionDigits: d }).format(Number(v) || 0);
  const PERIODS = { day: 'hoje', week: 'esta semana', month: 'este mês', custom: 'neste período' };
  const PREV = { day: 'ao dia anterior', week: 'à semana anterior', month: 'ao mês anterior', custom: 'ao período anterior' };
  const U = () => window.GF_USER || { isOwner: true, permissions: [] };
  const canView = () => U().isOwner || U().permissions.includes('cashflow');
  const canEnter = () => canView() || U().permissions.includes('stock');

  let kind = '', title = '', period = 'week', data = null, filters = {}, custom = { from: '', to: '' }, products = [];

  /* ------------------------------------------------------------ carregar */
  async function load() {
    if (!kind) return;
    $i('#ins-title').textContent = title;
    root.querySelectorAll('[data-view]').forEach(el => { el.hidden = !canView(); });
    $i('#ins-noview').hidden = canView();
    renderForm();
    if (!canView()) return;
    const body = $i('#ins-body');
    body.innerHTML = '<div class="skeleton-stack"><div class="skeleton" style="height:120px"></div><div class="skeleton" style="height:200px"></div></div>';
    const q = new URLSearchParams({ kind, period, ...(period === 'custom' ? custom : {}), ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)) });
    try { data = await api(`insights.php?${q}`); render(); if (kind === 'sales') renderRecent(); }
    catch (e) {
      body.innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível carregar o painel</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="ins-retry">Tentar novamente</button></div>`;
      $i('#ins-retry').onclick = load;
    }
  }

  /* ------------------------------------------------------------ peças reutilizáveis */
  const chg = (v, prev) => v === null || v === undefined ? `<span class="ins-chg none">sem ${prev === 'x' ? 'período' : 'dados'} anterior para comparar</span>`
    : `<span class="ins-chg ${v >= 0 ? 'up' : 'down'}">${v >= 0 ? '+' : ''}${num(v, 1)}% comparado ${PREV[period]}</span>`;
  const kpi = (label, value, sub = '') => `<div class="ins-kpi"><span>${esc(label)}</span><b>${value}</b>${sub ? `<small>${sub}</small>` : ''}</div>`;
  /** Barras horizontais (gráfico simples e acessível): a largura anima ao carregar. */
  const bars = (items, valueKey, label, fmt = v => num(v)) => {
    const max = Math.max(1, ...items.map(i => i[valueKey]));
    return `<ol class="ins-bars" aria-label="${esc(label)}">${items.map((i, n) => `<li><span class="ins-bar-name">${esc(i.name)}</span>
      <span class="ins-bar" role="img" aria-label="${esc(i.name)}: ${esc(fmt(i[valueKey]))}"><i style="--w:${Math.round(i[valueKey] / max * 100)}%;--d:${n * 70}ms"></i></span><b>${esc(fmt(i[valueKey]))}</b></li>`).join('')}</ol>`;
  };
  const empty = text => `<div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">📊</span><b>Ainda não há dados neste período</b><span>${text}</span></div>`;
  const hourLabel = h => `${String(h).padStart(2, '0')}h–${String((h + 1) % 24).padStart(2, '0')}h`;

  /* ------------------------------------------------------------ restaurante e loja */
  function renderSales() {
    const d = data, t = d.totals, top = d.top_product;
    if (!t.lines) return empty(kind === 'sales' ? 'Ainda não existem vendas registadas.' : 'Regista um pedido no formulário abaixo, ou carrega os dados de exemplo.');
    let h = '';
    h += `<div class="ins-hero"><div><p class="eyebrow">${kind === 'orders' ? 'PRODUTO OU PRATO' : 'ROUPA'} MAIS ${kind === 'orders' ? 'PEDIDO' : 'VENDIDA'} ${PERIODS[period].toUpperCase()}</p>
        <h3>${esc(top.name)}</h3><p class="ins-big">${num(top.qty)} unidades vendidas</p>${chg(top.change)}</div>
        <div class="ins-hero-side"><span>Receita gerada</span><b>${eur(top.revenue)}</b></div></div>`;
    h += '<div class="ins-kpis">' + kpi('Receita total', eur(t.revenue), chg(t.revenue_change)) + kpi(kind === 'orders' ? 'Pedidos (unidades)' : 'Quantidade vendida', num(t.qty), chg(t.qty_change));
    if (kind === 'orders') {
      h += kpi('Horário com mais pedidos', d.peak_hour ? hourLabel(d.peak_hour.hour) : '—', d.peak_hour ? `${num(d.peak_hour.qty)} unidades` : '') + kpi('Categoria mais vendida', d.categories[0] ? esc(d.categories[0].name) : '—', d.categories[0] ? `${num(d.categories[0].qty)} unidades · ${eur(d.categories[0].revenue)}` : '');
    } else {
      h += kpi('Marca mais vendida', d.brands[0] ? esc(d.brands[0].name) : '—', d.brands[0] ? `${num(d.brands[0].qty)} unidades` : '') + kpi('Tamanho mais procurado', d.sizes[0] ? esc(d.sizes[0].name) : '—', d.sizes[0] ? `${num(d.sizes[0].qty)} unidades` : '')
        + kpi('Cor mais escolhida', d.colors[0] ? esc(d.colors[0].name) : '—', d.colors[0] ? `${num(d.colors[0].qty)} unidades` : '');
    }
    h += '</div><div class="ins-cols">';
    h += kind === 'sales' ? `<article class="ins-box"><h3>Top 5 produtos</h3>${bars(d.top, 'qty', 'Top 5 por unidades vendidas', v => num(v) + ' un.')}</article>`
      : `<article class="ins-box"><h3>Top 5 produtos mais pedidos</h3>${bars(d.top, 'qty', 'Top 5 por unidades vendidas', v => num(v) + ' un.')}</article>`;
    if (kind === 'sales') h += `<article class="ins-box"><h3>Top 5 marcas</h3>${d.brands.length ? bars(d.brands, 'qty', 'Top 5 marcas', v => num(v) + ' un.') : '<p class="muted">Sem marcas registadas.</p>'}</article>`;
    else h += `<article class="ins-box"><h3>Categorias</h3>${d.categories.length ? bars(d.categories, 'revenue', 'Receita por categoria', eur) : '<p class="muted">Sem categorias registadas.</p>'}</article>`;
    h += '</div>';
    if (kind === 'sales' && d.ranking && d.ranking.length) {
      h += `<article class="ins-box"><h3>Produtos mais vendidos</h3><div class="ins-scroll"><table class="time-table rank-table"><thead><tr><th>#</th><th>Produto</th><th>Marca</th><th>Tamanho</th><th>Cor</th><th>Vendidos</th><th>Total gerado</th><th>Última venda</th></tr></thead><tbody>
        ${d.ranking.map((r, i) => `<tr><td class="rank-pos">${i + 1}</td><td>${esc(r.name)}</td><td>${esc(r.brand || '—')}</td><td>${esc(r.size || '—')}</td><td>${esc(r.color || '—')}</td><td>${num(r.qty)}</td><td>${eur(r.revenue)}</td>
          <td>${esc(new Date(r.last_sale.replace(' ', 'T')).toLocaleDateString(window.LUMINA_LOCALE || 'pt-PT'))}</td></tr>`).join('')}</tbody></table></div></article>`;
    }
    // estoque dos mais vendidos
    const withStock = d.top.filter(x => x.stock !== null);
    if (withStock.length) h += `<article class="ins-box"><h3>Estoque dos mais vendidos</h3><div class="ins-scroll"><table class="time-table"><thead><tr><th>Produto</th><th>Vendidos</th><th>Estoque atual</th><th>Dura aprox.</th></tr></thead><tbody>${withStock.map(x => `<tr><td>${esc(x.name)}</td><td>${num(x.qty)}</td><td>${num(x.stock)}${x.days_left !== null && x.days_left < 7 ? ' <span class="ins-low">baixo</span>' : ''}</td><td>${x.days_left !== null ? num(x.days_left, 1) + ' dias' : '—'}</td></tr>`).join('')}</tbody></table></div></article>`;
    h += `<article class="ins-box ins-recs"><h3>Recomendações de reposição</h3>${d.recommendations.length ? `<ul>${d.recommendations.map(r => `<li>⚠️ ${esc(r.text)}</li>`).join('')}</ul>` : '<p class="muted">Sem alertas: os produtos mais vendidos têm estoque para os próximos dias. As recomendações aparecem quando o nome do produto vendido coincide com um produto do teu estoque.</p>'}</article>`;
    return h;
  }

  /* ------------------------------------------------------------ viagens */
  function renderTrips() {
    const d = data, t = d.totals;
    if (!t.trips) return empty('Regista uma viagem no formulário abaixo, ou carrega os dados de exemplo.');
    const best = d.best_cost_route, net = d.best_net_route;
    let h = `<div class="ins-hero"><div><p class="eyebrow">DISTÂNCIA PERCORRIDA ${PERIODS[period].toUpperCase()}</p><h3>${num(t.km, 1)} km</h3><p class="ins-big">${num(t.trips)} viagens · custo médio ${t.cost_per_km !== null ? num(t.cost_per_km, 2) + ' €/km' : '—'}</p>${chg(t.km_change)}</div>
        <div class="ins-hero-side"><span>Custo total</span><b>${eur(t.total_cost)}</b></div></div>`;
    h += '<div class="ins-kpis">' + kpi('Consumo médio', t.avg_consumption !== null ? `${num(t.avg_consumption, 1)} L/100 km` : '—', 'só viagens com combustível registado')
      + kpi('Combustível gasto', eur(t.fuel_cost), `${num(t.liters, 1)} litros`) + kpi('Custo por quilómetro', t.cost_per_km !== null ? `${num(t.cost_per_km, 3)} €/km` : '—', chg(t.cost_km_change))
      + kpi('Manutenção e outras despesas', eur(t.other_costs), 'portagens, manutenção…') + kpi('Receita por viagem', t.revenue_per_trip !== null ? eur(t.revenue_per_trip) : '—', `Receita total ${eur(t.revenue)}`) + kpi('Resultado', eur(t.net), 'receita menos custos') + '</div>';
    h += '<div class="ins-cols">';
    h += `<article class="ins-box"><h3>Melhor relação distância/custo</h3>${best ? `<p class="ins-big">${esc(best.route)}</p><p class="muted">${num(best.cost_km, 3)} €/km · ${num(best.km, 1)} km em ${best.trips} viagem(ns)</p>` : '<p class="muted">—</p>'}
        <h3 style="margin-top:14px">Distância mais rentável</h3>${net ? `<p class="ins-big">${esc(net.route)}</p><p class="muted">${num(net.net_km, 3)} € de lucro por km · resultado ${eur(net.net)}</p>` : '<p class="muted">Regista a receita das viagens para ver a mais rentável.</p>'}</article>`;
    h += `<article class="ins-box"><h3>Rotas mais eficientes (custo por km)</h3>${d.routes.length ? bars(d.routes.map(r => ({ name: r.route, v: r.cost_km })), 'v', 'Custo por km de cada rota (menor é melhor)', v => num(v, 3) + ' €/km') : '<p class="muted">Sem rotas.</p>'}</article></div>`;
    h += `<article class="ins-box"><h3>Comparação entre viagens</h3><div class="ins-scroll"><table class="time-table"><thead><tr><th>Data</th><th>Veículo</th><th>Rota</th><th>Km</th><th>Custo</th><th>€/km</th><th>Receita</th><th>Resultado</th>${U().isOwner ? '<th></th>' : ''}</tr></thead><tbody>${d.recent.map(r => `<tr><td>${esc(r.date.split('-').reverse().join('/'))}</td><td>${esc(r.vehicle)}</td><td>${esc(r.route)}${r.demo ? ' <span class="ins-demo">exemplo</span>' : ''}</td><td>${num(r.km, 1)}</td><td>${eur(r.cost)}</td><td>${r.cost_km !== null ? num(r.cost_km, 3) : '—'}</td><td>${eur(r.revenue)}</td><td class="${r.net >= 0 ? 'positive' : 'negative'}">${eur(r.net)}</td>${U().isOwner ? `<td><button type="button" class="button secondary small" data-del-trip="${r.id}">×</button></td>` : ''}</tr>`).join('')}</tbody></table></div></article>`;
    h += '<p class="muted ins-note">🔒 Sem GPS: nenhuma localização é recolhida. Os números vêm só do que registas.</p>';
    return h;
  }

  function render() {
    // filtros do ramo
    const f = $i('#ins-filters');
    if (kind === 'sales') f.innerHTML = ['category', 'brand', 'size'].map(k => `<label>${{ category: 'Categoria', brand: 'Marca', size: 'Tamanho' }[k]}<select data-filter="${k}"><option value="">Todos</option>${(data.filters[k] || []).map(v => `<option${filters[k] === v ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>`).join('');
    else if (kind === 'trips') f.innerHTML = `<label>Veículo<select data-filter="vehicle"><option value="">Todos</option>${data.vehicles.map(v => `<option${filters.vehicle === v ? ' selected' : ''}>${esc(v)}</option>`).join('')}</select></label>`;
    else f.innerHTML = '';
    f.querySelectorAll('[data-filter]').forEach(s => s.onchange = () => { filters[s.dataset.filter] = s.value; load(); });
    $i('#ins-range').textContent = `${data.range.from.split('-').reverse().join('/')} a ${data.range.to.split('-').reverse().join('/')}`;
    // exemplos
    const demo = $i('#ins-demo');
    demo.hidden = !U().isOwner;
    demo.innerHTML = data.has_demo ? '<span class="ins-demo">Dados de exemplo</span> Estes números incluem dados de demonstração. <button type="button" class="button secondary small" id="ins-demo-clear">Remover dados de exemplo</button>'
      : (U().isOwner && !(data.totals.lines || data.totals.trips) ? 'Sem dados ainda. <button type="button" class="button secondary small" id="ins-demo-load">Carregar dados de exemplo</button>' : '');
    $i('#ins-body').innerHTML = kind === 'trips' ? renderTrips() : renderSales();
    // as barras animam a entrada
    requestAnimationFrame(() => requestAnimationFrame(() => $i('#ins-body').classList.add('in')));
    $i('#ins-body').classList.remove('in'); requestAnimationFrame(() => requestAnimationFrame(() => $i('#ins-body').classList.add('in')));
    $i('#ins-demo-load')?.addEventListener('click', () => demoAction('demo_load', 'Dados de exemplo carregados.'));
    $i('#ins-demo-clear')?.addEventListener('click', async () => { if (await UX.confirm({ title: 'Remover os dados de exemplo?', text: 'Só se apagam os dados marcados como exemplo. O que registaste tu fica.', confirmLabel: 'Remover', danger: true })) demoAction('demo_clear', 'Dados de exemplo removidos.'); });
    root.querySelectorAll('[data-del-trip]').forEach(b => b.onclick = async () => { if (await UX.confirm({ title: 'Apagar esta viagem?', text: 'Não se pode desfazer.', confirmLabel: 'Apagar', danger: true })) post('trip_delete', { id: +b.dataset.delTrip }, 'Viagem apagada.'); });
  }

  async function post(action, body, okText) {
    try { await api('insights.php', { method: 'POST', body: JSON.stringify({ action, kind, ...body, csrf }) }); msg(okText); load(); }
    catch (e) { msg(e.message, true); }
  }
  const demoAction = (a, t) => post(a, {}, t);

  /* ------------------------------------------------------------ registar (formulário do ramo) */
  async function renderForm() {
    const box = $i('#ins-form');
    if (!canEnter()) { box.innerHTML = ''; return; }
    if (kind === 'trips') {
      box.innerHTML = `<form class="ins-form" id="trip-form" novalidate><div class="ins-fields">
        <label>Veículo<input name="vehicle" maxlength="80" placeholder="Ex.: Mota Honda PCX" value="${esc(localStorage.getItem('gf-vehicle') || '')}"></label>
        <label>Rota<input name="route" required maxlength="160" placeholder="Ex.: Leiria–Coimbra"></label>
        <label>Distância (km)<input name="distance_km" type="number" step="0.1" min="0.1" max="5000" required></label>
        <label>Combustível (litros)<input name="fuel_liters" type="number" step="0.01" min="0"></label>
        <label>Custo do combustível (€)<input name="fuel_cost" type="number" step="0.01" min="0"></label>
        <label>Outras despesas (€)<input name="other_costs" type="number" step="0.01" min="0" placeholder="portagens, manutenção"></label>
        <label>Receita da viagem (€)<input name="revenue" type="number" step="0.01" min="0"></label>
        <label>Data<input name="trip_date" type="date" value="${new Date().toISOString().slice(0, 10)}"></label></div>
        <p class="ap-form-error" role="alert" hidden></p><div class="form-actions"><button class="button primary">Registar viagem</button></div></form>`;
      $i('#trip-form').onsubmit = async e => {
        e.preventDefault(); const f = e.target, err = f.querySelector('.ap-form-error'), body = Object.fromEntries(new FormData(f));
        if (!body.route.trim() || !(+body.distance_km > 0)) { err.textContent = 'Indica a rota e a distância (maior que zero).'; err.hidden = false; return; }
        err.hidden = true; try { localStorage.setItem('gf-vehicle', body.vehicle); } catch (x) {}
        await post('trip_add', body, 'Viagem registada.'); f.reset(); f.trip_date.value = new Date().toISOString().slice(0, 10);
      };
      return;
    }
    if (kind === 'sales') return renderSaleForm(box);
    if (!products.length && U().permissions.includes('stock') || (U().isOwner && !products.length)) { try { products = (await api('data.php?module=products')).items; } catch (e) { products = []; } }
    const clothing = kind === 'sales';
    box.innerHTML = `<form class="ins-form" id="sale-form" novalidate><div class="ins-fields">
      <label>Produto<input name="product_name" list="ins-products" required maxlength="160" placeholder="Escolhe ou escreve" autocomplete="off"><datalist id="ins-products">${products.map(p => `<option value="${esc(p.name)}"></option>`).join('')}</datalist></label>
      <label>Quantidade<input name="quantity" type="number" min="1" max="10000" step="1" value="1" required></label>
      <label>Valor total (€)<input name="amount" type="number" min="0" step="0.01" required></label>
      <label>Data e hora<input name="sold_at" type="datetime-local" value="${new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16)}"></label>
      <label>Categoria<input name="category" maxlength="100"></label>
      ${clothing ? '<label>Marca<input name="brand" maxlength="100"></label><label>Tamanho<input name="size" maxlength="20"></label><label>Cor<input name="color" maxlength="40"></label>' : ''}</div>
      <p class="ap-form-error" role="alert" hidden></p><div class="form-actions"><button class="button primary">${kind === 'orders' ? 'Registar pedido' : 'Registar venda'}</button></div></form>`;
    const f = $i('#sale-form');
    // ao escolher um produto do estoque, preenche o valor, a categoria e os campos do ramo
    f.product_name.addEventListener('change', () => {
      const p = products.find(x => x.name === f.product_name.value); if (!p) return;
      f.amount.value = (Number(p.sale_price) * (+f.quantity.value || 1)).toFixed(2); if (!f.category.value) f.category.value = p.category || '';
      if (clothing) { f.brand.value ||= p.attributes?.marca || ''; f.size.value ||= p.attributes?.tamanho || ''; f.color.value ||= p.attributes?.cor || ''; }
    });
    f.onsubmit = async e => {
      e.preventDefault(); const err = f.querySelector('.ap-form-error'), body = Object.fromEntries(new FormData(f));
      if (!body.product_name.trim()) { err.textContent = 'Indica o produto.'; err.hidden = false; return; }
      if (!(+body.quantity >= 1) || body.amount === '' || +body.amount < 0) { err.textContent = 'Confirma a quantidade e o valor total.'; err.hidden = false; return; }
      err.hidden = true; const p = products.find(x => x.name === body.product_name); if (p) body.product_id = p.id;
      await post('sale_add', body, kind === 'orders' ? 'Pedido registado.' : 'Venda registada.'); f.reset(); f.quantity.value = 1;
    };
  }


  /* ------------------------------------------------------------ venda ligada ao estoque (loja de roupa / loja online) */
  const newKey = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const nowText = () => new Date().toLocaleString(window.LUMINA_LOCALE || 'pt-PT', { dateStyle: 'short', timeStyle: 'short' });
  let saleKey = newKey(), saleBusy = false;

  async function reloadProducts() {
    try { const d = await api('data.php?module=products'); products = d.items; if (typeof state !== 'undefined') { state.products = d.items; state.stockSummary = d.summary || null; if (typeof renderProducts === 'function') renderProducts(); window.GFStock?.renderOverview?.(); } } catch (e) { /* mantém a lista anterior */ }
  }

  async function renderSaleForm(box) {
    await reloadProducts();
    const sellable = window.GFVar.sellable(products);
    const owner = !!U().isOwner;
    box.innerHTML = `<form class="ins-form sale-form" id="sale-form" novalidate><div class="ins-fields">
      <label>Procurar produto<input type="search" id="sale-search" maxlength="80" placeholder="Nome, marca, referência ou categoria" autocomplete="off"></label>
      <label>Produto<select name="product_id" required><option value="">${sellable.length ? 'Escolhe um produto…' : 'Não há produtos com estoque'}</option>${sellable.map(p => `<option value="${p.id}">${esc(p.name)}${p.brand ? ' · ' + esc(p.brand) : ''}</option>`).join('')}</select></label>
      <label data-sale-size hidden>Tamanho<select name="size"></select></label>
      <label data-sale-color hidden>Cor<select name="color"></select></label>
      <label>Quantidade<input name="quantity" type="number" min="1" step="1" value="1" required disabled></label>
      <label class="sale-total-row">Valor total (€)<input name="amount" type="number" min="0" step="0.01" required disabled><span class="sale-auto" id="sale-auto">Calculado: quantidade × preço</span></label>
      <label>Data e hora<input name="sold_at" id="sale-when" readonly value="${esc(nowText())}"></label>
      <label>Categoria<input name="category" readonly></label>
      <label>Marca<input name="brand" readonly></label></div>
      <p class="sale-hint" id="sale-hint" aria-live="polite">${sellable.length ? 'Escolhe o produto para ver os tamanhos e as cores disponíveis.' : 'Acrescenta produtos com quantidade no Estoque para poderes vender.'}</p>
      <p class="ap-form-error" role="alert" hidden></p><div class="form-actions"><button class="button primary" id="sale-submit" disabled>Registar venda</button></div></form>
      <div id="sale-recent"></div>`;
    const f = $i('#sale-form'), hint = $i('#sale-hint'), err = f.querySelector('.ap-form-error'), btn = $i('#sale-submit');
    let cur = null, variant = null, manual = false;
    const sizeLbl = f.querySelector('[data-sale-size]'), colorLbl = f.querySelector('[data-sale-color]');
    const showErr = t => { err.textContent = t || ''; err.hidden = !t; };
    const unitPrice = () => variant ? (variant.price !== null && variant.price !== undefined ? Number(variant.price) : Number(cur.sale_price)) : 0;
    const recalc = () => { if (!variant || manual) return; f.amount.value = (unitPrice() * (+f.quantity.value || 0)).toFixed(2); };
    const setAuto = () => { const a = $i('#sale-auto'); a.innerHTML = manual ? 'Valor alterado à mão. <button type="button" id="sale-recalc">Recalcular automaticamente</button>' : 'Calculado: quantidade × preço'; };

    // o relógio da data/hora acompanha o momento real (o servidor usa a hora do registo, não este campo)
    const clock = setInterval(() => { const w = $i('#sale-when'); if (!w) return clearInterval(clock); w.value = nowText(); }, 15000);

    const fillSelect = (sel, values, placeholder) => { sel.innerHTML = `<option value="">${placeholder}</option>` + values.map(v => `<option value="${esc(v)}">${esc(v)}</option>`).join(''); };
    const pickVariant = () => {
      const vs = cur ? cur.variants : [];
      const size = f.size.value, color = f.color.value;
      variant = vs.find(v => (v.size || '') === size && (v.color || '') === color) || null;
      f.quantity.disabled = f.amount.disabled = !variant; btn.disabled = !variant;
      if (!variant) { if (cur) hint.textContent = 'Escolhe o tamanho e a cor para ver o estoque.'; hint.className = 'sale-hint'; return; }
      f.quantity.max = variant.quantity; if (+f.quantity.value > variant.quantity) f.quantity.value = variant.quantity; if (+f.quantity.value < 1) f.quantity.value = 1;
      hint.textContent = `Disponível: ${variant.quantity} unidade${variant.quantity === 1 ? '' : 's'}.`; hint.className = 'sale-hint';
      manual = false; setAuto(); recalc();
    };
    const pickSize = () => {                                              // cores disponíveis para o tamanho escolhido
      const vs = cur.variants.filter(v => (v.size || '') === f.size.value);
      const colors = [...new Set(vs.map(v => v.color || ''))];
      const only = colors.length === 1 && vs.length === 1;
      fillSelect(f.color, colors.filter(Boolean), 'Escolhe a cor');
      colorLbl.hidden = !colors.some(Boolean);
      if (!colors.some(Boolean)) f.color.value = ''; else if (colors.filter(Boolean).length === 1) f.color.value = colors.find(Boolean);
      pickVariant();
    };
    const pickProduct = () => {
      cur = sellable.find(p => String(p.id) === f.product_id.value) || null; variant = null; manual = false; setAuto(); showErr('');
      f.category.value = cur ? (cur.category || '') : ''; f.brand.value = cur ? (cur.brand || '') : '';
      f.quantity.disabled = f.amount.disabled = true; btn.disabled = true; f.amount.value = ''; f.quantity.value = 1;
      sizeLbl.hidden = colorLbl.hidden = true; f.size.innerHTML = f.color.innerHTML = '';
      if (!cur) { hint.textContent = 'Escolhe o produto para ver os tamanhos e as cores disponíveis.'; return; }
      const sizes = [...new Set(cur.variants.map(v => v.size || ''))];
      fillSelect(f.size, sizes.filter(Boolean), 'Escolhe o tamanho'); sizeLbl.hidden = !sizes.some(Boolean);
      if (!sizes.some(Boolean)) { f.size.value = ''; pickSize(); }
      else if (sizes.filter(Boolean).length === 1) { f.size.value = sizes.find(Boolean); pickSize(); }
      else { fillSelect(f.color, [], 'Escolhe o tamanho primeiro'); hint.textContent = 'Escolhe o tamanho.'; }
    };
    f.product_id.addEventListener('change', pickProduct);
    f.size.addEventListener('change', pickSize);
    f.color.addEventListener('change', pickVariant);
    f.quantity.addEventListener('input', () => {
      if (variant && +f.quantity.value > variant.quantity) { hint.textContent = 'A quantidade selecionada é superior ao stock disponível.'; hint.className = 'sale-hint error'; }
      else if (variant) { hint.textContent = `Disponível: ${variant.quantity} unidade${variant.quantity === 1 ? '' : 's'}.`; hint.className = 'sale-hint'; }
      recalc();
    });
    f.amount.addEventListener('input', () => { manual = true; setAuto(); });
    f.addEventListener('click', e => { if (e.target.id === 'sale-recalc') { manual = false; setAuto(); recalc(); } });
    $i('#sale-search').addEventListener('input', e => {                    // procurar por nome, marca, referência ou categoria
      const q = e.target.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
      const list = sellable.filter(p => !q || [p.name, p.brand, p.sku, p.category].join(' ').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().includes(q));
      const keep = f.product_id.value;
      f.product_id.innerHTML = `<option value="">${list.length ? 'Escolhe um produto…' : 'Nenhum produto encontrado'}</option>` + list.map(p => `<option value="${p.id}">${esc(p.name)}${p.brand ? ' · ' + esc(p.brand) : ''}</option>`).join('');
      if (list.some(p => String(p.id) === keep)) f.product_id.value = keep; else if (keep) pickProduct();
      if (q && list.length === 1) { f.product_id.value = list[0].id; pickProduct(); }
    });

    f.onsubmit = async e => {
      e.preventDefault(); if (saleBusy) return;
      if (!cur || !variant) { showErr('Escolhe o produto, o tamanho e a cor.'); return; }
      const qty = +f.quantity.value;
      if (!Number.isInteger(qty) || qty < 1) { showErr('A quantidade tem de ser pelo menos 1.'); return; }
      if (qty > variant.quantity) { showErr('A quantidade selecionada é superior ao stock disponível.'); return; }
      if (f.amount.value === '' || +f.amount.value < 0) { showErr('Confirma o valor total.'); return; }
      showErr(''); saleBusy = true; btn.disabled = true; btn.textContent = 'A registar…'; f.classList.add('is-saving');
      try {
        const body = { action: 'sale_add', kind, variant_id: variant.id, quantity: qty, request_key: saleKey, csrf };
        if (manual) body.amount = f.amount.value;                              // sem alteração manual, o servidor calcula quantidade × preço
        const r = await api('insights.php', { method: 'POST', body: JSON.stringify(body) });
        msg(r.duplicate ? 'Esta venda já estava registada.' : `Venda registada. Stock atualizado: ${r.remaining ?? ''} un. restantes.`.replace(': ' + ' un. restantes.', '.'));
        saleKey = newKey(); await load();                 // lista de produtos e totais atualizados já, sem recarregar a página
      } catch (e2) {
        showErr(e2.message || 'Não foi possível registar a venda.'); msg(e2.message || 'Não foi possível registar a venda.', true);
        await reloadProducts();                                                  // o estoque pode ter mudado: mostra o valor real
        if (e2.data && e2.data.available !== undefined) { hint.textContent = `Disponível: ${e2.data.available} unidade(s).`; hint.className = 'sale-hint error'; }
      } finally { saleBusy = false; btn.textContent = 'Registar venda'; f.classList.remove('is-saving'); if (f.isConnected) btn.disabled = !variant; }
    };
    renderRecent();
  }

  /** Últimas vendas: o dono pode editar a quantidade/valor ou anular (o estoque é reposto). */
  function renderRecent() {
    const host = $i('#sale-recent'); if (!host) return;
    const list = (data && data.recent) || [];
    if (!list.length) { host.innerHTML = ''; return; }
    const owner = !!U().isOwner;
    host.innerHTML = `<article class="ins-box sale-recent"><h3>Últimas vendas</h3><div class="ins-scroll"><table class="time-table"><thead><tr><th>Data</th><th>Produto</th><th>Tamanho / cor</th><th>Qtd.</th><th>Total</th><th>Estado</th>${owner ? '<th></th>' : ''}</tr></thead><tbody>
      ${list.map(r => `<tr class="${r.status === 'cancelled' ? 'sale-cancelled' : ''}"><td>${esc(new Date(r.sold_at.replace(' ', 'T')).toLocaleString(window.LUMINA_LOCALE || 'pt-PT', { dateStyle: 'short', timeStyle: 'short' }))}</td>
        <td>${esc(r.name)}${r.brand ? ' · ' + esc(r.brand) : ''}</td><td>${esc([r.size, r.color].filter(Boolean).join(' / ') || '—')}</td><td>${num(r.qty)}</td><td>${eur(r.amount)}</td>
        <td><span class="sale-status ${r.status}">${r.status === 'cancelled' ? 'Anulada' : 'Concluída'}</span></td>
        ${owner ? `<td>${r.status === 'completed' && r.tracked ? `<span class="sale-actions"><button type="button" class="button ghost small" data-sale-edit="${r.id}">Editar</button><button type="button" class="button ghost small" data-sale-cancel="${r.id}">Anular</button></span>` : ''}</td>` : ''}</tr>`).join('')}
      </tbody></table></div></article>`;
    host.querySelectorAll('[data-sale-cancel]').forEach(b => b.onclick = async () => {
      const r = list.find(x => x.id === +b.dataset.saleCancel);
      if (!await UX.confirm({ title: 'Anular esta venda?', text: `«${r.name}»: as ${r.qty} unidade(s) voltam ao estoque e a venda deixa de contar nos totais.`, confirmLabel: 'Anular venda', danger: true })) return;
      b.disabled = true;
      try { const d = await api('insights.php', { method: 'POST', body: JSON.stringify({ action: 'sale_cancel', kind, id: r.id, csrf }) }); msg(d.already ? 'Esta venda já estava anulada.' : 'Venda anulada. Estoque reposto.'); await load(); }
      catch (e) { msg(e.message, true); b.disabled = false; }
    });
    host.querySelectorAll('[data-sale-edit]').forEach(b => b.onclick = () => {
      const r = list.find(x => x.id === +b.dataset.saleEdit);
      const m = UX.modal(`<h2>Editar venda</h2><p class="muted">${esc(r.name)}${r.size || r.color ? ' · ' + esc([r.size, r.color].filter(Boolean).join(' / ')) : ''}</p>
        <form id="sale-edit-form" novalidate><label>Quantidade<input name="quantity" type="number" min="1" step="1" value="${r.qty}" data-autofocus></label>
        <label>Valor total (€)<input name="amount" type="number" min="0" step="0.01" value="${r.amount.toFixed(2)}"></label>
        <p class="muted" style="font-size:12.5px">Se mudares a quantidade sem mexer no valor, o total é recalculado. A diferença de unidades entra ou sai do estoque.</p>
        <p class="ap-form-error" role="alert" hidden></p>
        <div class="ux-confirm-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button class="button primary">Guardar</button></div></form>`);
      const ef = m.root.querySelector('#sale-edit-form'), er = ef.querySelector('.ap-form-error');
      ef.querySelector('[data-no]').onclick = () => m.close(false);
      ef.onsubmit = async e => {
        e.preventDefault(); const q = +ef.quantity.value;
        if (!Number.isInteger(q) || q < 1) { er.textContent = 'A quantidade tem de ser pelo menos 1.'; er.hidden = false; return; }
        const body = { action: 'sale_update', kind, id: r.id, quantity: q, csrf };
        if (+ef.amount.value !== r.amount || q === r.qty) body.amount = ef.amount.value;
        try { await api('insights.php', { method: 'POST', body: JSON.stringify(body) }); m.close(true); msg('Venda atualizada. Estoque ajustado.'); await load(); }
        catch (e2) { er.textContent = e2.message; er.hidden = false; }
      };
    });
  }

  /* ------------------------------------------------------------ controlos (período) */
  root.querySelectorAll('[data-period]').forEach(b => b.onclick = () => {
    period = b.dataset.period;
    root.querySelectorAll('[data-period]').forEach(x => { x.setAttribute('aria-pressed', x === b); x.classList.toggle('on', x === b); });
    $i('#ins-custom').hidden = period !== 'custom';
    if (period !== 'custom' || (custom.from && custom.to)) load();
  });
  ['from', 'to'].forEach(k => $i(`#ins-${k}`).addEventListener('change', e => { custom[k] = e.target.value; if (custom.from && custom.to) load(); }));

  // quando o ramo muda (profiles.js), o painel muda com ele
  document.addEventListener('gf:profile', e => {
    const ins = e.detail.profile.insights;
    const newKind = ins ? ins.kind : '';
    if (newKind !== kind) { kind = newKind; title = ins ? ins.title : ''; filters = {}; products = []; data = null; }
    title = ins ? ins.title : '';
    if (!root.classList.contains('hidden') && kind) load();
  });
  document.addEventListener('gf:section', e => { if (e.detail === 'insights') { const ins = window.GFP?.current().insights; if (ins) { kind = ins.kind; title = ins.title; load(); } } });
})();
