<?php
// zones-api.php — datos del panel «Zonas» (códigos postales de los visitantes).
//   GET  ?action=report&from=&to=   -> reporte
//   GET  ?action=csv&from=&to=      -> descarga para Excel
//   POST action=config|demo_on|demo_off|wipe (solo analytics.manage)
// Las cuentas de gerente de sucursal no ven este panel (los datos son de todo el sitio).
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/zones.php';

dicosa_require_perm('analytics.view');
$me = dicosa_current_user();
function zn_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (dicosa_scope_branch($me) !== null) zn_json(['success' => false, 'error' => 'Este panel no está disponible para tu cuenta.'], 403);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'report');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dicosa_require_perm('analytics.manage');
    if ($action === 'config') {
        $in = json_decode((string)file_get_contents('php://input'), true);
        dicosa_zn_config_save(['enabled' => is_array($in) && !empty($in['enabled'])]);
        dicosa_audit('analytics', 'Cambió la captura de código postal (Zonas)', $me);
        zn_json(['success' => true, 'config' => dicosa_zn_config()]);
    }
    if ($action === 'demo_on') { $n = dicosa_zn_seed_demo(75); dicosa_audit('analytics', 'Cargó datos de ejemplo en Zonas', $me); zn_json(['success' => true, 'events' => $n]); }
    if ($action === 'demo_off') { @unlink(dicosa_zn_dir() . '/demo.jsonl'); dicosa_audit('analytics', 'Quitó los datos de ejemplo de Zonas', $me); zn_json(['success' => true]); }
    if ($action === 'wipe') { $n = dicosa_zn_wipe(); dicosa_audit('analytics', 'Borró los datos de Zonas', $me); zn_json(['success' => true, 'files' => $n]); }
    zn_json(['success' => false, 'error' => 'Acción no válida.'], 400);
}

$tz = new DateTimeZone(DICOSA_AN_TZ);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$valid = fn(string $d) => (bool)preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) && strtotime($d) !== false;
$to = $valid((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : $today;
$from = $valid((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : (new DateTimeImmutable($to, $tz))->modify('-89 days')->format('Y-m-d');
if ($to > $today) $to = $today;
if ($from > $to) $from = $to;
if ((strtotime($to) - strtotime($from)) / 86400 > 400) $from = (new DateTimeImmutable($to, $tz))->modify('-399 days')->format('Y-m-d');

$cacheDir = dicosa_zn_dir() . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
$cacheFile = $cacheDir . '/r-' . substr(sha1("$from|$to|" . dicosa_zn_stamp()), 0, 20) . '.json';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 90) {
    $report = json_decode((string)file_get_contents($cacheFile), true);
} else {
    $report = dicosa_zn_report($from, $to);
    foreach (glob($cacheDir . '/r-*.json') ?: [] as $f) if (time() - filemtime($f) > 600) @unlink($f);
    @file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

if ($action === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dicosa-zonas-' . $from . '_a_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    $put = fn(array $r) => fputcsv($o, $r, ',', '"', '\\');
    $put(['DICOSA — Zonas de los visitantes (C.P. compartido de forma voluntaria)']); $put(['Periodo', $from . ' a ' . $to]);
    $put(['Personas que compartieron su zona', $report['totals']['people']]); $put([]);
    $put(['MUNICIPIOS', 'Estado', 'Personas', '% del total', 'C.P. distintos']);
    foreach ($report['muns'] as $r) $put([$r['mun'], $r['estado'], $r['n'], $r['pct'], $r['ncp']]);
    $put([]); $put(['CÓDIGOS POSTALES', 'Municipio', 'Estado', 'Personas', '% del total']);
    foreach ($report['cps'] as $r) $put([$r['cp'], $r['mun'], $r['estado'], $r['n'], $r['pct']]);
    $put([]); $put(['COLONIAS (las que la persona eligió)', 'C.P.', 'Municipio', 'Personas']);
    foreach ($report['cols'] as $r) $put([$r['col'], $r['cp'], $r['mun'], $r['n']]);
    $put([]); $put(['ESTADOS', 'Personas', '% del total']);
    foreach ($report['states'] as $r) $put([$r['estado'], $r['n'], $r['pct']]);
    fclose($o);
    exit;
}

zn_json([
    'success' => true, 'report' => $report,
    'can_manage' => dicosa_can('analytics.manage'),
    'config' => dicosa_zn_config(),
    'has_real' => (bool)(glob(dicosa_zn_dir() . '/20??-??.jsonl') ?: []),
    'has_demo' => is_file(dicosa_zn_dir() . '/demo.jsonl'),
    'today' => $today,
]);
