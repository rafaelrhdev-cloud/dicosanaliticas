<?php
// auth.php — se incluye al inicio de cada página o endpoint del panel.
// Revisa que haya sesión, carga al usuario (con su rol) y ofrece
// dicosa_can() / dicosa_require_perm() para controlar los permisos.

declare(strict_types=1);

require_once __DIR__ . '/../lib/users.php';

// Inactividad máxima antes de cerrar la sesión, y duración máxima total.
const DICOSA_IDLE_SECONDS = 3600;        // 60 min sin tocar nada
const DICOSA_MAX_SESSION_SECONDS = 43200; // 12 horas

function dicosa_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)DICOSA_MAX_SESSION_SECONDS);
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure' => dicosa_is_https(),   // bajo HTTPS la cookie nunca viaja sin cifrar
    ]);
}

// Cierre automático por inactividad o por antigüedad. Las consultas de fondo (X-Background)
// no cuentan como actividad, para que una pestaña olvidada no mantenga la sesión abierta.
if (!empty($_SESSION['dicosa_uid'])) {
    $now = time();
    $last = (int)($_SESSION['dicosa_last'] ?? $now);
    $born = (int)($_SESSION['dicosa_born'] ?? $now);
    if ($now - $last > DICOSA_IDLE_SECONDS || $now - $born > DICOSA_MAX_SESSION_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        if (empty($_SERVER['HTTP_X_BACKGROUND'])) $_SESSION['dicosa_last'] = $now;
        $_SESSION['dicosa_born'] = $born;
    }
}

/** Token anti-CSRF de la sesión actual (se entrega al panel y se exige en cada POST). */
function dicosa_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return (string)$_SESSION['csrf'];
}

/** Usuario con sesión iniciada (se vuelve a leer en cada petición: si lo desactivan o le cambian el rol, aplica al instante). */
function dicosa_current_user(): ?array
{
    if (array_key_exists('dicosa_user', $GLOBALS)) return $GLOBALS['dicosa_user'];
    $user = null;
    if (!empty($_SESSION['dicosa_uid'])) {
        $u = dicosa_user_find_by('id', (string)$_SESSION['dicosa_uid']);
        if ($u && !empty($u['active']) && isset(dicosa_roles()[$u['role']])) $user = $u;
        else { unset($_SESSION['dicosa_uid']); }
    }
    return $GLOBALS['dicosa_user'] = $user;
}

function dicosa_deny(int $code, string $message, bool $json): void
{
    if ($json) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        header('Location: ' . ($code === 401 ? 'login.php' : 'dashboard.php'));
    }
    exit;
}

// Todo POST del panel (menos el login) debe traer el token de la sesión.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'login.php') {
    $sentToken = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? ''));
    if (empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $sentToken)) {
        dicosa_deny(empty($_SESSION['dicosa_uid']) ? 401 : 403,
            empty($_SESSION['dicosa_uid']) ? 'Tu sesión expiró. Vuelve a iniciar sesión.' : 'Solicitud no válida. Recarga la página e inténtalo de nuevo.', true);
    }
}

function dicosa_must_change(): bool
{
    $u = dicosa_current_user();
    return $u !== null && !empty($u['must_change']);
}

function dicosa_require_login(bool $isJsonEndpoint = false): void
{
    if (!dicosa_current_user()) dicosa_deny(401, 'No has iniciado sesión.', $isJsonEndpoint);
    // Con contraseña provisional solo se puede cambiar la contraseña, ver el panel y salir.
    if (dicosa_must_change() && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['change-password.php', 'dashboard.php', 'logout.php'], true))
        dicosa_deny(403, 'Primero cambia tu contraseña provisional (Ajustes).', $isJsonEndpoint);
}

function dicosa_can(string $perm): bool
{
    $u = dicosa_current_user();
    return $u !== null && empty($u['must_change']) && dicosa_role_has($u['role'], $perm);
}

function dicosa_require_perm(string $perm, bool $isJsonEndpoint = true): void
{
    dicosa_require_login($isJsonEndpoint);
    if (!dicosa_can($perm)) dicosa_deny(403, 'Tu usuario no tiene permiso para hacer esto.', $isJsonEndpoint);
}
