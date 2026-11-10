<?php
// users-api.php — administración de usuarios del panel y bitácora.
// Solo para roles con permiso "users.manage" (Programador y Dueño).
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/../lib/branches.php';
dicosa_require_perm('users.manage', true);
header('Content-Type: application/json; charset=utf-8');

$me = dicosa_current_user();
$roles = dicosa_roles();
$manageable = dicosa_manageable_roles($me['role']);

function out(array $a, int $c = 200): void { http_response_code($c); echo json_encode($a); exit; }
function fail(string $m, int $c = 422): void { out(['success' => false, 'error' => $m], $c); }

function active_devs(array $users, ?string $exceptId = null): int
{
    return count(array_filter($users, fn($u) => $u['role'] === 'dev' && !empty($u['active']) && $u['id'] !== $exceptId));
}

/** Solo un gerente por sucursal. */
function check_branch_free(array $users, string $branchId, ?string $exceptId): void
{
    if ($branchId === '') return;
    foreach ($users as $u) {
        if (($u['role'] ?? '') === 'manager' && ($u['branch_id'] ?? '') === $branchId && $u['id'] !== $exceptId)
            fail('Esa sucursal ya tiene gerente (' . ($u['name'] ?: $u['username']) . '). Solo puede haber uno por sucursal.');
    }
}

$action = (string)($_POST['action'] ?? 'list');
$users = dicosa_users_load();

if ($action === 'list') {
    $list = array_map(function ($u) use ($me, $manageable) {
        $p = dicosa_user_public($u);
        $p['is_me'] = $u['id'] === $me['id'];
        $p['can_manage'] = !$p['is_me'] && in_array($u['role'], $manageable, true);
        return $p;
    }, $users);
    usort($list, fn($a, $b) => (dicosa_roles()[$b['role']]['rank'] <=> dicosa_roles()[$a['role']]['rank']) ?: strcmp($a['name'], $b['name']));
    $assignable = [];
    foreach ($manageable as $k) $assignable[] = ['key' => $k, 'label' => $roles[$k]['label'], 'desc' => $roles[$k]['desc']];
    $branches = array_map(function ($b) use ($users) {
        $mgr = null;
        foreach ($users as $u) if (($u['role'] ?? '') === 'manager' && ($u['branch_id'] ?? '') === $b['id']) $mgr = $u['id'];
        return ['id' => $b['id'], 'name' => $b['name'], 'code' => $b['code'] ?? '', 'manager_id' => $mgr];
    }, dicosa_branches_load());
    out(['success' => true, 'users' => $list, 'assignable' => $assignable, 'branches' => $branches, 'me' => $me['id']]);
}

