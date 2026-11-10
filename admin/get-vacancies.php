<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require __DIR__ . '/../lib/branches.php';
dicosa_require_perm('vacancies.view', true);

header('Content-Type: application/json; charset=utf-8');

$path = __DIR__ . '/../data/vacancies.json';
$vacancies = json_decode((string)@file_get_contents($path), true);
if (!is_array($vacancies)) {
    $vacancies = [];
}

$scope = dicosa_scope_branch(dicosa_current_user());
if ($scope !== null) $vacancies = array_values(array_filter($vacancies, fn($v) => ($v['branch_id'] ?? '') === $scope));

// Más recientes primero.
usort($vacancies, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

$bl = array_map(fn($b) => ['id' => $b['id'], 'name' => $b['name']], dicosa_branches_load());
if ($scope !== null) $bl = array_values(array_filter($bl, fn($b) => $b['id'] === $scope));
echo json_encode(['success' => true, 'vacancies' => $vacancies, 'branches' => $bl]);
