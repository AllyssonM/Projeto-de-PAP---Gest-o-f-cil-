/* =========================================================
   Equipa: gestão de funcionários (dono) e troca de palavra-passe (todos)
   Usa api(), msg(), esc(), shortDate(), csrf e GF de app.js
   ========================================================= */

/* ---------- alterar palavra-passe ---------- */
(() => {
  const modal = $('#password-modal');
  const form = $('#password-form');
  const box = $('#password-message');
  const open = () => { form.reset(); box.textContent = ''; modal.classList.remove('hidden'); form.elements.current_password.focus(); };
  const close = () => { if (!GF.mustChangePassword) modal.classList.add('hidden'); };

  const openBtn = $('#open-password'); if (openBtn) openBtn.onclick = open;          // o botão já não está na página inicial: só a Área pessoal altera a palavra-passe (o modal serve o 1.º acesso)
  $('#password-cancel')?.addEventListener('click', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.classList.contains('hidden')) close(); });

  form.onsubmit = async e => {
    e.preventDefault();
    const d = formData(form);
    if (d.new_password !== d.confirm_password) { box.textContent = 'As duas palavras-passe novas não são iguais.'; return; }
    try {
      await api('auth.php?action=change_password', { method: 'POST', body: JSON.stringify({ current_password: d.current_password, new_password: d.new_password, csrf }) });
      if (GF.mustChangePassword) { window.location.reload(); return; }  // volta a carregar já com acesso
      close();
      msg('Palavra-passe alterada.');
    } catch (x) { box.textContent = x.message; }
  };
  if (GF.mustChangePassword) form.elements.current_password.focus();
})();

