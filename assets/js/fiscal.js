/* =========================================================================
   PRÓXIMAS DATAS FISCAIS  (assets/js/fiscal.js)
   -------------------------------------------------------------------------
   Mostra, na Visão geral, as próximas obrigações fiscais (IVA, Segurança Social, IRS) do negócio, a partir
   das respostas do questionário. Os dados vêm de api/fiscal.php (regras em config/fiscal.php).
   Sem respostas, mostra um convite para as dar. São datas INDICATIVAS (o aviso aparece sempre no cartão).
   ========================================================================= */
(function () {
  'use strict';
  const card = $('#fiscal-card');
  if (!card) return;
  const u = window.GF_USER || {};
  if (!(u.isOwner || (u.permissions || []).includes('cashflow'))) return;
  const KIND = { iva: 'IVA', ss: 'Seg. Social', irs: 'IRS' };
  const when = d => d < 0 ? 'passou' : d === 0 ? 'hoje' : d === 1 ? 'amanhã' : `em ${d} dias`;
  const fmt = iso => new Intl.DateTimeFormat((window.LUMINA_LOCALE || 'pt-PT'), { day: 'numeric', month: 'short' }).format(new Date(iso + 'T12:00:00')).replace('.', '');

  async function load() {
    try {
      const d = await api('fiscal.php');
      card.hidden = false;
      const list = $('#fiscal-list'), note = $('#fiscal-note');
      if (!d.answered) {
        list.innerHTML = '';
        note.innerHTML = 'Responde ao passo «Impostos» do questionário para veres aqui as tuas datas. ' + (u.isOwner ? '<button type="button" class="button small secondary" id="fiscal-answer">Responder agora</button>' : '');
        $('#fiscal-answer')?.addEventListener('click', () => $('#change-profile')?.click());
        return;
      }
      const items = d.deadlines.slice(0, 4);
      list.innerHTML = items.length ? items.map(x => `<li class="fiscal-item fiscal-${esc(x.kind)}"><span class="fiscal-date"><b>${esc(fmt(x.date))}</b><small>${esc(when(x.days))}</small></span>
        <span class="fiscal-text"><strong>${esc(x.title)}</strong><small>${esc(x.detail)}</small></span><span class="fiscal-kind">${esc(KIND[x.kind] || '')}</span></li>`).join('')
        : '<li class="muted">Sem datas fiscais nos próximos 75 dias.</li>';
      note.textContent = d.disclaimer;
    } catch (e) { card.hidden = true; }
  }
  window.gfProfileReady?.then(load);
  document.addEventListener('gf:profile', () => setTimeout(load, 300));
})();
