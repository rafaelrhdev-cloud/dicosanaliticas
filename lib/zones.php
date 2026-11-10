<?php
// zones.php — zonas de los visitantes por código postal (voluntario, anónimo, sin coordenadas ni IP).
// Base de C.P. sin conexión en lib/cp/ (SEPOMEX). Eventos en data/zones/AAAA-MM.jsonl.
declare(strict_types=1);

require_once __DIR__ . '/analytics.php';

const DICOSA_ZN_STATES = [
    '01' => ['AGS', 'Aguascalientes'], '02' => ['BC', 'Baja California'], '03' => ['BCS', 'Baja California Sur'],
    '04' => ['CAMP', 'Campeche'], '05' => ['COAH', 'Coahuila'], '06' => ['COL', 'Colima'], '07' => ['CHIS', 'Chiapas'],
    '08' => ['CHIH', 'Chihuahua'], '09' => ['CDMX', 'Ciudad de México'], '10' => ['DGO', 'Durango'], '11' => ['GTO', 'Guanajuato'],
    '12' => ['GRO', 'Guerrero'], '13' => ['HGO', 'Hidalgo'], '14' => ['JAL', 'Jalisco'], '15' => ['MEX', 'Estado de México'],
    '16' => ['MICH', 'Michoacán'], '17' => ['MOR', 'Morelos'], '18' => ['NAY', 'Nayarit'], '19' => ['NL', 'Nuevo León'],
    '20' => ['OAX', 'Oaxaca'], '21' => ['PUE', 'Puebla'], '22' => ['QRO', 'Querétaro'], '23' => ['QROO', 'Quintana Roo'],
    '24' => ['SLP', 'San Luis Potosí'], '25' => ['SIN', 'Sinaloa'], '26' => ['SON', 'Sonora'], '27' => ['TAB', 'Tabasco'],
    '28' => ['TAMPS', 'Tamaulipas'], '29' => ['TLAX', 'Tlaxcala'], '30' => ['VER', 'Veracruz'], '31' => ['YUC', 'Yucatán'], '32' => ['ZAC', 'Zacatecas'],
];

