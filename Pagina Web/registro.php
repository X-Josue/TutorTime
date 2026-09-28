<?php
session_start();

$conexion = new mysqli("localhost", "root", "", "tutortime");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $nombres = $_POST['nombres'];
    $apellidos = $_POST['apellidos'];
    $correo = $_POST['correo'];
    $contrasena = $_POST['contrasena'];
    $rol = $_POST['rol'];

    // Verificar duplicado
    $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE correo = ?");
    $stmt->bind_param("s", $correo);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $error = "Correo ya registrado";
    } else {

        $hash = password_hash($contrasena, PASSWORD_DEFAULT);

        $stmt = $conexion->prepare("INSERT INTO usuarios (nombres, apellidos, correo, contrasena, rol) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $nombres, $apellidos, $correo, $hash, $rol);

        if ($stmt->execute()) {

            $_SESSION['id'] = $stmt->insert_id;
            $_SESSION['correo'] = $correo;
            $_SESSION['tipo'] = $rol;
            $_SESSION['nombre'] = $nombres; 

            header("Location: menu.php");
            exit();

        } else {
            $error = "Error al registrar";
        }
    }

    $stmt->close();
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

        <?php if(isset($error)) echo "<p style='color:red;'>$error</p>"; ?>

        <form method="POST">

            <input type="text" name="nombres" class="form-control input-apple mb-2" placeholder="Nombres" required>

            <input type="text" name="apellidos" class="form-control input-apple mb-2" placeholder="Apellidos" required>

            <input type="email" name="correo" class="form-control input-apple mb-2" placeholder="Correo" required>

            <input type="password" name="contrasena" class="form-control input-apple mb-2" placeholder="Contraseña" required>

            <select name="rol" class="form-control input-apple mb-3" required>
                <option value="">Selecciona tu rol</option>
                <option value="Alumno">Alumno</option>
                <option value="Tutor">Tutor</option>
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