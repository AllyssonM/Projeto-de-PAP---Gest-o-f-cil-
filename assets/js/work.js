/* =========================================================================
   ÁREA DO FUNCIONÁRIO: MENSAGENS E TAREFAS  (assets/js/work.js)
   -------------------------------------------------------------------------
   O que o funcionário vê na sua aba: o seu estado (e o botão da pausa), as suas tarefas (para concluir), as suas metas do mês com o progresso,
   os comentários que o líder partilhou, e a conversa com o líder. Mostra também, sem rodeios, O QUE O LÍDER PODE VER sobre ele.
   Dados de api/work.php. Todo o texto é escapado.
   ========================================================================= */
(function () {
  'use strict';
  const root = $('#work-root');
  const u = window.GF_USER || {};
  if (!root || u.isOwner) return;
  const eur = n => money.format(Number(n) || 0);
  let chat = null;

  function html(d) {
    const tasks = d.tasks_pending.length ? d.tasks_pending.map(t => {
      const late = t.due_date && t.due_date < d.now.slice(0, 10);
      return `<li class="task-row"><button type="button" class="task-check" data-act="done" data-id="${t.id}" aria-label="Concluir a tarefa: ${esc(t.title)}"><span aria-hidden="true"></span></button>
        <div><strong>${esc(t.title)}</strong>${t.detail ? `<small>${esc(t.detail)}</small>` : ''}</div><span class="due ${late ? 'late' : ''}">${t.due_date ? (late ? 'Atrasada · ' : 'Até ') + esc(GFT.day(t.due_date)) : 'Sem prazo'}</span></li>`;
    }).join('') : '<li class="muted">Não tens tarefas por fazer. 🎉</li>';
    const done = d.tasks_done.length ? d.tasks_done.map(t => `<li class="task-row done"><button type="button" class="task-check on" data-act="reopen" data-id="${t.id}" aria-label="Reabrir a tarefa: ${esc(t.title)}"><span aria-hidden="true">✓</span></button><div><strong>${esc(t.title)}</strong></div><small>${esc(GFT.when(t.completed_at))}</small></li>`).join('') : '<li class="muted">Ainda não concluíste nenhuma.</li>';
    const goals = d.goals.length ? d.goals.map(g => `<li><div class="goal-top"><strong>${esc(g.kind_label)}</strong><span class="muted">${esc(g.month.slice(5) + '/' + g.month.slice(0, 4))}</span>${g.achieved ? '<span class="chip-ok">Cumprida</span>' : ''}</div>
        ${GFT.meter(g.percent, g.kind_label)}<small>${g.kind === 'sales_value' ? eur(g.actual) + ' de ' + eur(g.target) : g.actual + ' de ' + g.target} · ${g.percent} %</small></li>`).join('') : '<li class="muted">O teu líder ainda não definiu metas para este mês.</li>';
    const comments = d.comments.length ? d.comments.map(c => `<li><p>${esc(c.body).replace(/\n/g, '<br>')}</p><small>${esc(GFT.when(c.created_at))}</small></li>`).join('') : '<li class="muted">Ainda sem comentários.</li>';
    const m = d.month, paused = d.state === 'pause';
    return `<div class="staff-profile work-grid">
      <article class="panel work-status"><div><p class="eyebrow">O TEU ESTADO</p><h2>${GFT.chip(d.state)}</h2><p class="muted">O teu líder vê este estado em tempo real.</p></div>
        <button type="button" class="button ${paused ? 'primary' : 'secondary'}" data-act="pause">${paused ? '▶ Voltar da pausa' : '⏸ Fazer uma pausa'}</button></article>
      <article class="panel"><div class="panel-heading"><div><p class="eyebrow">ESTE MÊS</p><h2>O teu desempenho</h2></div></div>
        <div class="staff-tiles"><div><span>Produtividade</span><strong>${m.productivity == null ? '—' : m.productivity + ' %'}</strong>${GFT.meter(m.productivity, 'Produtividade')}</div><div><span>Desempenho geral</span><strong>${m.performance == null ? '—' : m.performance + ' %'}</strong>${GFT.meter(m.performance, 'Desempenho')}</div>
          <div><span>Vendas</span><strong>${m.sales_count}</strong><small>${eur(m.sales_value)}</small></div><div><span>Tarefas concluídas</span><strong>${m.tasks_done}</strong></div><div><span>Horas de trabalho</span><strong>${m.hours} h</strong></div></div></article>
      <div class="staff-cols">
        <article class="panel"><p class="eyebrow">TRABALHO</p><h2>As tuas tarefas</h2><ul class="task-list">${tasks}</ul><h3 class="sub">Concluídas recentemente</h3><ul class="task-list done">${done}</ul></article>
        <article class="panel"><p class="eyebrow">OBJETIVOS</p><h2>As tuas metas</h2><ul class="goal-list">${goals}</ul><h3 class="sub">Comentários do teu líder</h3><ul class="st-notes">${comments}</ul></article>
      </div>
      <article class="panel" id="work-chat-panel"><p class="eyebrow">COMUNICAÇÃO</p><h2>Mensagens com o teu líder</h2><div id="work-chat"></div></article>
      <article class="panel work-transparency"><p class="eyebrow">TRANSPARÊNCIA</p><h2>O que o teu líder pode ver</h2>
        <p>Para trabalhares com confiança, estas são as únicas coisas que o Lumina mostra ao teu líder sobre ti: o <b>estado</b> (online, ausente, em pausa ou offline), as <b>horas de entrada e saída</b> dos turnos, as <b>vendas que registas</b>, as <b>tarefas e metas</b> que ele te dá, a <b>hora a que entras no sistema</b> e as <b>mensagens</b> que trocam. O Lumina não vê o teu ecrã, não grava o que escreves e não segue a tua localização.</p></article></div>`;
  }

  async function load() {
    try {
      const d = await api('work.php'); GFT.setNow(d.now);
      chat?.destroy(); chat = null;
      const y = window.scrollY;
      root.innerHTML = html(d);
      chat = GFChat.mount($('#work-chat'), { leader: false, onChange: () => window.GFNotif?.refresh() });
      window.scrollTo(0, y);
    } catch (e) { root.innerHTML = `<p class="panel staff-empty">${esc(e.message)}</p>`; }
  }
  root.addEventListener('click', async e => {
    const b = e.target.closest('[data-act]'); if (!b) return;
    const act = b.dataset.act;
    try {
      if (act === 'done' || act === 'reopen') { await api('work.php', { method: 'POST', body: JSON.stringify({ action: act === 'done' ? 'task_done' : 'task_reopen', id: +b.dataset.id, csrf }) }); msg(act === 'done' ? 'Tarefa concluída. 👏' : 'Tarefa reaberta.'); load(); }
      if (act === 'pause') {
        const pausing = /Fazer/.test(b.textContent);
        await api('work.php', { method: 'POST', body: JSON.stringify({ action: 'presence', state: pausing ? 'pause' : 'auto', csrf }) }); msg(pausing ? 'Estás em pausa.' : 'Bem-vindo de volta!'); load();
      }
    } catch (x) { msg(x.message, true); }
  });
  document.addEventListener('gf:section', ev => {
    if (ev.detail === 'work') { load(); } else if (chat) { chat.destroy(); chat = null; }
  });
  // o funcionário que abre o painel já com esta aba (ou chega por um aviso)
  if (!$('#work').classList.contains('hidden')) load();
})();
