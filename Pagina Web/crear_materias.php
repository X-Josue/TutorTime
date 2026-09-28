<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

// SOLO TUTORES
if ($_SESSION['tipo'] != 'Tutor') {
    die("Acceso denegado");
}

$conexion = new mysqli("localhost", "root", "", "tutortime");

if ($conexion->connect_error) {
    die("Error de conexión");
}

// CREAR MATERIA
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $nombre = $_POST['nombre'];
    $descripcion = $_POST['descripcion'];

    // Generar código único
    $codigo = strtoupper(substr($nombre, 0, 3)) . "-" . rand(1000, 9999);

    $tutor_id = $_SESSION['id'];

    // Insertar materia
    $stmt = $conexion->prepare("
        INSERT INTO materias (tutor_id, nombre, descripcion, codigo)
        VALUES (?, ?, ?, ?)
    ");

    $stmt->bind_param("isss", $tutor_id, $nombre, $descripcion, $codigo);

    if ($stmt->execute()) {

        // Obtener ID de la materia
        $materia_id = $stmt->insert_id;

        // Crear chat automáticamente
        $stmtChat = $conexion->prepare("
            INSERT INTO chats (materia_id)
            VALUES (?)
        ");

        $stmtChat->bind_param("i", $materia_id);
        $stmtChat->execute();

        // Volver al menú
        header("Location: menu.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Crear Clase</title>

    <link rel="stylesheet" href="css/crear_materias.css">
</head>
<body>

<!-- HEADER -->
<header class="header">

    <!-- IZQUIERDA -->
    <div class="user">
        <?php echo $_SESSION['nombre']; ?>
    </div>

    <!-- CENTRO -->
    <div class="logo">
        TutorTime
    </div>

    <!-- DERECHA -->
    <div>
        <a href="menu.php" class="back-btn">
            ← Volver
        </a>
    </div>

</header>

<!-- CONTENIDO -->
<div class="container">

    <div class="create-card">

        <h2>Crear Clase</h2>

        <form method="POST">

            <input
                type="text"
                name="nombre"
                placeholder="Nombre de la clase"
                class="input"
                required
            >

            <textarea
                name="descripcion"
                placeholder="Descripción de la clase"
                class="input textarea"
                required
            ></textarea>

            <button type="submit" class="btn">
                Crear clase
            </button>

        </form>

    </div>

</div>

</body>
</html>