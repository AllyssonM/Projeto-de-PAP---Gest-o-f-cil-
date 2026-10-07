/* =========================================================================
   FUNCIONÁRIOS: A ÁREA DO LÍDER  (assets/js/staff.js)
   -------------------------------------------------------------------------
   Só o responsável do negócio vê isto. Duas vistas na mesma aba:
     LISTA    resumo da equipa (online, produtividade, vendas, melhor desempenho, metas, atividades, entradas, avisos), filtros e os cartões.
     PERFIL   um funcionário por inteiro: métricas do período, gráficos diários/semanais/mensais, metas, tarefas, entradas e saídas,
              observações do líder e a conversa. Daqui o líder também dá tarefas, define metas, escreve observações e envia anúncios.
   Também preenche o cartão «A tua equipa» da Visão geral. Todos os números vêm de api/staff.php (calculados no servidor).
   ========================================================================= */
(function () {
  'use strict';
  const u = window.GF_USER || {};
  const root = $('#staff'), overview = $('#staff-overview');
  if (!u.isOwner || (!root && !overview)) return;

  const PERIODS = { today: 'Hoje', week: 'Esta semana', month: 'Este mês', custom: 'Datas à escolha' };
  const KINDS = { sales_count: 'Número de vendas', sales_value: 'Valor das vendas', tasks_done: 'Tarefas concluídas' };
  const eur = n => money.format(Number(n) || 0);
  const pct = v => v == null ? '—' : `${v} %`;
  const f = { period: 'month', from: '', to: '', q: '', job: '', status: '', prod_min: '', perf_min: '', sales_min: '', sort: 'name' };
  let data = null, ovData = null, profileId = null, profileData = null, chat = null, unit = 'day', loadSeq = 0;

  const qs = (obj = f) => new URLSearchParams(Object.entries(obj).filter(([, v]) => v !== '' && v != null)).toString();
  const isVisible = el => el && !el.classList.contains('hidden');

  /* ======================= pequenos blocos ======================= */
  function periodControl(target, state, onChange) {
    target.innerHTML = `<div class="seg period-seg" role="group" aria-label="Período">${Object.entries(PERIODS).map(([k, l]) => `<button type="button" data-p="${k}" class="${state.period === k ? 'on' : ''}" aria-pressed="${state.period === k}">${l}</button>`).join('')}</div>
      <span class="period-dates" ${state.period === 'custom' ? '' : 'hidden'}><label>De<input type="date" name="from" value="${esc(state.from)}"></label><label>Até<input type="date" name="to" value="${esc(state.to)}"></label></span>`;
    target.querySelectorAll('[data-p]').forEach(b => b.onclick = () => { state.period = b.dataset.p; if (state.period !== 'custom') { state.from = ''; state.to = ''; } periodControl(target, state, onChange); onChange(); });
    target.querySelectorAll('.period-dates input').forEach(i => i.onchange = () => { state[i.name] = i.value; if (state.from && state.to) onChange(); });
  }
  const statCard = (label, value, extra = '', cls = '') => `<article class="summary-card staff-stat ${cls}"><span>${label}</span><strong>${value}</strong>${extra ? `<small>${extra}</small>` : ''}</article>`;

  /* ======================= resumo ======================= */
  function summaryHtml(s) {
    return `<div class="summary-grid staff-summary-grid">
      ${statCard('Funcionários', s.total, s.pause ? `${s.pause} em pausa` : '')}
      ${statCard('Online', s.online, `${s.away} ausente${s.away === 1 ? '' : 's'} · ${s.offline} offline`, 'income')}
      ${statCard('Produtividade média', pct(s.avg_productivity), 'tarefas concluídas ÷ tarefas do período')}
      ${statCard('Vendas', s.sales_count, eur(s.sales_value))}
      ${statCard('Melhor desempenho', s.best ? esc(s.best.name.split(' ')[0]) : '—', s.best ? `${s.best.performance} % · média de tarefas e metas` : 'ainda sem dados')}
      ${statCard('Metas cumpridas', `${s.goals_done} de ${s.goals_total}`, 'nos meses do período')}
    </div>`;
  }
  function listsHtml(s) {
    const act = s.recent_activity.length ? s.recent_activity.map(a => `<li>${GFT.avatar(a.photo, a.name, 30)}<span><strong>${esc(a.name)}</strong> ${esc(a.text.charAt(0).toLowerCase() + a.text.slice(1))}<small>${esc(GFT.when(a.at))}</small></span></li>`).join('') : '<li class="muted">Ainda sem atividade.</li>';
    const ent = s.recent_logins.length ? s.recent_logins.map(a => `<li>${GFT.avatar(a.photo, a.name, 30)}<span><strong>${esc(a.name)}</strong> entrou no sistema<small>${esc(GFT.when(a.at))}</small></span></li>`).join('') : '<li class="muted">Ainda ninguém entrou.</li>';
    return `<article class="panel staff-list-card"><p class="eyebrow">ATIVIDADES RECENTES</p><ul class="staff-feed">${act}</ul></article>
      <article class="panel staff-list-card"><p class="eyebrow">ENTRADAS RECENTES</p><ul class="staff-feed">${ent}</ul></article>
      <article class="panel staff-list-card"><p class="eyebrow">PENDENTE</p>
        <ul class="staff-feed pend"><li><span><strong>${s.notifications_unread}</strong> aviso${s.notifications_unread === 1 ? '' : 's'} por ler<small><button type="button" class="linklike" data-act="bell">Abrir o sino</button></small></span></li>
        <li><span><strong>${s.messages_unread}</strong> mensage${s.messages_unread === 1 ? 'm' : 'ns'} por ler<small>${s.messages_unread ? 'Abre o perfil de quem escreveu' : 'Tudo em dia'}</small></span></li></ul></article>`;
  }

  /* ======================= cartões ======================= */
  function cardHtml(c) {
    const la = c.last_activity;
    return `<article class="staff-card" data-id="${c.id}">
      <header>${GFT.avatar(c.photo, c.name, 52)}<div><h3><button type="button" class="staff-open" data-id="${c.id}" aria-label="Ver perfil de ${esc(c.name)}">${esc(c.name)}</button></h3><small>${esc(c.job_title || 'Sem cargo definido')}${c.department ? ' · ' + esc(c.department) : ''}</small></div>${GFT.chip(c.state)}</header>
      <div class="staff-meters">
        <div><span>Produtividade <b>${pct(c.productivity)}</b></span>${GFT.meter(c.productivity, 'Produtividade de ' + c.name)}</div>
        <div><span>Desempenho geral <b>${pct(c.performance)}</b></span>${GFT.meter(c.performance, 'Desempenho de ' + c.name)}</div>
      </div>
      <dl class="staff-facts">
        <div><dt>Vendas</dt><dd>${c.sales_count} · ${eur(c.sales_value)}</dd></div>
        <div><dt>Objetivos cumpridos</dt><dd>${c.goals_total ? `${c.goals_done} de ${c.goals_total}` : 'sem metas'}</dd></div>
        <div><dt>Tarefas concluídas</dt><dd>${c.tasks_done}${c.tasks_pending ? ` <small>(${c.tasks_pending} por fazer${c.tasks_overdue ? ', ' + c.tasks_overdue + ' atrasada' + (c.tasks_overdue > 1 ? 's' : '') : ''})</small>` : ''}</dd></div>
        <div><dt>Última atividade</dt><dd>${la ? `${esc(la.text)} <small>${esc(GFT.when(la.at))}</small>` : 'Ainda nenhuma'}</dd></div>
        <div class="wide"><dt>Última entrada no sistema</dt><dd>${c.last_login_at ? esc(GFT.full(c.last_login_at)) : 'Nunca entrou'}</dd></div>
      </dl></article>`;
  }

  /* ======================= lista ======================= */
  function renderList() {
    $('#staff-summary').innerHTML = summaryHtml(data.summary);
    $('#staff-panels').innerHTML = listsHtml(data.summary);
    const sel = $('#staff-filters [name=job]'), cur = sel.value;
    sel.innerHTML = '<option value="">Todos os cargos</option>' + data.jobs.map(j => `<option${j === cur ? ' selected' : ''}>${esc(j)}</option>`).join('');
    const box = $('#staff-cards');
    box.innerHTML = data.employees.length ? data.employees.map(cardHtml).join('')
      : `<div class="panel staff-empty">${data.summary.total === 0 ? '<h3>Ainda não tens funcionários</h3><p class="muted">Adiciona-os na aba «Equipa». Depois de entrarem, aparecem aqui.</p><button type="button" class="button primary" data-go="team">Ir para a Equipa</button>'
        : '<h3>Nenhum funcionário com estes filtros</h3><p class="muted">Experimenta limpar os filtros ou mudar o período.</p>'}</div>`;
    $('#staff-count').textContent = data.filtered ? `${data.employees.length} de ${data.summary.total}` : `${data.summary.total}`;
    $('#staff-period-note').textContent = `${GFT.day(data.period.from)} a ${GFT.day(data.period.to)}`;
  }
  async function loadList() {
    const seq = ++loadSeq;
    try {
      const d = await api('staff.php?' + qs());
      if (seq !== loadSeq) return;                                             // pedido antigo: ignora
      data = d; GFT.setNow(d.now);
      renderList(); if (!f.q && !f.job && !f.status && !f.prod_min && !f.perf_min && !f.sales_min && f.period === 'month') { ovData = d; renderOverview(); }
    } catch (e) { $('#staff-cards').innerHTML = `<div class="panel staff-empty"><p>${esc(e.message)}</p></div>`; }
  }
  let t = null; const later = () => { clearTimeout(t); t = setTimeout(loadList, 250); };

  /* ======================= cartão na Visão geral ======================= */
  function renderOverview() {
    if (!overview || !ovData) return;
    const s = ovData.summary;
    overview.hidden = false;
    overview.innerHTML = `<div class="panel-heading"><div><p class="eyebrow">EQUIPA</p><h2>A tua equipa</h2></div><button type="button" class="button secondary" data-go="staff">Ver funcionários</button></div>
      ${s.total === 0 ? '<p class="muted">Ainda não tens funcionários. Adiciona-os na aba «Equipa» e acompanha aqui o trabalho de cada um.</p>' : `
      <div class="staff-ov-grid">
        <div><span>Funcionários</span><strong>${s.total}</strong></div><div><span>Online</span><strong class="positive">${s.online}</strong></div><div><span>Ausentes</span><strong>${s.away}</strong></div>
        <div><span>Produtividade média</span><strong>${pct(s.avg_productivity)}</strong></div><div><span>Vendas</span><strong>${s.sales_count}</strong><small>${eur(s.sales_value)}</small></div>
        <div><span>Melhor desempenho</span><strong>${s.best ? esc(s.best.name.split(' ')[0]) : '—'}</strong><small>${s.best ? s.best.performance + ' %' : ''}</small></div>
        <div><span>Metas cumpridas</span><strong>${s.goals_done}/${s.goals_total}</strong></div>
        <div><span>Pendente</span><strong>${s.notifications_unread + s.messages_unread}</strong><small>${s.messages_unread} mensagens · ${s.notifications_unread} avisos</small></div>
      </div>
      <div class="staff-ov-lists"><div><h3>Entradas recentes</h3><ul class="staff-feed">${s.recent_logins.slice(0, 4).map(a => `<li>${GFT.avatar(a.photo, a.name, 28)}<span><strong>${esc(a.name)}</strong><small>${esc(GFT.when(a.at))}</small></span></li>`).join('') || '<li class="muted">Ainda ninguém entrou.</li>'}</ul></div>
      <div><h3>Atividades recentes</h3><ul class="staff-feed">${s.recent_activity.slice(0, 4).map(a => `<li>${GFT.avatar(a.photo, a.name, 28)}<span><strong>${esc(a.name)}</strong> ${esc(a.text.charAt(0).toLowerCase() + a.text.slice(1))}<small>${esc(GFT.when(a.at))}</small></span></li>`).join('') || '<li class="muted">Ainda sem atividade.</li>'}</ul></div></div>`}`;
  }
  async function loadOverview() {
    try { const d = await api('staff.php'); GFT.setNow(d.now); ovData = d; renderOverview(); if (isVisible(root) && !profileId && !f.q && f.period === 'month') { data = d; renderList(); } } catch (e) { /* o cartão fica como está */ }
  }

  /* ======================= formulários em janela ======================= */
  function fieldHtml(x) {
    const req = x.required ? ' required' : '', af = x.focus ? ' data-autofocus' : '';
    if (x.type === 'textarea') return `<label>${esc(x.label)}<textarea name="${x.name}" rows="4" maxlength="${x.max || 1000}"${req}${af}></textarea></label>`;
    if (x.type === 'select') return `<label>${esc(x.label)}<select name="${x.name}"${af}>${x.options.map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('')}</select></label>`;
    if (x.type === 'checkbox') return `<label class="chk"><input type="checkbox" name="${x.name}"> <span>${esc(x.label)}</span></label>`;
    return `<label>${esc(x.label)}<input name="${x.name}" type="${x.type || 'text'}" ${x.max ? `maxlength="${x.max}"` : ''} ${x.step ? `step="${x.step}" min="${x.min ?? 0}"` : ''} value="${esc(x.value ?? '')}"${req}${af}></label>`;
  }
  function formModal({ title, intro, fields, submit = 'Guardar', onSubmit }) {
    return new Promise(resolve => {
      const { root: m, close } = UX.modal(`<h2>${esc(title)}</h2>${intro ? `<p class="muted">${esc(intro)}</p>` : ''}<form class="sf-form" novalidate>${fields.map(fieldHtml).join('')}
        <p class="ap-form-error" role="alert" hidden></p><div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button class="button primary" type="submit">${esc(submit)}</button></div></form>`, { onClose: () => resolve(false) });
      const form = m.querySelector('form'), err = m.querySelector('.ap-form-error');
      m.querySelector('[data-no]').onclick = () => close(false);
      form.addEventListener('submit', async e => {
        e.preventDefault(); err.hidden = true;
        const data = {}; fields.forEach(x => { data[x.name] = x.type === 'checkbox' ? form.elements[x.name].checked : form.elements[x.name].value.trim(); });
        const missing = fields.find(x => x.required && !data[x.name]);
        if (missing) { err.textContent = `Preenche: ${missing.label}.`; err.hidden = false; form.elements[missing.name].focus(); return; }
        const b = form.querySelector('[type=submit]'); b.disabled = true;
        // resolve ANTES de fechar: o próprio fecho da janela também resolve (com «false»)
        try { await onSubmit(data); resolve(true); close(true); } catch (x) { err.textContent = x.message; err.hidden = false; b.disabled = false; }
      });
    });
  }
  const post = body => api('staff.php', { method: 'POST', body: JSON.stringify({ ...body, csrf }) });

  /* ======================= perfil ======================= */
  const bars = (series, key, fmt, label) => {
    const max = Math.max(1, ...series.map(s => s[key])), n = series.length, step = n > 10 ? 2 : 1, many = n > 8;
    return `<figure class="bars-fig"><div class="st-bars${many ? ' many' : ''}" role="img" aria-label="${esc(label)}: ${esc(series.map(s => `${s.label} ${fmt(s[key])}`).join('; '))}" style="--n:${n}">
      ${series.map((s, i) => `<div class="st-bar" title="${esc(s.label)}: ${esc(fmt(s[key]))}"><b>${esc(fmt(s[key]))}</b><span class="st-bar-fill" style="height:${Math.round(s[key] / max * 100)}%"></span><em>${i % step === 0 ? esc(s.label) : ''}</em></div>`).join('')}</div><figcaption>${esc(label)}</figcaption></figure>`;
  };
  function chartsHtml(p) {
    const s = p.series[unit];
    const u_ = { day: 'Últimos 14 dias', week: 'Últimas 8 semanas (início na segunda)', month: 'Últimos 6 meses' }[unit];
    return `<div class="seg chart-seg" role="group" aria-label="Escala dos gráficos">${[['day', 'Diário'], ['week', 'Semanal'], ['month', 'Mensal']].map(([k, l]) => `<button type="button" data-unit="${k}" class="${unit === k ? 'on' : ''}" aria-pressed="${unit === k}">${l}</button>`).join('')}</div>
      <p class="muted chart-sub">${u_}</p><div class="charts-grid">${bars(s, 'tasks', v => String(v), 'Tarefas concluídas')}${bars(s, 'sales', v => String(v), 'Vendas realizadas')}${bars(s, 'sales_value', v => eur(v).replace(/\s?€/, '€'), 'Valor das vendas')}${bars(s, 'hours', v => v + ' h', 'Horas de trabalho')}</div>`;
  }
  function profileHtml(p) {
    const e = p.employee;
    const goals = p.goals.length ? p.goals.map(g => `<li><div class="goal-top"><strong>${esc(g.kind_label)}</strong><span class="muted">${esc(g.month.slice(5) + '/' + g.month.slice(0, 4))}</span>${g.achieved ? '<span class="chip-ok">Cumprida</span>' : ''}<button type="button" class="linklike danger" data-act="goal-del" data-id="${g.id}" aria-label="Apagar meta ${esc(g.kind_label)}">Apagar</button></div>
        ${GFT.meter(g.percent, g.kind_label)}<small>${g.kind === 'sales_value' ? eur(g.actual) + ' de ' + eur(g.target) : g.actual + ' de ' + g.target} · ${g.percent} %</small></li>`).join('') : '<li class="muted">Ainda não há metas. Define a primeira.</li>';
    const pend = p.tasks_pending.length ? p.tasks_pending.map(x => `<li><div><strong>${esc(x.title)}</strong>${x.detail ? `<small>${esc(x.detail)}</small>` : ''}</div><span class="due ${x.due_date && x.due_date < p.now.slice(0, 10) ? 'late' : ''}">${x.due_date ? (x.due_date < p.now.slice(0, 10) ? 'Atrasada · ' : 'Até ') + esc(GFT.day(x.due_date)) : 'Sem prazo'}</span><button type="button" class="linklike danger" data-act="task-del" data-id="${x.id}" aria-label="Apagar tarefa ${esc(x.title)}">Apagar</button></li>`).join('') : '<li class="muted">Sem tarefas por fazer.</li>';
    const done = p.tasks_done.length ? p.tasks_done.map(x => `<li><span>✓ ${esc(x.title)}</span><small>${esc(GFT.when(x.completed_at))}</small></li>`).join('') : '<li class="muted">Ainda não concluiu nenhuma.</li>';
    const shifts = p.shifts.length ? `<div class="table-wrap"><table class="staff-table"><thead><tr><th>Entrada</th><th>Saída</th><th>Duração</th></tr></thead><tbody>${p.shifts.map(s => `<tr><td>${esc(GFT.full(s.started_at))}</td><td>${s.ended_at ? esc(GFT.full(s.ended_at)) : '<span class="chip-ok">Em curso</span>'}</td><td>${esc(GFT.mins(+s.mins))}${s.source === 'manual' ? ' <small>(manual)</small>' : ''}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Ainda não registou turnos.</p>';
    const notes = p.notes.length ? p.notes.map(n => `<li><p>${esc(n.body).replace(/\n/g, '<br>')}</p><small>${esc(GFT.when(n.created_at))} · ${n.shared ? '<span class="chip-ok">Partilhada com o funcionário</span>' : '<span class="chip-priv">Privada (só tu vês na aplicação)</span>'}</small><button type="button" class="linklike danger" data-act="note-del" data-id="${n.id}" aria-label="Apagar observação">Apagar</button></li>`).join('') : '<li class="muted">Sem observações.</li>';
    return `<div class="staff-profile">
      <button type="button" class="button secondary staff-back" data-act="back">← Voltar aos funcionários</button>
      <article class="panel staff-hero">
        <div class="staff-hero-id">${GFT.avatar(e.photo, e.name, 84)}<div><p class="eyebrow">PERFIL DO FUNCIONÁRIO</p><h2>${esc(e.name)}</h2><p class="muted">${esc(e.job_title || 'Sem cargo definido')}${e.department ? ' · ' + esc(e.department) : ''}</p>${GFT.chip(e.state)}</div></div>
        <dl class="staff-contact"><div><dt>Email</dt><dd>${esc(e.email)}</dd></div><div><dt>Telefone</dt><dd>${esc(e.phone || '—')}</dd></div><div><dt>Admissão</dt><dd>${e.hired_at ? esc(GFT.day(e.hired_at)) : '—'}</dd></div><div><dt>Última entrada</dt><dd>${e.last_login_at ? esc(GFT.full(e.last_login_at)) : 'Nunca entrou'}</dd></div></dl>
        <div class="staff-actions"><button type="button" class="button primary" data-act="msg">💬 Enviar mensagem${p.unread_messages ? ` <b class="count-pill">${p.unread_messages}</b>` : ''}</button><button type="button" class="button secondary" data-act="task">+ Nova tarefa</button><button type="button" class="button secondary" data-act="goal">🎯 Definir meta</button><button type="button" class="button secondary" data-act="note">📝 Observação</button></div>
      </article>
      <article class="panel"><div class="panel-heading"><div><p class="eyebrow">NO PERÍODO</p><h2>Desempenho</h2></div><div id="profile-period" class="period-wrap"></div></div>
        <div class="staff-tiles">
          <div><span>Produtividade</span><strong>${pct(e.productivity)}</strong>${GFT.meter(e.productivity, 'Produtividade')}</div><div><span>Desempenho geral</span><strong>${pct(e.performance)}</strong>${GFT.meter(e.performance, 'Desempenho geral')}</div>
          <div><span>Vendas realizadas</span><strong>${e.sales_count}</strong></div><div><span>Valor total das vendas</span><strong>${eur(e.sales_value)}</strong></div>
          <div><span>Metas cumpridas</span><strong>${e.goals_total ? `${e.goals_done} de ${e.goals_total}` : '—'}</strong></div><div><span>Tarefas concluídas</span><strong>${e.tasks_done}</strong><small>${e.tasks_pending} pendente${e.tasks_pending === 1 ? '' : 's'}${e.tasks_overdue ? ` · ${e.tasks_overdue} atrasada${e.tasks_overdue > 1 ? 's' : ''}` : ''}</small></div>
          <div><span>Horas de trabalho</span><strong>${e.hours} h</strong></div><div><span>Última atividade</span><strong class="small-strong">${e.last_activity ? esc(e.last_activity.text) : '—'}</strong><small>${e.last_activity ? esc(GFT.when(e.last_activity.at)) : ''}</small></div></div>
        <details class="how"><summary>Como se calculam estes números</summary><p><b>Produtividade</b> = tarefas concluídas ÷ tarefas do período (com prazo no período, ou sem prazo e criadas nele, ou concluídas nele). <b>Metas</b> = quanto já foi feito de cada meta dos meses do período (no máximo 100 %). <b>Desempenho geral</b> = média da produtividade e do progresso das metas. Sem dados, mostra «—» (nunca um 0 inventado). Vendas de demonstração não contam.</p></details></article>
      <article class="panel"><div class="panel-heading"><div><p class="eyebrow">HISTÓRICO</p><h2>Produtividade ao longo do tempo</h2></div></div><div id="profile-charts">${chartsHtml(p)}</div></article>
      <div class="staff-cols">
        <article class="panel"><div class="panel-heading"><div><p class="eyebrow">OBJETIVOS</p><h2>Metas</h2></div><button type="button" class="button small secondary" data-act="goal">Definir meta</button></div><ul class="goal-list">${goals}</ul></article>
        <article class="panel"><div class="panel-heading"><div><p class="eyebrow">TRABALHO</p><h2>Tarefas</h2></div><button type="button" class="button small secondary" data-act="task">Nova tarefa</button></div><h3 class="sub">Por fazer</h3><ul class="task-list">${pend}</ul><h3 class="sub">Concluídas recentemente</h3><ul class="task-list done">${done}</ul></article>
      </div>
      <div class="staff-cols">
        <article class="panel"><p class="eyebrow">REGISTO</p><h2>Entradas e saídas</h2><h3 class="sub">Turnos</h3>${shifts}<h3 class="sub">Entradas no sistema</h3><ul class="login-list">${p.logins.length ? p.logins.map(l => `<li>${esc(GFT.full(l))} <small>${esc(GFT.when(l))}</small></li>`).join('') : '<li class="muted">Ainda sem registos.</li>'}</ul></article>
        <article class="panel"><div class="panel-heading"><div><p class="eyebrow">NOTAS DO LÍDER</p><h2>Observações</h2></div><button type="button" class="button small secondary" data-act="note">Nova observação</button></div><ul class="st-notes">${notes}</ul>
          <p class="muted fine">As observações privadas só tu as vês na aplicação. Se o funcionário pedir os seus dados pessoais (RGPD), podem ter de lhe ser entregues.</p></article>
      </div>
      <article class="panel" id="staff-chat-panel"><div class="panel-heading"><div><p class="eyebrow">COMUNICAÇÃO</p><h2>Mensagens com ${esc(e.name.split(' ')[0])}</h2></div></div><div id="staff-chat"></div></article></div>`;
  }
  async function openProfile(id, quiet = false) {
    profileId = id;
    $('#staff-list-view').hidden = true;
    const view = $('#staff-profile-view'); view.hidden = false;
    if (!quiet) view.innerHTML = '<p class="muted staff-loading">A carregar o perfil…</p>';
    try {
      const p = await api(`staff.php?id=${encodeURIComponent(id)}&` + qs({ period: f.period, from: f.from, to: f.to }));
      if (profileId !== id) return;
      GFT.setNow(p.now); profileData = p;
      const scrollY = quiet ? window.scrollY : 0;
      chat?.destroy(); chat = null;
      view.innerHTML = profileHtml(p);
      periodControl($('#profile-period'), f, () => openProfile(id, true));
      chat = GFChat.mount($('#staff-chat'), { peerId: id, leader: true, onChange: () => { window.GFNotif?.refresh(); } });
      if (quiet) window.scrollTo(0, scrollY); else { view.querySelector('.staff-back').focus({ preventScroll: true }); window.scrollTo({ top: 0 }); }
    } catch (e) { view.innerHTML = `<button type="button" class="button secondary staff-back" data-act="back">← Voltar</button><p class="panel staff-empty">${esc(e.message)}</p>`; }
  }
  function backToList() {
    chat?.destroy(); chat = null; profileId = null; profileData = null;
    $('#staff-profile-view').hidden = true; $('#staff-profile-view').innerHTML = '';
    $('#staff-list-view').hidden = false; loadList(); loadOverview();
  }

  /* ======================= ações ======================= */
  const refresh = () => openProfile(profileId, true);
  const actions = {
    back: backToList, bell: () => $('#notif-btn')?.click(),
    msg: () => { $('#staff-chat-panel')?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' }); chat?.focus(); },
    task: () => formModal({ title: 'Nova tarefa', intro: `Para ${profileData.employee.name}. Ele recebe um aviso.`, submit: 'Dar tarefa',
      fields: [{ name: 'title', label: 'O que tem de fazer', max: 200, required: true, focus: true }, { name: 'detail', label: 'Detalhes (opcional)', type: 'textarea', max: 1000 }, { name: 'due_date', label: 'Prazo (opcional)', type: 'date' }],
      onSubmit: d => post({ action: 'task_create', employee_id: profileId, ...d }) }).then(ok => ok && (msg('Tarefa atribuída.'), refresh())),
    goal: () => formModal({ title: 'Definir meta mensal', intro: 'Se já existir uma meta do mesmo tipo nesse mês, é atualizada e o funcionário é avisado.', submit: 'Guardar meta',
      fields: [{ name: 'kind', label: 'Tipo de meta', type: 'select', options: Object.entries(KINDS), focus: true }, { name: 'target', label: 'Objetivo (número, ou euros nas vendas)', type: 'number', step: '0.01', min: 0.01, required: true }, { name: 'month', label: 'Mês', type: 'month', value: (profileData.now || '').slice(0, 7), required: true }],
      onSubmit: d => post({ action: 'goal_set', employee_id: profileId, ...d }) }).then(ok => ok && (msg('Meta guardada.'), refresh())),
    note: () => formModal({ title: 'Nova observação', intro: `Sobre ${profileData.employee.name}.`, submit: 'Guardar',
      fields: [{ name: 'body', label: 'Observação', type: 'textarea', max: 1000, required: true, focus: true }, { name: 'shared', label: 'Partilhar com o funcionário (ele recebe um aviso: «comentário sobre o desempenho»)', type: 'checkbox' }],
      onSubmit: d => post({ action: 'note_add', employee_id: profileId, ...d }) }).then(ok => ok && (msg('Observação guardada.'), refresh())),
  };
  async function del(kind, id, title, text) {
    if (!await UX.confirm({ title, text, confirmLabel: 'Apagar', danger: true })) return;
    try { await post({ action: kind, id }); msg('Apagado.'); refresh(); } catch (e) { msg(e.message, true); }
  }
  document.addEventListener('click', e => {
    if (!root) return;
    const open = e.target.closest('.staff-open'), card = e.target.closest('.staff-card');
    if (open) return void openProfile(+open.dataset.id);
    if (card && !e.target.closest('button, a')) return void openProfile(+card.dataset.id);
    const unitBtn = e.target.closest('[data-unit]');
    if (unitBtn && profileData) { unit = unitBtn.dataset.unit; $('#profile-charts').innerHTML = chartsHtml(profileData); return; }
    const act = e.target.closest('#staff [data-act]');
    if (!act) return;
    const a = act.dataset.act, id = +act.dataset.id;
    if (actions[a]) actions[a]();
    else if (a === 'goal-del') del('goal_delete', id, 'Apagar esta meta?', 'O progresso deixa de contar.');
    else if (a === 'task-del') del('task_delete', id, 'Apagar esta tarefa?', 'Deixa de aparecer ao funcionário.');
    else if (a === 'note-del') del('note_delete', id, 'Apagar esta observação?', 'Não se pode desfazer.');
  });

  /* ======================= filtros e anúncio ======================= */
  const form = $('#staff-filters');
  if (form) {
    periodControl($('#staff-period'), f, loadList);
    form.addEventListener('input', e => { if (e.target.name && e.target.name in f) { f[e.target.name] = e.target.value; later(); } });
    form.addEventListener('submit', e => e.preventDefault());
    $('#staff-clear').addEventListener('click', () => {
      Object.assign(f, { period: 'month', from: '', to: '', q: '', job: '', status: '', prod_min: '', perf_min: '', sales_min: '', sort: 'name' });
      form.reset(); periodControl($('#staff-period'), f, loadList); loadList();
    });
    $('#staff-announce').addEventListener('click', () => formModal({ title: 'Anúncio para a equipa', intro: 'Todos os funcionários ativos recebem um aviso no sino.', submit: 'Enviar anúncio',
      fields: [{ name: 'title', label: 'Título', max: 100, required: true, focus: true }, { name: 'body', label: 'Mensagem (opcional)', type: 'textarea', max: 300 }],
      onSubmit: async d => { const r = await post({ action: 'announce', ...d }); msg(`Anúncio enviado a ${r.recipients} funcionário${r.recipients === 1 ? '' : 's'}.`); } }));
  }

  /* ======================= arranque ======================= */
  document.addEventListener('gf:section', ev => {
    if (ev.detail === 'staff') { if (profileId) openProfile(profileId, true); else loadList(); }
    else { if (chat && profileId) { chat.destroy(); chat = null; } if (ev.detail === 'overview') loadOverview(); }
  });
  window.GFStaff = {
    open(id) { if (typeof showSection === 'function') showSection('staff'); openProfile(+id); },
    onNotifications() { if (!document.hidden && (isVisible($('#overview')) || (isVisible(root) && !profileId))) loadOverview(); },
  };
  loadOverview();
})();
