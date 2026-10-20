<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}
if (($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header("Location: menu.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$materias = [];
$error_tablas = false;
$error_formulario = '';
$nombre_oferta = '';
$descripcion = '';
$materia_seleccionada = (int)($_POST['materia_id'] ?? 0);
$temas_texto = '';

try {
    $res = $conexion->query(
        "SELECT m.id, m.nombre, c.nombre AS categoria
         FROM catalogo_materias m
         INNER JOIN categorias c ON c.id = m.categoria_id
         WHERE m.activa = 1 AND c.activa = 1
         ORDER BY c.nombre, m.nombre"
    );
    while ($fila = $res->fetch_assoc()) {
        $materias[] = $fila;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $nombre_oferta = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $temas_texto = trim($_POST['temas'] ?? '');
        $tutor_id = (int)$_SESSION['id'];

        if ($materia_id <= 0 || $nombre_oferta === '') {
            $error_formulario = 'Selecciona una materia y escribe un título para tu oferta.';
        } else {
            $check = $conexion->prepare("SELECT id FROM catalogo_materias WHERE id = ? AND activa = 1");
            $check->bind_param("i", $materia_id);
            $check->execute();
            $existe = $check->get_result()->num_rows > 0;
            $check->close();

            if (!$existe) {
                $error_formulario = 'La materia seleccionada no está disponible.';
            } else {
                $lineas = preg_split('/\r\n|\r|\n/', $temas_texto);
                $temas = [];
                foreach ($lineas as $linea) {
                    $tema = trim($linea);
                    if ($tema !== '') {
                        $temas[] = mb_substr($tema, 0, 150, 'UTF-8');
                    }
                }
                $temas = array_values(array_unique($temas));

                $conexion->begin_transaction();
                try {
                    $stmt = $conexion->prepare(
                        "INSERT INTO tutor_ofertas (tutor_id, materia_id, titulo, descripcion, estado)
                         VALUES (?, ?, ?, ?, 'publicada')"
                    );
                    $stmt->bind_param("iiss", $tutor_id, $materia_id, $nombre_oferta, $descripcion);
                    $stmt->execute();
                    $oferta_id = $stmt->insert_id;
                    $stmt->close();

                    if ($temas) {
                        $stmtTema = $conexion->prepare(
                            "INSERT INTO tutor_oferta_temas (oferta_id, tema) VALUES (?, ?)"
                        );
                        foreach ($temas as $tema) {
                            $stmtTema->bind_param("is", $oferta_id, $tema);
                            $stmtTema->execute();
                        }
                        $stmtTema->close();
                    }

                    $conexion->commit();
                    header("Location: menu.php?oferta_creada=1");
                    exit();
                } catch (Throwable $e) {
                    $conexion->rollback();
                    throw $e;
                }
            }
        }
    }
} catch (mysqli_sql_exception $e) {
    $error_tablas = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar oferta de tutoría | TutorTime</title>
    <link rel="stylesheet" href="css/catalogo_tutorias.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="menu.php">TutorTime</a>
    <div class="header-actions">
        <span class="welcome"><?= e($_SESSION['nombre'] ?? 'Tutor') ?></span>
        <a class="button button-light" href="menu.php">Volver al menú</a>
    </div>
</header>

<main class="page-container narrow-container">
    <section class="hero hero-compact">
        <p class="eyebrow">Perfil de tutor</p>
        <h1>Publica una oferta de tutoría</h1>
        <p>Indica la materia y los temas en los que puedes brindar apoyo individual.</p>
    </section>

    <?php if ($error_tablas): ?>
        <section class="notice notice-warning">
            <h2>Falta preparar la base de datos</h2>
            <p>El formulario está preparado, pero no se encontraron las tablas del catálogo. Solicita que ejecuten la migración SQL en la base de datos de pruebas.</p>
        </section>
    <?php else: ?>
        <?php if ($error_formulario): ?>
            <div class="notice notice-error"><?= e($error_formulario) ?></div>
        <?php endif; ?>
        <form class="form-card" method="POST" action="crear_oferta_tutoria.php">
            <label for="materia_id">Materia</label>
            <select id="materia_id" name="materia_id" required>
                <option value="">Selecciona una materia</option>
                <?php foreach ($materias as $materia): ?>
                    <option value="<?= (int)$materia['id'] ?>" <?= $materia_seleccionada === (int)$materia['id'] ? 'selected' : '' ?>>
                        <?= e($materia['categoria'] . ' — ' . $materia['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="titulo">Título de tu oferta</label>
            <input id="titulo" name="titulo" maxlength="120" required value="<?= e($nombre_oferta) ?>" placeholder="Ej. Apoyo en programación con C">

            <label for="descripcion">Descripción</label>
            <textarea id="descripcion" name="descripcion" rows="4" placeholder="Explica cómo puedes ayudar al alumno."><?= e($descripcion) ?></textarea>

            <label for="temas">Temas que puedes ofrecer</label>
            <textarea id="temas" name="temas" rows="6" placeholder="Escribe un tema por línea:&#10;Variables y tipos de datos&#10;Ciclos y condicionales&#10;Arreglos y funciones"><?= e($temas_texto) ?></textarea>
            <p class="field-help">Puedes agregar varios temas, uno por línea. El alumno también podrá consultarte por otros temas.</p>

            <button class="button button-full" type="submit">Publicar oferta</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
