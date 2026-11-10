<?php
// track.php — recibe los eventos anónimos del sitio público (visitas, clics, secciones vistas).
// Responde siempre 204 (sin contenido): al visitante nunca se le nota ni se le rompe nada.
//
// No se guarda: IP, nombre, correo, teléfono ni lo escrito en formularios.

declare(strict_types=1);

require_once __DIR__ . '/../lib/analytics.php';

function dicosa_track_done(): void
{
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') dicosa_track_done();

$cfg = dicosa_an_config();
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if (empty($cfg['enabled'])) dicosa_track_done();
if (dicosa_an_is_bot($ua)) dicosa_track_done();
if (!empty($cfg['respect_dnt']) && (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1')) dicosa_track_done();
if (!empty($cfg['exclude_staff']) && !empty($_COOKIE['dicosa_staff'])) dicosa_track_done();
if ($ip !== '' && in_array($ip, $cfg['ignore_ips'], true)) dicosa_track_done();

$raw = file_get_contents('php://input', false, null, 0, 16384);
$in = json_decode((string)$raw, true);
if (!is_array($in) || empty($in['ev']) || !is_array($in['ev'])) dicosa_track_done();

$vid = (string)($in['v'] ?? '');
$sid = (string)($in['s'] ?? '');
if (!preg_match('~^[a-z0-9]{8,32}$~', $vid) || !preg_match('~^[a-z0-9]{8,32}$~', $sid)) dicosa_track_done();

// Límite por IP: 120 envíos por minuto (la cuenta usa un código anónimo, no la IP).
$ok = true;
$rlKey = substr(hash('sha256', $ip . '|' . dicosa_an_secret()), 0, 12);
$minute = (int)floor(time() / 60);
dicosa_an_json_update(dicosa_an_dir() . '/rl.json', function (array $d) use ($rlKey, $minute, &$ok) {
    foreach ($d as $k => $row) if (($row[0] ?? 0) < $minute) unset($d[$k]);
    $n = (($d[$rlKey][0] ?? 0) === $minute ? $d[$rlKey][1] : 0) + 1;
    $d[$rlKey] = [$minute, $n];
    if ($n > 120) $ok = false;
    return $d;
});
if (!$ok) dicosa_track_done();

// Sucursales válidas (para no guardar identificadores inventados).
$validBranches = [];
$bl = json_decode((string)@file_get_contents(__DIR__ . '/../data/branches.json'), true);
if (is_array($bl)) foreach ($bl as $b) if (!empty($b['id'])) $validBranches[(string)$b['id']] = true;

$clean = static function ($v, int $max): string {
    $v = preg_replace('~[\x00-\x1F\x7F<>"\\\\]~u', '', (string)$v) ?? '';
    return trim(mb_substr($v, 0, $max));
};

$actions = array_merge(array_keys(DICOSA_AN_ACTIONS), ['bnear']);
$sections = array_keys(DICOSA_AN_SECTIONS);
$ownHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$now = time();
$out = [];

foreach (array_slice($in['ev'], 0, 12) as $e) {
    if (!is_array($e)) continue;
    $type = (string)($e['e'] ?? '');
    $b = $clean($e['b'] ?? '', 40);
    if ($b !== '' && !isset($validBranches[$b])) $b = '';
    $ev = ['t' => $now, 'e' => $type, 'v' => $vid, 's' => $sid];

    if ($type === 'pv') {
        $p = '/' . ltrim($clean($e['p'] ?? '/', 80), '/');
        if (!preg_match('~^/(sucursal/[a-z0-9-]{1,40})?$~', $p)) $p = '/';
        $ev['p'] = $p;
        if ($b !== '') $ev['b'] = $b;
        if (!empty($e['n'])) $ev['n'] = 1;
        if (!empty($e['f'])) {
            // Primera página de la sesión: de dónde llegó, qué dispositivo y zona aproximada.
            $refHost = (string)parse_url((string)($e['ref'] ?? ''), PHP_URL_HOST);
            $src = dicosa_an_source($refHost, $clean($e['utm'] ?? '', 40), $ownHost);
            $ev['r'] = $src === 'Directo' ? '' : $src;
            [$dev, $br, $os] = dicosa_an_parse_ua($ua, (int)($e['w'] ?? 0));
            [$c, $st, $ci] = dicosa_an_geo($ip, $cfg);
            $ev += ['d' => $dev, 'g' => $br, 'o' => $os, 'c' => $c, 'st' => $st, 'ci' => $ci];
        }
    } elseif ($type === 'ac') {
        $a = (string)($e['a'] ?? '');
        if (!in_array($a, $actions, true)) continue;
        $ev['a'] = $a;
        if ($b !== '') $ev['b'] = $b;
        $x = $clean($e['x'] ?? '', 60);
        if ($x !== '') $ev['x'] = $x;
    } elseif ($type === 'sc') {
        $x = (string)($e['x'] ?? '');
        if (!in_array($x, $sections, true)) continue;
        $ev['x'] = $x;
    } elseif ($type === 'lv') {
        $ev['u'] = max(0, min(1800, (int)($e['u'] ?? 0)));
        $ev['k'] = max(0, min(100, (int)($e['k'] ?? 0)));
    } else {
        continue;
    }
    $out[] = $ev;
}

dicosa_an_append($out);
if (mt_rand(1, 300) === 1) dicosa_an_purge();
dicosa_track_done();
