/* =========================================================
   CHAT COM A LUMINA (assistente de IA)
   - O navegador só envia a pergunta (e o id da conversa). Quem consulta os dados e de quem são
     os dados decide o servidor, pela sessão. O contexto da conversa também fica no servidor.
   - O texto da IA é sempre escapado antes de ser mostrado (sem HTML vindo da IA ou da base de dados).
   Usa do app.js: api() e a variável csrf.
   ========================================================= */
(function () {
  'use strict';
  const fab = $('#ai-fab'), panel = $('#ai-panel');
  if (!fab || !panel) return;

  const list = $('#ai-messages'), form = $('#ai-form'), input = $('#ai-input'), sendBtn = $('#ai-send');
  const sugg = $('#ai-suggestions');
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const first = panel.dataset.first || '';
  const tt = x => (window.t ? window.t(x) : x);                               // idioma: as respostas da Lumina chegam do servidor já no idioma certo; isto traduz só o que nasce aqui

  let convId = null, busy = false, loaded = false, isOpen = false, closeTimer = 0, mode = 'basic';

  /* ---------------- Markdown seguro (escapa tudo primeiro) ---------------- */
  const inline = s => esc(s)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  const cells = l => l.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());

  function md(src) {
    const lines = String(src).replace(/\r/g, '').split('\n');
    let html = '', i = 0;
    while (i < lines.length) {
      const line = lines[i];
      if (!line.trim()) { i++; continue; }
      if (/^\s*\|.*\|\s*$/.test(line) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(lines[i + 1])) {
        const head = cells(line); i += 2; const rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(cells(lines[i])); i++; }
        html += '<div class="ai-table"><table><thead><tr>' + head.map(h => `<th>${inline(h)}</th>`).join('') + '</tr></thead><tbody>'
          + rows.map(r => '<tr>' + head.map((_, k) => `<td>${inline(r[k] ?? '')}</td>`).join('') + '</tr>').join('') + '</tbody></table></div>';
      } else if (/^\s*[-*•]\s+/.test(line)) {
        const items = [];
        while (i < lines.length && /^\s*[-*•]\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*[-*•]\s+/, '')); i++; }
        html += '<ul>' + items.map(t => `<li>${inline(t)}</li>`).join('') + '</ul>';
      } else if (/^\s*\d+[.)]\s+/.test(line)) {
        const items = [];
        while (i < lines.length && /^\s*\d+[.)]\s+/.test(lines[i])) { items.push(lines[i].replace(/^\s*\d+[.)]\s+/, '')); i++; }
        html += '<ol>' + items.map(t => `<li>${inline(t)}</li>`).join('') + '</ol>';
      } else {
        const para = [];
        while (i < lines.length && lines[i].trim() && !/^\s*\|.*\|\s*$/.test(lines[i]) && !/^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) { para.push(lines[i].replace(/^#{1,4}\s+/, '')); i++; }
        html += '<p>' + para.map(inline).join('<br>') + '</p>';
      }
    }
    return html;
  }

  /* ---------------- mensagens ---------------- */
  const scrollDown = (smooth = true) => { list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' }); };

  function addUser(text) {
    const d = document.createElement('div');
    d.className = 'ai-msg user'; d.setAttribute('data-no-i18n', ''); d.textContent = text;
    list.appendChild(d); scrollDown();
  }
  function sourcesEl(sources) {
    if (!sources || !sources.length) return null;
    const s = document.createElement('div'); s.className = 'ai-sources';
    s.innerHTML = esc(tt('Fonte:')) + ' ' + sources.map(x => `<span>${esc(tt(x))}</span>`).join('');
    return s;
  }

  /** Mostra o texto a "escrever-se" mantendo a formatação (revela os caracteres, não o Markdown). */
  function typeInto(el, done) {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const nodes = []; let total = 0;
    while (walker.nextNode()) { const n = walker.currentNode; nodes.push({ n, t: n.nodeValue }); total += n.nodeValue.length; }
    nodes.forEach(o => { o.n.nodeValue = ''; });
    const duration = Math.min(2200, Math.max(450, total * 11));
    const t0 = performance.now(); let lastScroll = 0;
    (function step(now) {
      const p = Math.min(1, (now - t0) / duration);
      let left = Math.floor(p * total);
      for (const o of nodes) { const k = Math.min(o.t.length, left); o.n.nodeValue = o.t.slice(0, k); left -= k; if (left <= 0) break; }
      if (now - lastScroll > 120) { lastScroll = now; scrollDown(false); }
      if (p < 1) requestAnimationFrame(step); else { nodes.forEach(o => { o.n.nodeValue = o.t; }); scrollDown(); done && done(); }
    })(t0);
  }

  function addAssistant(text, sources, typing) {
    const d = document.createElement('div');
    d.className = 'ai-msg assistant'; d.setAttribute('data-no-i18n', ''); d.innerHTML = md(text);   // texto da Lumina: já vem no idioma certo (as conversas antigas ficam como foram escritas)
    list.appendChild(d);
    const finish = () => { const s = sourcesEl(sources); if (s) { d.appendChild(s); scrollDown(); } };
    if (typing && !reduce) typeInto(d, finish); else { finish(); scrollDown(); }
  }
  function addError(text) {
    const d = document.createElement('div'); d.className = 'ai-msg error'; d.textContent = text;
    list.appendChild(d); scrollDown();
  }
  function addThinking() {
    const d = document.createElement('div'); d.className = 'ai-thinking'; d.setAttribute('role', 'status');
    d.innerHTML = '<span class="ai-dots"><i></i><i></i><i></i></span><span class="label">Analisando os dados…</span>';
    list.appendChild(d); scrollDown(); return d;
  }

  function greeting() {
    addAssistant(tt(`Olá${first ? ', ' + first : ''}! 👋 Sou a **Lumina**, a tua assistente. Consulto os **dados do teu negócio** (só as áreas a que tens acesso) e respondo a perguntas simples. Escolhe uma sugestão aqui em baixo ou escreve a tua pergunta; se não souberes por onde começar, escreve **ajuda**.`), [], false);
  }

  /* Sugestões rápidas: só o que a pessoa pode consultar, e com o vocabulário do ramo (imóveis, viaturas, serviços...). */
  function refreshChips() {
    const perms = (window.GF_USER?.permissions || []), owner = window.GF_USER?.isOwner !== false;
    const can = m => owner || perms.includes(m);
    const tab = window.GFP?.current().vocab.stockTab || 'Estoque';
    const word = tab === 'Estoque' ? 'produtos' : tab.toLowerCase();
    const list = [];
    if (can('accounts')) list.push('Quanto temos para receber?', 'Quais contas estão vencidas?');
    if (can('stock')) list.push(`Que ${word} tenho com estoque baixo?`);
    if (can('cashflow')) list.push('Total de vendas deste mês');
    if (can('calendar')) list.push('Quais os meus próximos eventos?');
    if (!list.length) list.push('Ajuda');
    sugg.innerHTML = list.slice(0, 4).map(t => `<button type="button" class="ai-chip">${t.replace(/&/g, '&amp;').replace(/</g, '&lt;')}</button>`).join('');
    sugg.querySelectorAll('.ai-chip').forEach(b => b.onclick = () => { input.value = b.textContent; autogrow(); form.requestSubmit(); });
  }
  document.addEventListener('gf:profile', refreshChips);

  function setMode(m) {
    mode = m;
    $('#ai-mode').innerHTML = '<i class="ai-dot"></i>' + (m === 'ai' ? 'IA · dados do negócio' : 'Modo básico · dados do negócio');
  }

  /* ---------------- abrir / fechar ---------------- */
  async function loadHistory() {
    if (loaded) return; loaded = true;
    try {
      const d = await api('ai_chat.php?latest=1');
      setMode(d.mode);
      if (d.messages && d.messages.length) {
        convId = d.conversation_id;
        d.messages.forEach(m => m.role === 'user' ? addUser(m.content) : addAssistant(m.content, m.sources, false));
        sugg.classList.add('gone');
      } else greeting();
    } catch (e) { greeting(); }
    scrollDown(false);
  }

  function open() {
    clearTimeout(closeTimer);
    isOpen = true; panel.classList.remove('hidden');
    fab.classList.add('is-open'); fab.setAttribute('aria-expanded', 'true'); fab.setAttribute('aria-label', 'Fechar a Lumina');
    requestAnimationFrame(() => requestAnimationFrame(() => panel.classList.add('open')));
    loadHistory();
    setTimeout(() => input.focus({ preventScroll: true }), 300);
  }
  function close() {
    isOpen = false; panel.classList.remove('open');
    fab.classList.remove('is-open'); fab.setAttribute('aria-expanded', 'false'); fab.setAttribute('aria-label', 'Abrir a Lumina');
    closeTimer = setTimeout(() => panel.classList.add('hidden'), 330);
  }
  fab.onclick = () => (isOpen ? close() : open());
  $('#ai-close').onclick = () => { close(); fab.focus({ preventScroll: true }); };
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && isOpen && $('#reminder-modal')?.classList.contains('hidden') !== false) close();
  });

  /* ---------------- enviar ---------------- */
  function autogrow() { input.style.height = 'auto'; input.style.height = Math.min(112, input.scrollHeight) + 'px'; sendBtn.disabled = busy || !input.value.trim(); }
  input.addEventListener('input', autogrow);
  input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
  refreshChips();

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text || busy) return;
    busy = true; sendBtn.classList.remove('sent'); void sendBtn.offsetWidth; sendBtn.classList.add('sent');
    sugg.classList.add('gone');
    addUser(text); input.value = ''; autogrow();
    const thinking = addThinking();
    try {
      const r = await api('ai_chat.php', { method: 'POST', body: JSON.stringify({ message: text, conversation_id: convId, lang: window.LUMINA_LANG || 'pt', csrf }) });
      convId = r.conversation_id; setMode(r.mode);
      thinking.remove();
      addAssistant(r.reply, r.sources, true);
    } catch (err) {
      thinking.remove();
      addError(err.message || 'Não foi possível obter resposta agora.');
    } finally { busy = false; autogrow(); input.focus({ preventScroll: true }); }
  });

  /* ---------------- limpar conversa ---------------- */
  $('#ai-clear').onclick = async () => {
    if (busy) return;
    try { if (convId) await api(`ai_chat.php?conversation_id=${convId}`, { method: 'DELETE', body: JSON.stringify({ csrf }) }); } catch (e) {}
    convId = null; list.innerHTML = ''; sugg.classList.remove('gone'); greeting(); input.focus({ preventScroll: true });
  };

  $('#logout')?.addEventListener('click', () => { convId = null; });
  autogrow();
})();
