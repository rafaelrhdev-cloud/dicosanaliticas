<?php
// wa-lead.php — alguien deja su mensaje y teléfono antes de abrir WhatsApp.
// Se guarda para darle seguimiento (panel > Mensajes) y se manda un respaldo por correo.
// Responde JSON: {ok, wa_url}. Aunque el correo falle, el mensaje queda guardado y WhatsApp se abre igual.
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function wl_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wl_out(['ok' => false], 405);

$site = json_decode((string)@file_get_contents(__DIR__ . '/../data/site-data.json'), true);
$waNum = preg_replace('/\D/', '', (string)($site['whatsapp_number'] ?? '')) ?? '';
$waUrl = static fn(string $text) => 'https://wa.me/' . $waNum . ($text !== '' ? '?text=' . rawurlencode($text) : '');

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
if (!is_array($in)) wl_out(['ok' => false, 'error' => 'Datos no válidos.'], 400);

// Honeypot: un bot llena el campo oculto; fingimos éxito sin guardar.
if (!empty($in['web'])) wl_out(['ok' => true, 'wa_url' => $waUrl('')]);

$clean = static function ($v, int $max): string {
    $v = preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]~u', '', (string)$v) ?? '';
    return trim(mb_substr($v, 0, $max));
};
$name = $clean($in['name'] ?? '', 80);
$message = $clean($in['message'] ?? '', 1000);
$phone = preg_replace('/\D/', '', (string)($in['phone'] ?? '')) ?? '';
if (strlen($phone) >= 12 && str_starts_with($phone, '52')) $phone = substr($phone, -10);
if (strlen($phone) !== 10) wl_out(['ok' => false, 'error' => 'Escribe tu teléfono a 10 dígitos.', 'field' => 'phone'], 422);
if (mb_strlen($message) < 2) wl_out(['ok' => false, 'error' => 'Escribe tu mensaje.', 'field' => 'message'], 422);

// Límite por IP: 8 mensajes por hora.
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'x');
$rlPath = __DIR__ . '/../data/wa-attempts.json';
$rl = json_decode((string)@file_get_contents($rlPath), true);
if (!is_array($rl)) $rl = [];
$cut = time() - 3600;
foreach ($rl as $k => $t) { $rl[$k] = array_values(array_filter($t, fn($x) => $x > $cut)); if (!$rl[$k]) unset($rl[$k]); }
if (count($rl[$ip] ?? []) >= 8) wl_out(['ok' => false, 'error' => 'Enviaste varios mensajes seguidos. Intenta en un rato o escríbenos directo por WhatsApp.', 'direct' => $waUrl('')], 429);
$rl[$ip][] = time();
@file_put_contents($rlPath, json_encode($rl), LOCK_EX);

// Sucursal: solo si existe de verdad.
$branchId = ''; $branchName = '';
$bid = $clean($in['branch'] ?? '', 40);
if ($bid !== '') {
    $bl = json_decode((string)@file_get_contents(__DIR__ . '/../data/branches.json'), true);
    if (is_array($bl)) foreach ($bl as $b) if (($b['id'] ?? '') === $bid) { $branchId = $bid; $branchName = (string)($b['name'] ?? ''); }
}

$lead = [
    'id' => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
    'name' => $name, 'phone' => $phone, 'message' => $message,
    'branch_id' => $branchId, 'branch_name' => $branchName,
    'kind' => (($in['kind'] ?? '') === 'cotizacion') ? 'cotizacion' : 'mensaje',
    'status' => 'nuevo', 'notes' => '', 'email_sent' => false,
    'created_at' => date('Y-m-d H:i:s'),
];

$path = __DIR__ . '/../data/wa-leads.json';
$fh = @fopen($path, 'c+');
if (!$fh) wl_out(['ok' => true, 'wa_url' => $waUrl($message), 'saved' => false]);
$saved = false;
if (flock($fh, LOCK_EX)) {
    $all = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($all)) $all = [];
    $all[] = $lead;
    ftruncate($fh, 0); rewind($fh);
    $saved = fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
    fflush($fh); flock($fh, LOCK_UN);
}
fclose($fh);

// El respaldo por correo nunca debe impedir que se abra WhatsApp.
$sent = false;
try {
    require_once __DIR__ . '/../lib/mail-notify.php';
    $sent = dicosa_notify_wa_lead($lead);
} catch (\Throwable $e) {}
if ($sent && $saved) {
    $fh = @fopen($path, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
        $all = json_decode((string)stream_get_contents($fh), true);
        if (is_array($all)) {
            foreach ($all as &$r) if (($r['id'] ?? '') === $lead['id']) $r['email_sent'] = true;
            unset($r);
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        fflush($fh); flock($fh, LOCK_UN);
    }
    if ($fh) fclose($fh);
}

$text = ($name !== '' ? 'Hola, soy ' . $name . '. ' : 'Hola. ') . $message;
if ($lead['kind'] === 'cotizacion' && $branchName !== '') $text .= ' (Sucursal más cercana: ' . $branchName . ')';
wl_out(['ok' => true, 'wa_url' => $waUrl($text), 'saved' => $saved]);
