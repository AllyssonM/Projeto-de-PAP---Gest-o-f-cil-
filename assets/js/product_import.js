/* =========================================================================
   IMPORTAR / EXPORTAR PRODUTOS POR CSV  (assets/js/product_import.js)
   -------------------------------------------------------------------------
   No Estoque, «Importar CSV» abre o mesmo tipo de assistente do Fluxo de caixa (assets/js/import.js):
     1. escolhe o ficheiro; 2. vê a PRÉ-VISUALIZAÇÃO (nada é gravado) e corrige as colunas se preciso; 3. confirma.
   Só CRIA produtos novos: os que já existem (nome + marca) ficam como estão. Fica um botão «Anular esta importação».
   «Exportar CSV» descarrega todos os produtos (o mesmo formato que a importação lê). Toda a lógica está em api/product_import.php.
   ========================================================================= */
(function () {
  'use strict';
  const btn = $('#st-import'), exp = $('#st-export');
  if (!btn) return;
  const eur = n => money.format(Number(n) || 0);
  const STATUS = { ok: ['Novo', 'chip-ok'], duplicate: ['Já existe', 'chip-priv'] };

  function open() {
    const { root: m, close } = UX.modal(`<h2>Importar produtos de um CSV</h2>
      <div id="pimp-body"><p class="muted">Escolhe um ficheiro <b>CSV</b> (do Excel ou exportado daqui). Só são criados produtos novos: os que já existem <b>não são alterados</b>. Nada é gravado até confirmares. Máximo 1 MB e 2000 linhas.</p>
        <label class="button secondary imp-pick">📄 Escolher ficheiro CSV<input type="file" accept=".csv,.txt,text/csv,text/plain" hidden></label>
        <p class="ap-form-error" role="alert" hidden></p></div>
      <div class="ap-modal-actions"><button type="button" class="button secondary" data-no>Fechar</button><button type="button" class="button primary" data-yes hidden disabled>Importar</button></div>`, { wide: true });
    const body = m.querySelector('#pimp-body'), yes = m.querySelector('[data-yes]');
    let file = null, mapping = null, seq = 0, lastBatch = null;
    m.querySelector('[data-no]').onclick = () => close(false);

    const send = (action) => {
      const fd = new FormData(); fd.append('action', action); fd.append('file', file); fd.append('csrf', csrf);
      if (mapping) fd.append('mapping', JSON.stringify(mapping));
      return api('product_import.php', { method: 'POST', body: fd });
    };
    const fail = t => { let e = body.querySelector('.ap-form-error'); if (!e) { e = document.createElement('p'); e.className = 'ap-form-error'; e.setAttribute('role', 'alert'); body.prepend(e); } e.textContent = t; e.hidden = !t; };

    async function preview() {
      const my = ++seq;
      try { const d = await send('preview'); if (my !== seq) return; mapping = Object.assign({}, d.mapping); draw(d); }
      catch (e) { fail(e.message); yes.hidden = true; }
    }
    const select = (f, headers, cur) => `<label>${esc(f.label)}${f.required ? ' *' : ''}<select name="${esc(f.key)}"><option value="-1">${f.required ? '— escolhe —' : '— nenhuma —'}</option>${headers.map((h, i) => `<option value="${i}"${cur === i ? ' selected' : ''}>${esc(h || 'Coluna ' + (i + 1))}</option>`).join('')}</select></label>`;
    const plural = (n, a, b) => n === 1 ? a : b;

    function draw(d) {
      const s = d.summary;
      body.innerHTML = `<p class="imp-file"><b>${esc(file.name)}</b> · ${d.total} linha${plural(d.total, '', 's')} · separador «${d.delimiter === '\t' ? 'tab' : esc(d.delimiter)}»</p>
        <p class="ap-form-error" role="alert" hidden></p>
        <fieldset class="imp-map"><legend>O que é cada coluna? <small class="muted">(o Lumina já sugeriu; corrige se estiver errado)</small></legend>
          <div class="imp-map-grid">${d.fields.map(f => select(f, d.headers, mapping[f.key] ?? -1)).join('')}</div></fieldset>
        <div class="imp-sum" aria-live="polite">${d.needs_mapping ? `<p class="muted">${esc(d.message || 'Escolhe as colunas.')}</p>` :
          `<span class="chip-ok"><b>${s.products}</b> produto${plural(s.products, '', 's')} novo${plural(s.products, '', 's')} (${s.variants} variaç${s.variants === 1 ? 'ão' : 'ões'})</span> <span class="chip-priv"><b>${s.duplicates}</b> já existe${plural(s.duplicates, '', 'm')} (não alterado${plural(s.duplicates, '', 's')})</span> <span class="${s.errors ? 'chip-err' : 'chip-priv'}"><b>${s.errors}</b> linha${plural(s.errors, '', 's')} com erro</span>`}</div>
        ${d.sample.length ? `<div class="table-wrap"><table class="staff-table imp-table"><caption class="sr-only">Primeiros produtos, já convertidos</caption><thead><tr><th>Linha</th><th>Produto</th><th>Preço de venda</th><th>Variações</th><th>Quantidade</th><th>Estado</th></tr></thead><tbody>
          ${d.sample.map(r => `<tr><td>${r.line}</td><td>${esc(r.name)}${r.brand ? ' · ' + esc(r.brand) : ''}</td><td>${eur(r.sale_price)}</td><td>${r.variants}</td><td>${r.quantity}</td><td><span class="${(STATUS[r.status] || STATUS.ok)[1]}">${(STATUS[r.status] || STATUS.ok)[0]}</span></td></tr>`).join('')}</tbody></table></div>` : ''}
        ${d.errors.length ? `<details class="imp-errs" open><summary>${s.errors} linha${plural(s.errors, '', 's')} com erro (esse produto não será importado)</summary><ul>${d.errors.map(e => `<li>Linha ${e.line}: ${esc(e.reason)}</li>`).join('')}</ul>${s.errors > d.errors.length ? `<small class="muted">…e mais ${s.errors - d.errors.length}.</small>` : ''}</details>` : ''}`;
      yes.hidden = false; yes.disabled = !!d.needs_mapping || s.products === 0; yes.textContent = d.needs_mapping ? 'Importar' : `Importar ${s.products} produto${plural(s.products, '', 's')}`;
      body.querySelectorAll('select[name]').forEach(el => el.addEventListener('change', () => { const v = +el.value; if (v < 0) delete mapping[el.name]; else mapping[el.name] = v; preview(); }));
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
        body.innerHTML = `<div class="imp-done"><h3>✔ Importação concluída</h3><p><b>${r.created}</b> produto${plural(r.created, '', 's')} criado${plural(r.created, '', 's')} (${r.variants} variaç${r.variants === 1 ? 'ão' : 'ões'})${r.duplicates ? ` · ${r.duplicates} já existia${plural(r.duplicates, '', 'm')} (não alterado${plural(r.duplicates, '', 's')})` : ''}${r.errors ? ` · ${r.errors} linha${plural(r.errors, '', 's')} com erro (ignorada${plural(r.errors, '', 's')})` : ''}.</p>
          <p class="muted">Enganaste-te? Podes desfazer a importação: só se apagam os produtos que continuam como foram importados (sem edições, vendas ou ajustes de estoque). Só o responsável do negócio o pode fazer.</p><p class="ap-form-error" role="alert" hidden></p><button type="button" class="button secondary" id="pimp-undo">↩ Anular esta importação</button></div>`;
        yes.hidden = true; m.querySelector('[data-no]').textContent = 'Fechar';
        body.querySelector('#pimp-undo').onclick = async () => {
          if (!await UX.confirm({ title: 'Anular esta importação?', text: `Apaga os produtos criados por este ficheiro que ainda não foram mexidos.`, confirmLabel: 'Anular importação', danger: true })) return;
          try {
            const u = await api('product_import.php', { method: 'POST', body: JSON.stringify({ action: 'undo', batch: lastBatch, csrf }) }); window.loadAll?.();
            body.innerHTML = `<div class="imp-done"><h3>↩ Importação anulada</h3><p>${u.deleted} produto${plural(u.deleted, '', 's')} removido${plural(u.deleted, '', 's')}.</p>${u.kept.length ? `<p class="muted">Ficaram ${u.kept.length} porque já foram mexidos:</p><ul>${u.kept.map(k => `<li>${esc(k.name)}: ${esc(k.reason)}</li>`).join('')}</ul>` : ''}</div>`;
          } catch (x) { fail(x.message); }
        };
        msg(`${r.created} produtos importados.`);
      } catch (e) { fail(e.message); yes.disabled = false; }
    };
  }
  btn.addEventListener('click', open);
  if (exp) exp.addEventListener('click', () => { window.location.href = 'api/product_import.php?action=export'; });
})();
