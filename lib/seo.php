<?php
// seo.php — utilidades para las páginas que se generan con datos (sucursales, sitemap).
declare(strict_types=1);

require_once __DIR__ . '/branches.php';

const DICOSA_DEFAULT_SITE_URL = 'https://midicosa.com.mx';

/** Dirección pública del sitio, sin diagonal final. Se puede fijar con "site_url" en data/site-data.json. */
function dicosa_site_url(): string
{
    $d = json_decode((string)@file_get_contents(__DIR__ . '/../data/site-data.json'), true);
    $u = is_array($d) ? trim((string)($d['site_url'] ?? '')) : '';
    return rtrim($u !== '' ? $u : DICOSA_DEFAULT_SITE_URL, '/');
}

function dicosa_site_data(): array
{
    $d = json_decode((string)@file_get_contents(__DIR__ . '/../data/site-data.json'), true);
    return is_array($d) ? $d : [];
}

function dicosa_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Sucursales visibles al público: activas y con clave fija. */
function dicosa_public_branches(): array
{
    return array_values(array_filter(dicosa_branches_load(), fn($b) => ($b['active'] ?? true) !== false && !empty($b['code'])));
}

function dicosa_branch_by_code(string $code): ?array
{
    foreach (dicosa_branches_load() as $b) if (($b['code'] ?? '') === $code) return $b;
    return null;
}

function dicosa_trim_text(string $s, int $max): string
{
    $s = trim(preg_replace('/\s+/', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    return rtrim(mb_substr($s, 0, $max - 1), " ,.;:-") . '…';
}
