/* =========================================================================
   SERVICE WORKER DO LUMINA  (sw.js)
   -------------------------------------------------------------------------
   Faz duas coisas, e só estas:
     1. Guarda em cache os ficheiros ESTÁTICOS (css, js, imagens, tipos de letra) para o Lumina abrir mais depressa.
     2. Se não houver rede ao abrir uma página, mostra offline.html em vez do erro do navegador.
   NUNCA guarda: a API (api/*), as páginas do painel nem nada com dados financeiros ou pessoais. Quem sai da conta
   não deixa dados no telemóvel. Não regista movimentos offline (isso seria guardar dinheiro "em cima do joelho").
   VERSÃO: mudar CACHE_VERSION apaga a cache antiga.
   ========================================================================= */
const CACHE_VERSION = 'lumina-static-v4';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL, 'assets/css/style.css', 'assets/css/ux.css', 'assets/img/lumina-mark-96.png'];

self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE_VERSION).then(c => c.addAll(PRECACHE)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE_VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});

const isStatic = url => url.origin === location.origin && /\/assets\/.+\.(css|js|png|jpg|jpeg|webp|svg|woff2?|ico)$/i.test(url.pathname);

self.addEventListener('fetch', e => {
  const req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;           // só pedidos GET ao próprio site
  if (url.pathname.includes('/api/') || url.pathname.includes('/storage/')) return;   // dados: nunca passam pela cache
  if (req.mode === 'navigate') {                                                 // páginas: sempre da rede; sem rede, a página offline
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
    return;
  }
  if (isStatic(url)) {                                                           // estáticos: da cache, e atualiza em segundo plano
    e.respondWith(caches.open(CACHE_VERSION).then(async cache => {
      const hit = await cache.match(req, { ignoreSearch: false });
      const net = fetch(req).then(r => { if (r.ok) cache.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
  }
});
