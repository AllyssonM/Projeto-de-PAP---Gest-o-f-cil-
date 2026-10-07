/* Animação de entrada «Oi!» → metamorfose → marca Lumina. Só na primeira visita da sessão, na página inicial.
   Tem «Saltar» (botão, clique ou Esc), respeita prefers-reduced-motion e nunca bloqueia a página se algo falhar. */
(function () {
  var KEY = 'lumina-oi';
  try { if (sessionStorage.getItem(KEY)) return; sessionStorage.setItem(KEY, '1'); } catch (e) { return; }
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var root = document.documentElement;
  var el = document.createElement('div');
  el.id = 'oi'; el.className = 'oi' + (reduce ? ' oi-reduce' : ''); el.setAttribute('role', 'status'); el.setAttribute('aria-label', 'Bem-vindo ao Lumina');
  var dots = '';
  for (var i = 0; i < 14; i++) {
    var a = (i / 14) * Math.PI * 2 + (i % 3) * .4, r = 120 + (i * 37) % 150;
    dots += '<circle class="oi-p" style="--x:' + Math.round(300 + Math.cos(a) * r * 1.5) + 'px;--y:' + Math.round(150 + Math.sin(a) * r * .8) + 'px;--d:' + (i * 60) + 'ms" cx="0" cy="0" r="' + (2 + i % 3) + '"/>';
  }
  el.innerHTML =
    '<button type="button" class="oi-skip">Saltar</button>' +
    '<svg class="oi-svg" viewBox="0 0 600 300" aria-hidden="true" focusable="false">' +
    '<defs><linearGradient id="oi-g" gradientUnits="userSpaceOnUse" x1="110" y1="60" x2="460" y2="240"><stop offset="0" style="stop-color:var(--primary)"/><stop offset="1" style="stop-color:var(--blue)"/></linearGradient></defs>' +
    '<g class="oi-dots">' + dots + '</g>' +
    '<g class="oi-glow" fill="none" stroke="url(#oi-g)" stroke-width="16" stroke-linecap="round">' +
    '<circle class="oi-s oi-o" cx="190" cy="160" r="66"/>' +
    '<line class="oi-s oi-i" x1="300" y1="125" x2="300" y2="205"/><circle class="oi-s oi-id" cx="300" cy="82" r="3" fill="url(#oi-g)"/>' +
    '<line class="oi-s oi-e" x1="400" y1="85" x2="400" y2="165"/><circle class="oi-s oi-ed" cx="400" cy="205" r="3" fill="url(#oi-g)"/>' +
    '</g></svg>' +
    '<div class="oi-brand"><img src="assets/img/lumina-mark-96.png" alt="" width="56" height="54"><span>Lumina</span></div>';
  document.body.appendChild(el);
  root.classList.add('oi-on');
  var done = false;
  function end() {
    if (done) return; done = true;
    el.classList.add('oi-out'); root.classList.remove('oi-on');
    document.removeEventListener('keydown', onKey);
    setTimeout(function () { el.remove(); }, 450);
  }
  function onKey(e) { if (e.key === 'Escape') end(); }
  document.addEventListener('keydown', onKey);
  el.addEventListener('click', end);
  el.querySelector('.oi-skip').addEventListener('click', function (e) { e.stopPropagation(); end(); });
  setTimeout(end, reduce ? 1500 : 3900);
  setTimeout(end, 6000);
})();
