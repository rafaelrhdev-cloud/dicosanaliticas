<?php
// Mapa del sitio para buscadores: se arma solo con las sucursales activas.
declare(strict_types=1);
require __DIR__ . '/lib/seo.php';

header('Content-Type: application/xml; charset=utf-8');
$base = dicosa_site_url();
$home = @filemtime(__DIR__ . '/index.html') ?: time();
$brMtime = @filemtime(__DIR__ . '/data/branches.json') ?: time();

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '  <url><loc>' . dicosa_h($base . '/') . '</loc><lastmod>' . date('Y-m-d', $home) . '</lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
foreach (dicosa_public_branches() as $b) {
    echo '  <url><loc>' . dicosa_h($base . '/sucursal/' . $b['code']) . '</loc><lastmod>' . date('Y-m-d', $brMtime) . '</lastmod><changefreq>monthly</changefreq><priority>' . (!empty($b['is_main']) ? '0.9' : '0.8') . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
