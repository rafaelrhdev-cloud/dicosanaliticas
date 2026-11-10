<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
if (dicosa_current_user()) dicosa_audit('logout', 'Cerró sesión');
setcookie('dicosa_staff', '', ['expires' => time() - 3600, 'path' => '/']);
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
