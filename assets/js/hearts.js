/* =========================================================
   Corações que sobem a partir de um elemento (ex.: ao guardar
   um cliente). Uso: burstHearts(elemento)
   ========================================================= */
window.burstHearts = function (origin, count = 10) {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const r = origin?.getBoundingClientRect?.() || { left: innerWidth / 2, top: innerHeight / 2, width: 0, height: 0 };
  const x = r.left + r.width / 2, y = r.top + r.height / 2;
  const colors = ['#ff5d7a', '#ff8fa3', '#5ee0bd', '#ffd477', '#ff6b9a'];
  for (let i = 0; i < count; i++) {
    const h = document.createElement('span');
    h.className = 'heart-burst';
    h.textContent = '❤';
    h.setAttribute('aria-hidden', 'true');
    h.style.left = x + 'px';
    h.style.top = y + 'px';
    h.style.color = colors[i % colors.length];
    h.style.setProperty('--dx', `${(Math.random() - .5) * 220}px`);
    h.style.setProperty('--dy', `${-90 - Math.random() * 160}px`);
    h.style.setProperty('--rot', `${(Math.random() - .5) * 70}deg`);
    h.style.setProperty('--size', `${14 + Math.random() * 16}px`);
    h.style.animationDelay = `${i * 35}ms`;
    document.body.appendChild(h);
    h.addEventListener('animationend', () => h.remove());
  }
};
