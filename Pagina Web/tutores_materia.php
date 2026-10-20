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
if (empty($_SESSION['csrf_solicitud'])) {
    $_SESSION['csrf_solicitud'] = bin2hex(random_bytes(32));
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$materia_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$materia = null;
$tutores = [];
$error_catalogo = false;

try {
    if ($materia_id) {
        $stmt = $conexion->prepare(
            "SELECT m.id, m.nombre, m.descripcion, c.id AS categoria_id, c.nombre AS categoria_nombre
             FROM catalogo_materias m
             INNER JOIN categorias c ON c.id = m.categoria_id
             WHERE m.id = ? AND m.activa = 1 AND c.activa = 1"
        );
        $stmt->bind_param("i", $materia_id);
        $stmt->execute();
        $materia = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($materia) {
            $stmt = $conexion->prepare(
                "SELECT o.id AS oferta_id, o.titulo, o.descripcion AS oferta_descripcion,
                        u.id AS tutor_id, u.nombres, u.apellidos
                 FROM tutor_ofertas o
                 INNER JOIN usuarios u ON u.id = o.tutor_id
                 WHERE o.materia_id = ? AND o.estado = 'publicada' AND u.rol = 'Tutor'
                 ORDER BY u.nombres, u.apellidos"
            );
            $stmt->bind_param("i", $materia_id);
            $stmt->execute();
            $res = $stmt->get_result();

            while ($fila = $res->fetch_assoc()) {
                $fila['temas'] = [];
                $tema_stmt = $conexion->prepare(
                    "SELECT tema, descripcion FROM tutor_oferta_temas WHERE oferta_id = ? ORDER BY id"
                );
                $tema_stmt->bind_param("i", $fila['oferta_id']);
                $tema_stmt->execute();
                $tema_res = $tema_stmt->get_result();
                while ($tema = $tema_res->fetch_assoc()) {
                    $fila['temas'][] = $tema;
                }
                
                $tema_stmt->close();

                // Consultar los horarios disponibles de este tutor.
                $fila['horarios'] = [];

                $horario_stmt = $conexion->prepare(
                    "SELECT id, fecha, hora_inicio, hora_fin
                     FROM disponibilidad_tutores
                     WHERE tutor_id = ?
                       AND estado = 'disponible'
                       AND (
                           fecha > CURDATE()
                           OR (fecha = CURDATE() AND hora_inicio > CURTIME())
                       )
                     ORDER BY fecha, hora_inicio"
                );

                $horario_stmt->bind_param("i", $fila['tutor_id']);
                $horario_stmt->execute();

                $horario_res = $horario_stmt->get_result();

                while ($horario = $horario_res->fetch_assoc()) {
                    $fila['horarios'][] = $horario;
                }

                $horario_stmt->close();

                $tutores[] = $fila;

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
    <title><?= $materia ? e($materia['nombre']) : 'Tutores' ?> | TutorTime</title>
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
    <?php if (!empty($_SESSION['mensaje_solicitud'])): ?>
    <section class="notice">
        <p><?= e($_SESSION['mensaje_solicitud']) ?></p>
    </section>
    <?php unset($_SESSION['mensaje_solicitud']); ?>
<?php endif; ?>
    <?php if ($error_catalogo): ?>
        <section class="notice notice-warning">
            <h2>El catálogo aún no está conectado</h2>
            <p>Las tablas de ofertas y temas de tutores todavía no están disponibles. La base de datos debe recibir la migración antes de mostrar resultados reales.</p>
        </section>
    <?php elseif (!$materia): ?>
        <section class="notice">
            <h2>No encontramos esa materia</h2>
            <a class="button" href="catalogo_materias.php">Volver al catálogo</a>
        </section>
    <?php else: ?>
        <nav class="breadcrumbs">
            <a href="catalogo_materias.php">Categorías</a> <span>›</span>
            <a href="materias_categoria.php?id=<?= (int)$materia['categoria_id'] ?>"><?= e($materia['categoria_nombre']) ?></a>
            <span>›</span> <?= e($materia['nombre']) ?>
        </nav>
        <section class="hero hero-compact">
            <p class="eyebrow"><?= e($materia['categoria_nombre']) ?></p>
            <h1>Tutores de <?= e($materia['nombre']) ?></h1>
            <p><?= e($materia['descripcion'] ?: 'Selecciona un tutor según los temas que necesitas aprender.') ?></p>
        </section>

        <section class="section-heading">
            <h2>Elige al tutor que mejor se adapte a tu duda</h2>
            <p>Revisa los temas que ofrece cada tutor. Podrás consultar también por un tema que no esté publicado.</p>
        </section>

        <?php if (!$tutores): ?>
            <section class="notice">
                <h3>Aún no hay tutores publicados para esta materia</h3>
                <p>Intenta consultar más adelante o explora otra materia.</p>
            </section>
        <?php else: ?>
            <div class="tutor-grid">
                <?php foreach ($tutores as $tutor): ?>
                    <article class="tutor-card">
                        <div class="tutor-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($tutor['nombres'], 0, 1, 'UTF-8'), 'UTF-8')) ?></div>
                        <h3><?= e(trim($tutor['nombres'] . ' ' . $tutor['apellidos'])) ?></h3>
                        <p class="offer-title"><?= e($tutor['titulo']) ?></p>
                        <p><?= e($tutor['oferta_descripcion'] ?: 'Tutor disponible para brindar apoyo personalizado.') ?></p>
                        <div class="topic-block">
                            <strong>Temas que ofrece</strong>
                            <?php if ($tutor['temas']): ?>
                                <ul>
                                    <?php foreach ($tutor['temas'] as $tema): ?>
                                        <li>
                                            <?= e($tema['tema']) ?>
                                            <?php if (!empty($tema['descripcion'])): ?>
                                                <span class="topic-description"><?= e($tema['descripcion']) ?></span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="muted">El tutor aún no ha agregado temas específicos.</p>
                            <?php endif; ?>
                        </div>
                        <div class="tutor-actions">
                            <strong>Horarios disponibles</strong>

                            <?php if (!empty($tutor['horarios'])): ?>
                                <ul>
                                    <?php foreach ($tutor['horarios'] as $horario): ?>
                                        <li>
                                            <?= e(date('d/m/Y', strtotime($horario['fecha']))) ?>
                                            — <?= e(substr($horario['hora_inicio'], 0, 5)) ?>
                                            a <?= e(substr($horario['hora_fin'], 0, 5)) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="field-help">
                                    Este tutor no tiene horarios disponibles por el momento.
                                </p>
                            <?php endif; ?>
                        </div>
                        
                        <div class="tutor-actions">
                            <h4>Solicitar tutoría</h4>

                            <form method="POST" action="crear_solicitud_tutoria.php">
                                <input type="hidden" name="csrf_token"
                                    value="<?= e($_SESSION['csrf_solicitud']) ?>">

                                <input type="hidden" name="materia_id"
                                    value="<?= (int)$materia['id'] ?>">

                                <input type="hidden" name="oferta_id"
                                    value="<?= (int)$tutor['oferta_id'] ?>">

                                <input type="hidden" name="tutor_id"
                                    value="<?= (int)$tutor['tutor_id'] ?>">

                                <label for="tipo-<?= (int)$tutor['oferta_id'] ?>">
                                    ¿Qué necesitas?
                                </label>

                                <select
                                    id="tipo-<?= (int)$tutor['oferta_id'] ?>"
                                    name="tipo"
                                    class="tipo-solicitud"
                                    required>
                                    <option value="reserva">Reservar una tutoría</option>
                                    <option value="consulta_tema">Consultar otro tema</option>
                                </select>

                                <label for="tema-<?= (int)$tutor['oferta_id'] ?>">
                                    Tema que necesitas aprender
                                </label>

                                <input
                                    type="text"
                                    id="tema-<?= (int)$tutor['oferta_id'] ?>"
                                    name="tema_solicitado"
                                    list="lista-temas-<?= (int)$tutor['oferta_id'] ?>"
                                    maxlength="180"
                                    placeholder="Ej. Ciclos en Python"
                                    required>

                                <datalist id="lista-temas-<?= (int)$tutor['oferta_id'] ?>">
                                    <?php foreach ($tutor['temas'] as $tema): ?>
                                        <option value="<?= e($tema['tema']) ?>">
                                    <?php endforeach; ?>
                                </datalist>

                                <label for="horario-<?= (int)$tutor['oferta_id'] ?>">
                                    Horario disponible
                                </label>

                                <select
                                    id="horario-<?= (int)$tutor['oferta_id'] ?>"
                                    name="disponibilidad_id"
                                    class="horario-solicitud">

                                    <option value="">Selecciona un horario</option>

                                    <?php foreach ($tutor['horarios'] as $horario): ?>
                                        <option value="<?= (int)$horario['id'] ?>">
                                            <?= e(date('d/m/Y', strtotime($horario['fecha']))) ?>
                                            — <?= e(substr($horario['hora_inicio'], 0, 5)) ?>
                                            a <?= e(substr($horario['hora_fin'], 0, 5)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <label for="descripcion-<?= (int)$tutor['oferta_id'] ?>">
                                    Describe tu duda (opcional)
                                </label>

                                <textarea
                                    id="descripcion-<?= (int)$tutor['oferta_id'] ?>"
                                    name="descripcion_necesidad"
                                    rows="3"
                                    maxlength="5000"
                                    placeholder="¿Qué necesitas repasar o resolver?"></textarea>

                                <button class="button button-full" type="submit">
                                    Enviar solicitud
                                </button>
                            </form>
                        </div>
                        
                        <script>
                        document.querySelectorAll('.tutor-actions').forEach(function (contenedor) {
                            const tipo = contenedor.querySelector('.tipo-solicitud');
                            const horario = contenedor.querySelector('.horario-solicitud');

                            function actualizarHorario() {
                                const esReserva = tipo.value === 'reserva';

                                horario.required = esReserva;
                                horario.disabled = !esReserva;

                                if (!esReserva) {
                                    horario.value = '';
                                }
                            }

                            tipo.addEventListener('change', actualizarHorario);
                            actualizarHorario();
                        });
                        </script>

                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
