<?php
declare(strict_types=1);

require_once __DIR__ . '/users.php';
require_once __DIR__ . '/../lib/phpmailer/Exception.php';
require_once __DIR__ . '/../lib/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../lib/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Manda un correo avisando que llegó una solicitud de empleo nueva.
 * Nunca lanza una excepción hacia afuera: si algo falla, regresa false
 * y anota el motivo en data/mail-errors.log, para que enviar el correo
 * nunca pueda tumbar el envío de la solicitud del candidato.
 */
function dicosa_notify_new_application(array $application): bool
{
    $configPath = __DIR__ . '/../data/mail-config.php';
    if (!is_file($configPath)) {
        return false;
    }
    $config = include $configPath;
    if (!is_array($config) || empty($config['smtp_pass']) || $config['smtp_pass'] === 'PON_AQUI_TU_CONTRASEÑA_DE_APLICACION') {
        // Todavía no se configuró el correo — no es un error, solo no está listo.
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$config['smtp_port'];
        $mail->CharSet = 'UTF-8';

        $mail->setFrom($config['from_email'], $config['from_name']);
        $sent = [];
        if (!empty($config['notify_to']) && filter_var($config['notify_to'], FILTER_VALIDATE_EMAIL)) {
            $mail->addAddress($config['notify_to'], $config['notify_to_name'] ?? '');
            $sent[strtolower($config['notify_to'])] = true;
        }
        // Usuarios del panel (por ejemplo Recursos humanos) que activaron "recibir aviso de nuevas solicitudes".
        foreach (dicosa_notify_recipients((string)($application['branch_id'] ?? '')) as $r) {
            if (isset($sent[strtolower($r['email'])])) continue;
            $mail->addAddress($r['email'], $r['name']);
            $sent[strtolower($r['email'])] = true;
        }
        $mail->addReplyTo($application['email'], $application['name']);

        $mail->isHTML(true);
        $mail->Subject = 'Nueva solicitud de empleo: ' . $application['name'] . ' — ' . $application['position'];

        $safeName = htmlspecialchars($application['name'], ENT_QUOTES, 'UTF-8');
        $safeEmail = htmlspecialchars($application['email'], ENT_QUOTES, 'UTF-8');
        $safePhone = htmlspecialchars($application['phone'], ENT_QUOTES, 'UTF-8');
        $safePosition = htmlspecialchars($application['position'], ENT_QUOTES, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($application['message'] ?? '', ENT_QUOTES, 'UTF-8'));

        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;'>
              <div style='background:#1B1714;color:#FAF6F0;padding:20px 24px;border-radius:6px 6px 0 0;'>
                <h2 style='margin:0;font-size:18px;'>Nueva solicitud de empleo</h2>
              </div>
              <div style='border:1px solid #E4DACB;border-top:none;padding:24px;border-radius:0 0 6px 6px;'>
                <p style='margin:0 0 14px;'><strong>Puesto:</strong> {$safePosition}</p>
                <p style='margin:0 0 6px;'><strong>Nombre:</strong> {$safeName}</p>
                <p style='margin:0 0 6px;'><strong>Teléfono:</strong> {$safePhone}</p>
                <p style='margin:0 0 14px;'><strong>Correo:</strong> {$safeEmail}</p>
                " . ($application['message'] ? "<p style='margin:0 0 14px;'><strong>Mensaje:</strong><br>{$safeMessage}</p>" : "") . "
                <p style='margin:20px 0 0;padding-top:16px;border-top:1px solid #E4DACB;font-size:13px;color:#666;'>
                  Entra al panel de administración para ver el CV y cambiar el estado de este candidato.
                </p>
              </div>
            </div>
        ";
        $mail->AltBody = "Nueva solicitud de empleo\n\nPuesto: {$application['position']}\nNombre: {$application['name']}\nTeléfono: {$application['phone']}\nCorreo: {$application['email']}\n" . ($application['message'] ? "\nMensaje: {$application['message']}\n" : '');

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        $logPath = __DIR__ . '/../data/mail-errors.log';
        $line = date('Y-m-d H:i:s') . ' — ' . $mail->ErrorInfo . PHP_EOL;
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
        return false;
    }
}


