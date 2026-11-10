<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';

// Si ya inició sesión, mándalo directo al panel.
if (dicosa_current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if (isset($_GET['expired'])) $error = 'Tu sesión se cerró por inactividad. Inicia sesión de nuevo.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $key = dicosa_login_key($username);

    if (dicosa_login_blocked($key)) {
        $error = 'Demasiados intentos. Espera 10 minutos e inténtalo de nuevo.';
        dicosa_audit('login_bloqueado', $username, ['username' => $username, 'role' => '']);
    } else {
        $u = dicosa_user_find_by('username', $username);
        // Se verifica siempre una contraseña (aunque el usuario no exista) para que el tiempo de respuesta no delate si el usuario existe.
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsaltuOq6ZB4mO2xX3xHn1x1o5YlCqk0Dm';
        $ok = password_verify($password, $hash) && $u && !empty($u['active']);

        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['dicosa_uid'] = $u['id'];
            $_SESSION['dicosa_last'] = $_SESSION['dicosa_born'] = time();
            unset($_SESSION['csrf']);
            dicosa_login_clear($key);
            $all = dicosa_users_load();
            foreach ($all as &$x) { if ($x['id'] === $u['id']) { $x['last_login'] = date('Y-m-d H:i:s'); if ($password === DICOSA_DEFAULT_PASSWORD) $x['must_change'] = true; } }
            unset($x);
            dicosa_users_save($all);
            dicosa_audit('login', 'Inició sesión', $u);
            header('Location: dashboard.php');
            exit;
        }

        dicosa_login_fail($key);
        dicosa_audit('login_fallido', $username, ['username' => $username, 'role' => '']);
        sleep(1);
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Iniciar sesión — Panel DICOSA</title>
<link rel="icon" type="image/png" href="../assets/logo.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Inter:wght@400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
</head>
<body class="login-body">

<div class="login-card">
  <img src="../assets/logo.png" alt="DICOSA" class="login-logo">
  <h1>Panel DICOSA</h1>
  <p class="login-sub">Inicia sesión para editar tu página</p>

  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <form method="POST" class="login-form">
    <label>
      <span>Usuario</span>
      <input type="text" name="username" autocomplete="username" required autofocus>
    </label>
    <label>
      <span>Contraseña</span>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit" class="btn-login">Entrar</button>
  </form>
</div>

</body>
</html>
