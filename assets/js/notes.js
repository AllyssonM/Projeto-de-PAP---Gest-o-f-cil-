/* =========================================================================
   BLOCO DE NOTAS  (assets/js/notes.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  a aba "Notas": lista à esquerda, editor à direita.
     - Criar, editar, apagar (com confirmação) e FIXAR notas.
     - Etiquetas, cor (a paleta do sistema) e pesquisa por texto ou etiqueta.
     - GUARDAR AUTOMATICAMENTE: depois de parares de escrever (1 s) a nota é
       guardada; mostra "Guardado às HH:MM". Se falhar a ligação, fica um
       rascunho neste dispositivo e avisa-se o utilizador.
     - Privada (só tu) ou partilhada com a equipa.
   DADOS: api/notes.php. As notas privadas de um funcionário nem o dono lê.
   SEGURANÇA: todo o texto é escapado antes de ir para o ecrã (esc()).
   ========================================================================= */
(function () {
  'use strict';
  const root = document.querySelector('#notes');
  if (!root) return;
  const $n = s => root.querySelector(s);
  const COLORS = { teal: 'Turquesa', blue: 'Azul', purple: 'Roxo', coral: 'Coral', amber: 'Âmbar', slate: 'Cinza' };
  const hm = v => new Date(String(v).replace(' ', 'T')).toLocaleTimeString((window.LUMINA_LOCALE || 'pt-PT'), { hour: '2-digit', minute: '2-digit' });
  const day = v => new Date(String(v).replace(' ', 'T')).toLocaleDateString((window.LUMINA_LOCALE || 'pt-PT'), { day: '2-digit', month: 'short' });
  const DRAFT_KEY = 'gf-note-draft';

  let notes = [], tags = [], current = null, query = '', tagFilter = '', timer = 0, saving = false, loaded = false;

  /* ---------------------------------------------------------------- lista */
  async function load() {
    $n('#note-list').innerHTML = '<div class="skeleton-stack"><div class="skeleton" style="height:84px"></div><div class="skeleton" style="height:84px"></div><div class="skeleton" style="height:84px"></div></div>';
    try {
      const d = await api(`notes.php?q=${encodeURIComponent(query)}&tag=${encodeURIComponent(tagFilter)}`);
      notes = d.items; tags = d.tags; loaded = true;
      renderTags(); renderList();
    } catch (e) {
      $n('#note-list').innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível carregar as notas</b><span>${esc(e.message)}</span><button type="button" class="button secondary" id="note-retry">Tentar novamente</button></div>`;
      $n('#note-retry').onclick = load;
    }
  }
  function renderTags() {
    $n('#note-tags').innerHTML = tags.length ? ['', ...tags].map(t => `<button type="button" class="note-tag-filter${t === tagFilter ? ' on' : ''}" data-tag="${esc(t)}" aria-pressed="${t === tagFilter}">${t ? '#' + esc(t) : 'Todas'}</button>`).join('') : '';
    $n('#note-tags').querySelectorAll('[data-tag]').forEach(b => b.onclick = () => { tagFilter = b.dataset.tag; load(); });
  }
  function renderList() {
    const box = $n('#note-list');
    if (!notes.length) {
      box.innerHTML = (query || tagFilter)
        ? '<div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">🔎</span><b>Nenhuma nota encontrada</b><span>Tenta outra palavra ou remove o filtro.</span></div>'
        : '<div class="ux-empty"><span class="ux-empty-icon" aria-hidden="true">📝</span><b>Ainda não tens notas</b><span>Clica em "Nova nota" para guardar uma ideia, um lembrete ou uma lista.</span></div>';
      return;
    }
    box.innerHTML = notes.map((n, i) => `<button type="button" class="note-card note-${esc(n.color)}${current && current.id === n.id ? ' active' : ''}" data-id="${n.id}" style="--i:${Math.min(i, 8)}">
        <span class="note-top"><b>${esc(n.title)}</b>${n.pinned ? '<span class="note-pin" title="Fixada" aria-label="Fixada">📌</span>' : ''}</span>
        <span class="note-snippet">${esc((n.detail || '').slice(0, 110))}</span>
        <span class="note-meta">${n.tags.map(t => `<i>#${esc(t)}</i>`).join('')}<small>${n.shared ? '👥 Equipa' + (n.mine ? '' : ' · ' + esc(n.author || '')) : '🔒 Privada'} · ${esc(day(n.updated_at))}</small></span></button>`).join('');
    box.querySelectorAll('.note-card').forEach(c => c.onclick = () => openNote(notes.find(n => n.id === +c.dataset.id)));
  }

  /* ---------------------------------------------------------------- editor */
  const form = $n('#note-form');
  function showEditor(on) { $n('#note-empty').hidden = on; form.hidden = !on; }

  function fill(n) {
    current = n;
    form.title.value = n.title || ''; form.detail.value = n.detail || '';
    form.querySelectorAll('[name=color]').forEach(r => { r.checked = r.value === (n.color || 'teal'); });
    form.shared.checked = !!n.shared; form.shared.disabled = !!n.id && !n.mine;                       // só o autor decide
    form.querySelector('#note-pin').setAttribute('aria-pressed', !!n.pinned);
    form.querySelector('#note-pin').classList.toggle('on', !!n.pinned);
    chips = [...(n.tags || [])]; renderChips();
    $n('#note-delete').hidden = !n.id;
    setStatus(n.id ? `Última alteração: ${day(n.updated_at)} às ${hm(n.updated_at)}` : 'Nota nova: guarda-se sozinha enquanto escreves.');
    showEditor(true);
    $n('.note-editor').dataset.color = n.color || 'teal';
  }
  function openNote(n) { flush(); fill(n); renderList(); form.title.focus(); }
  function newNote() { flush(); fill({ id: null, title: '', detail: '', color: 'teal', tags: [], pinned: false, shared: false, mine: true }); renderList(); form.title.focus(); }
  $n('#note-new').onclick = newNote;

  /* etiquetas (chips) */
  let chips = [];
  function renderChips() {
    $n('#note-chips').innerHTML = chips.map((t, i) => `<span class="note-chip">#${esc(t)}<button type="button" data-rm="${i}" aria-label="Remover etiqueta ${esc(t)}">×</button></span>`).join('');
    $n('#note-chips').querySelectorAll('[data-rm]').forEach(b => b.onclick = () => { chips.splice(+b.dataset.rm, 1); renderChips(); changed(); });
  }
  const tagInput = $n('#note-tag-input');
  function addChip() {
    const t = tagInput.value.replace(/[#,]/g, '').trim().slice(0, 24);
    if (t && chips.length < 8 && !chips.some(c => c.toLowerCase() === t.toLowerCase())) { chips.push(t); renderChips(); changed(); }
    tagInput.value = '';
  }
  tagInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); addChip(); }
    if (e.key === 'Backspace' && !tagInput.value && chips.length) { chips.pop(); renderChips(); changed(); }
  });
  tagInput.addEventListener('blur', addChip);

  /* ---------------------------------------------------------------- guardar automaticamente */
  function setStatus(text, error = false) { const s = $n('#note-status'); s.textContent = text; s.classList.toggle('error', error); }
  function payload() {
    return { action: 'save', id: current?.id || undefined, title: form.title.value.trim(), detail: form.detail.value, color: form.querySelector('[name=color]:checked')?.value || 'teal',
      tags: chips, pinned: form.querySelector('#note-pin').getAttribute('aria-pressed') === 'true', shared: form.shared.checked, csrf };
  }
  function changed() {
    $n('.note-editor').dataset.color = form.querySelector('[name=color]:checked')?.value || 'teal';
    setStatus('A escrever…');
    clearTimeout(timer);
    timer = setTimeout(save, 1000);                                  // 1 s depois de parares de escrever
  }
  async function save() {
    clearTimeout(timer); timer = 0;                                   // sem alterações pendentes (senão o flush() voltava a guardar)
    const target = current;                                           // a nota a que ESTA gravação pertence
    const body = payload();
    if (!body.title && !body.detail.trim()) return;                  // nota vazia: não guarda
    const snapshot = JSON.stringify({ ...body, id: undefined, csrf: undefined });   // sem o id: o 1.º envio não o tem e o 2.º tem
    if (target && target._sent === snapshot) { if (current === target) setStatus(`Guardado às ${hm(target.updated_at)}`); return; }   // nada mudou: não repete o pedido
    if (saving) { timer = setTimeout(save, 400); return; }
    saving = true; setStatus('A guardar…');
    try {
      const d = await api('notes.php', { method: 'POST', body: JSON.stringify(body) });
      // A resposta chega depois: o utilizador pode já ter passado a outra nota. Por isso só se atualiza a nota
      // de origem (target); o editor só muda se ainda estiver nela. (Antes, o id colava-se à nota nova e sobrescrevia a anterior.)
      Object.assign(target, d.item); target._sent = snapshot;
      try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
      if (current === target) { $n('#note-delete').hidden = false; setStatus(`Guardado às ${hm(d.item.updated_at)}`); }
      const i = notes.findIndex(n => n.id === d.item.id); if (i >= 0) notes[i] = d.item; else notes.unshift(d.item);
      notes.sort((a, b) => (b.pinned - a.pinned) || String(b.updated_at).localeCompare(String(a.updated_at)));
      renderList();
      if (!tags.length || chips.some(t => !tags.includes(t))) load();            // etiquetas novas entram na barra
    } catch (e) {
      try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...body, csrf: undefined })); } catch (x) {}
      setStatus(`Não guardou (${e.message}). Ficou um rascunho neste dispositivo.`, true);
    } finally { saving = false; }
  }
  function flush() { if (timer) { clearTimeout(timer); timer = 0; if (!form.hidden) save(); } }
  window.addEventListener('beforeunload', () => { if (timer && !form.hidden) { try { localStorage.setItem(DRAFT_KEY, JSON.stringify({ ...payload(), csrf: undefined })); } catch (e) {} } });

  form.addEventListener('input', changed);
  form.addEventListener('change', changed);
  form.addEventListener('submit', e => { e.preventDefault(); save(); });
  $n('#note-pin').onclick = e => { const on = e.currentTarget.getAttribute('aria-pressed') !== 'true'; e.currentTarget.setAttribute('aria-pressed', on); e.currentTarget.classList.toggle('on', on); changed(); };
  $n('#note-delete').onclick = async () => {
    if (!current?.id) return;
    if (!await UX.confirm({ title: 'Apagar esta nota?', text: `"${current.title}" será apagada para sempre.`, confirmLabel: 'Apagar', danger: true })) return;
    try { await api(`notes.php?id=${current.id}`, { method: 'DELETE', body: JSON.stringify({ csrf }) }); current = null; showEditor(false); msg('Nota apagada.'); load(); }
    catch (e) { msg(e.message, true); }
  };

  /* ---------------------------------------------------------------- pesquisa */
  let st = 0;
  $n('#note-search').addEventListener('input', e => { clearTimeout(st); st = setTimeout(() => { query = e.target.value.trim(); load(); }, 250); });

  /* abre a aba -> carrega (uma vez) e recupera um rascunho que tenha ficado por guardar */
  document.addEventListener('gf:section', e => {
    if (e.detail !== 'notes') return;
    if (!loaded) load();
    try {
      const d = JSON.parse(localStorage.getItem(DRAFT_KEY) || 'null');
      if (d && !current && (d.title || d.detail)) { fill({ id: null, ...d, mine: true }); setStatus('Recuperámos um rascunho que não chegou a ser guardado. Escreve para o guardar.'); changed(); }
    } catch (x) {}
  });
  document.addEventListener('gf:section', e => { if (e.detail !== 'notes') flush(); });
  showEditor(false);
})();
