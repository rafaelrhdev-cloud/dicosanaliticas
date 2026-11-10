/* analytics.js (panel) — tablero de Analítica de DICOSA.
   Todo se dibuja con SVG/HTML propios: sin librerías externas, funciona sin internet y se imprime bien. */
(function () {
  'use strict';
  const D = window.DICOSA || {};
  const root = document.getElementById('anRoot');
  if (!root || !(D.perms || []).includes('analytics.view')) return;

  const API = 'analytics-api.php';
  const nf = new Intl.NumberFormat('es-MX');
  const fmt = n => nf.format(Math.round(+n || 0));
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const DAYS = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
  const DAYS_S = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

  // ---- fechas (siempre como texto AAAA-MM-DD, sin husos horarios) ----
  const toMs = s => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
  const addDays = (s, n) => new Date(toMs(s) + n * 864e5).toISOString().slice(0, 10);
  const dShort = s => (+s.slice(8, 10)) + ' ' + MONTHS[+s.slice(5, 7) - 1];
  const dLong = s => DAYS[(new Date(toMs(s)).getUTCDay() + 6) % 7] + ' ' + dShort(s) + (s.slice(0, 4) !== String(new Date().getFullYear()) ? ' ' + s.slice(0, 4) : '');
  const dur = sec => sec >= 3600 ? Math.floor(sec / 3600) + ' h ' + Math.round((sec % 3600) / 60) + ' min' : sec >= 60 ? Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s' : Math.round(sec) + ' s';

  const state = { range: '30', from: '', to: '', branch: '', series: 'visitors', compare: true, table: false, data: null, branches: [], loaded: false };
  try { const s = JSON.parse(localStorage.getItem('dicosa_an') || '{}'); if (s.range) state.range = s.range; if (s.from) state.from = s.from; if (s.to) state.to = s.to; } catch (e) {}

  // ---------------------------------------------------------------- estructura fija
  root.innerHTML = `
    <header class="panel-head an-head">
      <div>
        <h1>Analítica del sitio</h1>
        <p>Cómo se está comportando tu página: quién la visita, desde dónde, qué sucursales interesan y cuántos contactan. Sin tecnicismos.</p>
      </div>
      <span class="an-live" id="anLivePill" title="Personas que estuvieron activas en la página en los últimos 5 minutos"><i></i><b id="anLive">–</b><span>en el sitio ahora</span></span>
    </header>

    <div class="an-toolbar" id="anToolbar">
      <div class="an-seg" role="group" aria-label="Periodo">
        <button type="button" data-r="today">Hoy</button>
        <button type="button" data-r="7">7 días</button>
        <button type="button" data-r="30">30 días</button>
        <button type="button" data-r="90">90 días</button>
        <button type="button" data-r="custom">Fechas…</button>
      </div>
      <div class="an-custom" id="anCustom" hidden>
        <label>Del <input type="date" id="anFrom"></label>
        <label>al <input type="date" id="anTo"></label>
        <button type="button" class="an-btn" id="anApply">Aplicar</button>
      </div>
      <label class="an-branch" id="anBranchWrap"><span>Ver</span>
        <select id="anBranch"><option value="">Todo el sitio</option></select>
      </label>
      <div class="an-tools">
        <button type="button" class="an-btn ghost" id="anCsv" title="Descargar los números en un archivo para Excel">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg><span>Excel</span></button>
        <button type="button" class="an-btn ghost" id="anPrint" title="Imprimir o guardar como PDF">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg><span>PDF</span></button>
      </div>
    </div>

    <div class="an-printhead" id="anPrintHead"></div>
    <div id="anBody"></div>
    <div class="an-pop" id="anPop" hidden></div>
    <div class="an-tip" id="anTip" hidden></div>
  `;
  const $ = (s, el = root) => el.querySelector(s);
  const $$ = (s, el = root) => Array.from(el.querySelectorAll(s));
  const panelEl = document.getElementById('panel-analitica');
  const body = $('#anBody'), tip = $('#anTip'), pop = $('#anPop');

  // ---------------------------------------------------------------- carga
  const hdrBg = { 'X-Background': '1' };
  async function api(q, opts) {
    const r = await fetch(API + '?' + q, opts);
    return r.json();
  }

  function computeRange() {
    const today = (state.data && state.data.today) || new Date().toISOString().slice(0, 10);
    let from, to = today;
    if (state.range === 'today') from = today;
    else if (state.range === 'custom' && state.from && state.to) { from = state.from; to = state.to; }
    else { const n = +state.range || 30; from = addDays(today, -(n - 1)); }
    return { from, to };
  }

  let loadSeq = 0;
  async function load(background) {
    const seq = ++loadSeq;
    if (!background && !state.loaded) body.innerHTML = skeleton();
    else if (!background) body.classList.add('is-loading');
    const { from, to } = computeRange();
    try {
      const j = await api(`action=report&from=${from}&to=${to}&branch=${encodeURIComponent(state.branch)}&_=${Date.now()}`, background ? { headers: hdrBg } : undefined);
      if (seq !== loadSeq) return;
      if (!j.success) throw new Error(j.error || 'Error');
      state.data = j; state.loaded = true;
      render();
    } catch (e) {
      body.innerHTML = `<div class="an-empty"><h3>No se pudieron cargar las estadísticas</h3><p>${esc(e.message || 'Revisa tu conexión')}.</p><button class="an-btn" id="anRetry">Reintentar</button></div>`;
      const b = $('#anRetry'); if (b) b.onclick = () => load();
    }
    body.classList.remove('is-loading');
  }

  async function pollLive() {
    if (document.hidden || !panelEl.classList.contains('active')) return;
    try { const j = await api('action=live&_=' + Date.now() + '&branch=' + encodeURIComponent(state.branch), { headers: hdrBg }); if (j.success) { $('#anLive').textContent = j.live; $('#anLivePill').classList.toggle('on', j.live > 0); } } catch (e) {}
  }

  function skeleton() {
    return `<div class="an-skel"><div class="an-skel-row">${'<div class="an-card sk"></div>'.repeat(6)}</div><div class="an-card sk tall"></div><div class="an-skel-row two"><div class="an-card sk tall"></div><div class="an-card sk tall"></div></div></div>`;
  }

  // ---------------------------------------------------------------- piezas reutilizables
  const help = text => `<button type="button" class="an-i" aria-label="¿Qué significa?" data-help="${esc(text)}">i</button>`;
  const card = (title, inner, opts = {}) => `<section class="an-card ${opts.cls || ''}"${opts.id ? ` id="${opts.id}"` : ''}>
      <header class="an-card-h"><h3>${title}</h3>${opts.help ? help(opts.help) : ''}${opts.right ? `<div class="an-card-r">${opts.right}</div>` : ''}</header>${opts.sub ? `<p class="an-sub">${opts.sub}</p>` : ''}${inner}</section>`;

  function pctChange(cur, prev) { if (!prev) return cur ? null : 0; return (cur - prev) * 100 / prev; }
  function chip(cur, prev, periodWord) {
    const p = pctChange(cur, prev);
    if (p === null) return `<span class="an-chip new" title="No hay datos del periodo anterior para comparar">● Nuevo</span>`;
    if (Math.abs(p) < 0.5) return `<span class="an-chip flat" title="Igual que ${periodWord}">▬ Igual</span>`;
    const up = p > 0;
    return `<span class="an-chip ${up ? 'up' : 'down'}" title="${up ? 'Subió' : 'Bajó'} frente a ${periodWord} (${fmt(prev)})">${up ? '▲' : '▼'} ${up ? '+' : '−'}${Math.abs(p) < 10 ? Math.abs(p).toFixed(1).replace('.0', '') : fmt(Math.abs(p))}%</span>`;
  }

  function spark(vals, w = 96, h = 30) {
    const max = Math.max(1, ...vals), n = vals.length;
    if (n < 2) return '';
    const pts = vals.map((v, i) => [i * (w - 4) / (n - 1) + 2, h - 3 - (v / max) * (h - 8)]);
    const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join('');
    return `<svg class="an-spark" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" aria-hidden="true"><path d="${line}L${pts[n - 1][0].toFixed(1)} ${h}L${pts[0][0].toFixed(1)} ${h}Z" class="a"/><path d="${line}" class="l"/></svg>`;
  }

  function barList(items, o = {}) {
    items = items.filter(i => i.n > 0);
    if (!items.length) return `<p class="an-none">${o.empty || 'Todavía no hay datos aquí.'}</p>`;
    const max = o.max || Math.max(...items.map(i => i.n));
    const total = o.total || items.reduce((a, b) => a + b.n, 0);
    return `<ul class="an-bars">${items.map(i => `<li title="${esc(i.label || i.k)}: ${fmt(i.n)}">
      <div class="an-bar-l"><span>${o.icon ? o.icon(i) : ''}${esc(i.label || i.k)}</span><b>${o.pct ? (i.n * 100 / total).toFixed(i.n * 100 / total < 10 ? 1 : 0).replace('.0', '') + '%' : fmt(i.n)}</b></div>
      <div class="an-bar-t"><div class="an-bar-f" style="width:${Math.max(1.5, i.n * 100 / max).toFixed(1)}%"></div></div></li>`).join('')}</ul>`;
  }

  // ---------------------------------------------------------------- render principal
  function render() {
    const j = state.data, R = j.report, T = R.totals, P = R.prev;
    const hasAny = T.pageviews > 0 || P.pageviews > 0;
    const periodWord = R.range.days === 1 ? 'el día anterior' : 'el periodo anterior';

    // barra de herramientas
    $$('.an-seg button').forEach(b => b.classList.toggle('on', b.dataset.r === state.range));
    $('#anCustom').hidden = state.range !== 'custom';
    $('#anFrom').value = R.range.from; $('#anTo').value = R.range.to; $('#anTo').max = j.today; $('#anFrom').max = j.today;
    $('#anBranchWrap').hidden = !!j.scoped || state.branches.length < 2;
    $('#anPrintHead').innerHTML = `<strong>DICOSA — Analítica del sitio</strong><span>${periodLabel(R)}${state.branch ? ' · ' + esc(branchName(state.branch)) : ''}</span>`;

    let html = '';
    if (j.scoped) html += `<div class="an-banner info"><b>Estás viendo solo las estadísticas de tu sucursal.</b> Las demás están reservadas para administración.</div>`;
    if (!j.collecting) html += `<div class="an-banner warn"><b>El registro de visitas está pausado.</b> No se están guardando visitas nuevas. ${j.can_manage ? 'Puedes activarlo abajo, en “Configuración y privacidad”.' : 'Pide a un administrador que lo active.'}</div>`;
    if (R.demo) html += `<div class="an-banner demo"><span><b>Estás viendo datos de ejemplo</b> para que conozcas el tablero. Las visitas reales se mezclan con ellos.</span>${j.can_manage ? '<button type="button" class="an-btn" id="anDemoOff">Quitar datos de ejemplo</button>' : ''}</div>`;

    if (!hasAny) {
      html += `<div class="an-empty big">
        <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 52V12M8 52h48"/><path d="M16 42l12-14 10 8 16-20"/><circle cx="28" cy="28" r="2.6" fill="currentColor"/><circle cx="38" cy="36" r="2.6" fill="currentColor"/><circle cx="54" cy="16" r="2.6" fill="currentColor"/></svg>
        <h3>${state.branch ? 'Esta sucursal aún no tiene visitas en este periodo' : 'Todavía no hay visitas registradas'}</h3>
        <p>${j.has_real ? 'Prueba con un periodo más amplio.' : 'En cuanto alguien abra la página pública, aquí aparecerán las visitas, los contactos y las sucursales más consultadas. Tu propia visita no se cuenta mientras tengas la sesión abierta en este navegador.'}</p>
        ${j.can_manage && !j.has_demo ? '<button type="button" class="an-btn solid" id="anDemoOn">Ver el tablero con datos de ejemplo</button>' : ''}
      </div>`;
      body.innerHTML = html + settingsCard(j);
      wire(); return;
    }

    html += storyCard(R);
    html += `<div class="an-kpis">${kpis(R, periodWord)}</div>`;
    html += card('Visitas a lo largo del tiempo', `<div class="an-trend" id="anTrend"></div>`, {
      id: 'anTrendCard', cls: 'wide',
      help: 'Cada punto es un día. Pasa el dedo o el cursor sobre la gráfica para ver el número exacto. La línea punteada es el periodo anterior, para comparar.',
      right: `<div class="an-seg small" id="anSeries"><button data-s="visitors">Visitantes</button><button data-s="sessions">Visitas</button><button data-s="actions">Acciones</button></div>
              <label class="an-check"><input type="checkbox" id="anCmp" ${state.compare ? 'checked' : ''}> Comparar</label>
              <button type="button" class="an-link" id="anTblBtn">${state.table ? 'Ver gráfica' : 'Ver tabla'}</button>`
    });
    html += `<div class="an-grid two">`
      + card('Qué hace la gente', barList(R.actions, { icon: actionIcon }) + `<p class="an-foot">Las acciones de contacto (llamar, WhatsApp, cómo llegar y correo) son las más valiosas.</p>`, { help: 'Cuántas veces tocaron cada botón de la página. Una misma persona puede hacerlo varias veces.' })
      + card('Del interés al contacto', funnel(T), { help: 'Muestra cuántos visitantes van avanzando: primero entran, luego revisan una sucursal y por último contactan (llaman, escriben o piden indicaciones).' })
      + `</div>`;
    html += card('Sucursales: cuáles interesan más', branchTable(R), {
      cls: 'wide', help: 'Interés = veces que vieron la página de la sucursal o la eligieron en el selector. Contactos = llamadas, mensajes de WhatsApp y “Cómo llegar”.',
      sub: R.branches.length ? 'Ordenadas de mayor a menor interés en el periodo.' : ''
    });
    html += `<div class="an-grid two">`
      + card('¿Desde dónde nos visitan?', geoCard(R), { cls: 'geo', help: 'Ubicación aproximada según la conexión a internet del visitante (ciudad o estado). No se guarda su dirección ni se sabe quién es.' })
      + card('Cuándo nos visitan', heatCard(R), { cls: 'heat', help: 'Cuanto más oscuro el cuadro, más visitas a esa hora. Sirve para decidir cuándo publicar en redes o reforzar la atención.' })
      + `</div>`;
    html += `<div class="an-grid two">`
      + card('Qué partes de la página se ven', sectionsCard(R), { help: 'Porcentaje de visitas que llegó a ver cada sección. Si una sección importante casi no se ve, conviene subirla o resaltarla.' })
      + card('¿Cómo llegan a la página?', barList(R.sources.map(s => ({ k: s.k, n: s.n })), { pct: true }) + `<p class="an-foot">“Directo” es quien escribe la dirección, la guardó o llega desde un mensaje sin enlace rastreable.</p>`, { help: 'De qué sitio o aplicación venía la persona al entrar. Para medir una campaña, usa enlaces con ?utm_source=nombre.' })
      + `</div>`;
    html += `<div class="an-grid two">`
      + card('Quiénes nos visitan', profileCard(R), { help: 'Nuevos: es la primera vez que ese navegador entra. Recurrentes: ya habían entrado antes. También el tipo de aparato que usan.' })
      + card('Qué les interesa', interestsCard(R), { help: 'Lo que más buscan en el buscador de sucursales, los cortes que tocan en el mapa y las vacantes que abren.' })
      + `</div>`;
    html += settingsCard(j);
    body.innerHTML = html;
    drawTrend();
    wire();
    loadZonesMini(R);
  }

  const periodLabel = R => R.range.from === R.range.to ? dLong(R.range.from) : `${dShort(R.range.from)} – ${dShort(R.range.to)}${R.range.to.slice(0, 4) !== R.range.from.slice(0, 4) ? ' ' + R.range.to.slice(0, 4) : ''} (${R.range.days} días)`;
  const branchName = id => { const b = state.branches.find(x => x.id === id); return b ? b.name : id; };

  // ---------------------------------------------------------------- resumen en palabras
  function storyCard(R) {
    const T = R.totals, P = R.prev, L = [];
    const word = R.range.days === 1 ? 'ese día' : 'este periodo';
    const pc = pctChange(T.visitors, P.visitors);
    L.push(['users', `<b>${fmt(T.visitors)} ${T.visitors === 1 ? 'persona visitó' : 'personas visitaron'}</b> la página ${word}` + (pc === null || !P.visitors ? '.' : `, ${Math.abs(pc) < 0.5 ? 'casi lo mismo que' : `un <b>${fmt(Math.abs(pc))}% ${pc > 0 ? 'más' : 'menos'}</b> que`} en ${R.range.days === 1 ? 'el día anterior' : 'el periodo anterior'}.`)]);
    if (T.contact_visitors) {
      const top = R.actions.filter(a => ['call', 'wa', 'dir', 'mail'].includes(a.k)).sort((a, b) => b.n - a.n)[0];
      L.push(['phone', `<b>${fmt(T.contact_visitors)} ${T.contact_visitors === 1 ? 'persona dio' : 'personas dieron'} el paso de contactarte</b> (${T.contact_rate}% de los visitantes).` + (top && top.n ? ` Lo más usado: <b>${esc(top.label.toLowerCase())}</b>.` : '')]);
    }
    const tb = R.branches[0];
    if (tb && R.branches.length > 0 && (tb.interest + tb.contacts) > 0) L.push(['pin', `La sucursal que más interesa es <b>${esc(tb.name)}</b>` + (R.branches[1] ? `, seguida de ${esc(R.branches[1].name)}` : '') + '.']);
    const hm = heatInsights(R.heat);
    if (hm) L.push(['clock', `Tu día más fuerte es el <b>${hm.day}</b> y la gente entra más ${hm.hours}.`]);
    const dev = R.devices.device.find(d => d.k === 'mobile'), devT = R.devices.device.reduce((a, b) => a + b.n, 0);
    if (dev && devT) { const p = Math.round(dev.n * 100 / devT); if (p >= 55) L.push(['mobile', `<b>${p} de cada 100</b> visitantes entran desde el celular: lo que se ve en teléfono es lo que más importa.`]); }
    const s0 = R.sources[0], sT = R.sources.reduce((a, b) => a + b.n, 0);
    if (s0 && sT) L.push(['link', `La mayoría llega por <b>${esc(s0.k)}</b> (${Math.round(s0.n * 100 / sT)}%).`]);
    const loc = R.geo.city.find(c => /san juan del r/i.test(c.k)), cT = R.geo.city.reduce((a, b) => a + b.n, 0);
    if (loc && cT >= 10) L.push(['home', `<b>${Math.round(loc.n * 100 / cT)}%</b> de quienes tienen ubicación están en San Juan del Río.`]);
    if (T.applies) L.push(['briefcase', `<b>${fmt(T.applies)} ${T.applies === 1 ? 'solicitud' : 'solicitudes'} de empleo</b> llegaron desde la página.`]);
    return `<section class="an-card story"><header class="an-card-h"><h3>En resumen</h3><span class="an-pill">${periodLabel(R)}</span></header><ul>${L.slice(0, 6).map(l => `<li><i>${ICON[l[0]] || ''}</i><span>${l[1]}</span></li>`).join('')}</ul></section>`;
  }

  function heatInsights(h) {
    const byDay = h.map(r => r.reduce((a, b) => a + b, 0)), tot = byDay.reduce((a, b) => a + b, 0);
    if (tot < 20) return null;
    const di = byDay.indexOf(Math.max(...byDay));
    let best = 0, bs = -1;
    for (let s = 0; s < 24; s++) { const v = h.reduce((a, r) => a + r[s] + r[(s + 1) % 24] + r[(s + 2) % 24], 0); if (v > bs) { bs = v; best = s; } }
    const hr = x => { x = x % 24; return (x % 12 || 12) + (x < 12 ? ' am' : ' pm'); };
    return { day: DAYS[di], hours: `entre las ${hr(best)} y las ${hr(best + 3)}` };
  }

  // ---------------------------------------------------------------- KPIs
  function kpis(R, pw) {
    const T = R.totals, P = R.prev, D_ = R.daily;
    const k = [
      ['Visitantes', fmt(T.visitors), chip(T.visitors, P.visitors, pw), D_.map(d => d.visitors), 'Personas distintas que entraron a la página. Si la misma persona entra 3 veces, cuenta una sola vez.'],
      ['Visitas', fmt(T.sessions), chip(T.sessions, P.sessions, pw), D_.map(d => d.sessions), 'Cada vez que alguien entra y navega. Una persona puede hacer varias visitas.'],
      ['Contactos', fmt(T.contacts), chip(T.contacts, P.contacts, pw), D_.map(d => d.actions), 'Veces que tocaron Llamar, WhatsApp, Correo o Cómo llegar. Son personas con intención real de ir o comprar.'],
      ['Tasa de contacto', T.contact_rate + '%', chip(T.contact_rate, P.contact_rate, pw), null, 'De cada 100 visitantes, cuántos dieron el paso de contactarte. Más alto es mejor.'],
      ['Tiempo en la página', T.avg_time ? dur(T.avg_time) : '–', chip(T.avg_time, P.avg_time, pw), null, 'Cuánto tiempo pasa una persona en la página en cada visita.'],
      ['Solicitudes de empleo', fmt(T.applies), chip(T.applies, P.applies, pw), null, 'Personas que enviaron su solicitud y CV desde la bolsa de trabajo.'],
    ];
    return k.map((x, i) => `<article class="an-kpi${i < 3 ? ' key' : ''}"><header><span>${x[0]}</span>${help(x[4])}</header><strong>${x[1]}</strong><footer>${x[2]}${x[3] ? spark(x[3]) : ''}</footer></article>`).join('');
  }

  // ---------------------------------------------------------------- embudo
  function funnel(T) {
    const st = [['Entraron a la página', T.visitors], ['Revisaron una sucursal', T.branch_visitors], ['Contactaron', T.contact_visitors]];
    const base = Math.max(1, st[0][1]);
    return `<ol class="an-funnel">${st.map((s, i) => {
      const w = Math.max(3, s[1] * 100 / base), pct = i ? (st[i - 1][1] ? Math.round(s[1] * 100 / st[i - 1][1]) : 0) : 100;
      return `<li><div class="an-fn-h"><span>${s[0]}</span><b>${fmt(s[1])}</b></div><div class="an-fn-t"><div class="an-fn-f s${i}" style="width:${w.toFixed(1)}%"></div></div><small>${i ? `${pct}% de los que ${i === 1 ? 'entraron' : 'revisaron una sucursal'}` : 'Todos los visitantes'}</small></li>`;
    }).join('')}</ol>`;
  }

  // ---------------------------------------------------------------- sucursales
  function branchTable(R) {
    if (!R.branches.length) return '<p class="an-none">Aún no hay consultas de sucursales en este periodo.</p>';
    const max = Math.max(1, ...R.branches.map(b => b.interest));
    return `<div class="an-table-wrap"><table class="an-table"><thead><tr><th>#</th><th>Sucursal</th><th>Interés</th><th class="r">Página vista</th><th class="r">La eligieron</th><th class="r">Llamar</th><th class="r">WhatsApp</th><th class="r">Cómo llegar</th></tr></thead><tbody>${R.branches.map((b, i) => `<tr>
      <td data-label="Lugar"><span class="an-rank r${i < 3 ? i + 1 : 0}">${i + 1}</span></td>
      <td data-label="Sucursal" class="nm">${esc(b.name)}</td>
      <td data-label="Interés" class="bar"><div class="an-bar-t"><div class="an-bar-f" style="width:${Math.max(2, b.interest * 100 / max).toFixed(1)}%"></div></div><b>${fmt(b.interest)}</b></td>
      <td data-label="Página vista" class="r">${fmt(b.pv)}</td><td data-label="La eligieron" class="r">${fmt(b.bsel + b.bnear)}</td>
      <td data-label="Llamar" class="r">${fmt(b.call)}</td><td data-label="WhatsApp" class="r">${fmt(b.wa)}</td><td data-label="Cómo llegar" class="r">${fmt(b.dir)}</td></tr>`).join('')}</tbody></table></div>`;
  }

  // ---------------------------------------------------------------- mapa de estados (cuadrícula)
  const TILES = {
    BC: ['Baja California', 'BC', 0, 0], SON: ['Sonora', 'SON', 1, 0], CHIH: ['Chihuahua', 'CHH', 2, 0], COAH: ['Coahuila', 'COA', 3, 0], NL: ['Nuevo León', 'NL', 4, 0], TAMPS: ['Tamaulipas', 'TAM', 5, 0],
    BCS: ['Baja California Sur', 'BCS', 0, 1], SIN: ['Sinaloa', 'SIN', 1, 1], DGO: ['Durango', 'DGO', 2, 1], ZAC: ['Zacatecas', 'ZAC', 3, 1], SLP: ['San Luis Potosí', 'SLP', 4, 1],
    NAY: ['Nayarit', 'NAY', 1, 2], JAL: ['Jalisco', 'JAL', 2, 2], AGS: ['Aguascalientes', 'AGS', 3, 2], GTO: ['Guanajuato', 'GTO', 4, 2], QRO: ['Querétaro', 'QRO', 5, 2], HGO: ['Hidalgo', 'HGO', 6, 2], VER: ['Veracruz', 'VER', 7, 2],
    COL: ['Colima', 'COL', 1, 3], MICH: ['Michoacán', 'MIC', 2, 3], MEX: ['Estado de México', 'MEX', 4, 3], CDMX: ['Ciudad de México', 'CDMX', 5, 3], TLAX: ['Tlaxcala', 'TLA', 6, 3], PUE: ['Puebla', 'PUE', 7, 3],
    GRO: ['Guerrero', 'GRO', 3, 4], MOR: ['Morelos', 'MOR', 5, 4], OAX: ['Oaxaca', 'OAX', 7, 4], TAB: ['Tabasco', 'TAB', 8, 3], CHIS: ['Chiapas', 'CHP', 8, 4], CAMP: ['Campeche', 'CAM', 9, 3], YUC: ['Yucatán', 'YUC', 10, 2], QROO: ['Quintana Roo', 'ROO', 10, 3],
  };
  const RAMP = ['#F7DADD', '#EDB2B9', '#DE7F8C', '#C84A5C', '#A6182B', '#7A0F20'];   // un solo tono (rojo DICOSA), de claro a oscuro
  const rampIdx = (v, max) => { if (v <= 0) return -1; const r = v / max; return r > .8 ? 5 : r > .55 ? 4 : r > .3 ? 3 : r > .12 ? 2 : r > .04 ? 1 : 0; };
  const inkOn = i => i >= 3 ? '#fff' : '#3A1218';

  function geoCard(R) {
    const st = {}; R.geo.state.forEach(s => st[s.k] = s.n);
    const max = Math.max(1, ...Object.values(st));
    const known = R.geo.state.reduce((a, b) => a + (b.k === '—' ? 0 : b.n), 0);
    const tiles = Object.entries(TILES).map(([code, t]) => {
      const v = st[code] || 0, i = rampIdx(v, max);
      return `<button type="button" class="an-tile${v ? ' has' : ''}" style="grid-column:${t[2] + 1};grid-row:${t[3] + 1};${i >= 0 ? `background:${RAMP[i]};color:${inkOn(i)}` : ''}" data-tip="<b>${t[0]}</b><br>${v ? fmt(v) + (v === 1 ? ' visitante' : ' visitantes') : 'Sin visitas'}" aria-label="${t[0]}: ${v} visitantes">${t[1]}</button>`;
    }).join('');
    const nonMx = R.geo.country.filter(c => c.k && c.k !== 'MX' && c.k !== '—').reduce((a, b) => a + b.n, 0);
    const legend = `<div class="an-legend"><span>Menos</span>${RAMP.map(c => `<i style="background:${c}"></i>`).join('')}<span>Más visitantes</span></div>`;
    const cities = R.geo.city.filter(c => c.k !== '—');
    return `<div class="an-map" role="img" aria-label="Mapa de estados de México coloreado según visitantes">${tiles}</div>${legend}
      <h4 class="an-h4">Ciudades principales</h4>${known + nonMx === 0 && !cities.length ? '<p class="an-none">Aún no hay datos de ubicación. Se calculan solos con las visitas nuevas.</p>' : barList(cities.slice(0, 7), { empty: 'Sin datos de ciudad.' })}
      ${nonMx ? `<p class="an-foot">Además, <b>${fmt(nonMx)}</b> ${nonMx === 1 ? 'visitante' : 'visitantes'} desde fuera de México.</p>` : ''}
      <div class="an-zmini" id="anZMini"></div>`;
  }

  // ---------------------------------------------------------------- colonias y C.P. (resumen; el detalle está en el menú Zonas)
  let zmini = { tab: 'col', data: null };
  async function loadZonesMini(R) {
    const el = $('#anZMini');
    if (!el || state.branch || !document.getElementById('panel-zonas')) { if (el) el.remove(); return; }
    try {
      const j = await (await fetch(`zones-api.php?action=report&from=${R.range.from}&to=${R.range.to}&_=${Date.now()}`)).json();
      if (!j.success || !$('#anZMini')) return;
      zmini.data = j.report; if (zmini.tab === 'col' && !j.report.cols.length) zmini.tab = 'cp';
      drawZonesMini();
    } catch (e) { el.remove(); }
  }
  function drawZonesMini() {
    const el = $('#anZMini'), Z = zmini.data;
    if (!el || !Z) return;
    const T = Z.totals;
    const lists = {
      col: Z.cols.slice(0, 5).map(c => ({ k: c.col + ' · ' + c.cp, n: c.n })),
      cp: Z.cps.slice(0, 5).map(c => ({ k: c.cp + ' · ' + c.mun, n: c.n })),
      mun: Z.muns.slice(0, 5).map(c => ({ k: c.mun + ', ' + c.st, n: c.n })),
    };
    el.innerHTML = `<div class="an-zmini-h"><h4 class="an-h4">Colonias y códigos postales</h4>
        <div class="an-seg small" id="anZSeg"><button data-z="col" class="${zmini.tab === 'col' ? 'on' : ''}">Colonias</button><button data-z="cp" class="${zmini.tab === 'cp' ? 'on' : ''}">C.P.</button><button data-z="mun" class="${zmini.tab === 'mun' ? 'on' : ''}">Municipios</button></div></div>
      ${T.people ? barList(lists[zmini.tab], { empty: zmini.tab === 'col' ? 'Aún nadie elige su colonia. Es opcional al compartir el código postal.' : 'Sin datos todavía.' }) : '<p class="an-none">Aún nadie comparte su zona. Aparecerá aquí cuando alguien escriba su código postal.</p>'}
      <p class="an-foot">${T.people ? `Según <b>${fmt(T.people)}</b> ${T.people === 1 ? 'persona' : 'personas'} que compartieron su zona (voluntario). ` : ''}<button type="button" class="an-link" id="anZGo">Ver todo en Zonas →</button></p>`;
    $('#anZSeg').onclick = e => { const b = e.target.closest('[data-z]'); if (b) { zmini.tab = b.dataset.z; drawZonesMini(); } };
    $('#anZGo').onclick = () => { const l = document.querySelector('.side-link[data-panel="panel-zonas"]'); if (l) l.click(); };
  }

  // ---------------------------------------------------------------- mapa de calor (día x hora)
  function heatCard(R) {
    const h = R.heat, max = Math.max(1, ...h.flat());
    let cells = '';
    h.forEach((row, d) => row.forEach((v, hr) => {
      const i = rampIdx(v, max);
      cells += `<i class="an-hc" style="${i >= 0 ? `background:${RAMP[i]}` : ''}" data-tip="<b>${DAYS[d][0].toUpperCase() + DAYS[d].slice(1)}, ${hrLabel(hr)}</b><br>${fmt(v)} ${v === 1 ? 'visita' : 'visitas'}" aria-label="${DAYS[d]} ${hr} horas: ${v}"></i>`;
    }));
    const hours = [0, 3, 6, 9, 12, 15, 18, 21].map(x => `<span style="grid-column:${x + 2}/span 3">${hrLabel(x, true)}</span>`).join('');
    const ins = heatInsights(h);
    return `<div class="an-heat"><div class="an-heat-g">${DAYS_S.map((d, i) => `<span class="dl" style="grid-row:${i + 1}">${d}</span>`).join('')}${cells}</div><div class="an-heat-x"><span></span>${hours}</div></div>
      <div class="an-legend"><span>Pocas visitas</span>${RAMP.map(c => `<i style="background:${c}"></i>`).join('')}<span>Muchas</span></div>
      ${ins ? `<p class="an-foot">Mejor momento: <b>${ins.day}</b>, ${ins.hours}. Horario de la Ciudad de México.</p>` : '<p class="an-foot">Con más visitas se mostrará el mejor momento del día.</p>'}`;
  }
  const hrLabel = (h, short) => short ? (h % 12 || 12) + (h < 12 ? 'a' : 'p') : (h % 12 || 12) + (h < 12 ? ' am' : ' pm');

  // ---------------------------------------------------------------- secciones, perfil, intereses
  function sectionsCard(R) {
    const items = R.sections.map(s => ({ k: s.label, n: s.pct }));
    const max = 100;
    return `<ul class="an-bars">${items.map(i => `<li title="${esc(i.k)}"><div class="an-bar-l"><span>${esc(i.k)}</span><b>${i.n.toFixed(0)}%</b></div><div class="an-bar-t"><div class="an-bar-f" style="width:${Math.max(1.5, i.n).toFixed(1)}%"></div></div></li>`).join('')}</ul><p class="an-foot">Porcentaje de visitas que llegó a ver cada parte. Promedio de avance en la página: <b>${R.totals.depth}%</b>.</p>`;
  }

  const DEV = { mobile: 'Celular', desktop: 'Computadora', tablet: 'Tableta' };
  function profileCard(R) {
    const T = R.totals, tot = Math.max(1, T.new + T.returning);
    const dev = R.devices.device.filter(d => d.k !== '—').map(d => ({ k: DEV[d.k] || d.k, n: d.n }));
    return `<h4 class="an-h4 first">Nuevos y recurrentes</h4>
      <div class="an-split" role="img" aria-label="${T.new} nuevos y ${T.returning} recurrentes"><div class="a" style="width:${(T.new * 100 / tot).toFixed(1)}%"></div><div class="b" style="width:${(T.returning * 100 / tot).toFixed(1)}%"></div></div>
      <div class="an-split-l"><span><i class="a"></i>Nuevos <b>${fmt(T.new)}</b></span><span><i class="b"></i>Regresan <b>${fmt(T.returning)}</b></span></div>
      <h4 class="an-h4">Aparato</h4>${barList(dev, { pct: true })}
      <div class="an-two-col"><div><h4 class="an-h4">Navegador</h4>${barList(R.devices.browser.filter(d => d.k !== '—').slice(0, 5), { pct: true })}</div>
      <div><h4 class="an-h4">Sistema</h4>${barList(R.devices.os.filter(d => d.k !== '—').slice(0, 5), { pct: true })}</div></div>`;
  }

  function interestsCard(R) {
    const blk = (t, items, empty) => `<div><h4 class="an-h4 first">${t}</h4>${barList(items.slice(0, 5).map(x => ({ k: x.k, n: x.n })), { empty })}</div>`;
    return `<div class="an-three">${blk('Búsquedas de sucursal', R.searches, 'Nadie ha usado el buscador.')}${blk('Cortes que tocan', R.cuts, 'Sin toques en el mapa de cortes.')}${blk('Vacantes que abren', R.vacancies, 'Sin vacantes abiertas.')}</div>`;
  }

  // ---------------------------------------------------------------- configuración y privacidad
  function settingsCard(j) {
    const c = j.config;
    return `<details class="an-card settings" id="anSettings"><summary><h3>Configuración y privacidad</h3><span>${j.can_manage ? 'Ajustar' : 'Ver'}</span></summary>
      <div class="an-privacy"><ul>
        <li><b>Sin cookies de rastreo</b> ni servicios externos: los datos viven en tu propio sitio.</li>
        <li><b>No se guarda la IP</b> ni nombre, correo, teléfono o lo que se escriba en formularios.</li>
        <li>Cada visitante es un código al azar en su navegador; la ciudad se estima al momento y solo se guarda el nombre de la ciudad.</li>
        <li>No cuenta robots de buscadores ni las visitas de este navegador mientras tengas la sesión abierta (puedes cambiarlo abajo para probar el sitio).</li>
      </ul>
      <label class="sw an-self"><input type="checkbox" id="anSelf" ${j.self_counts ? 'checked' : ''}><span></span>Contar mis propias visitas y acciones en este navegador <small>Actívalo para probar el sitio y ver aquí lo que haces. Desactívalo al terminar.</small></label></div>
      ${j.can_manage && c ? `<form id="anCfg" class="an-cfg">
        <label class="sw"><input type="checkbox" name="enabled" ${c.enabled ? 'checked' : ''}><span></span>Registrar visitas</label>
        <label class="sw"><input type="checkbox" name="geo_lookup" ${c.geo_lookup ? 'checked' : ''}><span></span>Estimar ciudad y estado del visitante <small>(envía la conexión del visitante al servicio ipwho.is y no se guarda)</small></label>
        <label class="sw"><input type="checkbox" name="exclude_staff" ${c.exclude_staff ? 'checked' : ''}><span></span>No contar visitas del equipo con sesión abierta</label>
        <label class="sw"><input type="checkbox" name="respect_dnt" ${c.respect_dnt ? 'checked' : ''}><span></span>Respetar “No rastrear” del navegador</label>
        <label class="fld">Conservar el historial durante <select name="retention_days">${[[90, '3 meses'], [180, '6 meses'], [400, '13 meses'], [730, '2 años'], [1100, '3 años']].map(o => `<option value="${o[0]}" ${+c.retention_days === o[0] ? 'selected' : ''}>${o[1]}</option>`).join('')}</select></label>
        <label class="fld">Ignorar estas direcciones IP <small>(por ejemplo, la de la oficina; separadas por coma)</small><input type="text" name="ignore_ips" value="${esc((c.ignore_ips || []).join(', '))}" placeholder="201.100.1.1, 187.0.0.5"></label>
        <div class="an-cfg-actions"><button type="submit" class="an-btn solid">Guardar configuración</button><span class="save-status" id="anCfgSt"></span></div>
        <div class="an-danger"><div><b>Datos</b><small>${j.has_demo ? 'Hay datos de ejemplo cargados.' : 'Para presentar el tablero puedes cargar datos de ejemplo (se pueden quitar con un clic).'}</small></div>
          <div>${j.has_demo ? '<button type="button" class="an-btn" id="anDemoOff2">Quitar datos de ejemplo</button>' : '<button type="button" class="an-btn" id="anDemoOn2">Cargar datos de ejemplo</button>'}
          <button type="button" class="an-btn danger" id="anWipe">Borrar todos los datos</button></div></div>
      </form>` : ''}
    </details>`;
  }

  const ICON = {
    users: '<svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    phone: '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
    pin: '<svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
    clock: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>',
    mobile: '<svg viewBox="0 0 24 24"><rect x="6" y="2" width="12" height="20" rx="2"/><path d="M11 18h2"/></svg>',
    link: '<svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 0 0 7.07 0l3-3a5 5 0 0 0-7.07-7.07l-1.5 1.5"/><path d="M14 11a5 5 0 0 0-7.07 0l-3 3a5 5 0 0 0 7.07 7.07l1.5-1.5"/></svg>',
    home: '<svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>',
    briefcase: '<svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>',
  };
  const ACT_ICON = { call: 'phone', wa: 'phone', dir: 'pin', mail: 'link', bsel: 'pin', near: 'pin', loc: 'pin', apply_open: 'briefcase', apply_done: 'briefcase', cut: 'link', gal: 'link', srch: 'link', out: 'link' };
  function actionIcon(i) { return `<em class="an-ai ${['call', 'wa', 'dir', 'mail'].includes(i.k) ? 'key' : ''}">${ICON[ACT_ICON[i.k]] || ''}</em>`; }

  // ---------------------------------------------------------------- gráfica de tendencia
  function niceMax(v) { if (v <= 4) return 4; const p = Math.pow(10, Math.floor(Math.log10(v))), f = v / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * p; }

  function drawTrend() {
    const host = $('#anTrend'); if (!host) return;
    const R = state.data.report, key = state.series;
    $$('#anSeries button').forEach(b => b.classList.toggle('on', b.dataset.s === key));
    const cur = R.daily.map(d => d[key]), prev = R.prev_daily.map(d => d[key]);
    const lbl = { visitors: 'Visitantes', sessions: 'Visitas', actions: 'Acciones' }[key];
    if (state.table) {
      host.innerHTML = `<div class="an-table-wrap tall"><table class="an-table plain"><thead><tr><th>Día</th><th class="r">Visitantes</th><th class="r">Visitas</th><th class="r">Páginas vistas</th><th class="r">Acciones</th></tr></thead><tbody>${R.daily.slice().reverse().map(d => `<tr><td>${esc(dLong(d.d))}</td><td class="r">${fmt(d.visitors)}</td><td class="r">${fmt(d.sessions)}</td><td class="r">${fmt(d.pageviews)}</td><td class="r">${fmt(d.actions)}</td></tr>`).join('')}</tbody></table></div>`;
      return;
    }
    const W = Math.max(260, host.clientWidth || 600), H = W < 520 ? 220 : 280;
    const m = { l: 40, r: 14, t: 14, b: 28 }, iw = W - m.l - m.r, ih = H - m.t - m.b, n = cur.length;
    const cmp = state.compare && prev.some(v => v > 0);
    const ymax = niceMax(Math.max(1, ...cur, ...(cmp ? prev : [0])));
    const x = i => m.l + (n === 1 ? iw / 2 : i * iw / (n - 1)), y = v => m.t + ih - v * ih / ymax;
    const path = a => a.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join('');
    let g = '';
    for (let k = 0; k <= 4; k++) { const v = ymax * k / 4, yy = y(v); g += `<line x1="${m.l}" x2="${W - m.r}" y1="${yy}" y2="${yy}" class="gl${k ? '' : ' base'}"/><text x="${m.l - 8}" y="${yy + 4}" class="ay" text-anchor="end">${v >= 1000 ? (v / 1000).toFixed(v % 1000 ? 1 : 0) + ' mil' : (Number.isInteger(v) ? v : v.toFixed(1))}</text>`; }
    const nx = Math.min(n, W < 520 ? 4 : 7);
    for (let k = 0; k < nx; k++) { const i = nx === 1 ? 0 : Math.round(k * (n - 1) / (nx - 1)); g += `<text x="${x(i)}" y="${H - 8}" class="ax" text-anchor="${k === 0 ? 'start' : k === nx - 1 ? 'end' : 'middle'}">${dShort(R.daily[i].d)}</text>`; }
    const area = n > 1 ? `<path d="${path(cur)}L${x(n - 1)} ${y(0)}L${x(0)} ${y(0)}Z" class="area"/>` : '';
    const dots = n <= 45 ? cur.map((v, i) => `<circle cx="${x(i)}" cy="${y(v)}" r="${n > 31 ? 2.2 : 3}" class="dot"/>`).join('') : '';
    host.innerHTML = `<div class="an-legend-t"><span><i class="cur"></i>${lbl}</span>${cmp ? `<span><i class="prv"></i>${R.range.days === 1 ? 'Día anterior' : 'Periodo anterior'}</span>` : ''}</div>
      <svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" class="an-svg" role="img" aria-label="Gráfica de ${lbl.toLowerCase()} por día">${g}${area}${cmp ? `<path d="${path(prev)}" class="prv"/>` : ''}<path d="${path(cur)}" class="cur"/>${dots}
      <line class="xh" y1="${m.t}" y2="${m.t + ih}" x1="0" x2="0" hidden/><circle class="hd" r="5" hidden/><rect class="hit" x="${m.l}" y="${m.t}" width="${iw}" height="${ih}" fill="transparent"/></svg>`;
    const svg = $('svg', host), hit = $('.hit', svg), xh = $('.xh', svg), hd = $('.hd', svg);
    const at = ev => {
      const r = svg.getBoundingClientRect(), px = ((ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left) * (W / r.width);
      const i = Math.max(0, Math.min(n - 1, Math.round((px - m.l) / (iw / Math.max(1, n - 1)))));
      xh.removeAttribute('hidden'); hd.removeAttribute('hidden');
      xh.setAttribute('x1', x(i)); xh.setAttribute('x2', x(i)); hd.setAttribute('cx', x(i)); hd.setAttribute('cy', y(cur[i]));
      const d = R.daily[i], pd = cmp ? R.prev_daily[i] : null;
      showTip(`<b>${esc(dLong(d.d))}</b><div class="tr"><i class="cur"></i>${lbl}: <b>${fmt(cur[i])}</b></div>${pd ? `<div class="tr"><i class="prv"></i>${esc(dShort(pd.d))}: <b>${fmt(prev[i])}</b></div>` : ''}`, ((ev.touches ? ev.touches[0].clientX : ev.clientX)), r.top + y(cur[i]) * (r.height / H));
    };
    hit.addEventListener('mousemove', at); hit.addEventListener('touchstart', at, { passive: true }); hit.addEventListener('touchmove', at, { passive: true });
    hit.addEventListener('mouseleave', () => { xh.setAttribute('hidden', ''); hd.setAttribute('hidden', ''); hideTip(); });
    hit.addEventListener('touchend', () => setTimeout(() => { xh.setAttribute('hidden', ''); hd.setAttribute('hidden', ''); hideTip(); }, 1800));
  }

  function showTip(html, cx, cy) {
    tip.innerHTML = html; tip.hidden = false;
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let left = cx + 14, top = cy - h - 10;
    if (left + w > window.innerWidth - 8) left = cx - w - 14;
    if (left < 8) left = 8;
    if (top < 8) top = cy + 18;
    tip.style.left = left + 'px'; tip.style.top = top + 'px';
  }
  function hideTip() { tip.hidden = true; }

  // ---------------------------------------------------------------- eventos
  function wire() {
    // sugerencias al pasar sobre mapa y calor
    $$('[data-tip]').forEach(el => {
      el.addEventListener('mousemove', e => showTip(el.dataset.tip, e.clientX, e.clientY));
      el.addEventListener('mouseleave', hideTip);
      el.addEventListener('click', e => { showTip(el.dataset.tip, e.clientX, e.clientY); clearTimeout(wire.t); wire.t = setTimeout(hideTip, 2200); });
    });
    $$('#anSeries button').forEach(b => b.onclick = () => { state.series = b.dataset.s; drawTrend(); });
    const cmp = $('#anCmp'); if (cmp) cmp.onchange = () => { state.compare = cmp.checked; drawTrend(); };
    const tb = $('#anTblBtn'); if (tb) tb.onclick = () => { state.table = !state.table; tb.textContent = state.table ? 'Ver gráfica' : 'Ver tabla'; drawTrend(); };
    const on = $('#anDemoOn') || $('#anDemoOn2'), on2 = $('#anDemoOn2');
    [$('#anDemoOn'), on2].forEach(b => b && (b.onclick = () => post('demo_on', 'Cargando datos de ejemplo…', 'Datos de ejemplo cargados.')));
    [$('#anDemoOff'), $('#anDemoOff2')].forEach(b => b && (b.onclick = () => post('demo_off', 'Quitando…', 'Datos de ejemplo eliminados.')));
    const wipe = $('#anWipe'); if (wipe) wipe.onclick = () => { if (confirm('¿Borrar TODO el historial de visitas? Esta acción no se puede deshacer.')) post('wipe', 'Borrando…', 'Se borró el historial de analítica.'); };
    const self = $('#anSelf'); if (self) self.onchange = async () => {
      try {
        const j = await (await fetch(API + '?action=self&on=' + (self.checked ? 1 : 0), { method: 'POST' })).json();
        if (!j.success) throw new Error(j.error);
        showToast(self.checked ? 'Listo: en este navegador tus visitas SÍ se cuentan.' : 'Tus visitas en este navegador ya no se cuentan.');
      } catch (e) { self.checked = !self.checked; showToast('No se pudo cambiar. Intenta de nuevo.', true); }
    };
    const cfg = $('#anCfg'); if (cfg) cfg.onsubmit = async e => {
      e.preventDefault();
      const f = new FormData(cfg), body = { retention_days: +f.get('retention_days'), ignore_ips: String(f.get('ignore_ips') || '') };
      ['enabled', 'geo_lookup', 'exclude_staff', 'respect_dnt'].forEach(k => body[k] = f.get(k) === 'on');
      const st = $('#anCfgSt');
      try {
        const j = await (await fetch(API + '?action=config', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
        if (!j.success) throw new Error(j.error);
        st.textContent = 'Guardado ✓'; st.classList.add('show'); setTimeout(() => st.classList.remove('show'), 2500);
        load(true);
      } catch (err) { st.textContent = err.message || 'No se pudo guardar'; st.classList.add('show', 'error'); }
    };
  }

  async function post(action, busy, done) {
    showToast(busy);
    try {
      const r = await fetch(API + '?action=' + action + '&_=' + Date.now(), { method: 'POST', cache: 'no-store' });
      let j; try { j = await r.json(); } catch (e) { throw new Error('El servidor respondió con un error (' + r.status + '). Intenta de nuevo.'); }
      if (!j.success) throw new Error(j.error || 'No se pudo completar');
      showToast(done); state.loaded = true; load();
    } catch (e) { showToast(e.message || 'No se pudo completar', true); }
  }

  // barra de herramientas
  $$('.an-seg button', $('#anToolbar')).forEach(b => b.onclick = () => {
    state.range = b.dataset.r;
    if (state.range === 'custom') { const r = computeRange(); state.from = state.from || r.from; state.to = state.to || r.to; $('#anCustom').hidden = false; $$('.an-seg button').forEach(x => x.classList.toggle('on', x === b)); return; }
    save(); load();
  });
  $('#anApply').onclick = () => {
    let f = $('#anFrom').value, t = $('#anTo').value; if (!f || !t) return;
    if (f > t) [f, t] = [t, f];
    state.from = f; state.to = t; state.range = 'custom'; save(); load();
  };
  $('#anBranch').onchange = e => { state.branch = e.target.value; load(); pollLive(); };
  $('#anCsv').onclick = () => { const r = computeRange(); location.href = `${API}?action=csv&from=${r.from}&to=${r.to}&branch=${encodeURIComponent(state.branch)}`; };
  $('#anPrint').onclick = () => window.print();
  function save() { try { localStorage.setItem('dicosa_an', JSON.stringify({ range: state.range, from: state.from, to: state.to })); } catch (e) {} }

  // ayudas “i”
  root.addEventListener('click', e => {
    const b = e.target.closest('.an-i');
    if (!b) { pop.hidden = true; return; }
    e.stopPropagation();
    if (!pop.hidden && pop._for === b) { pop.hidden = true; return; }
    pop.textContent = b.dataset.help; pop._for = b; pop.hidden = false;
    const r = b.getBoundingClientRect(), host = root.getBoundingClientRect();
    pop.style.top = (r.bottom - host.top + 8) + 'px';
    pop.style.left = Math.max(8, Math.min(r.left - host.left - 20, host.width - pop.offsetWidth - 8)) + 'px';
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { pop.hidden = true; hideTip(); } });
  window.addEventListener('scroll', hideTip, { passive: true });
  let rs; window.addEventListener('resize', () => { clearTimeout(rs); rs = setTimeout(() => { if (state.data && $('#anTrend')) drawTrend(); }, 150); });

  // ---------------------------------------------------------------- arranque
  async function boot() {
    if (!D.user || D.user.role !== 'manager') {
      try { const bl = await (await fetch('../data/branches.json?_=' + Date.now())).json(); state.branches = bl.filter(b => b.active !== false).map(b => ({ id: b.id, name: b.name })); } catch (e) {}
      $('#anBranch').innerHTML = '<option value="">Todo el sitio</option>' + state.branches.map(b => `<option value="${esc(b.id)}">${esc(b.name)}</option>`).join('');
    }
    load(); pollLive();
    setInterval(pollLive, 30000);
    setInterval(() => { if (!document.hidden && panelEl.classList.contains('active') && state.range !== 'custom') load(true); }, 300000);
  }
  // se carga al abrir el panel por primera vez (o de inmediato si ya es el activo)
  let started = false;
  const start = () => { if (!started) { started = true; boot(); } };
  if (panelEl.classList.contains('active')) start();
  document.querySelectorAll('.side-link[data-panel="panel-analitica"]').forEach(l => l.addEventListener('click', () => { start(); setTimeout(() => state.data && drawTrend(), 60); }));
})();
