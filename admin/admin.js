// ===== Permisos (vienen de dashboard.php) =====
const D = window.DICOSA || { user: {}, perms: [], roles: {} };
const can = p => D.perms.indexOf(p) !== -1;
const SCOPED = D.user.role === 'manager';

// Todas las peticiones del panel llevan el token anti-CSRF; si la sesión expiró, se regresa al login.
(function () {
  const nativeFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    const method = (init.method || (input && input.method) || 'GET').toUpperCase();
    if (method === 'POST') {
      const h = new Headers(init.headers || {});
      if (D.csrf) h.set('X-CSRF-Token', D.csrf);
      init = Object.assign({}, init, { headers: h });
    }
    const res = await nativeFetch(input, init);
    if (res.status === 401) { window.location.href = 'login.php?expired=1'; }
    return res;
  };
})();


// ===== Toast =====
const toast = document.getElementById('toast');
let toastTimer = null;
function showToast(message, isError = false) {
  clearTimeout(toastTimer);
  toast.textContent = message;
  toast.classList.toggle('error', isError);
  toast.classList.add('show');
  toastTimer = setTimeout(() => toast.classList.remove('show'), 3200);
}

// ===== Sidebar panel switching =====
const sideLinks = document.querySelectorAll('.side-link');
const panels = document.querySelectorAll('.panel');
// Ajusta la altura de los textos de las vacantes (se recalcula al mostrar el panel y al girar el teléfono)
function growVacantes() {
  document.querySelectorAll('#vacantesGrid textarea').forEach(t => { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; });
}
window.addEventListener('resize', growVacantes);

// Menú deslizable en teléfono
const mTitle = document.getElementById('mTitle');
const mBurger = document.getElementById('mBurger');
function setNav(open) {
  document.body.classList.toggle('nav-open', open);
  if (mBurger) mBurger.setAttribute('aria-expanded', open ? 'true' : 'false');
}
if (mBurger) mBurger.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
const mScrim = document.getElementById('mScrim');
if (mScrim) mScrim.addEventListener('click', () => setNav(false));
document.addEventListener('keydown', e => { if (e.key === 'Escape') setNav(false); });
function syncTitle() {
  const a = document.querySelector('.side-link.active');
  if (a && mTitle) mTitle.textContent = a.textContent.replace(/\s+\d+\s*$/, '').trim();
}

sideLinks.forEach(link => {
  link.addEventListener('click', () => {
    setNav(false);
    window.scrollTo(0, 0);
    setTimeout(growVacantes, 30);
    sideLinks.forEach(l => l.classList.remove('active'));
    panels.forEach(p => p.classList.remove('active'));
    link.classList.add('active');
    document.getElementById(link.dataset.panel).classList.add('active');
  });
});

syncTitle();
sideLinks.forEach(l => l.addEventListener('click', syncTitle));

// ===== Guardar contacto =====
const formContacto = document.getElementById('formContacto');
const contactoStatus = document.getElementById('contactoStatus');

if (formContacto) formContacto.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = formContacto.querySelector('.btn-save');
  btn.disabled = true;
  btn.textContent = 'Guardando...';
  contactoStatus.classList.remove('show', 'error');

  try {
    const res = await fetch('save-contact.php', {
      method: 'POST',
      body: new FormData(formContacto),
    });
    const json = await res.json();

    if (json.success) {
      contactoStatus.textContent = 'Guardado ✓';
      contactoStatus.classList.add('show');
      showToast('Los cambios ya están publicados en la página.');
    } else {
      throw new Error(json.error || 'No se pudo guardar.');
    }
  } catch (err) {
    contactoStatus.textContent = 'Error al guardar';
    contactoStatus.classList.add('show', 'error');
    showToast(err.message || 'Ocurrió un error al guardar.', true);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Guardar cambios';
  }
});

// ===== Galería: subir fotos =====
document.querySelectorAll('.upload-input').forEach(input => {
  input.addEventListener('change', () => {
    if (input.files && input.files[0]) {
      uploadPhoto(input.dataset.slot, input.files[0]);
    }
  });
});

// Reduce la foto a 2000 px de lado mayor (respeta la orientación del celular). Si falla, se sube la original.
async function shrinkImage(file) {
  try {
    if (file.size < 600 * 1024) return file;
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, 2000 / Math.max(bmp.width, bmp.height));
    const w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
    const canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, w, h);
    ctx.drawImage(bmp, 0, 0, w, h);
    const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.88));
    if (blob && blob.size < file.size) return new File([blob], 'foto.jpg', { type: 'image/jpeg' });
  } catch (e) { /* se sube la original */ }
  return file;
}

