/* =========================================================================
   INSTALAR O LUMINA NO TELEMÓVEL  (assets/js/pwa.js)
   -------------------------------------------------------------------------
   Regista o service worker (sw.js) para o Lumina poder ser instalado no ecrã inicial e abrir mais depressa.
   Só funciona em https ou em localhost (regra dos navegadores). Se falhar, o Lumina continua a funcionar normalmente.
   ========================================================================= */
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
  window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(() => { /* sem PWA: tudo o resto funciona */ }));
}
