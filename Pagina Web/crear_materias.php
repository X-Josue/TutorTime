<?php
session_start();

if (!isset($_SESSION['id'])) {
    header('Location: login.php');
    exit();
}
if (($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header('Location: menu.php');
    exit();
}

require_once __DIR__ . '/conexion.php';

$error = '';
$nombre = '';
$descripcion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');

    if ($nombre === '' || $descripcion === '') {
        $error = 'Completa el nombre y la descripción de la clase.';
    } elseif (mb_strlen($nombre) > 100 || mb_strlen($descripcion) > 1000) {
        $error = 'El nombre o la descripción son demasiado largos.';
    } else {
        $prefijo = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $nombre), 0, 3));
        $codigo = ($prefijo !== '' ? $prefijo : 'CLS') . '-' . random_int(1000, 9999);
        $tutor_id = (int)$_SESSION['id'];

        try {
            $conexion->begin_transaction();

            $stmt = $conexion->prepare(
                "INSERT INTO materias (tutor_id, nombre, descripcion, codigo)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->bind_param("isss", $tutor_id, $nombre, $descripcion, $codigo);
            $stmt->execute();
            $materia_id = (int)$stmt->insert_id;
            $stmt->close();

            $stmt = $conexion->prepare("INSERT INTO chats (materia_id) VALUES (?)");
            $stmt->bind_param("i", $materia_id);
            $stmt->execute();
            $stmt->close();

            $conexion->commit();
            header('Location: menu.php');
            exit();
        } catch (Throwable $error_bd) {
            $conexion->rollback();
            error_log('TutorTime: no se pudo crear la clase: ' . $error_bd->getMessage());
            $error = 'No se pudo crear la clase. Inténtalo de nuevo.';
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
    <title>Crear Clase</title>

    <link rel="stylesheet" href="css/crear_materias.css">
</head>
<body>

<!-- HEADER -->
<header class="header">

    <!-- IZQUIERDA -->
    <div class="user">
        <?= e($_SESSION['nombre'] ?? 'Tutor') ?>
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

        <?php if ($error !== ''): ?>
            <p role="alert"><?= e($error) ?></p>
        <?php endif; ?>

        <form method="POST">

            <input
                type="text"
                name="nombre"
                placeholder="Nombre de la clase"
                class="input"
                maxlength="100"
                value="<?= e($nombre) ?>"
                required
            >

            <textarea
                name="descripcion"
                placeholder="Descripción de la clase"
                class="input textarea"
                maxlength="1000"
                required
            ><?= e($descripcion) ?></textarea>

            <button type="submit" class="btn">
                Crear clase
            </button>

        </form>

    </div>

</div>

</body>
</html>