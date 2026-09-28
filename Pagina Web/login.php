<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - TutorTime</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- CSS personalizado -->
    <link rel="stylesheet" href="css/login.css">
</head>
<body>

<div class="container d-flex justify-content-center align-items-center vh-100">

    <div class="login-card text-center">

        <h2 class="title">TutorTime</h2>
        <p class="subtitle">Inicia sesión para continuar</p>

        <form action="login_process.php" method="POST">

            <div class="form-group">
                <input type="email" name="correo" class="form-control input-apple" placeholder="Correo electrónico" required>
            </div>

            <div class="form-group mt-3">
                <input type="password" name="contrasena" class="form-control input-apple" placeholder="Contraseña" required>
            </div>

            <button type="submit" class="btn btn-dark w-100 mt-4 btn-apple">
                Iniciar sesión
            </button>

        </form>

        <div class="mt-3">
            <a href="registro.php" class="link">¿No tienes cuenta? Regístrate</a>
        </div>

    </div>

</div>

</body>
</html>