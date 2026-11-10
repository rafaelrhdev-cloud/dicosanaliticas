<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('contact.edit', true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$dataPath = __DIR__ . '/../data/site-data.json';

$fields = [
    'phone_display', 'phone_tel', 'whatsapp_number', 'email',
    'address_line1', 'address_line2', 'maps_link', 'maps_embed_query',
    'hours_weekday', 'hours_sunday',
];

$current = json_decode((string)file_get_contents($dataPath), true) ?: [];

foreach ($fields as $field) {
    if (isset($_POST[$field])) {
        $current[$field] = trim((string)$_POST[$field]);
    }
}

// Validaciones mínimas de sentido común.
if ($current['email'] !== '' && !filter_var($current['email'], FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'El correo no es válido.']);
    exit;
}
if (!preg_match('/^\d{7,15}$/', $current['phone_tel'] ?? '')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'El teléfono (solo números) no es válido.']);
    exit;
}
if (!preg_match('/^\d{10,15}$/', $current['whatsapp_number'] ?? '')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'El número de WhatsApp no es válido.']);
    exit;
}

$current['updated_at'] = date('Y-m-d H:i:s');

$ok = file_put_contents(
    $dataPath,
    json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo escribir el archivo. Revisa permisos de la carpeta data/.']);
    exit;
}

dicosa_audit('contacto_editado', 'Actualizó los datos de contacto');
echo json_encode(['success' => true, 'data' => $current]);
