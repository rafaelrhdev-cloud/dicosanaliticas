<?php
// leads-api.php — mensajes que la gente dejó antes de abrir WhatsApp (menú «Mensajes»).
//   GET  ?action=list                 -> mensajes (el gerente solo ve los de su sucursal)
//   GET  ?action=csv                  -> descarga para Excel
//   POST action=update {id,status?,notes?}  (requiere contact.edit)
declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

require __DIR__ . '/auth.php';
dicosa_require_perm('contact.view');
$me = dicosa_current_user();
$scope = dicosa_scope_branch($me);   // null = todos; texto = solo su sucursal

function ld_json($d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
const LD_STATUS = ['nuevo' => 'Nuevo', 'seguimiento' => 'En seguimiento', 'cerrado' => 'Atendido'];
$path = __DIR__ . '/../data/wa-leads.json';
$visible = fn(array $l) => $scope === null || ($scope !== '' && ($l['branch_id'] ?? '') === $scope);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dicosa_require_perm('contact.edit');
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in) || (string)($in['action'] ?? '') !== 'update') ld_json(['success' => false, 'error' => 'Acción no válida.'], 400);
    $id = (string)($in['id'] ?? '');
    $fh = @fopen($path, 'c+');
    if (!$fh) ld_json(['success' => false, 'error' => 'No se pudo abrir el archivo.'], 500);
    $found = null;
    if (flock($fh, LOCK_EX)) {
        $all = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($all)) $all = [];
        foreach ($all as &$l) {
            if (($l['id'] ?? '') !== $id || !$visible($l)) continue;
            if (isset($in['status']) && isset(LD_STATUS[(string)$in['status']])) $l['status'] = (string)$in['status'];
            if (isset($in['notes'])) $l['notes'] = mb_substr(trim((string)$in['notes']), 0, 1000);
            $l['updated_by'] = $me['username'] ?? ''; $l['updated_at'] = date('Y-m-d H:i:s');
            $found = $l;
        }
        unset($l);
        if ($found) { ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); }
        fflush($fh); flock($fh, LOCK_UN);
    }
    fclose($fh);
    if (!$found) ld_json(['success' => false, 'error' => 'No se encontró el mensaje.'], 404);
    dicosa_audit('mensajes', 'Actualizó un mensaje de WhatsApp (' . $id . ')', $me);
    ld_json(['success' => true, 'lead' => $found]);
}

$all = json_decode((string)@file_get_contents($path), true);
$leads = array_values(array_filter(is_array($all) ? $all : [], $visible));
usort($leads, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));

if ((string)($_GET['action'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dicosa-mensajes-whatsapp-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Fecha', 'Tipo', 'Nombre', 'Teléfono', 'Sucursal', 'Mensaje', 'Estado', 'Notas', 'Respaldo por correo'], ',', '"', '\\');
    foreach ($leads as $l) fputcsv($o, [$l['created_at'], ($l['kind'] ?? '') === 'cotizacion' ? 'Cotización' : 'Mensaje', $l['name'], $l['phone'], $l['branch_name'] ?? '', $l['message'], LD_STATUS[$l['status']] ?? $l['status'], $l['notes'] ?? '', !empty($l['email_sent']) ? 'Enviado' : 'Pendiente'], ',', '"', '\\');
    fclose($o);
    exit;
}

ld_json(['success' => true, 'leads' => $leads, 'can_edit' => dicosa_can('contact.edit'), 'statuses' => LD_STATUS]);
