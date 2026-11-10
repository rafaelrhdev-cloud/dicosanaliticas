<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('candidates.view', false);

$id = trim((string)($_GET['id'] ?? ''));
dicosa_audit('cv_descargado', $id);
if ($id === '') {
    http_response_code(400);
    echo 'Falta el identificador del candidato.';
    exit;
}

$path = __DIR__ . '/../data/applications.json';
$applications = json_decode((string)@file_get_contents($path), true);
if (!is_array($applications)) {
    $applications = [];
}

$application = null;
foreach ($applications as $app) {
    if (($app['id'] ?? '') === $id) {
        $application = $app;
        break;
    }
}

if ($application === null) {
    http_response_code(404);
    echo 'No se encontró ese candidato.';
    exit;
}

$scope = dicosa_scope_branch(dicosa_current_user());
if ($scope !== null && ($scope === '' || ($application['branch_id'] ?? '') !== $scope)) {
    http_response_code(403);
    echo 'Este candidato no es de tu sucursal.';
    exit;
}

$filePath = __DIR__ . '/../data/cv-uploads/' . basename((string)$application['cv_filename']);
if (!is_file($filePath)) {
    http_response_code(404);
    echo 'El archivo ya no está disponible.';
    exit;
}

$ext = strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION));
$mimeMap = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

$downloadName = preg_replace('/[^A-Za-z0-9_\-\. ]/', '', $application['name'] ?? 'CV') . '.' . $ext;

// Los PDF los abrimos directo en el navegador (todos lo saben mostrar).
// Los Word (.doc/.docx) los forzamos a descarga: el navegador no puede
// mostrarlos por su cuenta y, si se lo intentamos "entregar" para abrir
// inline, es el sistema operativo el que termina preguntando con qué
// programa abrirlo — una descarga normal evita esa ventana confusa.
$disposition = $ext === 'pdf' ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
