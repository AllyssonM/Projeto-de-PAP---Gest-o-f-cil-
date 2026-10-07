/* =========================================================================
   IDIOMAS (i18n)  (assets/js/i18n.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  traduz TODA a interface para o idioma escolhido (pt, en, es) sem duplicar páginas.
   COMO:  o português é o texto de origem (está nas páginas e nos scripts). Os dicionários assets/i18n/<idioma>.json são listas
          "texto em português": "tradução". O motor percorre a página (textos e atributos: placeholder, title, aria-label,
          alt, data-tip...) e troca cada texto pela tradução; um observador faz o mesmo ao que os scripts acrescentam
          depois (avisos, tabelas, janelas...), por isso mensagens de sucesso/erro, validações e estados de carregamento
          também ficam traduzidos.
   TEXTOS COM VALORES:  chaves com {0}, {1}... (ex.: "{0} conta(s) em atraso" -> "{0} overdue account(s)") são "padrões":
          os valores (números, nomes) mantêm-se e só a frase é traduzida.
   NÃO TRADUZ:  conteúdo escrito pelo utilizador (nomes de clientes, notas...) a não ser que coincida com um texto do sistema;
          zonas marcadas com data-no-i18n (as respostas da Lumina vêm do servidor já no idioma certo).
   NOVO IDIOMA:  criar assets/i18n/<sigla>.json e acrescentar a sigla em assets/js/i18n-boot.js, includes/lang.php e no seletor.
   API:  window.i18n.t('texto')  ·  window.i18n.setLang('en')  ·  window.t = i18n.t  ·  evento "gf:i18n-ready".
   ========================================================================= */
