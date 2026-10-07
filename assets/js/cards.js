/* =========================================================
   CARTÕES ASSOCIADOS + JANELA "ADICIONAR CARTÃO" (demonstração)

   SEGURANÇA
   - O número completo e o CVV/CVC ficam SÓ neste navegador, nos campos do formulário.
     Nunca são enviados, guardados (localStorage, cookies, base de dados) nem registados.
   - Para o servidor só vai: bandeira, últimos 4 dígitos, validade, titular e "principal".
   - Quando o cartão é guardado (ou a janela fecha) o número e o CVV são apagados e o cartão
     passa a mostrar •••• •••• •••• 1234.
   - Não há integração de pagamentos: nenhum pagamento é feito nem aprovado por um banco.
   Usa do app.js: api() e a variável csrf.
   ========================================================= */
(function () {
  'use strict';
  const list = $('#cards-list'), addBtn = $('#card-add-btn'), modal = $('#card-modal');
  if (!list || !addBtn || !modal) return;

  const dlg = $('#cm-dialog'), form = $('#cm-form'), xBtn = $('#cm-x'), saveBtn = $('#cm-save'), alertEl = $('#cm-alert');
  const fHolder = $('#cm-holder'), fNumber = $('#cm-number'), fExp = $('#cm-exp'), fCvv = $('#cm-cvv'), fPrimary = $('#cm-primary');
  const cc = $('#cc'), tilt = $('#cc-tilt'), scene = $('#cc-scene');
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  /* ---------------- bandeiras ---------------- */
  const BRANDS = {
    visa:       { label: 'VISA',       len: 16, cvv: 3, groups: [4, 4, 4, 4] },
    mastercard: { label: 'Mastercard', len: 16, cvv: 3, groups: [4, 4, 4, 4] },
    amex:       { label: 'AMEX',       len: 15, cvv: 4, groups: [4, 6, 5] }
  };
  function detect(d) {
    if (/^4/.test(d)) return 'visa';
    if (/^3[47]/.test(d)) return 'amex';
    if (/^5[1-5]/.test(d)) return 'mastercard';
    if (d.length >= 4) { const n = +d.slice(0, 4); if (n >= 2221 && n <= 2720) return 'mastercard'; }
    return '';
  }
  const groupsOf = brand => (BRANDS[brand] || BRANDS.visa).groups;
  const maxLen = brand => (BRANDS[brand] || BRANDS.visa).len;
  function luhn(d) {
    let s = 0;
    for (let i = 0; i < d.length; i++) {
      let n = +d[d.length - 1 - i];
      if (i % 2 === 1) { n *= 2; if (n > 9) n -= 9; }
      s += n;
    }
    return d.length > 0 && s % 10 === 0;
  }

  /* ---------------- estado do formulário (só em memória) ---------------- */
  let digits = '';           // número do cartão: nunca sai deste ficheiro/navegador
  let masked = false;        // depois de guardar, o cartão só mostra os últimos 4 dígitos
  let last4 = '';
  let busy = false, isOpen = false, lastFocus = null, closeTimer = 0;

  /* ---------------- cartão digital ---------------- */
  const numEl = $('#cc-number'), nameEl = $('#cc-name'), expEl = $('#cc-exp'), brandEl = $('#cc-brand'), cvvEl = $('#cc-cvv');
  let builtFor = '';

  function buildNumber(brand) {
    const key = brand || 'x';
    if (builtFor === key) return;
    builtFor = key;
    numEl.replaceChildren();
    groupsOf(brand).forEach(n => {
      const g = document.createElement('span');
      for (let i = 0; i < n; i++) { const c = document.createElement('i'); c.className = 'ph'; c.textContent = '•'; g.appendChild(c); }
      numEl.appendChild(g);
    });
  }
  function renderNumber(prevLen) {
    const brand = detect(digits);
    buildNumber(brand);
    const total = maxLen(brand);
    const chars = numEl.querySelectorAll('i');
    chars.forEach((el, idx) => {
      let ch = '•', real = false;
      if (masked) { if (idx >= total - 4) { ch = last4[idx - (total - 4)] || '•'; real = true; } }
      else if (idx < digits.length) { ch = digits[idx]; real = true; }
      if (el.textContent !== ch) {
        el.textContent = ch; el.classList.toggle('ph', !real);
        if (real && !masked && idx >= prevLen) { el.classList.remove('pop'); void el.offsetWidth; el.classList.add('pop'); }
      }
      el.classList.toggle('ph', !real);
    });
    cc.dataset.brand = brand;
    const label = brand ? BRANDS[brand].label : '';
    if (brandEl.textContent !== label) brandEl.textContent = label;
    const tag = $('#cm-brandtag'); tag.textContent = label; tag.classList.toggle('on', !!brand);
  }
  function renderName() {
    const v = fHolder.value.trim().toUpperCase() || 'NOME DO TITULAR';
    if (nameEl.textContent !== v) { nameEl.textContent = v; nameEl.classList.remove('cc-name-pop'); void nameEl.offsetWidth; nameEl.classList.add('cc-name-pop'); }
    nameEl.style.opacity = fHolder.value.trim() ? '1' : '.55';
  }
  function renderExp() {
    const v = fExp.value || 'MM/AA';
    if (expEl.textContent !== v) { expEl.textContent = v; expEl.classList.remove('cc-exp-pop'); void expEl.offsetWidth; expEl.classList.add('cc-exp-pop'); }
    expEl.style.opacity = fExp.value ? '1' : '.55';
  }
  function renderCvv() { cvvEl.textContent = '•'.repeat(fCvv.value.length); }     // o verso mostra pontos, nunca os dígitos

  /* ---------------- formatação dos campos ---------------- */
  function groupDigits(d, brand) {
    const out = []; let i = 0;
    for (const n of groupsOf(brand)) { if (i >= d.length) break; out.push(d.slice(i, i + n)); i += n; }
    return out.join(' ');
  }
  fNumber.addEventListener('input', () => {
    const raw = fNumber.value, caret = fNumber.selectionStart ?? raw.length;
    const before = raw.slice(0, caret).replace(/\D/g, '').length;
    const prev = digits.length;
    let d = raw.replace(/\D/g, '').slice(0, 19);
    d = d.slice(0, maxLen(detect(d)));
    digits = d;
    const text = groupDigits(d, detect(d));
    fNumber.value = text;
    let pos = 0, count = 0;
    while (pos < text.length && count < before) { if (/\d/.test(text[pos])) count++; pos++; }
    try { fNumber.setSelectionRange(pos, pos); } catch (e) {}
    clearErr('number'); renderNumber(prev);
    const cvvMax = digits && BRANDS[detect(digits)] ? BRANDS[detect(digits)].cvv : 4;
    fCvv.maxLength = cvvMax; if (fCvv.value.length > cvvMax) { fCvv.value = fCvv.value.slice(0, cvvMax); renderCvv(); }
  });
  fExp.addEventListener('input', e => {
    let d = fExp.value.replace(/\D/g, '').slice(0, 4);
    if (d.length === 1 && +d > 1) d = '0' + d;                          // 4 -> 04
    let out = d;
    if (d.length >= 3) out = d.slice(0, 2) + '/' + d.slice(2);
    else if (d.length === 2 && e.inputType !== 'deleteContentBackward') out = d + '/';
    fExp.value = out; clearErr('exp'); renderExp();
  });
  fCvv.addEventListener('input', () => { fCvv.value = fCvv.value.replace(/\D/g, ''); clearErr('cvv'); renderCvv(); });
  fHolder.addEventListener('input', () => {
    fHolder.value = fHolder.value.replace(/[^\p{L} .'\-]/gu, '').replace(/\s{2,}/g, ' ').slice(0, 40);
    clearErr('holder'); renderName();
  });

  /* ---------------- rotação do cartão ao escolher o CVV ---------------- */
  fCvv.addEventListener('focus', () => { if (!masked) cc.classList.add('is-flipped'); });
  fCvv.addEventListener('blur', () => cc.classList.remove('is-flipped'));

  /* ---------------- inclinação e reflexo ao passar o rato ---------------- */
  scene.addEventListener('pointermove', e => {
    if (e.pointerType !== 'mouse' || reduced()) return;
    const r = scene.getBoundingClientRect(), x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
    tilt.style.setProperty('--ry', ((x - .5) * 16).toFixed(1) + 'deg');
    tilt.style.setProperty('--rx', (-(y - .5) * 12).toFixed(1) + 'deg');
    cc.style.setProperty('--mx', (x * 100).toFixed(0) + '%'); cc.style.setProperty('--my', (y * 100).toFixed(0) + '%'); cc.style.setProperty('--ga', '1');
  });
  scene.addEventListener('pointerleave', () => { tilt.style.setProperty('--rx', '0deg'); tilt.style.setProperty('--ry', '0deg'); cc.style.setProperty('--ga', '0'); });

  /* ---------------- validação ---------------- */
  function setErr(name, msg) {
    const el = form.querySelector(`.cm-err[data-for="${name}"]`); if (!el) return;
    el.textContent = msg;
    const input = form.elements[name]; if (input) input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  }
  function clearErr(name) { setErr(name, ''); hideAlert(); }
  function showAlert(t) { alertEl.textContent = t; alertEl.hidden = false; }
  function hideAlert() { alertEl.hidden = true; alertEl.textContent = ''; }

  function parseExp() {
    const m = fExp.value.match(/^(\d{2})\/(\d{2})$/);
    return m ? { month: +m[1], year: 2000 + +m[2] } : null;
  }
  function validate() {
    const errs = {};
    const holder = fHolder.value.trim();
    if (holder.length < 2 || !/^\p{L}[\p{L} .'\-]*$/u.test(holder)) errs.holder = 'Indica o nome do titular.';
    const brand = detect(digits);
    if (!brand) errs.number = 'Número de cartão não reconhecido (Visa, Mastercard ou American Express).';
    else if (digits.length !== BRANDS[brand].len || !luhn(digits)) errs.number = 'Número de cartão inválido.';
    const exp = parseExp(), now = new Date();
    if (!exp || exp.month < 1 || exp.month > 12) errs.exp = 'Validade inválida (MM/AA).';
    else if (exp.year * 12 + exp.month < now.getFullYear() * 12 + now.getMonth() + 1) errs.exp = 'Este cartão já expirou.';
    else if (exp.year > now.getFullYear() + 20) errs.exp = 'Validade inválida.';
    const cvvLen = brand ? BRANDS[brand].cvv : 3;
    if (!/^\d+$/.test(fCvv.value) || fCvv.value.length !== cvvLen) errs.cvv = `O CVV tem ${cvvLen} dígitos.`;
    return errs;
  }

  /* ---------------- estados: formulário -> a guardar -> concluído ---------------- */
  function setState(state) {
    dlg.dataset.state = state;
    const order = ['form', 'processing', 'success'], at = order.indexOf(state);
    dlg.querySelectorAll('.cm-steps li').forEach(li => {
      const i = order.indexOf(li.dataset.step);
      li.classList.toggle('on', i === at); li.classList.toggle('done', i < at);
    });
    xBtn.disabled = state === 'processing';
  }

  /** Apaga o número e o CVV (campos e memória) e deixa o cartão só com os últimos 4 dígitos. */
  function wipeSensitive() {
    last4 = digits.slice(-4); masked = true;
    digits = ''; fNumber.value = ''; fCvv.value = ''; renderCvv(); renderNumber(0);
  }
  function resetForm() {
    digits = ''; last4 = ''; masked = false; builtFor = '';
    form.reset(); fCvv.maxLength = 4;
    form.querySelectorAll('.cm-err').forEach(e => { e.textContent = ''; }); form.querySelectorAll('input').forEach(i => i.setAttribute('aria-invalid', 'false'));
    hideAlert(); cc.classList.remove('is-flipped');
    renderNumber(0); renderName(); renderExp(); renderCvv();
    setState('form');
  }

  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (busy) return;
    hideAlert();
    const errs = validate();
    ['holder', 'number', 'exp', 'cvv'].forEach(k => setErr(k, errs[k] || ''));
    const bad = Object.keys(errs);
    if (bad.length) {
      const first = form.elements[bad[0]]; first && first.focus();
      bad.forEach(k => { const f = form.elements[k]?.closest('.cm-field'); if (f) { f.classList.remove('shake'); void f.offsetWidth; f.classList.add('shake'); } });
      return;
    }

    busy = true;
    const exp = parseExp();
    const payload = {                                   // tudo o que vai para o servidor
      holder: fHolder.value.trim().replace(/\s+/g, ' '), brand: detect(digits), last4: digits.slice(-4),
      exp_month: exp.month, exp_year: exp.year, is_primary: fPrimary.checked, csrf
    };
    setState('processing'); cc.classList.remove('is-flipped'); document.activeElement && document.activeElement.blur();
    try {
      const timeout = new Promise((_, rej) => setTimeout(() => rej(new Error('O pedido demorou demasiado. Tenta outra vez.')), 12000));
      const [res] = await Promise.all([Promise.race([api('cards.php', { method: 'POST', body: JSON.stringify(payload) }), timeout]), sleep(reduced() ? 300 : 1350)]);
      wipeSensitive();                                // o número completo e o CVV desaparecem aqui
      $('#cm-success-num').textContent = brandMask(res.item.brand, res.item.last4);
      setState('success');
      await sleep(reduced() ? 600 : 2100);
      await closeModal(true);
      render(res.items, res.item.id);
    } catch (err) {
      setState('form'); showAlert(err.message || 'Não foi possível guardar o cartão.');
    } finally { busy = false; }
  });

  /* ---------------- abrir / fechar ---------------- */
  function open() {
    if (isOpen) return;
    clearTimeout(closeTimer);
    lastFocus = document.activeElement; isOpen = true;
    resetForm();
    modal.classList.remove('hidden');
    requestAnimationFrame(() => requestAnimationFrame(() => { modal.classList.add('show'); fHolder.focus({ preventScroll: true }); }));
  }
  function closeModal(force) {
    if (!isOpen || (busy && !force)) return Promise.resolve();
    isOpen = false;
    modal.classList.remove('show');
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus({ preventScroll: true }); } catch (e) {} }
    return new Promise(res => {
      closeTimer = setTimeout(() => { modal.classList.add('hidden'); resetForm(); res(); }, reduced() ? 0 : 320);   // limpa tudo ao fechar
    });
  }
  addBtn.addEventListener('click', open);
  xBtn.addEventListener('click', () => closeModal());
  modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => {
    if (!isOpen) return;
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeModal(); return; }
    if (e.key === 'Tab') {
      const f = [...dlg.querySelectorAll('button, input')].filter(n => !n.disabled && n.offsetParent !== null && n.type !== 'hidden');
      if (!f.length) { e.preventDefault(); return; }
      const first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || !dlg.contains(document.activeElement))) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && (document.activeElement === last || !dlg.contains(document.activeElement))) { e.preventDefault(); first.focus(); }
    }
  }, true);
  $('#logout')?.addEventListener('click', resetForm);

  /* ---------------- lista de cartões associados (só dados mascarados) ---------------- */
  function brandMask(brand, l4) { return brand === 'amex' ? `•••• •••••• •${l4}` : `•••• •••• •••• ${l4}`; }
  const el = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };

  function render(items, newId) {
    list.replaceChildren();
    if (!items.length) { list.appendChild(el('div', 'cards-empty', 'Ainda não tens cartões associados.')); return; }
    items.forEach((c, i) => {
      const card = el('div', 'wallet-card' + (c.id === newId ? ' is-new' : '')); card.dataset.brand = c.brand; card.style.setProperty('--i', i);
      const top = el('div', 'wc-top'); top.appendChild(el('span', 'wc-brand', (BRANDS[c.brand] || { label: c.brand }).label));
      const badges = el('span', 'wc-badges');
      if (c.is_primary) badges.appendChild(el('span', 'wc-badge primary', 'Principal'));
      badges.appendChild(el('span', 'wc-badge ' + (c.status === 'active' ? 'ok' : 'expired'), { active: 'Ativo', disabled: 'Desativado', expired: 'Expirado' }[c.status] || 'Expirado'));
      if (c.status !== 'active') card.classList.add('is-off');                  // cartão desativado/expirado: aspeto apagado
      top.appendChild(badges); card.appendChild(top);
      card.appendChild(el('div', 'wc-number', brandMask(c.brand, c.last4)));
      const bottom = el('div', 'wc-bottom');
      if (c.holder) { const d = el('div'); d.appendChild(el('small', null, 'TITULAR')); d.appendChild(el('b', null, c.holder)); bottom.appendChild(d); }
      const v = el('div'); v.appendChild(el('small', null, 'VALIDADE')); v.appendChild(el('b', null, String(c.exp_month).padStart(2, '0') + '/' + String(c.exp_year).slice(-2))); bottom.appendChild(v);
      card.appendChild(bottom);
      if (list.dataset.manage === 'true') card.appendChild(manageActions(c));   // só na Área pessoal
      list.appendChild(card);
    });
  }

  /* ---------------- gestão de um cartão (Área pessoal) ----------------
     Principal / Ativar-Desativar / Remover. Desativar e remover pedem confirmação; todas as
     ações mostram um aviso (toast) no fim. O servidor volta a verificar tudo (api/cards.php). */
  async function act(action, id, okText) {
    try {
      const d = await api('cards.php', { method: 'POST', body: JSON.stringify({ action, id, csrf }) });
      render(d.items); msg(okText);
    } catch (e) { msg(e.message, true); }
  }
  async function setStatus(id, status, okText) {
    try { const d = await api('cards.php', { method: 'POST', body: JSON.stringify({ action: 'set_status', id, status, csrf }) }); render(d.items); msg(okText); }
    catch (e) { msg(e.message, true); }
  }
  function manageActions(c) {
    const row = el('div', 'wc-actions');
    const btn = (label, cls, fn) => { const b = el('button', 'button small ' + cls, label); b.type = 'button'; b.onclick = fn; row.appendChild(b); return b; };
    const last = `${(BRANDS[c.brand] || { label: c.brand }).label} •••• ${c.last4}`;
    if (c.status === 'active' && !c.is_primary) btn('Definir como principal', 'secondary', () => act('set_primary', c.id, 'Cartão principal atualizado.'));
    if (c.status === 'disabled') btn('Ativar cartão', 'primary', () => setStatus(c.id, 'active', 'Cartão ativado.'));
    if (c.status === 'active') btn('Desativar cartão', 'secondary', async () => {
      if (await UX.confirm({ title: 'Desativar este cartão?', text: `${last} fica desativado e deixa de poder ser usado até o ativares outra vez.`, confirmLabel: 'Desativar', danger: true })) setStatus(c.id, 'disabled', 'Cartão desativado com sucesso.');
    });
    btn('Remover', 'danger', async () => {
      if (await UX.confirm({ title: 'Remover este cartão?', text: `${last} deixa de estar associado à tua conta. Esta ação não se pode desfazer.`, confirmLabel: 'Remover', danger: true })) act('delete', c.id, 'Cartão removido.');
    });
    return row;
  }
  async function refresh() {
    try { render((await api('cards.php')).items); }
    catch (e) { list.replaceChildren(el('div', 'cards-empty', 'Não foi possível carregar os cartões.')); }
  }
  window.gfCards = { refresh };

  resetForm();
  refresh();
})();
