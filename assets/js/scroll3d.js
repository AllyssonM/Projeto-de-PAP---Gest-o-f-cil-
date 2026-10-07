/* =========================================================
   Animação 3D controlada pelo scroll (secção #showcase)
   0 = caos · 1 = camadas organizadas · 2 = painel único
   ========================================================= */
(() => {
  const section = document.querySelector('#showcase');
  if (!section) return;

  const scene = section.querySelector('.scene');
  const title = section.querySelector('#showcase-title');
  const text = section.querySelector('#showcase-text');
  const steps = section.querySelectorAll('[data-step]');
  const copy = [
    ['Tudo espalhado.', 'Folhas de cálculo, papéis e mensagens soltas dificultam qualquer decisão.'],
    ['Tudo no seu lugar.', 'Cada movimento, cliente e produto ganha a sua camada, organizada e fácil de encontrar.'],
    ['Um só painel.', 'Saldo, entradas, clientes e estoque juntos, prontos para decidires com confiança.']
  ];

  // posição de cada camada em cada etapa: [x, y, z, rotZ] (px, px, px, graus)
  const frames = {
    'layer-base':  [[-60, 40, -80, -14], [0, 0, 0, 0],    [0, 0, 0, 0]],
    'layer-chart': [[-170, 120, 60, 22], [0, 0, 70, 0],   [0, 0, 6, 0]],
    'layer-kpis':  [[150, -150, 40, -18], [0, 0, 140, 0], [0, 0, 10, 0]],
    'layer-list':  [[190, 140, 100, 16], [0, 0, 210, 0],  [0, 0, 14, 0]],
    'layer-badge': [[-200, -170, 160, -26], [0, 0, 280, 0], [0, 0, 24, 0]]
  };
  // rotação da cena: [rotX, rotY, rotZ]
  const sceneFrames = [[8, -6, 0], [56, 0, -32], [14, -16, 0]];
  const layers = [...scene.querySelectorAll('.layer')].map(el => ({ el, f: frames[[...el.classList].find(c => frames[c])] }));

  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
  const lerp = (a, b, t) => a + (b - a) * t;
  const ease = t => t < .5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
  const mix = (list, p) => {           // p de 0 a 2
    const i = Math.min(1, Math.floor(p)), t = ease(Math.min(1, p - i));
    return list[i].map((v, k) => lerp(v, list[i + 1][k], t));
  };
  let lastStep = -1, ticking = false;

  function update() {
    ticking = false;
    let p;
    if (reduce.matches) p = 2;
    else {
      const r = section.getBoundingClientRect();
      const total = section.offsetHeight - window.innerHeight;
      p = Math.min(1, Math.max(0, -r.top / Math.max(1, total))) * 2;
    }
    const [rx, ry, rz] = mix(sceneFrames, p);
    scene.style.transform = `rotateX(${rx}deg) rotateY(${ry}deg) rotateZ(${rz}deg)`;
    layers.forEach(({ el, f }) => {
      if (!f) return;
      const [x, y, z, r] = mix(f, p);
      el.style.transform = `translate3d(${x}px, ${y}px, ${z}px) rotate(${r}deg)`;
    });
    section.style.setProperty('--progress', (p / 2).toFixed(3));

    const step = p < .66 ? 0 : p < 1.4 ? 1 : 2;
    if (step !== lastStep) {
      lastStep = step;
      title.textContent = copy[step][0];
      text.textContent = copy[step][1];
      steps.forEach(li => li.classList.toggle('active', Number(li.dataset.step) === step));
      section.dataset.step = step;
    }
  }

  const request = () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } };
  window.addEventListener('scroll', request, { passive: true });
  window.addEventListener('resize', request);
  reduce.addEventListener?.('change', request);
  update();
})();