async function uploadPhoto(slot, file) {
  const preview = document.getElementById(`preview-${slot}`);
  const hint = document.getElementById(`hint-${slot}`);
  const progress = document.getElementById(`progress-${slot}`);

  if (!file.type.match(/^image\/(jpeg|png|webp)$/)) {
    showToast('Solo se permiten imágenes JPG, PNG o WEBP.', true);
    return;
  }
  if (file.size > 25 * 1024 * 1024) {
    showToast('La imagen pesa demasiado (máximo 25MB).', true);
    return;
  }

  // Vista previa local instantánea, antes de que termine de subir.
  const localUrl = URL.createObjectURL(file);
  preview.style.backgroundImage = `url('${localUrl}')`;
  preview.classList.remove('empty');
  preview.innerHTML = '';
  hint.textContent = 'Subiendo...';
  progress.style.width = '30%';

  // Se reduce en el navegador antes de subir: ahorra datos móviles y evita rechazos por tamaño.
  const toSend = await shrinkImage(file);

  const formData = new FormData();
  formData.append('slot', slot);
  formData.append('photo', toSend, toSend.name || 'foto.jpg');

  try {
    const res = await fetch('upload-photo.php', { method: 'POST', body: formData });
    const json = await res.json();
    progress.style.width = '100%';

    if (json.success) {
      preview.style.backgroundImage = `url('${json.url}')`;
      hint.textContent = 'Foto cargada';
      showToast('Foto actualizada' + (json.kb ? ' (' + json.kb + ' KB)' : '') + '.');
    } else {
      throw new Error(json.error || 'No se pudo subir la foto.');
    }
  } catch (err) {
    hint.textContent = 'Error al subir';
    showToast(err.message || 'Ocurrió un error al subir la foto.', true);
  } finally {
    setTimeout(() => { progress.style.width = '0%'; }, 500);
  }
}

// Drag & drop sobre cada tile
document.querySelectorAll('.upload-tile').forEach(tile => {
  const slot = tile.dataset.slot;
  ['dragenter', 'dragover'].forEach(evt =>
    tile.addEventListener(evt, (e) => { e.preventDefault(); tile.classList.add('dragover'); })
  );
  ['dragleave', 'drop'].forEach(evt =>
    tile.addEventListener(evt, (e) => { e.preventDefault(); tile.classList.remove('dragover'); })
  );
  tile.addEventListener('drop', (e) => {
    const file = e.dataTransfer.files && e.dataTransfer.files[0];
    if (file) uploadPhoto(slot, file);
  });
});

// ===== Bolsa de trabajo =====
const candidatosGrid = document.getElementById('candidatosGrid');
const empleoEmptyMsg = document.getElementById('empleoEmptyMsg');
const empleoTabs = document.getElementById('empleoTabs');
const btnRefreshEmpleo = document.getElementById('btnRefreshEmpleo');
const sideBadgeEmpleo = document.getElementById('sideBadgeEmpleo');

let allApplications = [];
let currentFilter = 'todos';

const STATUS_LABELS = {
  recibido: 'Recibido',
  agendado: 'Agendado',
  rechazado: 'Rechazado',
  contratado: 'Contratado',
};

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str || '';
  return div.innerHTML;
}

function renderCandidatos() {
  const filtered = currentFilter === 'todos'
    ? allApplications
    : allApplications.filter(a => a.status === currentFilter);

  // Contadores
  document.getElementById('count-todos').textContent = allApplications.length;
  ['recibido', 'agendado', 'rechazado', 'contratado'].forEach(s => {
    document.getElementById(`count-${s}`).textContent = allApplications.filter(a => a.status === s).length;
  });

  const nuevos = allApplications.filter(a => a.status === 'recibido').length;
  if (nuevos > 0) {
    sideBadgeEmpleo.textContent = nuevos;
    sideBadgeEmpleo.hidden = false;
  } else {
    sideBadgeEmpleo.hidden = true;
  }

  if (filtered.length === 0) {
    candidatosGrid.innerHTML = '';
    empleoEmptyMsg.textContent = allApplications.length === 0
      ? 'Todavía no hay candidatos. En cuanto alguien envíe su CV desde la página, va a aparecer aquí.'
      : 'No hay candidatos con este estado.';
    candidatosGrid.appendChild(empleoEmptyMsg);
    return;
  }

  candidatosGrid.innerHTML = filtered.map(app => {
    const date = new Date(app.applied_at.replace(' ', 'T'));
    const dateStr = isNaN(date) ? app.applied_at : date.toLocaleDateString('es-MX', { day: 'numeric', month: 'long', year: 'numeric' });
    return `
      <div class="candidato-card" data-id="${escapeHtml(app.id)}">
        <div class="candidato-main">
          <div class="candidato-top">
            <span class="candidato-name">${escapeHtml(app.name)}</span>
            <span class="status-pill status-${app.status}">${STATUS_LABELS[app.status] || app.status}</span>
          </div>
          <div class="candidato-position">${escapeHtml(app.position)}</div>
          <div class="candidato-meta">
            <a href="tel:${escapeHtml(app.phone)}">${escapeHtml(app.phone)}</a>
            <a href="mailto:${escapeHtml(app.email)}">${escapeHtml(app.email)}</a>
          </div>
          ${app.message ? `<div class="candidato-message">${escapeHtml(app.message)}</div>` : ''}
          <div class="candidato-date">Recibido el ${dateStr}</div>
        </div>
        <div class="candidato-actions">
          <a href="download-cv.php?id=${encodeURIComponent(app.id)}" target="_blank" class="btn-cv">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
            Ver CV
          </a>
          <select class="status-select" data-id="${escapeHtml(app.id)}">
            ${Object.entries(STATUS_LABELS).map(([val, label]) =>
              `<option value="${val}" ${app.status === val ? 'selected' : ''}>${label}</option>`
            ).join('')}
          </select>
        </div>
      </div>
    `;
  }).join('');

  candidatosGrid.querySelectorAll('.status-select').forEach(select => {
    select.addEventListener('change', () => updateStatus(select.dataset.id, select.value));
  });
}

