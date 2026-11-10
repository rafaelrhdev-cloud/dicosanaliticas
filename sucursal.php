<?php
// Página pública de una sucursal (/sucursal/clave). Se genera con los datos que se editan en el panel,
// para que cada local tenga su propia página con dirección, teléfono, horario y datos para Google.
declare(strict_types=1);
require __DIR__ . '/lib/seo.php';

$code = strtolower(trim((string)($_GET['code'] ?? '')));
$b = preg_match('/^[a-z0-9-]{2,30}$/', $code) ? dicosa_branch_by_code($code) : null;

$base = dicosa_site_url();
$root = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/sucursal.php')), '/\\') . '/';   // por si el sitio está en una subcarpeta (XAMPP)
$site = dicosa_site_data();
$all = dicosa_public_branches();

if (!$b || ($b['active'] ?? true) === false) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="es-MX"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex"><title>Sucursal no encontrada — DICOSA</title><base href="<?= dicosa_h($root) ?>">
<link rel="stylesheet" href="styles.css"></head><body style="display:grid;place-items:center;min-height:100vh;text-align:center;padding:24px">
<div><h1 style="font-family:var(--ff-display);font-size:2rem">Esa sucursal no existe</h1>
<p style="margin:12px 0 24px;color:var(--carbon-70)">Puede que haya cambiado de dirección o que ya no esté disponible.</p>
<a class="btn btn-primary" href="./#sucursales">Ver todas las sucursales</a></div></body></html><?php
    exit;
}

$name = (string)$b['name'];
$address = trim((string)($b['address'] ?? ''));
$phone = trim((string)($b['phone'] ?? ''));
$phoneTel = preg_replace('/[^\d+]/', '', $phone);
$hours = trim((string)($b['hours'] ?? ''));
$isMain = !empty($b['is_main']);
$hasGeo = isset($b['lat'], $b['lng']) && $b['lat'] !== null && $b['lng'] !== null && $b['lat'] !== '' && $b['lng'] !== '';
$inSjr = stripos($address, 'San Juan del R') !== false;
$city = $inSjr ? 'San Juan del Río, Qro.' : '';

$mapQuery = $hasGeo ? ($b['lat'] . ',' . $b['lng']) : trim($address . ' ' . $name);
$mapEmbed = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery) . '&output=embed';
$mapDir = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($mapQuery);
$wa = preg_replace('/\D/', '', (string)($site['whatsapp_number'] ?? ''));

$url = $base . '/sucursal/' . $b['code'];
$shortName = trim(preg_split('/\s+[—–-]\s+/u', $name)[0]);
$title = dicosa_trim_text('Carnicería en San Juan del Río | DICOSA ' . $shortName, 70);
if (!$inSjr) $title = dicosa_trim_text('Carnicería DICOSA ' . $name, 70);
$descParts = ['Carnicería DICOSA ' . $name . ($city ? ' en ' . rtrim($city, '.') : '') . '.'];
if ($address) $descParts[] = $address . '.';
if ($hours) $descParts[] = 'Horario: ' . $hours . '.';
$descParts[] = 'Res y cerdo frescos, cadena de frío' . ($phone ? '. Llama al ' . $phone : '') . '.';
$desc = dicosa_trim_text(implode(' ', $descParts), 158);

