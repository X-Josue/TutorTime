
<?php
require_once __DIR__ . '/google_config.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login - TutorTime</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- CSS personalizado -->
    <link rel="stylesheet" href="css/login.css">

    <!-- Google Identity Services -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>

<body>

<div class="container d-flex justify-content-center align-items-center vh-100">

    <div class="login-card text-center">

        <h2 class="title">TutorTime</h2>
        <p class="subtitle">Inicia sesión para continuar</p>

        <?php if (isset($_GET['error'])): ?>
            <p class="text-danger" role="alert">
                Correo o contraseña incorrectos.
            </p>
        <?php endif; ?>

        <!-- Inicio de sesión tradicional -->
        <form action="login_process.php" method="POST">

            <div class="form-group">
                <input
                    type="email"
                    name="correo"
                    class="form-control input-apple"
                    placeholder="Correo electrónico"
                    required
                >
            </div>

            <div class="form-group mt-3">
                <input
                    type="password"
                    name="contrasena"
                    class="form-control input-apple"
                    placeholder="Contraseña"
                    required
                >
            </div>

            <button type="submit" class="btn btn-dark w-100 mt-4 btn-apple">
                Iniciar sesión
            </button>

        </form>

        <!-- Separador -->
        <div class="d-flex align-items-center my-4">
            <hr class="flex-grow-1">
            <span class="px-3 text-muted">O continúa con</span>
            <hr class="flex-grow-1">
        </div>

        <!-- Configuración de Google -->
        <div
            id="g_id_onload"
            data-client_id="<?= htmlspecialchars(GOOGLE_CLIENT_ID, ENT_QUOTES, 'UTF-8') ?>"
            data-login_uri="http://localhost/TutorTime/Pagina%20Web/google_login.php"
            data-auto_prompt="false">
        </div>

        <!-- Botón de Google -->
        <div class="d-flex justify-content-center">
            <div
                class="g_id_signin"
                data-type="standard"
                data-size="large"
                data-theme="outline"
                data-text="continue_with"
                data-shape="rectangular"
                data-logo_alignment="left">
            </div>
        </div>

        <!-- Registro -->
        <div class="mt-3">
            <a href="registro.php" class="link">
                ¿No tienes cuenta? Regístrate
            </a>
        </div>

    </div>

</div>

</body>
</html>
