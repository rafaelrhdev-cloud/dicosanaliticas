<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
dicosa_require_perm('config.tech', true);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/mail-notify.php';

$configPath = __DIR__ . '/../data/mail-config.php';
$config = is_file($configPath) ? include $configPath : null;

if (!is_array($config) || empty($config['smtp_pass']) || $config['smtp_pass'] === 'PON_AQUI_TU_CONTRASEÑA_DE_APLICACION') {
    echo json_encode([
        'success' => false,
        'error' => 'Todavía no configuras la contraseña de aplicación en data/mail-config.php.',
    ]);
    exit;
}

$testApplication = [
    'name' => 'Candidato de prueba',
    'email' => 'prueba@ejemplo.com',
    'phone' => '000 000 0000',
    'position' => 'Este es un correo de prueba',
    'message' => 'Si estás leyendo esto, la notificación por correo del sitio DICOSA está funcionando correctamente.',
];

$sent = dicosa_notify_new_application($testApplication);

if ($sent) {
    echo json_encode(['success' => true]);
} else {
    $logPath = __DIR__ . '/../data/mail-errors.log';
    $lastError = '';
    if (is_file($logPath)) {
        $lines = file($logPath);
        $lastError = $lines ? trim(end($lines)) : '';
    }
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo enviar. ' . ($lastError !== '' ? "Detalle: {$lastError}" : 'Revisa la configuración SMTP.'),
    ]);
}
