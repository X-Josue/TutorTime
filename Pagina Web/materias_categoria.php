<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}
if (($_SESSION['tipo'] ?? '') !== 'Alumno') {
    header("Location: menu.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$categoria_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$categoria = null;
$materias = [];
$error_catalogo = false;

try {
    if ($categoria_id) {
        $stmt = $conexion->prepare("SELECT id, nombre, descripcion FROM categorias WHERE id = ? AND activa = 1");
        $stmt->bind_param("i", $categoria_id);
        $stmt->execute();
        $categoria = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($categoria) {
            $stmt = $conexion->prepare(
                "SELECT m.id, m.nombre, m.descripcion,
                        COUNT(DISTINCT o.id) AS total_tutores
                 FROM catalogo_materias m
                 LEFT JOIN tutor_ofertas o
                   ON o.materia_id = m.id AND o.estado = 'publicada'
                 LEFT JOIN usuarios u
                   ON u.id = o.tutor_id AND u.rol = 'Tutor'
                 WHERE m.categoria_id = ? AND m.activa = 1
                 GROUP BY m.id, m.nombre, m.descripcion
                 ORDER BY m.nombre"
            );
            $stmt->bind_param("i", $categoria_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($fila = $res->fetch_assoc()) {
                $materias[] = $fila;
            }
            $stmt->close();
        }
    }
} catch (mysqli_sql_exception $e) {
    $error_catalogo = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $categoria ? e($categoria['nombre']) : 'Materias' ?> | TutorTime</title>
    <link rel="stylesheet" href="css/catalogo_tutorias.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="menu.php">TutorTime</a>
    <div class="header-actions">
        <a class="button button-light" href="catalogo_materias.php">Categorías</a>
        <a class="button button-light" href="menu.php">Menú</a>
    </div>
</header>

<main class="page-container">
    <nav class="breadcrumbs"><a href="catalogo_materias.php">Categorías</a> <span>›</span> <?= $categoria ? e($categoria['nombre']) : 'Materia' ?></nav>

    <?php if ($error_catalogo): ?>
        <section class="notice notice-warning">
            <h2>El catálogo aún no está conectado</h2>
            <p>Las tablas del catálogo y de las ofertas de tutores todavía no están disponibles en esta base de datos.</p>
        </section>
    <?php elseif (!$categoria): ?>
        <section class="notice">
            <h2>No encontramos esa categoría</h2>
            <p>Regresa al catálogo y selecciona una categoría disponible.</p>
            <a class="button" href="catalogo_materias.php">Ver categorías</a>
        </section>
    <?php else: ?>
        <section class="hero hero-compact">
            <p class="eyebrow">Categoría</p>
            <h1><?= e($categoria['nombre']) ?></h1>
            <p><?= e($categoria['descripcion'] ?: 'Selecciona la materia en la que necesitas apoyo.') ?></p>
        </section>

        <section class="section-heading">
            <h2>Materias disponibles</h2>
            <p>Elige una materia para consultar los tutores que la ofrecen.</p>
        </section>

        <?php if (!$materias): ?>
            <section class="notice">
                <h3>Aún no hay materias en esta categoría</h3>
                <p>Pronto se podrán publicar nuevas ofertas de tutoría.</p>
            </section>
        <?php else: ?>
            <div class="subject-list">
                <?php foreach ($materias as $materia): ?>
                    <article class="subject-card">
                        <div>
                            <h3><?= e($materia['nombre']) ?></h3>
                            <p><?= e($materia['descripcion'] ?: 'Solicita apoyo personalizado en esta materia.') ?></p>
                            <span class="muted"><?= (int)$materia['total_tutores'] ?> ofertas publicadas</span>
                        </div>
                        <a class="button" href="tutores_materia.php?id=<?= (int)$materia['id'] ?>">Ver tutores</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
