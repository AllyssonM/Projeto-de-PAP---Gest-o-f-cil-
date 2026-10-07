/* Estoque em tempo real.
   - Cada cartão de produto (com estoque em unidades) tem um editor: − / campo / + e "Guardar".
   - Guarda em api/data.php?module=product_stock (transação + movimento de estoque + auditoria).
   - O valor mostrado é SEMPRE o que a API devolve (a base de dados), nunca o que foi escrito no campo.
   - Atualiza o resumo da visão geral e refresca sozinho (a cada 30 s e ao voltar ao separador),
     sem pisar edições em curso. Usa os globais de app.js: state, api, csrf, msg, esc, loadAll. */
(() => {
  const unitOf = ramo => (ramo && ramo.vocab && ramo.vocab.unit) || 'un';
  const isLow = p => Number(p.stock_quantity) > 0 && Number(p.stock_quantity) <= Number(p.minimum_stock);
  const isOut = p => Number(p.stock_quantity) <= 0;
  const MAX = 1000000;

  const badge = p => isOut(p) ? '<span class="stock-badge out">Sem estoque</span>'
    : isLow(p) ? '<span class="stock-badge low">Estoque baixo</span>'
    : '<span class="stock-badge ok">Estoque normal</span>';

  /** HTML do bloco de estoque de um cartão. */
  function cardHtml(x, ramo) {
    const id = Number(x.id), q = Number(x.stock_quantity);
    return `<div class="stock-editor" data-stock-id="${id}" data-saved="${q}" data-min="${Number(x.minimum_stock)}">
      <div class="stock-line"><span class="stock-label">Em estoque (${esc(unitOf(ramo))})</span>${badge(x)}</div>
      <div class="stock-controls">
        <button type="button" class="stock-step" data-step="-1" aria-label="Diminuir uma unidade de ${esc(x.name)}">−</button>
        <input class="stock-input" type="text" inputmode="numeric" autocomplete="off" value="${q}" aria-label="Quantidade em estoque de ${esc(x.name)}">
        <button type="button" class="stock-step" data-step="1" aria-label="Aumentar uma unidade de ${esc(x.name)}">+</button>
      </div>
      <div class="stock-actions" hidden>
        <button type="button" class="stock-save">Guardar</button>
        <button type="button" class="stock-cancel">Cancelar</button>
      </div>
      <p class="stock-feedback" role="status" aria-live="polite"></p>
      <p class="stock-min">Mínimo: ${Number(x.minimum_stock)}</p>
    </div>`;
  }

  /** Valida o texto escrito: só inteiros >= 0. Devolve {ok, value, error}. */
  function parse(raw) {
    const t = String(raw ?? '').trim();
    if (t === '') return { ok: false, error: 'Indica a quantidade.' };
    if (!/^-?\d+$/.test(t)) return { ok: false, error: /^-?\d+[.,]\d+$/.test(t) ? 'Usa um número inteiro (sem decimais).' : 'Escreve apenas números.' };
    const n = Number(t);
    if (n < 0) return { ok: false, error: 'A quantidade não pode ser negativa.' };
    if (n > MAX) return { ok: false, error: 'Quantidade demasiado grande.' };
    return { ok: true, value: n };
  }

  const feedback = (box, text, kind) => {
    const p = box.querySelector('.stock-feedback');
    p.textContent = text || ''; p.className = 'stock-feedback' + (kind ? ' ' + kind : '');
  };

  function dirty(box) {
    const input = box.querySelector('.stock-input');
    const r = parse(input.value);
    const changed = !r.ok || r.value !== Number(box.dataset.saved);
    box.querySelector('.stock-actions').hidden = !changed;
    box.classList.toggle('is-dirty', changed);
    input.classList.toggle('invalid', !r.ok);
    input.setAttribute('aria-invalid', r.ok ? 'false' : 'true');
    feedback(box, r.ok ? '' : r.error, r.ok ? '' : 'error');
    return r;
  }

  async function save(box) {
    const r = dirty(box);
    if (!r.ok) { box.querySelector('.stock-input').focus(); return; }
    const id = Number(box.dataset.stockId), input = box.querySelector('.stock-input');
    const btn = box.querySelector('.stock-save');
    btn.disabled = true; box.classList.add('is-saving'); feedback(box, 'A guardar…', 'busy');
    try {
      const res = await fetch(base + 'data.php?module=product_stock', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, stock_quantity: r.value, expected: Number(box.dataset.saved), csrf }),
      });
      if (res.status === 401) { location.href = 'index.php#autenticacao'; return; }
      let d; try { d = await res.json(); } catch { throw Error('Resposta inválida do servidor.'); }
      if (res.status === 409 && d.conflict) {
        // Outra pessoa alterou entretanto: mostra o valor real e deixa decidir.
        box.dataset.saved = d.current; input.value = d.current;
        dirty(box); feedback(box, d.error, 'error'); apply(id, { stock_quantity: d.current });
        return;
      }
      if (!res.ok || !d.success) throw Error(d.error || 'Não foi possível guardar o estoque.');
      apply(id, d.item); if (d.summary) { state.stockSummary = d.summary; renderOverview(); }
      rerenderCard(id, true, d.delta);
      msg(`Estoque de «${d.item.name}» atualizado: ${d.previous} → ${d.item.stock_quantity}.`);
    } catch (e) {
      feedback(box, e.message || 'Erro ao guardar.', 'error'); msg(e.message || 'Erro ao guardar o estoque.', true);
    } finally { btn.disabled = false; box.classList.remove('is-saving'); }
  }

  /** Atualiza o produto em memória com o que a API devolveu. */
  function apply(id, item) {
    const p = (state.products || []).find(x => Number(x.id) === id);
    if (p && item) Object.assign(p, item);
  }

  /** Redesenha só o bloco de estoque de um cartão (sem perder o resto da página). */
  function rerenderCard(id, flash, delta) {
    const box = document.querySelector(`.stock-editor[data-stock-id="${id}"]`);
    const p = (state.products || []).find(x => Number(x.id) === id);
    if (!box || !p) return;
    const wrap = document.createElement('div');
    wrap.innerHTML = cardHtml(p, window.GFP.current());
    const fresh = wrap.firstElementChild;
    box.replaceWith(fresh);
    if (flash) {
      const sign = delta > 0 ? '+' : '';
      feedback(fresh, `Guardado ✓${delta ? ` (${sign}${delta})` : ''}`, 'ok');
      fresh.classList.add('just-saved');
      setTimeout(() => { fresh.classList.remove('just-saved'); const f = fresh.querySelector('.stock-feedback'); if (f && f.classList.contains('ok')) feedback(fresh, ''); }, 2600);
    }
  }

  /* ---- resumo na visão geral ---- */
  function renderOverview() {
    const box = document.getElementById('stock-overview');
    if (!box) return;
    const allowed = typeof can === 'function' ? can('stock') : true;
    const ramo = window.GFP && window.GFP.current();
    const s = state.stockSummary;
    if (!allowed || !ramo || !ramo.trackStock || !s) { box.hidden = true; return; }
    box.hidden = false;
    const attention = (state.products || []).filter(p => isOut(p) || isLow(p))
      .sort((a, b) => Number(a.stock_quantity) - Number(b.stock_quantity)).slice(0, 5);
    const stat = (n, label, cls = '') => `<div class="stock-stat ${cls}"><strong>${Number(n)}</strong><span>${label}</span></div>`;
    box.innerHTML = `<div class="panel-heading"><div><p class="eyebrow">ESTOQUE</p><h2>Estado do estoque</h2></div>
        <button type="button" class="button ghost small" data-section-go="stock">Gerir estoque</button></div>
      <div class="stock-stats">${stat(s.products, 'Produtos')}${stat(s.units, 'Unidades')}
        ${stat(s.low, 'Estoque baixo', s.low ? 'warn' : '')}${stat(s.out_of_stock, 'Sem estoque', s.out_of_stock ? 'danger' : '')}</div>
      ${attention.length ? `<ul class="stock-attention">${attention.map(p => `<li><span>${esc(p.name)}</span>
        <b class="${isOut(p) ? 'out' : 'low'}">${Number(p.stock_quantity)} / <span>mín.</span> ${Number(p.minimum_stock)}</b></li>`).join('')}</ul>`
        : (s.products ? '<p class="stock-allok">✓ Todos os produtos têm estoque acima do mínimo.</p>' : '<p class="stock-allok">Ainda não há produtos registados.</p>')}`;
    const go = box.querySelector('[data-section-go]');
    if (go) go.onclick = () => showSection('stock');
  }

  /* ---- eventos (delegação: sobrevive aos redesenhos) ---- */
  document.addEventListener('click', e => {
    const box = e.target.closest('.stock-editor'); if (!box) return;
    const input = box.querySelector('.stock-input');
    if (e.target.closest('.stock-step')) {
      const step = Number(e.target.closest('.stock-step').dataset.step);
      const r = parse(input.value); const cur = r.ok ? r.value : Number(box.dataset.saved);
      input.value = Math.max(0, Math.min(MAX, cur + step)); dirty(box);
    } else if (e.target.closest('.stock-save')) save(box);
    else if (e.target.closest('.stock-cancel')) { input.value = box.dataset.saved; dirty(box); }
  });
  document.addEventListener('input', e => { if (e.target.classList.contains('stock-input')) dirty(e.target.closest('.stock-editor')); });
  document.addEventListener('keydown', e => {
    if (!e.target.classList.contains('stock-input')) return;
    const box = e.target.closest('.stock-editor');
    if (e.key === 'Enter') { e.preventDefault(); save(box); }
    else if (e.key === 'Escape') { e.target.value = box.dataset.saved; dirty(box); }
  });

  document.addEventListener('gf:products-rendered', renderOverview);
  document.addEventListener('gf:profile', renderOverview);

  /* ---- atualização automática (sem pisar edições em curso) ---- */
  async function refresh() {
    if (document.hidden || typeof can !== 'function' || !can('stock')) return;
    if (document.querySelector('.stock-editor.is-dirty, .stock-editor.is-saving')) return;
    try {
      const d = await api('data.php?module=products');
      state.products = d.items; state.stockSummary = d.summary || null;
      renderProducts();
    } catch { /* silencioso: a próxima volta tenta de novo */ }
  }
  setInterval(refresh, 30000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

  window.GFStock = { cardHtml, parse, refresh, renderOverview };
})();
