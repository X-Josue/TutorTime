<?php
session_start();

$conexion = new mysqli("localhost", "root", "", "tutortime");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$correo = $_POST['correo'];
$contrasena = $_POST['contrasena'];

$stmt = $conexion->prepare("SELECT * FROM usuarios WHERE correo = ?");
$stmt->bind_param("s", $correo);
$stmt->execute();

$resultado = $stmt->get_result();

if ($resultado->num_rows > 0) {

    $usuario = $resultado->fetch_assoc();

    if (password_verify($contrasena, $usuario['contrasena'])) {

        // 🔥 SESIONES CORRECTAS
        $_SESSION['id'] = $usuario['id'];
        $_SESSION['correo'] = $usuario['correo'];
        $_SESSION['tipo'] = $usuario['rol'];
        $_SESSION['nombre'] = $usuario['nombres']; 

        header("Location: menu.php");
        exit();

    } else {
        echo "Contraseña incorrecta";
    }

} else {
    echo "Usuario no encontrado";
}

$stmt->close();
$conexion->close();
?>