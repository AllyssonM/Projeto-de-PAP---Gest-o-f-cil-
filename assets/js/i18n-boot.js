/* =========================================================================
   IDIOMA — ARRANQUE  (assets/js/i18n-boot.js)   [corre no <head>, antes de tudo]
   -------------------------------------------------------------------------
   Decide o idioma (pt, en ou es) e deixa-o em window.LUMINA_LANG / window.LUMINA_LOCALE.
   ORDEM:  1) escolha manual guardada (localStorage "lumina-lang")  2) preferência da conta (window.LUMINA_ACCOUNT_LANG)
           3) idioma do navegador (navigator.languages)  4) fuso horário (ex.: America/Sao_Paulo -> pt)  5) português.
   A deteção automática NÃO é guardada como escolha manual: só quem escolhe no menu fixa o idioma.
   Escreve o cookie "lumina_lang" (o servidor usa-o para a Lumina, emails e documentos legais) e, se não for português,
   esconde a página por instantes até estar traduzida (evita ver o português a piscar) e pede o dicionário.
   ========================================================================= */
(function () {
  'use strict';
  var SUP = ['pt', 'en', 'es'], LOC = { pt: 'pt-PT', en: 'en-GB', es: 'es-ES' };
  var lang = null, manual = false;
  try { var s = localStorage.getItem('lumina-lang'); if (SUP.indexOf(s) > -1) { lang = s; manual = true; } } catch (e) {}
  if (!lang && SUP.indexOf(window.LUMINA_ACCOUNT_LANG) > -1) { lang = window.LUMINA_ACCOUNT_LANG; manual = true; try { localStorage.setItem('lumina-lang', lang); } catch (e) {} }
  if (!lang) {
    var list = (navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || '']);
    for (var i = 0; i < list.length && !lang; i++) { var c = String(list[i] || '').toLowerCase().slice(0, 2); if (SUP.indexOf(c) > -1) lang = c; }
    if (!lang) {                                              // idioma não suportado (ex.: francês): tenta o fuso horário
      var tz = ''; try { tz = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {}
      if (/^(Europe\/(Lisbon|Madrid)|Atlantic\/(Madeira|Azores|Canary)|America\/(Sao_Paulo|Bahia|Fortaleza|Recife|Manaus|Belem|Mexico_City|Bogota|Lima|Argentina|Santiago|Caracas|Montevideo)|Africa\/(Luanda|Maputo)|Europe\/London|America\/(New_York|Chicago|Denver|Los_Angeles)|Australia)/.test(tz)) {
        lang = /Lisbon|Madeira|Azores|Sao_Paulo|Bahia|Fortaleza|Recife|Manaus|Belem|Luanda|Maputo/.test(tz) ? 'pt' : (/London|New_York|Chicago|Denver|Los_Angeles|Australia/.test(tz) ? 'en' : 'es');
      }
    }
    if (!lang) lang = 'pt';
  }
  window.LUMINA_LANG = lang; window.LUMINA_LOCALE = LOC[lang]; window.LUMINA_LANG_MANUAL = manual;
  document.documentElement.lang = LOC[lang];
  try { document.cookie = 'lumina_lang=' + lang + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {}
  if (lang !== 'pt') {
    var me = document.currentScript, root = new URL('../i18n/', me.src);
    window.LUMINA_DICT = fetch(root + lang + '.json?v=' + (me.getAttribute('data-v') || ''), { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    document.documentElement.classList.add('i18n-wait');
    setTimeout(function () { document.documentElement.classList.remove('i18n-wait'); }, 2500);       // nunca deixa a página escondida
  }
})();
