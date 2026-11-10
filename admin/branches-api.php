<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/../lib/branches.php';
dicosa_require_login(true);
header('Content-Type: application/json; charset=utf-8');

$list = dicosa_branches_load();
$me = dicosa_current_user();
$scope = dicosa_scope_branch($me);   // null = todas; texto = solo esa sucursal (gerente)

function out(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a); exit; }
function store(array $list): void {
    if (!dicosa_branches_save($list)) out(['success' => false, 'error' => 'No se pudo guardar. Revisa permisos de la carpeta data/.'], 500);
}

$action = (string)($_POST['action'] ?? 'list');
if ($action === 'list') {
    dicosa_require_perm('branches.view');
    $vis = $scope === null ? $list : array_values(array_filter($list, fn($b) => ($b['id'] ?? '') === $scope));
    out(['success' => true, 'branches' => $vis]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['success' => false, 'error' => 'Método no permitido.'], 405);

$id = trim((string)($_POST['id'] ?? ''));

if ($action === 'delete') {
    dicosa_require_perm('branches.delete');
    $target = null;
    foreach ($list as $b) if (($b['id'] ?? '') === $id) $target = $b;
    if (!$target) out(['success' => false, 'error' => 'No se encontró la sucursal.'], 404);
    foreach (dicosa_users_load() as $u) {
        if (($u['role'] ?? '') === 'manager' && ($u['branch_id'] ?? '') === $id)
            out(['success' => false, 'error' => 'Esta sucursal tiene gerente asignado (' . ($u['name'] ?: $u['username']) . '). Elimínalo o cámbialo de sucursal primero, en Usuarios.'], 422);
    }
    store(array_values(array_filter($list, fn($b) => ($b['id'] ?? '') !== $id)));
    // Sus vacantes quedan inactivas y sin sucursal para no publicar puestos de una sucursal que ya no existe.
    $vp = __DIR__ . '/../data/vacancies.json';
    $vac = json_decode((string)@file_get_contents($vp), true);
    $moved = 0;
    if (is_array($vac)) {
        foreach ($vac as &$v) if (($v['branch_id'] ?? '') === $id) { $v['branch_id'] = ''; $v['active'] = false; $moved++; }
        unset($v);
        if ($moved) file_put_contents($vp, json_encode($vac, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
    dicosa_audit('sucursal_eliminada', ($target['code'] ?? $id) . ($moved ? " ($moved vacantes desactivadas)" : ''));
    out(['success' => true]);
}
if ($action !== 'save') out(['success' => false, 'error' => 'Acción inválida.'], 422);

if ($id === '') dicosa_require_perm('branches.create'); else dicosa_require_perm('branches.edit');
if ($scope !== null && $id !== $scope) out(['success' => false, 'error' => 'Solo puedes editar tu propia sucursal.'], 403);

$name = trim((string)($_POST['name'] ?? ''));
if ($name === '' || strlen($name) > 120) out(['success' => false, 'error' => 'Escribe el nombre de la sucursal.'], 422);

$num = function (string $k, float $min, float $max) {
    $v = trim((string)($_POST[$k] ?? ''));
    if ($v === '') return null;
    if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) out(['success' => false, 'error' => 'Las coordenadas no son válidas.'], 422);
    return round((float)$v, 6);
};

$old = null;
foreach ($list as $x) if (($x['id'] ?? '') === $id && $id !== '') $old = $x;
if ($id !== '' && !$old) out(['success' => false, 'error' => 'No se encontró la sucursal.'], 404);

if ($old) {
    $code = $old['code'];   // la clave fija NUNCA cambia al editar
} else {
    $code = dicosa_slug((string)($_POST['code'] ?? ''));
    if (!preg_match('/^[a-z0-9-]{2,30}$/', $code)) out(['success' => false, 'error' => 'La clave fija debe tener de 2 a 30 letras o números (sin espacios ni acentos).'], 422);
    foreach ($list as $x) if (($x['code'] ?? '') === $code) out(['success' => false, 'error' => 'Ya existe una sucursal con esa clave fija.'], 422);
}

$b = [
    'id' => $old ? $old['id'] : 'b-' . bin2hex(random_bytes(5)),
    'code' => $code,
    'name' => $name,
    'address' => substr(trim((string)($_POST['address'] ?? '')), 0, 250),
    'phone' => substr(trim((string)($_POST['phone'] ?? '')), 0, 40),
    'hours' => substr(trim((string)($_POST['hours'] ?? '')), 0, 120),
    'lat' => $num('lat', -90, 90),
    'lng' => $num('lng', -180, 180),
    'is_main' => ($_POST['is_main'] ?? '0') === '1',
    'active' => ($_POST['active'] ?? '1') === '1',
];
if ($scope !== null) { // el gerente no cambia si es Central ni si se muestra en el sitio
    $b['is_main'] = !empty($old['is_main']);
    $b['active'] = !isset($old['active']) || !empty($old['active']);
}

if ($old) { foreach ($list as &$x) if (($x['id'] ?? '') === $b['id']) { $x = $b; break; } unset($x); }
else $list[] = $b;
store($list);
dicosa_audit($old ? 'sucursal_editada' : 'sucursal_creada', $code . ' — ' . $b['name']);
out(['success' => true, 'branch' => $b]);
