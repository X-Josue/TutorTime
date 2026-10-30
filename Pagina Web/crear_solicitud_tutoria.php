
<?php
session_start();

if (
    !isset($_SESSION['id']) ||
    ($_SESSION['tipo'] ?? '') !== 'Alumno'
) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

function volverAlCatalogo(string $mensaje): void {
    $_SESSION['mensaje_solicitud'] = $mensaje;

    $materiaId = filter_var(
        $_POST['materia_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    if ($materiaId) {
        header(
            "Location: tutores_materia.php?id=" .
            $materiaId
        );
    } else {
        header("Location: catalogo_materias.php");
    }

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: catalogo_materias.php");
    exit();
}

$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_solicitud']) ||
    !is_string($token) ||
    !hash_equals($_SESSION['csrf_solicitud'], $token)
) {
    volverAlCatalogo(
        'La solicitud expiró. Recarga la página e inténtalo nuevamente.'
    );
}

$alumnoId = (int) $_SESSION['id'];
$ofertaId = filter_var(
    $_POST['oferta_id'] ?? '',
    FILTER_VALIDATE_INT
);
$tutorId = filter_var(
    $_POST['tutor_id'] ?? '',
    FILTER_VALIDATE_INT
);
$materiaId = filter_var(
    $_POST['materia_id'] ?? '',
    FILTER_VALIDATE_INT
);
$disponibilidadId = filter_var(
    $_POST['disponibilidad_id'] ?? '',
    FILTER_VALIDATE_INT
);

$tipo = $_POST['tipo'] ?? '';
$tema = trim($_POST['tema_solicitado'] ?? '');
$descripcion = trim($_POST['descripcion_necesidad'] ?? '');

if (
    !$ofertaId ||
    !$tutorId ||
    !$materiaId ||
    !in_array($tipo, ['reserva', 'consulta_tema'], true) ||
    $tema === '' ||
    mb_strlen($tema) > 180 ||
    mb_strlen($descripcion) > 5000
) {
    volverAlCatalogo(
        'Revisa los datos del formulario e inténtalo nuevamente.'
    );
}

if ($tipo === 'reserva' && !$disponibilidadId) {
    volverAlCatalogo(
        'Selecciona un horario para solicitar la tutoría.'
    );
}

if ($tipo === 'consulta_tema') {
    $disponibilidadId = null;
}

try {
    $conexion->begin_transaction();

    // Confirmar que la oferta sigue publicada
    // y pertenece al tutor y a la materia seleccionados.
    $stmt = $conexion->prepare(
        "SELECT id
         FROM tutor_ofertas
         WHERE id = ?
           AND tutor_id = ?
           AND materia_id = ?
           AND estado = 'publicada'"
    );

    $stmt->bind_param(
        "iii",
        $ofertaId,
        $tutorId,
        $materiaId
    );

    $stmt->execute();
    $ofertaValida = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ofertaValida) {
        throw new RuntimeException(
            'La oferta ya no está disponible.'
        );
    }

    $estadoSolicitud = 'consulta_pendiente';

    if ($tipo === 'reserva') {
        // Bloquear el horario solo si continúa disponible.
        $stmt = $conexion->prepare(
            "UPDATE disponibilidad_tutores
             SET estado = 'solicitado'
             WHERE id = ?
               AND tutor_id = ?
               AND oferta_id = ?
               AND estado = 'disponible'
               AND (
                   fecha > CURDATE()
                   OR (
                       fecha = CURDATE()
                       AND hora_inicio > CURTIME()
                   )
               )"
        );

        $stmt->bind_param(
            "iii",
            $disponibilidadId,
            $tutorId,
            $ofertaId
        );

        $stmt->execute();
        $horarioActualizado = $stmt->affected_rows;
        $stmt->close();

        if ($horarioActualizado !== 1) {
            throw new RuntimeException(
                'Ese horario ya no está disponible. Selecciona otro.'
            );
        }

        $estadoSolicitud = 'pendiente';
    }

    $stmt = $conexion->prepare(
        "INSERT INTO solicitudes_tutorias
            (
                alumno_id,
                tutor_id,
                oferta_id,
                disponibilidad_id,
                tipo,
                tema_solicitado,
                descripcion_necesidad,
                estado
            )
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->bind_param(
        "iiiissss",
        $alumnoId,
        $tutorId,
        $ofertaId,
        $disponibilidadId,
        $tipo,
        $tema,
        $descripcion,
        $estadoSolicitud
    );

    $stmt->execute();
    $stmt->close();

    $conexion->commit();

    // Evitar reutilizar el formulario al actualizar la página.
    unset($_SESSION['csrf_solicitud']);

    volverAlCatalogo(
        'Tu solicitud se registró correctamente.'
    );

} catch (Throwable $error) {
    $conexion->rollback();

    error_log(
        'TutorTime: no se pudo registrar la solicitud: ' .
        $error->getMessage()
    );

    $mensaje = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'No se pudo registrar la solicitud. Inténtalo más tarde.';

    volverAlCatalogo($mensaje);
}
