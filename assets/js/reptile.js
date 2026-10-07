/* =========================================================
   Réptil que segue o cursor (canvas, animação procedural)
   - Só em computadores com rato; desliga-se com "reduzir animações".
   - Botão 🦎 liga/desliga (fica guardado no navegador).
   ========================================================= */
(() => {
  const canvas = document.querySelector('#reptile');
  const toggle = document.querySelector('[data-reptile-toggle]');
  if (!canvas) return;

  const fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!fine || reduce) { canvas.remove(); toggle?.remove(); return; }

  const KEY = 'gf-reptile';
  const ctx = canvas.getContext('2d');
  const SEGMENTS = 30, GAP = 8.5;
  const LEGS = [{ at: 6, len: [17, 15] }, { at: 13, len: [15, 14] }];   // ombros e ancas
  let on = true;
  try { on = localStorage.getItem(KEY) !== 'off'; } catch {}

  let w = 0, h = 0, dpr = 1;
  const mouse = { x: innerWidth * .7, y: innerHeight * .6 };
  const spine = Array.from({ length: SEGMENTS }, (_, i) => ({ x: mouse.x - i * GAP, y: mouse.y }));
  let heading = 0;
  const feet = [];
  LEGS.forEach(leg => [-1, 1].forEach(side => feet.push({ leg, side, x: 0, y: 0, from: null, to: null, t: 1, placed: false })));

  function resize() {
    dpr = Math.min(2, window.devicePixelRatio || 1);
    w = innerWidth; h = innerHeight;
    canvas.width = w * dpr; canvas.height = h * dpr;
    canvas.style.width = w + 'px'; canvas.style.height = h + 'px';
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  const width = i => {                  // largura do corpo em cada segmento
    if (i < 3) return 6 + i * 1.5;                                  // cabeça
    if (i < 5) return 6;                                            // pescoço
    if (i < 13) return 8 + 3 * Math.sin(Math.PI * (i - 5) / 8);     // tronco
    return Math.max(1.2, 8 * (1 - (i - 13) / (SEGMENTS - 13)));     // cauda
  };
  const angleAt = i => {
    const a = spine[Math.max(0, i - 1)], b = spine[Math.min(SEGMENTS - 1, i + 1)];
    return Math.atan2(a.y - b.y, a.x - b.x);
  };

  function step() {
    const head = spine[0];
    const dx = mouse.x - head.x, dy = mouse.y - head.y, dist = Math.hypot(dx, dy);
    if (dist > 24) {
      // vira aos poucos para o cursor e anda mais depressa quando está longe
      const target = Math.atan2(dy, dx);
      let diff = target - heading;
      diff = Math.atan2(Math.sin(diff), Math.cos(diff));
      heading += diff * .12;
      const speed = Math.min(6.5, 1.2 + dist * .035);
      head.x += Math.cos(heading) * speed;
      head.y += Math.sin(heading) * speed;
    }
    for (let i = 1; i < SEGMENTS; i++) {
      const a = spine[i - 1], b = spine[i];
      const ang = Math.atan2(b.y - a.y, b.x - a.x);
      b.x = a.x + Math.cos(ang) * GAP;
      b.y = a.y + Math.sin(ang) * GAP;
    }

    // patas: cada pé fica no chão até ficar longe demais e depois dá um passo
    feet.forEach((f, k) => {
      const base = spine[f.leg.at], ang = angleAt(f.leg.at);
      const reach = f.leg.len[0] + f.leg.len[1] - 6;
      const ideal = {
        x: base.x + Math.cos(ang + f.side * 1.05) * reach + Math.cos(ang) * 10,
        y: base.y + Math.sin(ang + f.side * 1.05) * reach + Math.sin(ang) * 10
      };
      if (!f.placed) { f.x = ideal.x; f.y = ideal.y; f.placed = true; }
      const partner = feet[k ^ 1];
      if (f.t >= 1 && Math.hypot(ideal.x - f.x, ideal.y - f.y) > reach * .9 && partner.t >= 1) {
        f.from = { x: f.x, y: f.y };
        f.to = { x: ideal.x + Math.cos(ang) * 8, y: ideal.y + Math.sin(ang) * 8 };
        f.t = 0;
      }
      if (f.t < 1) {
        f.t = Math.min(1, f.t + .16);
        f.x = f.from.x + (f.to.x - f.from.x) * f.t;
        f.y = f.from.y + (f.to.y - f.from.y) * f.t;
      }
    });
  }

  function drawLeg(f) {
    const base = spine[f.leg.at], [l1, l2] = f.leg.len;
    const dx = f.x - base.x, dy = f.y - base.y;
    const d = Math.min(l1 + l2 - .01, Math.hypot(dx, dy));
    const a = Math.atan2(dy, dx);
    const bend = Math.acos(Math.max(-1, Math.min(1, (l1 * l1 + d * d - l2 * l2) / (2 * l1 * d || 1))));
    const knee = { x: base.x + Math.cos(a - f.side * bend) * l1, y: base.y + Math.sin(a - f.side * bend) * l1 };
    ctx.beginPath();
    ctx.moveTo(base.x, base.y); ctx.lineTo(knee.x, knee.y); ctx.lineTo(f.x, f.y);
    ctx.lineWidth = 4.5; ctx.stroke();
    // dedos
    const fa = Math.atan2(f.y - knee.y, f.x - knee.x);
    for (let t = -1; t <= 1; t++) {
      ctx.beginPath();
      ctx.moveTo(f.x, f.y);
      ctx.lineTo(f.x + Math.cos(fa + t * .55) * 6, f.y + Math.sin(fa + t * .55) * 6);
      ctx.lineWidth = 1.8; ctx.stroke();
    }
  }

  function draw() {
    ctx.clearRect(0, 0, w, h);
    const light = document.documentElement.dataset.tema === 'light';
    const body = light ? '#139c7d' : '#5ee0bd';
    const dark = light ? '#0b6b55' : '#2a9d82';
    ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    ctx.strokeStyle = dark;
    feet.forEach(drawLeg);

    // contorno do corpo
    const left = [], right = [];
    spine.forEach((p, i) => {
      const a = angleAt(i) + Math.PI / 2, r = width(i);
      left.push([p.x + Math.cos(a) * r, p.y + Math.sin(a) * r]);
      right.push([p.x - Math.cos(a) * r, p.y - Math.sin(a) * r]);
    });
    const head = spine[0], ha = angleAt(0);
    ctx.beginPath();
    ctx.moveTo(head.x + Math.cos(ha) * 9, head.y + Math.sin(ha) * 9);
    left.forEach(([x, y]) => ctx.lineTo(x, y));
    right.reverse().forEach(([x, y]) => ctx.lineTo(x, y));
    ctx.closePath();
    const g = ctx.createLinearGradient(head.x, head.y, spine[SEGMENTS - 1].x, spine[SEGMENTS - 1].y);
    g.addColorStop(0, body); g.addColorStop(1, dark);
    ctx.fillStyle = g;
    ctx.shadowColor = 'rgba(0,0,0,.25)'; ctx.shadowBlur = 10; ctx.shadowOffsetY = 4;
    ctx.fill();
    ctx.shadowColor = 'transparent';

    // manchas nas costas
    ctx.fillStyle = light ? 'rgba(255,255,255,.35)' : 'rgba(6,34,29,.35)';
    for (let i = 6; i < SEGMENTS - 6; i += 3) {
      ctx.beginPath(); ctx.arc(spine[i].x, spine[i].y, Math.max(1, width(i) * .28), 0, Math.PI * 2); ctx.fill();
    }

    // olhos
    [-1, 1].forEach(s => {
      const ex = head.x + Math.cos(ha) * 3 + Math.cos(ha + s * Math.PI / 2) * 5;
      const ey = head.y + Math.sin(ha) * 3 + Math.sin(ha + s * Math.PI / 2) * 5;
      ctx.fillStyle = '#06221d'; ctx.beginPath(); ctx.arc(ex, ey, 2.3, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(ex + .6, ey - .6, .8, 0, Math.PI * 2); ctx.fill();
    });
  }

  let raf = null;
  function loop() { step(); draw(); raf = requestAnimationFrame(loop); }
  function start() { if (!raf && on && !document.hidden) { canvas.hidden = false; raf = requestAnimationFrame(loop); } }
  function stop() { cancelAnimationFrame(raf); raf = null; }

  function set(value) {
    on = value;
    try { localStorage.setItem(KEY, on ? 'on' : 'off'); } catch {}
    toggle?.setAttribute('aria-pressed', String(on));
    if (on) start(); else { stop(); ctx.clearRect(0, 0, w, h); canvas.hidden = true; }
  }

  window.addEventListener('pointermove', e => { mouse.x = e.clientX; mouse.y = e.clientY; }, { passive: true });
  window.addEventListener('resize', resize);
  document.addEventListener('visibilitychange', () => document.hidden ? stop() : start());
  toggle?.addEventListener('click', () => set(!on));

  resize();
  set(on);
})();
