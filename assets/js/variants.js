/* Estoque com tamanhos e cores (assets/js/variants.js)
   - Nos ramos com variações (loja de roupa, loja online) cada produto tem uma lista de variações: tamanho + cor + quantidade (+ preço, opcional).
   - Formulário do produto: editor de variações, validação antes de guardar, aviso de produto repetido (mesmo nome e marca),
     modo "editar" (o mesmo formulário, preenchido) e cancelar edição.
   - Lista: cartões com as variações, estados (Disponível, Stock reduzido, Esgotado, Inativo), pesquisa, filtros e ordenação.
   - Apagar produto ou variação pede confirmação (só o dono).
   Usa os globais de app.js: state, api, csrf, msg, esc, money, loadAll, GF. */
(() => {
  const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'Único', '36', '37', '38', '39', '40', '41', '42', '43', '44', '45'];
  const COLORS = ['Preto', 'Branco', 'Cinzento', 'Azul', 'Vermelho', 'Verde', 'Amarelo', 'Rosa', 'Bege', 'Castanho'];
  const ramo = () => (window.GFP && window.GFP.current && window.GFP.current()) || null;
  const active = () => !!(ramo() && ramo().variants);
  const $v = s => document.querySelector(s);
  const isOwner = () => !window.GF || window.GF.isOwner !== false;
  let editingId = null;

  /* ---------------------------------------------------------- estado de um produto / variação */
  const stateOf = p => p.status === 'inactive' ? 'inactive' : Number(p.stock_quantity) <= 0 ? 'out'
    : Number(p.stock_quantity) <= Number(p.minimum_stock) ? 'low' : 'ok';
  const LABEL = { ok: 'Disponível', low: 'Stock reduzido', out: 'Esgotado', inactive: 'Inativo' };
  const vState = (v, p) => p.status === 'inactive' || v.status === 'inactive' ? 'inactive' : Number(v.quantity) <= 0 ? 'out'
    : Number(v.quantity) <= Number(p.minimum_stock) ? 'low' : 'ok';
  const badge = st => `<span class="stock-badge ${st === 'inactive' ? 'out' : st}">${LABEL[st]}</span>`;

  /* ---------------------------------------------------------- cartão do produto */
  function cardHtml(x) {
    const st = stateOf(x), vs = x.variants || [];
    const price = Number(x.sale_price) || 0;
    const rows = vs.map(v => `<tr class="v-${vState(v, x)}"><td>${esc(v.size || '—')}</td><td>${esc(v.color || '—')}</td><td class="num">${Number(v.quantity)}</td>
        <td>${badge(vState(v, x))}</td><td class="num">${v.price !== null && v.price !== undefined ? money.format(Number(v.price)) : ''}</td>
        <td>${isOwner() && vs.length > 1 ? `<button type="button" class="delete-button" data-var-del="${v.id}" aria-label="Apagar a variação ${esc((v.size || '') + ' ' + (v.color || ''))}" title="Apagar variação">×</button>` : ''}</td></tr>`).join('');
    return `<div class="product-card variants-card is-${st}" data-product-id="${Number(x.id)}">
      ${x.image_url ? `<img class="product-thumb" src="${esc(x.image_url)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : ''}
      <h3>${esc(x.name)}</h3>
      <p>${esc(x.sku || 'Sem referência')} · ${esc(x.category || 'Sem categoria')}${x.brand ? ' · ' + esc(x.brand) : ''}</p>
      <p class="variants-sum">${badge(st)} <span><b>${Number(x.stock_quantity)}</b> un. no total</span></p>
      <p class="product-price">Preço de venda: <b>${money.format(price)}</b></p>
      <div class="variants-scroll"><table class="variants-table"><thead><tr><th>Tamanho</th><th>Cor</th><th class="num">Qtd.</th><th>Estado</th><th class="num">Preço</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>
      <div class="variants-actions"><button type="button" class="button ghost small" data-prod-edit="${Number(x.id)}">Editar</button>
        ${isOwner() ? `<button type="button" class="button ghost small danger" data-prod-del="${Number(x.id)}">Apagar</button>` : ''}</div>
    </div>`;
  }

  /* ---------------------------------------------------------- pesquisa, filtros, ordenação */
  const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
  function filters() {
    const f = $v('#stock-filters'); if (!f) return {};
    return Object.fromEntries(new FormData(f));
  }
  function apply(list) {
    const f = filters(), q = norm(f.q);
    let out = list.filter(p => {
      const vs = p.variants || [];
      if (q && !norm([p.name, p.brand, p.sku, p.category].join(' ')).includes(q)) return false;
      if (f.category && p.category !== f.category) return false;
      if (f.brand && p.brand !== f.brand) return false;
      if (f.size && !vs.some(v => v.size === f.size)) return false;
      if (f.color && !vs.some(v => v.color === f.color)) return false;
      if (f.avail && stateOf(p) !== f.avail) return false;
      return true;
    });
    const key = { name: p => norm(p.name), qty: p => -Number(p.stock_quantity), price: p => Number(p.sale_price), date: p => -Date.parse(String(p.updated_at).replace(' ', 'T')) || 0 }[f.sort || 'name'];
    out = out.slice().sort((a, b) => { const x = key(a), y = key(b); return x < y ? -1 : x > y ? 1 : norm(a.name) < norm(b.name) ? -1 : 1; });
    return out;
  }
  function fillFilterOptions() {
    const f = $v('#stock-filters'); if (!f) return;
    const list = state.products || [];
    const opts = {
      category: [...new Set(list.map(p => p.category).filter(Boolean))],
      brand: [...new Set(list.map(p => p.brand).filter(Boolean))],
      size: [...new Set(list.flatMap(p => (p.variants || []).map(v => v.size)).filter(Boolean))],
      color: [...new Set(list.flatMap(p => (p.variants || []).map(v => v.color)).filter(Boolean))],
    };
    for (const [name, values] of Object.entries(opts)) {
      const sel = f.elements[name]; if (!sel) continue; const cur = sel.value;
      sel.innerHTML = sel.options[0].outerHTML + values.sort((a, b) => a.localeCompare(b, 'pt', { numeric: true })).map(v => `<option>${esc(v)}</option>`).join('');
      sel.value = values.includes(cur) ? cur : '';
    }
    f.classList.toggle('ramo-off', !active());
  }
  function countLine(shown, total) {
    const el = $v('#stock-count'); if (!el) return;
    el.hidden = !active() || !total; el.textContent = shown === total ? `${total} produto${total === 1 ? '' : 's'}` : `${shown} de ${total} produtos`;
  }

  /* ---------------------------------------------------------- editor de variações */
  const datalists = `<datalist id="dl-sizes">${SIZES.map(s => `<option value="${s}">`).join('')}</datalist><datalist id="dl-colors">${COLORS.map(s => `<option value="${s}">`).join('')}</datalist>`;
  function rowHtml(v = {}) {
    return `<div class="variant-row" data-vid="${v.id || ''}" data-expected="${v.id ? Number(v.quantity) : ''}">
      <label><span>Tamanho</span><input name="v_size" list="dl-sizes" maxlength="20" value="${esc(v.size || '')}" placeholder="Ex.: M"></label>
      <label><span>Cor</span><input name="v_color" list="dl-colors" maxlength="40" value="${esc(v.color || '')}" placeholder="Ex.: Preto"></label>
      <label><span>Quantidade</span><input name="v_qty" type="number" min="0" step="1" inputmode="numeric" value="${v.quantity ?? 0}" required></label>
      <label><span>Preço (opcional)</span><input name="v_price" type="number" min="0" step="0.01" inputmode="decimal" value="${v.price ?? ''}" placeholder="igual ao produto"></label>
      <button type="button" class="delete-button" data-row-del aria-label="Retirar esta variação" title="Retirar">×</button></div>`;
  }
  function addRow(v) { const box = $v('#variants-rows'); if (box) box.insertAdjacentHTML('beforeend', rowHtml(v)); }
  function resetRows() { const box = $v('#variants-rows'); if (!box) return; box.innerHTML = datalists + rowHtml(); }

  function collect() {
    const rows = [...document.querySelectorAll('#variants-rows .variant-row')];
    const variants = rows.map(r => ({
      id: r.dataset.vid ? Number(r.dataset.vid) : undefined,
      size: r.querySelector('[name=v_size]').value.trim(), color: r.querySelector('[name=v_color]').value.trim(),
      quantity: r.querySelector('[name=v_qty]').value.trim(), price: r.querySelector('[name=v_price]').value.trim(),
    }));
    const expected = {}; rows.forEach(r => { if (r.dataset.vid && r.dataset.expected !== '') expected[r.dataset.vid] = Number(r.dataset.expected); });
    return { variants, expected };
  }

  /** Validação antes de enviar (o servidor valida outra vez). Devolve a mensagem de erro ou ''. */
  function validate(body) {
    if (!String(body.name || '').trim()) return 'Indica o nome do produto.';
    if (body.sale_price === '' || isNaN(Number(String(body.sale_price).replace(',', '.'))) || Number(String(body.sale_price).replace(',', '.')) < 0) return 'O preço de venda não é um valor válido.';
    if (body.cost_price !== '' && (isNaN(Number(String(body.cost_price).replace(',', '.'))) || Number(String(body.cost_price).replace(',', '.')) < 0)) return 'O preço de custo não é um valor válido.';
    if (!body.variants.length) return 'Acrescenta pelo menos uma variação.';
    const seen = new Set();
    for (const v of body.variants) {
      if (!/^\d+$/.test(v.quantity)) return 'A quantidade tem de ser um número inteiro igual ou superior a zero.';
      if (v.price !== '' && (isNaN(Number(String(v.price).replace(',', '.'))) || Number(String(v.price).replace(',', '.')) < 0)) return 'O preço de uma variação não é válido.';
      const k = norm(v.size) + '|' + norm(v.color);
      if (seen.has(k)) return 'Há duas variações com o mesmo tamanho e cor. Junta-as numa só.';
      seen.add(k);
    }
    return '';
  }

  const errBox = () => $v('#product-form-error');
  function showError(html) { const b = errBox(); if (!b) return; b.innerHTML = html; b.hidden = !html; }

  /* ---------------------------------------------------------- guardar (novo ou edição) */
  async function submit(form) {
    const body = { ...Object.fromEntries(new FormData(form)), csrf };
    for (const k of Object.keys(body)) if (k.startsWith('attr_') || k.startsWith('v_')) delete body[k];
    body.attributes = window.GFP.collectAttrs(form);
    Object.assign(body, collect());
    delete body.stock_quantity;                                       // a quantidade vem das variações
    const bad = validate(body);
    if (bad) { showError(esc(bad)); return; }
    showError('');
    const btn = $v('#product-save'), label = btn.textContent;
    btn.disabled = true; btn.textContent = 'A guardar…'; form.classList.add('is-saving');
    try {
      const editing = editingId !== null;
      if (editing) body.id = editingId;
      await api(`data.php?module=${editing ? 'product_update' : 'products'}`, { method: 'POST', body: JSON.stringify(body) });
      msg(editing ? 'Produto atualizado com sucesso.' : 'Produto guardado com sucesso.');
      window.burstHearts?.(btn);
      stopEdit(); await loadAll();
    } catch (e) {
      const dup = e && e.data && e.data.duplicate_id;
      if (dup) showError(`${esc(e.message)} <button type="button" class="button ghost small" data-prod-edit="${Number(dup)}">Editar o existente</button>`);
      else if (e && e.data && e.data.conflict) { showError(esc(e.message)); await loadAll(); }
      else showError(esc(e.message || 'Não foi possível guardar o produto.'));
      msg(e.message || 'Não foi possível guardar o produto.', true);
    } finally { btn.disabled = false; btn.textContent = editingId !== null ? 'Guardar alterações' : label.replace('A guardar…', 'Guardar produto'); form.classList.remove('is-saving'); }
  }

  /* ---------------------------------------------------------- modo editar */
  function startEdit(id) {
    const p = (state.products || []).find(x => Number(x.id) === Number(id)); if (!p) return;
    const form = $v('#product-form'); if (!form) return;
    editingId = Number(id);
    form.elements.name.value = p.name || ''; form.elements.sku.value = p.sku || ''; form.elements.category.value = p.category || '';
    if (form.elements.brand) form.elements.brand.value = p.brand || '';
    form.elements.cost_price.value = p.cost_price ?? ''; form.elements.sale_price.value = p.sale_price ?? '';
    form.elements.minimum_stock.value = p.minimum_stock ?? 0;
    if (form.elements.status) form.elements.status.value = p.status === 'inactive' ? 'inactive' : 'active';
    if (form.elements.image_url) form.elements.image_url.value = p.image_url || '';
    const attrs = p.attributes || {};
    for (const f of window.GFP.current().fields) { const el = form.elements['attr_' + f.key]; if (el) el.value = attrs[f.key] || ''; }
    const box = $v('#variants-rows'); if (box) { box.innerHTML = datalists; (p.variants || []).forEach(v => addRow(v)); }
    $v('#product-form-title').textContent = 'Editar produto'; $v('#product-save').textContent = 'Guardar alterações'; $v('#product-cancel-edit').hidden = false;
    showError(''); form.classList.add('is-editing');
    form.scrollIntoView({ behavior: 'smooth', block: 'center' }); form.elements.name.focus({ preventScroll: true });
  }
  function stopEdit() {
    editingId = null; const form = $v('#product-form'); if (!form) return;
    form.reset(); form.classList.remove('is-editing'); resetRows(); showError('');
    $v('#product-form-title').textContent = window.GFP?.t ? window.GFP.t('stockNewTitle', 'Adicionar produto') : 'Adicionar produto';
    $v('#product-save').textContent = 'Guardar produto'; $v('#product-cancel-edit').hidden = true;
    window.GFP?.refreshForm?.();
  }

  /* ---------------------------------------------------------- apagar (com confirmação) */
  async function del(kind, id) {
    const p = kind === 'product' ? (state.products || []).find(x => Number(x.id) === id) : null;
    const ok = await UX.confirm({ title: kind === 'product' ? 'Apagar este produto?' : 'Apagar esta variação?',
      text: kind === 'product' ? `«${p ? p.name : 'Produto'}» deixa de aparecer no estoque e nas vendas. O histórico das vendas já feitas mantém-se.` : 'O estoque desta variação deixa de contar. O histórico das vendas já feitas mantém-se.',
      confirmLabel: 'Apagar', cancelLabel: 'Cancelar', danger: true });
    if (!ok) return;
    try {
      await api(`data.php?module=${kind === 'product' ? 'product_delete' : 'variant_delete'}`, { method: 'POST', body: JSON.stringify({ id, csrf }) });
      msg(kind === 'product' ? 'Produto apagado.' : 'Variação apagada.');
      if (editingId === id) stopEdit(); await loadAll();
    } catch (e) { msg(e.message || 'Não foi possível apagar.', true); }
  }

  /* ---------------------------------------------------------- eventos */
  document.addEventListener('click', e => {
    const t = e.target;
    if (t.closest('#variant-add')) { addRow(); const rows = document.querySelectorAll('#variants-rows .variant-row'); rows[rows.length - 1]?.querySelector('input')?.focus(); return; }
    const rm = t.closest('[data-row-del]');
    if (rm) { const rows = document.querySelectorAll('#variants-rows .variant-row'); const row = rm.closest('.variant-row');
      if (rows.length <= 1) { showError('Um produto precisa de pelo menos uma variação.'); return; }
      if (row.dataset.vid) { del('variant', Number(row.dataset.vid)); return; } row.remove(); return; }
    const ed = t.closest('[data-prod-edit]'); if (ed) { startEdit(Number(ed.dataset.prodEdit)); return; }
    const dp = t.closest('[data-prod-del]'); if (dp) { del('product', Number(dp.dataset.prodDel)); return; }
    const dv = t.closest('[data-var-del]'); if (dv) { del('variant', Number(dv.dataset.varDel)); return; }
    if (t.closest('#product-cancel-edit')) stopEdit();
  });
  document.addEventListener('input', e => { if (e.target.closest && e.target.closest('#stock-filters')) window.renderProducts && window.renderProducts(); });
  document.addEventListener('change', e => { if (e.target.closest && e.target.closest('#stock-filters')) window.renderProducts && window.renderProducts(); });
  document.addEventListener('submit', e => { if (e.target.id === 'stock-filters') e.preventDefault(); });

  function init() {
    const form = $v('#product-form'); if (!form) return;
    resetRows();
    form.onsubmit = e => { e.preventDefault(); if (active()) submit(form); else window.submitForm(form, 'products'); };
  }
  document.addEventListener('DOMContentLoaded', init);
  if (document.readyState !== 'loading') init();
  document.addEventListener('gf:products-rendered', fillFilterOptions);
  document.addEventListener('gf:profile', fillFilterOptions);

  window.GFVar = { active, cardHtml, apply, countLine, stateOf, startEdit, collect, validate,
    /** Variações que se podem vender: produto ativo, variação ativa e com unidades. */
    sellable: list => (list || []).filter(p => p.status === 'active').map(p => ({ ...p, variants: (p.variants || []).filter(v => v.status === 'active' && Number(v.quantity) > 0) })).filter(p => p.variants.length) };
})();
