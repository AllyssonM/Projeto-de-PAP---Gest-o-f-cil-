/* =========================================================================
   LÓGICA DA ÁREA PESSOAL  (assets/js/area-pessoal.js)
   -------------------------------------------------------------------------
   O QUE FAZ (por zonas, na ordem do ficheiro):
     1. SEPARADORES    - Perfil / Empresa / Cartões / Segurança, com o indicador a deslizar
     2. PERFIL         - ver e editar os dados; foto com pré-visualização antes de guardar
     3. EMPRESA        - dados, logo (com pré-visualização) e cor principal
     4. PREFERÊNCIAS   - interruptor "Ocultar valores" (liga-se a privacy.js)
     5. SEGURANÇA      - palavra-passe, autenticação em 2 passos (QR code e códigos de
                         recuperação), sessões ativas, avisos e atividade recente
   OS CARTÕES são tratados por assets/js/cards.js (partilhado com o painel).
   REGRAS: toda a validação aqui é só para ajudar quem escreve; o servidor
           (api/me.php e api/security.php) valida SEMPRE outra vez.
   DEPENDE DE: api-lite.js (api, csrf, msg, $, esc), ux.js (toast, confirm), privacy.js.
   ========================================================================= */
(function () {
  'use strict';
  const fmtDate = v => v ? new Date(String(v).replace(' ', 'T')).toLocaleString((window.LUMINA_LOCALE || 'pt-PT'), { dateStyle: 'short', timeStyle: 'short' }) : '—';
  let me = null;                                   // dados do utilizador/empresa (api/me.php)

  /* ------------------------------------------------------------------
     0) JANELA GENÉRICA (vidro, foco preso, Esc fecha)
     Usa as classes do aviso de reunião, por isso tem o mesmo aspeto.
     ------------------------------------------------------------------ */
  const openModal = (html, opts) => UX.modal(html, opts);        // janela de vidro partilhada (assets/js/ux.js)

  /** Mostra um erro dentro de um formulário/janela (com role="alert" para leitores de ecrã). */
  function showError(scope, text) {
    const box = scope.querySelector('.ap-form-error');
    if (!box) return msg(text, true);
    box.textContent = text; box.hidden = !text;
  }

  /* ------------------------------------------------------------------
     1) SEPARADORES (com indicador a deslizar)
     ------------------------------------------------------------------ */
  const tabs = $$('.ap-tabs [role=tab]'), panels = $$('.ap-panel'), indicator = $('.ap-ind');
  function moveIndicator() {
    const t = $('.ap-tabs [aria-selected=true]');
    if (!t || !indicator) return;
    indicator.style.width = t.offsetWidth + 'px'; indicator.style.height = t.offsetHeight + 'px';
    indicator.style.transform = `translate(${t.offsetLeft}px, ${t.offsetTop}px)`;
    indicator.classList.add('placed');
    if (!indicator.classList.contains('ready')) requestAnimationFrame(() => requestAnimationFrame(() => indicator.classList.add('ready')));   // 1.ª posição sem deslizar
  }
  function selectTab(name, { focus = false, hash = true } = {}) {
    tabs.forEach(t => { const on = t.dataset.tab === name; t.setAttribute('aria-selected', on); t.tabIndex = on ? 0 : -1; if (on && focus) t.focus(); });
    panels.forEach(p => { p.hidden = p.dataset.panel !== name; if (!p.hidden) p.querySelectorAll('.ap-card').forEach(c => { c.style.animation = 'none'; void c.offsetWidth; c.style.animation = ''; }); });   // repete a entrada suave
    moveIndicator();
    if (hash) history.replaceState(null, '', '#' + name);
    if (name === 'seguranca') loadSecurity();
  }
  tabs.forEach(t => t.addEventListener('click', () => selectTab(t.dataset.tab)));
  $('.ap-tabs').addEventListener('keydown', e => {                      // setas esquerda/direita mudam de separador
    const i = tabs.findIndex(t => t.getAttribute('aria-selected') === 'true');
    if (e.key === 'ArrowRight') { e.preventDefault(); selectTab(tabs[(i + 1) % tabs.length].dataset.tab, { focus: true }); }
    if (e.key === 'ArrowLeft') { e.preventDefault(); selectTab(tabs[(i - 1 + tabs.length) % tabs.length].dataset.tab, { focus: true }); }
  });
  window.addEventListener('resize', moveIndicator);
  document.fonts?.ready.then(moveIndicator);

  $('#logout').onclick = async () => { try { await api('auth.php?action=logout', { method: 'POST', body: '{}' }); } catch (e) {} location.href = 'index.php'; };

  /* ------------------------------------------------------------------
     2) PERFIL
     ------------------------------------------------------------------ */
  function renderProfile() {
    const u = me.user;
    $('#profile-view').innerHTML = `<dl class="ap-dl">
      <div><dt>Nome</dt><dd>${esc(u.name)}</dd></div>
      <div><dt>Função</dt><dd>${esc(u.job_title || u.role_label)}</dd></div>
      <div><dt>Email</dt><dd>${esc(u.email)}</dd></div>
      <div><dt>Telefone</dt><dd>${esc(u.phone || '—')}</dd></div></dl>`;
    const f = $('#profile-form');
    f.name.value = u.name; f.job_title.value = u.job_title || u.role_label; f.email.value = u.email; f.phone.value = u.phone || '';
    setAvatar(u.avatar_url, u.initials);
  }
  function setAvatar(url, initials) {
    const img = $('#ap-avatar-img'), ini = $('#ap-avatar-initials');
    if (url) { img.src = url; img.hidden = false; ini.hidden = true; } else { img.hidden = true; img.removeAttribute('src'); ini.hidden = false; ini.textContent = initials || '?'; }
    $('#avatar-add').hidden = !!url; $('#avatar-change').hidden = !url; $('#avatar-remove').hidden = !url;
  }
  const profileForm = $('#profile-form');
  $('#profile-edit').onclick = () => { $('#profile-view').hidden = true; profileForm.hidden = false; $('#profile-edit').hidden = true; profileForm.name.focus(); };
  const endEdit = () => { profileForm.hidden = true; $('#profile-view').hidden = false; $('#profile-edit').hidden = false; showError(profileForm, ''); renderProfile(); };
  $('#profile-cancel').onclick = endEdit;
  profileForm.addEventListener('submit', async e => {
    e.preventDefault();
    const name = profileForm.name.value.trim(), phone = profileForm.phone.value.trim();
    if (name.length < 2) return showError(profileForm, 'Indica o teu nome (pelo menos 2 letras).');
    if (phone && !/^\+?[0-9 ()\-]{6,25}$/.test(phone)) return showError(profileForm, 'O telefone não parece válido (ex.: +351 912 345 678).');
    try {
      await api('me.php', { method: 'POST', body: JSON.stringify({ action: 'profile_update', name, phone, job_title: profileForm.job_title.disabled ? undefined : profileForm.job_title.value, csrf }) });
      await loadMe(); endEdit(); msg('Dados guardados.');
    } catch (err) { showError(profileForm, err.message); }
  });

  /* ---------- upload de imagem com pré-visualização ----------
     Escolher ficheiro -> verificar tipo e tamanho (mensagem amigável) -> mostrar a imagem ->
     só depois de "Guardar" é que vai para o servidor. */
  function pickImage({ input, kinds, maxMB, label, round, title, onSave }) {
    input.value = '';
    input.onchange = () => {
      const file = input.files[0]; if (!file) return;
      if (!kinds.includes(file.type)) return msg(`Formato não suportado. Usa ${label}.`, true);
      if (file.size > maxMB * 1024 * 1024) return msg(`A imagem é demasiado grande (máximo ${maxMB} MB).`, true);
      const url = URL.createObjectURL(file);
      // O navegador deduz o tipo pelo nome do ficheiro, que pode mentir (um texto chamado "foto.png").
      // Por isso tenta mesmo abrir a imagem: se não abrir, avisa logo, sem esperar pelo servidor.
      const probe = new Image();
      probe.onerror = () => { URL.revokeObjectURL(url); msg('O ficheiro não é uma imagem válida.', true); };
      probe.onload = () => showPreview(file, url);
      probe.src = url;
    };
    input.click();

    function showPreview(file, url) {
      const { root, close } = openModal(`<h2>${esc(title)}</h2><p class="muted">Esta é a pré-visualização. Se estiver bem, clica em Guardar.</p>
        <div class="ap-preview ${round ? 'round' : ''}"><img src="${url}" alt="Pré-visualização"></div>
        <p class="ap-form-error" role="alert" hidden></p>
        <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button primary" data-yes>Guardar</button></div>`,
        { onClose: () => URL.revokeObjectURL(url) });
      root.querySelector('[data-no]').onclick = () => close(false);
      root.querySelector('[data-yes]').onclick = async (ev) => {
        ev.target.disabled = true; ev.target.textContent = 'A guardar…';
        try { await onSave(file); close(true); }
        catch (err) { showError(root, err.message); ev.target.disabled = false; ev.target.textContent = 'Guardar'; }
      };
    }
  }
  const upload = (action, file) => { const fd = new FormData(); fd.append('action', action); fd.append('csrf', csrf); fd.append('file', file); return api('me.php', { method: 'POST', body: fd }); };

  const choosePhoto = () => pickImage({ input: $('#avatar-file'), kinds: ['image/jpeg', 'image/png', 'image/webp'], maxMB: 2, label: 'JPG, PNG ou WEBP', round: true, title: 'Foto de perfil',
    onSave: async f => { const d = await upload('avatar_upload', f); setAvatar(d.avatar_url, me.user.initials); msg('Foto atualizada.'); } });
  $('#avatar-add').onclick = choosePhoto; $('#avatar-change').onclick = choosePhoto;
  $('#avatar-remove').onclick = async () => {
    if (!await UX.confirm({ title: 'Remover a foto?', text: 'Vais voltar a ver as tuas iniciais.', confirmLabel: 'Remover', danger: true })) return;
    try { await api('me.php', { method: 'POST', body: JSON.stringify({ action: 'avatar_remove', csrf }) }); setAvatar(null, me.user.initials); msg('Foto removida.'); } catch (e) { msg(e.message, true); }
  };

  /* ------------------------------------------------------------------
     3) EMPRESA
     ------------------------------------------------------------------ */
  const companyForm = $('#company-form');
  const validNif = n => { if (!/^\d{9}$/.test(n) || !'123568 9'.includes(n[0]) || n[0] === ' ') return false; let s = 0; for (let i = 0; i < 8; i++) s += +n[i] * (9 - i); const c = 11 - (s % 11); return +n[8] === (c >= 10 ? 0 : c); };
  function renderCompany() {
    const c = me.company || {};
    companyForm.name.value = c.name || ''; companyForm.activity.value = c.activity || ''; companyForm.tax_number.value = c.tax_number || '';
    companyForm.address.value = c.address || ''; companyForm.phone.value = c.phone || ''; companyForm.email.value = c.email || ''; companyForm.website.value = c.website || '';
    setBrand(c.brand_color || '');
    setLogo(c.logo_url);
  }
  function setLogo(url) {
    const img = $('#ap-logo-img'), empty = $('#ap-logo-empty');
    if (url) { img.src = url; img.hidden = false; empty.hidden = true; } else { img.hidden = true; img.removeAttribute('src'); empty.hidden = false; }
    $('#logo-add').hidden = !!url; $('#logo-change').hidden = !url; $('#logo-remove').hidden = !url;
  }
  function setBrand(hex) {
    const ok = /^#[0-9a-f]{6}$/i.test(hex);
    $('#brand-color').value = ok ? hex : '#14b8a6'; $('#brand-hex').value = ok ? hex.toLowerCase() : '';
  }
  $('#brand-color').addEventListener('input', e => { $('#brand-hex').value = e.target.value; });
  $('#brand-hex').addEventListener('input', e => { if (/^#[0-9a-f]{6}$/i.test(e.target.value)) $('#brand-color').value = e.target.value; });
  $('#brand-reset').onclick = () => setBrand('');

  const chooseLogo = () => pickImage({ input: $('#logo-file'), kinds: ['image/png', 'image/jpeg', 'image/svg+xml'], maxMB: 1, label: 'PNG, JPG ou SVG', round: false, title: 'Logo da empresa',
    onSave: async f => { const d = await upload('logo_upload', f); setLogo(d.logo_url); msg('Logo atualizada.'); } });
  $('#logo-add').onclick = chooseLogo; $('#logo-change').onclick = chooseLogo;
  $('#logo-remove').onclick = async () => {
    if (!await UX.confirm({ title: 'Remover a logo?', text: 'Os relatórios e PDFs deixam de a mostrar.', confirmLabel: 'Remover', danger: true })) return;
    try { await api('me.php', { method: 'POST', body: JSON.stringify({ action: 'logo_remove', csrf }) }); setLogo(null); msg('Logo removida.'); } catch (e) { msg(e.message, true); }
  };

  companyForm.addEventListener('submit', async e => {
    e.preventDefault();
    const v = n => companyForm.elements[n].value.trim();
    if (!v('name')) return showError(companyForm, 'Indica o nome da empresa.');
    if (v('tax_number') && !validNif(v('tax_number'))) return showError(companyForm, 'O NIF não é válido (9 dígitos).');
    if (v('email') && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v('email'))) return showError(companyForm, 'O email profissional não é válido.');
    if (v('website') && !/^https?:\/\//i.test(v('website'))) return showError(companyForm, 'O website tem de começar por http:// ou https://.');
    if (v('brand_color') && !/^#[0-9a-f]{6}$/i.test(v('brand_color'))) return showError(companyForm, 'A cor tem de estar no formato #rrggbb.');
    showError(companyForm, '');
    try {
      await api('me.php', { method: 'POST', body: JSON.stringify({ action: 'company_update', name: v('name'), activity: v('activity'), tax_number: v('tax_number'), address: v('address'), phone: v('phone'), email: v('email'), website: v('website'), brand_color: v('brand_color'), csrf }) });
      msg('Empresa guardada.'); await loadMe();
    } catch (err) { showError(companyForm, err.message); }
  });

  /* ------------------------------------------------------------------
     4) PREFERÊNCIAS: ocultar valores
     ------------------------------------------------------------------ */
  $('#pref-hide').addEventListener('change', e => window.GFPrivacy?.set(e.target.checked));
  document.addEventListener('click', () => setTimeout(() => { const c = $('#pref-hide'); if (c) c.checked = window.GFPrivacy?.isHidden() ?? c.checked; }, 0));   // mantém o interruptor igual ao botão do cabeçalho

  /* ------------------------------------------------------------------
     5) SEGURANÇA
     ------------------------------------------------------------------ */
  const EVENT_LABELS = { login: 'Início de sessão', logout: 'Sessão terminada', password_change: 'Palavra-passe alterada', '2fa_enabled': '2.º passo ativado', '2fa_disabled': '2.º passo desativado',
    sessions_revoked: 'Sessões terminadas', '2fa_backup_code_used': 'Código de recuperação usado' };
  let sec = null;

  async function loadSecurity() {
    $('#sessions').innerHTML = '<div class="skeleton-stack"><div class="skeleton" style="height:54px"></div><div class="skeleton" style="height:54px"></div></div>';
    try { sec = await api('security.php'); renderSecurity(); }
    catch (err) {
      $('#sessions').innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível carregar a segurança</b><span>${esc(err.message)}</span><button type="button" class="button secondary" id="sec-retry">Tentar novamente</button></div>`;
      $('#sec-retry').onclick = loadSecurity;
    }
  }
  function renderSecurity() {
    // avisos
    $('#sec-warnings-card').hidden = !sec.warnings.length;
    $('#sec-warnings').innerHTML = sec.warnings.map(w => `<li class="${esc(w.level)}"><span aria-hidden="true">${w.level === 'warn' ? '⚠️' : 'ℹ️'}</span><span>${esc(w.text)}</span></li>`).join('');
    // 2 passos
    const on = sec.two_factor.enabled;
    $('#tfa-chip').textContent = on ? 'Ativo' : 'Desligado'; $('#tfa-chip').classList.toggle('on', on);
    $('#tfa-toggle').disabled = false; $('#tfa-toggle').textContent = on ? 'Desativar 2 passos' : 'Ativar 2 passos';
    $('#tfa-toggle').className = 'button ' + (on ? 'danger' : 'primary');
    $('#tfa-text').textContent = on ? `O segundo passo está ativo. Tens ${sec.two_factor.backup_left} código(s) de recuperação por usar.` : 'Com o segundo passo, além da palavra-passe é preciso um código da tua app de autenticação (Google Authenticator, Microsoft Authenticator, Authy…).';
    // sessões
    $('#sessions').innerHTML = sec.sessions.length ? sec.sessions.map(s => `<div class="session-item ${s.current ? 'current' : ''}">
        <span class="session-ico" aria-hidden="true">${/Android|iOS/.test(s.device) ? '📱' : '💻'}</span>
        <div class="session-meta"><b>${esc(s.device)} ${s.current ? '<span class="ap-chip on">Este dispositivo</span>' : ''}</b><small>IP ${esc(s.ip || '—')} · Última atividade ${esc(fmtDate(s.last_seen_at))}</small></div>
        ${s.current ? '' : `<button type="button" class="button secondary small" data-revoke="${s.id}">Terminar</button>`}</div>`).join('') : '<p class="muted">Sem sessões ativas.</p>';
    $$('[data-revoke]').forEach(b => b.onclick = () => revokeSession(+b.dataset.revoke));
    // eventos
    $('#sec-events').innerHTML = sec.events.length ? sec.events.map(ev => `<li><span>${esc(EVENT_LABELS[ev.action] || ev.action)}</span><small>${esc(fmtDate(ev.created_at))} · IP ${esc(ev.ip || '—')}</small></li>`).join('') : '<li class="muted">Ainda sem eventos.</li>';
  }

  async function revokeSession(id) {
    if (!await UX.confirm({ title: 'Terminar esta sessão?', text: 'Esse dispositivo vai ter de iniciar sessão outra vez.', confirmLabel: 'Terminar' })) return;
    try { await api('security.php?action=session_revoke', { method: 'POST', body: JSON.stringify({ id, csrf }) }); msg('Sessão terminada.'); loadSecurity(); } catch (e) { msg(e.message, true); }
  }
  $('#session-here').onclick = async () => {
    if (!await UX.confirm({ title: 'Terminar sessão neste dispositivo?', text: 'Vais voltar ao ecrã de entrada.', confirmLabel: 'Terminar sessão' })) return;
    $('#logout').click();
  };
  $('#session-all').onclick = async () => {
    const pw = await UX.confirm({ title: 'Terminar sessão em TODOS os dispositivos?', text: 'Inclui este. Por segurança, confirma com a tua palavra-passe.', confirmLabel: 'Terminar tudo', danger: true, input: { type: 'password', label: 'Palavra-passe atual' } });
    if (!pw) return;
    try { await api('security.php?action=sessions_revoke_all', { method: 'POST', body: JSON.stringify({ password: pw, include_current: true, csrf }) }); location.href = 'index.php'; }
    catch (e) { msg(e.message, true); }
  };

  /* ---------- palavra-passe ---------- */
  const pwForm = $('#password-form');
  pwForm.new_password.addEventListener('input', () => {                // medidor simples de força
    const v = pwForm.new_password.value, m = $('#pw-meter');
    const classes = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(r => r.test(v)).length;
    const level = !v ? '' : (v.length >= 12 && classes >= 3) ? 'strong' : (v.length >= 8 && classes >= 2) ? 'ok' : 'weak';
    m.className = 'ap-meter ' + level; m.textContent = { '': '', weak: 'Fraca: usa pelo menos 8 caracteres, com letras e números.', ok: 'Razoável.', strong: 'Forte.' }[level];
  });
  pwForm.addEventListener('submit', async e => {
    e.preventDefault();
    const f = pwForm.elements;
    if (f.new_password.value.length < 8) return showError(pwForm, 'A nova palavra-passe tem de ter pelo menos 8 caracteres.');
    if (f.new_password.value !== f.repeat.value) return showError(pwForm, 'As duas palavras-passe novas não coincidem.');
    showError(pwForm, '');
    try {
      const d = await api('security.php?action=change_password', { method: 'POST', body: JSON.stringify({ current_password: f.current_password.value, new_password: f.new_password.value, logout_others: f.logout_others.checked, csrf }) });
      pwForm.reset(); $('#pw-meter').textContent = '';
      msg(d.others_revoked ? `Palavra-passe alterada. ${d.others_revoked} outra(s) sessão(ões) terminada(s).` : 'Palavra-passe alterada.'); loadSecurity();
    } catch (err) { showError(pwForm, err.message); }
  });

  /* ---------- 2 passos ---------- */
  $('#tfa-toggle').onclick = () => (sec?.two_factor.enabled ? disableTwoFactor() : enableTwoFactor());

  async function enableTwoFactor() {
    let d;
    try { d = await api('security.php?action=2fa_begin', { method: 'POST', body: JSON.stringify({ csrf }) }); } catch (e) { return msg(e.message, true); }
    let qrSvg = '';
    try { const qr = qrcode(0, 'M'); qr.addData(d.uri); qr.make(); qrSvg = qr.createSvgTag({ cellSize: 5, margin: 0, scalable: true }); } catch (e) { /* sem QR: a chave escrita chega */ }
    const { root, close } = openModal(`<h2>Ativar o segundo passo</h2>
      <p class="muted">1. Na tua app de autenticação, lê este QR code (ou escreve a chave).<br>2. Escreve abaixo o código de 6 dígitos que a app mostra.</p>
      ${qrSvg ? `<div class="ap-qr" role="img" aria-label="QR code para a app de autenticação">${qrSvg}</div>` : ''}
      <p class="muted ap-small">Chave manual:</p><code class="ap-secret">${esc(d.secret.match(/.{1,4}/g).join(' '))}</code>
      <label class="ux-confirm-input" style="margin-top:12px">Código de 6 dígitos<input class="ap-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button primary" data-yes>Ativar</button></div>`);
    const input = root.querySelector('input');
    root.querySelector('[data-no]').onclick = () => close(false);
    const go = async () => {
      const btn = root.querySelector('[data-yes]'); btn.disabled = true;
      try {
        const r = await api('security.php?action=2fa_enable', { method: 'POST', body: JSON.stringify({ code: input.value, csrf }) });
        close(true); showBackupCodes(r.backup_codes); loadSecurity();
      } catch (err) { showError(root, err.message); btn.disabled = false; input.select(); }
    };
    root.querySelector('[data-yes]').onclick = go;
    input.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
  }

  /** Códigos de recuperação: só se mostram esta vez. */
  function showBackupCodes(codes) {
    const text = 'Códigos de recuperação (uso único) - Lumina\n' + codes.join('\n') + '\n';
    const { root, close } = openModal(`<h2>Guarda os códigos de recuperação</h2>
      <p class="muted">Se perderes o telemóvel, cada código serve <b>uma vez</b> para entrares. <b>Não voltam a ser mostrados.</b> Guarda-os num sítio seguro.</p>
      <div class="ap-codes">${codes.map(c => `<code>${esc(c)}</code>`).join('')}</div>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-copy>Copiar</button><button type="button" class="button secondary" data-dl>Descarregar</button><button type="button" class="button primary" data-done>Já guardei</button></div>`);
    root.querySelector('[data-copy]').onclick = async () => { try { await navigator.clipboard.writeText(text); msg('Códigos copiados.'); } catch { msg('Não foi possível copiar. Descarrega o ficheiro.', true); } };
    root.querySelector('[data-dl]').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' })); a.download = 'codigos-de-recuperacao.txt'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 1000); };
    root.querySelector('[data-done]').onclick = () => close(true);
  }

  async function disableTwoFactor() {
    const { root, close } = openModal(`<h2>Desativar o segundo passo?</h2>
      <p class="muted">A conta fica menos protegida. Para confirmar, escreve a tua palavra-passe e um código da app.</p>
      <label class="ux-confirm-input">Palavra-passe atual<input name="pw" type="password" autocomplete="current-password"></label>
      <label class="ux-confirm-input">Código de 6 dígitos<input name="code" class="ap-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button danger" data-yes>Desativar</button></div>`);
    root.querySelector('[data-no]').onclick = () => close(false);
    root.querySelector('[data-yes]').onclick = async (ev) => {
      ev.target.disabled = true;
      try { await api('security.php?action=2fa_disable', { method: 'POST', body: JSON.stringify({ password: root.querySelector('[name=pw]').value, code: root.querySelector('[name=code]').value, csrf }) }); close(true); msg('Segundo passo desativado.'); loadSecurity(); }
      catch (err) { showError(root, err.message); ev.target.disabled = false; }
    };
  }

  /* Eliminar a conta (RGPD): só o administrador. Pede a palavra-passe, o código do 2.º passo (se ativo) e a palavra ELIMINAR. */
  async function deleteAccount() {
    const tfa = !!sec?.two_factor.enabled;
    const { root, close } = openModal(`<h2>Eliminar a conta?</h2>
      <p class="muted">Apaga <strong>para sempre</strong> a tua conta, a da tua equipa e todos os dados do negócio (movimentos, clientes, notas, tempo, ficheiros). Não há volta a dar. Descarrega os teus dados antes, se quiseres guardá-los.</p>
      <label class="ux-confirm-input">Palavra-passe atual<input name="pw" type="password" autocomplete="current-password"></label>
      ${tfa ? '<label class="ux-confirm-input">Código de 6 dígitos<input name="code" class="ap-code-input" inputmode="numeric" autocomplete="one-time-code" maxlength="7"></label>' : ''}
      <label class="ux-confirm-input">Escreve a palavra ELIMINAR<input name="word" autocomplete="off"></label>
      <p class="ap-form-error" role="alert" hidden></p>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Cancelar</button><button type="button" class="button danger" data-yes>Eliminar tudo</button></div>`);
    root.querySelector('[data-no]').onclick = () => close(false);
    root.querySelector('[data-yes]').onclick = async ev => {
      ev.target.disabled = true;
      const val = n => root.querySelector(`[name=${n}]`)?.value || '';
      try {
        await api('account.php', { method: 'POST', body: JSON.stringify({ action: 'delete_account', password: val('pw'), code: val('code'), confirm: val('word'), csrf }) });
        close(true); window.location.href = 'index.php?conta=eliminada';
      } catch (err) { showError(root, err.message); ev.target.disabled = false; }
    };
  }
  $('#delete-account')?.addEventListener('click', deleteAccount);

  /* ------------------------------------------------------------------
     ARRANQUE
     ------------------------------------------------------------------ */
  async function loadMe() {
    me = await api('me.php');
    renderProfile(); renderCompany();
  }
  (async function init() {
    const start = (location.hash || '#perfil').slice(1);
    selectTab(tabs.some(t => t.dataset.tab === start) ? start : 'perfil', { hash: false });
    try { await loadMe(); }
    catch (err) {
      $('#profile-view').innerHTML = `<div class="ux-empty ux-error"><b>Não foi possível carregar os teus dados</b><span>${esc(err.message)}</span><button type="button" class="button secondary" id="me-retry">Tentar novamente</button></div>`;
      $('#me-retry').onclick = () => location.reload();
    }
    moveIndicator();
  })();
})();
