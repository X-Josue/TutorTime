
<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['tipo'] ?? '') !== 'Alumno') {
    header("Location: menu.php");
    exit;
}

require_once "conexion.php";

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$alumno_id = (int) $_SESSION['id'];
$solicitudes = [];
$error_panel = false;

$sql = "SELECT
            s.id,
            s.tipo,
            s.tema_solicitado,
            s.descripcion_necesidad,
            s.estado,
            s.respuesta_tutor,
            s.fecha_solicitud,
            u.nombres AS tutor_nombres,
            u.apellidos AS tutor_apellidos,
            o.titulo AS oferta_titulo,
            d.fecha AS fecha_tutoria,
            d.hora_inicio,
            d.hora_fin
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u
            ON u.id = s.tutor_id
        LEFT JOIN tutor_ofertas o
            ON o.id = s.oferta_id
        LEFT JOIN disponibilidad_tutores d
            ON d.id = s.disponibilidad_id
        WHERE s.alumno_id = ?
        ORDER BY s.fecha_solicitud DESC, s.id DESC";

try {
    $consulta = $conexion->prepare($sql);
    $consulta->bind_param("i", $alumno_id);
    $consulta->execute();

    $resultado = $consulta->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $solicitudes[] = $fila;
    }

    $consulta->close();

} catch (mysqli_sql_exception $ex) {
    error_log("Error al consultar solicitudes del alumno: " . $ex->getMessage());
    $error_panel = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis solicitudes | TutorTime</title>
    <link rel="stylesheet" href="css/catalogo_tutorias.css">
</head>
<body>

<header class="site-header">
    <div class="header-content">
        <h1>TutorTime</h1>
        <nav>
            <a href="menu.php">Volver al menú</a>
        </nav>
    </div>
</header>

<main class="page-container">

    <section class="hero hero-compact">
        <h2>Mis solicitudes</h2>
        <p>Consulta el estado de tus tutorías y las respuestas de los tutores.</p>
    </section>

    <?php if ($error_panel): ?>

        <section class="form-card">
            <p>No fue posible cargar tus solicitudes. Inténtalo más tarde.</p>
        </section>

    <?php elseif (empty($solicitudes)): ?>

        <section class="form-card">
            <h3>Aún no tienes solicitudes</h3>
            <p>Cuando solicites una tutoría o consultes un tema, aparecerá aquí.</p>
            <a href="catalogo_materias.php">Buscar tutorías</a>
        </section>

    <?php else: ?>

        <?php foreach ($solicitudes as $solicitud): ?>

            <section class="form-card">

                <h3><?= e($solicitud['tema_solicitado']) ?></h3>

                <p>
                    <strong>Tutor:</strong>
                    <?= e($solicitud['tutor_nombres'] . ' ' . $solicitud['tutor_apellidos']) ?>
                </p>

                <?php if (!empty($solicitud['oferta_titulo'])): ?>
                    <p>
                        <strong>Oferta:</strong>
                        <?= e($solicitud['oferta_titulo']) ?>
                    </p>
                <?php endif; ?>

                <p>
                    <strong>Tipo:</strong>
                    <?= $solicitud['tipo'] === 'reserva'
                        ? 'Reserva de tutoría'
                        : 'Consulta de tema' ?>
                </p>

                <p>
                    <strong>Estado:</strong>
                    <?= e($solicitud['estado']) ?>
                </p>

                <?php if (!empty($solicitud['fecha_tutoria'])): ?>
                    <p>
                        <strong>Fecha de tutoría:</strong>
                        <?= e($solicitud['fecha_tutoria']) ?>
                    </p>

                    <p>
                        <strong>Horario:</strong>
                        <?= e(substr($solicitud['hora_inicio'], 0, 5)) ?>
                        a
                        <?= e(substr($solicitud['hora_fin'], 0, 5)) ?>
                    </p>
                <?php endif; ?>

                <p>
                    <strong>Descripción enviada:</strong><br>
                    <?= nl2br(e(
                        $solicitud['descripcion_necesidad']
                        ?: 'Sin descripción adicional.'
                    )) ?>
                </p>

                <div>
                    <strong>Respuesta del tutor:</strong>

                    <?php if (!empty($solicitud['respuesta_tutor'])): ?>
                        <p><?= nl2br(e($solicitud['respuesta_tutor'])) ?></p>
                    <?php elseif (
                        $solicitud['estado'] === 'pendiente' ||
                        $solicitud['estado'] === 'consulta_pendiente'
                    ): ?>
                        <p>El tutor todavía no ha respondido.</p>
                    <?php else: ?>
                        <p>No se registró una respuesta adicional.</p>
                    <?php endif; ?>
                </div>
                
                <?php if (
                    $solicitud['tipo'] === 'reserva' &&
                    $solicitud['estado'] === 'confirmada'
                ): ?>
                    <p>
                        <a
                            href="menu.php?abrir_chat=<?= (int)$solicitud['id'] ?>"
                            class="btn-primary"
                        >
                            Abrir chat
                        </a>
                    </p>
                <?php endif; ?>
            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
