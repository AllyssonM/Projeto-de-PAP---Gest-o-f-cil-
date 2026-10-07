/* =========================================================
   Calculadora normal (botões + teclado)
   ========================================================= */
(() => {
  const panel = $('#calc-standard');
  if (!panel) return;

  const display = $('#calc-display');
  const expr = $('#calc-expr');
  const symbols = { '+': '+', '-': '−', '*': '×', '/': '÷' };
  let current = '0';        // número a ser escrito
  let stored = null;        // valor anterior
  let op = null;            // operação pendente
  let fresh = false;        // o próximo dígito começa um número novo

  const fmt = n => {
    if (!isFinite(n)) return 'Erro';
    const r = Math.round(n * 1e10) / 1e10;
    return String(r).replace('.', ',');
  };
  const value = () => Number(current);

  function show() {
    display.textContent = current === 'Erro' ? 'Erro' : current.replace('.', ',');
    expr.innerHTML = stored !== null && op ? `${fmt(stored)} ${symbols[op]}` : '&nbsp;';
  }

  function compute(a, b, o) {
    if (o === '+') return a + b;
    if (o === '-') return a - b;
    if (o === '*') return a * b;
    if (o === '/') return b === 0 ? NaN : a / b;
    return b;
  }

  function press(key) {
    if (current === 'Erro' && key !== 'clear') { current = '0'; stored = null; op = null; }
    if (/^\d$/.test(key)) {
      if (fresh || current === '0') { current = key; fresh = false; }
      else if (current.replace(/[-.]/g, '').length < 15) current += key;
    } else if (key === '.') {
      if (fresh) { current = '0.'; fresh = false; }
      else if (!current.includes('.')) current += '.';
    } else if (key in symbols) {
      if (op && !fresh) stored = compute(stored, value(), op);
      else if (stored === null || !op) stored = value();
      op = key; fresh = true;
      current = fmt(stored).replace(',', '.');
    } else if (key === 'equals') {
      if (op === null) return show();
      const result = compute(stored, value(), op);
      expr.textContent = `${fmt(stored)} ${symbols[op]} ${fmt(value())} =`;
      current = isFinite(result) ? fmt(result).replace(',', '.') : 'Erro';
      stored = null; op = null; fresh = true;
      display.textContent = current.replace('.', ',');
      return;
    } else if (key === 'clear') {
      current = '0'; stored = null; op = null; fresh = false;
    } else if (key === 'back') {
      if (fresh) return;
      current = current.length > 1 && !(current.length === 2 && current.startsWith('-')) ? current.slice(0, -1) : '0';
    } else if (key === 'percent') {
      // 200 + 10% = 220 ; 50% sozinho = 0,5
      const v = stored !== null && op && (op === '+' || op === '-') ? stored * value() / 100 : value() / 100;
      current = fmt(v).replace(',', '.'); fresh = false;
    } else if (key === 'negate') {
      if (current !== '0') current = current.startsWith('-') ? current.slice(1) : '-' + current;
    }
    show();
  }

  panel.querySelectorAll('[data-key]').forEach(b => b.addEventListener('click', () => {
    press(b.dataset.key);
    b.classList.add('pressed'); setTimeout(() => b.classList.remove('pressed'), 120);
  }));

  // teclado: funciona quando a aba Calculadora está aberta e não se está a escrever num campo
  const map = { Enter: 'equals', '=': 'equals', Escape: 'clear', Delete: 'clear', Backspace: 'back', ',': '.', '.': '.', '%': 'percent', '+': '+', '-': '-', '*': '*', x: '*', '/': '/' };
  document.addEventListener('keydown', e => {
    if ($('#calculator').classList.contains('hidden')) return;
    if (e.target.matches('input,textarea,select') || e.ctrlKey || e.metaKey || e.altKey) return;
    const key = /^\d$/.test(e.key) ? e.key : map[e.key];
    if (!key) return;
    e.preventDefault();
    press(key);
    const btn = panel.querySelector(`[data-key="${CSS.escape(key)}"]`);
    if (btn) { btn.classList.add('pressed'); setTimeout(() => btn.classList.remove('pressed'), 120); }
  });

  $('#calc-to-cost').onclick = () => {
    const v = Number(current);
    if (!isFinite(v) || v < 0) return msg('O resultado não pode ser usado como custo.', true);
    const cost = $('#calc-cost');
    cost.value = Math.round(v * 100) / 100;
    cost.dispatchEvent(new Event('input'));
    cost.focus();
    msg('Resultado copiado para o custo do produto.');
  };

  show();
})();
