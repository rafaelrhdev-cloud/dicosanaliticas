/* analytics.js — medición anónima y ligera del sitio DICOSA.
   Sin cookies, sin servicios externos. Guarda un código al azar en el navegador (no identifica a la persona).
   Envía a api/track.php: visita, secciones vistas, clics de contacto, tiempo en la página. */
(function () {
  'use strict';
  if (window.__dicosaTracker) return;
  window.__dicosaTracker = true;
  if (/\/admin\//.test(location.pathname)) return;
  try { if (localStorage.getItem('dicosa_no_track') === '1') return; } catch (e) {}

  var ENDPOINT = (document.querySelector('base') ? '' : '') + 'api/track.php';
  var mem = {};
  function store(kind, k, v) {
    try {
      var s = kind === 'l' ? window.localStorage : window.sessionStorage;
      if (v === undefined) return s.getItem(k);
      s.setItem(k, v);
    } catch (e) { if (v === undefined) return mem[k] || null; mem[k] = v; }
    return null;
  }
  function rid() {
    var a = '';
    try { var u = new Uint8Array(9); crypto.getRandomValues(u); for (var i = 0; i < u.length; i++) a += (u[i] % 36).toString(36); }
    catch (e) { a = Math.random().toString(36).slice(2, 11); }
    return (Date.now().toString(36) + a).slice(0, 20);
  }

  // Visitante (se guarda en el navegador) y sesión (se renueva tras 30 min sin actividad).
  var vid = store('l', 'dc_v'), isNew = false;
  if (!vid) { vid = rid(); store('l', 'dc_v', vid); isNew = true; }
  var sess = null;
  try { sess = JSON.parse(store('s', 'dc_s') || 'null'); } catch (e) {}
  var firstOfSession = false;
  if (!sess || !sess.id || Date.now() - sess.t > 30 * 60 * 1000) { sess = { id: rid(), t: Date.now() }; firstOfSession = true; }
  function touch() { sess.t = Date.now(); store('s', 'dc_s', JSON.stringify(sess)); }
  touch();

  var queue = [], timer = null;
  function send(events, beacon) {
    if (!events.length) return;
    var body = JSON.stringify({ v: vid, s: sess.id, ev: events });
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }))) return;
    } catch (e) {}
    try { fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true, headers: { 'Content-Type': 'text/plain' } }); } catch (e) {}
  }
  function flush() { var q = queue; queue = []; clearTimeout(timer); timer = null; send(q); }
  function push(ev) { touch(); queue.push(ev); if (!timer) timer = setTimeout(flush, 1200); if (queue.length >= 8) flush(); }

  // Sucursal "actual" (la página de una sucursal la fija sola; en la principal la fija script.js).
  var branch = (document.documentElement.getAttribute('data-branch') || '');
  window.dicosaSetBranch = function (id) { branch = id || ''; };
  window.dicosaTrack = function (action, extra, branchId) {
    push({ e: 'ac', a: action, x: extra ? String(extra).slice(0, 60) : undefined, b: branchId !== undefined ? branchId : branch });
  };

  // 1) Visita
  var q = new URLSearchParams(location.search);
  var path = location.pathname.replace(/^.*?(\/sucursal\/[a-z0-9-]+)\/?$/, '$1');
  if (!/^\/sucursal\//.test(path)) path = '/';
  push({ e: 'pv', p: path, b: branch, n: isNew ? 1 : 0, f: firstOfSession ? 1 : 0, ref: document.referrer || '', utm: q.get('utm_source') || q.get('src') || '', w: window.innerWidth });

  // 2) Secciones vistas (una vez por sesión cada una)
  var seen = {};
  try { seen = JSON.parse(store('s', 'dc_sec') || '{}'); } catch (e) {}
  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var id = en.target.id;
        io.unobserve(en.target);
        if (seen[id]) return;
        seen[id] = 1; store('s', 'dc_sec', JSON.stringify(seen));
        push({ e: 'sc', x: id });
      });
    }, { threshold: 0.25 });
    ['inicio', 'nosotros', 'cortes', 'carnicerias', 'galeria', 'empresas', 'empleo', 'sucursales'].forEach(function (id) {
      var el = document.getElementById(id); if (el) io.observe(el);
    });
  }

  // 3) Clics de contacto y salidas (se detectan solos por el tipo de enlace)
  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a) return;
    var href = a.getAttribute('href') || '';
    var holder = a.closest('[data-bid]');
    var b = holder ? holder.getAttribute('data-bid') : branch;
    if (/^tel:/i.test(href)) return window.dicosaTrack('call', '', b);
    if (/^mailto:/i.test(href)) return window.dicosaTrack('mail', '', b);
    if (/wa\.me|api\.whatsapp\.com|whatsapp:/i.test(href)) return window.dicosaTrack('wa', '', b);
    if (/google\.[a-z.]+\/maps\/dir|maps\.apple\.com|waze\.com|goo\.gl\/maps|maps\.app\.goo\.gl/i.test(href)) return window.dicosaTrack('dir', '', b);
    try {
      var u = new URL(a.href, location.href);
      if (u.host && u.host !== location.host && /^https?:$/.test(u.protocol)) window.dicosaTrack('out', u.host.replace(/^www\./, ''), b);
    } catch (e) {}
  }, true);

  // 4) Tiempo en la página y qué tan abajo llegó (se manda al salir o cambiar de pestaña)
  var visibleMs = 0, since = document.visibilityState === 'visible' ? Date.now() : 0, maxDepth = 0;
  function depth() {
    var h = document.documentElement, total = h.scrollHeight - window.innerHeight;
    var d = total > 0 ? Math.round((window.pageYOffset || h.scrollTop) * 100 / total) : 100;
    if (d > maxDepth) maxDepth = Math.min(100, d);
  }
  window.addEventListener('scroll', depth, { passive: true });
  depth();
  function leave() {
    if (since) { visibleMs += Date.now() - since; since = 0; }
    var secs = Math.round(visibleMs / 1000);
    if (secs > 0) { queue.push({ e: 'lv', u: secs, k: maxDepth }); visibleMs = 0; }
    flush();
  }
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') leave();
    else since = Date.now();
  });
  window.addEventListener('pagehide', leave);
})();
