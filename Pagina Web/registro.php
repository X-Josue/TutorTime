<?php
session_start();
require_once __DIR__ . '/conexion.php';

$error = '';
$nombres = '';
$apellidos = '';
$correo = '';
$rol = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombres = trim($_POST['nombres'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $correo = trim($_POST['correo'] ?? '');
    $contrasena = $_POST['contrasena'] ?? '';
    $rol = $_POST['rol'] ?? '';

    if ($nombres === '' || $apellidos === '' || $correo === '' || $contrasena === '') {
        $error = 'Completa todos los campos.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe un correo electrónico válido.';
    } elseif (!in_array($rol, ['Alumno', 'Tutor'], true)) {
        $error = 'Selecciona un rol válido.';
    } elseif (strlen($contrasena) < 8) {
        $error = 'La contraseña debe tener al menos 8 caracteres.';
    } else {
        $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE correo = ? LIMIT 1");
        $stmt->bind_param("s", $correo);
        $stmt->execute();
        $correo_existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($correo_existe) {
            $error = 'Ese correo ya está registrado.';
        } else {
            $hash = password_hash($contrasena, PASSWORD_DEFAULT);
            $stmt = $conexion->prepare(
                "INSERT INTO usuarios (nombres, apellidos, correo, contrasena, rol)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("sssss", $nombres, $apellidos, $correo, $hash, $rol);
            $stmt->execute();

            session_regenerate_id(true);
            $_SESSION['id'] = (int)$stmt->insert_id;
            $_SESSION['correo'] = $correo;
            $_SESSION['tipo'] = $rol;
            $_SESSION['nombre'] = $nombres;
            $stmt->close();

            header('Location: menu.php');
            exit();
        }
    }
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro - TutorTime</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/registro.css">
</head>
<body>

<div class="container d-flex justify-content-center align-items-center vh-100">

    <div class="login-card text-center">

        <h2 class="title">Crear cuenta</h2>
        <p class="subtitle">Únete a TutorTime</p>

        <?php if ($error !== ''): ?>
            <p class="text-danger" role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST">

            <input type="text" name="nombres" class="form-control input-apple mb-2" placeholder="Nombres" maxlength="100" value="<?= e($nombres) ?>" required>

            <input type="text" name="apellidos" class="form-control input-apple mb-2" placeholder="Apellidos" maxlength="100" value="<?= e($apellidos) ?>" required>

            <input type="email" name="correo" class="form-control input-apple mb-2" placeholder="Correo" maxlength="100" value="<?= e($correo) ?>" required>

            <input type="password" name="contrasena" class="form-control input-apple mb-2" placeholder="Contraseña" minlength="8" required>

            <select name="rol" class="form-control input-apple mb-3" required>
                <option value="">Selecciona tu rol</option>
                <option value="Alumno" <?= $rol === "Alumno" ? "selected" : "" ?>>Alumno</option>
                <option value="Tutor" <?= $rol === "Tutor" ? "selected" : "" ?>>Tutor</option>
            </select>

            <button type="submit" class="btn btn-dark w-100 btn-apple">
                Registrarse
            </button>

        </form>

        <div class="mt-3">
            <a href="login.php" class="link">¿Ya tienes cuenta? Inicia sesión</a>
        </div>

    </div>

</div>

</body>
</html>