/**
 * Respaldo por correo de un mensaje que alguien dejó antes de abrir WhatsApp.
 * Va a "wa_backup_to" (uno o varios correos separados por coma) o, si no existe, a "notify_to".
 * Nunca lanza excepción: si falla, el mensaje igual queda guardado en el panel.
 */
function dicosa_notify_wa_lead(array $lead): bool
{
    $configPath = __DIR__ . '/../data/mail-config.php';
    if (!is_file($configPath)) return false;
    $config = include $configPath;
    if (!is_array($config) || empty($config['smtp_pass']) || $config['smtp_pass'] === 'PON_AQUI_TU_CONTRASEÑA_DE_APLICACION') return false;

    $to = [];
    foreach (preg_split('~[\s,;]+~', (string)($config['wa_backup_to'] ?? $config['notify_to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $e) {
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $to[strtolower($e)] = $e;
    }
    if (!$to) return false;

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)$config['smtp_port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['from_email'], $config['from_name']);
        foreach ($to as $e) $mail->addAddress($e);

        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $name = $lead['name'] !== '' ? $lead['name'] : 'Sin nombre';
        $phone = $lead['phone'];
        $wa = 'https://wa.me/52' . $phone;
        $branch = $lead['branch_name'] !== '' ? $lead['branch_name'] : 'Sitio en general';
        $mail->isHTML(true);
        $isQ = ($lead['kind'] ?? '') === 'cotizacion';
        $mail->Subject = ($isQ ? 'Cotización por WhatsApp: ' : 'Respaldo WhatsApp: ') . $name . ' — ' . $phone . ($isQ && $lead['branch_name'] !== '' ? ' (' . $lead['branch_name'] . ')' : '');
        $mail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;'>
              <div style='background:#1B1714;color:#FAF6F0;padding:20px 24px;border-radius:6px 6px 0 0;'>
                <h2 style='margin:0;font-size:18px;'>{$h($isQ ? 'Solicitud de cotización (respaldo)' : 'Mensaje por WhatsApp (respaldo)')}</h2>
              </div>
              <div style='border:1px solid #E4DACB;border-top:none;padding:24px;border-radius:0 0 6px 6px;'>
                <p style='margin:0 0 6px;'><strong>Nombre:</strong> {$h($name)}</p>
                <p style='margin:0 0 6px;'><strong>Teléfono:</strong> {$h($phone)}</p>
                <p style='margin:0 0 6px;'><strong>{$h($isQ ? 'Sucursal que le queda más cerca' : 'Desde')}:</strong> {$h($isQ && $lead['branch_name'] === '' ? 'No indicó' : $branch)}</p>
                <p style='margin:0 0 14px;'><strong>Fecha:</strong> {$h($lead['created_at'])}</p>
                <p style='margin:0 0 14px;padding:14px;background:#FAF6F0;border-radius:6px;'>" . nl2br($h($lead['message'])) . "</p>
                <p style='margin:0;'><a href='{$h($wa)}' style='color:#A6182B;'>Responder por WhatsApp</a></p>
                <p style='margin:20px 0 0;padding-top:16px;border-top:1px solid #E4DACB;font-size:13px;color:#666;'>
                  Este correo es un respaldo del primer mensaje. También lo puedes ver y darle seguimiento en el panel (menú Mensajes).
                </p>
              </div>
            </div>";
        $mail->AltBody = ($isQ ? "Solicitud de cotización (respaldo)" : "Mensaje por WhatsApp (respaldo)") . "\n\nNombre: $name\nTeléfono: $phone\nDesde: $branch\nFecha: {$lead['created_at']}\n\n{$lead['message']}\n\nResponder: $wa\n";
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        @file_put_contents(__DIR__ . '/../data/mail-errors.log', date('Y-m-d H:i:s') . ' — [wa] ' . $mail->ErrorInfo . PHP_EOL, FILE_APPEND | LOCK_EX);
        return false;
    }
}
