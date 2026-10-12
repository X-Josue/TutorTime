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

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conexion = new mysqli("localhost", "root", "", "tutortime");
$conexion->set_charset("utf8mb4");

$categorias = [];
$error_catalogo = false;

try {
    $resultado = $conexion->query(
        "SELECT c.id, c.nombre, c.descripcion, COUNT(m.id) AS total_materias
         FROM categorias c
         LEFT JOIN catalogo_materias m
           ON m.categoria_id = c.id AND m.activa = 1
         WHERE c.activa = 1
         GROUP BY c.id, c.nombre, c.descripcion
         ORDER BY c.nombre"
    );
    while ($fila = $resultado->fetch_assoc()) {
        $categorias[] = $fila;
    }
} catch (mysqli_sql_exception $e) {
    // Ocurre, por ejemplo, si aún no se ha ejecutado la migración SQL.
    $error_catalogo = true;
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buscar tutorías | TutorTime</title>
    <link rel="stylesheet" href="css/catalogo_tutorias.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="menu.php">TutorTime</a>
    <div class="header-actions">
        <span class="welcome">Hola, <?= e($_SESSION['nombre'] ?? 'Alumno') ?></span>
        <a class="button button-light" href="menu.php">Volver al menú</a>
    </div>
</header>

<main class="page-container">
    <section class="hero">
        <p class="eyebrow">Tutorías personalizadas</p>
        <h1>¿Qué necesitas aprender?</h1>
        <p>Explora las materias, encuentra un tutor y solicita una sesión individual.</p>
    </section>

    <?php if ($error_catalogo): ?>
        <section class="notice notice-warning">
            <h2>El catálogo aún no está disponible</h2>
            <p>Las páginas ya están preparadas, pero faltan las tablas nuevas de TutorTime.
               Pide a la persona encargada de la base de datos que ejecute
               <strong>tutortime_migracion_catalogo_tutorias.sql</strong> en la base de datos de pruebas.</p>
        </section>
    <?php elseif (!$categorias): ?>
        <section class="notice">
            <h2>Próximamente habrá materias disponibles</h2>
            <p>Aún no hay categorías activas en el catálogo.</p>
        </section>
    <?php else: ?>
        <section class="section-heading">
            <h2>Explorar por categoría</h2>
            <p>Selecciona el área que corresponde a tu duda.</p>
        </section>
        <div class="category-grid">
            <?php foreach ($categorias as $categoria): ?>
                <a class="category-card" href="materias_categoria.php?id=<?= (int)$categoria['id'] ?>">
                    <span class="category-icon" aria-hidden="true"><?= e(match ($categoria['nombre']) {
                        'Programación y desarrollo' => '⌘',
                        'Matemáticas' => '∑',
                        'Ciencias básicas' => '⚛',
                        'Redes y sistemas' => '⌁',
                        'Bases de datos' => '▤',
                        'Idiomas' => '文',
                        default => '▣'
                    }) ?></span>
                    <h3><?= e($categoria['nombre']) ?></h3>
                    <p><?= e($categoria['descripcion'] ?: 'Explora las materias de esta categoría.') ?></p>
                    <span class="card-footer"><?= (int)$categoria['total_materias'] ?> materias <span aria-hidden="true">→</span></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
