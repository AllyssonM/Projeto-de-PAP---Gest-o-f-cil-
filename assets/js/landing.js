/* =========================================================
   Landing page: menu móvel, animações, Spline (opcional)
   e login/registo animados ligados à API PHP (api/auth.php)
   ========================================================= */
document.addEventListener('DOMContentLoaded', () => {

  /* ---- menu no telemóvel ---- */
  const menuBtn = document.querySelector('[data-menu-toggle]');
  const nav = document.querySelector('.main-nav');
  menuBtn?.addEventListener('click', () => nav.classList.toggle('open'));
  nav?.querySelectorAll('a').forEach(a => a.addEventListener('click', () => nav.classList.remove('open')));

  /* ---- animação ao fazer scroll ---- */
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => { if (entry.isIntersecting) entry.target.classList.add('visible'); });
  }, { threshold: .12 });
  document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

  /* ---- Spline 3D (só carrega se data-spline-url tiver um URL) ---- */
  const visual = document.querySelector('[data-spline-url]');
  const splineUrl = visual?.dataset.splineUrl;
  if (splineUrl) {
    const script = document.createElement('script');
    script.type = 'module';
    script.src = 'https://unpkg.com/@splinetool/viewer@1.9.96/build/spline-viewer.js';
    script.onload = () => {
      const viewer = document.createElement('spline-viewer');
      viewer.setAttribute('url', splineUrl);
      visual.replaceChildren(viewer);
    };
    document.head.appendChild(script);
  }

  /* ---- login / registo com painéis deslizantes ---- */
  const box = document.querySelector('#auth-box');
  if (!box) return;                        // já tem sessão: não há formulários

  const loginForm = document.querySelector('#login-form');
  const registerForm = document.querySelector('#register-form');

  function setMode(mode, focus = false) {
    const registering = mode === 'register';
    box.classList.toggle('register-mode', registering);
    // o painel escondido não recebe foco nem é lido
    loginForm.closest('.form-panel').inert = registering;
    registerForm.closest('.form-panel').inert = !registering;
    [loginForm, registerForm].forEach(f => { f.querySelector('.message').textContent = ''; });
    if (focus) {
      const target = (registering ? registerForm : loginForm).querySelector('input');
      setTimeout(() => target?.focus({ preventScroll: true }), 350);
    }
  }
  setMode('login');

  document.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode, true)));
  // links "Entrar" / "Começar" em toda a página abrem o modo certo
  document.querySelectorAll('[data-open-mode]').forEach(a => a.addEventListener('click', () => setMode(a.dataset.openMode, true)));
  if (location.hash === '#autenticacao') setMode('login');

  /* mostrar / esconder palavra-passe */
  document.querySelectorAll('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
    const input = btn.parentElement.querySelector('input');
    const show = input.type === 'password';
    // no registo, o campo de confirmação acompanha
    const form = btn.closest('form');
    form.querySelectorAll('input[name=password],input[name=confirm]').forEach(i => { i.type = show ? 'text' : 'password'; });
    btn.textContent = show ? '🙈' : '👁';
    btn.setAttribute('aria-label', show ? 'Esconder palavra-passe' : 'Mostrar palavra-passe');
  }));

  function fail(form, text, field) {
    form.querySelector('.message').textContent = text;
    form.classList.remove('shake');
    void form.offsetWidth;               // reinicia a animação
    form.classList.add('shake');
    form.querySelectorAll('.field').forEach(f => f.classList.remove('invalid'));
    if (field) {
      const input = form.elements[field];
      input?.closest('.field')?.classList.add('invalid');
      input?.focus();
    }
  }
  [loginForm, registerForm].forEach(f => {
    f.addEventListener('animationend', () => f.classList.remove('shake'));
    f.addEventListener('input', e => e.target.closest('.field')?.classList.remove('invalid'));
  });

  const validEmail = v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);

  async function send(form, action, payload) {
    const button = form.querySelector('[type=submit]');
    const label = button.textContent;
    button.disabled = true;
    button.textContent = 'A enviar…';
    form.querySelector('.message').textContent = '';
    try {
      const response = await fetch(`api/auth.php?action=${action}`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const text = await response.text();
      let result;
      try { result = JSON.parse(text); }
      catch { throw new Error('O servidor não respondeu corretamente. Confirma que o Apache e o MySQL estão ligados.'); }
      if (!response.ok || !result.success) throw new Error(result.error || 'Erro no servidor.');
      button.textContent = '✓';
      box.classList.add('success');
      window.location.href = 'dashboard.php';
    } catch (error) {
      fail(form, error.message);
      button.disabled = false;
      button.textContent = label;
    }
  }

  loginForm.addEventListener('submit', e => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(loginForm).entries());
    if (!validEmail(d.email.trim())) return fail(loginForm, 'Introduz um email válido.', 'email');
    if (!d.password) return fail(loginForm, 'Introduz a tua palavra-passe.', 'password');     // o tamanho mínimo só vale ao CRIAR a palavra-passe, nunca ao entrar
    send(loginForm, 'login', { email: d.email.trim(), password: d.password });
  });

  registerForm.addEventListener('submit', e => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(registerForm).entries());
    if (d.name.trim().length < 2) return fail(registerForm, 'Introduz o teu nome.', 'name');
    if (!validEmail(d.email.trim())) return fail(registerForm, 'Introduz um email válido.', 'email');
    if (d.password.length < 8) return fail(registerForm, 'A palavra-passe precisa de pelo menos 8 caracteres.', 'password');
    if (d.password !== d.confirm) return fail(registerForm, 'As palavras-passe não coincidem.', 'confirm');
    send(registerForm, 'register', { name: d.name.trim(), email: d.email.trim(), password: d.password });
  });
});
