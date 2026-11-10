<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_login(true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$current = (string)($_POST['current_password'] ?? '');
$new = (string)($_POST['new_password'] ?? '');
$confirm = (string)($_POST['confirm_password'] ?? '');

if (strlen($new) < 8) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres.']);
    exit;
}
if ($new === DICOSA_DEFAULT_PASSWORD || $new === $current) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Elige una contraseña distinta a la actual y a la de fábrica.']);
    exit;
}
if ($new !== $confirm) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Las contraseñas no coinciden.']);
    exit;
}

$me = dicosa_current_user();
if (!password_verify($current, (string)$me['password_hash'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'La contraseña actual no es correcta.']);
    exit;
}

$users = dicosa_users_load();
foreach ($users as &$u) {
    if ($u['id'] === $me['id']) { $u['password_hash'] = password_hash($new, PASSWORD_DEFAULT); $u['must_change'] = false; }
}
unset($u);

if (!dicosa_users_save($users)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar. Revisa permisos de la carpeta data/.']);
    exit;
}

dicosa_audit('cambio_contrasena', 'Cambió su propia contraseña');
echo json_encode(['success' => true]);
