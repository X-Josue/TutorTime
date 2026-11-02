
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

function e($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function nombreEstado($estado)
{
    $estados = [
        'consulta_pendiente' => 'Consulta pendiente',
        'respondida' => 'Consulta respondida',
        'pendiente' => 'Pendiente de confirmación',
        'confirmada' => 'Confirmada',
        'rechazada' => 'Rechazada',
        'cancelada' => 'Cancelada',
        'completada' => 'Completada'
    ];

    return $estados[$estado] ?? $estado;
}

$alumnoId = (int)$_SESSION['id'];
$solicitudes = [];
$errorPanel = false;

/* Crear token CSRF si todavía no existe */
if (empty($_SESSION['csrf_solicitud'])) {
    $_SESSION['csrf_solicitud'] = bin2hex(random_bytes(32));
}

/* Recuperar mensaje de la última operación */
$mensajeSolicitud = $_SESSION['mensaje_solicitud'] ?? '';
unset($_SESSION['mensaje_solicitud']);

try {

    $sql = "
        SELECT
            s.id,
            s.tutor_id,
            s.oferta_id,
            s.tipo,
            s.tema_solicitado,
            s.descripcion_necesidad,
            s.estado,
            s.respuesta_tutor,
            s.modalidad,
            s.sala_videollamada,
            s.fecha_solicitud,
            u.nombres AS tutor_nombres,
            u.apellidos AS tutor_apellidos,
            o.titulo AS oferta_titulo,
            o.materia_id,
            d.fecha AS fecha_tutoria,
            d.hora_inicio,
            d.hora_fin
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u ON u.id = s.tutor_id
        LEFT JOIN tutor_ofertas o ON o.id = s.oferta_id
        LEFT JOIN disponibilidad_tutores d ON d.id = s.disponibilidad_id
        WHERE s.alumno_id = ?
        ORDER BY s.fecha_solicitud DESC, s.id DESC
    ";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("i", $alumnoId);
    $stmt->execute();

    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $fila['horarios_disponibles'] = [];
        $solicitudes[] = $fila;
    }

    $stmt->close();

    /*
     * Buscar horarios disponibles para cada consulta
     * que ya fue respondida por el tutor.
     */
    foreach ($solicitudes as $indice => $solicitud) {

        if (
            $solicitud['tipo'] === 'consulta_tema' &&
            $solicitud['estado'] === 'respondida'
        ) {

            $sqlHorarios = "
                SELECT id, fecha, hora_inicio, hora_fin
                FROM disponibilidad_tutores
                WHERE tutor_id = ?
                  AND estado = 'disponible'
                  AND (
                      fecha > CURDATE()
                      OR (fecha = CURDATE() AND hora_inicio > CURTIME())
                  )
                ORDER BY fecha ASC, hora_inicio ASC
            ";

            $stmtHorarios = $conexion->prepare($sqlHorarios);

            $tutorId = (int)$solicitud['tutor_id'];

            $stmtHorarios->bind_param("i", $tutorId);
            $stmtHorarios->execute();

            $resultadoHorarios = $stmtHorarios->get_result();

            while ($horario = $resultadoHorarios->fetch_assoc()) {
                $solicitudes[$indice]['horarios_disponibles'][] = $horario;
            }

            $stmtHorarios->close();
        }
    }

} catch (Throwable $e) {

    error_log("Error al consultar solicitudes del alumno: " . $e->getMessage());
    $errorPanel = true;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Mis solicitudes - TutorTime</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        body {
            background-color: #f4f6f9;
        }

        .contenedor {
            max-width: 1000px;
            margin: 40px auto;
        }

        .solicitud {
            border: none;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .respuesta {
            background-color: #f0f4ff;
            border-left: 4px solid #0d6efd;
            padding: 12px;
            border-radius: 5px;
            margin-top: 15px;
        }

        .continuar {
            background-color: #f0fff4;
            border: 1px solid #b7e4c7;
            border-radius: 8px;
            padding: 18px;
            margin-top: 18px;
        }
    </style>
</head>

<body>

<div class="container contenedor">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2>Mis solicitudes</h2>
            <p class="text-muted mb-0">
                Consulta el estado de tus solicitudes de tutoría.
            </p>
        </div>

        <a href="menu.php" class="btn btn-outline-secondary">
            Regresar
        </a>
    </div>

    <?php if ($mensajeSolicitud !== ''): ?>
        <div class="alert alert-info">
            <?= e($mensajeSolicitud) ?>
        </div>
    <?php endif; ?>

    <?php if ($errorPanel): ?>

        <div class="alert alert-danger">
            No fue posible cargar tus solicitudes. Intenta nuevamente.
        </div>

    <?php elseif (empty($solicitudes)): ?>

        <div class="card solicitud">
            <div class="card-body text-center py-5">
                <h5>No tienes solicitudes registradas</h5>
                <p class="text-muted">
                    Cuando solicites una tutoría o realices una consulta,
                    aparecerá aquí.
                </p>

                <a href="catalogo_materias.php" class="btn btn-primary">
                    Buscar tutorías
                </a>
            </div>
        </div>

    <?php else: ?>

        <?php foreach ($solicitudes as $solicitud): ?>

            <div class="card solicitud">
                <div class="card-body">

                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">

                        <div>
                            <h5 class="mb-1">
                                <?= e($solicitud['tema_solicitado']) ?>
                            </h5>

                            <p class="text-muted mb-1">
                                Tutor:
                                <?= e($solicitud['tutor_nombres'] . ' ' . $solicitud['tutor_apellidos']) ?>
                            </p>

                            <?php if (!empty($solicitud['oferta_titulo'])): ?>
                                <p class="text-muted mb-0">
                                    Oferta:
                                    <?= e($solicitud['oferta_titulo']) ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <span class="badge bg-primary">
                            <?= e(nombreEstado($solicitud['estado'])) ?>
                        </span>

                    </div>

                    <hr>

                    <p>
                        <strong>Tipo de solicitud:</strong>
                        <?= $solicitud['tipo'] === 'consulta_tema'
                            ? 'Consulta de tema'
                            : 'Reserva de tutoría' ?>
                    </p>

                    <?php if (!empty($solicitud['fecha_tutoria'])): ?>
                        <p>
                            <strong>Fecha:</strong>
                            <?= e($solicitud['fecha_tutoria']) ?>
                        </p>

                        <p>
                            <strong>Horario:</strong>
                            <?= e(substr($solicitud['hora_inicio'], 0, 5)) ?>
                            -
                            <?= e(substr($solicitud['hora_fin'], 0, 5)) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($solicitud['descripcion_necesidad'])): ?>
                        <p>
                            <strong>Descripción:</strong><br>
                            <?= nl2br(e($solicitud['descripcion_necesidad'])) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($solicitud['respuesta_tutor'])): ?>

                        <div class="respuesta">
                            <strong>Respuesta del tutor:</strong>
                            <p class="mb-0 mt-1">
                                <?= nl2br(e($solicitud['respuesta_tutor'])) ?>
                            </p>
                        </div>

                    <?php elseif (
                        in_array(
                            $solicitud['estado'],
                            ['pendiente', 'consulta_pendiente'],
                            true
                        )
                    ): ?>

                        <p class="text-muted mt-3 mb-0">
                            El tutor todavía no ha respondido.
                        </p>

                    <?php else: ?>

                        <p class="text-muted mt-3 mb-0">
                            No se registró una respuesta adicional.
                        </p>

                    <?php endif; ?>

                    <?php if (
                        $solicitud['tipo'] === 'consulta_tema' &&
                        $solicitud['estado'] === 'respondida'
                    ): ?>

                        <div class="continuar">

                            <h6>Continuar con la tutoría</h6>

                            <p class="text-muted">
                                El tutor puede ayudarte con este tema.
                                Selecciona uno de sus horarios disponibles
                                para enviar una solicitud de tutoría.
                            </p>

                            <?php if (!empty($solicitud['horarios_disponibles'])): ?>

                                <form
                                    method="POST"
                                    action="crear_solicitud_tutoria.php"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= e($_SESSION['csrf_solicitud']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="origen"
                                        value="mis_solicitudes"
                                    >

                                    <input
                                        type="hidden"
                                        name="consulta_id"
                                        value="<?= (int)$solicitud['id'] ?>"
                                    >

                                    <div class="mb-3">

                                        <label class="form-label">
                                            Selecciona un horario
                                        </label>

                                        <select
                                            name="disponibilidad_id"
                                            class="form-select"
                                            required
                                        >

                                            <option value="">
                                                Selecciona un horario...
                                            </option>

                                            <?php foreach (
                                                $solicitud['horarios_disponibles']
                                                as $horario
                                            ): ?>

                                                <option value="<?= (int)$horario['id'] ?>">

                                                    <?= e($horario['fecha']) ?>
                                                    -
                                                    <?= e(substr($horario['hora_inicio'], 0, 5)) ?>
                                                    a
                                                    <?= e(substr($horario['hora_fin'], 0, 5)) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn btn-success"
                                    >
                                        Solicitar tutoría
                                    </button>

                                </form>

                            <?php else: ?>

                                <div class="alert alert-warning mb-0">
                                    El tutor no tiene horarios disponibles
                                    por el momento. Puedes revisar más tarde.
                                </div>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                    <?php if (
                        $solicitud['tipo'] === 'reserva' &&
                        $solicitud['estado'] === 'confirmada'
                    ): ?>

                        <div class="mt-3">

                            <a
                                href="menu.php?abrir_chat=<?= (int)$solicitud['id'] ?>"
                                class="btn btn-outline-primary"
                            >
                                Abrir chat con el tutor
                            </a>

                            <?php if (
                                $solicitud['modalidad'] === 'virtual' &&
                                !empty($solicitud['sala_videollamada'])
                            ): ?>

                                <a
                                    href="videollamada.php?id=<?= (int)$solicitud['id'] ?>"
                                    class="btn btn-success"
                                >
                                    Unirse a videollamada
                                </a>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                </div>
            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
