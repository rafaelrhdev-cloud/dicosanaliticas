/* wa-lead.js — antes de abrir WhatsApp pedimos nombre (opcional), teléfono y mensaje.
   Así el mensaje y el teléfono quedan guardados (panel > Mensajes) y se manda un respaldo al correo.
   Siempre hay una salida: "Abrir WhatsApp sin dejar mis datos". */
(function () {
  'use strict';
  if (window.__dicosaWaLead || /\/admin\//.test(location.pathname)) return;
  window.__dicosaWaLead = true;
  var API = (document.querySelector('script[src$="wa-lead.js"]') || {}).src ? document.querySelector('script[src$="wa-lead.js"]').src.replace(/wa-lead\.js.*$/, '') + 'api/wa-lead.php' : 'api/wa-lead.php';

  var css = '.wl-ov{position:fixed;inset:0;z-index:1200;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(20,15,12,.55);backdrop-filter:blur(4px);opacity:0;transition:opacity .25s}' +
    '.wl-ov.show{opacity:1}.wl-ov[hidden]{display:none}' +
    '.wl-card{background:#FAF6F0;border-radius:12px;width:100%;max-width:440px;max-height:calc(100vh - 32px);overflow:auto;padding:26px 26px 20px;position:relative;box-shadow:0 30px 70px -20px rgba(0,0,0,.5);transform:translateY(14px);transition:transform .3s}' +
    '.wl-ov.show .wl-card{transform:none}' +
    '.wl-x{position:absolute;top:12px;right:14px;border:0;background:transparent;font-size:1.1rem;cursor:pointer;color:#1B1714;opacity:.6;padding:6px}.wl-x:hover{opacity:1}' +
    '.wl-ico{width:44px;height:44px;border-radius:50%;background:#1E8E5A;color:#fff;display:grid;place-items:center;margin-bottom:12px}.wl-ico svg{width:24px;height:24px}' +
    '.wl-card h3{font-family:var(--ff-display,Fraunces,serif);font-size:1.35rem;margin:0 0 6px;color:#1B1714}' +
    '.wl-card p.wl-sub{font-size:.88rem;line-height:1.5;color:rgba(27,23,20,.7);margin:0 0 18px}' +
    '.wl-f{display:block;margin-bottom:12px}.wl-f span{display:block;font-family:var(--ff-mono,monospace);font-size:.66rem;letter-spacing:.06em;text-transform:uppercase;color:rgba(27,23,20,.7);margin-bottom:5px}' +
    '.wl-f em{font-style:normal;opacity:.6;text-transform:none;letter-spacing:0}' +
    '.wl-f input,.wl-f textarea{width:100%;box-sizing:border-box;padding:12px 14px;border:1.5px solid #E4DACB;border-radius:4px;font:inherit;font-size:.95rem;background:#fff;color:#1B1714}' +
    '.wl-f textarea{resize:vertical;min-height:84px}.wl-f input:focus,.wl-f textarea:focus{outline:none;border-color:#A6182B}.wl-f.err input,.wl-f.err textarea{border-color:#B42318}' +
    '.wl-br{display:inline-block;margin:-6px 0 14px;padding:5px 12px;border-radius:999px;background:rgba(166,25,46,.08);color:#A6182B;font-size:.78rem;font-weight:600}' +
    '.wl-br[hidden]{display:none}' +
    '.wl-hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}' +
    '.wl-err{color:#B42318;font-size:.8rem;min-height:1.1em;margin:0 0 8px}' +
    '.wl-go{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:4px;background:#1E8E5A;color:#fff;font:inherit;font-weight:700;letter-spacing:.02em;padding:14px 18px;cursor:pointer}.wl-go:hover{background:#17713A}.wl-go:disabled{opacity:.6;cursor:wait}' +
    '.wl-direct{display:block;text-align:center;margin-top:12px;font-size:.8rem;color:rgba(27,23,20,.6);text-decoration:underline}.wl-direct:hover{color:#1B1714}' +
    '.wl-priv{font-size:.72rem;line-height:1.45;color:rgba(27,23,20,.55);margin:12px 0 0;text-align:center}' +
    '.wl-ok{text-align:center;padding:10px 0 4px}.wl-ok h3{margin-top:6px}.wl-ok .wl-go{margin-top:14px;text-decoration:none}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  var ov = document.createElement('div');
  ov.className = 'wl-ov'; ov.hidden = true;
  ov.innerHTML = '<div class="wl-card" role="dialog" aria-modal="true" aria-labelledby="wlT">' +
    '<button type="button" class="wl-x" aria-label="Cerrar">✕</button>' +
    '<div id="wlForm"><div class="wl-ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M20.5 3.5A11.8 11.8 0 0 0 12 0 11.9 11.9 0 0 0 1.7 17.8L0 24l6.4-1.7A11.9 11.9 0 0 0 12 24a11.9 11.9 0 0 0 8.5-20.5zM12 21.8a9.8 9.8 0 0 1-5-1.4l-.4-.2-3.8 1 1-3.7-.2-.4A9.8 9.8 0 1 1 12 21.8zm5.4-7.3c-.3-.1-1.7-.8-2-.9s-.5-.1-.7.1-.8.9-.9 1.1-.3.2-.6.1a8 8 0 0 1-4-3.5c-.3-.5.3-.5.9-1.6.1-.2 0-.4 0-.5l-.9-2.1c-.2-.6-.5-.5-.7-.5h-.6a1.2 1.2 0 0 0-.9.4 3.7 3.7 0 0 0-1.1 2.7 6.4 6.4 0 0 0 1.4 3.4 14.7 14.7 0 0 0 5.6 4.9c2 .8 2.8.9 3.8.7a3.2 3.2 0 0 0 2.1-1.5 2.6 2.6 0 0 0 .2-1.5c-.1-.1-.3-.2-.6-.3z"/></svg></div>' +
    '<h3 id="wlT">Escríbenos por WhatsApp</h3>' +
    '<p class="wl-sub" id="wlSub">Déjanos tu mensaje y tu teléfono: así te damos seguimiento y nadie se queda sin respuesta.</p>' +
    '<p class="wl-br" id="wlBr" hidden></p><form id="wlF" novalidate>' +
    '<label class="wl-f"><span>Tu nombre <em>(opcional)</em></span><input name="name" autocomplete="name" maxlength="80"></label>' +
    '<label class="wl-f" id="wlFp"><span>Tu teléfono (10 dígitos)</span><input name="phone" inputmode="tel" autocomplete="tel-national" maxlength="16" placeholder="427 123 4567"></label>' +
    '<label class="wl-f" id="wlFm"><span>Tu mensaje</span><textarea name="message" maxlength="1000" placeholder="Hola, quisiera cotizar…"></textarea></label>' +
    '<input class="wl-hp" name="web" tabindex="-1" autocomplete="off" aria-hidden="true">' +
    '<p class="wl-err" id="wlE" role="alert"></p>' +
    '<button type="submit" class="wl-go" id="wlGo">Continuar a WhatsApp</button></form>' +
    '<a class="wl-direct" id="wlDirect" href="#">Abrir WhatsApp sin dejar mis datos</a>' +
    '<p class="wl-priv">Guardamos tu nombre, teléfono y mensaje solo para atenderte. <a href="#aviso-privacidad" data-open-privacy style="color:#A6182B">Aviso de privacidad</a></p></div>' +
    '<div class="wl-ok" id="wlOk" hidden><h3>¡Listo!</h3><p>Abriendo WhatsApp… Si no se abre solo, toca el botón.</p><a class="wl-go" id="wlOpen" href="#" target="_blank" rel="noopener">Abrir WhatsApp</a></div></div>';
  document.body.appendChild(ov);
  var $ = function (id) { return document.getElementById(id); };
  var card = ov.firstChild, form = $('wlF'), errEl = $('wlE'), go = $('wlGo');
  var last = null, plainHref = '', quote = false, quoteBranchName = '';

  function open(href, trigger) {
    plainHref = href; last = trigger || document.activeElement;
    quote = !!(trigger && trigger.id === 'empresasWhatsappLink');
    var qs = document.getElementById('quoteBranch'), qid = quote && qs ? qs.value : '';
    quoteBranchName = qid && qs.selectedOptions[0] ? qs.selectedOptions[0].textContent : '';
    if (quote) window.dicosaBranch = qid;
    $('wlT').textContent = quote ? 'Solicita tu cotización' : 'Escríbenos por WhatsApp';
    $('wlSub').textContent = quote ? 'Cuéntanos qué necesitas y te contactamos el mismo día.' : 'Déjanos tu mensaje y tu teléfono: así te damos seguimiento y nadie se queda sin respuesta.';
    $('wlBr').hidden = !(quote && quoteBranchName); $('wlBr').textContent = quoteBranchName ? 'Sucursal que te queda más cerca: ' + quoteBranchName : '';
    form.message.placeholder = quote ? 'Ej. 30 kg de arrachera y 20 kg de costilla cada semana…' : 'Hola, quisiera cotizar…';
    $('wlForm').hidden = false; $('wlOk').hidden = true; errEl.textContent = ''; go.disabled = false;
    ov.hidden = false; requestAnimationFrame(function () { ov.classList.add('show'); });
    try { var s = JSON.parse(localStorage.getItem('dicosa_wa_me') || 'null'); if (s) { if (s.name && !form.name.value) form.name.value = s.name; if (s.phone && !form.phone.value) form.phone.value = s.phone; } } catch (e) {}
    setTimeout(function () { (form.phone.value ? form.message : form.phone).focus(); }, 60);
  }
  function close() { ov.classList.remove('show'); setTimeout(function () { ov.hidden = true; }, 250); if (last && last.focus) last.focus(); }
  function branch() { var h = document.documentElement.getAttribute('data-branch'); return h || window.dicosaBranch || ''; }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[href*="wa.me/"]') : null;
    if (!a || a.hasAttribute('data-wa-direct') || a.id === 'wlOpen' || a.id === 'wlDirect') return;
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
    e.preventDefault();
    var holder = a.closest('[data-bid]'); if (holder) window.dicosaBranch = holder.getAttribute('data-bid');
    open(a.href, a);
  });
  ov.querySelector('.wl-x').addEventListener('click', close);
  ov.addEventListener('click', function (e) { if (e.target === ov) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ov.hidden) close(); });
  $('wlDirect').addEventListener('click', function (e) { e.preventDefault(); window.open(plainHref || 'https://wa.me/', '_blank', 'noopener'); close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var phone = form.phone.value.replace(/\D/g, ''); if (phone.length >= 12 && phone.indexOf('52') === 0) phone = phone.slice(-10);
    var msg = form.message.value.trim();
    $('wlFp').classList.toggle('err', phone.length !== 10); $('wlFm').classList.toggle('err', msg.length < 2);
    if (phone.length !== 10) { errEl.textContent = 'Escribe tu teléfono a 10 dígitos.'; form.phone.focus(); return; }
    if (msg.length < 2) { errEl.textContent = 'Escribe tu mensaje.'; form.message.focus(); return; }
    errEl.textContent = ''; go.disabled = true; go.textContent = 'Un momento…';
    var win = null; try { win = window.open('', '_blank'); } catch (x) {}   // se abre ya, para que el navegador no la bloquee
    fetch(API, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify({ name: form.name.value, phone: phone, message: msg, web: form.web.value, branch: branch(), kind: quote ? 'cotizacion' : 'mensaje' }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var url = j.wa_url || j.direct;
        if (!j.ok && !url) throw new Error(j.error || 'Error');
        if (!j.ok) { errEl.textContent = j.error || ''; if (win) win.close(); go.disabled = false; go.textContent = 'Continuar a WhatsApp'; return; }
        try { localStorage.setItem('dicosa_wa_me', JSON.stringify({ name: form.name.value.trim(), phone: phone })); } catch (x) {}
        $('wlForm').hidden = true; $('wlOk').hidden = false; $('wlOpen').href = url;
        if (win) win.location.href = url; else window.location.href = url;
        form.message.value = ''; go.textContent = 'Continuar a WhatsApp';
        setTimeout(close, 4000);
      })
      .catch(function () {
        // Si algo falla, jamás se pierde al cliente: se abre WhatsApp con su mensaje.
        var url = plainHref + (plainHref.indexOf('?') < 0 ? '?text=' : '&text=') + encodeURIComponent(msg);
        if (win) win.location.href = url; else window.location.href = url;
        close();
      });
  });
})();
