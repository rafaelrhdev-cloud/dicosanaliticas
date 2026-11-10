<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('candidates.edit', true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$id = trim((string)($_POST['id'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));
$notes = array_key_exists('notes', $_POST) ? (string)$_POST['notes'] : null;

$allowedStatus = ['recibido', 'agendado', 'rechazado', 'contratado'];
if ($status !== '' && !in_array($status, $allowedStatus, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Estado inválido.']);
    exit;
}
if ($id === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Falta el identificador del candidato.']);
    exit;
}

$path = __DIR__ . '/../data/applications.json';
$applications = json_decode((string)@file_get_contents($path), true);
if (!is_array($applications)) {
    $applications = [];
}

$found = false;
foreach ($applications as &$app) {
    if (($app['id'] ?? '') === $id) {
        if ($status !== '') {
            $app['status'] = $status;
        }
        if ($notes !== null) {
            $app['notes'] = substr($notes, 0, 2000);
        }
        $found = true;
        break;
    }
}
unset($app);

if (!$found) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No se encontró ese candidato.']);
    exit;
}

$ok = file_put_contents(
    $path,
    json_encode($applications, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo guardar el cambio.']);
    exit;
}

dicosa_audit('candidato_estatus', $id . ' → ' . $status);
echo json_encode(['success' => true]);
