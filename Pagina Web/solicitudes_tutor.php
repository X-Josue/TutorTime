
<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

if (($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header("Location: menu.php");
    exit;
}

require_once "conexion.php";

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

// Token de seguridad para los formularios.
if (empty($_SESSION['csrf_responder_solicitud'])) {
    $_SESSION['csrf_responder_solicitud'] = bin2hex(random_bytes(32));
}

$usuario_id = (int) $_SESSION['id'];
$solicitudes = [];
$error_panel = false;

// Consultar únicamente las solicitudes dirigidas al tutor conectado.
$sql = "SELECT
            s.id,
            s.tipo,
            s.tema_solicitado,
            s.descripcion_necesidad,
            s.estado,
            s.respuesta_tutor,
            s.fecha_solicitud,
            u.nombres AS alumno_nombres,
            u.apellidos AS alumno_apellidos,
            o.titulo AS oferta_titulo,
            d.fecha AS fecha_tutoria,
            d.hora_inicio,
            d.hora_fin
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u
            ON u.id = s.alumno_id
        LEFT JOIN tutor_ofertas o
            ON o.id = s.oferta_id
        LEFT JOIN disponibilidad_tutores d
            ON d.id = s.disponibilidad_id
        WHERE s.tutor_id = ?
        ORDER BY s.fecha_solicitud DESC, s.id DESC";

try {
    $consulta = $conexion->prepare($sql);
    $consulta->bind_param("i", $usuario_id);
    $consulta->execute();

    $resultado = $consulta->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $solicitudes[] = $fila;
    }

    $consulta->close();

} catch (mysqli_sql_exception $ex) {
    error_log("Error al consultar solicitudes del tutor: " . $ex->getMessage());
    $error_panel = true;
}

$mensaje = $_SESSION['mensaje_tutor'] ?? '';
unset($_SESSION['mensaje_tutor']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitudes de tutoría | TutorTime</title>
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
        <h2>Solicitudes de tutoría</h2>
        <p>Consulta las solicitudes que te han enviado los alumnos.</p>
    </section>

    <?php if ($mensaje !== ''): ?>
        <div class="notice">
            <?= e($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($error_panel): ?>

        <section class="form-card">
            <p>No fue posible cargar las solicitudes. Revisa la conexión y la estructura de la base de datos.</p>
        </section>

    <?php elseif (empty($solicitudes)): ?>

        <section class="form-card">
            <h3>No tienes solicitudes todavía</h3>
            <p>Cuando un alumno solicite una tutoría, aparecerá aquí.</p>
        </section>

    <?php else: ?>

        <?php foreach ($solicitudes as $solicitud): ?>
            <section class="form-card">
                <h3>
                    <?= e($solicitud['tema_solicitado']) ?>
                </h3>

                <p>
                    <strong>Alumno:</strong>
                    <?= e($solicitud['alumno_nombres'] . ' ' . $solicitud['alumno_apellidos']) ?>
                </p>

                <p>
                    <strong>Tipo de solicitud:</strong>
                    <?= $solicitud['tipo'] === 'reserva' ? 'Reserva de tutoría' : 'Consulta de tema' ?>
                </p>

                <?php if (!empty($solicitud['oferta_titulo'])): ?>
                    <p>
                        <strong>Oferta:</strong>
                        <?= e($solicitud['oferta_titulo']) ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($solicitud['fecha_tutoria'])): ?>
                    <p>
                        <strong>Fecha solicitada:</strong>
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
                    <strong>Descripción del alumno:</strong><br>
                    <?= nl2br(e($solicitud['descripcion_necesidad'] ?: 'Sin descripción adicional.')) ?>
                </p>

                <p>
                    <strong>Estado:</strong>
                    <?= e($solicitud['estado']) ?>
                </p>

                <?php if (!empty($solicitud['respuesta_tutor'])): ?>
                    <p>
                        <strong>Tu respuesta:</strong><br>
                        <?= nl2br(e($solicitud['respuesta_tutor'])) ?>
                    </p>
                <?php endif; ?>

                <?php
                $es_reserva_pendiente =
                    $solicitud['tipo'] === 'reserva'
                    && $solicitud['estado'] === 'pendiente';

                $es_consulta_pendiente =
                    $solicitud['tipo'] === 'consulta_tema'
                    && $solicitud['estado'] === 'consulta_pendiente';
                ?>

                <?php if ($es_reserva_pendiente || $es_consulta_pendiente): ?>
                    <form action="responder_solicitud_tutoria.php" method="POST">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION['csrf_responder_solicitud']) ?>"
                        >

                        <input
                            type="hidden"
                            name="solicitud_id"
                            value="<?= (int)$solicitud['id'] ?>"
                        >

                        <label for="respuesta_<?= (int)$solicitud['id'] ?>">
                            Respuesta para el alumno (opcional)
                        </label>

                        <textarea
                            id="respuesta_<?= (int)$solicitud['id'] ?>"
                            name="respuesta_tutor"
                            rows="3"
                            maxlength="2000"
                            placeholder="Escribe una respuesta..."
                        ></textarea>

                        <?php if ($es_reserva_pendiente): ?>
                            <button type="submit" name="accion" value="aceptar">
                                Aceptar tutoría
                            </button>

                            <button type="submit" name="accion" value="rechazar">
                                Rechazar tutoría
                            </button>
                        <?php else: ?>
                            <button type="submit" name="accion" value="responder_consulta">
                                Responder consulta
                            </button>

                            <button type="submit" name="accion" value="rechazar_consulta">
                                Rechazar consulta
                            </button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