(function () {
  'use strict';
  var lang = window.LUMINA_LANG || 'pt';
  var ATTRS = ['placeholder', 'title', 'aria-label', 'alt', 'data-tip', 'aria-placeholder', 'aria-description', 'aria-roledescription', 'label', 'data-confirm'];
  var SKIP = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEXTAREA: 1, CODE: 0, PRE: 0, SVG: 0 };
  var exact = Object.create(null), buckets = Object.create(null), generic = [], cache = Object.create(null);
  var ready = lang === 'pt';
  var textRec = new WeakMap();      // nó de texto -> { out }  (o que escrevemos; se o nó mudar, é texto novo do programa)
  var attrRec = new WeakMap();      // elemento -> { attr: out }

  function norm(s) { return s.replace(/\s+/g, ' ').trim(); }

  /* ---------- dicionário ---------- */
  function load(dict) {
    Object.keys(dict).forEach(function (k) {
      var v = dict[k];
      if (typeof v !== 'string' || v === '') return;
      if (k.indexOf('{') > -1 && /\{\d+\}/.test(k)) {
        var names = [], parts = k.split(/\{(\d+)\}/);
        var src = '';
        for (var i = 0; i < parts.length; i++) {
          if (i % 2) {                                                   // valor dinâmico; colado a uma letra ("movimento{1}") = sufixo de plural ('', 's', 'm', 'ns') ou texto a seguir (", Maria")
            names.push(+parts[i]);
            src += /[A-Za-zÀ-ÿ]$/.test(parts[i - 1]) ? '([A-Za-zÀ-ÿ]{0,2}|[^A-Za-zÀ-ÿ][\\s\\S]*?)' : '(.+?)';
          } else src += parts[i].replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+');
        }
        var prefix = parts[0].trim().slice(0, 3);
        var letters = k.replace(/\{\d+\}/g, '').replace(/[^A-Za-zÀ-ÿ]/g, '').length;
        // padrões "magros" ("{0} de {1}") só valem para números; senão trocariam metade de qualquer frase ("Preço de venda" -> "Preço of venda")
        var numeric = parts[0] === '' ? letters < 8 : letters < 4;
        var entry = { re: new RegExp('^' + src + '$', 's'), names: names, out: v, len: k.length, numeric: numeric };
        if (prefix.length >= 3) (buckets[prefix] = buckets[prefix] || []).push(entry); else generic.push(entry);
      } else exact[norm(k)] = v;
    });
    Object.keys(buckets).forEach(function (b) { buckets[b].sort(function (a, c) { return c.len - a.len; }); });
    generic.sort(function (a, c) { return c.len - a.len; });
  }

  /** Traduz um texto (sem espaços nas pontas). Devolve null se não houver tradução. */
  function lookup(text) {
    if (!text) return null;
    if (text in exact) return exact[text];
    if (text in cache) return cache[text];
    var hit = null, list = text.indexOf('::') > -1 ? [] : (buckets[text.slice(0, 3)] || []).concat(generic);
    for (var i = 0; i < list.length && !hit; i++) {
      var m = list[i].re.exec(text);
      if (m && list[i].numeric && m.slice(1).some(function (x) { return !/^[\d.,%€\s\/:+\-]*$/.test(x); })) m = null;
      if (m) {
        var e = list[i];
        hit = e.out.replace(/\{(\d+)(?::([^|}]*)\|([^}]*))?\}/g, function (_, n, one, many) {
          var idx = e.names.indexOf(+n); var arg = idx > -1 ? m[idx + 1] : '';
          if (one !== undefined) return arg === '' ? one : many;               // {n:singular|plural}: o valor pt vazio = singular
          var a = norm(arg), o = a ? (lkc(a) != null ? lkc(a) : loose(a)) : null;   // o próprio valor também se traduz (ex.: "produtos", " · 2 já existiam")
          return o != null ? (/^\s/.test(arg) ? ' ' : '') + o : arg;
        });
      }
    }
    if (Object.keys(cache).length > 4000) cache = Object.create(null);
    cache[text] = hit;
    return hit;
  }


  /** Contexto do texto que se está a traduzir (data-i18n-ctx): "contexto::texto" tem prioridade sobre "texto". */
  var CTX = null;
  function lk(t) { if (CTX) { var c = lookup(CTX + '::' + t); if (c != null) return c; } return lookup(t); }
  /** Como lk, mas "peça" encontra "Peça" (e vice-versa): palavras soltas dentro de frases mudam de maiúscula. */
  function lkc(t) {
    var r = lk(t); if (r != null || !t) return r;
    var f = t.charAt(0);
    if (f !== f.toUpperCase()) { r = lk(f.toUpperCase() + t.slice(1)); return r == null ? null : r.charAt(0).toLowerCase() + r.slice(1); }
    if (t.slice(1) !== t.slice(1).toUpperCase()) { r = lk(f.toLowerCase() + t.slice(1)); return r == null ? null : r.charAt(0).toUpperCase() + r.slice(1); }
    return null;
  }

  /** Textos "colados": separadores (" · ", " — ", " | "), pontuação, emojis e símbolos à volta não impedem a tradução de cada parte; MAIÚSCULAS também. */
  var EDGE = /^([\s\p{P}\p{S}]*)([\s\S]*?)([\s\p{P}\p{S}]*)$/u;
  var SEP = /(\s+[·•|—–]\s+)/;
  function loose(n) {
    var parts = n.split(SEP);
    if (parts.length > 1) {
      var any = false, outp = parts.map(function (p, i) {
        if (i % 2) return p;
        var o = lkc(p); if (o == null) o = loose1(p); if (o != null) any = true; return o != null ? o : p;
      });
      return any ? outp.join('') : null;
    }
    return loose1(n);
  }
  function loose1(n) {
    var m = EDGE.exec(n); if (!m || !m[2]) return null;
    var core = m[2], o = null;
    if (m[1]) { o = lk(core + m[3]); if (o != null) return m[1] + o; }                      // só tira o que está ANTES (emoji, "· "): o ponto final faz parte do texto
    if (m[1] || m[3]) { o = lkc(core); if (o != null) return m[1] + o + m[3]; }
    if (core.length > 2 && core === core.toUpperCase() && core !== core.toLowerCase()) {          // "NOVA PEÇA": procura "Nova peça" e devolve em maiúsculas
      var low = core.toLowerCase(), cap = low.charAt(0).toUpperCase() + low.slice(1);
      var t = lk(cap); if (t == null) t = lk(low);
      if (t != null) return m[1] + t.toUpperCase() + m[3];
    }
    return null;
  }

  function tr(s) {
    if (s == null || lang === 'pt') return s;
    var str = String(s), n = norm(str);
    var out = lk(n);
    if (out == null) out = loose(n);
    if (out == null) return str;
    var lead = str.match(/^\s*/)[0], trail = str.match(/\s*$/)[0];
    return lead + out + trail;
  }

  /* ---------- percorrer a página ---------- */
  function skipEl(el) {
    for (var e = el; e && e.nodeType === 1; e = e.parentElement) {
      if (SKIP[e.tagName] === 1 || e.hasAttribute('data-no-i18n') || e.isContentEditable) return true;
    }
    return false;
  }
  var missing = window.LUMINA_I18N_DEBUG ? (window.LUMINA_I18N_MISSING = new Set()) : null;

  function ctxOf(el) { var c = el && el.closest && el.closest('[data-i18n-ctx]'); return c ? c.getAttribute('data-i18n-ctx') : null; }
  function doText(node) {
    var rec = textRec.get(node), cur = node.data;
    if (rec && rec.out === cur) return;                       // já traduzido por nós
    if (!/\S/.test(cur)) return;
    CTX = ctxOf(node.parentElement);
    var out = tr(cur); CTX = null;
    if (out !== cur) { textRec.set(node, { out: out }); node.data = out; }
    else if (missing && /[A-Za-zÀ-ÿ]{2,}/.test(cur)) missing.add(norm(cur));
  }
  function doAttrs(el) {
    if (skipEl(el)) return;
    var rec = attrRec.get(el);
    for (var i = 0; i < ATTRS.length; i++) {
      var a = ATTRS[i];
      if (!el.hasAttribute(a)) continue;
      var cur = el.getAttribute(a);
      if (rec && rec[a] === cur) continue;
      CTX = ctxOf(el); var out = tr(cur); CTX = null;
      if (out !== cur) { rec = rec || {}; rec[a] = out; el.setAttribute(a, out); }
      else if (missing && /[A-Za-zÀ-ÿ]{2,}/.test(cur)) missing.add(norm(cur));
    }
    if (el.tagName === 'INPUT' && /^(submit|button|reset)$/.test(el.type) && el.hasAttribute('value')) {
      var v = el.getAttribute('value'); var ov = tr(v); if (ov !== v) { rec = rec || {}; rec.value = ov; el.setAttribute('value', ov); }
    }
    if (rec) attrRec.set(el, rec);
  }
  function walk(root) {
    if (!ready || lang === 'pt' || !root) return;
    if (root.nodeType === 3) { if (root.parentElement && !skipEl(root.parentElement)) doText(root); return; }
    if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return;
    var base = root.nodeType === 1 ? root : (root.documentElement || root);
    if (root.nodeType === 1 && skipEl(root)) return;
    if (base.nodeType === 1) doAttrs(base);
    var w = document.createTreeWalker(root, 1 | 4, {
      acceptNode: function (n) {
        if (n.nodeType === 1) return (SKIP[n.tagName] === 1 || n.hasAttribute('data-no-i18n') || n.isContentEditable) ? 2 : 1;   // 2 = salta a árvore
        return 1;
      }
    });
    var n;
    while ((n = w.nextNode())) { if (n.nodeType === 3) doText(n); else doAttrs(n); }
  }

  /* ---------- observador ---------- */
  var queue = new Set(), scheduled = false;
  function flush() {
    scheduled = false;
    var items = Array.from(queue); queue.clear();
    obs && obs.disconnect();
    items.forEach(function (n) { if (n.isConnected !== false) walk(n); });
    observe();
  }
  function enqueue(n) { queue.add(n); if (!scheduled) { scheduled = true; (window.queueMicrotask || setTimeout)(flush); } }
  var obs = null;
  function observe() {
    if (!obs) obs = new MutationObserver(function (list) {
      list.forEach(function (m) {
        if (m.type === 'childList') m.addedNodes.forEach(function (n) { enqueue(n); });
        else if (m.type === 'characterData') enqueue(m.target);
        else if (m.type === 'attributes') enqueue(m.target);
      });
    });
    obs.observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS.concat(['value']) });
  }

  /* ---------- idioma: guardar e mudar ---------- */
  function setLang(l) {
    if (['pt', 'en', 'es'].indexOf(l) < 0) return;
    try { localStorage.setItem('lumina-lang', l); } catch (e) {}
    try { document.cookie = 'lumina_lang=' + l + ';path=/;max-age=31536000;SameSite=Lax'; } catch (e) {}
    var done = function () { location.reload(); };                       // recarrega: tudo (datas, gráficos, Lumina) nasce no idioma novo
    var user = window.GF_USER, token = window.GF_CSRF || (typeof csrf !== 'undefined' ? csrf : '');
    if (user && token) {                                                 // com sessão: guarda também na conta (vale noutros aparelhos)
      fetch('api/me.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_lang', lang: l, csrf: token }) })
        .then(done, done);
    } else done();
  }

  function markCurrent() {
    document.querySelectorAll('[data-lang]').forEach(function (b) {
      var on = b.getAttribute('data-lang') === lang; b.setAttribute('aria-current', on ? 'true' : 'false');
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lang]');
    if (b) { e.preventDefault(); setLang(b.getAttribute('data-lang')); return; }
    var t = e.target.closest && e.target.closest('[data-lang-toggle]');
    var pops = document.querySelectorAll('.lang-pop');
    pops.forEach(function (p) { if (!t || p.parentElement !== t.parentElement) p.hidden = true; });
    if (t) { var pop = t.parentElement.querySelector('.lang-pop'); if (pop) { pop.hidden = !pop.hidden; t.setAttribute('aria-expanded', String(!pop.hidden)); } }
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.querySelectorAll('.lang-pop').forEach(function (p) { p.hidden = true; }); });

  function finish() {
    ready = true;
    if (lang !== 'pt') { walk(document); document.title = tr(document.title); observe(); }
    markCurrent();
    document.documentElement.classList.remove('i18n-wait');
    document.dispatchEvent(new CustomEvent('gf:i18n-ready'));
  }

  function tPublic(x) { var prev = CTX; CTX = CTX || ctxOf(document.documentElement); try { return tr(x); } finally { CTX = prev; } }
  window.i18n = { t: tPublic, lang: lang, setLang: setLang, walk: walk };
  window.t = tPublic;

  function start() {
    if (lang === 'pt') { markCurrent(); document.dispatchEvent(new CustomEvent('gf:i18n-ready')); return; }
    (window.LUMINA_DICT || Promise.reject()).then(function (d) { load(d); }).catch(function () { /* sem dicionário: fica em português */ })
      .then(function () { if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', finish); else finish(); });
  }
  start();
})();
