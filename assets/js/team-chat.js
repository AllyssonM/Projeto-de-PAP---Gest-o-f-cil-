/* =========================================================================
   CONVERSA ENTRE O LÍDER E UM FUNCIONÁRIO  (assets/js/team-chat.js)
   -------------------------------------------------------------------------
   GFChat.mount(elemento, { peerId, leader: true|false, onChange }) desenha a caixa de mensagens: o histórico, o campo para escrever e,
   em cada mensagem tua, se já foi lida. O líder pode escolher o tipo (mensagem, tarefa, desempenho ou aviso importante).
   Atualiza sozinha a cada 10 s enquanto a página está visível. Abrir a conversa marca como lidas as mensagens recebidas (feito no servidor).
   Devolve { refresh(), destroy() }. Todo o texto é escapado.
   ========================================================================= */
(function () {
  'use strict';
  const KIND = { general: '', task: 'Tarefa', performance: 'Desempenho', notice: 'Aviso importante' };
  function mount(el, { peerId = 0, leader = false, onChange } = {}) {
    el.innerHTML = `<div class="chat">
      <div class="chat-log" role="log" aria-live="polite" aria-label="Conversa" tabindex="0"></div>
      <form class="chat-form" autocomplete="off" novalidate>
        ${leader ? `<label class="chat-kind"><span class="sr-only">Tipo de mensagem</span><select name="kind" aria-label="Tipo de mensagem"><option value="general">Mensagem</option><option value="task">Tarefa</option><option value="performance">Desempenho</option><option value="notice">Aviso importante</option></select></label>` : ''}
        <label class="chat-text"><span class="sr-only">Escreve a tua mensagem</span><textarea name="body" rows="2" maxlength="1000" placeholder="Escreve uma mensagem… (Enter envia, Shift+Enter muda de linha)"></textarea></label>
        <button class="button primary" type="submit">Enviar</button>
      </form>
      <p class="ap-form-error chat-error" role="alert" hidden></p></div>`;
    const log = el.querySelector('.chat-log'), form = el.querySelector('form'), err = el.querySelector('.chat-error'), ta = form.elements.body;
    let timer = null, lastSig = '', dead = false, busy = false;

    function draw(d) {
      GFT.setNow(d.now);
      const sig = JSON.stringify(d.items.map(i => [i.id, i.read]));
      if (sig === lastSig) return;
      const atEnd = log.scrollTop + log.clientHeight >= log.scrollHeight - 40;
      lastSig = sig;
      log.innerHTML = d.items.length ? d.items.map(m => `<div class="msg ${m.mine ? 'mine' : 'theirs'}">
          <div class="msg-bubble">${KIND[m.kind] ? `<span class="msg-kind k-${esc(m.kind)}">${esc(KIND[m.kind])}</span>` : ''}<p>${esc(m.body).replace(/\n/g, '<br>')}</p></div>
          <small>${esc(GFT.when(m.created_at))}${m.mine ? ` · <span class="msg-read ${m.read ? 'is-read' : ''}">${m.read ? '✓✓ Lida' : '✓ Enviada, por ler'}</span>` : ''}</small></div>`).join('')
        : `<p class="muted chat-empty">Ainda não há mensagens. Escreve a primeira.</p>`;
      if (atEnd || !log.dataset.ready) log.scrollTop = log.scrollHeight;
      log.dataset.ready = '1';
    }
    async function refresh(mark = true) {
      if (dead) return;
      try { draw(await api(`messages.php?${leader ? 'with=' + encodeURIComponent(peerId) + '&' : ''}mark=${mark ? 1 : 0}`)); err.hidden = true; if (mark) onChange?.(); } catch (e) { if (!log.dataset.ready) log.innerHTML = `<p class="muted">${esc(e.message)}</p>`; }
    }
    async function send() {
      const body = ta.value.trim();
      if (!body || busy) return;
      busy = true; err.hidden = true;
      try {
        await api('messages.php', { method: 'POST', body: JSON.stringify({ to: peerId, body, kind: leader ? form.elements.kind.value : 'general', csrf }) });
        ta.value = ''; await refresh(); window.GFNotif?.refresh();
      } catch (e) { err.textContent = e.message; err.hidden = false; }
      finally { busy = false; ta.focus(); }
    }
    form.addEventListener('submit', e => { e.preventDefault(); send(); });
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } });
    timer = setInterval(() => { if (!document.hidden && document.body.contains(el)) refresh(); }, 10000);
    refresh();
    return { refresh, focus: () => ta.focus(), destroy() { dead = true; clearInterval(timer); } };
  }
  window.GFChat = { mount };
})();
