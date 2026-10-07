/* =========================================================================
   AVISOS: O SINO  (assets/js/notifications.js)
   -------------------------------------------------------------------------
   O sino do cabeçalho mostra quantos avisos há por ler e abre a lista. Quando chega um aviso NOVO com a página aberta, aparece também um
   cartão de vidro (como o dos eventos diários) com «Marcar como lido» e «Ver».
     líder:        «Ana entrou no sistema», novas mensagens dos funcionários
     funcionário:  nova mensagem do líder, nova tarefa, meta alterada, comentário sobre o desempenho, anúncios da equipa
   Pergunta ao servidor a cada 45 s enquanto a página está visível (isto também mantém o estado «online»). Nada de sons nem pop-ups do navegador.
   ========================================================================= */
(function () {
  'use strict';
  const btn = $('#notif-btn');
  if (!btn) return;
  const panel = $('#notif-panel'), list = $('#notif-list'), badge = $('#notif-badge'), stack = $('#notif-stack');
  const POLL_MS = 45000, ICON = { login: '👋', message: '💬', task: '✅', goal: '🎯', feedback: '⭐', announcement: '📣' };
  const isOwner = !!(window.GF_USER && window.GF_USER.isOwner);
  let items = [], unread = 0, lastId = null, timer = null, stopped = false;

  const avatar = n => n.actor ? GFT.avatar(n.actor.photo, n.actor.name, 38) : `<span class="gft-avatar gft-initial notif-emoji" style="width:38px;height:38px" aria-hidden="true">${ICON[n.type] || '🔔'}</span>`;

  function paintBadge() {
    badge.hidden = unread === 0;
    badge.dataset.n = unread > 99 ? '99+' : String(unread);   // o número vem do CSS (::after): o nome acessível do botão já o diz
    btn.setAttribute('aria-label', unread ? `Avisos (${unread} por ler)` : 'Avisos');
  }
  let lastSig = '';
  function paintList() {
    // não redesenha se nada mudou (e, quando muda, devolve o foco ao mesmo aviso): quem navega com o teclado não perde o sítio
    const sig = JSON.stringify(items.map(n => [n.id, n.read, GFT.when(n.created_at)]));
    if (sig === lastSig) return;
    lastSig = sig;
    const had = list.contains(document.activeElement) ? document.activeElement : null;
    const hadKey = had ? (had.classList.contains('notif-mark') ? 'mark' : 'open') + ':' + had.dataset.id : '';
    list.innerHTML = items.length ? items.map(n => `<li class="notif-item${n.read ? '' : ' unread'}">
        <button type="button" class="notif-open" data-id="${n.id}">${avatar(n)}
          <span class="notif-text"><strong>${esc(n.title)}</strong>${n.body ? `<span>${esc(n.body)}</span>` : ''}<small>${esc(GFT.when(n.created_at))}${n.read ? '' : ' · <b>por ler</b>'}</small></span></button>
        ${n.read ? '' : `<button type="button" class="notif-mark" data-id="${n.id}" aria-label="Marcar como lido: ${esc(n.title)}" title="Marcar como lido">✓</button>`}</li>`).join('')
      : '<li class="muted notif-empty">Sem avisos por agora.</li>';
    $('#notif-readall').hidden = unread === 0;
    if (hadKey) {                                                  // o elemento antigo foi substituído: foca o equivalente (ou o primeiro aviso)
      const [kind, id] = hadKey.split(':');
      (list.querySelector(`.notif-${kind}[data-id="${id}"]`) || list.querySelector('.notif-open') || $('#notif-close')).focus({ preventScroll: true });
    }
  }

  async function markRead(id) {
    try { await api('notifications.php', { method: 'POST', body: JSON.stringify({ action: 'read', id, csrf }) }); } catch (e) { /* o próximo pedido corrige */ }
    const n = items.find(x => x.id === id);
    if (n && !n.read) { n.read = true; unread = Math.max(0, unread - 1); }
    paintBadge(); paintList();
  }
  function go(n) {                                           // abre o sítio certo para cada tipo de aviso
    closePanel(false);
    if (isOwner && n.actor && (n.type === 'login' || n.type === 'message')) { window.GFStaff?.open(n.actor.id); return; }
    if (n.section && document.querySelector(`.nav-tab[data-section="${n.section}"]`)) showSection(n.section);
  }

  /* ---- cartão que aparece quando chega um aviso novo ---- */
  function popup(n) {
    const c = document.createElement('div');
    c.className = 'notif-card'; c.setAttribute('role', 'status');
    c.innerHTML = `<div class="notif-card-head"><span aria-hidden="true">🔔</span><strong>${esc(n.title)}</strong><button type="button" class="notif-x" aria-label="Fechar aviso">×</button></div>
      <div class="notif-card-body">${avatar(n)}<span>${n.body ? `${esc(n.body)}<br>` : ''}<small>${esc(GFT.when(n.created_at))}</small></span></div>
      <div class="notif-card-actions"><button type="button" class="button small secondary" data-act="read">Marcar como lido</button><button type="button" class="button small primary" data-act="open">Ver</button></div>`;
    stack.appendChild(c);
    requestAnimationFrame(() => c.classList.add('in'));
    let t = setTimeout(close, 12000);
    function close() { clearTimeout(t); c.classList.remove('in'); setTimeout(() => c.remove(), 250); }
    c.addEventListener('mouseenter', () => clearTimeout(t)); c.addEventListener('focusin', () => clearTimeout(t));
    c.addEventListener('mouseleave', () => { t = setTimeout(close, 6000); });
    c.querySelector('.notif-x').onclick = close;
    c.querySelector('[data-act=read]').onclick = () => { markRead(n.id); close(); };
    c.querySelector('[data-act=open]').onclick = () => { markRead(n.id); close(); go(n); };
    while (stack.children.length > 3) stack.firstElementChild.remove();
  }

  async function poll() {
    if (stopped) return;
    try {
      const d = await api('notifications.php');
      GFT.setNow(d.now);
      const maxId = d.items.reduce((m, n) => Math.max(m, n.id), 0);
      if (lastId !== null) d.items.filter(n => !n.read && n.id > lastId).reverse().slice(-3).forEach(popup);      // só os que chegaram agora
      lastId = Math.max(lastId ?? 0, maxId);
      items = d.items; unread = d.unread;
      paintBadge(); paintList();
      if (window.GFStaff?.onNotifications) window.GFStaff.onNotifications(d);
    } catch (e) { if (/Sess/i.test(e.message)) stopped = true; }
  }

  /* ---- painel ---- */
  function openPanel() {
    panel.hidden = false; btn.setAttribute('aria-expanded', 'true');
    requestAnimationFrame(() => panel.classList.add('open'));
    poll();
    (panel.querySelector('.notif-open') || $('#notif-close')).focus({ preventScroll: true });
  }
  function closePanel(returnFocus = true) {
    if (panel.hidden) return;
    panel.classList.remove('open'); btn.setAttribute('aria-expanded', 'false');
    setTimeout(() => { panel.hidden = true; }, 180);
    if (returnFocus) btn.focus({ preventScroll: true });
  }
  btn.addEventListener('click', () => panel.hidden ? openPanel() : closePanel());
  $('#notif-close').addEventListener('click', () => closePanel());
  $('#notif-readall').addEventListener('click', async () => {
    try { await api('notifications.php', { method: 'POST', body: JSON.stringify({ action: 'read_all', csrf }) }); } catch (e) { /* ignora */ }
    items.forEach(n => { n.read = true; }); unread = 0; paintBadge(); paintList();
  });
  list.addEventListener('click', e => {
    const mark = e.target.closest('.notif-mark'), open = e.target.closest('.notif-open');
    if (mark) markRead(+mark.dataset.id);
    else if (open) { const n = items.find(x => x.id === +open.dataset.id); if (n) { markRead(n.id); go(n); } }
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !panel.hidden) closePanel(); });
  document.addEventListener('click', e => { if (!panel.hidden && !panel.contains(e.target) && !btn.contains(e.target)) closePanel(false); });

  poll();
  timer = setInterval(() => { if (!document.hidden) poll(); }, POLL_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) poll(); });
  window.GFNotif = { refresh: poll, get unread() { return unread; } };
})();
