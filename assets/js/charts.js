/* =========================================================================
   GRÁFICOS DE ENTRADAS E SAÍDAS  (assets/js/charts.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  o gráfico "Movimento" da Visão geral e o gráfico do Relatório (que vai
               também para o PDF). O utilizador escolhe:
     PERÍODO:  Diário (14 dias) · Semanal (8 semanas) · Mensal (6 meses) · 12 meses · Anual (5 anos)
     TIPO:     Barras ou Linhas
   Mudar o período ou o tipo só redesenha o gráfico: o resto da página não se mexe.
   A ESCOLHA FICA GUARDADA neste navegador (uma para a Visão geral, outra para o Relatório).
   DESIGN: por omissão (Mensal + Barras) o gráfico é IDÊNTICO ao original: usa o mesmo
     HTML e o mesmo CSS (.bar-group, .income-bar, .expense-bar). As Linhas e o gráfico do
     relatório são SVG com as mesmas cores (turquesa = entradas, coral = saídas).
   PDF: o gráfico do relatório é SVG (vetorial), por isso aparece nítido e com cores certas
     ao imprimir/guardar como PDF.
   DEPENDE DE common.js (api, esc) e app.js (money). Os totais por período vêm do servidor (api/data.php?module=series).
   ========================================================================= */
(function () {
  'use strict';

  /* ----------------------------------------------------------------- períodos */
  const PERIODS = {
    day:      { label: 'Diário',   adj: 'diário',   span: 'dos últimos 14 dias' },
    week:     { label: 'Semanal',  adj: 'semanal',  span: 'das últimas 8 semanas' },
    month:    { label: 'Mensal',   adj: 'mensal',   span: 'dos últimos 6 meses' },
    months12: { label: '12 meses', adj: 'de 12 meses', span: 'dos últimos 12 meses' },
    year:     { label: 'Anual',    adj: 'anual',    span: 'dos últimos 5 anos' },
  };
  const TYPES = { bars: 'Barras', lines: 'Linhas' };
  /** Totais por período: calculados no servidor (api/data.php?module=series), que devolve { labels, income, expense }. */
  async function fetchSeries(period) {
    const gu = window.GF_USER || {};
    if (!(gu.isOwner || (gu.permissions || []).includes('cashflow'))) throw new Error('Sem permissão para o fluxo de caixa.');      // não pede ao servidor o que ele vai recusar
    const d = await api('data.php?module=series&period=' + encodeURIComponent(period));
    return d.series;
  }

  /* ----------------------------------------------------------------- desenho */
  /** Gráfico de barras com o HTML/CSS ORIGINAL (por isso o aspeto por omissão não muda). */
  function barsHtml(s) {
    const max = Math.max(1, ...s.income, ...s.expense);
    const h = v => v > 0 ? Math.max(4, v / max * 100) : 0;
    return s.labels.map((label, i) => `
    <div class="bar-group">
      <div class="bars">
        <span class="bar income-bar" style="height:${h(s.income[i])}%" title="Entradas ${money.format(s.income[i])}"></span>
        <span class="bar expense-bar" style="height:${h(s.expense[i])}%" title="Saídas ${money.format(s.expense[i])}"></span>
      </div><small>${esc(label)}</small>
    </div>`).join('');
  }

  const C = { income: '#2fcfa8', expense: '#f27d72' };         // as cores do sistema, legíveis no ecrã escuro e no papel branco

  /** Gráfico em SVG (linhas ou barras). Usado nas "Linhas" e no relatório/PDF. */
  function svg(s, type, label) {
    const W = 720, H = 250, L = 28, R = 28, T = 16, B = 30, iw = W - L - R, ih = H - T - B, n = s.labels.length;
    const max = Math.max(1, ...s.income, ...s.expense);
    const x = i => n === 1 ? L + iw / 2 : L + (iw * (type === 'bars' ? (i + .5) / n : i / (n - 1)));
    const y = v => T + ih - (v / max) * ih;
    let g = '';
    for (let k = 0; k <= 4; k++) { const yy = T + ih * k / 4; g += `<line x1="${L}" x2="${W - R}" y1="${yy}" y2="${yy}" stroke="currentColor" stroke-opacity="${k === 4 ? .35 : .12}" stroke-width="1"${k === 4 ? '' : ' stroke-dasharray="3 5"'}/>`; }
    let body = '';
    if (type === 'bars') {
      const bw = Math.min(26, iw / n / 3);
      s.labels.forEach((_, i) => {
        body += `<rect x="${x(i) - bw - 2}" y="${y(s.income[i])}" width="${bw}" height="${T + ih - y(s.income[i])}" rx="4" fill="${C.income}"><title>${esc(s.labels[i])}: Entradas ${money.format(s.income[i])}</title></rect>`;
        body += `<rect x="${x(i) + 2}" y="${y(s.expense[i])}" width="${bw}" height="${T + ih - y(s.expense[i])}" rx="4" fill="${C.expense}"><title>${esc(s.labels[i])}: Saídas ${money.format(s.expense[i])}</title></rect>`;
      });
    } else {
      const line = (arr, color, id) => {
        const pts = arr.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
        const area = `${x(0).toFixed(1)},${T + ih} ${pts} ${x(n - 1).toFixed(1)},${T + ih}`;
        return `<defs><linearGradient id="${id}" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="${color}" stop-opacity=".28"/><stop offset="1" stop-color="${color}" stop-opacity="0"/></linearGradient></defs>
          <polygon points="${area}" fill="url(#${id})"/><polyline points="${pts}" fill="none" stroke="${color}" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>`
          + arr.map((v, i) => `<circle cx="${x(i).toFixed(1)}" cy="${y(v).toFixed(1)}" r="3.6" fill="${color}" stroke="#fff" stroke-opacity=".7" stroke-width="1"><title>${esc(s.labels[i])}: ${color === C.income ? 'Entradas' : 'Saídas'} ${money.format(v)}</title></circle>`).join('');
      };
      body = line(s.income, C.income, 'gi' + Math.random().toString(36).slice(2, 6)) + line(s.expense, C.expense, 'ge' + Math.random().toString(36).slice(2, 6));
    }
    const step = Math.ceil(n / 14);
    const xl = s.labels.map((l, i) => i % step ? '' : `<text x="${x(i)}" y="${H - 8}" text-anchor="middle" font-size="12" fill="currentColor" fill-opacity=".75">${esc(l)}</text>`).join('');
    return `<svg class="gf-svg" viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(label)}" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">${g}${body}${xl}</svg>`;
  }

  const sumOf = a => a.reduce((t, v) => t + v, 0);
  const describe = (s, type, period) => `Gráfico de ${TYPES[type].toLowerCase()} ${PERIODS[period].span}: entradas ${money.format(sumOf(s.income))}, saídas ${money.format(sumOf(s.expense))}.`;

  /* ----------------------------------------------------------------- escolha guardada */
  function load(key) {
    try { const v = JSON.parse(localStorage.getItem('lumina-chart-' + key) || '{}'); return { period: PERIODS[v.period] ? v.period : 'month', type: TYPES[v.type] ? v.type : 'bars' }; }
    catch (e) { return { period: 'month', type: 'bars' }; }
  }
  const save = (key, v) => { try { localStorage.setItem('lumina-chart-' + key, JSON.stringify(v)); } catch (e) {} };

  /** Os botões "Diário · Semanal · ... | Barras · Linhas". Reaproveita o estilo .seg do sistema. */
  function controls(host, key, onChange) {
    const st = load(key);
    host.innerHTML = `<div class="seg" role="group" aria-label="Período do gráfico">${Object.entries(PERIODS).map(([k, p]) => `<button type="button" data-p="${k}" aria-pressed="${k === st.period}" class="${k === st.period ? 'on' : ''}">${p.label}</button>`).join('')}</div>
      <div class="seg" role="group" aria-label="Tipo de gráfico">${Object.entries(TYPES).map(([k, l]) => `<button type="button" data-t="${k}" aria-pressed="${k === st.type}" class="${k === st.type ? 'on' : ''}">${l}</button>`).join('')}</div>`;
    host.querySelectorAll('[data-p],[data-t]').forEach(b => b.onclick = () => {
      const cur = load(key); if (b.dataset.p) cur.period = b.dataset.p; if (b.dataset.t) cur.type = b.dataset.t;
      save(key, cur);
      host.querySelectorAll('[data-p]').forEach(x => { const on = x.dataset.p === cur.period; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on); });
      host.querySelectorAll('[data-t]').forEach(x => { const on = x.dataset.t === cur.type; x.classList.toggle('on', on); x.setAttribute('aria-pressed', on); });
      onChange(cur);
    });
  }

  /* ----------------------------------------------------------------- Visão geral */
  let overviewToken = 0;
  async function renderOverview() {
    const box = document.querySelector('#bar-chart'); if (!box) return;
    const st = load('overview'), mine = ++overviewToken;
    const title = document.querySelector('#chart-title'); if (title) title.textContent = 'Movimento ' + PERIODS[st.period].adj;
    let s;
    try { s = await fetchSeries(st.period); } catch (e) { box.textContent = 'Não foi possível carregar o gráfico.'; return; }
    if (mine !== overviewToken) return;                              // chegou uma escolha mais recente: esta já não interessa
    box.setAttribute('aria-label', describe(s, st.type, st.period));
    if (st.type === 'bars') { box.classList.remove('is-lines'); box.innerHTML = barsHtml(s); }                   // exatamente o original
    else { box.classList.add('is-lines'); box.innerHTML = svg(s, 'lines', describe(s, 'lines', st.period)); }
  }
  function mountOverview() {
    const host = document.querySelector('#chart-controls'); if (!host || host.dataset.ready) return;
    host.dataset.ready = '1'; controls(host, 'overview', renderOverview);
  }

  /* ----------------------------------------------------------------- Relatório (e PDF) */
  function reportChartHtml() {
    return `<div class="report-summary report-chart-box"><div class="panel-heading"><h3 id="report-chart-title">Entradas e saídas</h3><div id="report-chart-controls" class="chart-controls no-print"></div></div>
      <div id="report-chart" class="gf-chart"></div>
      <div class="legend"><span><i class="legend-income"></i>Entradas</span><span><i class="legend-expense"></i>Saídas</span></div></div>`;
  }
  let reportToken = 0;
  async function renderReportChart() {
    const box = document.querySelector('#report-chart'); if (!box) return;
    const st = load('report'), mine = ++reportToken;
    const t = document.querySelector('#report-chart-title'); if (t) t.textContent = 'Entradas e saídas · ' + PERIODS[st.period].label.toLowerCase() + ' ' + PERIODS[st.period].span.replace('dos ', '(').replace('das ', '(') + ')';
    let s;
    try { s = await fetchSeries(st.period); } catch (e) { box.textContent = 'Não foi possível carregar o gráfico.'; return; }
    if (mine !== reportToken) return;
    box.innerHTML = svg(s, st.type, describe(s, st.type, st.period));
  }
  function mountReport() {
    const host = document.querySelector('#report-chart-controls'); if (!host) return;
    controls(host, 'report', renderReportChart); renderReportChart();
  }

  window.GFCharts = { PERIODS, TYPES, svg, renderOverview, mountOverview, reportChartHtml, mountReport };
})();
