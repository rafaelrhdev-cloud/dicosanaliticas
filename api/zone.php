<?php
// zone.php — la persona decide compartir su código postal (opcional).
//   GET  ?status            -> {enabled}
//   GET  ?cp=76800          -> {ok, cp, mun, estado, colonias[]}  (solo consulta, no guarda nada)
//   POST {v,k}              -> embudo: k = shown | skip | later (sin datos de zona)
//   POST {v,cp,c?}          -> guarda UN dato anónimo: código postal (y colonia si la eligió). Sin IP ni coordenadas.
declare(strict_types=1);

require_once __DIR__ . '/../lib/zones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function zn_out(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$zc = dicosa_zn_config();
$cfg = dicosa_an_config();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (isset($_GET['status'])) zn_out(['enabled' => (bool)$zc['enabled']]);
    $z = $zc['enabled'] ? dicosa_zn_lookup(preg_replace('~\D~', '', (string)($_GET['cp'] ?? '')) ?? '') : null;
    if (!$z) zn_out(['ok' => false]);
    zn_out(['ok' => true, 'cp' => $z['cp'], 'mun' => $z['mun'], 'estado' => $z['estado'], 'st' => $z['st'], 'colonias' => array_slice($z['colonias'], 0, 120)]);
}

if (!$zc['enabled']) zn_out(['ok' => false], 403);
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
// Guardar es una decisión de la persona; aun así se respetan los mismos filtros que la analítica.
if (!$cfg['enabled'] || dicosa_an_is_bot($ua)) zn_out(['ok' => true, 'saved' => false]);
if (!empty($cfg['respect_dnt']) && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) zn_out(['ok' => true, 'saved' => false]);
if (!empty($cfg['exclude_staff']) && !empty($_COOKIE['dicosa_staff'])) zn_out(['ok' => true, 'saved' => false]);
if ($ip !== '' && in_array($ip, $cfg['ignore_ips'], true)) zn_out(['ok' => true, 'saved' => false]);

$in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
if (!is_array($in)) zn_out(['ok' => false], 400);
$vid = (string)($in['v'] ?? '');
if (!preg_match('~^[a-z0-9]{8,32}$~', $vid)) zn_out(['ok' => false], 400);
// Embudo: solo avisos anónimos de "se mostró" / "ahora no" / "después" (sin código postal).
$k = (string)($in['k'] ?? '');
if ($k !== '') {
    if (!in_array($k, ['shown', 'skip', 'later'], true)) zn_out(['ok' => false], 400);
    dicosa_zn_append([['t' => time(), 'v' => $vid, 'k' => $k]]);
    zn_out(['ok' => true, 'saved' => true]);
}
$z = dicosa_zn_lookup(preg_replace('~\D~', '', (string)($in['cp'] ?? '')) ?? '');
if (!$z) zn_out(['ok' => false, 'error' => 'cp'], 422);

// Límite por IP: 20 por minuto (reutiliza el contador anónimo de la analítica con otra llave).
$ok = true;
$rlKey = 'z' . substr(hash('sha256', $ip . '|' . dicosa_an_secret()), 0, 11);
$minute = (int)floor(time() / 60);
dicosa_an_json_update(dicosa_an_dir() . '/rl.json', function (array $d) use ($rlKey, $minute, &$ok) {
    foreach ($d as $k => $row) if (($row[0] ?? 0) < $minute) unset($d[$k]);
    $n = (($d[$rlKey][0] ?? 0) === $minute ? $d[$rlKey][1] : 0) + 1;
    $d[$rlKey] = [$minute, $n];
    if ($n > 20) $ok = false;
    return $d;
});
if (!$ok) zn_out(['ok' => true, 'saved' => false], 429);

$ev = ['t' => time(), 'v' => $vid, 'cp' => $z['cp']];
$col = trim((string)($in['c'] ?? ''));
if ($col !== '' && in_array($col, $z['colonias'], true)) $ev['c'] = $col;
dicosa_zn_append([$ev]);
if (mt_rand(1, 100) === 1) dicosa_zn_purge();
zn_out(['ok' => true, 'saved' => true, 'cp' => $z['cp'], 'mun' => $z['mun'], 'estado' => $z['estado']]);
