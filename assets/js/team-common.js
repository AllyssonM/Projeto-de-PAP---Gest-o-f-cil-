/* =========================================================================
   GESTÃO DE FUNCIONÁRIOS: PEÇAS COMUNS  (assets/js/team-common.js)
   -------------------------------------------------------------------------
   Pequenas funções usadas pelo sino de avisos, pela aba «Funcionários», pela aba do funcionário e pela conversa:
     GFT.avatar(foto, nome, tamanho)   a fotografia (ou a inicial, num círculo)
     GFT.chip(estado)                  o estado: online, ausente, em pausa ou offline
     GFT.meter(percentagem, rótulo)    uma barra de progresso acessível
     GFT.when(data)                    «agora», «há 5 min», «hoje, 14:32», «ontem, 09:10» ou «03/10, 17:45»
     GFT.setNow(data)                  a hora do SERVIDOR (cada API a devolve), para não depender do relógio nem do fuso do computador
   ========================================================================= */
(function () {
  'use strict';
  const LABEL = { online: 'Online', away: 'Ausente', pause: 'Em pausa', offline: 'Offline' };
  let offset = 0;
  const parse = s => {
    const m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0)) : null;
  };
  const setNow = s => { const d = parse(s); if (d) offset = d.getTime() - Date.now(); };
  const nowEst = () => new Date(Date.now() + offset);
  function when(s) {
    const d = parse(s); if (!d) return '—';
    const n = nowEst(), diff = (n - d) / 1000, hm = `${pad(d.getHours())}:${pad(d.getMinutes())}`;
    if (diff < 45) return 'agora';
    if (diff < 3600) return `há ${Math.max(1, Math.round(diff / 60))} min`;
    const y = new Date(n); y.setDate(y.getDate() - 1);
    if (d.toDateString() === n.toDateString()) return `hoje, ${hm}`;
    if (d.toDateString() === y.toDateString()) return `ontem, ${hm}`;
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}, ${hm}`;
  }
  const full = s => { const d = parse(s); return d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}` : '—'; };
  const day = s => { const d = parse(s); return d ? `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}` : '—'; };
  const mins = n => n == null ? '—' : (n >= 60 ? `${Math.floor(n / 60)} h ${pad(n % 60)} min` : `${n} min`);
  function avatar(photo, name, size = 44) {
    const ini = String(name || '?').trim().charAt(0).toUpperCase() || '?';
    return photo ? `<img class="gft-avatar" src="${esc(photo)}" alt="" width="${size}" height="${size}" loading="lazy">`
                 : `<span class="gft-avatar gft-initial" style="width:${size}px;height:${size}px;font-size:${Math.round(size * .42)}px" aria-hidden="true">${esc(ini)}</span>`;
  }
  const chip = state => `<span class="status-chip st-${esc(state)}"><i aria-hidden="true"></i>${esc(LABEL[state] || state)}</span>`;
  const meter = (pct, label) => pct == null ? '<div class="meter empty" role="img" aria-label="' + esc(label) + ': sem dados"><span style="width:0"></span></div>'
    : `<div class="meter" role="progressbar" aria-label="${esc(label)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="width:${Math.min(100, pct)}%"></span></div>`;
  window.GFT = { LABEL, parse, setNow, when, full, day, mins, avatar, chip, meter };
})();
