<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('vacancies.delete', true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

$id = trim((string)($_POST['id'] ?? ''));
if ($id === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Falta el identificador de la vacante.']);
    exit;
}

$path = __DIR__ . '/../data/vacancies.json';
$vacancies = json_decode((string)@file_get_contents($path), true);
if (!is_array($vacancies)) {
    $vacancies = [];
}

$filtered = array_values(array_filter($vacancies, fn($v) => ($v['id'] ?? '') !== $id));

if (count($filtered) === count($vacancies)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'No se encontró esa vacante.']);
    exit;
}

$ok = file_put_contents(
    $path,
    json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    LOCK_EX
);

if ($ok === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo eliminar la vacante.']);
    exit;
}

dicosa_audit('vacante_eliminada', $id);
echo json_encode(['success' => true]);
