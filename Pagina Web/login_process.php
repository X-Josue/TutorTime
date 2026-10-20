<?php
session_start();
require_once __DIR__ . '/conexion.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit();
}

$correo = trim($_POST['correo'] ?? '');
$contrasena = $_POST['contrasena'] ?? '';

$stmt = $conexion->prepare(
    "SELECT id, correo, nombres, rol, contrasena FROM usuarios WHERE correo = ? LIMIT 1"
);
$stmt->bind_param("s", $correo);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuario || !password_verify($contrasena, $usuario['contrasena'])) {
    header('Location: login.php?error=1');
    exit();
}

session_regenerate_id(true);
$_SESSION['id'] = (int)$usuario['id'];
$_SESSION['correo'] = $usuario['correo'];
$_SESSION['tipo'] = $usuario['rol'];
$_SESSION['nombre'] = $usuario['nombres'];

header('Location: menu.php');
exit();
