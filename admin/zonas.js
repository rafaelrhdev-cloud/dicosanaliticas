/* zonas.js (panel) — «Zonas»: de dónde son quienes visitan, por municipio, código postal y colonia.
   Los datos son voluntarios: la persona escribe su C.P. en la sección Sucursales. Sin IP, sin coordenadas. */
(function () {
  'use strict';
  const D = window.DICOSA || {};
  const root = document.getElementById('znRoot');
  if (!root || !(D.perms || []).includes('analytics.view')) return;

  const API = 'zones-api.php';
  const nf = new Intl.NumberFormat('es-MX');
  const fmt = n => nf.format(Math.round(+n || 0));
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const norm = s => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const pc = n => (n >= 10 ? Math.round(n) : (+n).toFixed(1).replace('.0', '')) + '%';
  const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const toMs = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
  const addDays = (s, n) => new Date(toMs(s) + n * 864e5).toISOString().slice(0, 10);
  const dShort = s => (+s.slice(8, 10)) + ' ' + MONTHS[+s.slice(5, 7) - 1] + ' ' + s.slice(0, 4);

  const state = { range: '90', tab: 'mun', q: '', st: '', limit: 8, sel: null, data: null, loaded: false };
  try { const s = JSON.parse(localStorage.getItem('dicosa_zn') || '{}'); if (s.range) state.range = s.range; } catch (e) {}

  const IC = {
    pin: '<svg viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>',
    search: '<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>',
    csv: '<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>',
    print: '<svg viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
    ext: '<svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14L21 3"/></svg>',
    lock: '<svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>'
  };

  root.innerHTML = `
    <header class="panel-head an-head">
      <div>
        <h1>Zonas de los visitantes</h1>
        <p>De qué municipio, código postal y colonia son quienes visitan tu página. Solo cuenta a las personas que quisieron compartir su zona.</p>
      </div>
    </header>
    <div class="an-toolbar" id="znToolbar">
      <div class="an-seg" role="group" aria-label="Periodo">
        <button type="button" data-r="30">30 días</button>
        <button type="button" data-r="90">90 días</button>
        <button type="button" data-r="180">6 meses</button>
        <button type="button" data-r="365">1 año</button>
      </div>
      <div class="an-tools">
        <button type="button" class="an-btn ghost" id="znCsv" title="Descargar los números en un archivo para Excel">${IC.csv.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}<span>Excel</span></button>
        <button type="button" class="an-btn ghost" id="znPrint" title="Imprimir o guardar como PDF">${IC.print.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}<span>PDF</span></button>
      </div>
    </div>
    <div class="an-printhead" id="znPrintHead"></div>
    <div id="znBody"></div>`;
  const $ = (s, el = root) => el.querySelector(s);
  const $$ = (s, el = root) => Array.from(el.querySelectorAll(s));
  const body = $('#znBody'), panelEl = document.getElementById('panel-zonas');

  function range() {
    const today = (state.data && state.data.today) || new Date().toISOString().slice(0, 10);
    return { from: addDays(today, -((+state.range || 90) - 1)), to: today };
  }

  let seq = 0;
  async function load(bg) {
    const my = ++seq;
    if (!state.loaded) body.innerHTML = '<div class="zn-skel"><i></i><i></i><i></i><i></i></div>';
    const { from, to } = range();
    try {
      const r = await fetch(`${API}?action=report&from=${from}&to=${to}&_=${Date.now()}`, bg ? { headers: { 'X-Background': '1' } } : undefined);
      const j = await r.json();
      if (my !== seq) return;
      if (!j.success) throw new Error(j.error || 'Error');
      state.data = j; state.loaded = true; render();
    } catch (e) {
      body.innerHTML = `<div class="an-empty"><h3>No se pudieron cargar las zonas</h3><p>${esc(e.message || 'Revisa tu conexión')}.</p><button class="an-btn" id="znRetry">Reintentar</button></div>`;
      const b = $('#znRetry'); if (b) b.onclick = () => load();
    }
  }

  // ------------------------------------------------------------ piezas
  function spark(a, w = 220, h = 44) {
    if (!a || a.length < 2 || !a.some(x => x > 0)) return '';
    // se agrupa por semanas si hay muchos días, para que la línea sea legible
    if (a.length > 70) { const g = Math.ceil(a.length / 52), o = []; for (let i = 0; i < a.length; i += g) o.push(a.slice(i, i + g).reduce((x, y) => x + y, 0)); a = o; }
    const max = Math.max(...a, 1), n = a.length;
    const pts = a.map((v, i) => [i * (w - 4) / (n - 1) + 2, h - 4 - (v / max) * (h - 10)]);
    const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join('');
    return `<svg class="zn-spark" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" aria-hidden="true"><path d="${line}L${w - 2} ${h}L2 ${h}Z" class="a"/><path d="${line}" class="l"/></svg>`;
  }
  const chip = (cur, prev) => {
    if (!prev) return '<span class="an-chip new">● Nuevo</span>';
    const p = (cur - prev) * 100 / prev;
    if (Math.abs(p) < 0.5) return '<span class="an-chip flat">▬ Igual</span>';
    return `<span class="an-chip ${p > 0 ? 'up' : 'down'}">${p > 0 ? '▲ +' : '▼ −'}${Math.abs(p) < 10 ? Math.abs(p).toFixed(1).replace('.0', '') : fmt(Math.abs(p))}%</span>`;
  };
  const help = t => `<span class="an-i" tabindex="0" role="img" aria-label="${esc(t)}" title="${esc(t)}">?</span>`;

  function items() {
    const R = state.data.report, q = norm(state.q);
    let l;
    if (state.tab === 'cp') l = R.cps.map(x => ({ id: x.cp, name: x.cp, sub: `${x.mun}, ${x.st}`, st: x.st, n: x.n, pct: x.pct, src: x }));
    else if (state.tab === 'col') l = R.cols.map(x => ({ id: x.cp + '|' + x.col, name: x.col, sub: `C.P. ${x.cp} · ${x.mun}, ${x.st}`, st: x.st, n: x.n, pct: x.pct, src: x }));
    else l = R.muns.map(x => ({ id: x.mun + '|' + x.st, name: x.mun, sub: x.estado + (x.ncp ? ` · ${x.ncp} C.P.` : ''), st: x.st, n: x.n, pct: x.pct, src: x }));
    if (state.st) l = l.filter(i => i.st === state.st);
    if (q) l = l.filter(i => norm(i.name + ' ' + i.sub).includes(q));
    return l;
  }

  // ------------------------------------------------------------ pantalla
  function render() {
    const j = state.data, R = j.report, T = R.totals;
    $$('.an-seg [data-r]').forEach(b => b.classList.toggle('on', b.dataset.r === state.range));
    $('#znPrintHead').innerHTML = `<h2>DICOSA — Zonas de los visitantes</h2><p>${dShort(R.from)} al ${dShort(R.to)}</p>`;
    let h = '';
    if (!j.config.enabled) h += `<div class="an-banner warn"><b>La invitación a compartir el C.P. está apagada.</b> Los visitantes no la ven y no se guardan zonas nuevas. ${j.can_manage ? 'Puedes encenderla abajo, en “Cómo se obtienen estos datos”.' : 'Pide a un administrador que la encienda.'}</div>`;
    if (j.has_demo) h += `<div class="an-banner demo"><span><b>Estás viendo datos de ejemplo</b> para que conozcas el panel. Los datos reales se mezclan con ellos.</span>${j.can_manage ? '<button type="button" class="an-btn" id="znDemoOff">Quitar datos de ejemplo</button>' : ''}</div>`;

    if (!T.people) {
      h += `<div class="an-empty big">${IC.pin.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"')}
        <h3>Aún nadie ha compartido su zona</h3>
        <p>Al entrar a tu página (y en la sección <b>Sucursales</b>) aparece una invitación opcional: la persona escribe su código postal para ver la sucursal más cercana. Cuando alguien lo haga, aquí verás de dónde viene.</p>
        ${j.can_manage ? '<button type="button" class="an-btn solid" id="znDemoOn">Ver el panel con datos de ejemplo</button>' : ''}</div>`;
      h += how(j);
      body.innerHTML = h; bind(); return;
    }

    const colPeople = R.cols.reduce((a, c) => a + c.n, 0);
    h += `<div class="an-kpis zn-kpis">
      <article class="an-kpi key"><header><span>Personas que compartieron su zona</span>${help('Cada persona cuenta una vez por código postal. Es una muestra voluntaria: sirve para ver tendencias, no para contar a todos.')}</header><strong>${fmt(T.people)}</strong><footer>${chip(T.people, T.prev_people)}<small>${T.coverage == null ? 'vs. periodo anterior' : pc(T.coverage) + ' de los visitantes'}</small></footer></article>
      <article class="an-kpi key"><header><span>Municipios</span></header><strong>${fmt(T.muns)}</strong><footer><small>${fmt(T.states)} ${T.states === 1 ? 'estado' : 'estados'}</small></footer></article>
      <article class="an-kpi key"><header><span>Códigos postales</span></header><strong>${fmt(T.cps)}</strong><footer><small>${T.p80 ? fmt(T.p80) + ' juntan el 80 %' : '&nbsp;'}</small></footer></article>
      <article class="an-kpi key"><header><span>Colonias</span>${help('Las que eligieron las personas al compartir su zona. Es opcional, por eso son menos que los códigos postales.')}</header><strong>${fmt(T.cols)}</strong><footer><small>${colPeople ? fmt(colPeople) + (colPeople === 1 ? ' persona la eligió' : ' personas las eligieron') : 'aún nadie la elige'}</small></footer></article>
    </div>`;
    h += story(R) + colonias(R);
    h += `<div class="zn-grid"><section class="an-card zn-explorer" id="znExplorer"></section><aside class="zn-side" id="znSide"></aside></div>`;
    h += funnel(R);
    h += `<div class="zn-grid2"><section class="an-card"><header class="an-card-h"><h3>Por estado</h3></header>${bars(R.states.slice(0, 8).map(s => ({ k: s.estado, n: s.n, p: s.pct })))}</section>${how(j)}</div>`;
    body.innerHTML = h;
    bind(); drawExplorer();
  }

  function story(R) {
    const T = R.totals, m = R.muns[0], L = [];
    if (m) L.push(`<b>${pc(m.pct)}</b> de quienes compartieron su zona viven en <b>${esc(m.mun)}</b>${m.ncp > 1 ? `, repartidos en ${m.ncp} códigos postales` : ''}.`);
    const col = R.cols[0];
    if (col) L.push(`La colonia que más nos visita es <b>${esc(col.col)}</b> (C.P. ${esc(col.cp)}, ${esc(col.mun)}): ${fmt(col.n)} ${col.n === 1 ? 'persona' : 'personas'}.`);
    const cp = R.cps[0];
    if (cp) L.push(`El código postal con más visitantes es <b>${esc(cp.cp)}</b> (${esc(cp.mun)}): ${fmt(cp.n)} ${cp.n === 1 ? 'persona' : 'personas'}.`);
    if (T.cps > 3 && T.p80) L.push(`<b>${T.p80}</b> ${T.p80 === 1 ? 'código postal junta' : 'códigos postales juntan'} el 80 % de las respuestas: ahí conviene concentrar promociones y reparto.`);
    const far = R.states.filter(s => s.st !== (m && m.st)).reduce((a, s) => a + s.n, 0);
    if (far > 0 && T.responses) L.push(`<b>${pc(far * 100 / T.responses)}</b> viene de otros estados: señal de clientes que podrían pedir a distancia.`);
    if (!L.length) return '';
    return `<section class="an-card zn-story"><header class="an-card-h"><h3>En resumen</h3><span class="an-pill">${dShort(R.from)} – ${dShort(R.to)}</span></header><ul>${L.map(x => `<li>${x}</li>`).join('')}</ul></section>`;
  }

  function funnel(R) {
    const f = R.funnel; if (!f || !f.shown) return '';
    const rows = [['Vieron la invitación', f.shown, 'Personas a quienes les apareció la tarjeta al entrar o en Sucursales.'], ['Compartieron su zona', f.shared, 'Escribieron su código postal y lo enviaron.'], ['La dejaron para después', f.later, 'Cerraron la tarjeta flotante; sigue disponible en Sucursales.'], ['No quisieron compartirla', f.skip, 'Pulsaron “Ahora no” y no la volvieron a ver.']];
    const rate = f.shown ? f.shared * 100 / f.shown : 0;
    return `<section class="an-card zn-funnel"><header class="an-card-h"><h3>¿Cuánta gente la usa?</h3>${help('Sirve para saber si la invitación funciona. Las personas que no hacen nada se cuentan como “vieron la invitación”.')}<span class="an-pill">${pc(rate)} comparte</span></header>
      ${bars(rows.map(r => ({ k: r[0], n: r[1], p: f.shown ? r[1] * 100 / f.shown : 0 })))}
      <p class="an-foot">De cada 100 personas que vieron la invitación, <b>${Math.round(rate)}</b> compartieron su zona. Si baja mucho, prueba con un texto más corto o mostrarla un poco más tarde.</p></section>`;
  }

  function colonias(R) {
    const top = R.cols.slice(0, 12), max = Math.max(...top.map(c => c.n), 1);
    if (!top.length) return `<section class="an-card zn-cols-card"><header class="an-card-h"><h3>Colonias más visitadas</h3></header><p class="an-foot">Todavía nadie ha elegido su colonia. Aparecen aquí cuando una persona escribe su código postal y escoge su colonia (es opcional).</p></section>`;
    return `<section class="an-card zn-cols-card"><header class="an-card-h"><h3>Colonias más visitadas</h3><button type="button" class="zn-linkbtn" data-goto="col">Ver las ${fmt(R.totals.cols)} →</button></header>
      <ul class="zn-colgrid">${top.map(c => `<li><div><b>${esc(c.col)}</b><small>${esc(c.cp)} · ${esc(c.mun)}</small></div><span>${fmt(c.n)}</span><i style="--w:${Math.max(6, c.n * 100 / max).toFixed(0)}%"></i></li>`).join('')}</ul></section>`;
  }

  function bars(a) {
    const max = Math.max(...a.map(x => x.n), 1);
    return `<ul class="zn-bars">${a.map(x => `<li><div class="zn-bl"><span>${esc(x.k)}</span><b>${fmt(x.n)}${x.p != null ? ` <em>${pc(x.p)}</em>` : ''}</b></div><div class="an-bar-t"><div class="an-bar-f" style="width:${Math.max(2, x.n * 100 / max).toFixed(1)}%"></div></div></li>`).join('')}</ul>`;
  }

  function how(j) {
    return `<section class="an-card zn-how"><header class="an-card-h"><h3>Cómo se obtienen estos datos</h3></header>
      <ol class="zn-steps">
        <li><b>La persona decide.</b> Al entrar (junto al aviso de ubicación), en una tarjeta flotante o en Sucursales, ve una invitación opcional: escribe su código postal y, si quiere, elige su colonia.</li>
        <li><b>Se guarda poco.</b> Solo el código postal (y la colonia elegida) con un código anónimo del navegador. Nunca su nombre, IP, ni ubicación exacta.</li>
        <li><b>Se ve en conjunto.</b> Aquí solo aparecen totales por zona; nadie puede ver quién es cada persona.</li>
      </ol>
      <p class="an-foot zn-lock">${IC.lock.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}<span>Es una <b>muestra voluntaria</b>: úsala para ver tendencias y comparar zonas, no como conteo total de visitantes.</span></p>
      ${j.can_manage ? `<div class="zn-cfg">
        <label class="zn-switch"><input type="checkbox" id="znEnabled" ${j.config.enabled ? 'checked' : ''}><span></span><em>Mostrar la invitación a los visitantes</em></label>
        <div class="zn-cfg-b">
          ${j.has_demo ? '' : '<button type="button" class="an-btn ghost" id="znDemoOn2">Cargar datos de ejemplo</button>'}
          <button type="button" class="an-btn ghost zn-danger" id="znWipe">Borrar todos los datos de zonas</button>
        </div></div>` : ''}
    </section>`;
  }

  function drawExplorer() {
    const R = state.data.report, el = $('#znExplorer');
    if (!el) return;
    const all = items();
    const tabs = [['mun', 'Municipios', R.totals.muns], ['cp', 'Códigos postales', R.totals.cps], ['col', 'Colonias', R.totals.cols]];
    const max = Math.max(...all.map(i => i.n), 1);
    const shown = all.slice(0, state.limit);
    if (!state.sel || !all.some(i => i.id === state.sel)) state.sel = all[0] ? all[0].id : null;
    el.innerHTML = `
      <header class="an-card-h"><h3>Explorar por zona</h3>${help('Empieza por municipios y entra a códigos postales o colonias cuando quieras más detalle.')}</header>
      <div class="zn-tabs" role="tablist">${tabs.map(t => `<button type="button" role="tab" aria-selected="${t[0] === state.tab}" class="${t[0] === state.tab ? 'on' : ''}" data-tab="${t[0]}">${t[1]}<i>${fmt(t[2])}</i></button>`).join('')}</div>
      <div class="zn-filters">
        <label class="zn-search">${IC.search.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"')}<input type="search" id="znQ" placeholder="${state.tab === 'cp' ? 'Buscar C.P., municipio…' : state.tab === 'col' ? 'Buscar colonia…' : 'Buscar municipio…'}" value="${esc(state.q)}" autocomplete="off"></label>
        <select id="znSt" aria-label="Filtrar por estado"><option value="">Todos los estados</option>${R.states.map(s => `<option value="${esc(s.st)}" ${s.st === state.st ? 'selected' : ''}>${esc(s.estado)}</option>`).join('')}</select>
      </div>
      ${all.length ? `<ol class="zn-list">${shown.map((i, k) => `
        <li><button type="button" class="zn-row${i.id === state.sel ? ' sel' : ''}" data-id="${esc(i.id)}">
          <span class="an-rank r${k < 3 ? k + 1 : 0}">${all.indexOf(i) + 1}</span>
          <span class="zn-nm"><b>${esc(i.name)}</b><small>${esc(i.sub)}</small></span>
          <span class="zn-bar"><span class="an-bar-t"><span class="an-bar-f" style="width:${Math.max(2, i.n * 100 / max).toFixed(1)}%"></span></span></span>
          <span class="zn-n"><b>${fmt(i.n)}</b><small>${pc(i.pct)}</small></span>
        </button></li>`).join('')}</ol>
        ${all.length > shown.length ? `<button type="button" class="an-btn ghost zn-more" id="znMore">Ver ${Math.min(8, all.length - shown.length)} más <span>(${fmt(all.length - shown.length)} restantes)</span></button>` : ''}`
        : `<div class="zn-none">${state.tab === 'col' && !R.cols.length ? 'Nadie ha elegido su colonia todavía. Es un dato opcional, por eso suele haber menos que de municipios.' : 'No hay resultados con ese filtro.'}</div>`}`;
    drawDetail();
  }

  function drawDetail() {
    const side = $('#znSide');
    if (!side) return;
    const i = items().find(x => x.id === state.sel);
    if (!i) { side.innerHTML = ''; return; }
    const s = i.src, R = state.data.report;
    let inner = '';
    const maps = q => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
    if (state.tab === 'mun') {
      inner = `<p class="zn-big"><b>${fmt(i.n)}</b> ${i.n === 1 ? 'persona' : 'personas'} <em>${pc(i.pct)} del total</em></p>${spark(s.ser)}
        ${s.cps && s.cps.length ? `<h4 class="an-h4">Códigos postales con más gente</h4>${bars(s.cps.map(c => ({ k: c.k, n: c.n })))}` : ''}
        ${s.cols && s.cols.length ? `<h4 class="an-h4">Colonias elegidas</h4>${bars(s.cols.map(c => ({ k: c.k, n: c.n })))}` : '<p class="an-foot">Nadie de este municipio ha elegido su colonia todavía.</p>'}
        <a class="an-btn ghost zn-ext" target="_blank" rel="noopener" href="${maps(i.name + ', ' + s.estado + ', México')}">Ver en Google Maps ${IC.ext.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}</a>`;
    } else if (state.tab === 'cp') {
      inner = `<p class="zn-big"><b>${fmt(i.n)}</b> ${i.n === 1 ? 'persona' : 'personas'} <em>${pc(i.pct)} del total</em></p>${spark(s.ser)}
        <h4 class="an-h4">Colonias que cubre este C.P.</h4>
        <p class="zn-cols">${(s.sample || []).map(c => `<span>${esc(c)}</span>`).join('')}${s.ncol > (s.sample || []).length ? `<span class="m">+${s.ncol - s.sample.length} más</span>` : ''}</p>
        ${s.cols && s.cols.length ? `<h4 class="an-h4">Colonias que eligieron</h4>${bars(s.cols.map(c => ({ k: c.k, n: c.n })))}` : '<p class="an-foot">Nadie ha elegido su colonia en este C.P. todavía.</p>'}
        <a class="an-btn ghost zn-ext" target="_blank" rel="noopener" href="${maps('CP ' + i.name + ' ' + s.mun + ', ' + s.estado + ', México')}">Ver en Google Maps ${IC.ext.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}</a>`;
    } else {
      inner = `<p class="zn-big"><b>${fmt(i.n)}</b> ${i.n === 1 ? 'persona' : 'personas'} <em>${pc(i.pct)} del total</em></p>
        <p class="an-foot">Colonia de ${esc(s.mun)}, ${esc(s.st)} (C.P. ${esc(s.cp)}). Solo cuentan quienes eligieron su colonia, por eso son menos que en municipios.</p>
        <a class="an-btn ghost zn-ext" target="_blank" rel="noopener" href="${maps('Colonia ' + i.name + ' CP ' + s.cp + ' ' + s.mun + ', México')}">Ver en Google Maps ${IC.ext.replace('<svg', '<svg fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"')}</a>`;
    }
    side.innerHTML = `<section class="an-card zn-detail"><span class="zn-kind">${state.tab === 'mun' ? 'Municipio' : state.tab === 'cp' ? 'Código postal' : 'Colonia'}</span><h3>${esc(i.name)}</h3><p class="zn-sub">${esc(i.sub)}</p>${inner}</section>`;
  }

  // ------------------------------------------------------------ eventos
  async function post(action, data) {
    const r = await fetch(`${API}?action=${action}`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data || {}) });
    return r.json();
  }
  function toast(m) { if (typeof showToast === 'function') showToast(m); }

  function bind() {
    const on = (id, fn) => { const e = $(id); if (e) e.onclick = fn; };
    const demoOn = async () => { const j = await post('demo_on'); if (j.success) { toast('Datos de ejemplo cargados'); load(); } };
    on('#znDemoOn', demoOn); on('#znDemoOn2', demoOn);
    on('#znDemoOff', async () => { const j = await post('demo_off'); if (j.success) { toast('Datos de ejemplo quitados'); load(); } });
    on('#znWipe', async () => {
      if (!confirm('¿Borrar TODOS los datos de zonas (incluye los reales)? Esta acción no se puede deshacer.')) return;
      const j = await post('wipe'); if (j.success) { toast('Datos de zonas borrados'); state.sel = null; load(); }
    });
    const en = $('#znEnabled');
    if (en) en.onchange = async () => { const j = await post('config', { enabled: en.checked }); if (j.success) { toast(en.checked ? 'Invitación encendida' : 'Invitación apagada'); load(); } else en.checked = !en.checked; };
    const go = $('[data-goto]');
    if (go) go.onclick = () => { state.tab = 'col'; state.limit = 8; state.sel = null; drawExplorer(); $('#znExplorer').scrollIntoView({ behavior: 'smooth', block: 'start' }); };
    const ex = $('#znExplorer');
    if (ex) {
      ex.onclick = e => {
        const t = e.target.closest('[data-tab]'), r = e.target.closest('.zn-row'), m = e.target.closest('#znMore');
        if (t) { state.tab = t.dataset.tab; state.limit = 8; state.sel = null; drawExplorer(); }
        else if (r) { state.sel = r.dataset.id; $$('.zn-row', ex).forEach(x => x.classList.toggle('sel', x === r)); drawDetail(); if (matchMedia('(max-width:980px)').matches) $('#znSide').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        else if (m) { state.limit += 8; drawExplorer(); }
      };
      ex.oninput = e => { if (e.target.id === 'znQ') { state.q = e.target.value; state.limit = 8; const p = e.target.selectionStart; drawExplorer(); const q = $('#znQ'); q.focus(); q.setSelectionRange(p, p); } };
      ex.onchange = e => { if (e.target.id === 'znSt') { state.st = e.target.value; state.limit = 8; drawExplorer(); } };
    }
  }

  $$('.an-seg [data-r]').forEach(b => b.onclick = () => { state.range = b.dataset.r; try { localStorage.setItem('dicosa_zn', JSON.stringify({ range: state.range })); } catch (e) {} load(); });
  $('#znCsv').onclick = () => { const { from, to } = range(); location.href = `${API}?action=csv&from=${from}&to=${to}`; };
  $('#znPrint').onclick = () => { document.body.classList.add('zn-printing'); window.print(); setTimeout(() => document.body.classList.remove('zn-printing'), 500); };

  let started = false;
  const start = () => { if (!started) { started = true; load(); setInterval(() => { if (!document.hidden && panelEl.classList.contains('active')) load(true); }, 300000); } };
  if (panelEl.classList.contains('active')) start();
  document.querySelectorAll('.side-link[data-panel="panel-zonas"]').forEach(l => l.addEventListener('click', start));
})();
