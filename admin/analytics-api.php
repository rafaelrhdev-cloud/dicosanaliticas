<?php
// analytics-api.php — datos del tablero de Analítica (solo para quien tenga permiso).
//   GET  ?action=report&from=AAAA-MM-DD&to=AAAA-MM-DD&branch=ID   -> reporte completo
//   GET  ?action=live                                             -> personas en el sitio ahora
//   GET  ?action=csv&from=&to=&branch=                            -> descarga para Excel
//   POST action=config|demo_on|demo_off|wipe (solo analytics.manage)
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_once __DIR__ . '/../lib/analytics.php';

dicosa_require_perm('analytics.view');
$me = dicosa_current_user();
$scope = dicosa_scope_branch($me);          // null = todo el sitio; texto = solo su sucursal
$isManager = $scope !== null;

function an_json($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? 'report');

// ------------------------------------------------------------ acciones que cambian cosas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Cualquier persona con acceso puede decidir si en SU navegador se cuentan sus visitas (para probar el sitio).
    if ($action === 'self') {
        $on = !empty($_GET['on']);
        $o = ['path' => '/', 'secure' => dicosa_is_https(), 'httponly' => true, 'samesite' => 'Lax'];
        if ($on) { setcookie('dicosa_staff_off', '1', $o + ['expires' => time() + 60 * 86400]); setcookie('dicosa_staff', '', $o + ['expires' => time() - 3600]); }
        else { setcookie('dicosa_staff_off', '', $o + ['expires' => time() - 3600]); setcookie('dicosa_staff', '1', $o + ['expires' => 0]); }
        an_json(['success' => true, 'self_counts' => $on]);
    }
    dicosa_require_perm('analytics.manage');
    if ($action === 'config') {
        $in = json_decode((string)file_get_contents('php://input'), true);
        $in = is_array($in) ? $in : $_POST;
        $cfg = [
            'enabled' => !empty($in['enabled']), 'geo_lookup' => !empty($in['geo_lookup']),
            'exclude_staff' => !empty($in['exclude_staff']), 'respect_dnt' => !empty($in['respect_dnt']),
            'retention_days' => (int)($in['retention_days'] ?? 400),
            'ignore_ips' => preg_split('~[\s,;]+~', (string)(is_array($in['ignore_ips'] ?? null) ? implode(' ', $in['ignore_ips']) : ($in['ignore_ips'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ];
        dicosa_an_clear_cache();
        if (!dicosa_an_config_save($cfg)) an_json(['success' => false, 'error' => 'No se pudo guardar la configuración.'], 500);
        dicosa_audit('analytics', 'Cambió la configuración de analítica', $me);
        an_json(['success' => true, 'config' => dicosa_an_config()]);
    }
    if ($action === 'demo_on') {
        $n = dicosa_an_seed_demo(75);
        dicosa_audit('analytics', 'Cargó datos de ejemplo en analítica', $me);
        an_json(['success' => true, 'events' => $n]);
    }
    if ($action === 'demo_off') {
        @unlink(dicosa_an_dir() . '/demo.jsonl');
        dicosa_an_clear_cache();
        dicosa_audit('analytics', 'Quitó los datos de ejemplo de analítica', $me);
        an_json(['success' => true]);
    }
    if ($action === 'wipe') {
        $n = dicosa_an_wipe();
        dicosa_audit('analytics', 'Borró todos los datos de analítica', $me);
        an_json(['success' => true, 'files' => $n]);
    }
    an_json(['success' => false, 'error' => 'Acción no válida.'], 400);
}

// ------------------------------------------------------------ lectura
$branch = (string)($_GET['branch'] ?? '');
if ($isManager) $branch = $scope !== '' ? $scope : '__none__';   // el gerente solo ve lo de su sucursal

if ($action === 'live') an_json(['success' => true, 'live' => $branch === '__none__' ? 0 : dicosa_an_live($branch)]);

$tz = new DateTimeZone(DICOSA_AN_TZ);
$today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
$valid = fn(string $d) => (bool)preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) && strtotime($d) !== false;
$to = $valid((string)($_GET['to'] ?? '')) ? (string)$_GET['to'] : $today;
$from = $valid((string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : (new DateTimeImmutable($to, $tz))->modify('-29 days')->format('Y-m-d');
if ($to > $today) $to = $today;
if ($from > $to) $from = $to;
$days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;
if ($days > 400) { $from = (new DateTimeImmutable($to, $tz))->modify('-399 days')->format('Y-m-d'); }

// Caché corta (90 s) para que abrir y cambiar de filtro sea inmediato aunque haya muchos datos.
$cacheDir = dicosa_an_dir() . '/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
// La llave cambia en cuanto entra un dato nuevo (o se cargan/quitan ejemplos): lo nuevo se ve al instante.
clearstatcache();
$demoStamp = '0';
foreach (glob(dicosa_an_dir() . '/*.jsonl') ?: [] as $f) $demoStamp .= basename($f) . filemtime($f) . filesize($f);
$cacheFile = $cacheDir . '/r-' . substr(sha1("$from|$to|$branch|$demoStamp"), 0, 20) . '.json';
if (is_file($cacheFile) && time() - filemtime($cacheFile) < 90) {
    $report = json_decode((string)file_get_contents($cacheFile), true);
} else {
    $report = $branch === '__none__' ? null : dicosa_an_report($from, $to, $branch);
    if ($report) {
        // Limpia reportes viejos de caché y guarda este.
        foreach (glob($cacheDir . '/r-*.json') ?: [] as $f) if (time() - filemtime($f) > 600) @unlink($f);
        @file_put_contents($cacheFile, json_encode($report, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}
if (!$report) {
    $report = dicosa_an_report($today, $today, '__none__');   // vacío y con la forma correcta
}

if ($action === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="dicosa-analitica-' . $from . '_a_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");   // para que Excel respete los acentos
    $put = fn(array $r) => fputcsv($o, $r, ',', '"', '\\');
    $put(['DICOSA — Analítica del sitio']); $put(['Periodo', $from . ' a ' . $to]); $put([]);
    $t = $report['totals'];
    $put(['RESUMEN', 'Este periodo', 'Periodo anterior']);
    foreach (['visitors' => 'Visitantes', 'sessions' => 'Visitas', 'pageviews' => 'Páginas vistas', 'contacts' => 'Contactos (llamar, WhatsApp, cómo llegar, correo)',
        'contact_rate' => '% de visitantes que contactaron', 'avg_time' => 'Tiempo promedio (segundos)', 'applies' => 'Solicitudes de empleo'] as $k => $l) $put([$l, $t[$k], $report['prev'][$k]]);
    $put([]); $put(['POR DÍA', 'Visitantes', 'Visitas', 'Páginas vistas', 'Acciones']);
    foreach ($report['daily'] as $d) $put([$d['d'], $d['visitors'], $d['sessions'], $d['pageviews'], $d['actions']]);
    $put([]); $put(['SUCURSALES', 'Páginas vistas', 'La eligieron', 'Llamadas', 'WhatsApp', 'Cómo llegar', 'Correo']);
    foreach ($report['branches'] as $b) $put([$b['name'], $b['pv'], $b['bsel'], $b['call'], $b['wa'], $b['dir'], $b['mail']]);
    $put([]); $put(['ACCIONES', 'Veces']);
    foreach ($report['actions'] as $a) $put([$a['label'], $a['n']]);
    $put([]); $put(['ESTADO', 'Visitantes']);
    foreach ($report['geo']['state'] as $r) $put([$r['k'], $r['n']]);
    $put([]); $put(['CIUDAD', 'Visitantes']);
    foreach ($report['geo']['city'] as $r) $put([$r['k'], $r['n']]);
    $put([]); $put(['ORIGEN', 'Visitas']);
    foreach ($report['sources'] as $r) $put([$r['k'], $r['n']]);
    $put([]); $put(['DISPOSITIVO', 'Visitantes']);
    foreach ($report['devices']['device'] as $r) $put([$r['k'], $r['n']]);
    fclose($o);
    exit;
}

$cfg = dicosa_an_config();
$files = glob(dicosa_an_dir() . '/20??-??.jsonl') ?: [];
an_json([
    'success' => true,
    'report' => $report,
    'scoped' => $isManager,
    'can_manage' => dicosa_can('analytics.manage'),
    'config' => dicosa_can('analytics.manage') ? $cfg : null,
    'collecting' => (bool)$cfg['enabled'],
    'has_real' => (bool)$files,
    'has_demo' => is_file(dicosa_an_dir() . '/demo.jsonl'),
    'today' => $today,
    'self_counts' => !empty($_COOKIE['dicosa_staff_off']),
]);