function dicosa_zn_dir(): string
{
    $d = __DIR__ . '/../data/zones';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

function dicosa_zn_config(): array
{
    $c = json_decode((string)@file_get_contents(dicosa_zn_dir() . '/config.json'), true);
    return ['enabled' => is_array($c) ? !empty($c['enabled']) : true];
}

function dicosa_zn_config_save(array $c): bool
{
    return file_put_contents(dicosa_zn_dir() . '/config.json', json_encode(['enabled' => !empty($c['enabled'])]), LOCK_EX) !== false;
}

/** Busca un C.P. en la base sin conexión. Devuelve null si no existe. */
function dicosa_zn_lookup(string $cp): ?array
{
    static $files = [];
    if (!preg_match('~^\d{5}$~', $cp)) return null;
    $pre = substr($cp, 0, 2);
    if (!isset($files[$pre])) {
        $f = __DIR__ . '/cp/' . $pre . '.json';
        $files[$pre] = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    $e = $files[$pre][$cp] ?? null;
    if (!$e) return null;
    [$cve, $mun, $city, $cols] = $e + [null, '', '', []];
    [$code, $name] = DICOSA_ZN_STATES[$cve] ?? ['', ''];
    return ['cp' => $cp, 'st' => $code, 'estado' => $name, 'mun' => (string)$mun, 'ciudad' => ($city && $city !== 'NULL') ? (string)$city : (string)$mun, 'colonias' => array_values((array)$cols)];
}

function dicosa_zn_append(array $events): void
{
    if (!$events) return;
    $lines = '';
    foreach ($events as $e) $lines .= json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(dicosa_zn_dir() . '/' . gmdate('Y-m') . '.jsonl', $lines, FILE_APPEND | LOCK_EX);
}

function dicosa_zn_files(int $from, int $to): array
{
    $files = [];
    for ($t = gmmktime(0, 0, 0, (int)gmdate('n', $from), 1, (int)gmdate('Y', $from)); $t <= $to; $t = gmmktime(0, 0, 0, (int)gmdate('n', $t) + 1, 1, (int)gmdate('Y', $t))) {
        $f = dicosa_zn_dir() . '/' . gmdate('Y-m', $t) . '.jsonl';
        if (is_file($f)) $files[] = $f;
    }
    if (is_file(dicosa_zn_dir() . '/demo.jsonl')) $files[] = dicosa_zn_dir() . '/demo.jsonl';
    return $files;
}

function dicosa_zn_purge(): void
{
    $limit = time() - dicosa_an_config()['retention_days'] * 86400;
    foreach (glob(dicosa_zn_dir() . '/20??-??.jsonl') ?: [] as $f) {
        if (preg_match('~(\d{4})-(\d{2})\.jsonl$~', $f, $m) && gmmktime(0, 0, 0, (int)$m[2] + 1, 1, (int)$m[1]) < $limit) @unlink($f);
    }
}

function dicosa_zn_wipe(): int
{
    $n = 0;
    foreach (glob(dicosa_zn_dir() . '/*.jsonl') ?: [] as $f) if (@unlink($f)) $n++;
    foreach (glob(dicosa_zn_dir() . '/cache/*') ?: [] as $f) @unlink($f);
    return $n;
}

function dicosa_zn_stamp(): string
{
    clearstatcache();
    $s = '0';
    foreach (glob(dicosa_zn_dir() . '/*.jsonl') ?: [] as $f) $s .= basename($f) . filemtime($f) . filesize($f);
    return $s;
}

/** Cuenta personas distintas (una por C.P.) entre dos fechas. */
function dicosa_zn_collect(int $from, int $to): array
{
    $seen = [];   // "v|cp" => [t, colonia]
    foreach (dicosa_zn_files($from, $to) as $f) {
        $fh = @fopen($f, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            if (!preg_match('~^\{"t":(\d+),~', $line, $m)) continue;
            $t = (int)$m[1];
            if ($t < $from || $t > $to) continue;
            $e = json_decode($line, true);
            if (!is_array($e) || empty($e['v'])) continue;
            if (empty($e['cp'])) {   // paso del embudo: la invitación se mostró / la cerraron
                if (isset($e['k'])) $seen['#' . $e['k'] . '|' . $e['v']] = [$t, ''];
                continue;
            }
            $k = $e['v'] . '|' . $e['cp'];
            if (!isset($seen[$k])) $seen[$k] = [$t, (string)($e['c'] ?? '')];
            elseif (!empty($e['c'])) $seen[$k][1] = (string)$e['c'];
        }
        fclose($fh);
    }
    return $seen;
}

function dicosa_zn_report(string $from, string $to): array
{
    $tz = new DateTimeZone(DICOSA_AN_TZ);
    $a = new DateTimeImmutable($from . ' 00:00:00', $tz);
    $b = new DateTimeImmutable($to . ' 23:59:59', $tz);
    $days = (int)floor(($b->getTimestamp() - $a->getTimestamp()) / 86400) + 1;
    $dayIdx = static fn(int $t) => max(0, min($days - 1, (int)floor(($t - $a->getTimestamp()) / 86400)));

    $seen = dicosa_zn_collect($a->getTimestamp(), $b->getTimestamp());
    $prevFrom = $a->modify("-{$days} days")->getTimestamp();
    $prevPeople = [];
    foreach (dicosa_zn_collect($prevFrom, $a->getTimestamp() - 1) as $k => $_) $prevPeople[explode('|', $k)[0]] = 1;

    $cps = []; $muns = []; $sts = []; $cols = []; $people = [];
    $fn = ['shown' => [], 'skip' => [], 'later' => []];
    foreach ($seen as $k => [$t, $col]) {
        if ($k[0] === '#') { [$kind, $vv] = explode('|', substr($k, 1), 2); if (isset($fn[$kind])) $fn[$kind][$vv] = 1; continue; }
        $v = explode('|', $k)[0];
        $cp = explode('|', $k)[1];
        $people[$v] = 1;
        $z = dicosa_zn_lookup($cp);
        if (!$z) continue;
        $di = $dayIdx($t);
        $ck = $cp;
        $cps[$ck] ??= ['cp' => $cp, 'mun' => $z['mun'], 'st' => $z['st'], 'estado' => $z['estado'], 'n' => 0, 'ncol' => count($z['colonias']), 'sample' => array_slice($z['colonias'], 0, 4), 'cols' => [], 'ser' => array_fill(0, $days, 0)];
        $cps[$ck]['n']++; $cps[$ck]['ser'][$di]++;
        $mk = $z['mun'] . '|' . $z['st'];
        $muns[$mk] ??= ['mun' => $z['mun'], 'st' => $z['st'], 'estado' => $z['estado'], 'n' => 0, 'cps' => [], 'cols' => [], 'ser' => array_fill(0, $days, 0)];
        $muns[$mk]['n']++; $muns[$mk]['ser'][$di]++;
        $muns[$mk]['cps'][$cp] = ($muns[$mk]['cps'][$cp] ?? 0) + 1;
        $sts[$z['st']] ??= ['st' => $z['st'], 'estado' => $z['estado'], 'n' => 0];
        $sts[$z['st']]['n']++;
        if ($col !== '' && in_array($col, $z['colonias'], true)) {
            $cps[$ck]['cols'][$col] = ($cps[$ck]['cols'][$col] ?? 0) + 1;
            $muns[$mk]['cols'][$col] = ($muns[$mk]['cols'][$col] ?? 0) + 1;
            $cols[$cp . '|' . $col] ??= ['col' => $col, 'cp' => $cp, 'mun' => $z['mun'], 'st' => $z['st'], 'n' => 0];
            $cols[$cp . '|' . $col]['n']++;
        }
    }
    $total = array_sum(array_column($cps, 'n'));
    $pct = static fn(int $n) => $total > 0 ? round($n * 100 / $total, 1) : 0;
    $sortN = static function (array &$l) { usort($l, fn($x, $y) => ($y['n'] <=> $x['n']) ?: strcmp((string)($x['mun'] ?? $x['col'] ?? ''), (string)($y['mun'] ?? $y['col'] ?? ''))); };

    $top = static function (array $m, int $max = 6) { arsort($m); $o = []; foreach (array_slice($m, 0, $max, true) as $k => $n) $o[] = ['k' => (string)$k, 'n' => $n]; return $o; };

    $cpList = array_values($cps); $sortN($cpList);
    foreach ($cpList as $i => &$r) { $r['pct'] = $pct($r['n']); $r['cols'] = $top($r['cols']); if ($i >= 80) unset($r['ser']); } unset($r);
    $munList = array_values($muns); $sortN($munList);
    foreach ($munList as $i => &$r) { $r['pct'] = $pct($r['n']); $r['ncp'] = count($r['cps']); $r['cps'] = $top($r['cps']); $r['cols'] = $top($r['cols']); if ($i >= 80) unset($r['ser']); } unset($r);
    $colList = array_values($cols); $sortN($colList);
    foreach ($colList as &$r) $r['pct'] = $pct($r['n']); unset($r);
    $stList = array_values($sts); $sortN($stList);
    foreach ($stList as &$r) $r['pct'] = $pct($r['n']); unset($r);

    // ¿Cuántos C.P. juntan el 80 % de las respuestas?
    $acc = 0; $p80 = 0;
    foreach ($cpList as $r) { $acc += $r['n']; $p80++; if ($total && $acc >= $total * 0.8) break; }

    // Cobertura: qué parte de los visitantes del sitio compartió su zona.
    $visitors = 0;
    try { $visitors = (int)(dicosa_an_report($from, $to, '')['totals']['visitors'] ?? 0); } catch (Throwable $e) {}

    $daily = array_fill(0, $days, 0);
    foreach ($cpList as $r) if (isset($r['ser'])) foreach ($r['ser'] as $i => $n) $daily[$i] += $n;

    return [
        'from' => $from, 'to' => $to, 'days' => $days,
        'totals' => ['people' => count($people), 'responses' => $total, 'prev_people' => count($prevPeople), 'cps' => count($cpList), 'muns' => count($munList), 'states' => count($stList), 'cols' => count($colList), 'visitors' => $visitors,
            'coverage' => $visitors > 0 ? min(100, round(count($people) * 100 / $visitors, 1)) : null, 'p80' => $p80],
        'muns' => array_slice($munList, 0, 150), 'cps' => array_slice($cpList, 0, 300), 'cols' => array_slice($colList, 0, 300), 'states' => $stList,
        'daily' => $daily,
        'funnel' => ['shown' => count($fn['shown'] + $fn['skip'] + $fn['later'] + $people), 'skip' => count(array_diff_key($fn['skip'], $people)), 'later' => count(array_diff_key($fn['later'], $people)), 'shared' => count($people)],
    ];
}

/** Datos de ejemplo (para enseñar el panel). Solo usa C.P. reales de la base. */
function dicosa_zn_seed_demo(int $days = 75): int
{
    mt_srand(20260202);
    $pool = [['76800', 30], ['76803', 22], ['76804', 14], ['76806', 10], ['76807', 12], ['76809', 8], ['76810', 6], ['76820', 4], ['76750', 9], ['76700', 6],
        ['76000', 8], ['76030', 5], ['76090', 4], ['76146', 3], ['76220', 3], ['76240', 2], ['06600', 3], ['03100', 2], ['50000', 2], ['38010', 2], ['37000', 1], ['44100', 1]];
    $pool = array_values(array_filter($pool, fn($p) => dicosa_zn_lookup($p[0])));
    if (!$pool) return 0;
    $sum = array_sum(array_column($pool, 1));
    $out = ''; $n = 0; $now = time();
    for ($i = 0; $i < 360; $i++) {
        $r = mt_rand(1, $sum); $cp = $pool[0][0];
        foreach ($pool as [$c, $w]) { $r -= $w; if ($r <= 0) { $cp = $c; break; } }
        $t = $now - mt_rand(0, $days * 86400 - 1);
        $ev = ['t' => $t, 'v' => 'demo' . substr(md5('d' . $i), 0, 10), 'cp' => $cp];
        $cols = dicosa_zn_lookup($cp)['colonias'];
        if ($cols && mt_rand(1, 100) <= 55) $ev['c'] = $cols[mt_rand(0, count($cols) - 1)];
        $out .= json_encode($ev, JSON_UNESCAPED_UNICODE) . "\n"; $n++;
    }
    // Quienes vieron la invitación y no compartieron (para el embudo)
    for ($i = 0; $i < 640; $i++) {
        $t = $now - mt_rand(0, $days * 86400 - 1);
        $k = mt_rand(1, 100) <= 45 ? 'skip' : (mt_rand(1, 100) <= 40 ? 'later' : 'shown');
        $out .= json_encode(['t' => $t, 'v' => 'demx' . substr(md5('x' . $i), 0, 10), 'k' => $k === 'shown' ? 'shown' : $k]) . "\n";
        $out .= json_encode(['t' => $t - 5, 'v' => 'demx' . substr(md5('x' . $i), 0, 10), 'k' => 'shown']) . "\n";
    }
    // el archivo debe estar en orden de tiempo para la lectura rápida
    $lines = explode("\n", trim($out));
    usort($lines, fn($x, $y) => strcmp($x, $y) <=> 0);
    file_put_contents(dicosa_zn_dir() . '/demo.jsonl', implode("\n", $lines) . "\n", LOCK_EX);
    return $n;
}
