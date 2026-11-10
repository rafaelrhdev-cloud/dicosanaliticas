<?php
// analytics.php — analítica propia del sitio (sin cookies, sin servicios externos de rastreo).
//
// Qué se guarda: eventos anónimos (visita, clic en llamar/WhatsApp/cómo llegar, sucursal elegida,
// sección vista...) con un identificador al azar del navegador, tipo de dispositivo y zona aproximada.
// Qué NO se guarda: IP, nombre, teléfono, correo, ni lo que se escribe en formularios.
// Dónde: data/analytics/AAAA-MM.jsonl (una línea por evento). Se borra solo pasado el tiempo de retención.

declare(strict_types=1);

const DICOSA_AN_TZ = 'America/Mexico_City';

function dicosa_an_dir(): string
{
    $d = __DIR__ . '/../data/analytics';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

// ------------------------------------------------------------------ configuración

function dicosa_an_config(): array
{
    $def = [
        'enabled' => true,        // registrar visitas
        'geo_lookup' => true,     // estimar ciudad/estado a partir de la IP (la IP no se guarda)
        'exclude_staff' => true,  // no contar visitas de quien tiene sesión abierta en el panel
        'retention_days' => 400,  // días que se conservan los eventos
        'respect_dnt' => true,    // no registrar a quien activó "No rastrear" en su navegador
        'ignore_ips' => [],       // IPs a ignorar (por ejemplo la de la oficina)
    ];
    $p = dicosa_an_dir() . '/config.json';
    $c = is_file($p) ? json_decode((string)@file_get_contents($p), true) : null;
    return is_array($c) ? array_merge($def, array_intersect_key($c, $def)) : $def;
}

function dicosa_an_config_save(array $c): bool
{
    $c['retention_days'] = max(30, min(1100, (int)($c['retention_days'] ?? 400)));
    $c['ignore_ips'] = array_values(array_filter(array_map('trim', (array)($c['ignore_ips'] ?? [])), fn($x) => $x !== '' && filter_var($x, FILTER_VALIDATE_IP)));
    foreach (['enabled', 'geo_lookup', 'exclude_staff', 'respect_dnt'] as $k) $c[$k] = !empty($c[$k]);
    return file_put_contents(dicosa_an_dir() . '/config.json', json_encode($c, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

/** Lee-modifica-escribe un JSON con candado (varias visitas a la vez no se pisan). */
function dicosa_an_json_update(string $path, callable $fn): void
{
    $fh = @fopen($path, 'c+');
    if (!$fh) return;
    if (flock($fh, LOCK_EX)) {
        $raw = stream_get_contents($fh);
        $data = $raw !== '' && $raw !== false ? json_decode($raw, true) : [];
        $data = $fn(is_array($data) ? $data : []);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

// ------------------------------------------------------------------ reconocimiento del visitante

function dicosa_an_is_bot(string $ua): bool
{
    if ($ua === '' || strlen($ua) < 12) return true;
    return (bool)preg_match('~bot|crawl|spider|slurp|headless|lighthouse|pagespeed|pingdom|uptime|monitor|curl/|wget|python|node-fetch|axios|okhttp|java/|go-http|libwww|scrapy|facebookexternalhit|preview|ia_archiver|httpclient|phantom|selenium|puppeteer|playwright~i', $ua);
}

/** @return array{0:string,1:string,2:string} dispositivo, navegador, sistema */
function dicosa_an_parse_ua(string $ua, int $width = 0): array
{
    $tablet = (bool)preg_match('~iPad|Tablet|PlayBook|Silk|Kindle~i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false);
    $mobile = !$tablet && (bool)preg_match('~Mobi|iPhone|iPod|Android|Windows Phone~i', $ua);
    $dev = $tablet ? 'tablet' : ($mobile ? 'mobile' : 'desktop');
    if ($width > 0 && $dev === 'desktop' && $width < 700) $dev = 'mobile';   // navegador de escritorio con ventana angosta

    if (preg_match('~FBAN|FBAV|FB_IAB~', $ua)) $br = 'Facebook (app)';
    elseif (stripos($ua, 'Instagram') !== false) $br = 'Instagram (app)';
    elseif (preg_match('~Edg(e|A|iOS)?/~', $ua)) $br = 'Edge';
    elseif (preg_match('~OPR/|Opera~', $ua)) $br = 'Opera';
    elseif (stripos($ua, 'SamsungBrowser') !== false) $br = 'Samsung Internet';
    elseif (preg_match('~Firefox/|FxiOS~', $ua)) $br = 'Firefox';
    elseif (preg_match('~Chrome/|CriOS~', $ua)) $br = 'Chrome';
    elseif (stripos($ua, 'Safari') !== false) $br = 'Safari';
    else $br = 'Otro';

    if (preg_match('~iPhone|iPad|iPod~', $ua)) $os = 'iOS';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'CrOS') !== false) $os = 'ChromeOS';
    elseif (preg_match('~Macintosh|Mac OS~', $ua)) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    else $os = 'Otro';
    return [$dev, $br, $os];
}

/** Clasifica de dónde llegó la persona (referente y/o utm_source). */
function dicosa_an_source(string $refHost, string $utm, string $ownHost): string
{
    $utm = strtolower(trim($utm));
    if ($utm !== '') {
        $map = ['google' => 'Google', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'ig' => 'Instagram',
            'whatsapp' => 'WhatsApp', 'wa' => 'WhatsApp', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'qr' => 'Código QR', 'email' => 'Correo', 'newsletter' => 'Correo'];
        return $map[$utm] ?? ('Campaña: ' . substr(preg_replace('~[^a-z0-9 _\-]~', '', $utm), 0, 24));
    }
    $h = strtolower(preg_replace('~^www\.~', '', trim($refHost)));
    if ($h === '' || $h === strtolower(preg_replace('~^www\.~', '', $ownHost))) return 'Directo';
    if (preg_match('~(^|\.)google\.[a-z.]+$~', $h)) return str_starts_with($h, 'maps.') ? 'Google Maps' : 'Google';
    if (preg_match('~(^|\.)(bing|duckduckgo|yahoo|ecosia|brave)\.~', $h)) return 'Otros buscadores';
    if (preg_match('~(^|\.)(facebook|fb)\.(com|me)$|^l\.facebook|^lm\.facebook|^m\.facebook~', $h)) return 'Facebook';
    if (str_contains($h, 'instagram')) return 'Instagram';
    if (str_contains($h, 'whatsapp') || $h === 'wa.me') return 'WhatsApp';
    if (preg_match('~^(t\.co|twitter\.com|x\.com)$~', $h)) return 'X (Twitter)';
    if (str_contains($h, 'youtube') || $h === 'youtu.be') return 'YouTube';
    if (str_contains($h, 'tiktok')) return 'TikTok';
    return 'Otros sitios';
}

// ------------------------------------------------------------------ ubicación aproximada

/** Nombre de estado -> clave de 3-4 letras. */
function dicosa_an_state_code(string $name): string
{
    static $map = null;
    if ($map === null) {
        $m = [
            'AGS' => ['aguascalientes'], 'BC' => ['baja california'], 'BCS' => ['baja california sur'],
            'CAMP' => ['campeche'], 'CHIS' => ['chiapas'], 'CHIH' => ['chihuahua'],
            'CDMX' => ['ciudad de mexico', 'mexico city', 'distrito federal', 'cdmx', 'mexico d.f.'],
            'COAH' => ['coahuila', 'coahuila de zaragoza'], 'COL' => ['colima'], 'DGO' => ['durango'],
            'GTO' => ['guanajuato'], 'GRO' => ['guerrero'], 'HGO' => ['hidalgo'], 'JAL' => ['jalisco'],
            'MEX' => ['estado de mexico', 'state of mexico', 'edomex', 'mexico'],
            'MICH' => ['michoacan', 'michoacan de ocampo'], 'MOR' => ['morelos'], 'NAY' => ['nayarit'],
            'NL' => ['nuevo leon'], 'OAX' => ['oaxaca'], 'PUE' => ['puebla'], 'QRO' => ['queretaro', 'queretaro de arteaga'],
            'QROO' => ['quintana roo'], 'SLP' => ['san luis potosi'], 'SIN' => ['sinaloa'], 'SON' => ['sonora'],
            'TAB' => ['tabasco'], 'TAMPS' => ['tamaulipas'], 'TLAX' => ['tlaxcala'],
            'VER' => ['veracruz', 'veracruz de ignacio de la llave'], 'YUC' => ['yucatan'], 'ZAC' => ['zacatecas'],
        ];
        $map = [];
        foreach ($m as $code => $names) foreach ($names as $n) $map[$n] = $code;
    }
    $k = strtolower(trim($name));
    $k = strtr($k, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return $map[$k] ?? '';
}

function dicosa_an_is_public_ip(string $ip): bool
{
    return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** @return array{0:string,1:string,2:string} país (ISO-2), estado (clave) y ciudad. Vacíos si no se sabe. */
function dicosa_an_geo(string $ip, array $cfg): array
{
    // 1) Cabeceras de Cloudflare u otro proxy (si el sitio está detrás de uno): no cuesta nada.
    $c = strtoupper(substr((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 2));
    if (preg_match('~^[A-Z]{2}$~', $c) && $c !== 'XX' && $c !== 'T1') {
        $st = $c === 'MX' ? dicosa_an_state_code((string)($_SERVER['HTTP_CF_REGION'] ?? '')) : '';
        $city = substr((string)($_SERVER['HTTP_CF_IPCITY'] ?? ''), 0, 60);
        if ($city !== '' || $st !== '' || empty($cfg['geo_lookup'])) return [$c, $st, $city];
    }
    if (empty($cfg['geo_lookup']) || !dicosa_an_is_public_ip($ip)) return [$c ?? '', '', ''];

    // 2) Consulta con caché (30 días). Solo se guarda un código anónimo, nunca la IP.
    $key = substr(hash('sha256', $ip . '|' . dicosa_an_secret()), 0, 16);
    $path = dicosa_an_dir() . '/geo-cache.json';
    $cache = is_file($path) ? json_decode((string)@file_get_contents($path), true) : [];
    if (is_array($cache) && isset($cache[$key]) && time() - (int)$cache[$key][0] < 30 * 86400) {
        return [(string)$cache[$key][1], (string)$cache[$key][2], (string)$cache[$key][3]];
    }
    $res = ['', '', ''];
    $url = 'https://ipwho.is/' . rawurlencode($ip) . '?lang=es&fields=success,country_code,region,city';
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_USERAGENT => 'DICOSA-site/1.0']);
        $body = curl_exec($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 3]]));
    }
    $j = is_string($body) ? json_decode($body, true) : null;
    if (is_array($j) && !empty($j['success'])) {
        $cc = strtoupper(substr((string)($j['country_code'] ?? ''), 0, 2));
        $res = [$cc, $cc === 'MX' ? dicosa_an_state_code((string)($j['region'] ?? '')) : '', substr((string)($j['city'] ?? ''), 0, 60)];
        dicosa_an_json_update($path, function (array $cch) use ($key, $res) {
            $cch[$key] = [time(), $res[0], $res[1], $res[2]];
            if (count($cch) > 4000) { uasort($cch, fn($a, $b) => $b[0] <=> $a[0]); $cch = array_slice($cch, 0, 3000, true); }
            return $cch;
        });
    }
    return $res;
}

/** Sal secreta del sitio (para identificadores anónimos). Se crea sola. */
function dicosa_an_secret(): string
{
    static $s = null;
    if ($s !== null) return $s;
    $p = dicosa_an_dir() . '/secret.php';
    if (is_file($p)) { $v = include $p; if (is_string($v) && strlen($v) >= 32) return $s = $v; }
    $s = bin2hex(random_bytes(24));
    @file_put_contents($p, "<?php return '" . $s . "';\n", LOCK_EX);
    return $s;
}

// ------------------------------------------------------------------ almacenamiento

function dicosa_an_append(array $events): void
{
    if (!$events) return;
    $lines = '';
    foreach ($events as $e) $lines .= json_encode($e, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents(dicosa_an_dir() . '/' . gmdate('Y-m') . '.jsonl', $lines, FILE_APPEND | LOCK_EX);
}

/** Borra archivos mensuales más viejos que la retención. */
function dicosa_an_purge(): void
{
    $cfg = dicosa_an_config();
    $limit = time() - $cfg['retention_days'] * 86400;
    foreach (glob(dicosa_an_dir() . '/20??-??.jsonl') ?: [] as $f) {
        if (preg_match('~(\d{4})-(\d{2})\.jsonl$~', $f, $m) && gmmktime(0, 0, 0, (int)$m[2] + 1, 1, (int)$m[1]) < $limit) @unlink($f);
    }
}

/** Borra todos los datos de analítica (conserva configuración). */
function dicosa_an_wipe(): int
{
    $n = 0;
    foreach (glob(dicosa_an_dir() . '/*.jsonl') ?: [] as $f) { if (@unlink($f)) $n++; }
    foreach (glob(dicosa_an_dir() . '/cache/*') ?: [] as $f) @unlink($f);
    @unlink(dicosa_an_dir() . '/geo-cache.json');
    return $n;
}

function dicosa_an_clear_cache(): void
{
    foreach (glob(dicosa_an_dir() . '/cache/*') ?: [] as $f) @unlink($f);
}

/** Archivos que cubren [from,to] (marcas de tiempo UTC). El de ejemplo se lee siempre. */
function dicosa_an_files(int $from, int $to): array
{
    $files = [];
    for ($t = gmmktime(0, 0, 0, (int)gmdate('n', $from), 1, (int)gmdate('Y', $from)); $t <= $to; $t = gmmktime(0, 0, 0, (int)gmdate('n', $t) + 1, 1, (int)gmdate('Y', $t))) {
        $f = dicosa_an_dir() . '/' . gmdate('Y-m', $t) . '.jsonl';
        if (is_file($f)) $files[] = $f;
    }
    $demo = dicosa_an_dir() . '/demo.jsonl';
    if (is_file($demo)) $files[] = $demo;
    return $files;
}

/** Recorre eventos entre dos marcas de tiempo, en orden. */
function dicosa_an_each(int $from, int $to, callable $cb): void
{
    foreach (dicosa_an_files($from, $to) as $f) {
        $fh = @fopen($f, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            // Salto rápido: la línea empieza con {"t":1730000000,
            if (!preg_match('~^\{"t":(\d+),~', $line, $m)) continue;
            $t = (int)$m[1];
            if ($t < $from || $t > $to) continue;
            $ev = json_decode($line, true);
            if (is_array($ev)) $cb($ev);
        }
        fclose($fh);
    }
}

// ------------------------------------------------------------------ nombres

const DICOSA_AN_ACTIONS = [
    'call' => 'Llamar', 'wa' => 'WhatsApp', 'dir' => 'Cómo llegar', 'mail' => 'Correo',
    'bsel' => 'Eligió una sucursal', 'near' => 'Buscó la más cercana', 'loc' => 'Compartió su ubicación',
    'apply_open' => 'Abrió una vacante', 'apply_done' => 'Envió solicitud de empleo',
    'cut' => 'Exploró un corte', 'gal' => 'Abrió una foto', 'srch' => 'Buscó en sucursales', 'out' => 'Salió a otro sitio',
];
const DICOSA_AN_SECTIONS = [
    'inicio' => 'Portada', 'nosotros' => 'Nosotros', 'cortes' => 'Mapa de cortes', 'carnicerias' => 'Nuestras carnicerías',
    'galeria' => 'Galería', 'empresas' => 'Mayoreo para empresas', 'empleo' => 'Bolsa de trabajo', 'sucursales' => 'Sucursales',
];

function dicosa_an_branch_names(): array
{
    $out = ['demo-centro' => 'Sucursal Centro (ejemplo)', 'demo-pedregoso' => 'Sucursal Pedregoso (ejemplo)', 'demo-sanjuan' => 'Sucursal Santa Cruz (ejemplo)'];
    $list = json_decode((string)@file_get_contents(__DIR__ . '/../data/branches.json'), true);
    if (is_array($list)) foreach ($list as $b) if (!empty($b['id'])) $out[$b['id']] = (string)($b['name'] ?? $b['id']);
    return $out;
}

// ------------------------------------------------------------------ reportes

function dicosa_an_day_bounds(string $day): array
{
    $tz = new DateTimeZone(DICOSA_AN_TZ);
    $s = new DateTimeImmutable($day . ' 00:00:00', $tz);
    return [$s->getTimestamp(), $s->modify('+1 day')->getTimestamp() - 1];
}

function dicosa_an_new_acc(): array
{
    return [
        'v' => [], 's' => [], 'pv' => 0, 'daily' => [], 'newv' => [], 'act' => [], 'contactV' => [], 'applyV' => [], 'branchV' => [],
        'time' => [], 'depth' => [], 'br' => [], 'vattr' => [], 'heat' => array_fill(0, 7, array_fill(0, 24, 0)),
        'sec' => [], 'pages' => [], 'srch' => [], 'cut' => [], 'vac' => [], 'gal' => [], 'out' => [], 'src' => [], 'locs' => 0,
    ];
}

function dicosa_an_inc(&$a, string $k, int $n = 1): void { if (!is_array($a)) $a = []; $a[$k] = ($a[$k] ?? 0) + $n; }

/**
 * Reporte completo para un rango de días (en hora de México) + el periodo anterior de igual duración.
 * $branch: '' = todo el sitio; id = solo eventos ligados a esa sucursal.
 */
function dicosa_an_report(string $from, string $to, string $branch = ''): array
{
    $tz = new DateTimeZone(DICOSA_AN_TZ);
    [$curS] = dicosa_an_day_bounds($from);
    [, $curE] = dicosa_an_day_bounds($to);
    $days = (int)round(($curE + 1 - $curS) / 86400);
    $prevS = $curS - $days * 86400;
    $prevE = $curS - 1;
    $off = $tz->getOffset(new DateTimeImmutable('@' . $curS));   // México no usa horario de verano; un solo desfase basta

    $A = ['cur' => dicosa_an_new_acc(), 'prev' => dicosa_an_new_acc()];
    $sess = [];   // sid -> atributos del primer pageview (dispositivo, zona...)
    $demo = false;

    dicosa_an_each($prevS, $curE, function (array $e) use (&$A, &$sess, &$demo, $branch, $curS, $off) {
        if (!empty($e['demo'])) $demo = true;
        $sid = (string)($e['s'] ?? '');
        $vid = (string)($e['v'] ?? '');
        $type = (string)($e['e'] ?? '');
        if ($type === 'pv' && $sid !== '' && !isset($sess[$sid])) {
            $sess[$sid] = ['d' => $e['d'] ?? '', 'g' => $e['g'] ?? '', 'o' => $e['o'] ?? '', 'c' => $e['c'] ?? '', 'st' => $e['st'] ?? '', 'ci' => $e['ci'] ?? ''];
        }
        if ($branch !== '' && ($e['b'] ?? '') !== $branch) return;

        $t = (int)$e['t'];
        $acc = &$A[$t >= $curS ? 'cur' : 'prev'];
        $day = gmdate('Y-m-d', $t + $off);
        $acc['v'][$vid] = 1;
        $acc['s'][$sid] = 1;
        if (!isset($acc['daily'][$day])) $acc['daily'][$day] = ['v' => [], 's' => [], 'pv' => 0, 'ac' => 0];
        $dd = &$acc['daily'][$day];
        $dd['v'][$vid] = 1;
        $dd['s'][$sid] = 1;
        $b = (string)($e['b'] ?? '');

        switch ($type) {
            case 'pv':
                $acc['pv']++; $dd['pv']++;
                $p = (string)($e['p'] ?? '/');
                dicosa_an_inc($acc['pages'], $p);
                if (!empty($e['n'])) $acc['newv'][$vid] = 1;
                if (array_key_exists('r', $e)) {
                    dicosa_an_inc($acc['src'], (string)($e['r'] ?: 'Directo'));
                    $w = (int)gmdate('w', $t + $off); $h = (int)gmdate('G', $t + $off);
                    $acc['heat'][($w + 6) % 7][$h]++;   // lunes = 0
                }
                if ($b !== '') { dicosa_an_inc($acc['br'][$b], 'pv'); $acc['branchV'][$vid] = 1; }
                if (!isset($acc['vattr'][$vid]) && isset($sess[$sid])) $acc['vattr'][$vid] = $sess[$sid];
                break;
            case 'ac':
                $a = (string)($e['a'] ?? '');
                $x = (string)($e['x'] ?? '');
                $dd['ac']++;
                dicosa_an_inc($acc['act'], $a);
                if (in_array($a, ['call', 'wa', 'dir', 'mail'], true)) $acc['contactV'][$vid] = 1;
                if ($a === 'apply_done') $acc['applyV'][$vid] = 1;
                if ($a === 'loc') $acc['locs']++;
                if (in_array($a, ['bsel', 'bnear', 'near'], true)) $acc['branchV'][$vid] = 1;
                if ($b !== '') {
                    $k = ['bsel' => 'bsel', 'bnear' => 'bnear', 'call' => 'call', 'dir' => 'dir', 'wa' => 'wa', 'mail' => 'mail'][$a] ?? null;
                    if ($k) dicosa_an_inc($acc['br'][$b], $k);
                }
                if ($a === 'srch' && $x !== '') dicosa_an_inc($acc['srch'], $x);
                if ($a === 'cut' && $x !== '') dicosa_an_inc($acc['cut'], $x);
                if ($a === 'apply_open' && $x !== '') dicosa_an_inc($acc['vac'], $x);
                if ($a === 'out' && $x !== '') dicosa_an_inc($acc['out'], $x);
                if ($a === 'gal' && $x !== '') dicosa_an_inc($acc['gal'], $x);
                break;
            case 'sc':
                $x = (string)($e['x'] ?? '');
                if ($x !== '') $acc['sec'][$x][$sid] = 1;
                break;
            case 'lv':
                $acc['time'][$sid] = min(3600, ($acc['time'][$sid] ?? 0) + max(0, (int)($e['u'] ?? 0)));
                $acc['depth'][$sid] = max($acc['depth'][$sid] ?? 0, min(100, (int)($e['k'] ?? 0)));
                break;
        }
        unset($dd, $acc);
    });

    $names = dicosa_an_branch_names();
    $sum = function (array $a) {
        $nV = count($a['v']); $nS = count($a['s']);
        $times = array_filter($a['time'], fn($x) => $x > 0);
        $contact = count($a['contactV']);
        return [
            'visitors' => $nV, 'sessions' => $nS, 'pageviews' => $a['pv'],
            'new' => count($a['newv']), 'returning' => max(0, $nV - count($a['newv'])),
            'avg_time' => $times ? (int)round(array_sum($times) / count($times)) : 0,
            'depth' => $a['depth'] ? (int)round(array_sum($a['depth']) / count($a['depth'])) : 0,
            'contacts' => ($a['act']['call'] ?? 0) + ($a['act']['wa'] ?? 0) + ($a['act']['dir'] ?? 0) + ($a['act']['mail'] ?? 0),
            'contact_visitors' => $contact,
            'contact_rate' => $nV ? round($contact * 100 / $nV, 1) : 0,
            'applies' => $a['act']['apply_done'] ?? 0,
            'apply_visitors' => count($a['applyV']),
            'branch_visitors' => count($a['branchV']),
            'pages_per_session' => $nS ? round($a['pv'] / $nS, 1) : 0,
        ];
    };

    $series = function (array $a, int $startTs, int $n) use ($off) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $d = gmdate('Y-m-d', $startTs + $off + $i * 86400);
            $r = $a['daily'][$d] ?? null;
            $out[] = ['d' => $d, 'visitors' => $r ? count($r['v']) : 0, 'sessions' => $r ? count($r['s']) : 0, 'pageviews' => $r['pv'] ?? 0, 'actions' => $r['ac'] ?? 0];
        }
        return $out;
    };

    $top = function (array $m, int $n = 12) {
        arsort($m);
        $o = [];
        foreach (array_slice($m, 0, $n, true) as $k => $v) $o[] = ['k' => (string)$k, 'n' => $v];
        return $o;
    };

    $cur = $A['cur'];
    // Dimensiones por visitante (cada persona cuenta una vez, con los datos de su primera visita del periodo)
    $dim = ['d' => [], 'g' => [], 'o' => [], 'c' => [], 'st' => [], 'ci' => []];
    foreach ($cur['vattr'] as $at) foreach ($dim as $k => $_) dicosa_an_inc($dim[$k], (string)($at[$k] ?? '') ?: '—');

    $branches = [];
    foreach ($cur['br'] as $id => $m) {
        $row = ['id' => $id, 'name' => $names[$id] ?? $id] + array_merge(['pv' => 0, 'bsel' => 0, 'bnear' => 0, 'call' => 0, 'dir' => 0, 'wa' => 0, 'mail' => 0], $m);
        $row['interest'] = $row['pv'] + $row['bsel'] + $row['bnear'];
        $row['contacts'] = $row['call'] + $row['dir'] + $row['wa'] + $row['mail'];
        $branches[] = $row;
    }
    usort($branches, fn($a, $b) => ($b['interest'] + $b['contacts'] * 2) <=> ($a['interest'] + $a['contacts'] * 2));

    $sections = [];
    $nSess = max(1, count($cur['s']));
    foreach (DICOSA_AN_SECTIONS as $id => $label) $sections[] = ['k' => $id, 'label' => $label, 'n' => count($cur['sec'][$id] ?? []), 'pct' => round(count($cur['sec'][$id] ?? []) * 100 / $nSess, 1)];

    $actions = [];
    foreach (DICOSA_AN_ACTIONS as $k => $label) $actions[] = ['k' => $k, 'label' => $label, 'n' => $cur['act'][$k] ?? 0, 'prev' => $A['prev']['act'][$k] ?? 0];

    return [
        'range' => ['from' => $from, 'to' => $to, 'days' => $days, 'prev_from' => gmdate('Y-m-d', $prevS + $off), 'prev_to' => gmdate('Y-m-d', $prevE + $off)],
        'branch' => $branch,
        'demo' => $demo,
        'totals' => $sum($cur), 'prev' => $sum($A['prev']),
        'daily' => $series($cur, $curS, $days), 'prev_daily' => $series($A['prev'], $prevS, $days),
        'actions' => $actions,
        'branches' => $branches,
        'geo' => ['country' => $top($dim['c'], 12), 'state' => $top($dim['st'], 40), 'city' => $top($dim['ci'], 15)],
        'devices' => ['device' => $top($dim['d']), 'browser' => $top($dim['g'], 8), 'os' => $top($dim['o'], 8)],
        'sources' => $top($cur['src'], 10),
        'heat' => $cur['heat'],
        'sections' => $sections,
        'pages' => $top($cur['pages'], 10),
        'searches' => $top($cur['srch'], 10), 'cuts' => $top($cur['cut'], 8), 'vacancies' => $top($cur['vac'], 8),
        'outbound' => $top($cur['out'], 6),
        'generated' => time(),
    ];
}

/** Personas con actividad en los últimos 5 minutos (lee solo el final del archivo del mes). */
function dicosa_an_live(string $branch = ''): int
{
    $f = dicosa_an_dir() . '/' . gmdate('Y-m') . '.jsonl';
    $set = [];
    $since = time() - 300;
    if (is_file($f)) {
        $size = filesize($f);
        $fh = fopen($f, 'r');
        if ($fh) {
            fseek($fh, max(0, $size - 120000));
            if ($size > 120000) fgets($fh);
            while (($line = fgets($fh)) !== false) {
                if (!preg_match('~^\{"t":(\d+),~', $line, $m) || (int)$m[1] < $since) continue;
                $e = json_decode($line, true);
                if (!is_array($e) || !empty($e['demo'])) continue;
                if ($branch !== '' && ($e['b'] ?? '') !== $branch) continue;
                $set[(string)($e['v'] ?? '')] = 1;
            }
            fclose($fh);
        }
    }
    return count($set);
}

/** Datos de ejemplo (solo para presentar el panel). Se guardan aparte y se pueden quitar con un clic. */
function dicosa_an_seed_demo(int $days = 75): int
{
    mt_srand(20260101);
    $branchIds = ['demo-centro', 'demo-pedregoso', 'demo-sanjuan'];
    $real = json_decode((string)@file_get_contents(__DIR__ . '/../data/branches.json'), true);
    if (is_array($real)) foreach ($real as $b) if (!empty($b['id']) && !empty($b['active'])) array_unshift($branchIds, $b['id']);
    $branchIds = array_values(array_unique($branchIds));
    $w = fn(array $m) => (function () use ($m) { $r = mt_rand(1, (int)array_sum($m)); foreach ($m as $k => $x) { $r -= $x; if ($r <= 0) return $k; } return array_key_first($m); })();
    $cities = [['MX', 'QRO', 'San Juan del Río', 52], ['MX', 'QRO', 'Querétaro', 14], ['MX', 'QRO', 'Tequisquiapan', 6], ['MX', 'QRO', 'Pedro Escobedo', 5],
        ['MX', 'CDMX', 'Ciudad de México', 7], ['MX', 'MEX', 'Toluca', 3], ['MX', 'HGO', 'Pachuca', 2], ['MX', 'GTO', 'Celaya', 3], ['MX', 'GTO', 'León', 2],
        ['MX', 'JAL', 'Guadalajara', 2], ['MX', 'NL', 'Monterrey', 1], ['MX', 'PUE', 'Puebla', 1], ['US', '', 'Houston', 1], ['US', '', 'Chicago', 1]];
    $hourW = [0, 0, 0, 0, 1, 3, 8, 12, 14, 14, 13, 11, 9, 8, 7, 6, 6, 5, 4, 3, 2, 1, 1, 0];
    $dayW = [1.0, 0.95, 0.95, 1.0, 1.15, 1.45, 0.8];   // lun..dom
    $now = time();
    $tz = new DateTimeZone(DICOSA_AN_TZ);
    $out = '';
    $n = 0;
    $vids = [];
    $ref = ['Directo' => 34, 'Google' => 30, 'Google Maps' => 14, 'Facebook' => 10, 'WhatsApp' => 6, 'Instagram' => 4, 'Otros sitios' => 2];
    for ($d = $days; $d >= 0; $d--) {
        $base = new DateTimeImmutable('today -' . $d . ' days', $tz);
        $wd = ((int)$base->format('N')) - 1;
        $growth = 0.7 + 0.5 * (($days - $d) / $days);
        $visits = (int)round((38 + mt_rand(-8, 10)) * $dayW[$wd] * $growth);
        for ($i = 0; $i < $visits; $i++) {
            $hr = $w(array_combine(range(0, 23), $hourW));
            $t = $base->getTimestamp() + $hr * 3600 + mt_rand(0, 3599);
            if ($t > $now) continue;
            $isNew = mt_rand(1, 100) <= 64 || !$vids;
            $vid = $isNew ? 'demo' . bin2hex(random_bytes(5)) : $vids[array_rand($vids)];
            if ($isNew) $vids[] = $vid;
            $sid = 'ds' . bin2hex(random_bytes(5));
            $geo = $cities[array_rand($cities)]; $r = mt_rand(1, 100); $acc = 0;
            foreach ($cities as $c) { $acc += $c[3]; if ($r <= $acc) { $geo = $c; break; } }
            $dev = $w(['mobile' => 78, 'desktop' => 19, 'tablet' => 3]);
            $os = $dev === 'desktop' ? $w(['Windows' => 80, 'macOS' => 18, 'Linux' => 2]) : ($w(['Android' => 72, 'iOS' => 28]));
            $br = $dev === 'desktop' ? $w(['Chrome' => 70, 'Edge' => 18, 'Firefox' => 7, 'Safari' => 5]) : ($os === 'iOS' ? $w(['Safari' => 70, 'Facebook (app)' => 20, 'Chrome' => 10]) : $w(['Chrome' => 74, 'Facebook (app)' => 14, 'Samsung Internet' => 9, 'Instagram (app)' => 3]));
            $at = ['v' => $vid, 's' => $sid, 'd' => $dev, 'g' => $br, 'o' => $os, 'c' => $geo[0], 'st' => $geo[1], 'ci' => $geo[2], 'demo' => 1];
            $src = $w($ref);
            $out .= json_encode(['t' => $t, 'e' => 'pv', 'p' => '/', 'r' => $src === 'Directo' ? '' : $src] + ($isNew ? ['n' => 1] : []) + $at) . "\n"; $n++;
            $tt = $t;
            // secciones
            $secs = array_keys(DICOSA_AN_SECTIONS); $reach = mt_rand(2, 8);
            foreach (array_slice($secs, 0, $reach) as $s) { if ($s !== 'inicio' && mt_rand(1, 100) > 78) break; $tt += mt_rand(3, 20); $out .= json_encode(['t' => $tt, 'e' => 'sc', 'x' => $s] + $at) . "\n"; $n++; }
            // sucursales
            $b = '';
            if (mt_rand(1, 100) <= 46) {
                $b = $branchIds[min(count($branchIds) - 1, (int)(pow(mt_rand(0, 100) / 100, 1.7) * count($branchIds)))];
                $tt += mt_rand(5, 40);
                $out .= json_encode(['t' => $tt, 'e' => 'ac', 'a' => 'bsel', 'b' => $b, 'x' => $b] + $at) . "\n"; $n++;
                if (mt_rand(1, 100) <= 14) { $out .= json_encode(['t' => $tt + 2, 'e' => 'pv', 'p' => '/sucursal/' . $b, 'b' => $b] + $at) . "\n"; $n++; }
                $r = mt_rand(1, 100);
                if ($r <= 20) { $out .= json_encode(['t' => $tt + 5, 'e' => 'ac', 'a' => 'dir', 'b' => $b] + $at) . "\n"; $n++; }
                elseif ($r <= 36) { $out .= json_encode(['t' => $tt + 5, 'e' => 'ac', 'a' => 'call', 'b' => $b] + $at) . "\n"; $n++; }
                elseif ($r <= 42) { $out .= json_encode(['t' => $tt + 5, 'e' => 'ac', 'a' => 'wa', 'b' => $b] + $at) . "\n"; $n++; }
                if (mt_rand(1, 100) <= 7) { $out .= json_encode(['t' => $tt + 3, 'e' => 'ac', 'a' => 'srch', 'x' => $w(['pedregoso' => 4, 'centro' => 3, 'santa cruz' => 3, 'cerca de mi' => 2, 'san juan' => 2])] + $at) . "\n"; $n++; }
            } else {
                $r = mt_rand(1, 100);
                if ($r <= 9) { $out .= json_encode(['t' => $tt + 9, 'e' => 'ac', 'a' => 'call'] + $at) . "\n"; $n++; }
                elseif ($r <= 17) { $out .= json_encode(['t' => $tt + 9, 'e' => 'ac', 'a' => 'wa'] + $at) . "\n"; $n++; }
            }
            if (mt_rand(1, 100) <= 18) { $out .= json_encode(['t' => $tt + 4, 'e' => 'ac', 'a' => 'cut', 'x' => $w(['Costilla' => 6, 'Lomo' => 5, 'Arrachera' => 5, 'Pierna' => 3, 'Chuleta' => 3, 'Espaldilla' => 2, 'Aguayón' => 2])] + $at) . "\n"; $n++; }
            if (mt_rand(1, 100) <= 9) { $out .= json_encode(['t' => $tt + 6, 'e' => 'ac', 'a' => 'gal', 'x' => (string)mt_rand(1, 6)] + $at) . "\n"; $n++; }
            if (mt_rand(1, 100) <= 7) {
                $out .= json_encode(['t' => $tt + 8, 'e' => 'ac', 'a' => 'apply_open', 'x' => $w(['Carnicero/a' => 5, 'Cajero/a' => 3, 'Auxiliar de carnicería' => 4, 'Repartidor/a' => 2])] + $at) . "\n"; $n++;
                if (mt_rand(1, 100) <= 40) { $out .= json_encode(['t' => $tt + 90, 'e' => 'ac', 'a' => 'apply_done'] + $at) . "\n"; $n++; }
            }
            $out .= json_encode(['t' => $tt + 6, 'e' => 'lv', 'u' => mt_rand(8, 260), 'k' => mt_rand(15, 100)] + $at) . "\n"; $n++;
        }
    }
    // El archivo debe ir ordenado por tiempo para que el recorrido por sesión funcione.
    $lines = array_filter(explode("\n", $out));
    usort($lines, fn($a, $b) => (int)substr($a, 5) <=> (int)substr($b, 5));
    file_put_contents(dicosa_an_dir() . '/demo.jsonl', implode("\n", $lines) . "\n", LOCK_EX);
    dicosa_an_clear_cache();
    return $n;
}
