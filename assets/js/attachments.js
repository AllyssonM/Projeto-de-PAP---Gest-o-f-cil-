/* =========================================================================
   ANEXOS: FOTO OU PDF DO RECIBO  (assets/js/attachments.js)
   -------------------------------------------------------------------------
   Em cada movimento (Fluxo de caixa) e em cada conta (Contas) há um botão 📎. Abre uma janela para:
     - ver os ficheiros já juntos (miniatura nas fotos, ícone nos PDF);
     - juntar um novo: "Tirar foto" (abre a câmara no telemóvel) ou "Escolher ficheiro" (foto ou PDF, até 5 MB);
     - apagar (o administrador, ou quem o juntou).
   O botão mostra quantos ficheiros tem (📎 2). Os ficheiros vão para api/attachments.php (validação e segurança no servidor).
   ========================================================================= */
(function () {
  'use strict';
  const MAX = 5 * 1024 * 1024;
  const counts = { transaction: {}, bill: {} };
  const u = window.GF_USER || {};
  const can = m => u.isOwner || (u.permissions || []).includes(m);
  const size = n => n >= 1048576 ? (n / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';

  /* contagens para o 📎 2 das tabelas */
  async function refresh() {
    try {
      if (can('cashflow')) counts.transaction = (await api('attachments.php?counts=transaction')).counts;
      if (can('accounts')) counts.bill = (await api('attachments.php?counts=bill')).counts;
    } catch (e) { /* sem contagens: ficam só os 📎 */ }
    decorate();
  }
  function decorate() {
    $$('[data-attach]').forEach(b => {
      const n = Number((counts[b.dataset.attach] || {})[b.dataset.id] || 0);
      b.textContent = n ? `📎 ${n}` : '📎';
      b.classList.toggle('has-files', n > 0);
    });
  }
  ['#transactions', '#bills'].forEach(sel => { const el = $(sel); if (el) new MutationObserver(decorate).observe(el, { childList: true }); });

  /* a janela */
  async function open(entity, id, title) {
    const { root, close } = UX.modal(`<h2>Recibos e anexos</h2><p class="muted">${esc(title || '')}</p>
      <ul class="attach-list" id="attach-list" aria-live="polite"></ul>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="attach-add">
        <label class="button secondary attach-pick">📷 Tirar foto<input type="file" accept="image/*" capture="environment" hidden></label>
        <label class="button secondary attach-pick">📎 Escolher ficheiro<input type="file" accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" hidden></label>
      </div>
      <p class="muted attach-hint">Foto ou PDF, até 5 MB. As fotos perdem a localização GPS ao serem guardadas.</p>
      <div class="ap-modal-actions"><button type="button" class="button primary" data-no>Fechar</button></div>`, { onClose: () => refresh() });
    const list = root.querySelector('#attach-list'), err = root.querySelector('.ap-form-error');
    const fail = t => { err.textContent = t; err.hidden = !t; };

    async function load() {
      try {
        const d = await api(`attachments.php?entity=${entity}&id=${encodeURIComponent(id)}`);
        counts[entity][id] = d.items.length;
        list.innerHTML = d.items.length ? d.items.map(a => `<li class="attach-item">
          ${a.mime.startsWith('image/') ? `<a href="${esc(a.url)}" target="_blank" rel="noopener"><img src="${esc(a.url)}" alt="Pré-visualização de ${esc(a.name)}" width="56" height="56" loading="lazy"></a>` : '<span class="attach-pdf" aria-hidden="true">PDF</span>'}
          <span class="attach-meta"><a href="${esc(a.url)}" target="_blank" rel="noopener">${esc(a.name)}</a><small>${esc(size(a.size))} · ${esc(String(a.created_at).slice(0, 10))}</small></span>
          ${a.can_delete ? `<button type="button" class="button small danger" data-del="${a.id}" aria-label="Apagar ${esc(a.name)}">Apagar</button>` : ''}</li>`).join('')
          : '<li class="muted">Ainda não há ficheiros. Tira uma foto do recibo ou escolhe um PDF.</li>';
        list.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => {
          if (!await UX.confirm({ title: 'Apagar este ficheiro?', text: 'Não se pode desfazer.', confirmLabel: 'Apagar', danger: true })) return;
          try { await api(`attachments.php?id=${encodeURIComponent(b.dataset.del)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) }); load(); } catch (e) { fail(e.message); }
        });
      } catch (e) { list.innerHTML = `<li class="muted">${esc(e.message)}</li>`; }
    }

    root.querySelectorAll('input[type=file]').forEach(inp => inp.addEventListener('change', async () => {
      const file = inp.files[0]; inp.value = ''; fail('');
      if (!file) return;
      if (file.size > MAX) return fail('O ficheiro é demasiado grande (máximo 5 MB).');
      const fd = new FormData(); fd.append('entity', entity); fd.append('entity_id', id); fd.append('csrf', csrf); fd.append('file', file);
      list.setAttribute('aria-busy', 'true');
      try { await api('attachments.php', { method: 'POST', body: fd }); msg('Ficheiro juntado.'); await load(); } catch (e) { fail(e.message); }
      finally { list.removeAttribute('aria-busy'); }
    }));
    root.querySelector('[data-no]').onclick = () => close(true);
    load();
  }

  document.addEventListener('click', e => {
    const b = e.target.closest('[data-attach]');
    if (b) open(b.dataset.attach, b.dataset.id, b.dataset.title);
  });
  window.GFAttach = { open, refresh };
})();
