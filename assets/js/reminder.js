/* =========================================================
   Aviso "Reunião se aproximando" + cronómetro.
   O servidor decide que reuniões mostrar (api/calendar.php?upcoming=1)
   e envia a sua hora; o cronómetro usa essa hora, por isso não
   depende do relógio nem do fuso do computador.
   ========================================================= */
(() => {
  const modal = $('#reminder-modal');
  if (!modal) return;

  const card = $('#reminder-card');
  let offset = 0;            // hora do servidor - hora do computador (ms)
  let timer = null;
  let current = null;
  let dismissed = false;
  let lastFocus = null;
  let lastSpokenMinute = null;

  const serverNow = () => Date.now() + offset;

  function fill(ev, others) {
    $('#reminder-title').textContent = 'Reunião se aproximando';
    $('#reminder-event-title').textContent = ev.title;
    const [y, m, d] = ev.event_date.split('-').map(Number);
    $('#reminder-date').textContent = new Date(y, m - 1, d).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { weekday: 'long', day: 'numeric', month: 'long' });
    $('#reminder-time').textContent = hhmm(ev.start_time);
    $('#reminder-end').textContent = hhmm(ev.end_time);
    $('#reminder-end-row').hidden = !ev.end_time;
    $('#reminder-loc').textContent = ev.location || '';
    $('#reminder-loc-row').hidden = !ev.location;
    $('#reminder-desc').textContent = ev.description || '';
    $('#reminder-desc').hidden = !ev.description;
    const more = $('#reminder-more');
    more.hidden = !others.length;
    more.textContent = others.length ? 'Também hoje: ' + others.map(o => `${hhmm(o.start_time)} ${o.title}`).join(' · ') : '';
    card.classList.remove('soon', 'now');
  }

  function tick() {
    if (!current) return;
    const left = Math.max(0, current.starts_at_ms - serverNow());
    const total = Math.floor(left / 1000);
    const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60), s = total % 60;
    $('#rc-h').textContent = pad(h);
    $('#rc-m').textContent = pad(m);
    $('#rc-s').textContent = pad(s);
    card.classList.toggle('soon', left > 0 && left < 10 * 60 * 1000);

    // leitores de ecrã: só anuncia uma vez por minuto
    const minute = Math.ceil(left / 60000);
    if (minute !== lastSpokenMinute) {
      lastSpokenMinute = minute;
      $('#reminder-live').textContent = left > 0 ? `Faltam ${h} horas e ${m} minutos para ${current.title}.` : '';
    }

    if (left <= 0) {
      clearInterval(timer); timer = null;
      card.classList.add('now');
      card.classList.remove('soon');
      $('#reminder-title').textContent = 'A reunião está começando agora!';
      $('#reminder-live').textContent = `A reunião ${current.title} está a começar agora.`;
    }
  }

  function open(ev, others) {
    current = ev;
    lastSpokenMinute = null;
    fill(ev, others);
    tick();
    clearInterval(timer);
    timer = setInterval(tick, 1000);
    if (modal.classList.contains('hidden')) {
      lastFocus = document.activeElement;
      modal.classList.remove('hidden');
      requestAnimationFrame(() => modal.classList.add('show'));
      $('#reminder-view').focus();
    }
  }

  function close(remember = true) {
    clearInterval(timer); timer = null;
    modal.classList.remove('show');
    modal.classList.add('hidden');
    lastFocus?.focus?.();
    if (remember && !dismissed) {
      dismissed = true;
      // só volta a aparecer numa nova sessão
      api('calendar.php?dismiss=1', { method: 'POST', body: JSON.stringify({ csrf }) }).catch(() => {});
    }
  }

  async function check() {
    if (dismissed) return;
    if (!(window.GF_USER?.permissions || []).includes('calendar')) return;   // sem acesso ao calendário nem pede (evita um 403 inútil)
    try {
      const d = await api('calendar.php?upcoming=1');
      offset = Number(d.server_now_ms) - Date.now();
      dismissed = !!d.dismissed;
      const items = (d.items || []).filter(ev => ev.starts_at_ms > serverNow());
      if (dismissed || !items.length) { if (!modal.classList.contains('hidden')) close(false); return; }
      open(items[0], items.slice(1));
    } catch { /* sem tabela ou sem ligação: o painel continua a funcionar */ }
  }

  $('#reminder-x').onclick = () => close();
  $('#reminder-dismiss').onclick = () => close();
  $('#reminder-view').onclick = () => {
    const ev = current;
    close();
    if (ev) window.gfCalendar?.goTo(ev.event_date, ev.id);
  };
  modal.addEventListener('click', e => { if (e.target === modal) close(); });
  document.addEventListener('keydown', e => {
    if (modal.classList.contains('hidden')) return;
    if (e.key === 'Escape') close();
    if (e.key === 'Tab') {   // manter o foco dentro do aviso
      const items = [...card.querySelectorAll('button')];
      const first = items[0], last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  window.gfReminder = { refresh: check };
  check();
})();
