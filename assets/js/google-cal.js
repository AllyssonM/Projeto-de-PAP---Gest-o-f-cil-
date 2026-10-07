/* =========================================================================
   LIGAÇÃO AO GOOGLE CALENDAR (ecrã)  (assets/js/google-cal.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  o cartão "Sincronizar com o Google" na aba Calendário.
     - Mostra o estado: "Não configurado" / "Não ligado" / "Ligado".
     - EXPLICA que permissões pede (só 2) e que nunca vê a palavra-passe da Google.
     - Ligar: leva o utilizador ao site da Google (OAuth); ao voltar, mostra o resultado.
     - Escolher quais calendários sincronizar; modo manual ou automático.
     - Sincronizar agora; estado da última sincronização; erros claros + "Tentar novamente".
     - Desligar (revoga o acesso na Google).
     - Mostra a opção "Enviar também para o Google Calendar" no formulário de eventos.
   NÃO SIMULA: sem credenciais (config/google.php) o cartão diz "não configurado" e
   explica o que falta, em vez de fingir uma ligação.
   AUTOMÁTICO: sincroniza quando se abre o calendário (no máximo a cada 5 minutos). Não há
   sincronização em segundo plano com o navegador fechado.
   DADOS: api/google.php. Os tokens nunca chegam ao navegador.
   ========================================================================= */
