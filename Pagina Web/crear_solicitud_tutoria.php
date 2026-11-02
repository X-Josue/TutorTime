
<?php
session_start();

if (!isset($_SESSION['id']) || ($_SESSION['tipo'] ?? '') !== 'Alumno') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

function volverAlCatalogo($mensaje, $origen = '', $materiaId = null)
{
    $_SESSION['mensaje_solicitud'] = $mensaje;

    if ($origen === 'mis_solicitudes') {
        header("Location: mis_solicitudes_tutoria.php");
    } elseif ($materiaId) {
        header("Location: tutores_materia.php?id=" . (int)$materiaId);
    } else {
        header("Location: catalogo_materias.php");
    }

    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: catalogo_materias.php");
    exit();
}

/* Validar token CSRF */
$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_solicitud']) ||
    !is_string($token) ||
    !hash_equals($_SESSION['csrf_solicitud'], $token)
) {
    volverAlCatalogo(
        'La solicitud expiró. Actualiza la página e inténtalo nuevamente.',
        $_POST['origen'] ?? ''
    );
}

$alumnoId = (int)$_SESSION['id'];
$origen = $_POST['origen'] ?? '';

/*
|--------------------------------------------------------------------------
| PROCESO DESDE MIS SOLICITUDES
|--------------------------------------------------------------------------
| El alumno selecciona un horario después de que el tutor respondió
| que puede ayudar con el tema.
*/
if ($origen === 'mis_solicitudes') {

    $consultaId = filter_var(
        $_POST['consulta_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    $disponibilidadId = filter_var(
        $_POST['disponibilidad_id'] ?? '',
        FILTER_VALIDATE_INT
    );

    if (!$consultaId || !$disponibilidadId) {
        volverAlCatalogo(
            'Selecciona un horario válido.',
            'mis_solicitudes'
        );
    }

    try {
        $conexion->begin_transaction();

        /*
         * Comprobar que la consulta pertenece al alumno
         * y que el tutor ya la respondió.
         */
        $sqlConsulta = "
            SELECT tutor_id, oferta_id, tema_solicitado, descripcion_necesidad
            FROM solicitudes_tutorias
            WHERE id = ?
              AND alumno_id = ?
              AND tipo = 'consulta_tema'
              AND estado = 'respondida'
            FOR UPDATE
        ";

        $stmtConsulta = $conexion->prepare($sqlConsulta);
        $stmtConsulta->bind_param("ii", $consultaId, $alumnoId);
        $stmtConsulta->execute();

        $consulta = $stmtConsulta->get_result()->fetch_assoc();
        $stmtConsulta->close();

        if (!$consulta) {
            throw new Exception(
                'La consulta ya no está disponible o no pertenece a tu cuenta.'
            );
        }

        $tutorId = (int)$consulta['tutor_id'];
        $ofertaId = (int)$consulta['oferta_id'];
        $tema = $consulta['tema_solicitado'];
        $descripcion = $consulta['descripcion_necesidad'];

        if ($ofertaId <= 0) {
            throw new Exception(
                'No se encontró la oferta relacionada con esta consulta.'
            );
        }

        /* Comprobar que la oferta pertenece al tutor */
        $sqlOferta = "
            SELECT id
            FROM tutor_ofertas
            WHERE id = ? AND tutor_id = ?
        ";

        $stmtOferta = $conexion->prepare($sqlOferta);
        $stmtOferta->bind_param("ii", $ofertaId, $tutorId);
        $stmtOferta->execute();

        $oferta = $stmtOferta->get_result()->fetch_assoc();
        $stmtOferta->close();

        if (!$oferta) {
            throw new Exception(
                'La oferta relacionada con esta consulta ya no está disponible.'
            );
        }

        /*
         * Cambiar el horario de disponible a solicitado.
         */
        $sqlHorario = "
            UPDATE disponibilidad_tutores
            SET estado = 'solicitado'
            WHERE id = ?
              AND tutor_id = ?
              AND estado = 'disponible'
              AND (
                  fecha > CURDATE()
                  OR (fecha = CURDATE() AND hora_inicio > CURTIME())
              )
        ";

        $stmtHorario = $conexion->prepare($sqlHorario);
        $stmtHorario->bind_param(
            "ii",
            $disponibilidadId,
            $tutorId
        );
        $stmtHorario->execute();

        if ($stmtHorario->affected_rows !== 1) {
            $stmtHorario->close();

            throw new Exception(
                'Ese horario ya no está disponible. Actualiza la página y selecciona otro.'
            );
        }

        $stmtHorario->close();

        /*
         * Crear una nueva solicitud de reserva.
         * La consulta original permanece como respondida.
         */
        $tipo = 'reserva';
        $estado = 'pendiente';

        $sqlInsertar = "
            INSERT INTO solicitudes_tutorias (
                alumno_id,
                tutor_id,
                oferta_id,
                disponibilidad_id,
                tipo,
                tema_solicitado,
                descripcion_necesidad,
                estado
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmtInsertar = $conexion->prepare($sqlInsertar);
        $stmtInsertar->bind_param(
            "iiiissss",
            $alumnoId,
            $tutorId,
            $ofertaId,
            $disponibilidadId,
            $tipo,
            $tema,
            $descripcion,
            $estado
        );

        $stmtInsertar->execute();
        $stmtInsertar->close();

        $conexion->commit();

        unset($_SESSION['csrf_solicitud']);

        volverAlCatalogo(
            'Tu solicitud de tutoría se registró correctamente. Ahora espera la confirmación del tutor.',
            'mis_solicitudes'
        );

    } catch (Throwable $e) {

        $conexion->rollback();
        error_log("Error al crear reserva desde consulta: " . $e->getMessage());

        $mensaje = ($e instanceof Exception)
            ? $e->getMessage()
            : 'Ocurrió un error al registrar la tutoría.';

        volverAlCatalogo($mensaje, 'mis_solicitudes');
    }
}

/*
|--------------------------------------------------------------------------
| PROCESO NORMAL DESDE TUTORES_MATERIA.PHP
|--------------------------------------------------------------------------
| Se conserva el funcionamiento del formulario original.
*/

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

if (!$ofertaId || !$tutorId || !$materiaId) {
    volverAlCatalogo('Los datos de la solicitud no son válidos.');
}

if (!in_array($tipo, ['reserva', 'consulta_tema'], true)) {
    volverAlCatalogo('El tipo de solicitud no es válido.', '', $materiaId);
}

if ($tema === '') {
    volverAlCatalogo('Debes indicar el tema que necesitas estudiar.', '', $materiaId);
}

if (strlen($tema) > 150) {
    volverAlCatalogo('El tema no puede superar los 150 caracteres.', '', $materiaId);
}

if (strlen($descripcion) > 1000) {
    volverAlCatalogo('La descripción no puede superar los 1000 caracteres.', '', $materiaId);
}

if ($tipo === 'reserva' && !$disponibilidadId) {
    volverAlCatalogo('Debes seleccionar un horario.', '', $materiaId);
}

if ($tipo === 'consulta_tema') {
    $disponibilidadId = null;
}

try {
    $conexion->begin_transaction();

    /* Verificar que la oferta pertenece al tutor y a la materia */
    $sqlOferta = "
        SELECT id
        FROM tutor_ofertas
        WHERE id = ?
          AND tutor_id = ?
          AND materia_id = ?
          AND estado = 'publicada'
    ";

    $stmtOferta = $conexion->prepare($sqlOferta);
    $stmtOferta->bind_param(
        "iii",
        $ofertaId,
        $tutorId,
        $materiaId
    );
    $stmtOferta->execute();

    $oferta = $stmtOferta->get_result()->fetch_assoc();
    $stmtOferta->close();

    if (!$oferta) {
        throw new Exception('La oferta seleccionada ya no está disponible.');
    }

    $estadoSolicitud = 'consulta_pendiente';

    /* Si es una reserva, solicitar el horario */
    if ($tipo === 'reserva') {

        $sqlHorario = "
            UPDATE disponibilidad_tutores
            SET estado = 'solicitado'
            WHERE id = ?
              AND tutor_id = ?
              AND estado = 'disponible'
              AND (
                  fecha > CURDATE()
                  OR (fecha = CURDATE() AND hora_inicio > CURTIME())
              )
        ";

        $stmtHorario = $conexion->prepare($sqlHorario);
        $stmtHorario->bind_param(
            "ii",
            $disponibilidadId,
            $tutorId
        );
        $stmtHorario->execute();

        if ($stmtHorario->affected_rows !== 1) {
            $stmtHorario->close();

            throw new Exception(
                'El horario seleccionado ya no está disponible.'
            );
        }

        $stmtHorario->close();

        $estadoSolicitud = 'pendiente';
    }

    /* Registrar la solicitud */
    $sqlInsertar = "
        INSERT INTO solicitudes_tutorias (
            alumno_id,
            tutor_id,
            oferta_id,
            disponibilidad_id,
            tipo,
            tema_solicitado,
            descripcion_necesidad,
            estado
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmtInsertar = $conexion->prepare($sqlInsertar);
    $stmtInsertar->bind_param(
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

    $stmtInsertar->execute();
    $stmtInsertar->close();

    $conexion->commit();

    unset($_SESSION['csrf_solicitud']);

    volverAlCatalogo(
        'Tu solicitud se registró correctamente.',
        '',
        $materiaId
    );

} catch (Throwable $e) {

    $conexion->rollback();
    error_log("Error al crear solicitud de tutoría: " . $e->getMessage());

    $mensaje = ($e instanceof Exception)
        ? $e->getMessage()
        : 'Ocurrió un error al registrar tu solicitud.';

    volverAlCatalogo($mensaje, '', $materiaId);
}
