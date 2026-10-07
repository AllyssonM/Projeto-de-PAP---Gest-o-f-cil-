/* =========================================================================
   META ADS (ecrã)  (assets/js/meta.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  a aba "Meta Ads" (só o dono do negócio).
     - Estado: "Não configurado" / "Não ligado" / "Ligado" (e aviso se a autorização expirou).
     - EXPLICA que permissões pede (só leitura) e que nunca vê a palavra-passe do Facebook.
     - Ligar: leva a pessoa ao site da Meta (OAuth); ao voltar mostra o resultado.
     - Escolher a conta de anúncios, o período (7/30/90 dias) e ver campanhas, conjuntos e anúncios com as métricas.
     - Atualizar agora; abre com a última leitura guardada e atualiza sozinho se tiver mais de 10 minutos.
     - Desligar (revoga o acesso na Meta e apaga o token e os dados guardados).
   NÃO SIMULA: sem credenciais (config/meta.php) diz "não configurado". Mostra "—" quando a Meta não devolve um valor.
   DADOS: api/meta.php. O token nunca chega ao navegador. Usa os componentes que já existem (panel, summary-card, seg, tabela).
   ========================================================================= */
(function () {
  'use strict';
  const panel = document.querySelector('#meta-panel');
  if (!panel) return;
  const LOC = () => window.LUMINA_LOCALE || 'pt-PT';
  const STALE_MIN = 10;
  const $m = s => panel.querySelector(s);
  let st = null, data = null, level = 'campaigns', busy = false;

  const num = v => v == null ? '—' : new Intl.NumberFormat(LOC()).format(v);
  const cur = (v, c, d = 2) => v == null ? '—' : new Intl.NumberFormat(LOC(), { style: 'currency', currency: c || 'EUR', minimumFractionDigits: d, maximumFractionDigits: d }).format(v);
  const pct = v => v == null ? '—' : new Intl.NumberFormat(LOC(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v) + ' %';
  const roasF = v => v == null ? '—' : new Intl.NumberFormat(LOC(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v) + '×';
  const fmt = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString(LOC(), { dateStyle: 'short', timeStyle: 'short' }) : 'Nunca';
  const STATUS = { ACTIVE: 'Ativa', PAUSED: 'Pausada', ARCHIVED: 'Arquivada', DELETED: 'Eliminada', DISAPPROVED: 'Reprovada', WITH_ISSUES: 'Com problemas', PENDING_REVIEW: 'Em revisão',
    CAMPAIGN_PAUSED: 'Campanha pausada', ADSET_PAUSED: 'Conjunto pausado', IN_PROCESS: 'Em processamento', PENDING_BILLING_INFO: 'Falta informação de pagamento' };
  const post = (action, extra = {}) => api('meta.php', { method: 'POST', body: JSON.stringify({ action, ...extra, csrf }) });

  async function load() {
    try { st = await api('meta.php?action=status'); render(); }
    catch (e) {
      $m('#meta-chip').textContent = 'Erro';
      $m('#meta-body').innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível ver o estado</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="meta-retry">Tentar novamente</button></div>`;
      $m('#meta-retry').onclick = load;
    }
  }

  function render() {
    const chip = $m('#meta-chip'), body = $m('#meta-body'), box = document.querySelector('#meta-data');
    box.hidden = true;
    const about = `<p class="muted g-about">Vais ser levado ao site da Meta para autorizares. Pedimos só:</p><ul class="g-scopes">${st.scopes.map(s => `<li>${esc(s)}</li>`).join('')}</ul><p class="muted g-about">🔒 ${esc(st.privacy)}</p>`;
    if (!st.configured) {
      chip.textContent = 'Não configurado'; chip.classList.remove('on');
      body.innerHTML = `<p class="muted">A ligação à Meta Ads ainda não está configurada neste sistema.</p><p class="muted g-about">Quem instala o sistema precisa de criar uma app em developers.facebook.com e preencher <code>config/meta.php</code> (ID e chave secreta da app). Endereço de redirecionamento a registar na Meta: <code>${esc(st.redirect_uri)}</code></p>`;
      return;
    }
    if (!st.connected) {
      chip.textContent = 'Não ligado'; chip.classList.remove('on');
      body.innerHTML = `${st.last_error ? `<p class="ap-form-error" role="alert">${esc(st.last_error)}</p>` : ''}${about}<div class="form-actions"><button type="button" class="button primary" id="meta-connect">Ligar Meta Ads</button></div>`;
      $m('#meta-connect').onclick = connect;
      return;
    }
    chip.textContent = st.expired ? 'Autorização expirada' : 'Meta Ads ligada'; chip.classList.toggle('on', !st.expired);
    const warn = st.expired ? 'A autorização da Meta expirou. Liga outra vez para voltares a ver os dados.'
      : (st.last_error || (st.days_left != null && st.days_left <= 7 ? `A autorização da Meta expira em ${st.days_left} dia(s). Liga outra vez antes de expirar.` : ''));
    body.innerHTML = `${warn ? `<p class="ap-form-error" role="alert">${esc(warn)} <button type="button" class="button secondary small" id="meta-reconnect">Ligar outra vez</button></p>` : ''}
      <p class="muted">Ligada como <b>${esc(st.fb_user || '—')}</b>${st.days_left != null && !st.expired ? ` · a autorização vale mais ${st.days_left} dia(s)` : ''}</p>
      <fieldset class="g-fs"><legend>Conta de anúncios</legend><div id="meta-accounts"><div class="skeleton" style="height:22px"></div></div></fieldset>
      <div class="form-actions"><button type="button" class="button danger" id="meta-disconnect">Desligar Meta Ads</button></div>`;
    $m('#meta-reconnect')?.addEventListener('click', connect);
    $m('#meta-disconnect').onclick = disconnect;
    if (!st.expired) loadAccounts();
    if (st.account && !st.expired) { box.hidden = false; showData(); }
  }

  async function loadAccounts() {
    const host = $m('#meta-accounts');
    try {
      const d = await api('meta.php?action=accounts');
      if (!host.isConnected) return;                  // o cartão foi redesenhado entretanto
      if (!d.items.length) { host.innerHTML = '<p class="muted">Esta ligação não tem nenhuma conta de anúncios disponível. Confirma na Meta que a tua conta tem acesso a uma conta de anúncios.</p>'; return; }
      host.innerHTML = `<select id="meta-account" aria-label="Conta de anúncios">${st.account ? '' : '<option value="">Escolhe uma conta…</option>'}${d.items.map(a => `<option value="${esc(a.id)}"${a.selected ? ' selected' : ''}>${esc(a.name || a.id)} · ${esc(a.currency)}${a.status !== 'ACTIVE' ? ' · ' + esc(a.status) : ''}</option>`).join('')}</select>`;
      $m('#meta-account').onchange = async ev => {
        if (!ev.target.value) return;
        try { const r = await post('select_account', { id: ev.target.value }); st.account = r.account; data = null; msg('Conta de anúncios escolhida.'); document.querySelector('#meta-data').hidden = false; showData(); }
        catch (e) { msg(e.message, true); }
      };
    } catch (e) { if (!host.isConnected) return; host.innerHTML = `<p class="ap-form-error">${esc(e.message)} <button type="button" class="button secondary small" id="meta-acc-retry">Tentar novamente</button></p>`; host.querySelector('#meta-acc-retry').onclick = loadAccounts; }
  }

  async function connect() {
    try { const d = await post('connect'); location.href = d.url; }       // vai ao site da Meta
    catch (e) { msg(e.message, true); }
  }

  async function disconnect() {
    if (!await UX.confirm({ title: 'Desligar a Meta Ads?', text: 'Revogamos o acesso na Meta e apagamos o token e os dados que guardámos. Não altera nada nos teus anúncios.', confirmLabel: 'Desligar', danger: true })) return;
    try { await post('disconnect'); data = null; msg('Meta Ads desligada.'); await load(); }
    catch (e) { msg(e.message, true); }
  }

  /* ---------- dados ---------- */
  async function showData() {
    setPeriodButtons();
    renderData();                                   // vazio/a carregar
    try {
      const d = await api('meta.php?action=data');
      data = d.data;
      renderData();
      const old = !data || !st.last_sync_at || (new Date(st.now.replace(' ', 'T')) - new Date(data.fetched_at.replace(' ', 'T'))) > STALE_MIN * 60000;
      if (old) sync(false);
    } catch (e) { msg(e.message, true); sync(false); }
  }

  async function sync(manual) {
    if (busy) return; busy = true;
    const btn = document.querySelector('#meta-refresh'); if (btn) { btn.disabled = true; btn.textContent = 'A atualizar…'; }
    document.querySelector('#meta-table').setAttribute('aria-busy', 'true');
    try {
      const d = await post('sync');
      data = d.data; st.last_sync_at = d.last_sync_at; st.last_error = null;
      renderData();
      if (manual) msg('Dados da Meta atualizados.');
    } catch (e) {
      msg(e.message, true);
      const t = document.querySelector('#meta-table');
      if (!data) t.innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível obter os dados da Meta</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="meta-data-retry">Tentar novamente</button></div>`;
      document.querySelector('#meta-data-retry')?.addEventListener('click', () => sync(true));
      if (/ligar|expirou|autoriza/i.test(e.message)) load();
    } finally { busy = false; document.querySelector('#meta-table').removeAttribute('aria-busy'); const b = document.querySelector('#meta-refresh'); if (b) { b.disabled = false; b.textContent = 'Atualizar'; } }
  }

  function setPeriodButtons() {
    document.querySelectorAll('#meta-period button').forEach(b => { const on = b.dataset.p === st.date_preset; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
  }

  const card = (label, value, hint) => `<article class="summary-card"><span>${label}</span><strong>${value}</strong>${hint ? `<small>${hint}</small>` : ''}</article>`;

  function renderData() {
    const totals = document.querySelector('#meta-totals'), table = document.querySelector('#meta-table'), upd = document.querySelector('#meta-updated');
    document.querySelectorAll('#meta-level button').forEach(b => { const on = b.dataset.l === level; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
    document.querySelector('#meta-level-title').textContent = { campaigns: 'Campanhas', adsets: 'Conjuntos de anúncios', ads: 'Anúncios' }[level];
    if (!data) {
      upd.textContent = '';
      totals.innerHTML = ['Investimento', 'Impressões', 'Cliques', 'Alcance', 'Conversões', 'CPC', 'CPM', 'CTR', 'ROAS'].map(l => card(l, '…')).join('');
      table.innerHTML = '<div class="skeleton-stack"><div class="skeleton" style="height:22px"></div><div class="skeleton" style="height:22px"></div><div class="skeleton" style="height:22px"></div></div>';
      return;
    }
    const t = data.totals, c = data.currency;
    upd.textContent = `Conta ${st.account?.name || data.account_id} · atualizado em ${fmt(data.fetched_at)}`;
    const none = (v) => v == null ? 'A Meta não devolveu este valor' : '';
    totals.innerHTML = [
      card('Investimento', cur(t.spend, c)), card('Impressões', num(t.impressions)), card('Cliques', num(t.clicks)),
      card('Alcance', num(t.reach)), card('Conversões', num(t.conversions), t.conversion_type === 'lead' ? 'Contactos (leads)' : (t.conversion_type === 'purchase' ? 'Compras' : 'Sem conversões no período')),
      card('CPC', cur(t.cpc, c), none(t.cpc)), card('CPM', cur(t.cpm, c), none(t.cpm)), card('CTR', pct(t.ctr), none(t.ctr)), card('ROAS', roasF(t.roas), t.roas == null ? 'Sem valor de compras para calcular' : ''),
    ].join('');
    const rows = data[level] || [];
    if (!rows.length) {
      table.innerHTML = `<div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">📣</span><b>Sem ${{ campaigns: 'campanhas', adsets: 'conjuntos de anúncios', ads: 'anúncios' }[level]}</b><span>Esta conta de anúncios não tem nada para mostrar neste nível.</span></div>`;
      return;
    }
    const budget = r => r.daily_budget != null ? `${cur(r.daily_budget, c)}<span>/dia</span>` : (r.lifetime_budget != null ? `${cur(r.lifetime_budget, c)} <span>total</span>` : '—');
    table.innerHTML = `<table><thead><tr><th>Nome</th><th>Estado</th>${level !== 'ads' ? '<th class="num">Orçamento</th>' : ''}<th class="num">Investimento</th><th class="num">Impressões</th><th class="num">Cliques</th><th class="num">CTR</th><th class="num">CPC</th><th class="num">Conversões</th><th class="num">ROAS</th></tr></thead><tbody>${rows.map(r => {
      const m = r.metrics;
      return `<tr><td class="name" title="${esc(r.name)}">${esc(r.name)}</td><td>${esc(STATUS[r.effective_status] || r.effective_status || '—')}</td>${level !== 'ads' ? `<td class="num">${budget(r)}</td>` : ''}<td class="num">${cur(m.spend, c)}</td><td class="num">${num(m.impressions)}</td><td class="num">${num(m.clicks)}</td><td class="num">${pct(m.ctr)}</td><td class="num">${cur(m.cpc, c)}</td><td class="num">${num(m.conversions)}</td><td class="num">${roasF(m.roas)}</td></tr>`;
    }).join('')}</tbody></table>`;
  }

  document.querySelector('#meta-refresh').onclick = () => sync(true);
  document.querySelectorAll('#meta-level button').forEach(b => b.onclick = () => { level = b.dataset.l; renderData(); });
  document.querySelectorAll('#meta-period button').forEach(b => b.onclick = async () => {
    try {
      const r = await post('set_period', { preset: b.dataset.p });
      st.date_preset = r.date_preset; data = r.data; setPeriodButtons(); renderData();
      if (!data) sync(false);
    } catch (e) { msg(e.message, true); }
  });

  // resultado do regresso da Meta (?meta=connected|denied|state|error|permission)
  const back = new URLSearchParams(location.search).get('meta');
  const BACK = { connected: ['success', 'Meta Ads ligada com sucesso.'], denied: ['warn', 'Não deste autorização à Meta. Podes tentar outra vez quando quiseres.'],
    state: ['error', 'O pedido de ligação expirou ou não era válido. Tenta ligar outra vez.'], error: ['error', 'Não foi possível concluir a ligação à Meta. Tenta outra vez.'],
    permission: ['error', 'A Meta não deu as permissões necessárias. Liga outra vez e aceita todas as permissões pedidas.'] };
  if (back && BACK[back]) { window.UX?.toast(BACK[back][0], BACK[back][1]); history.replaceState(null, '', location.pathname + '#meta'); setTimeout(() => window.showSection?.('meta'), 400); }

  document.addEventListener('gf:section', e => { if (e.detail === 'meta') load(); });
})();