(function () {
  'use strict';
  const panel = document.querySelector('#google-panel');
  if (!panel) return;
  const $g = s => panel.querySelector(s);
  const fmt = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString((window.LUMINA_LOCALE || 'pt-PT'), { dateStyle: 'short', timeStyle: 'short' }) : 'Nunca';
  const AUTO_MINUTES = 5;
  let st = null, busy = false;

  async function load() {
    try { st = await api('google.php?action=status'); render(); }
    catch (e) { $g('#g-chip').textContent = 'Erro'; $g('#g-body').innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível ver o estado</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="g-retry">Tentar novamente</button></div>`; $g('#g-retry').onclick = load; }
  }

  function render() {
    const chip = $g('#g-chip'), body = $g('#g-body');
    document.querySelector('#cal-google-row').hidden = !(st.configured && st.connected);          // opção "enviar também" só se está ligado
    const about = `<p class="muted g-about">Vais ser levado ao site da Google para autorizares. Pedimos só:</p><ul class="g-scopes">${st.scopes.map(s => `<li>${esc(s)}</li>`).join('')}</ul><p class="muted g-about">🔒 ${esc(st.privacy)}</p>`;
    if (!st.configured) {
      chip.textContent = 'Não configurado'; chip.classList.remove('on');
      body.innerHTML = `<p class="muted">A ligação ao Google Calendar ainda não está configurada neste sistema.</p>${(window.GF_USER?.isOwner) ? `<p class="muted g-about">Quem instala o sistema precisa de criar credenciais OAuth no Google Cloud e preenchê-las em <code>config/google.php</code> (as instruções estão nesse ficheiro). Endereço de redirecionamento a registar:</p><code class="ap-secret g-uri">${esc(st.redirect_uri)}</code>` : '<p class="muted g-about">Pede ao administrador para o configurar.</p>'}`;
      return;
    }
    if (!st.connected) {
      chip.textContent = 'Não ligado'; chip.classList.remove('on');
      body.innerHTML = `${st.last_error ? `<p class="ap-form-error" role="alert">${esc(st.last_error)}</p>` : ''}${about}<div class="form-actions"><button type="button" class="button primary" id="g-connect">Ligar ao Google Calendar</button></div>`;
      $g('#g-connect').onclick = connect;
      return;
    }
    chip.textContent = 'Google Calendar ligado'; chip.classList.add('on');
    body.innerHTML = `${st.last_error ? `<p class="ap-form-error" role="alert">${esc(st.last_error)} <button type="button" class="button secondary small" id="g-reconnect">Ligar outra vez</button></p>` : ''}
      <fieldset class="g-fs"><legend>Calendários a sincronizar</legend><div id="g-cals" class="g-cals"><div class="skeleton" style="height:22px"></div></div></fieldset>
      <fieldset class="g-fs"><legend>Sincronização</legend>
        <label class="ap-check"><input type="radio" name="g-mode" value="manual" ${st.sync_mode === 'manual' ? 'checked' : ''}> Manual (só quando carrego no botão)</label>
        <label class="ap-check"><input type="radio" name="g-mode" value="auto" ${st.sync_mode === 'auto' ? 'checked' : ''}> Automática (ao abrir o calendário, no máx. a cada ${AUTO_MINUTES} min)</label></fieldset>
      <p class="muted" id="g-last">Última sincronização: <b>${esc(fmt(st.last_sync_at))}</b></p>
      <div class="form-actions"><button type="button" class="button primary" id="g-sync">Sincronizar agora</button><button type="button" class="button danger" id="g-disconnect">Desligar Google Calendar</button></div>`;
    $g('#g-sync').onclick = () => sync(true);
    $g('#g-disconnect').onclick = disconnect;
    $g('#g-reconnect')?.addEventListener('click', connect);
    panel.querySelectorAll('[name=g-mode]').forEach(r => r.onchange = async () => { try { await api('google.php', { method: 'POST', body: JSON.stringify({ action: 'set_mode', mode: r.value, csrf }) }); st.sync_mode = r.value; msg(r.value === 'auto' ? 'Sincronização automática ativada.' : 'Sincronização manual.'); } catch (e) { msg(e.message, true); } });
    loadCalendars();
  }

  async function loadCalendars() {
    const box = $g('#g-cals');
    try {
      const d = await api('google.php?action=calendars');
      box.innerHTML = d.items.map(c => `<label class="ap-check"><input type="checkbox" value="${esc(c.id)}" ${c.selected ? 'checked' : ''}> ${esc(c.name)}${c.primary ? ' <small>(principal)</small>' : ''}${c.can_write ? '' : ' <small>(só leitura)</small>'}</label>`).join('') || '<p class="muted">Sem calendários.</p>';
      box.querySelectorAll('input').forEach(i => i.onchange = async () => {
        const ids = [...box.querySelectorAll('input:checked')].map(x => x.value);
        if (!ids.length) { i.checked = true; return msg('Escolhe pelo menos um calendário.', true); }
        try { await api('google.php', { method: 'POST', body: JSON.stringify({ action: 'set_calendars', ids, csrf }) }); msg('Calendários atualizados. Sincroniza para ver as alterações.'); } catch (e) { msg(e.message, true); }
      });
    } catch (e) { box.innerHTML = `<p class="ap-form-error">${esc(e.message)} <button type="button" class="button secondary small" id="g-cal-retry">Tentar novamente</button></p>`; $g('#g-cal-retry').onclick = loadCalendars; }
  }

  async function connect() {
    try { const d = await api('google.php', { method: 'POST', body: JSON.stringify({ action: 'connect', csrf }) }); location.href = d.url; }      // vai ao site da Google
    catch (e) { msg(e.message, true); }
  }

  /** Traz os eventos do Google. "manual" = mostra o resultado; automático = em silêncio. */
  async function sync(manual) {
    if (busy) return; busy = true;
    const btn = $g('#g-sync'); if (btn) { btn.disabled = true; btn.textContent = 'A sincronizar…'; }
    try {
      const d = await api('google.php', { method: 'POST', body: JSON.stringify({ action: 'sync', csrf }) });
      st.last_sync_at = d.last_sync_at; st.last_error = null;
      if (manual) msg(`Sincronizado: ${d.new} novo(s), ${d.updated} atualizado(s), ${d.removed} removido(s).`);
      window.gfCalendar?.reload?.();
      const last = $g('#g-last b'); if (last) last.textContent = fmt(d.last_sync_at);
    } catch (e) { msg(e.message, true); await load(); }
    finally { busy = false; const b = $g('#g-sync'); if (b) { b.disabled = false; b.textContent = 'Sincronizar agora'; } }
  }

  async function disconnect() {
    if (!await UX.confirm({ title: 'Desligar o Google Calendar?', text: 'Revogamos o acesso na Google e removemos da agenda os eventos que vieram de lá. Os teus eventos do sistema ficam.', confirmLabel: 'Desligar', danger: true })) return;
    try { await api('google.php', { method: 'POST', body: JSON.stringify({ action: 'disconnect', csrf }) }); msg('Google Calendar desligado.'); window.gfCalendar?.reload?.(); await load(); }
    catch (e) { msg(e.message, true); }
  }

  // resultado do regresso da Google (?google=connected|denied|state|error)
  const back = new URLSearchParams(location.search).get('google');
  const BACK = { connected: ['success', 'Google Calendar ligado com sucesso.'], denied: ['warn', 'Não deste autorização à Google. Podes tentar outra vez quando quiseres.'], state: ['error', 'O pedido de ligação expirou ou não é válido. Tenta outra vez.'], error: ['error', 'Não foi possível concluir a ligação com a Google. Tenta outra vez.'] };
  if (back && BACK[back]) { window.UX?.toast(BACK[back][0], BACK[back][1]); history.replaceState(null, '', location.pathname + '#calendar'); setTimeout(() => window.showSection?.('calendar'), 400); }

  document.addEventListener('gf:section', async e => {
    if (e.detail !== 'calendar') return;
    await load();
    // automático: sincroniza ao abrir se passaram mais de 5 minutos
    if (st?.connected && st.sync_mode === 'auto') {
      // as DUAS horas são do servidor (a de agora e a da última sincronização): não depende do relógio nem do fuso do computador
      const at = s => new Date(String(s).replace(' ', 'T')).getTime();
      const age = st.last_sync_at ? ((st.now ? at(st.now) : Date.now()) - at(st.last_sync_at)) / 60000 : Infinity;
      if (age > AUTO_MINUTES) sync(false);
    }
  });
})();
