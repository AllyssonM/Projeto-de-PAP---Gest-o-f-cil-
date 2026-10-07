/* =========================================================================
   PEÇAS COMUNS DO JAVASCRIPT  (assets/js/common.js)
   -------------------------------------------------------------------------
   O QUE FAZ:  reúne as funções que antes estavam repetidas em vários ficheiros
               ($, $$, esc, pad, reduced, api, msg e o token csrf). Carrega-se
               PRIMEIRO, antes de qualquer outro script, no painel e na Área pessoal.
   REGRA:      se precisares de uma destas funções, usa-a daqui. Não a definas outra vez.
   ========================================================================= */
const base = 'api/';                                                      // as páginas estão na raiz; a API em api/
const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];                        // devolve uma lista normal (map, filter, forEach)
let csrf = window.GF_CSRF || '';                                         // token de segurança; o painel atualiza-o depois do login

/** Escapa texto de utilizador antes de o pôr em innerHTML (evita XSS). Usar SEMPRE. */
const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const pad = n => String(n).padStart(2, '0');
const hhmm = t => String(t || '').slice(0, 5);                           // "14:30:00" -> "14:30"
const reduced = () => matchMedia('(prefers-reduced-motion: reduce)').matches;   // a pessoa pediu "reduzir movimento"

/** Pede dados à API. Sessão expirada -> volta ao login. Erros do servidor viram Error com a mensagem do servidor. */
async function api(url, options = {}) {
  const isForm = options.body instanceof FormData;                         // uploads: o navegador define o Content-Type
  const r = await fetch(base + url, {
    credentials: 'same-origin', ...options,
    headers: isForm ? (options.headers || {}) : { 'Content-Type': 'application/json', ...(options.headers || {}) },
  });
  if (r.status === 401) { window.location.href = 'index.php#autenticacao'; throw Error('Sessão necessária.'); }
  let d;
  try { d = await r.json(); } catch { throw Error('Resposta inválida do servidor.'); }
  if (!r.ok) { const err = Error(d.error || 'Erro no servidor'); err.data = d; err.status = r.status; throw err; }   // d: dados extra (ex.: produto repetido, conflito)
  return d;
}

/** Mensagem ao utilizador: barra de estado do painel (se existir) e aviso (toast). */
function msg(text, error = false) {
  const box = $('#status');
  if (box) { box.textContent = text; box.classList.toggle('error', error); }
  // As mensagens de arranque não precisam de aviso.
  if (window.UX && text && !['Pronto.', 'Dados carregados.', 'A carregar...'].includes(text)) window.UX.toast(error ? 'error' : 'success', text);
}
