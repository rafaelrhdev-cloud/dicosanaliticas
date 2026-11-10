<?php
// users.php — usuarios, roles, bitácora y control de intentos de login.
// Se guarda todo en archivos JSON dentro de data/ (más adelante se puede
// pasar a MySQL sin cambiar la lógica de permisos de abajo).

declare(strict_types=1);

/** Contraseña de fábrica: quien entre con ella debe cambiarla. */
const DICOSA_DEFAULT_PASSWORD = 'Dicosa2026!';

const DICOSA_ALL_PERMS = [
    'contact.view', 'contact.edit',
    'gallery.view', 'gallery.edit',
    'branches.view', 'branches.create', 'branches.edit', 'branches.delete',
    'vacancies.view', 'vacancies.edit', 'vacancies.delete',
    'candidates.view', 'candidates.edit',
    'analytics.view', 'analytics.manage',
    'users.manage', 'audit.view', 'config.tech',
];

/** Roles del panel, de mayor a menor nivel. */
function dicosa_roles(): array
{
    $all = DICOSA_ALL_PERMS;
    return [
        'dev' => [
            'label' => 'Programador', 'rank' => 100,
            'desc' => 'Acceso total, incluida la configuración técnica y los demás Programadores.',
            'perms' => $all,
        ],
        'owner' => [
            'label' => 'Dueño', 'rank' => 80,
            'desc' => 'Administra todo el sitio y controla los usuarios (administradores y menores). Ve la bitácora y la analítica (puede configurarla).',
            'perms' => array_values(array_diff($all, ['config.tech'])),
        ],
        'admin' => [
            'label' => 'Administrador', 'rank' => 60,
            'desc' => 'Gestiona contacto, galería, sucursales (incluso eliminar), vacantes y candidatos. Ve la analítica del sitio.',
            'perms' => ['contact.view', 'contact.edit', 'gallery.view', 'gallery.edit', 'branches.view', 'branches.create',
                'branches.edit', 'branches.delete', 'vacancies.view', 'vacancies.edit', 'vacancies.delete',
                'candidates.view', 'candidates.edit', 'analytics.view'],
        ],
        'rh' => [
            'label' => 'Recursos humanos', 'rank' => 40,
            'desc' => 'Vacantes y candidatos (ver CVs y cambiar estatus). Recibe el aviso de nuevas solicitudes.',
            'perms' => ['vacancies.view', 'vacancies.edit', 'vacancies.delete', 'candidates.view', 'candidates.edit'],
        ],
        'marketing' => [
            'label' => 'Marketing', 'rank' => 20,
            'desc' => 'Solo lectura: consulta la galería, contacto, sucursales, la analítica y las zonas de los visitantes. No puede cambiar nada.',
            'perms' => ['gallery.view', 'contact.view', 'branches.view', 'analytics.view'],
        ],
        'viewer' => [
            'label' => 'Solo lectura', 'rank' => 10,
            'desc' => 'Solo lectura: revisa todo el contenido, los candidatos y la analítica, pero no puede cambiar nada.',
            'perms' => ['contact.view', 'gallery.view', 'branches.view', 'vacancies.view', 'candidates.view', 'analytics.view'],
        ],
    ];
}

function dicosa_role_has(string $role, string $perm): bool
{
    $r = dicosa_roles()[$role] ?? null;
    return $r !== null && in_array($perm, $r['perms'], true);
}

/** Roles que un usuario con $actorRole puede crear / editar / desactivar. */
function dicosa_manageable_roles(string $actorRole): array
{
    $roles = dicosa_roles();
    if (!isset($roles[$actorRole]) || !dicosa_role_has($actorRole, 'users.manage')) return [];
    if ($actorRole === 'dev') return array_keys($roles);
    $rank = $roles[$actorRole]['rank'];
    return array_values(array_filter(array_keys($roles), fn($k) => $roles[$k]['rank'] < $rank));
}

// ---------------------------------------------------------------- usuarios

function dicosa_users_path(): string { return __DIR__ . '/../data/users.json'; }

