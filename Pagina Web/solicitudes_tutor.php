
<?php
session_start();

// Verificar que el usuario haya iniciado sesión.
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// Verificar que sea tutor.
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
$mensaje = '';

// =====================================================
// 1. PROCESAR FINALIZACION DE TUTORIA
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['completar_tutoria'])) {

    $token = $_POST['csrf_token'] ?? '';

    $solicitud_id = filter_input(
        INPUT_POST,
        'solicitud_id',
        FILTER_VALIDATE_INT
    );

    if (
        !$token ||
        !hash_equals($_SESSION['csrf_responder_solicitud'], $token)
    ) {
        $mensaje = "La solicitud no es válida. Intenta nuevamente.";

    } elseif (!$solicitud_id || $solicitud_id <= 0) {
        $mensaje = "El ID de la tutoría no es válido.";

    } else {

        try {

            // Solo permite completar reservas confirmadas
            // que pertenezcan al tutor conectado.
            $sql_completar = "UPDATE solicitudes_tutorias
                              SET estado = 'completada',
                                  fecha_actualizacion = CURRENT_TIMESTAMP
                              WHERE id = ?
                                AND tutor_id = ?
                                AND tipo = 'reserva'
                                AND estado = 'confirmada'";

            $stmt_completar = $conexion->prepare($sql_completar);

            $stmt_completar->bind_param(
                "ii",
                $solicitud_id,
                $usuario_id
            );

            $stmt_completar->execute();

            if ($stmt_completar->affected_rows > 0) {
                $mensaje = "La tutoría se marcó como completada correctamente.";
            } else {
                $mensaje = "No se pudo completar la tutoría. Verifica que siga confirmada y que te pertenezca.";
            }

            $stmt_completar->close();

        } catch (mysqli_sql_exception $ex) {

            error_log("Error al completar tutoría: " . $ex->getMessage());

            $mensaje = "Ocurrió un error al intentar completar la tutoría.";
        }
    }
}

// =====================================================
// 2. CONSULTAR SOLICITUDES DEL TUTOR
// =====================================================

$sql = "SELECT
            s.id,
            s.tipo,
            s.tema_solicitado,
            s.descripcion_necesidad,
            s.estado,
            s.respuesta_tutor,
            s.modalidad,
            s.sala_videollamada,
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

// Mensajes anteriores.
if ($mensaje === '') {
    $mensaje = $_SESSION['mensaje_tutor'] ?? '';
}

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

            <p>
                No fue posible cargar las solicitudes.
                Revisa la conexión y la estructura de la base de datos.
            </p>

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

                <!-- RESPONDER SOLICITUDES -->

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

                <!-- CONFIGURAR MODALIDAD -->

                <?php if (
                    $solicitud['tipo'] === 'reserva' &&
                    $solicitud['estado'] === 'confirmada' &&
                    empty($solicitud['modalidad'])
                ): ?>

                    <hr>

                    <h3>Modalidad de la tutoría</h3>

                    <p>
                        Después de acordar con el alumno por chat,
                        selecciona cómo se realizará la tutoría.
                    </p>

                    <form action="configurar_modalidad.php" method="POST">

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

                        <label for="modalidad_<?= (int)$solicitud['id'] ?>">
                            Modalidad:
                        </label>

                        <select
                            name="modalidad"
                            id="modalidad_<?= (int)$solicitud['id'] ?>"
                            required
                        >

                            <option value="">Selecciona una opción</option>

                            <option value="presencial">Presencial</option>

                            <option value="virtual">Virtual</option>

                        </select>

                        <button type="submit">
                            Guardar modalidad
                        </button>

                    </form>

                <?php elseif (!empty($solicitud['modalidad'])): ?>

                    <p>
                        <strong>Modalidad acordada:</strong>
                        <?= e($solicitud['modalidad']) ?>
                    </p>

                    <?php if (
                        $solicitud['estado'] === 'confirmada' &&
                        $solicitud['modalidad'] === 'virtual' &&
                        !empty($solicitud['sala_videollamada'])
                    ): ?>

                        <a
                            href="videollamada.php?id=<?= (int)$solicitud['id'] ?>"
                            class="btn-primary"
                            target="_blank"
                        >
                            Entrar a videollamada
                        </a>

                    <?php endif; ?>

                <?php endif; ?>

                <!-- MARCAR TUTORIA COMO COMPLETADA -->

                <?php if (
                    $solicitud['tipo'] === 'reserva' &&
                    $solicitud['estado'] === 'confirmada'
                ): ?>

                    <hr>

                    <form
                        method="POST"
                        action="solicitudes_tutor.php"
                        onsubmit="return confirm('¿Estás seguro de que deseas marcar esta tutoría como completada?');"
                    >

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

                        <button
                            type="submit"
                            name="completar_tutoria"
                            value="1"
                        >
                            Marcar como completada
                        </button>

                    </form>

                <?php elseif ($solicitud['estado'] === 'completada'): ?>

                    <p>
                        <strong>Esta tutoría ya fue completada.</strong>
                    </p>

                <?php endif; ?>

            </section>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>
