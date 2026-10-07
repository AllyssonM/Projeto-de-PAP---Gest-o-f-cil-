/* Fundo em vídeo (todas as páginas): sem som, em loop, sem controlos, atrás do conteúdo, sem receber cliques.
   Se a reprodução automática não for permitida (ou o utilizador pede menos movimento), fica o poster como imagem de fundo. */
(function () {
  if (document.getElementById('fundo-video')) return;
  var wrap = document.createElement('div');
  wrap.id = 'fundo-video'; wrap.className = 'fundo-video'; wrap.setAttribute('aria-hidden', 'true');
  var small = window.matchMedia('(max-width: 900px), (max-aspect-ratio: 4/5)').matches;
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var saveData = navigator.connection && navigator.connection.saveData;
  var v = document.createElement('video');
  v.muted = true; v.defaultMuted = true; v.loop = true; v.autoplay = true; v.playsInline = true;
  v.setAttribute('muted', ''); v.setAttribute('playsinline', ''); v.setAttribute('webkit-playsinline', '');
  v.setAttribute('disablepictureinpicture', ''); v.setAttribute('disableremoteplayback', '');
  v.setAttribute('controlslist', 'nodownload nofullscreen noremoteplayback'); v.controls = false; v.tabIndex = -1;
  v.preload = 'auto';
  v.poster = 'assets/video/' + (small ? 'fundo-poster-m.webp' : 'fundo-poster.webp');
  v.addEventListener('contextmenu', function (e) { e.preventDefault(); });
  wrap.appendChild(v);
  document.body.insertBefore(wrap, document.body.firstChild);
  if (reduce || saveData) return;                                    // só o poster
  var key = 'lumina-fundo-t';
  v.addEventListener('loadedmetadata', function () {                // continua de onde ficou na página anterior
    try { var t = parseFloat(sessionStorage.getItem(key)); if (t > 0 && t < v.duration) v.currentTime = t; } catch (e) {}
  });
  function save() { try { sessionStorage.setItem(key, String(v.currentTime || 0)); } catch (e) {} }
  window.addEventListener('pagehide', save);
  v.addEventListener('playing', function () { wrap.classList.add('on'); });
  var mp4 = v.canPlayType('video/mp4; codecs="avc1.42E01E"');          // H.264 onde existir; senão WebM
  v.src = 'assets/video/' + (small ? 'fundo-m' : 'fundo') + (mp4 ? '.mp4' : '.webm');
  var p = v.play(); if (p && p.catch) p.catch(function () { /* sem autoplay: fica o poster */ });
  document.addEventListener('visibilitychange', function () { if (!document.hidden && v.paused) { var q = v.play(); if (q && q.catch) q.catch(function () {}); } });
})();
