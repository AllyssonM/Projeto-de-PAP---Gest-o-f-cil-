/* =========================================================================
   IMPORTAR MOVIMENTOS DE UM CSV  (assets/js/import.js)
   -------------------------------------------------------------------------
   No Fluxo de caixa, «Importar CSV» abre um assistente em 3 passos, sem gravar nada até a pessoa confirmar:
     1. escolhe o ficheiro (o extrato do banco ou do Excel, exportado em CSV);
     2. vê a PRÉ-VISUALIZAÇÃO: o que o Lumina percebeu em cada coluna (e corrige se for preciso), quantos movimentos são novos,
        quantos já existem (e serão ignorados) e que linhas têm erro e porquê;
     3. confirma. Fica um botão «Anular esta importação» para desfazer tudo.
   Toda a leitura e validação é feita no servidor (api/import.php + includes/csv_import.php); aqui só se mostra e se escolhe.
   ========================================================================= */
(function () {
  'use strict';
  const btn = $('#cf-import');
  if (!btn) return;
  const eur = n => money.format(Number(n) || 0);
  const FIELDS = [['date', 'Data', true], ['description', 'Descrição', false], ['amount', 'Valor (com sinal)', false], ['debit', 'Débito (saídas)', false], ['credit', 'Crédito (entradas)', false], ['category', 'Categoria', false]];
  const STATUS = { ok: ['Novo', 'chip-ok'], duplicate: ['Já existe', 'chip-priv'] };

  function open() {
    const { root: m, close } = UX.modal(`<h2>Importar movimentos de um CSV</h2>
      <div id="imp-body"><p class="muted">Exporta o extrato do banco (ou do Excel) em <b>CSV</b> e escolhe o ficheiro. Nada é gravado até confirmares. Máximo 1 MB e 2000 linhas.</p>
        <label class="button secondary imp-pick">📄 Escolher ficheiro CSV<input type="file" accept=".csv,.txt,text/csv,text/plain" hidden></label>
        <p class="ap-form-error" role="alert" hidden></p></div>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Fechar</button><button type="button" class="button primary" data-yes hidden disabled>Importar</button></div>`, { wide: true });
    const body = m.querySelector('#imp-body'), yes = m.querySelector('[data-yes]');
    let file = null, mapping = null, opts = { sign_mode: 'auto', default_category: '' }, seq = 0, lastBatch = null;
    m.querySelector('[data-no]').onclick = () => close(false);

    const send = (action, extra = {}) => {
      const fd = new FormData(); fd.append('action', action); fd.append('file', file); fd.append('csrf', csrf);
      if (mapping) fd.append('mapping', JSON.stringify(mapping)); fd.append('options', JSON.stringify(opts));
      Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
      return api('import.php', { method: 'POST', body: fd });
    };
    const fail = t => { let e = body.querySelector('.ap-form-error'); if (!e) { e = document.createElement('p'); e.className = 'ap-form-error'; e.setAttribute('role', 'alert'); body.prepend(e); } e.textContent = t; e.hidden = !t; };

    async function preview() {
      const my = ++seq;
      try {
        const d = await send('preview');
        if (my !== seq) return;
        mapping = Object.fromEntries(Object.entries(d.mapping)); draw(d);
      } catch (e) { fail(e.message); yes.hidden = true; }
    }
    function select(name, label, headers, cur, required) {
      return `<label>${esc(label)}${required ? ' *' : ''}<select name="${name}"><option value="-1">${required ? '— escolhe —' : '— nenhuma —'}</option>${headers.map((h, i) => `<option value="${i}"${cur === i ? ' selected' : ''}>${esc(h || 'Coluna ' + (i + 1))}</option>`).join('')}</select></label>`;
    }
    function draw(d) {
      const s = d.summary, noSign = !(mapping.amount != null) && (mapping.debit != null || mapping.credit != null);
      body.innerHTML = `<p class="imp-file"><b>${esc(file.name)}</b> · ${d.total} linha${d.total === 1 ? '' : 's'} · separador «${d.delimiter === '\t' ? 'tab' : esc(d.delimiter)}»</p>
        <p class="ap-form-error" role="alert" hidden></p>
        <fieldset class="imp-map"><legend>O que é cada coluna? <small class="muted">(o Lumina já sugeriu; corrige se estiver errado)</small></legend>
          <div class="imp-map-grid">${FIELDS.map(([k, l, r]) => select(k, l, d.headers, mapping[k] ?? -1, r)).join('')}</div>
          <div class="imp-opts"><label>Sinal do valor<select name="sign_mode"><option value="auto"${opts.sign_mode === 'auto' ? ' selected' : ''}>Automático (negativo = despesa)</option><option value="income"${opts.sign_mode === 'income' ? ' selected' : ''}>Tudo são entradas</option><option value="expense"${opts.sign_mode === 'expense' ? ' selected' : ''}>Tudo são despesas</option></select></label>
            <label>Categoria por omissão<input name="default_category" maxlength="80" placeholder="Importado" value="${esc(opts.default_category)}"></label></div></fieldset>
        <div class="imp-sum" aria-live="polite">${d.needs_mapping ? `<p class="muted">${esc(d.message || 'Escolhe as colunas.')}</p>` :
          `<span class="chip-ok"><b>${s.valid}</b> novo${s.valid === 1 ? '' : 's'}</span> <span class="chip-priv"><b>${s.duplicates}</b> já existe${s.duplicates === 1 ? '' : 'm'} (ignorado${s.duplicates === 1 ? '' : 's'})</span> <span class="${s.errors ? 'chip-err' : 'chip-priv'}"><b>${s.errors}</b> com erro</span>`}</div>
        ${d.sample.length ? `<div class="table-wrap"><table class="staff-table imp-table"><caption class="sr-only">Primeiras linhas, já convertidas</caption><thead><tr><th>Linha</th><th>Data</th><th>Descrição</th><th>Tipo</th><th>Valor</th><th>Estado</th></tr></thead><tbody>
          ${d.sample.map(r => `<tr><td>${r.line}</td><td>${esc(r.occurred_at.slice(8, 10) + '/' + r.occurred_at.slice(5, 7) + '/' + r.occurred_at.slice(0, 4))}</td><td>${esc(r.description)}</td><td>${r.type === 'income' ? 'Entrada' : 'Saída'}</td><td>${eur(r.type === 'income' ? r.amount : -r.amount)}</td><td><span class="${STATUS[r.status][1]}">${STATUS[r.status][0]}</span></td></tr>`).join('')}</tbody></table></div>` : ''}
        ${d.errors.length ? `<details class="imp-errs" open><summary>${s.errors} linha${s.errors === 1 ? '' : 's'} com erro (não serão importadas)</summary><ul>${d.errors.map(e => `<li>Linha ${e.line}: ${esc(e.reason)}</li>`).join('')}</ul>${s.errors > d.errors.length ? `<small class="muted">…e mais ${s.errors - d.errors.length}.</small>` : ''}</details>` : ''}`;
      yes.hidden = false; yes.disabled = !!d.needs_mapping || s.valid === 0; yes.textContent = d.needs_mapping ? 'Importar' : `Importar ${s.valid} movimento${s.valid === 1 ? '' : 's'}`;
      body.querySelectorAll('select[name], input[name=default_category]').forEach(el => el.addEventListener('change', () => {
        if (el.name === 'sign_mode' || el.name === 'default_category') opts[el.name] = el.value;
        else { const v = +el.value; if (v < 0) delete mapping[el.name]; else mapping[el.name] = v; }
        preview();
      }));
    }

    m.querySelector('input[type=file]').addEventListener('change', e => {
      file = e.target.files[0]; if (!file) return;
      if (file.size > 1048576) return fail('O ficheiro é demasiado grande (máximo 1 MB). Divide-o em partes.');
      mapping = null; preview();
    });

    yes.onclick = async () => {
      yes.disabled = true;
      try {
        const r = await send('import'); lastBatch = r.batch; window.loadAll?.();
        body.innerHTML = `<div class="imp-done"><h3>✔ Importação concluída</h3><p><b>${r.imported}</b> movimento${r.imported === 1 ? '' : 's'} importado${r.imported === 1 ? '' : 's'}${r.duplicates ? ` · ${r.duplicates} já existia${r.duplicates === 1 ? '' : 'm'} (ignorado${r.duplicates === 1 ? '' : 's'})` : ''}${r.errors ? ` · ${r.errors} linha${r.errors === 1 ? '' : 's'} com erro` : ''}.</p>
          <p class="muted">Enganaste-te? Podes desfazer tudo o que veio deste ficheiro (os movimentos que fizeste à mão não são tocados).</p><p class="ap-form-error" role="alert" hidden></p><button type="button" class="button secondary" id="imp-undo">↩ Anular esta importação</button></div>`;
        yes.hidden = true; m.querySelector('[data-no]').textContent = 'Fechar';
        body.querySelector('#imp-undo').onclick = async () => {
          if (!await UX.confirm({ title: 'Anular esta importação?', text: `Apaga os ${r.imported} movimentos que vieram deste ficheiro.`, confirmLabel: 'Anular importação', danger: true })) return;
          try { const u = await api('import.php', { method: 'POST', body: JSON.stringify({ action: 'undo', batch: lastBatch, csrf }) }); window.loadAll?.(); body.innerHTML = `<div class="imp-done"><h3>↩ Importação anulada</h3><p>${u.deleted} movimento${u.deleted === 1 ? '' : 's'} removido${u.deleted === 1 ? '' : 's'}.</p></div>`; msg('Importação anulada.'); }
          catch (x) { fail(x.message); }
        };
        msg(`${r.imported} movimentos importados.`);
      } catch (e) { fail(e.message); yes.disabled = false; }
    };
  }
  btn.addEventListener('click', open);
  window.GFImport = { open };
})();
