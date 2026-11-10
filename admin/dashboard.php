<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_login();
$me = dicosa_current_user();
$roles = dicosa_roles();
$panelView = [
    'panel-analitica' => 'analytics.view',
    'panel-zonas' => 'analytics.view',
    'panel-contacto' => 'contact.view', 'panel-mensajes' => 'contact.view', 'panel-galeria' => 'gallery.view', 'panel-empleo' => 'candidates.view',
    'panel-vacantes' => 'vacancies.view', 'panel-sucursales' => 'branches.view', 'panel-usuarios' => 'users.manage',
    'panel-ajustes' => null,
];
$panelEdit = [
    'panel-contacto' => 'contact.edit', 'panel-mensajes' => 'contact.edit', 'panel-galeria' => 'gallery.edit', 'panel-empleo' => 'candidates.edit',
    'panel-vacantes' => 'vacancies.edit', 'panel-sucursales' => 'branches.edit',
];
$firstPanel = 'panel-ajustes';
foreach ($panelView as $pid => $perm) { if ($perm === null || dicosa_can($perm)) { $firstPanel = $pid; break; } }
function panel_cls(string $pid): string
{
    global $panelView, $panelEdit, $firstPanel;
    $cls = '';
    if ($pid === $firstPanel) $cls .= ' active';
    if (isset($panelEdit[$pid]) && !dicosa_can($panelEdit[$pid])) $cls .= ' readonly';
    if ($panelView[$pid] !== null && !dicosa_can($panelView[$pid])) $cls .= ' no-access';
    return $cls;
}

// Marca este navegador como "del equipo" (hasta cerrarlo) para que sus visitas al sitio no cuenten en la analítica.
// Quien quiera probar el sitio y ver sus propias acciones lo desactiva en Analítica > Configuración y privacidad.
if (!headers_sent()) {
    $cookieOpts = ['path' => '/', 'secure' => dicosa_is_https(), 'httponly' => true, 'samesite' => 'Lax'];
    if (empty($_COOKIE['dicosa_staff_off'])) setcookie('dicosa_staff', '1', $cookieOpts + ['expires' => 0]);
    elseif (!empty($_COOKIE['dicosa_staff'])) setcookie('dicosa_staff', '', $cookieOpts + ['expires' => time() - 3600]);
}

$dataPath = __DIR__ . '/../data/site-data.json';
$data = json_decode((string)file_get_contents($dataPath), true) ?: [];

function v(array $data, string $key): string
{
    return htmlspecialchars($data[$key] ?? '', ENT_QUOTES, 'UTF-8');
}

// Revisa qué fotos de la galería ya existen, para mostrar su vista previa.
$galleryLabels = [
    1 => 'Exhibidor principal',
    2 => 'Área de cortes',
    3 => 'Cadena de frío',
    4 => 'Fachada',
    5 => 'Nuestro equipo',
    6 => 'Cortes del día',
];
$galleryDir = __DIR__ . '/../assets/gallery/';
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Panel DICOSA</title>
<link rel="icon" type="image/png" href="../assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
<link rel="stylesheet" href="analytics.css">
<link rel="stylesheet" href="zonas.css">
<link rel="stylesheet" href="mensajes.css">
</head>
<body>