$ld = [
    '@context' => 'https://schema.org',
    '@type' => $isMain ? ['GroceryStore', 'WholesaleStore'] : 'GroceryStore',
    '@id' => $url . '#sucursal',
    'name' => 'DICOSA — ' . $name,
    'url' => $url,
    'image' => $base . '/assets/og-image.jpg',
    'logo' => $base . '/assets/logo.png',
    'description' => $desc,
    'parentOrganization' => ['@type' => 'Organization', 'name' => 'DICOSA', 'url' => $base . '/', 'foundingDate' => '1987'],
];
if ($phone) $ld['telephone'] = (strlen($phoneTel) === 10 ? '+52' : '') . $phoneTel;
if ($address) {
    $ld['address'] = ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressCountry' => 'MX']
        + ($inSjr ? ['addressLocality' => 'San Juan del Río', 'addressRegion' => 'Querétaro'] : []);
}
if ($hasGeo) $ld['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => (float)$b['lat'], 'longitude' => (float)$b['lng']];
$ld['hasMap'] = $mapDir;
$ld['areaServed'] = $inSjr ? 'San Juan del Río, Querétaro' : 'Querétaro';

$crumbs = [
    '@context' => 'https://schema.org', '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'DICOSA', 'item' => $base . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Sucursales', 'item' => $base . '/#sucursales'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $name, 'item' => $url],
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;
?><!DOCTYPE html>
<html lang="es-MX" data-branch="<?= dicosa_h((string)$b['id']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<base href="<?= dicosa_h($root) ?>">
<title><?= dicosa_h($title) ?></title>
<meta name="description" content="<?= dicosa_h($desc) ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<link rel="canonical" href="<?= dicosa_h($url) ?>">
<meta name="theme-color" content="#A6182B">
<meta property="og:type" content="website">
<meta property="og:locale" content="es_MX">
<meta property="og:site_name" content="DICOSA">
<meta property="og:title" content="<?= dicosa_h($title) ?>">
<meta property="og:description" content="<?= dicosa_h($desc) ?>">
<meta property="og:url" content="<?= dicosa_h($url) ?>">
<meta property="og:image" content="<?= dicosa_h($base . '/assets/og-image.jpg') ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap"></noscript>
<link rel="stylesheet" href="styles.css">
<style>
.bp-top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 22px;background:var(--hueso);border-bottom:1px solid var(--marmol)}
.bp-brand{display:flex;align-items:center;gap:10px;font-family:var(--ff-display);font-weight:700;font-size:1.2rem}
.bp-brand img{width:38px;height:38px;object-fit:contain}
.bp-back{font-family:var(--ff-mono);font-size:.72rem;letter-spacing:.06em;text-transform:uppercase;color:var(--rojo-dicosa);text-decoration:underline;text-underline-offset:4px}
.bp-hero{padding:56px 22px 36px;max-width:1040px;margin:0 auto}
.bp-crumb{font-family:var(--ff-mono);font-size:.7rem;letter-spacing:.05em;text-transform:uppercase;color:var(--carbon-70);margin-bottom:18px}
.bp-crumb a{text-decoration:underline;text-underline-offset:3px}
.bp-hero h1{font-family:var(--ff-display);font-size:clamp(2rem,5vw,3.4rem);line-height:1.08;max-width:760px}
.bp-hero h1 em{font-style:italic;color:var(--rojo-dicosa)}
.bp-lead{margin-top:16px;max-width:620px;color:var(--carbon-70);font-size:1.05rem;line-height:1.65}
.bp-badge{display:inline-block;margin-bottom:16px;padding:6px 12px;border-radius:20px;background:var(--rojo-dicosa);color:var(--hueso);font-family:var(--ff-mono);font-size:.66rem;letter-spacing:.06em;text-transform:uppercase}
.bp-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.2fr);gap:28px;padding:0 22px 56px;max-width:1040px;margin:0 auto;align-items:start}
.bp-card{background:#fff;border:1px solid var(--marmol);border-radius:10px;padding:26px}
.bp-card dl{display:grid;gap:18px}
.bp-card dt{font-family:var(--ff-mono);font-size:.66rem;letter-spacing:.07em;text-transform:uppercase;color:var(--carbon-70);margin-bottom:4px}
.bp-card dd{font-size:1.02rem;line-height:1.5}
.bp-card dd a{text-decoration:underline;text-underline-offset:3px}
.bp-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
.bp-actions .btn{justify-content:center;flex:1 1 150px}
.bp-map{border-radius:10px;overflow:hidden;border:1px solid var(--marmol);background:var(--hueso-2);min-height:340px}
.bp-map iframe{width:100%;height:100%;min-height:380px;border:0;display:block}
.bp-others{padding:40px 22px 64px;max-width:1040px;margin:0 auto;border-top:1px solid var(--marmol)}
.bp-others h2{font-family:var(--ff-display);font-size:1.5rem;margin-bottom:18px}
.bp-others ul{list-style:none;display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}
.bp-others li a{display:block;padding:14px 16px;background:#fff;border:1px solid var(--marmol);border-radius:8px;transition:border-color .25s}
.bp-others li a:hover{border-color:var(--rojo-dicosa)}
.bp-others li strong{display:block;font-family:var(--ff-display)}
.bp-others li small{color:var(--carbon-70);font-size:.8rem}
.bp-foot{padding:26px 22px;text-align:center;background:var(--carbon);color:rgba(250,246,240,.7);font-size:.85rem}
.bp-foot a{color:var(--hueso);text-decoration:underline;text-underline-offset:3px}
@media (max-width:820px){.bp-grid{grid-template-columns:minmax(0,1fr)}.bp-hero{padding:36px 20px 24px}.bp-card{padding:22px 18px}.bp-map iframe{min-height:320px}}
</style>
<script type="application/ld+json"><?= json_encode($ld, $jsonFlags) ?></script>
<script type="application/ld+json"><?= json_encode($crumbs, $jsonFlags) ?></script>
</head>
<body>

<div class="bp-top">
  <a class="bp-brand" href="./"><img src="assets/logo-128.png" alt="DICOSA" width="38" height="38">DICOSA</a>
  <a class="bp-back" href="./#sucursales">← Todas las sucursales</a>
</div>

<main>
  <section class="bp-hero">
    <nav class="bp-crumb" aria-label="Ruta"><a href="./">Inicio</a> › <a href="./#sucursales">Sucursales</a> › <?= dicosa_h($name) ?></nav>
    <?php if ($isMain): ?><span class="bp-badge">Central de mayoreo</span><?php endif; ?>
    <h1>Carnicería DICOSA <em><?= dicosa_h($name) ?></em><?= $city ? ' en ' . dicosa_h($city) : '' ?></h1>
    <p class="bp-lead">Carne de res y cerdo fresca, con cadena de frío cuidada del corral a tu cocina. Somos DICOSA, una carnicería que sirve desde 1987<?= $city ? ' a ' . dicosa_h(rtrim($city, '.')) : '' ?>. <?= $isMain ? 'Aquí también atendemos pedidos al mayoreo para restaurantes, hoteles y negocios.' : 'Pasa por tus cortes o llámanos para apartarlos.' ?></p>
  </section>

  <div class="bp-grid">
    <div class="bp-card">
      <dl>
        <?php if ($address): ?><div><dt>Dirección</dt><dd><address style="font-style:normal"><?= dicosa_h($address) ?></address></dd></div><?php endif; ?>
        <?php if ($phone): ?><div><dt>Teléfono</dt><dd><a href="tel:<?= dicosa_h($phoneTel) ?>"><?= dicosa_h($phone) ?></a></dd></div><?php endif; ?>
        <?php if ($hours): ?><div><dt>Horario</dt><dd><?= dicosa_h($hours) ?></dd></div><?php endif; ?>
      </dl>
      <div class="bp-actions">
        <a class="btn btn-primary" href="<?= dicosa_h($mapDir) ?>" target="_blank" rel="noopener">Cómo llegar</a>
        <?php if ($phone): ?><a class="btn btn-ghost" href="tel:<?= dicosa_h($phoneTel) ?>">Llamar</a><?php endif; ?>
        <?php if ($wa): ?><a class="btn btn-ghost" href="https://wa.me/<?= dicosa_h($wa) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
      </div>
    </div>
    <div class="bp-map"><iframe src="<?= dicosa_h($mapEmbed) ?>" loading="lazy" title="Mapa de <?= dicosa_h($name) ?>" referrerpolicy="no-referrer-when-downgrade"></iframe></div>
  </div>

  <?php $others = array_values(array_filter($all, fn($x) => $x['code'] !== $b['code'])); if ($others): ?>
  <section class="bp-others">
    <h2>Otras sucursales DICOSA</h2>
    <ul>
      <?php foreach ($others as $o): ?>
      <li><a href="sucursal/<?= dicosa_h($o['code']) ?>"><strong><?= dicosa_h($o['name']) ?></strong><small><?= dicosa_h(dicosa_trim_text((string)($o['address'] ?? ''), 70)) ?></small></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>
</main>

<div class="bp-foot">© <?= date('Y') ?> DICOSA · <a href="./">Volver al sitio principal</a></div>
<script src="analytics.js" defer></script>
<script src="wa-lead.js" defer></script>
</body>
</html>
