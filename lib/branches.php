<?php
// branches.php — lectura/escritura de sucursales y su "clave fija" (code).
declare(strict_types=1);

function dicosa_branches_path(): string { return __DIR__ . '/../data/branches.json'; }

/** "Pedregoso Norte" -> "pedregoso-norte" */
function dicosa_slug(string $s): string
{
    $s = strtolower(trim($s));
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false) $s = strtolower($t); }
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string)$s, '-');
}

function dicosa_branches_save(array $list): bool
{
    return file_put_contents(dicosa_branches_path(),
        json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/** Lee las sucursales. A las que no tienen clave fija (versiones anteriores) se les asigna una y se guarda. */
function dicosa_branches_load(): array
{
    $list = json_decode((string)@file_get_contents(dicosa_branches_path()), true);
    if (!is_array($list)) return [];
    $used = [];
    foreach ($list as $b) if (!empty($b['code'])) $used[$b['code']] = true;
    $changed = false;
    foreach ($list as &$b) {
        if (!empty($b['code'])) continue;
        $base = ($b['id'] ?? '') === 'b-central' ? 'central' : (dicosa_slug((string)($b['name'] ?? '')) ?: 'sucursal');
        $base = substr($base, 0, 30);
        $code = $base; $n = 2;
        while (isset($used[$code])) $code = substr($base, 0, 27) . '-' . $n++;
        $used[$code] = true; $b['code'] = $code; $changed = true;
    }
    unset($b);
    if ($changed) dicosa_branches_save($list);
    return $list;
}

function dicosa_branch_find(string $id): ?array
{
    foreach (dicosa_branches_load() as $b) if (($b['id'] ?? '') === $id) return $b;
    return null;
}