/* ---------- equipa (só o dono) ---------- */
(() => {
  if (!GF.isOwner || !$('#team')) return;

  const form = $('#team-form');
  const MODULES = GF.modules || {};
  // Por omissão, um funcionário novo é de ENTRADA DE DADOS: regista produtos, contas e clientes e tem agenda,
  // mas não vê os totais do negócio (Visão geral, Fluxo de caixa, Relatórios) e não elimina nada.
  const PRESETS = {
    entry:   ['stock', 'accounts', 'clients', 'calendar'],
    agenda:  ['calendar'],
    manager: ['cashflow', 'accounts', 'clients', 'stock', 'calendar'],
  };
  const DEFAULT_PERMS = PRESETS.entry;
  let team = [];
  let loaded = false;

  $('#team-perm-list').innerHTML = Object.entries(MODULES).map(([key, label]) =>
    `<label class="team-perm"><input type="checkbox" name="perm" value="${key}"> <span>${esc(label)}</span></label>`).join('');

  const setPerms = list => form.querySelectorAll('[name=perm]').forEach(c => { c.checked = list.includes(c.value); });
  // os botões "Entrada de dados", "Só agenda" e "Gestor" marcam as áreas de uma vez
  form.querySelectorAll('[data-preset]').forEach(b => b.onclick = () => setPerms(PRESETS[b.dataset.preset] || []));
  const fmtLogin = v => v ? `Último acesso: ${date(v)}` : 'Ainda não entrou';
  const permLabels = list => list.length ? list.map(k => `<span class="team-tag">${esc(MODULES[k] || k)}</span>`).join('') : '<span class="team-tag none">Sem acesso a módulos</span>';

  function render() {
    const q = $('#team-search').value.trim().toLowerCase();
    const rows = team.filter(p => !q || [p.name, p.email, p.job_title, p.department].some(v => String(v || '').toLowerCase().includes(q)));
    const active = team.filter(p => p.status === 'active');
    const departments = [...new Set(team.map(p => p.department).filter(Boolean))];

    $('#team-total').textContent = team.length;
    $('#team-active').textContent = active.length;
    $('#team-inactive').textContent = team.length - active.length;
    $('#team-departments').textContent = departments.length;
    $('#team-department-list').innerHTML = departments.map(d => `<option value="${esc(d)}">`).join('');

    $('#team-list').innerHTML = rows.length ? rows.map(p => {
      const off = p.status !== 'active';
      return `<article class="team-card${off ? ' off' : ''}">
        <div class="team-head">
          <span class="team-avatar" aria-hidden="true">${esc((p.name || '?').trim().charAt(0).toUpperCase())}</span>
          <div><h3>${esc(p.name)}</h3><small>${esc(p.job_title || 'Sem cargo')}${p.department ? ' · ' + esc(p.department) : ''}</small></div>
          <span class="badge ${off ? 'late' : 'ok'}">${off ? 'Desativado' : 'Ativo'}</span>
        </div>
        <p class="team-meta">${esc(p.email)}${p.phone ? ' · ' + esc(p.phone) : ''}${p.hired_at ? ' · desde ' + shortDate(p.hired_at) : ''}</p>
        <p class="team-meta">${fmtLogin(p.last_login_at)}${p.must_change_password ? ' · <b>palavra-passe provisória</b>' : ''}</p>
        <div class="team-tags">${permLabels(p.permissions)}</div>
        <div class="team-actions">
          <button class="button small secondary" data-team-edit="${p.id}">Editar</button>
          <button class="button small secondary" data-team-status="${p.id}">${off ? 'Ativar' : 'Desativar'}</button>
          <button class="button small secondary" data-team-invite="${p.id}">✉ Enviar convite</button>
          <button class="button small secondary" data-team-reset="${p.id}">Nova palavra-passe</button>
          <button class="delete-button" data-team-delete="${p.id}" aria-label="Eliminar funcionário" title="Eliminar">×</button>
        </div>
      </article>`;
    }).join('') : `<p class="muted">${team.length ? 'Nenhum funcionário corresponde à pesquisa.' : 'Ainda não tens funcionários. Adiciona o primeiro no formulário ao lado.'}</p>`;

    const byDept = {};
    team.forEach(p => { const d = p.department || 'Sem departamento'; byDept[d] = (byDept[d] || 0) + 1; });
    const max = Math.max(1, ...Object.values(byDept));
    $('#team-by-department').innerHTML = Object.keys(byDept).length ? Object.entries(byDept).sort((a, b) => b[1] - a[1]).map(([d, n]) =>
      `<div class="team-dept"><span>${esc(d)}</span><i style="width:${(n / max * 100).toFixed(0)}%"></i><b>${n}</b></div>`).join('') : '<p class="muted">—</p>';

    $$('[data-team-edit]').forEach(b => b.onclick = () => edit(Number(b.dataset.teamEdit)));
    $$('[data-team-status]').forEach(b => b.onclick = () => toggle(Number(b.dataset.teamStatus)));
    $$('[data-team-invite]').forEach(b => b.onclick = () => invite(Number(b.dataset.teamInvite)));
    $$('[data-team-reset]').forEach(b => b.onclick = () => reset(Number(b.dataset.teamReset)));
    $$('[data-team-delete]').forEach(b => b.onclick = () => remove(Number(b.dataset.teamDelete)));
  }

  async function load() {
    try {
      const d = await api('team.php');
      team = d.items;
      loaded = true;
      render();
    } catch (e) { msg(e.message, true); }
  }

  function resetForm() {
    form.reset();
    form.elements.id.value = '';
    setPerms(DEFAULT_PERMS);
    $('#team-password-row').hidden = false;
    $('#team-form-title').textContent = 'Adicionar funcionário';
    $('#team-form-eyebrow').textContent = 'NOVO FUNCIONÁRIO';
    $('#team-cancel').classList.add('hidden');
  }

  function edit(id) {
    const p = team.find(x => Number(x.id) === id);
    if (!p) return;
    ['id', 'name', 'email', 'job_title', 'department', 'phone', 'hired_at'].forEach(k => { form.elements[k].value = p[k] ?? ''; });
    setPerms(p.permissions);
    $('#team-password-row').hidden = true;  // a palavra-passe muda-se com "Nova palavra-passe"
    $('#team-form-title').textContent = 'Editar ' + p.name;
    $('#team-form-eyebrow').textContent = 'EDITAR FUNCIONÁRIO';
    $('#team-cancel').classList.remove('hidden');
    form.elements.name.focus();
  }

  function showTempPassword(email, password) {
    $('#temp-pass-email').textContent = email;
    $('#temp-pass-value').textContent = password;
    $('#temp-pass-modal').classList.remove('hidden');
    $('#temp-pass-copy').focus();
  }
  $('#temp-pass-close').onclick = () => $('#temp-pass-modal').classList.add('hidden');
  $('#temp-pass-copy').onclick = async () => {
    const text = `Email: ${$('#temp-pass-email').textContent}\nPalavra-passe provisória: ${$('#temp-pass-value').textContent}`;
    try { await navigator.clipboard.writeText(text); $('#temp-pass-copy').textContent = 'Copiado ✓'; }
    catch { $('#temp-pass-copy').textContent = 'Seleciona e copia à mão'; }
    setTimeout(() => { $('#temp-pass-copy').textContent = 'Copiar'; }, 2000);
  };

  form.onsubmit = async e => {
    e.preventDefault();
    const d = formData(form);
    const body = { ...d, permissions: [...form.querySelectorAll('[name=perm]:checked')].map(c => c.value), action: 'save', csrf };
    delete body.perm;
    if (d.id) delete body.password;
    try {
      const r = await api('team.php', { method: 'POST', body: JSON.stringify(body) });
      if (d.id) msg('Funcionário atualizado.');
      else if (r.invite_sent) msg('Funcionário criado. Convite enviado por email.');
      else if (r.mail_error) msg('Funcionário criado, mas o convite NÃO foi enviado. ' + r.mail_error + ' Entrega a palavra-passe provisória em mão.', true);
      else msg('Funcionário criado.');
      if (r.temp_password) showTempPassword(d.email.trim().toLowerCase(), r.temp_password);
      window.burstHearts?.(e.submitter || form);
      resetForm();
      load();
    } catch (x) { msg(x.message, true); }
  };

  async function toggle(id) {
    const p = team.find(x => Number(x.id) === id);
    const status = p.status === 'active' ? 'inactive' : 'active';
    if (status === 'inactive' && !await UX.confirm({ title: `Desativar ${p.name}?`, text: 'Deixa de conseguir entrar até voltares a ativar.', confirmLabel: 'Desativar', danger: true })) return;
    try {
      await api('team.php', { method: 'POST', body: JSON.stringify({ action: 'status', id, status, csrf }) });
      msg(status === 'active' ? 'Funcionário ativado.' : 'Funcionário desativado.');
      load();
    } catch (x) { msg(x.message, true); }
  }

  async function invite(id) {
    const p = team.find(x => Number(x.id) === id);
    if (!await UX.confirm({ title: 'Enviar convite?', text: `Enviamos um email a ${p.email} para ${p.name} escolher a palavra-passe. O link vale 3 dias.`, confirmLabel: 'Enviar' })) return;
    try {
      const r = await api('team.php', { method: 'POST', body: JSON.stringify({ action: 'invite', id, csrf }) });
      msg(r.sent ? 'Convite enviado para ' + p.email + '.' : (r.mail_error || 'Não foi possível enviar o email.') + ' Usa "Nova palavra-passe".', !r.sent);
    } catch (x) { msg(x.message, true); }
  }

  async function reset(id) {
    const p = team.find(x => Number(x.id) === id);
    if (!await UX.confirm({ title: 'Nova palavra-passe provisória?', text: `${p.name} deixa de poder entrar com a atual.`, confirmLabel: 'Gerar', danger: true })) return;
    try {
      const r = await api('team.php', { method: 'POST', body: JSON.stringify({ action: 'reset_password', id, csrf }) });
      showTempPassword(p.email, r.temp_password);
      load();
    } catch (x) { msg(x.message, true); }
  }

  async function remove(id) {
    const p = team.find(x => Number(x.id) === id);
    if (!await UX.confirm({ title: `Eliminar ${p.name}?`, text: 'A conta e a agenda pessoal são apagadas. Os dados do negócio ficam.', confirmLabel: 'Eliminar', danger: true })) return;
    try {
      await api(`team.php?id=${encodeURIComponent(id)}`, { method: 'DELETE', body: JSON.stringify({ csrf }) });
      msg('Funcionário eliminado.');
      if (Number(form.elements.id.value) === id) resetForm();
      load();
    } catch (x) { msg(x.message, true); }
  }

  $('#team-generate').onclick = () => {
    const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    const rnd = crypto.getRandomValues(new Uint32Array(10));
    form.elements.password.value = [...rnd].map(n => chars[n % chars.length]).join('');
  };
  $('#team-cancel').onclick = resetForm;
  $('#team-search').oninput = render;
  $('#mail-test').onclick = async ev => {
    const b = ev.currentTarget; b.disabled = true; const label = b.textContent; b.textContent = 'A enviar…';
    try {
      const r = await api('auth.php?action=test_mail', { method: 'POST', body: JSON.stringify({ csrf }) });
      msg(r.sent ? 'Email de teste enviado para ' + r.to + '. Verifica a caixa de entrada (e o spam).' : r.message, !r.sent);
    } catch (x) { msg(x.message, true); } finally { b.disabled = false; b.textContent = label; }
  };
  document.addEventListener('gf:section', e => { if (e.detail === 'team' && !loaded) load(); });
  resetForm();
})();