async function loadApplications(showSpinner = false, background = false) {
  if (showSpinner) btnRefreshEmpleo.classList.add('spinning');
  try {
    const res = await fetch('get-applications.php', background ? { headers: { 'X-Background': '1' } } : undefined);
    const json = await res.json();
    if (json.success) {
      allApplications = json.applications;
      renderCandidatos();
    }
  } catch (err) {
    showToast('No se pudo cargar la lista de candidatos.', true);
  } finally {
    if (showSpinner) setTimeout(() => btnRefreshEmpleo.classList.remove('spinning'), 400);
  }
}

async function updateStatus(id, status) {
  try {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('status', status);
    const res = await fetch('update-application-status.php', { method: 'POST', body: formData });
    const json = await res.json();
    if (json.success) {
      const app = allApplications.find(a => a.id === id);
      if (app) app.status = status;
      renderCandidatos();
      showToast('Estado actualizado.');
    } else {
      throw new Error(json.error || 'No se pudo actualizar.');
    }
  } catch (err) {
    showToast(err.message || 'Ocurrió un error.', true);
    loadApplications();
  }
}

empleoTabs.addEventListener('click', (e) => {
  const btn = e.target.closest('.empleo-tab');
  if (!btn) return;
  empleoTabs.querySelectorAll('.empleo-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  currentFilter = btn.dataset.filter;
  renderCandidatos();
});

btnRefreshEmpleo.addEventListener('click', () => loadApplications(true));

if (can('candidates.view')) {
  loadApplications();
  setInterval(() => loadApplications(false, true), 60000);
}
//  // revisa cada minuto si hay candidatos nuevos

// ===== Vacantes =====
const vacantesGrid = document.getElementById('vacantesGrid');
const vacantesEmptyMsg = document.getElementById('vacantesEmptyMsg');
const formNuevaVacante = document.getElementById('formNuevaVacante');
const vacanteNuevaStatus = document.getElementById('vacanteNuevaStatus');

let allVacancies = [];
let vacBranches = [];
const branchName = id => { const b = vacBranches.find(x => x.id === id); return b ? b.name : ''; };
const branchOptions = sel => '<option value="">General (todas)</option>' + vacBranches.map(b => '<option value="' + escapeHtml(b.id) + '"' + (b.id === sel ? ' selected' : '') + '>' + escapeHtml(b.name) + '</option>').join('');

function renderVacantes() {
  if (allVacancies.length === 0) {
    vacantesGrid.innerHTML = '';
    vacantesEmptyMsg.textContent = 'Todavía no has agregado ninguna vacante.';
    vacantesGrid.appendChild(vacantesEmptyMsg);
    return;
  }

  vacantesGrid.innerHTML = allVacancies.map(v => `
    <div class="vacante-card ${v.active ? '' : 'inactive'}" data-id="${escapeHtml(v.id)}">
      <div class="vacante-main">
        <div class="vacante-title-row">
          <textarea class="vacante-title-input" rows="1" placeholder="Nombre del puesto">${escapeHtml(v.title)}</textarea>
        </div>
        <textarea class="vacante-desc" rows="1" placeholder="Descripción (opcional)">${escapeHtml(v.description)}</textarea>
        ${SCOPED ? '' : `<select class="vacante-branch" title="Sucursal de esta vacante">${branchOptions(v.branch_id || '')}</select>`}
        <span class="vacante-save-hint">Los cambios se guardan solos al salir del campo</span>
      </div>
      <div class="vacante-actions">
        <label class="toggle-switch" title="${v.active ? 'Vacante activa (visible en el sitio)' : 'Vacante inactiva (oculta)'}">
          <input type="checkbox" class="vacante-active-toggle" ${v.active ? 'checked' : ''}>
          <span class="toggle-track"></span>
        </label>
        <button class="btn-delete-vacante" title="Eliminar vacante">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6h14z"/></svg>
        </button>
      </div>
    </div>
  `).join('');

  vacantesGrid.querySelectorAll('.vacante-card').forEach(card => {
    const id = card.dataset.id;
    const titleInput = card.querySelector('.vacante-title-input');
    const grow = el => { el.style.height = 'auto'; el.style.height = el.scrollHeight + 'px'; };
    card.querySelectorAll('textarea').forEach(t => { t.addEventListener('input', () => grow(t)); requestAnimationFrame(() => grow(t)); });
    titleInput.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); titleInput.blur(); } });
    const descInput = card.querySelector('.vacante-desc');
    const toggle = card.querySelector('.vacante-active-toggle');
    const deleteBtn = card.querySelector('.btn-delete-vacante');
    const branchSel = card.querySelector('.vacante-branch');
    if (!can('vacancies.delete') && deleteBtn) deleteBtn.hidden = true;

    const saveThis = () => saveVacante(id, titleInput.value, descInput.value, toggle.checked, card, branchSel ? branchSel.value : null);
    if (branchSel) branchSel.addEventListener('change', saveThis);
    titleInput.addEventListener('blur', saveThis);
    descInput.addEventListener('blur', saveThis);
    toggle.addEventListener('change', saveThis);

    deleteBtn.addEventListener('click', () => {
      if (confirm(`¿Eliminar la vacante "${titleInput.value}"? Esto no se puede deshacer.`)) {
        deleteVacante(id, card);
      }
    });
  });
}

