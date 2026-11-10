/* mensajes.js (panel) — mensajes que la gente deja antes de abrir WhatsApp, con su teléfono, para darles seguimiento. */
(function () {
  'use strict';
  const D = window.DICOSA || {};
  const root = document.getElementById('msRoot');
  if (!root || !(D.perms || []).includes('contact.view')) return;
  const API = 'leads-api.php';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const MON = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const fmtPhone = p => /^\d{10}$/.test(p) ? p.slice(0, 3) + ' ' + p.slice(3, 6) + ' ' + p.slice(6) : p;
  function when(s) {
    const d = new Date(s.replace(' ', 'T')); if (isNaN(d)) return s;
    const mins = Math.round((Date.now() - d) / 60000);
    if (mins < 1) return 'hace un momento';
    if (mins < 60) return 'hace ' + mins + ' min';
    if (mins < 1440) return 'hace ' + Math.round(mins / 60) + ' h';
    return d.getDate() + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear() + ', ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
  }
  const st = { f: 'todos', q: '', leads: [], canEdit: false, names: { nuevo: 'Nuevo', seguimiento: 'En seguimiento', cerrado: 'Atendido' } };

  root.innerHTML = `
    <header class="panel-head"><h1>Mensajes de WhatsApp</h1>
      <p>Quienes escriben desde la página dejan su mensaje y su teléfono antes de abrir WhatsApp. Aquí se guardan para que nadie se quede sin respuesta, y además llega un respaldo por correo.</p></header>
    <div class="ms-bar" id="msBar"></div>
    <div id="msBody"><div class="an-empty"><p>Cargando…</p></div></div>`;
  const bar = root.querySelector('#msBar'), body = root.querySelector('#msBody');

  async function load() {
    try {
      const j = await (await fetch(API + '?action=list&_=' + Date.now())).json();
      if (!j.success) throw new Error(j.error || 'Error');
      st.leads = j.leads; st.canEdit = j.can_edit; st.names = j.statuses || st.names;
      badge(); drawBar(); draw();
    } catch (e) { body.innerHTML = `<div class="an-empty"><h3>No se pudieron cargar los mensajes</h3><p>${esc(e.message)}</p><button class="an-btn" id="msRetry">Reintentar</button></div>`; const b = body.querySelector('#msRetry'); if (b) b.onclick = load; }
  }
  function badge() {
    const n = st.leads.filter(l => l.status === 'nuevo').length, b = document.getElementById('sideBadgeMensajes');
    if (b) { b.textContent = n; b.hidden = !n; }
  }
  const count = k => k === 'todos' ? st.leads.length : st.leads.filter(l => l.status === k).length;
  function drawBar() {
    const tabs = [['todos', 'Todos'], ['nuevo', 'Nuevos'], ['seguimiento', 'En seguimiento'], ['cerrado', 'Atendidos']];
    bar.innerHTML = `<div class="an-seg" role="group" aria-label="Estado">${tabs.map(t => `<button type="button" data-f="${t[0]}" class="${st.f === t[0] ? 'on' : ''}">${t[1]}<i>${count(t[0])}</i></button>`).join('')}</div>
      <input class="ms-search" id="msQ" type="search" placeholder="Buscar nombre, teléfono o mensaje…" value="${esc(st.q)}" autocomplete="off">
      <a class="an-btn ghost" href="${API}?action=csv" title="Descargar en Excel">Excel</a>`;
  }
  function draw() {
    const q = norm(st.q);
    const list = st.leads.filter(l => (st.f === 'todos' || l.status === st.f) && (!q || norm(l.name + ' ' + l.phone + ' ' + l.message + ' ' + (l.branch_name || '')).includes(q)));
    if (!st.leads.length) { body.innerHTML = `<div class="an-empty big"><h3>Aún no hay mensajes</h3><p>Cuando alguien toque «WhatsApp» en tu página, escribirá su mensaje y teléfono aquí. Verás cada uno al instante, con un botón para responderle.</p></div>`; return; }
    if (!list.length) { body.innerHTML = '<div class="an-empty"><p>No hay mensajes con ese filtro.</p></div>'; return; }
    const pending = st.leads.filter(l => !l.email_sent).length;
    body.innerHTML = (pending ? `<div class="ms-note"><b>${pending} ${pending === 1 ? 'mensaje no tiene' : 'mensajes no tienen'} respaldo por correo.</b> Revisa que el correo esté configurado (Ajustes › probar correo). Los mensajes están a salvo aquí.</div>` : '') +
      `<div class="ms-list">${list.map(card).join('')}</div>`;
  }
  function card(l) {
    const wa = 'https://wa.me/52' + l.phone;
    return `<article class="ms-card ${esc(l.status)}" data-id="${esc(l.id)}">
      <div class="ms-top"><h3>${esc(l.name || 'Sin nombre')}</h3><span class="ms-phone">${esc(fmtPhone(l.phone))}</span><span class="ms-when">${esc(when(l.created_at))}</span></div>
      <div class="ms-meta">${l.status === 'nuevo' ? '<span class="ms-tag new">● Nuevo</span>' : ''}${l.kind === 'cotizacion' ? '<span class="ms-tag q">Cotización</span>' : ''}<span class="ms-tag">${l.kind === 'cotizacion' ? 'Sucursal más cercana: ' + esc(l.branch_name || 'no indicó') : esc(l.branch_name || 'Sitio en general')}</span></div>
      <div class="ms-msg">${esc(l.message)}</div>
      <div class="ms-actions"><a class="wa" href="${wa}" target="_blank" rel="noopener">Responder por WhatsApp</a><a href="tel:${esc(l.phone)}">Llamar</a></div>
      ${st.canEdit ? `<div class="ms-edit"><select data-k="status" aria-label="Estado">${Object.entries(st.names).map(([k, v]) => `<option value="${k}" ${k === l.status ? 'selected' : ''}>${v}</option>`).join('')}</select>
        <textarea data-k="notes" rows="1" maxlength="1000" placeholder="Notas de seguimiento (quién le contestó, qué quedó pendiente…)">${esc(l.notes || '')}</textarea></div>` : (l.notes ? `<div class="ms-msg">${esc(l.notes)}</div>` : '')}
      <div class="ms-foot"><span>Respaldo por correo: ${l.email_sent ? '<b class="ok">✓ enviado</b>' : '<b class="pend">pendiente</b>'}</span><span class="ms-saved">✓ Guardado</span></div>
    </article>`;
  }
  async function save(el, data) {
    const c = el.closest('.ms-card'), l = st.leads.find(x => x.id === c.dataset.id);
    try {
      const j = await (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ action: 'update', id: c.dataset.id }, data)) })).json();
      if (!j.success) throw new Error(j.error);
      Object.assign(l, j.lead); badge(); drawBar();
      c.className = 'ms-card ' + l.status; const s = c.querySelector('.ms-saved'); s.classList.add('on'); setTimeout(() => s.classList.remove('on'), 1800);
      const nt = c.querySelector('.ms-tag.new'); if (nt && l.status !== 'nuevo') nt.remove();
      if (st.f !== 'todos' && data.status) setTimeout(draw, 700);
    } catch (e) { if (typeof showToast === 'function') showToast('No se pudo guardar: ' + e.message, true); }
  }
  bar.addEventListener('click', e => { const b = e.target.closest('[data-f]'); if (b) { st.f = b.dataset.f; drawBar(); draw(); } });
  bar.addEventListener('input', e => { if (e.target.id === 'msQ') { st.q = e.target.value; draw(); } });
  body.addEventListener('change', e => { if (e.target.dataset.k === 'status') save(e.target, { status: e.target.value }); });
  body.addEventListener('focusout', e => { if (e.target.dataset.k === 'notes') { const l = st.leads.find(x => x.id === e.target.closest('.ms-card').dataset.id); if (l && (l.notes || '') !== e.target.value.trim()) save(e.target, { notes: e.target.value }); } });

  let started = false;
  const start = () => { if (!started) { started = true; load(); setInterval(() => { if (!document.hidden && document.getElementById('panel-mensajes').classList.contains('active') && !body.contains(document.activeElement)) load(); }, 60000); } };
  load.once = start;
  fetch(API + '?action=list&_=' + Date.now()).then(r => r.json()).then(j => { if (j.success) { st.leads = j.leads; badge(); } }).catch(() => {});   // número de nuevos en el menú
  if (document.getElementById('panel-mensajes').classList.contains('active')) start();
  document.querySelectorAll('.side-link[data-panel="panel-mensajes"]').forEach(l => l.addEventListener('click', start));
})();
