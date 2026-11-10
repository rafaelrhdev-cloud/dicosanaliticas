<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('candidates.view', true);

header('Content-Type: application/json; charset=utf-8');

$path = __DIR__ . '/../data/applications.json';
$applications = json_decode((string)@file_get_contents($path), true);
if (!is_array($applications)) {
    $applications = [];
}

$scope = dicosa_scope_branch(dicosa_current_user());   // gerente: solo su sucursal
if ($scope !== null) $applications = array_values(array_filter($applications, fn($a) => $scope !== '' && ($a['branch_id'] ?? '') === $scope));

// Más recientes primero.
usort($applications, fn($a, $b) => strcmp($b['applied_at'] ?? '', $a['applied_at'] ?? ''));

echo json_encode(['success' => true, 'applications' => $applications]);