if ($action === 'audit') {
    dicosa_require_perm('audit.view', true);
    out(['success' => true, 'entries' => dicosa_audit_read(200)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.', 405);

$id = trim((string)($_POST['id'] ?? ''));

// ------------------------------------------------------------------ eliminar
if ($action === 'delete') {
    $target = null;
    foreach ($users as $u) if ($u['id'] === $id) $target = $u;
    if (!$target) fail('No se encontró el usuario.', 404);
    if ($target['id'] === $me['id']) fail('No puedes eliminar tu propio usuario.');
    if (!in_array($target['role'], $manageable, true)) fail('No tienes permiso para eliminar a este usuario.', 403);
    if ($target['role'] === 'dev' && active_devs($users, $target['id']) < 1) fail('Debe quedar al menos un Programador activo.');
    $users = array_values(array_filter($users, fn($u) => $u['id'] !== $id));
    if (!dicosa_users_save($users)) fail('No se pudo guardar. Revisa permisos de la carpeta data/.', 500);
    dicosa_audit('usuario_eliminado', $target['username'] . ' (' . $target['role'] . ')');
    out(['success' => true]);
}

if ($action !== 'save') fail('Acción inválida.');

// ------------------------------------------------------------ crear / editar
$name = trim((string)($_POST['name'] ?? ''));
$username = strtolower(trim((string)($_POST['username'] ?? '')));
$email = trim((string)($_POST['email'] ?? ''));
$role = trim((string)($_POST['role'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$active = ($_POST['active'] ?? '1') === '1';
$notify = ($_POST['notify'] ?? '0') === '1';

if ($name === '' || strlen($name) > 80) fail('Escribe el nombre de la persona.');
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('El correo no es válido.');
if ($notify && $email === '') fail('Para recibir avisos hace falta un correo.');
if ($password !== '' && strlen($password) < 8) fail('La contraseña debe tener al menos 8 caracteres.');

$branchId = trim((string)($_POST['branch_id'] ?? ''));
if ($role === 'manager') {
    if ($branchId === '' || !dicosa_branch_find($branchId)) fail('Elige la sucursal que va a administrar este gerente.');
} else {
    $branchId = '';
}

$idx = null;
foreach ($users as $i => $u) if ($u['id'] === $id && $id !== '') $idx = $i;

if ($idx === null) { // ---- crear
    if (!preg_match('/^[a-z0-9._-]{3,30}$/', $username)) fail('El usuario debe tener de 3 a 30 letras minúsculas, números, punto, guion o guion bajo.');
    if (dicosa_user_find_by('username', $username)) fail('Ese usuario ya existe.');
    if ($password === '') fail('Escribe una contraseña (mínimo 8 caracteres).');
    if (!in_array($role, $manageable, true)) fail('No puedes crear usuarios con ese rol.', 403);
    check_branch_free($users, $branchId, null);
    $new = [
        'id' => 'u-' . bin2hex(random_bytes(5)), 'username' => $username, 'name' => $name, 'email' => $email,
        'role' => $role, 'branch_id' => $branchId, 'active' => $active, 'notify' => $notify,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'must_change' => true, 'created_at' => date('Y-m-d H:i:s'), 'last_login' => null,
    ];
    $users[] = $new;
    if (!dicosa_users_save($users)) fail('No se pudo guardar. Revisa permisos de la carpeta data/.', 500);
    dicosa_audit('usuario_creado', $username . ' (' . $role . ')');
    out(['success' => true, 'user' => dicosa_user_public($new)]);
}

// ---- editar
$target = $users[$idx];
$isMe = $target['id'] === $me['id'];
if (!$isMe && !in_array($target['role'], $manageable, true)) fail('No tienes permiso para editar a este usuario.', 403);

if ($isMe) { // uno mismo: solo nombre, correo y avisos (el rol y el estado no se pueden cambiar a sí mismo)
    $role = $target['role']; $active = true;
} else {
    if (!in_array($role, $manageable, true)) fail('No puedes asignar ese rol.', 403);
    check_branch_free($users, $branchId, $target['id']);
    if ($target['role'] === 'dev' && ($role !== 'dev' || !$active) && active_devs($users, $target['id']) < 1) fail('Debe quedar al menos un Programador activo.');
}

$users[$idx]['name'] = $name;
$users[$idx]['email'] = $email;
$users[$idx]['role'] = $role;
$users[$idx]['branch_id'] = $branchId;
$users[$idx]['active'] = $active;
$users[$idx]['notify'] = $notify;
$changes = [];
if ($role !== $target['role']) $changes[] = 'rol ' . $target['role'] . '→' . $role;
if ($branchId !== (string)($target['branch_id'] ?? '')) $changes[] = 'sucursal ' . ($branchId ?: 'ninguna');
if ($active !== !empty($target['active'])) $changes[] = $active ? 'activado' : 'desactivado';
if ($password !== '') { $users[$idx]['password_hash'] = password_hash($password, PASSWORD_DEFAULT); $users[$idx]['must_change'] = true; $changes[] = 'contraseña restablecida'; }

if (!dicosa_users_save($users)) fail('No se pudo guardar. Revisa permisos de la carpeta data/.', 500);
dicosa_audit('usuario_editado', $target['username'] . ($changes ? ' — ' . implode(', ', $changes) : ''));
out(['success' => true, 'user' => dicosa_user_public($users[$idx])]);