<div class="app-shell">

  <!-- Barra superior (solo teléfono) -->
  <header class="m-topbar">
    <button type="button" class="m-burger" id="mBurger" aria-label="Abrir menú" aria-expanded="false">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <img src="../assets/logo.png" alt="" class="m-logo">
    <strong id="mTitle">Panel DICOSA</strong>
  </header>
  <div class="m-scrim" id="mScrim"></div>

  <!-- ===== SIDEBAR ===== -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <img src="../assets/logo.png" alt="DICOSA">
      <div><strong>DICOSA</strong><span>Panel de control</span></div>
    </div>

    <nav class="sidebar-nav">
      <?php if (dicosa_can('analytics.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-analitica' ? ' active' : '' ?>" data-panel="panel-analitica">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 15l4-5 3 3 5-7"/></svg>
        Analítica
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('analytics.view') && dicosa_scope_branch($me) === null): ?>
      <button class="side-link" data-panel="panel-zonas">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.6"/></svg>
        Zonas
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('contact.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-contacto' ? ' active' : '' ?>" data-panel="panel-contacto">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        Contacto
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('contact.view')): ?>
      <button class="side-link" data-panel="panel-mensajes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 20.5l1.6-5.3A8.4 8.4 0 1 1 21 11.5z"/></svg>
        Mensajes
        <span class="side-badge" id="sideBadgeMensajes" hidden>0</span>
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('gallery.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-galeria' ? ' active' : '' ?>" data-panel="panel-galeria">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
        Galería
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('candidates.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-empleo' ? ' active' : '' ?>" data-panel="panel-empleo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 7h-9M14 17H5M17 4h4v4M7 20H3v-4"/><path d="M20 7l-7 7-4-4-5 5"/></svg>
        Bolsa de trabajo
        <span class="side-badge" id="sideBadgeEmpleo" hidden>0</span>
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('vacancies.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-vacantes' ? ' active' : '' ?>" data-panel="panel-vacantes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6h-4V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2H4a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2z"/><path d="M8 6V4h8v2"/></svg>
        Vacantes
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('branches.view')): ?>
      <button class="side-link<?= $firstPanel === 'panel-sucursales' ? ' active' : '' ?>" data-panel="panel-sucursales">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        Sucursales
      </button>
      <?php endif; ?>
      <?php if (dicosa_can('users.manage')): ?>
      <button class="side-link<?= $firstPanel === 'panel-usuarios' ? ' active' : '' ?>" data-panel="panel-usuarios">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        Usuarios
      </button>
      <?php endif; ?>
      <button class="side-link<?= $firstPanel === 'panel-ajustes' ? ' active' : '' ?>" data-panel="panel-ajustes">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        Ajustes
      </button>
    </nav>

    <div class="sidebar-user">
      <span class="su-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($me['name'] ?: $me['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
      <div><strong><?= htmlspecialchars($me['name'] ?: $me['username'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($roles[$me['role']]['label'], ENT_QUOTES, 'UTF-8') ?></small></div>
    </div>

    <a href="../index.html" target="_blank" class="sidebar-view-site">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/></svg>
      Ver página pública
    </a>
    <a href="logout.php" class="sidebar-logout">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
      Cerrar sesión
    </a>
  </aside>

  <!-- ===== MAIN ===== -->
  <main class="main">

    <!-- PANEL: ANALÍTICA -->
    <?php if (dicosa_can('analytics.view')): ?>
    <section class="panel<?= panel_cls('panel-analitica') ?>" id="panel-analitica"><div id="anRoot"></div></section>
    <section class="panel<?= panel_cls('panel-mensajes') ?>" id="panel-mensajes"><div id="msRoot"></div></section>
    <?php if (dicosa_can('analytics.view') && dicosa_scope_branch($me) === null): ?>
    <section class="panel" id="panel-zonas"><div id="znRoot"></div></section>
    <?php endif; ?>
    <?php endif; ?>

    <!-- PANEL: CONTACTO -->
    <section class="panel<?= panel_cls('panel-contacto') ?>" id="panel-contacto">
      <header class="panel-head">
        <div>
          <h1>Información de contacto</h1>
          <p>Estos datos se actualizan en todo el sitio: encabezado, pie de página y la sección de ubicación.</p>
        </div>
      </header>

      <form id="formContacto" class="card-form">
        <div class="form-grid">
          <label>
            <span>Teléfono (como se muestra)</span>
            <input type="text" name="phone_display" value="<?= v($data, 'phone_display') ?>" placeholder="01 (427) 264-0077" required>
          </label>
          <label>
            <span>Teléfono (solo números, para el botón de llamar)</span>
            <input type="text" name="phone_tel" value="<?= v($data, 'phone_tel') ?>" placeholder="4272640077" required>
          </label>
          <label>
            <span>WhatsApp (con código de país, sin +)</span>
            <input type="text" name="whatsapp_number" value="<?= v($data, 'whatsapp_number') ?>" placeholder="524272640077" required>
          </label>
          <label>
            <span>Correo</span>
            <input type="email" name="email" value="<?= v($data, 'email') ?>" placeholder="ventas@midicosa.com.mx" required>
          </label>
          <label class="span-2">
            <span>Dirección — línea 1</span>
            <input type="text" name="address_line1" value="<?= v($data, 'address_line1') ?>" required>
          </label>
          <label class="span-2">
            <span>Dirección — línea 2</span>
            <input type="text" name="address_line2" value="<?= v($data, 'address_line2') ?>" required>
          </label>
          <label class="span-2">
            <span>Enlace de Google Maps (botón "Cómo llegar")</span>
            <input type="url" name="maps_link" value="<?= v($data, 'maps_link') ?>" placeholder="https://goo.gl/maps/...">
          </label>
          <label class="span-2">
            <span>Dirección para el mapa embebido (búsqueda de Google Maps)</span>
            <input type="text" name="maps_embed_query" value="<?= v($data, 'maps_embed_query') ?>">
          </label>
          <label>
            <span>Horario — Lunes a sábado</span>
            <input type="text" name="hours_weekday" value="<?= v($data, 'hours_weekday') ?>">
          </label>
          <label>
            <span>Horario — Domingo</span>
            <input type="text" name="hours_sunday" value="<?= v($data, 'hours_sunday') ?>">
          </label>
        </div>

        <div class="form-actions">
          <span class="save-status" id="contactoStatus"></span>
          <button type="submit" class="btn-save">Guardar cambios</button>
        </div>
      </form>
    </section>

    <!-- PANEL: GALERIA -->
    <section class="panel<?= panel_cls('panel-galeria') ?>" id="panel-galeria">
      <header class="panel-head">
        <div>
          <h1>Galería del establecimiento</h1>
          <p>Haz clic en cualquier recuadro para subir o reemplazar esa foto. Se actualiza al instante en la página pública.</p>
        </div>
      </header>

      <div class="gallery-admin-grid">
        <?php foreach ($galleryLabels as $slot => $label):
            $file = $galleryDir . "foto-{$slot}.jpg";
            $exists = is_file($file);
            $src = $exists ? "../assets/gallery/foto-{$slot}.jpg?t=" . filemtime($file) : '';
        ?>
        <div class="upload-tile" data-slot="<?= $slot ?>">
          <div class="upload-preview <?= $exists ? '' : 'empty' ?>" id="preview-<?= $slot ?>" style="<?= $exists ? "background-image:url('{$src}')" : '' ?>">
            <?php if (!$exists): ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="M21 15l-5-5L5 19"/></svg>
            <?php endif; ?>
          </div>
          <div class="upload-info">
            <strong><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong>
            <span class="upload-hint" id="hint-<?= $slot ?>"><?= $exists ? 'Foto cargada' : 'Sin foto — se muestra un ícono' ?></span>
          </div>
          <label class="btn-upload">
            Subir foto
            <input type="file" accept="image/jpeg,image/png,image/webp" class="upload-input" data-slot="<?= $slot ?>" hidden>
          </label>
          <div class="upload-progress" id="progress-<?= $slot ?>"></div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- PANEL: BOLSA DE TRABAJO -->
    <section class="panel<?= panel_cls('panel-empleo') ?>" id="panel-empleo">
      <header class="panel-head panel-head-row">
        <div>
          <h1>Bolsa de trabajo</h1>
          <p>Candidatos que han enviado su CV desde la página pública.</p>
        </div>
        <button class="btn-refresh" id="btnRefreshEmpleo">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
          Actualizar
        </button>
      </header>

      <div class="empleo-tabs" id="empleoTabs">
        <button class="empleo-tab active" data-filter="todos">Todos <span class="tab-count" id="count-todos">0</span></button>
        <button class="empleo-tab" data-filter="recibido">Recibido <span class="tab-count" id="count-recibido">0</span></button>
        <button class="empleo-tab" data-filter="agendado">Agendado <span class="tab-count" id="count-agendado">0</span></button>
        <button class="empleo-tab" data-filter="rechazado">Rechazado <span class="tab-count" id="count-rechazado">0</span></button>
        <button class="empleo-tab" data-filter="contratado">Contratado <span class="tab-count" id="count-contratado">0</span></button>
      </div>

      <div class="candidatos-grid" id="candidatosGrid">
        <p class="empleo-empty" id="empleoEmptyMsg">Cargando candidatos...</p>
      </div>
    </section>

    <!-- PANEL: VACANTES -->
    <section class="panel<?= panel_cls('panel-vacantes') ?>" id="panel-vacantes">
      <header class="panel-head">
        <div>
          <h1>Vacantes</h1>
          <p>Controla qué puestos aparecen como disponibles en la página pública. Solo las vacantes activas se muestran a los candidatos.</p>
        </div>
      </header>

      <form id="formNuevaVacante" class="card-form card-narrow" style="margin-bottom:30px;">
        <div class="form-grid">
          <label class="span-2">
            <span>Nombre del puesto</span>
            <input type="text" name="title" placeholder="Ej. Cajero/a" required>
          </label>
          <label class="span-2" id="vacBranchWrap">
            <span>Sucursal</span>
            <select name="branch_id"><option value="">General (todas las sucursales)</option></select>
          </label>
          <label class="span-2">
            <span>Descripción (opcional)</span>
            <textarea name="description" rows="2" placeholder="Horario, requisitos, etc."></textarea>
          </label>
        </div>
        <div class="form-actions">
          <span class="save-status" id="vacanteNuevaStatus"></span>
          <button type="submit" class="btn-save">+ Agregar vacante</button>
        </div>
      </form>

      <div class="vacantes-grid" id="vacantesGrid">
        <p class="empleo-empty" id="vacantesEmptyMsg">Cargando vacantes...</p>
      </div>
    </section>

    <!-- PANEL: SUCURSALES -->
    <section class="panel<?= panel_cls('panel-sucursales') ?>" id="panel-sucursales">
      <header class="panel-head panel-head-row">
        <div>
          <h1>Sucursales</h1>
          <p>Agrega, edita o desactiva sucursales. Las activas aparecen en la página pública; las que tienen coordenadas entran al cálculo de "la más cercana".</p>
        </div>
        <button type="button" class="btn-refresh" id="btnNuevaSucursal">+ Agregar sucursal</button>
      </header>
      <div class="vacantes-grid" id="sucursalesGrid"><p class="empleo-empty">Cargando...</p></div>
    </section>

    <!-- PANEL: USUARIOS -->
    <?php if (dicosa_can('users.manage')): ?>
    <section class="panel<?= panel_cls('panel-usuarios') ?>" id="panel-usuarios">
      <header class="panel-head panel-head-row">
        <div>
          <h1>Usuarios</h1>
          <p>Crea y administra a las personas que entran a este panel. Cada rol tiene permisos distintos.</p>
        </div>
        <button type="button" class="btn-refresh" id="btnNuevoUsuario">+ Nuevo usuario</button>
      </header>
      <details class="roles-legend" id="rolesLegend"><summary>¿Qué puede hacer cada rol?</summary><div id="rolesLegendBody"></div></details>
      <div class="vacantes-grid" id="usuariosGrid"><p class="empleo-empty">Cargando...</p></div>

      <div class="audit-wrap">
        <button type="button" class="btn-refresh" id="btnAudit">Ver actividad reciente</button>
        <div class="audit-box" id="auditBox" hidden></div>
      </div>
    </section>
    <?php endif; ?>

    <!-- PANEL: AJUSTES -->
    <section class="panel<?= panel_cls('panel-ajustes') ?>" id="panel-ajustes">
      <?php if (dicosa_must_change()): ?>
      <div class="force-note"><strong>Antes de continuar, cambia tu contraseña.</strong> La que tienes es provisional (de fábrica o asignada por otra persona). Al guardar la nueva se desbloquea el resto del panel.</div>
      <?php endif; ?>
      <header class="panel-head">
        <div>
          <h1>Ajustes de la cuenta</h1>
          <p>Cambia la contraseña con la que entras a este panel.</p>
        </div>
      </header>

      <form id="formPassword" class="card-form card-narrow">
        <div class="form-grid">
          <label class="span-2">
            <span>Contraseña actual</span>
            <input type="password" name="current_password" autocomplete="current-password" required>
          </label>
          <label class="span-2">
            <span>Nueva contraseña (mínimo 8 caracteres)</span>
            <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
          </label>
          <label class="span-2">
            <span>Confirmar nueva contraseña</span>
            <input type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
          </label>
        </div>
        <div class="form-actions">
          <span class="save-status" id="passwordStatus"></span>
          <button type="submit" class="btn-save">Actualizar contraseña</button>
        </div>
      </form>

      <?php if (dicosa_can('config.tech')): ?>
      <div class="card-form card-narrow" style="margin-top:24px;">
        <h3 style="font-family:var(--ff-display);font-size:1.1rem;margin-bottom:8px;">Notificaciones por correo</h3>
        <p style="color:var(--carbon-70);font-size:.86rem;margin-bottom:20px;">
          Cuando llega una solicitud de empleo nueva, el sitio intenta avisar por correo automáticamente.
          Usa este botón para comprobar que quedó bien configurado, sin tener que mandar una solicitud de prueba.
        </p>
        <div class="form-actions" style="justify-content:flex-start;">
          <button type="button" class="btn-save" id="btnTestEmail">Enviar correo de prueba</button>
          <span class="save-status" id="testEmailStatus"></span>
        </div>
      </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<div class="toast" id="toast"></div>

<script>
window.DICOSA = <?= json_encode([
    'user' => ['id' => $me['id'], 'name' => $me['name'], 'username' => $me['username'], 'role' => $me['role'], 'branch_id' => (string)($me['branch_id'] ?? '')],
    'perms' => dicosa_must_change() ? [] : $roles[$me['role']]['perms'],
    'force' => dicosa_must_change(),
    'csrf' => dicosa_csrf_token(),
    'roles' => array_map(fn($r) => ['label' => $r['label'], 'desc' => $r['desc'], 'rank' => $r['rank']], $roles),
    'first' => $firstPanel,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="admin.js"></script>
<script src="analytics.js"></script>
<script src="zonas.js"></script>
<script src="mensajes.js"></script>
</body>
</html>