function fillVacBranchSelect() {
  const wrap = document.getElementById('vacBranchWrap');
  if (!wrap) return;
  if (SCOPED) { wrap.hidden = true; return; }
  const sel = wrap.querySelector('select'), cur = sel.value;
  sel.innerHTML = branchOptions(cur);
}

async function loadVacantesAdmin() {
  try {
    const res = await fetch('get-vacancies.php');
    const json = await res.json();
    if (json.success) {
      allVacancies = json.vacancies;
      vacBranches = json.branches || [];
      fillVacBranchSelect();
      renderVacantes();
    }
  } catch (err) {
    showToast('No se pudo cargar la lista de vacantes.', true);
  }
}

async function saveVacante(id, title, description, active, card, branchId) {
  if (!title.trim()) {
    showToast('El nombre del puesto no puede quedar vacío.', true);
    loadVacantesAdmin();
    return;
  }
  try {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('title', title.trim());
    formData.append('description', description.trim());
    formData.append('active', active ? '1' : '0');
    if (branchId !== null && branchId !== undefined) formData.append('branch_id', branchId);
    const res = await fetch('save-vacancy.php', { method: 'POST', body: formData });
    const json = await res.json();
    if (json.success) {
      card.classList.toggle('inactive', !active);
      const v = allVacancies.find(x => x.id === id);
      if (v) { v.title = title.trim(); v.description = description.trim(); v.active = active; }
      showToast('Vacante actualizada.');
    } else {
      throw new Error(json.error || 'No se pudo guardar.');
    }
  } catch (err) {
    showToast(err.message || 'Ocurrió un error.', true);
  }
}

async function deleteVacante(id, card) {
  try {
    const formData = new FormData();
    formData.append('id', id);
    const res = await fetch('delete-vacancy.php', { method: 'POST', body: formData });
    const json = await res.json();
    if (json.success) {
      allVacancies = allVacancies.filter(v => v.id !== id);
      card.remove();
      if (allVacancies.length === 0) renderVacantes();
      showToast('Vacante eliminada.');
    } else {
      throw new Error(json.error || 'No se pudo eliminar.');
    }
  } catch (err) {
    showToast(err.message || 'Ocurrió un error.', true);
  }
}