function dicosa_users_save(array $users): bool
{
    return file_put_contents(
        dicosa_users_path(),
        json_encode(array_values($users), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}

/** Lee los usuarios. La primera vez crea el Programador a partir de credentials.php. */
function dicosa_users_load(): array
{
    $path = dicosa_users_path();
    $list = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
    if (is_array($list) && count($list) > 0) {
        // Roles retirados (Gerente de sucursal y Editor): esas cuentas pasan a Solo lectura, sin perder su acceso.
        $changed = false;
        foreach ($list as &$u) {
            if (in_array($u['role'] ?? '', ['manager', 'editor'], true)) { $u['role'] = 'viewer'; unset($u['branch_id']); $changed = true; }
        }
        unset($u);
        if ($changed) dicosa_users_save($list);
        return $list;
    }

    $credsPath = __DIR__ . '/../data/credentials.php';
    $creds = is_file($credsPath) ? include $credsPath : null;
    $username = is_array($creds) && !empty($creds['username']) ? (string)$creds['username'] : 'admin';
    $hash = is_array($creds) && !empty($creds['password_hash'])
        ? (string)$creds['password_hash']
        : password_hash('Dicosa2026!', PASSWORD_DEFAULT);

    $seed = [[
        'id' => 'u-' . bin2hex(random_bytes(5)),
        'username' => strtolower($username),
        'name' => 'Programador',
        'email' => '',
        'role' => 'dev',
        'active' => true,
        'notify' => false,
        'must_change' => true,
        'password_hash' => $hash,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => null,
    ]];
    dicosa_users_save($seed);
    return $seed;
}

function dicosa_user_find_by(string $field, string $value): ?array
{
    foreach (dicosa_users_load() as $u) {
        if (($u[$field] ?? null) === $value) return $u;
    }
    return null;
}

/** Datos del usuario que se pueden mandar al navegador (sin hash). */
function dicosa_user_public(array $u): array
{
    return [
        'id' => $u['id'], 'username' => $u['username'], 'name' => $u['name'] ?? '',
        'email' => $u['email'] ?? '', 'role' => $u['role'], 'active' => !empty($u['active']),
        'notify' => !empty($u['notify']), 'branch_id' => (string)($u['branch_id'] ?? ''), 'created_at' => $u['created_at'] ?? null, 'last_login' => $u['last_login'] ?? null,
    ];
}

/** Correos de quienes deben recibir el aviso de nuevas solicitudes de empleo. */
function dicosa_notify_recipients(string $branchId = ''): array
{
    $out = [];
    foreach (dicosa_users_load() as $u) {
        $email = trim((string)($u['email'] ?? ''));
        if (!empty($u['active']) && !empty($u['notify']) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[strtolower($email)] = ['email' => $email, 'name' => (string)($u['name'] ?? '')];
        }
    }
    return array_values($out);
}

// ---------------------------------------------------------------- bitácora

function dicosa_audit_path(): string { return __DIR__ . '/../data/audit.log'; }

function dicosa_audit(string $action, string $detail = '', ?array $user = null): void
{
    $user = $user ?? ($GLOBALS['dicosa_user'] ?? null);
    $line = json_encode([
        'ts' => date('Y-m-d H:i:s'),
        'user' => $user['username'] ?? '—',
        'role' => $user['role'] ?? '',
        'action' => $action,
        'detail' => mb_substr_safe($detail, 300),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    $path = dicosa_audit_path();
    if (is_file($path) && filesize($path) > 2 * 1024 * 1024) @rename($path, $path . '.1');
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function mb_substr_safe(string $s, int $len): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $len) : substr($s, 0, $len);
}

function dicosa_audit_read(int $limit = 150): array
{
    $path = dicosa_audit_path();
    if (!is_file($path)) return [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -$limit);
    $out = [];
    foreach (array_reverse($lines) as $l) {
        $r = json_decode($l, true);
        if (is_array($r)) $out[] = $r;
    }
    return $out;
}

// ---------------------------------------------------------- intentos de login

function dicosa_login_key(string $username): string
{
    return strtolower($username) . '|' . ($_SERVER['REMOTE_ADDR'] ?? '');
}

function dicosa_login_attempts(): array
{
    $p = __DIR__ . '/../data/login-attempts.json';
    $a = is_file($p) ? json_decode((string)file_get_contents($p), true) : [];
    return is_array($a) ? $a : [];
}

function dicosa_login_attempts_save(array $a): void
{
    @file_put_contents(__DIR__ . '/../data/login-attempts.json', json_encode($a), LOCK_EX);
}

function dicosa_login_blocked(string $key): bool
{
    $recent = array_filter(dicosa_login_attempts()[$key] ?? [], fn($t) => $t > time() - 600);
    return count($recent) >= 5;
}

function dicosa_login_fail(string $key): void
{
    $a = dicosa_login_attempts();
    foreach ($a as $k => $times) {
        $a[$k] = array_values(array_filter($times, fn($t) => $t > time() - 600));
        if (!$a[$k]) unset($a[$k]);
    }
    $a[$key][] = time();
    dicosa_login_attempts_save($a);
}

function dicosa_login_clear(string $key): void
{
    $a = dicosa_login_attempts();
    unset($a[$key]);
    dicosa_login_attempts_save($a);
}


/** Ya no hay roles limitados a una sucursal: todos ven todas (null). Se conserva por compatibilidad. */
function dicosa_scope_branch(?array $user): ?string
{
    return null;
}
