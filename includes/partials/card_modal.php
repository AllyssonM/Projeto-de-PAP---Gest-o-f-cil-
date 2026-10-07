<?php
/* Janela "Adicionar cartão" (demonstração). Usada no dashboard.php e na area-pessoal.php.
   Só o dono a vê; a lógica está em assets/js/cards.js e api/cards.php. */
if (empty($isOwner)) return;
?>
<!-- ===== ADICIONAR CARTÃO (demonstração) ===== -->
<div id="card-modal" class="reminder-modal card-modal hidden" role="dialog" aria-modal="true" aria-labelledby="cm-title">
  <div class="reminder-card card-dialog" id="cm-dialog" data-state="form">
    <button type="button" class="reminder-close" id="cm-x" aria-label="Fechar">×</button>

    <ol class="cm-steps" aria-hidden="true">
      <li data-step="form" class="on"><i>1</i><span>Cartão</span></li>
      <li data-step="processing"><i>2</i><span>A guardar</span></li>
      <li data-step="success"><i>3</i><span>Concluído</span></li>
    </ol>

    <div class="cm-body">
      <!-- cartão digital -->
      <div class="cm-left">
        <div class="cc-scene" id="cc-scene">
          <div class="cc-tilt" id="cc-tilt">
            <div class="cc" id="cc" data-brand="">
              <div class="cc-face cc-front">
                <span class="cc-shine"></span><span class="cc-glare"></span>
                <div class="cc-top">
                  <span class="cc-chip"></span>
                  <svg class="cc-nfc" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M8 6c3 3.5 3 8.5 0 12M12 4c4.5 5 4.5 11 0 16M16 2c6 6.5 6 13.5 0 20"/></svg>
                  <span class="cc-brand" id="cc-brand"></span>
                </div>
                <div class="cc-number" id="cc-number"></div>
                <div class="cc-bottom">
                  <div><small>TITULAR</small><b id="cc-name">NOME DO TITULAR</b></div>
                  <div><small>VALIDADE</small><b id="cc-exp">MM/AA</b></div>
                </div>
                <span class="cc-ok" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></span>
              </div>
              <div class="cc-face cc-back">
                <span class="cc-stripe"></span>
                <div class="cc-sign"><span class="cc-sig-line"></span><span class="cc-cvv" id="cc-cvv"></span></div>
                <small class="cc-cvv-label">CVV / CVC</small>
                <small class="cc-fine">Cartão de demonstração. Nenhum pagamento é feito.</small>
              </div>
            </div>
          </div>
        </div>
        <p class="cm-note">Demonstração: o número completo e o CVV nunca são enviados nem guardados. Só ficam a bandeira, os últimos 4 dígitos, o titular e a validade.</p>
      </div>

      <!-- formulário -->
      <div class="cm-right">
        <form id="cm-form" novalidate autocomplete="off">
          <p class="eyebrow">ADICIONAR CARTÃO</p>
          <h2 id="cm-title">Dados do cartão</h2>
          <p class="muted cm-sub">Introduz os dados para associar o cartão à tua conta.</p>

          <label class="cm-field">Nome do titular
            <span class="cm-input"><input name="holder" id="cm-holder" type="text" inputmode="text" autocomplete="cc-name" maxlength="40" placeholder="Como aparece no cartão" spellcheck="false"></span>
            <em class="cm-err" data-for="holder"></em>
          </label>
          <label class="cm-field">Número do cartão
            <span class="cm-input"><input name="number" id="cm-number" type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000" spellcheck="false"><b class="cm-brandtag" id="cm-brandtag"></b></span>
            <em class="cm-err" data-for="number"></em>
          </label>
          <div class="cm-row">
            <label class="cm-field">Validade
              <span class="cm-input"><input name="exp" id="cm-exp" type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA" spellcheck="false"></span>
              <em class="cm-err" data-for="exp"></em>
            </label>
            <label class="cm-field">CVV / CVC
              <span class="cm-input"><input name="cvv" id="cm-cvv" type="password" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="•••" spellcheck="false"></span>
              <em class="cm-err" data-for="cvv"></em>
            </label>
          </div>

          <label class="cm-toggle"><input type="checkbox" name="is_primary" id="cm-primary"><span class="cm-switch" aria-hidden="true"></span><span>Definir como cartão principal</span></label>
          <p class="cm-alert" id="cm-alert" role="alert" hidden></p>

          <button type="submit" class="button primary cm-submit" id="cm-save"><span>Guardar cartão</span></button>
        </form>

        <!-- estados de processamento e sucesso -->
        <div class="cm-overlay" id="cm-overlay" aria-live="polite">
          <div class="cm-pane cm-processing">
            <div class="cm-spinner" aria-hidden="true"><i></i><i></i></div>
            <h3>A guardar o cartão…</h3>
            <p class="muted">Só os últimos 4 dígitos são guardados.</p>
          </div>
          <div class="cm-pane cm-success">
            <div class="cm-check" aria-hidden="true">
              <span class="cm-ring"></span><span class="cm-ring r2"></span>
              <svg viewBox="0 0 52 52" width="64" height="64" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><circle class="cm-check-c" cx="26" cy="26" r="22"/><path class="cm-check-p" d="M15 27l8 8 15-17"/></svg>
            </div>
            <h3>Cartão adicionado com sucesso</h3>
            <p class="muted cm-success-num" id="cm-success-num"></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