formNuevaVacante.addEventListener('submit', async (e) => {
  e.preventDefault();
  const btn = formNuevaVacante.querySelector('.btn-save');
  const title = formNuevaVacante.title.value.trim();
  if (!title) {
    vacanteNuevaStatus.textContent = 'Escribe un nombre de puesto.';
    vacanteNuevaStatus.classList.add('show', 'error');
    return;
  }
  btn.disabled = true;
  btn.textContent = 'Agregando...';
  try {
    const formData = new FormData();
    formData.append('id', '');
    formData.append('title', title);
    formData.append('description', formNuevaVacante.description.value.trim());
    formData.append('active', '1');
    if (!SCOPED && formNuevaVacante.branch_id) formData.append('branch_id', formNuevaVacante.branch_id.value);
    const res = await fetch('save-vacancy.php', { method: 'POST', body: formData });
    const json = await res.json();
    if (json.success) {
      formNuevaVacante.reset();
      vacanteNuevaStatus.classList.remove('show', 'error');
      showToast('Vacante agregada.');
      loadVacantesAdmin();
    } else {
      throw new Error(json.error || 'No se pudo agregar.');
    }
  } catch (err) {
    vacanteNuevaStatus.textContent = err.message || 'Ocurrió un error.';
    vacanteNuevaStatus.classList.add('show', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = '+ Agregar vacante';
  }
});

if (can('vacancies.view')) loadVacantesAdmin();

// ===== Probar notificación por correo =====
const btnTestEmail = document.getElementById('btnTestEmail');
const testEmailStatus = document.getElementById('testEmailStatus');

if (btnTestEmail) btnTestEmail.addEventListener('click', async () => {
  btnTestEmail.disabled = true;
  btnTestEmail.textContent = 'Enviando...';
  testEmailStatus.classList.remove('show', 'error');

  try {
    const res = await fetch('test-email.php', { method: 'POST' });
    const json = await res.json();
    if (json.success) {
      testEmailStatus.textContent = '¡Enviado! Revisa la bandeja de entrada.';
      testEmailStatus.classList.add('show');
      showToast('Correo de prueba enviado.');
    } else {
      throw new Error(json.error || 'No se pudo enviar.');
    }
  } catch (err) {
    testEmailStatus.textContent = err.message || 'Ocurrió un error.';
    testEmailStatus.classList.add('show', 'error');
  } finally {
    btnTestEmail.disabled = false;
    btnTestEmail.textContent = 'Enviar correo de prueba';
  }
});

// ===== Cambiar contraseña =====
const formPassword = document.getElementById('formPassword');
const passwordStatus = document.getElementById('passwordStatus');

formPassword.addEventListener('submit', async (e) => {
  e.preventDefault();
  const newPass = formPassword.new_password.value;
  const confirmPass = formPassword.confirm_password.value;

  if (newPass !== confirmPass) {
    passwordStatus.textContent = 'Las contraseñas no coinciden';
    passwordStatus.classList.add('show', 'error');
    return;
  }

  const btn = formPassword.querySelector('.btn-save');
  btn.disabled = true;
  btn.textContent = 'Actualizando...';
  passwordStatus.classList.remove('show', 'error');

  try {
    const res = await fetch('change-password.php', {
      method: 'POST',
      body: new FormData(formPassword),
    });
    const json = await res.json();

    if (json.success) {
      passwordStatus.textContent = 'Contraseña actualizada ✓';
      passwordStatus.classList.add('show');
      showToast('Tu contraseña se actualizó correctamente.');
      formPassword.reset();
      if (D.force) { setTimeout(() => location.reload(), 900); }
    } else {
      throw new Error(json.error || 'No se pudo actualizar.');
    }
  } catch (err) {
    passwordStatus.textContent = 'Error';
    passwordStatus.classList.add('show', 'error');
    showToast(err.message || 'Ocurrió un error.', true);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Actualizar contraseña';
  }
});

// ===== Sucursales =====
const sucGrid = document.getElementById('sucursalesGrid');
let sucursales = [];

async function sucApi(data) {
  const fd = new FormData();
  Object.entries(data).forEach(([k, v]) => fd.append(k, v));
  const r = await fetch('branches-api.php', { method: 'POST', body: fd });
  return r.json();
}

function sucCard(b, open) {
  const v = x => escapeHtml(x == null ? '' : String(x));
  const hasGeo = b.lat != null && b.lat !== '' && b.lng != null && b.lng !== '';
  const isNew = !b.id;
  const tags = (b.code ? '<span class="suc-tag code" title="Clave fija">#' + v(b.code) + '</span>' : '') + (b.is_main ? '<span class="suc-tag tag-central">Central</span>' : '') +
    (b.active === false ? '<span class="suc-tag off">Inactiva</span>' : '') +
    (b.id && !hasGeo ? '<span class="suc-tag warn">Sin ubicación</span>' : '');
  return `<div class="vacante-card suc-item ${b.active === false ? 'inactive' : ''} ${open ? 'open' : ''}" data-id="${v(b.id)}" style="flex-direction:column">
    <button type="button" class="suc-head" data-a="toggle" aria-expanded="${open ? 'true' : 'false'}">
      <span class="suc-head-main"><strong>${v(b.name) || 'Nueva sucursal'}</strong>${tags}<small>${v(b.address)}</small></span>
      <span class="suc-chev" aria-hidden="true">▾</span>
    </button>
    <div class="suc-body">
      <div class="form-grid" style="width:100%">
        ${isNew
          ? '<label class="span-2"><span>Clave fija de la sucursal</span><input data-f="code" placeholder="ej. pedregoso" maxlength="30" autocomplete="off"><small class="suc-hint">Es el identificador permanente (letras y números, sin espacios). <strong>No se podrá cambiar después</strong>: si te equivocas, hay que eliminar la sucursal y crearla de nuevo. Cada gerente queda ligado a esta clave.</small></label>'
          : '<div class="span-2 code-lock"><span>Clave fija</span><strong>' + v(b.code) + '</strong><small>🔒 No se puede modificar. Para cambiarla hay que eliminar la sucursal.</small></div>'}
        <label class="span-2"><span>Nombre</span><input data-f="name" value="${v(b.name)}" placeholder="Sucursal Centro"></label>
        <label class="span-2"><span>Dirección</span><input data-f="address" value="${v(b.address)}"></label>
        <label><span>Teléfono</span><input data-f="phone" value="${v(b.phone)}"></label>
        <label><span>Horario</span><input data-f="hours" value="${v(b.hours)}"></label>
        <label><span>Latitud</span><input data-f="lat" value="${v(b.lat)}" placeholder="20.375986"></label>
        <label><span>Longitud</span><input data-f="lng" value="${v(b.lng)}" placeholder="-99.958136"></label>
        <p class="span-2 suc-hint">Clic derecho sobre el lugar en Google Maps → copia los dos números → pégalos aquí (primero latitud, luego longitud).</p>
      </div>
      <div class="suc-admin-actions">
        <button type="button" class="btn-refresh" data-a="geo">Coordenadas desde la dirección</button>
        ${SCOPED ? '' : `<label class="chk"><input type="checkbox" data-f="is_main" ${b.is_main ? 'checked' : ''}> Central</label>
        <label class="chk"><input type="checkbox" data-f="active" ${b.active !== false ? 'checked' : ''}> Activa</label>`}
        ${b.id && can('branches.delete') ? '<button type="button" class="btn-refresh" data-a="del">Eliminar</button>' : ''}
        <button type="button" class="btn-save" data-a="save">Guardar</button>
      </div>
    </div>
  </div>`;
}

function sucRender(withNew) {
  let bar = document.getElementById('sucFilter');
  if (!bar) {
    bar = document.createElement('input');
    bar.id = 'sucFilter'; bar.type = 'search'; bar.placeholder = 'Buscar sucursal…'; bar.className = 'suc-filter';
    bar.addEventListener('input', () => sucRender(false));
    sucGrid.parentNode.insertBefore(bar, sucGrid);
  }
  const t = bar.value.trim().toLowerCase();
  const rows = (withNew ? [{ id: '', active: true }] : [])
    .concat(sucursales.filter(b => !t || (b.name + ' ' + (b.code || '') + ' ' + (b.address || '')).toLowerCase().includes(t)));
  sucGrid.innerHTML = rows.length
    ? rows.map((b, i) => sucCard(b, withNew && i === 0)).join('')
    : '<p class="empleo-empty">' + (t ? 'Sin resultados.' : 'Todavía no hay sucursales.') + '</p>';
  bar.style.display = sucursales.length > 4 ? '' : 'none';
}

async function sucLoad() {
  try {
    const j = await sucApi({ action: 'list' });
    if (j.success) { sucursales = j.branches; sucRender(false); }
  } catch (e) { showToast('No se pudo cargar la lista de sucursales.', true); }
}

const btnNS = document.getElementById('btnNuevaSucursal');
if (btnNS && !can('branches.create')) btnNS.hidden = true;
if (btnNS) btnNS.addEventListener('click', () => sucRender(true));

sucGrid.addEventListener('click', async e => {
  const btn = e.target.closest('button[data-a]');
  if (!btn) return;
  const card = btn.closest('.vacante-card');
  const f = n => card.querySelector('[data-f="' + n + '"]');
  const a = btn.dataset.a;

  if (a === 'toggle') {
    const open = card.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    return;
  }

  if (a === 'geo') {
    const addr = f('address').value.trim();
    if (!addr) { showToast('Escribe la dirección primero.', true); return; }
    try {
      const parts = addr.replace(/\bCP\.?\s*\d+/i, '').split(',').map(s => s.trim()).filter(Boolean);
      let hit = null;
      for (let i = 0; i < Math.max(1, parts.length - 1) && !hit; i++) {
        const term = parts.slice(i).join(', ') + ', México';
        const r = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=mx&q=' + encodeURIComponent(term));
        const j = await r.json();
        if (j[0]) hit = j[0];
      }
      if (!hit) throw new Error('sin resultado');
      f('lat').value = (+hit.lat).toFixed(6);
      f('lng').value = (+hit.lon).toFixed(6);
      showToast('Coordenadas aproximadas encontradas. Revisa en el mapa y ajusta si hace falta.');
    } catch (err) {
      showToast('No se encontró esa dirección. Pega un enlace de Google Maps o escribe las coordenadas.', true);
    }
  }

  if (a === 'save') {
    const j = await sucApi({
      action: 'save', id: card.dataset.id, code: f('code') ? f('code').value : '', name: f('name').value, address: f('address').value,
      phone: f('phone').value, hours: f('hours').value, lat: f('lat').value, lng: f('lng').value,
      is_main: f('is_main') && f('is_main').checked ? '1' : '0', active: f('active') ? (f('active').checked ? '1' : '0') : '1',
    });
    if (j.success) { showToast('Sucursal guardada.'); sucLoad(); } else showToast(j.error || 'No se pudo guardar.', true);
  }

  if (a === 'del' && confirm('¿Eliminar esta sucursal? Esto no se puede deshacer.')) {
    const j = await sucApi({ action: 'delete', id: card.dataset.id });
    if (j.success) { showToast('Sucursal eliminada.'); sucLoad(); } else showToast(j.error || 'No se pudo eliminar.', true);
  }
});

if (can('branches.view')) sucLoad();


// ===== Modo solo lectura según el rol =====
// Los permisos reales se validan en el servidor; esto solo evita mostrar controles que no sirven.
(function () {
  const editMap = {
    'panel-contacto': 'contact.edit', 'panel-galeria': 'gallery.edit', 'panel-empleo': 'candidates.edit',
    'panel-vacantes': 'vacancies.edit', 'panel-sucursales': 'branches.edit',
  };
  function lock(panel) {
    panel.querySelectorAll('input,textarea,select,button.btn-save,.btn-save').forEach(el => {
      if (el.closest('.no-lock')) return;
      if (el.matches('[type=search],.suc-filter,#sucFilter')) return;
      el.disabled = true;
    });
    panel.querySelectorAll('.upload-tile').forEach(t => t.style.pointerEvents = 'none');
    if (panel.id === 'panel-vacantes') { const f = document.getElementById('formNuevaVacante'); if (f) f.hidden = true; }
    if (panel.id === 'panel-sucursales') {
      const b = document.getElementById('btnNuevaSucursal'); if (b) b.hidden = true;
      panel.querySelectorAll('button[data-a="save"]').forEach(x => x.hidden = true);
    }
    if (panel.id === 'panel-vacantes') panel.querySelectorAll('button').forEach(x => { if (!x.classList.contains('btn-refresh')) x.hidden = true; });
  }
  Object.keys(editMap).forEach(id => {
    if (can(editMap[id])) return;
    const p = document.getElementById(id);
    if (!p) return;
    const note = document.createElement('div');
    note.className = 'readonly-note';
    note.textContent = 'Tu rol solo puede consultar esta sección.';
    const head = p.querySelector('.panel-head');
    if (head) head.after(note);
    lock(p);
    new MutationObserver(() => lock(p)).observe(p, { childList: true, subtree: true });
  });
  // Sucursales: ocultar eliminar si no hay permiso
  if (!can('branches.delete')) {
    const p = document.getElementById('panel-sucursales');
    if (p) new MutationObserver(() => p.querySelectorAll('button[data-a="del"]').forEach(x => x.hidden = true))
      .observe(p, { childList: true, subtree: true });
  }
})();

// ===== Usuarios =====
(function () {
  const grid = document.getElementById('usuariosGrid');
  if (!grid) return;
  let data = { users: [], assignable: [], branches: [], me: {} };
  let openId = null;
  const roleLabel = r => (D.roles[r] && D.roles[r].label) || r;

  const legend = document.getElementById('rolesLegendBody');
  legend.innerHTML = Object.keys(D.roles).sort((a, b) => D.roles[b].rank - D.roles[a].rank)
    .map(k => '<p><strong>' + escapeHtml(D.roles[k].label) + '.</strong> ' + escapeHtml(D.roles[k].desc) + '</p>').join('');

  async function api(body) {
    const r = await fetch('users-api.php', { method: 'POST', body: new URLSearchParams(body) });
    return r.json();
  }

  function card(u, isNew) {
    const roles = isNew ? data.assignable : (u.can_manage ? data.assignable : [u.role]);
    const editable = isNew || u.can_manage;
    const opts = roles.map(r => '<option value="' + r + '"' + (r === u.role ? ' selected' : '') + '>' + escapeHtml(roleLabel(r)) + '</option>').join('');
    const open = isNew || u.id === openId;
    const last = u.last_login ? 'Último acceso: ' + escapeHtml(u.last_login) : 'Aún no ha entrado';
    return '<article class="vacante-card user-card' + (u.active === false ? ' inactive' : '') + (open ? ' open' : '') + '" data-id="' + (isNew ? '' : escapeHtml(u.id)) + '">' +
      '<button type="button" class="user-sum" data-a="toggle"><span class="su-avatar">' + escapeHtml((u.name || u.username || '+').charAt(0).toUpperCase()) + '</span>' +
      '<span class="user-main"><strong>' + escapeHtml(isNew ? 'Nuevo usuario' : (u.name || u.username)) + (u.is_me ? ' <em>(tú)</em>' : '') + '</strong>' +
      '<small>' + (isNew ? 'Llena los datos y guarda' : escapeHtml(roleLabel(u.role)) + ' · ' + (u.active ? 'activo' : 'desactivado')) + '</small></span><span class="chev">▾</span></button>' +
      '<div class="user-body"><div class="user-grid">' +
      '<label>Nombre<input name="name" value="' + escapeHtml(u.name || '') + '"' + (editable ? '' : ' disabled') + '></label>' +
      '<label>Usuario<input name="username" value="' + escapeHtml(u.username || '') + '" ' + (isNew ? 'placeholder="ej. maria.rh"' : 'disabled') + ' autocomplete="off"></label>' +
      '<label>Correo<input name="email" type="email" value="' + escapeHtml(u.email || '') + '"' + (editable ? '' : ' disabled') + '></label>' +
      '<label>Rol<select name="role"' + (editable && !u.is_me ? '' : ' disabled') + '>' + opts + '</select><small class="role-desc">' + escapeHtml((D.roles[u.role] || {}).desc || '') + '</small></label>' +
      (editable ? '<label>' + (isNew ? 'Contraseña (mín. 8)' : 'Nueva contraseña (opcional)') + '<input name="password" type="password" autocomplete="new-password"></label>' : '') +
      '</div>' +
      (editable ? '<div class="user-checks"><label><input type="checkbox" name="notify"' + (u.notify ? ' checked' : '') + '> Avisarle por correo cuando llegue una solicitud de empleo</label>' +
        '<label><input type="checkbox" name="active"' + (u.active !== false ? ' checked' : '') + (u.is_me ? ' disabled' : '') + '> Usuario activo</label></div>' +
        '<div class="user-actions"><small>' + last + '</small><span>' +
        (!isNew && !u.is_me ? '<button type="button" class="btn-danger" data-a="del">Eliminar</button>' : '') +
        '<button type="button" class="btn-save" data-a="save">Guardar</button></span></div>' : '<div class="user-actions"><small>' + last + ' · Solo un rol superior puede modificarlo.</small></div>') +
      '</div></article>';
  }

  function render(newCard) {
    grid.innerHTML = (newCard ? card({ role: data.assignable[data.assignable.length - 1], active: true }, true) : '') + data.users.map(u => card(u, false)).join('');
  }

  async function load() {
    try {
      const j = await api({ action: 'list' });
      if (!j.success) { grid.innerHTML = '<p class="empleo-empty">' + escapeHtml(j.error || 'Sin acceso.') + '</p>'; return; }
      data = j; data.assignable = j.assignable.map(a => a.key); data.branches = j.branches || []; render(false);
    } catch (e) { showToast('No se pudo cargar la lista de usuarios.', true); }
  }

  grid.addEventListener('change', e => {
    if (e.target.name !== 'role') return;
    const s = e.target.parentNode.querySelector('.role-desc');
    if (s) s.textContent = (D.roles[e.target.value] || {}).desc || '';
  });

  document.getElementById('btnNuevoUsuario').addEventListener('click', () => render(true));

  grid.addEventListener('click', async e => {
    const b = e.target.closest('button[data-a]');
    if (!b) return;
    const c = b.closest('.user-card');
    const a = b.dataset.a;
    if (a === 'toggle') {
      const id = c.dataset.id;
      openId = (openId === id && c.classList.contains('open')) ? null : id;
      if (!id) { c.classList.toggle('open'); return; }
      grid.querySelectorAll('.user-card').forEach(x => x.classList.toggle('open', x.dataset.id === openId));
      return;
    }
    const f = n => c.querySelector('[name=' + n + ']');
    if (a === 'save') {
      const body = { action: 'save', id: c.dataset.id, name: f('name').value, username: f('username').value, email: f('email').value,
        role: f('role').value, branch_id: f('branch_id') ? f('branch_id').value : '', password: f('password') ? f('password').value : '',
        notify: f('notify').checked ? '1' : '0', active: f('active').checked ? '1' : '0' };
      b.disabled = true;
      const j = await api(body);
      b.disabled = false;
      if (j.success) { showToast('Usuario guardado.'); openId = j.user ? j.user.id : null; load(); } else showToast(j.error || 'No se pudo guardar.', true);
    }
    if (a === 'del' && confirm('¿Eliminar este usuario? Esto no se puede deshacer.')) {
      const j = await api({ action: 'delete', id: c.dataset.id });
      if (j.success) { showToast('Usuario eliminado.'); load(); } else showToast(j.error || 'No se pudo eliminar.', true);
    }
  });

  // Bitácora
  const btnAudit = document.getElementById('btnAudit'), box = document.getElementById('auditBox');
  if (!can('audit.view')) btnAudit.hidden = true;
  btnAudit.addEventListener('click', async () => {
    if (!box.hidden) { box.hidden = true; return; }
    const j = await api({ action: 'audit' });
    if (!j.success) { showToast(j.error || 'No se pudo leer la bitácora.', true); return; }
    box.innerHTML = j.entries.length ? '<table><thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead><tbody>' +
      j.entries.map(r => '<tr><td>' + escapeHtml(r.ts) + '</td><td>' + escapeHtml(r.user) + '</td><td>' + escapeHtml(r.action) + '</td><td>' + escapeHtml(r.detail || '') + '</td></tr>').join('') + '</tbody></table>'
      : '<p class="empleo-empty">Aún no hay actividad.</p>';
    box.hidden = false;
  });

  load();
})();

// ===== Tarjeta "Tu rol" en Ajustes =====
(function () {
  const p = document.getElementById('panel-ajustes');
  const r = D.roles[D.user.role];
  if (D.force) return;
  if (!p || !r) return;
  const box = document.createElement('div');
  box.className = 'my-role';
  box.innerHTML = '<span>Tu rol</span><strong>' + escapeHtml(r.label) + '</strong><p>' + escapeHtml(r.desc) + '</p>';
  const head = p.querySelector('.panel-head');
  head.after(box);
})();
