/* =========================================================================
   QUESTIONÁRIO DE ARRANQUE E ESCOLHA DO RAMO  (assets/js/profile.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  no primeiro acesso do dono (e em "Alterar ramo" / "Refazer o questionário") mostra um
               assistente de 6 passos curtos:
                 1 Qual é o teu negócio?   (nome + ramo)
                 2 Como trabalhas?         (equipa e local de trabalho)
                 3 Como recebes?           (numerário, MB Way, cartão...)
                 4 O que queres acompanhar? (que abas ficam ligadas; já vêm sugeridas)
                 5 Impostos                (regime de IVA e Segurança Social)
                 6 O teu objetivo          (faturação, meta e, opcionalmente, uma conta de reserva)
               As respostas ficam em api/profile.php. O assistente só LIGA e DESLIGA abas e adapta textos;
               nunca muda o desenho do site.
   ATALHO:     "Usar sugestões e entrar" (passo 1) grava só o nome e o ramo, com as sugestões por omissão.
   COMO SE LIGA: a lista de ramos e a personalização vêm de profiles.js (GFP); depois de gravar,
               GFP.apply() troca textos, campos, dicas e abas. Dados usados: common.js ($, esc, api, msg, csrf).
   ========================================================================= */
(function () {
  'use strict';

  let businessProfile = null;                 // o perfil guardado (linha da base de dados)
  let step = 1, saving = false;
  let answers = {};                           // respostas do questionário
  let modules = new Set(), modulesTouched = false;
  const TOTAL = 6;

  const DEFAULTS = () => ({ team: 'solo', place: 'fixed', pay: [], vat: 'unknown', ss: 'unknown', revenue: 'unknown', goal: 'none', goal_amount: '' });
  const ESSENTIAL = ['overview', 'cashflow', 'reports'];
  const STEP_TEXT = {
    1: ['Qual é o teu negócio?', 'Escolhe o que mais se parece com o teu trabalho. Podes mudar depois.'],
    2: ['Como trabalhas?', 'Isto decide se mostramos a equipa e os horários.'],
    3: ['Como recebes?', 'Podes escolher mais do que uma. Serve para te sugerirmos o que acompanhar.'],
    4: ['O que queres acompanhar?', 'Só vês as abas que escolheres. Já vêm sugeridas para ti.'],
    5: ['Impostos', 'Para te lembrarmos das datas certas. Não sabes? Escolhe «Não sei».'],
    6: ['O teu objetivo', 'Opcional. Ajuda-nos a mostrar o que importa primeiro.'],
  };

  /* ---------------- peças reutilizáveis ---------------- */
  const radio = (name, value, title, sub = '') => `<label class="wiz-opt"><input type="radio" name="${name}" value="${value}" ${answers[name] === value ? 'checked' : ''}><span><strong>${esc(title)}</strong>${sub ? `<small>${esc(sub)}</small>` : ''}</span></label>`;
  const check = (name, value, title, sub = '', checked = false, disabled = false) => `<label class="wiz-opt"><input type="checkbox" name="${name}" value="${value}" ${checked ? 'checked' : ''} ${disabled ? 'disabled' : ''}><span><strong>${esc(title)}</strong>${sub ? `<small>${esc(sub)}</small>` : ''}</span></label>`;
  const group = (label, html) => `<fieldset class="wiz-group"><legend>${esc(label)}</legend><div class="wiz-grid">${html}</div></fieldset>`;

  /* Cartão de um ramo (passo 1): ícone, nome, descrição e "o teu produto". */
  function choiceCard(type) {
    const p = GFP.get(type);
    const selected = $('#profile-type').value === type && $('#profile-choices').dataset.picked ? 'selected' : '';
    return `<button type="button" class="profile-choice ${selected}" data-profile-type="${type}">
      <span class="profile-icon">${p.icon}</span>
      <strong>${esc(p.name)}</strong>
      <small>${esc(p.subtitle)}</small>
      <em class="profile-sells">O teu produto: <b>${esc(p.vocab.stockTitle)}</b></em>
    </button>`;
  }
  function renderChoices() {
    const box = $('#profile-choices');
    box.innerHTML = GFP.ORDER.map(choiceCard).join('');
    box.querySelectorAll('[data-profile-type]').forEach(button => {
      button.onclick = () => {
        box.querySelectorAll('[data-profile-type]').forEach(x => x.classList.remove('selected'));
        button.classList.add('selected');
        box.dataset.picked = '1';
        $('#profile-type').value = button.dataset.profileType;
        $('#profile-message').textContent = '';
        if (!modulesTouched) modules = suggestModules();
      };
    });
  }

  /* As abas que a pessoa pode ligar/desligar, com a descrição no vocabulário do ramo. */
  function moduleCatalog() {
    const p = GFP.get($('#profile-type').value || 'general');
    return [
      ['overview', 'Visão geral', 'O resumo do teu dia.', true], ['cashflow', 'Fluxo de caixa', 'Entradas, saídas e previsão.', true], ['reports', 'Relatórios', 'Resumo e PDF com logo.', true],
      ['accounts', 'Contas a pagar e a receber', 'O que deves e o que te devem.'], ['clients', 'Clientes', 'Quem te compra e quanto te deve.'],
      ['stock', p.vocab.stockTab || 'Estoque', `O que vendes (${(p.vocab.stockTitle || 'produtos').toLowerCase()}).`],
      ['calendar', 'Calendário', 'Marcações, lembretes e Google Calendar.'], ['notes', 'Notas', 'Apontamentos teus ou partilhados.'],
      ['time', 'Tempo ativo', 'Horas de trabalho da equipa.'], ['team', 'Equipa', 'Funcionários e permissões.'],
      ...(p.insights ? [['insights', p.insights.title, 'O painel inteligente do teu ramo.']] : []),
      ['calculator', 'Calculadora', 'Margens e preços.'], ['weather', 'Tempo (meteorologia)', 'Útil se trabalhas na rua.'],
    ];
  }
  /* Sugestão de abas, a partir do ramo e das respostas. */
  function suggestModules() {
    const p = GFP.get($('#profile-type').value || 'general'), m = new Set([...ESSENTIAL, 'accounts', 'notes', 'calendar', 'calculator']);
    if (p.modules.clients !== false) m.add('clients');
    if (p.modules.stock !== false) m.add('stock');
    if (p.insights) m.add('insights');
    if (answers.team && answers.team !== 'solo') { m.add('team'); m.add('time'); }
    if (answers.place === 'mobile' || answers.place === 'both') m.add('weather');
    return m;
  }

  /* ---------------- desenho dos passos ---------------- */
  function bodyFor(n) {
    if (n === 2) return group('Quantas pessoas trabalham contigo?', radio('team', 'solo', 'Só eu') + radio('team', 'small', '2 a 5 pessoas') + radio('team', 'large', '6 ou mais'))
      + group('Onde trabalhas?', radio('place', 'fixed', 'Num espaço fixo', 'loja, oficina, barbearia...') + radio('place', 'mobile', 'Em deslocação', 'estrada, casa do cliente...') + radio('place', 'online', 'Online') + radio('place', 'both', 'Um pouco de tudo'));
    if (n === 3) return group('Como te pagam?', [['cash', 'Numerário'], ['mbway', 'MB Way'], ['card', 'Cartão / terminal'], ['transfer', 'Transferência'], ['platforms', 'Plataformas', 'Uber, Bolt, Glovo, marketplaces...']].map(([v, t, s]) => check('pay', v, t, s || '', (answers.pay || []).includes(v))).join(''));
    if (n === 4) return group('Abas ligadas', moduleCatalog().map(([id, title, sub, always]) => check('module', id, title, sub, always || modules.has(id), !!always)).join(''))
      + '<p class="muted wiz-hint">Podes ligar ou desligar abas quando quiseres em «Refazer o questionário».</p>';
    if (n === 5) return group('Como pagas o IVA?', radio('vat', 'exempt', 'Estou isento', 'regime de isenção') + radio('vat', 'quarterly', 'Trimestral') + radio('vat', 'monthly', 'Mensal') + radio('vat', 'unknown', 'Não sei'))
      + group('Segurança Social', radio('ss', 'independent', 'Trabalhador independente') + radio('ss', 'company', 'Através de uma empresa') + radio('ss', 'unknown', 'Não sei'));
    if (n === 6) return group('Quanto faturas, em média, por mês?', radio('revenue', 'lt1k', 'Menos de 1 000 €') + radio('revenue', '1to3k', '1 000 a 3 000 €') + radio('revenue', '3to10k', '3 000 a 10 000 €') + radio('revenue', 'gt10k', 'Mais de 10 000 €') + radio('revenue', 'unknown', 'Prefiro não dizer'))
      + group('O que queres alcançar?', radio('goal', 'vat', 'Juntar dinheiro para o IVA') + radio('goal', 'rent', 'Pagar a renda sem aperto') + radio('goal', 'grow', 'Fazer o negócio crescer') + radio('goal', 'expenses', 'Controlar as despesas') + radio('goal', 'none', 'Ainda não sei'))
      + `<label class="wiz-amount" id="wiz-amount" ${['vat', 'rent', 'grow'].includes(answers.goal) ? '' : 'hidden'}>Valor a juntar (opcional)<input name="goal_amount" inputmode="decimal" placeholder="Ex.: 1500" value="${esc(answers.goal_amount || '')}"><small class="muted">Criamos uma conta de reserva com esta meta.</small></label>`;
    return '';
  }

  function collect() {                                          // lê o que a pessoa escolheu no passo atual
    const form = $('#profile-form');
    for (const r of form.querySelectorAll('#wiz-body input[type=radio]:checked')) answers[r.name] = r.value;
    if (step === 3) answers.pay = [...form.querySelectorAll('input[name=pay]:checked')].map(i => i.value);
    if (step === 4) { modules = new Set([...ESSENTIAL, ...[...form.querySelectorAll('input[name=module]:checked')].map(i => i.value)]); modulesTouched = true; }
    if (step === 6) answers.goal_amount = (form.querySelector('[name=goal_amount]')?.value || '').trim();
  }

  function show(n) {
    step = n;
    $('#wiz-step-1').hidden = n !== 1;
    const body = $('#wiz-body'); body.hidden = n === 1; body.innerHTML = n === 1 ? '' : bodyFor(n);
    $('#wiz-title').textContent = STEP_TEXT[n][0]; $('#wiz-sub').textContent = STEP_TEXT[n][1];
    $('#wiz-eyebrow').textContent = businessProfile ? `PASSO ${n} DE ${TOTAL}` : `CONFIGURAÇÃO INICIAL · PASSO ${n} DE ${TOTAL}`;
    const bar = $('#wiz-progress'); bar.setAttribute('aria-valuenow', n); $('#wiz-bar').style.width = (n / TOTAL * 100) + '%';
    $('#wiz-back').hidden = n === 1; $('#wiz-quick').hidden = n !== 1;
    $('#wiz-next').textContent = n === TOTAL ? 'Concluir' : 'Continuar';
    $('#profile-message').textContent = '';
    body.querySelectorAll('input[name=goal]').forEach(i => i.addEventListener('change', () => { $('#wiz-amount').hidden = !['vat', 'rent', 'grow'].includes(i.value); }));
    setTimeout(() => ($('#wiz-body').hidden ? $('#profile-form [name=business_name]') : body.querySelector('input:not([disabled])'))?.focus({ preventScroll: true }), 60);
  }

  /* ---------------- guardar ---------------- */
  async function save(useSuggestions) {
    if (saving) return; saving = true;
    const name = ($('#profile-form [name=business_name]').value || '').trim(), type = $('#profile-type').value;
    if (useSuggestions) { modules = suggestModules(); }
    const p = GFP.get(type);
    try {
      const d = await api('profile.php', { method: 'POST', body: JSON.stringify({
        business_name: name, business_type: type, enabled_modules: [...modules],
        onboarding: useSuggestions ? {} : answers, labels: { primary: p.primary }, csrf }) });
      setProfileUI(d.profile);
      window.loadAll?.();                                       // recarrega os dados já com os campos do novo ramo
      showDone(d);
    } catch (x) { $('#profile-message').textContent = x.message; }
    finally { saving = false; }
  }

  function showDone(d) {
    const labels = moduleCatalog().filter(([id]) => modules.has(id)).map(([, t]) => t);
    $('#wiz-step-1').hidden = true; $('#wiz-body').hidden = false;
    $('#wiz-title').textContent = 'Está tudo pronto!';
    $('#wiz-sub').textContent = 'O teu Lumina ficou configurado. Podes mudar tudo quando quiseres.';
    $('#wiz-eyebrow').textContent = 'CONCLUÍDO';
    $('#wiz-bar').style.width = '100%';
    $('#wiz-body').innerHTML = `<div class="wiz-done"><p><strong>Abas ligadas:</strong></p><p class="wiz-chips">${labels.map(t => `<span>${esc(t)}</span>`).join('')}</p>`
      + (d.goal_account_created ? '<p>✔ Criámos a tua conta de reserva com a meta que indicaste (aba Contas).</p>' : '')
      + '<p class="muted">Dica: começa por registar o teu primeiro movimento com «+ Novo movimento».</p></div>';
    $('#wiz-back').hidden = true; $('#wiz-quick').hidden = true; $('#wiz-next').textContent = 'Entrar no painel'; $('#wiz-next').dataset.done = '1';
  }

  /* ---------------- abrir / fechar ---------------- */
  function open() {
    const ob = businessProfile?.onboarding && !Array.isArray(businessProfile.onboarding) ? businessProfile.onboarding : {};
    answers = { ...DEFAULTS(), ...ob, pay: ob.pay || [], goal_amount: ob.goal_amount ?? '' };
    const hasModules = Array.isArray(businessProfile?.enabled_modules);
    modulesTouched = hasModules; modules = hasModules ? new Set([...ESSENTIAL, ...businessProfile.enabled_modules]) : null;
    $('#profile-type').value = businessProfile?.business_type || 'general';
    $('#profile-form [name=business_name]').value = businessProfile?.business_name || '';
    $('#profile-choices').dataset.picked = businessProfile ? '1' : '';
    renderChoices();
    if (!modules) modules = suggestModules();
    delete $('#wiz-next').dataset.done;
    $('#wiz-close').hidden = !businessProfile;                     // só dá para fechar sem guardar se já existe um perfil
    $('#profile-modal').classList.remove('hidden');
    show(1);
  }
  const close = () => $('#profile-modal').classList.add('hidden');

  $('#profile-form').addEventListener('submit', async e => {
    e.preventDefault();
    if ($('#wiz-next').dataset.done) return close();
    if (step === 1) {
      if (!($('#profile-form [name=business_name]').value || '').trim()) { $('#profile-message').textContent = 'Escreve o nome do teu negócio.'; $('#profile-form [name=business_name]').focus(); return; }
      if (!$('#profile-choices').dataset.picked) { $('#profile-message').textContent = 'Escolhe o teu ramo de atividade.'; return; }
      if (!modulesTouched) modules = suggestModules();
      return show(2);
    }
    collect();
    if (step === 3 && !modulesTouched) modules = suggestModules();
    if (step < TOTAL) return show(step + 1);
    save(false);
  });
  $('#wiz-back').onclick = () => { collect(); show(step - 1); };
  $('#wiz-quick').onclick = () => {
    if (!($('#profile-form [name=business_name]').value || '').trim()) { $('#profile-message').textContent = 'Escreve o nome do teu negócio.'; return; }
    if (!$('#profile-choices').dataset.picked) { $('#profile-message').textContent = 'Escolhe o teu ramo de atividade.'; return; }
    save(true);
  };
  $('#wiz-close').onclick = close;
  $('#profile-modal').addEventListener('keydown', e => { if (e.key === 'Escape' && !$('#wiz-close').hidden) close(); });

  /* ---------------- aplicar o perfil à página ---------------- */
  function setProfileUI(profile) {
    businessProfile = profile;
    window.GF_PROFILE = profile;                                // outros scripts (ex.: relatório) leem o nome do negócio daqui
    const p = GFP.get(profile.business_type);
    $('#business-title').textContent = profile.business_name || p.name;       // nome do negócio no topo
    $('#business-subtitle').textContent = p.subtitle;
    $('#profile-badge').textContent = `${p.icon} ${p.name}`;                   // etiqueta do ramo
    $('#adaptive-title').textContent = p.primary;                              // banner da Visão geral
    $('#adaptive-subtitle').textContent = p.subtitle;
    $('#adaptive-shortcuts').innerHTML = p.chips.map(x => `<span>${esc(x)}</span>`).join('');
    GFP.apply(profile);                                                        // vocabulário, campos, dicas, abas...
  }

  /* Lê o perfil guardado. Sem perfil: o dono faz o questionário; o funcionário usa o vocabulário geral. */
  async function loadProfile() {
    if (window.GF_USER?.mustChangePassword) return;       // primeiro acesso: antes tem de trocar a palavra-passe
    try {
      const d = await api('profile.php');
      if (!d.profile) {
        GFP.apply(null);
        if (window.GF_USER?.isOwner) open();
        return;
      }
      setProfileUI(d.profile);
      if (window.GF_USER?.isOwner && new URLSearchParams(location.search).has('questionario')) {   // vindo de "Refazer o questionário"
        history.replaceState(null, '', location.pathname + location.hash);
        open();
      }
    } catch (e) { msg(e.message, true); }
  }

  $('#change-profile')?.addEventListener('click', open);                       // botão "Alterar ramo" (só o dono)
  window.gfProfileReady = loadProfile();                                       // outros scripts esperam por isto antes de mostrar avisos
})();
