/* =========================================================================
   TEMPO ATIVO / REGISTO DE TRABALHO  (assets/js/time.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  a aba "Tempo ativo".
     1. O MEU TURNO  - registar entrada, pausar, retomar e registar saída. O contador
                       corre em tempo real SEM recarregar a página.
     2. TOTAIS       - horas de hoje, da semana e do mês (também a contar em direto).
     3. HISTÓRICO    - filtrar por datas e (gestor) por funcionário; total de horas;
                       exportar para CSV (abre no Excel).
     4. CORREÇÕES    - só o administrador e os gerentes: corrigir entrada/saída ou
                       registar um turno em falta. Pede SEMPRE um motivo, e fica
                       registado quem alterou e quando.
   O CONTADOR NÃO DEPENDE DO RELÓGIO DO PC: o servidor diz que horas são (server_ts)
   e calculamos a diferença; assim, mesmo com o relógio do computador desacertado,
   a hora de entrada e o tempo trabalhado batem certo.
   ESTADOS VISUAIS: "Em turno" (turquesa), "Em pausa" (âmbar), "Fora de turno" (cinza).
   DADOS: api/time.php. Privacidade: um funcionário só vê os seus registos.
   ========================================================================= */
(function () {
  'use strict';
  const root = document.querySelector('#time');
  if (!root) return;
  const $t = s => root.querySelector(s);
  const hms = s => { s = Math.max(0, Math.floor(s)); return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(n => String(n).padStart(2, '0')).join(':'); };
  const hm = s => { s = Math.max(0, Math.floor(s)); return `${Math.floor(s / 3600)}h${String(Math.floor(s % 3600 / 60)).padStart(2, '0')}`; };
  const dmy = v => String(v).slice(0, 10).split('-').reverse().join('/');
  const time = v => String(v).slice(11, 16);
  const STATES = { off: 'Fora de turno', on: 'Em turno', paused: 'Em pausa' };

  let st = null, offsetMs = 0, fetchedAt = 0, ticker = 0, isManager = false, hist = null;
  const filters = { from: '', to: '', employee: '' };

  /* ------------------------------------------------------------ o meu turno */
  /** O "agora" do servidor, calculado a partir da diferença medida (não do relógio do PC). */
  const serverNow = () => (Date.now() + offsetMs) / 1000;

  async function loadStatus(announce) {
    try {
      st = await api('time.php?action=status');
      offsetMs = st.server_ts * 1000 - Date.now(); fetchedAt = st.server_ts;
      isManager = !!st.is_manager;
      renderClock(announce); startTicker();
    } catch (e) {
      $t('#time-counter').textContent = '--:--:--';
      $t('#time-since').innerHTML = `Não foi possível carregar o turno. <button type="button" class="button secondary small" id="time-retry">Tentar novamente</button>`;
      $t('#time-retry').onclick = () => loadStatus();
    }
  }

  function renderClock(announce) {
    const chip = $t('#time-state');
    chip.dataset.state = st.state;
    chip.querySelector('span').textContent = STATES[st.state];
    $t('#time-in').hidden = st.state !== 'off';
    $t('#time-pause').hidden = st.state !== 'on';
    $t('#time-resume').hidden = st.state !== 'paused';
    $t('#time-out').hidden = st.state === 'off';
    $t('#time-since').textContent = st.shift ? `Entrada às ${time(st.shift.started_at)}` : 'Ainda não registaste a entrada hoje.';
    tick();
    if (announce) $t('#time-live').textContent = `${STATES[st.state]}.`;          // anunciado aos leitores de ecrã (o contador não, para não incomodar)
  }

  /** Atualiza o contador e os totais a cada segundo, sem pedir nada ao servidor. */
  function tick() {
    if (!st) return;
    const dt = serverNow() - fetchedAt;                         // segundos desde que o servidor respondeu
    const running = st.state === 'on' ? dt : 0;                  // só conta enquanto o turno está ativo (em pausa não)
    $t('#time-counter').textContent = hms((st.shift?.worked_seconds || 0) + running);
    $t('#time-today').textContent = hm(st.today_seconds + running);
    $t('#time-week').textContent = hm(st.week_seconds + running);
    $t('#time-month').textContent = hm(st.month_seconds + running);
    const p = $t('#time-pause-for');
    if (st.state === 'paused' && st.shift?.paused_since_ts) { p.hidden = false; p.textContent = `Em pausa há ${hms(serverNow() - st.shift.paused_since_ts)}`; } else p.hidden = true;
  }
  function startTicker() {
    clearInterval(ticker);
    ticker = setInterval(() => { if (!document.hidden && !root.classList.contains('hidden')) tick(); }, 1000);     // só trabalha com o separador visível
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && !root.classList.contains('hidden')) loadStatus(); });    // ao voltar ao separador, volta a sincronizar

  async function act(action, okText, btn) {
    if (btn) btn.disabled = true;
    try {
      const d = await api('time.php', { method: 'POST', body: JSON.stringify({ action, csrf }) });
      st = d; offsetMs = d.server_ts * 1000 - Date.now(); fetchedAt = d.server_ts;
      renderClock(true); msg(okText); loadHistory();
    } catch (e) { msg(e.message, true); loadStatus(); }
    finally { if (btn) btn.disabled = false; }
  }
  $t('#time-in').onclick = e => act('clock_in', 'Entrada registada. Bom trabalho!', e.currentTarget);
  $t('#time-pause').onclick = e => act('pause', 'Pausa iniciada.', e.currentTarget);
  $t('#time-resume').onclick = e => act('resume', 'Turno retomado.', e.currentTarget);
  $t('#time-out').onclick = async () => {
    if (!await UX.confirm({ title: 'Registar a saída?', text: 'O turno termina agora.', confirmLabel: 'Registar saída' })) return;
    act('clock_out', 'Saída registada.', $t('#time-out'));
  };

  /* ------------------------------------------------------------ histórico */
  function initFilters() {
    const now = new Date(), pad = n => String(n).padStart(2, '0');
    filters.from = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-01`;
    filters.to = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    $t('#time-from').value = filters.from; $t('#time-to').value = filters.to;
  }
  const query = () => `from=${filters.from}&to=${filters.to}${filters.employee ? '&employee=' + filters.employee : ''}`;

  async function loadHistory() {
    const box = $t('#time-table');
    box.innerHTML = '<div class="skeleton-stack"><div class="skeleton" style="height:40px"></div><div class="skeleton" style="height:40px"></div><div class="skeleton" style="height:40px"></div></div>';
    try { hist = await api(`time.php?action=list&${query()}`); isManager = hist.is_manager; renderHistory(); }
    catch (e) {
      box.innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível carregar o histórico</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="time-hist-retry">Tentar novamente</button></div>`;
      $t('#time-hist-retry').onclick = loadHistory;
    }
  }

  function renderHistory() {
    // controlos só para gestores
    root.querySelectorAll('[data-manager]').forEach(el => { el.hidden = !isManager; });
    const sel = $t('#time-employee');
    if (isManager && sel.options.length <= 1) sel.innerHTML = '<option value="">Todos os funcionários</option>' + hist.employees.map(e => `<option value="${e.id}">${esc(e.name)}</option>`).join('');
    $t('#time-export').href = `api/time.php?action=export&${query()}`;

    // resumo por funcionário
    $t('#time-totals').innerHTML = hist.totals.length
      ? `<div class="time-chip total"><span>Total do período</span><b>${hm(hist.total_seconds)}</b></div>` + hist.totals.map(t => `<div class="time-chip"><span>${esc(t.name)}</span><b>${hm(t.seconds)}</b><small>${t.days} dia(s)</small></div>`).join('')
      : '';

    const box = $t('#time-table');
    if (!hist.items.length) {
      box.innerHTML = '<div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">⏱️</span><b>Sem registos neste período</b><span>Regista a entrada para começar, ou muda as datas do filtro.</span></div>';
      return;
    }
    box.innerHTML = `<div class="time-scroll"><table class="time-table"><thead><tr>${isManager ? '<th>Funcionário</th>' : ''}<th>Data</th><th>Entrada</th><th>Saída</th><th>Pausas</th><th>Total</th><th>Origem</th>${isManager ? '<th><span class="sr-only">Ações</span></th>' : ''}</tr></thead><tbody>
      ${hist.items.map(s => `<tr>${isManager ? `<td>${esc(s.employee_name)}</td>` : ''}<td>${dmy(s.started_at)}</td><td>${time(s.started_at)}</td>
        <td>${s.open ? '<span class="time-live">em curso</span>' : time(s.ended_at)}</td><td>${Math.round(s.pause_seconds / 60)} min</td><td><b>${hm(s.worked_seconds)}</b></td>
        <td>${s.source === 'manual' ? `<span class="time-badge manual" title="${esc(`Corrigido por ${s.edited_by_name || '—'} em ${dmy(s.edited_at || s.started_at)}: ${s.edit_reason || ''}`)}">Corrigido</span>` : '<span class="time-badge">Relógio</span>'}</td>
        ${isManager ? `<td>${s.open ? '' : `<button type="button" class="button secondary small" data-edit="${s.id}">Corrigir</button>`}</td>` : ''}</tr>`).join('')}</tbody></table></div>`;
    box.querySelectorAll('[data-edit]').forEach(b => b.onclick = () => editShift(hist.items.find(s => s.id === +b.dataset.edit)));
  }

  ['#time-from', '#time-to'].forEach(sel => $t(sel).addEventListener('change', e => {
    filters[sel === '#time-from' ? 'from' : 'to'] = e.target.value;
    if (filters.from && filters.to) loadHistory();
  }));
  $t('#time-employee').addEventListener('change', e => { filters.employee = e.target.value; loadHistory(); });

  /* ------------------------------------------------------------ correções (gestor) */
  const local = v => String(v).slice(0, 16).replace(' ', 'T');            // "2026-10-02 08:00:00" -> "2026-10-02T08:00" (datetime-local)

  function shiftModal({ title, intro, shift, employees, danger }) {
    const { root: m, close } = UX.modal(`<h2>${esc(title)}</h2><p class="muted">${esc(intro)}</p>
      ${employees ? `<label class="ux-confirm-input">Funcionário<select name="employee">${employees.map(e => `<option value="${e.id}">${esc(e.name)}</option>`).join('')}</select></label>` : ''}
      <label class="ux-confirm-input">Entrada<input name="start" type="datetime-local" value="${shift ? local(shift.started_at) : ''}"></label>
      <label class="ux-confirm-input">Saída<input name="end" type="datetime-local" value="${shift ? local(shift.ended_at) : ''}"></label>
      <label class="ux-confirm-input">Motivo da correção (obrigatório)<input name="reason" maxlength="255" placeholder="Ex.: Esqueceu-se de marcar a saída"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions">${danger ? '<button type="button" class="button danger" data-del>Eliminar registo</button>' : ''}<button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button primary" data-yes>Guardar</button></div>`);
    const err = t => { const b = m.querySelector('.ap-form-error'); b.textContent = t; b.hidden = !t; };
    m.querySelector('[data-no]').onclick = () => close(false);
    return { m, close, err };
  }

  function editShift(s) {
    const { m, close, err } = shiftModal({ title: 'Corrigir horário', intro: `${s.employee_name}, ${dmy(s.started_at)}. A correção fica registada com o teu nome.`, shift: s, danger: window.GF_USER?.isOwner });
    m.querySelector('[data-yes]').onclick = async e => {
      const body = { action: 'edit', id: s.id, started_at: m.querySelector('[name=start]').value, ended_at: m.querySelector('[name=end]').value, reason: m.querySelector('[name=reason]').value, csrf };
      if (!body.reason.trim()) return err('Indica o motivo da correção.');
      e.target.disabled = true;
      try { await api('time.php', { method: 'POST', body: JSON.stringify(body) }); close(true); msg('Horário corrigido.'); loadHistory(); loadStatus(); }
      catch (x) { err(x.message); e.target.disabled = false; }
    };
    const del = m.querySelector('[data-del]');
    if (del) del.onclick = async () => {
      if (!await UX.confirm({ title: 'Eliminar este registo?', text: 'Esta ação não se pode desfazer (fica no registo de auditoria).', confirmLabel: 'Eliminar', danger: true })) return;
      try { await api('time.php', { method: 'POST', body: JSON.stringify({ action: 'delete', id: s.id, csrf }) }); close(true); msg('Registo eliminado.'); loadHistory(); } catch (x) { err(x.message); }
    };
  }

  $t('#time-manual').onclick = () => {
    const { m, close, err } = shiftModal({ title: 'Registar turno em falta', intro: 'Para um turno que não foi marcado no relógio. Fica registado com o teu nome.', employees: hist.employees });
    m.querySelector('[data-yes]').onclick = async e => {
      const body = { action: 'create_manual', employee_id: +m.querySelector('[name=employee]').value, started_at: m.querySelector('[name=start]').value, ended_at: m.querySelector('[name=end]').value, reason: m.querySelector('[name=reason]').value, csrf };
      if (!body.reason.trim()) return err('Indica o motivo.');
      e.target.disabled = true;
      try { await api('time.php', { method: 'POST', body: JSON.stringify(body) }); close(true); msg('Turno registado.'); loadHistory(); }
      catch (x) { err(x.message); e.target.disabled = false; }
    };
  };

  /* abrir a aba: carrega o estado e o histórico na primeira vez e sincroniza nas seguintes */
  let first = true;
  document.addEventListener('gf:section', e => {
    if (e.detail !== 'time') return;
    if (first) { first = false; initFilters(); loadHistory(); }
    loadStatus();
  });
})();
