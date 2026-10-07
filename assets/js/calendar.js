/* =========================================================
   Calendário: agenda mensal do utilizador (api/calendar.php)
   A semana começa à segunda-feira.
   ========================================================= */
(() => {
  const grid = $('#cal-grid');
  if (!grid) return;

  const form = $('#calendar-form');
  const note = $('#cal-message');
  const iso = d => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

  const today = new Date();
  let view = new Date(today.getFullYear(), today.getMonth(), 1);
  let selected = iso(today);
  let events = [];          // eventos do mês visível
  let loaded = false;
  let highlightId = null;

  function say(text, error = false) {
    note.textContent = text;
    note.classList.toggle('error', error);
    note.classList.toggle('hidden', !text);
  }

  async function load() {
    try {
      const d = await api(`calendar.php?month=${view.getFullYear()}-${pad(view.getMonth() + 1)}`);
      events = d.items || [];
      loaded = true;
      render();
    } catch (e) { say(e.message, true); render(); }
  }

  function render() {
    const title = view.toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { month: 'long', year: 'numeric' });
    $('#cal-title').textContent = title.charAt(0).toUpperCase() + title.slice(1);

    const first = new Date(view.getFullYear(), view.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7;          // segunda = 0
    const start = new Date(first); start.setDate(1 - offset);
    const todayIso = iso(new Date());
    const byDay = {};
    events.forEach(ev => { (byDay[ev.event_date] ||= []).push(ev); });

    let html = '';
    for (let i = 0; i < 42; i++) {
      const d = new Date(start); d.setDate(start.getDate() + i);
      const key = iso(d), list = byDay[key] || [];
      const cls = ['cal-day',
        d.getMonth() !== view.getMonth() && 'other',
        key === todayIso && 'today',
        key === selected && 'selected',
        list.length && 'has-events'].filter(Boolean).join(' ');
      const label = d.toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'long', day: 'numeric', month: 'long' }) + (list.length ? `, ${list.length} evento(s)` : '');
      html += `<button type="button" class="${cls}" data-day="${key}" aria-label="${esc(label)}" aria-pressed="${key === selected}">
        <span class="cal-num">${d.getDate()}</span>
        ${list.length ? `<span class="cal-dots">${list.slice(0, 3).map(ev => `<i title="${esc(hhmm(ev.start_time) + ' ' + ev.title)}"></i>`).join('')}${list.length > 3 ? '<em>+' + (list.length - 3) + '</em>' : ''}</span>` : ''}
        ${list[0] ? `<small class="cal-first">${esc(hhmm(list[0].start_time) + ' ' + list[0].title)}</small>` : ''}
      </button>`;
    }
    grid.innerHTML = html;
    grid.querySelectorAll('[data-day]').forEach(b => b.onclick = () => selectDay(b.dataset.day));
    renderDay();
  }

  function renderDay() {
    const [y, m, d] = selected.split('-').map(Number);
    const title = new Date(y, m - 1, d).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'long', day: 'numeric', month: 'long' });
    $('#cal-day-title').textContent = title.charAt(0).toUpperCase() + title.slice(1);
    const list = events.filter(ev => ev.event_date === selected);
    const box = $('#cal-day-events');
    box.innerHTML = list.length ? list.map(ev => `<div class="cal-event${Number(ev.id) === highlightId ? ' highlight' : ''}" data-event="${ev.id}">
        <div class="cal-event-time">${hhmm(ev.start_time)}<span>${hhmm(ev.end_time)}</span></div>
        <div class="cal-event-body"><strong>${esc(ev.title)}</strong>${ev.source === 'google' ? ' <span class="g-badge" title="Evento do Google Calendar">Google</span>' : ev.google_event_id ? ' <span class="g-badge sent" title="Também está no Google Calendar">no Google</span>' : ''}
          ${ev.location ? `<small>📍 ${esc(ev.location)}</small>` : ''}
          ${ev.description ? `<p>${esc(ev.description)}</p>` : ''}</div>
        <button type="button" class="delete-button" data-del-event="${ev.id}" aria-label="Eliminar evento" title="Eliminar">×</button>
      </div>`).join('') : '<p class="muted">Sem eventos neste dia.</p>';
    box.querySelectorAll('[data-del-event]').forEach(b => b.onclick = () => remove(b.dataset.delEvent));
    const hl = box.querySelector('.highlight');
    if (hl) hl.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  function selectDay(key) {
    selected = key;
    highlightId = null;
    form.elements.event_date.value = key;
    const [y, m] = key.split('-').map(Number);
    if (y !== view.getFullYear() || m - 1 !== view.getMonth()) { view = new Date(y, m - 1, 1); load(); }
    else render();
  }

  async function remove(id) {
    if (!await UX.confirm({ title: 'Eliminar este evento?', text: 'Esta ação não se pode desfazer.', confirmLabel: 'Eliminar', danger: true })) return;
    try {
      const d = await api(`calendar.php?id=${encodeURIComponent(id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) });
      say(d.warning || 'Evento eliminado.', !!d.warning);
      await load();
      window.gfReminder?.refresh();
    } catch (e) { say(e.message, true); }
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const data = formData(form);
    if (data.end_time <= data.start_time) return say('A hora de fim tem de ser depois da hora de início.', true);
    const btn = $('#cal-save');
    btn.disabled = true;
    try {
      const r = await api('calendar.php', { method: 'POST', body: JSON.stringify({ ...data, csrf }) });
      say(r.warning || 'Evento guardado.', !!r.warning);
      form.elements.title.value = ''; form.elements.location.value = ''; form.elements.description.value = '';
      selected = data.event_date;
      highlightId = Number(r.item?.id) || null;
      const [y, m] = selected.split('-').map(Number);
      view = new Date(y, m - 1, 1);
      await load();
      window.gfReminder?.refresh();
    } catch (x) { say(x.message, true); }
    finally { btn.disabled = false; }
  });

  $('#cal-prev').onclick = () => { view = new Date(view.getFullYear(), view.getMonth() - 1, 1); load(); };
  $('#cal-next').onclick = () => { view = new Date(view.getFullYear(), view.getMonth() + 1, 1); load(); };
  $('#cal-today').onclick = () => { const t = new Date(); view = new Date(t.getFullYear(), t.getMonth(), 1); selected = iso(t); form.elements.event_date.value = selected; load(); };

  form.elements.event_date.value = selected;
  document.addEventListener('gf:section', e => { if (e.detail === 'calendar' && !loaded) load(); });

  /* usado pelo aviso de reunião: abre o dia e destaca o evento */
  window.gfCalendar = {
    reload: () => load(),                                  // usado pela sincronização do Google (google-cal.js)
    async goTo(day, id) {
      selected = day;
      form.elements.event_date.value = day;
      const [y, m] = day.split('-').map(Number);
      view = new Date(y, m - 1, 1);
      showSection('calendar');
      highlightId = Number(id) || null;
      await load();
    }
  };
})();
