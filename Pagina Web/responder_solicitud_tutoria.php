
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

$usuario_id = (int) $_SESSION['id'];

function regresar($mensaje) {
    $_SESSION['mensaje_tutor'] = $mensaje;
    header("Location: solicitudes_tutor.php");
    exit;
}

// Solo se aceptan peticiones POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    regresar("Solicitud no válida.");
}

// Validar token CSRF.
$token_recibido = $_POST['csrf_token'] ?? '';
$token_sesion = $_SESSION['csrf_responder_solicitud'] ?? '';

if (
    !is_string($token_recibido) ||
    !is_string($token_sesion) ||
    $token_sesion === '' ||
    !hash_equals($token_sesion, $token_recibido)
) {
    regresar("La sesión de seguridad expiró. Recarga la página e inténtalo nuevamente.");
}

$solicitud_id = filter_input(INPUT_POST, 'solicitud_id', FILTER_VALIDATE_INT);
$accion = $_POST['accion'] ?? '';
$respuesta = trim((string)($_POST['respuesta_tutor'] ?? ''));

if (!$solicitud_id || $solicitud_id < 1) {
    regresar("La solicitud indicada no es válida.");
}

if (strlen($respuesta) > 2000) {
    regresar("La respuesta no puede superar los 2000 caracteres.");
}

$acciones_validas = [
    'aceptar',
    'rechazar',
    'responder_consulta',
    'rechazar_consulta'
];

if (!in_array($accion, $acciones_validas, true)) {
    regresar("La acción solicitada no es válida.");
}

$transaccion_iniciada = false;

try {
    $conexion->begin_transaction();
    $transaccion_iniciada = true;

    // Bloquear y comprobar la solicitud. Debe pertenecer al tutor conectado.
    $consulta = $conexion->prepare(
        "SELECT id, tipo, estado, disponibilidad_id
         FROM solicitudes_tutorias
         WHERE id = ? AND tutor_id = ?
         FOR UPDATE"
    );

    $consulta->bind_param("ii", $solicitud_id, $usuario_id);
    $consulta->execute();

    $solicitud = $consulta->get_result()->fetch_assoc();
    $consulta->close();

    if (!$solicitud) {
        throw new RuntimeException("No se encontró la solicitud o no te pertenece.");
    }

    $tipo = $solicitud['tipo'];
    $estado = $solicitud['estado'];
    $disponibilidad_id = $solicitud['disponibilidad_id'];

    // Aceptar una reserva.
    if ($accion === 'aceptar') {
        if ($tipo !== 'reserva' || $estado !== 'pendiente') {
            throw new RuntimeException("Esta reserva ya fue atendida o no puede aceptarse.");
        }

        if (!$disponibilidad_id) {
            throw new RuntimeException("La reserva no tiene un horario asociado.");
        }

        // Bloquear el horario y comprobar que corresponda al tutor.
        $consulta = $conexion->prepare(
            "SELECT id, estado
             FROM disponibilidad_tutores
             WHERE id = ? AND tutor_id = ?
             FOR UPDATE"
        );

        $consulta->bind_param("ii", $disponibilidad_id, $usuario_id);
        $consulta->execute();

        $horario = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$horario || $horario['estado'] !== 'solicitado') {
            throw new RuntimeException("El horario ya no está disponible para confirmar esta reserva.");
        }

        $consulta = $conexion->prepare(
            "UPDATE solicitudes_tutorias
             SET estado = 'confirmada', respuesta_tutor = ?
             WHERE id = ? AND tutor_id = ? AND tipo = 'reserva'
               AND estado = 'pendiente'"
        );

        $consulta->bind_param("sii", $respuesta, $solicitud_id, $usuario_id);
        $consulta->execute();
        $actualizadas = $consulta->affected_rows;
        $consulta->close();

        if ($actualizadas !== 1) {
            throw new RuntimeException("No se pudo confirmar la reserva.");
        }

        $consulta = $conexion->prepare(
            "UPDATE disponibilidad_tutores
             SET estado = 'reservado'
             WHERE id = ? AND tutor_id = ? AND estado = 'solicitado'"
        );

        $consulta->bind_param("ii", $disponibilidad_id, $usuario_id);
        $consulta->execute();
        $horarios_actualizados = $consulta->affected_rows;
        $consulta->close();

        if ($horarios_actualizados !== 1) {
            throw new RuntimeException("No se pudo reservar el horario.");
        }

        $conexion->commit();
        $transaccion_iniciada = false;

        regresar("La tutoría fue confirmada correctamente.");
    }

    // Rechazar una reserva.
    if ($accion === 'rechazar') {
        if ($tipo !== 'reserva' || $estado !== 'pendiente') {
            throw new RuntimeException("Esta reserva ya fue atendida o no puede rechazarse.");
        }

        if (!$disponibilidad_id) {
            throw new RuntimeException("La reserva no tiene un horario asociado.");
        }

        // Bloquear el horario antes de modificar el estado de la solicitud.
        $consulta = $conexion->prepare(
            "SELECT id
             FROM disponibilidad_tutores
             WHERE id = ? AND tutor_id = ?
             FOR UPDATE"
        );

        $consulta->bind_param("ii", $disponibilidad_id, $usuario_id);
        $consulta->execute();

        $horario = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        if (!$horario) {
            throw new RuntimeException("No se encontró el horario asociado.");
        }

        $consulta = $conexion->prepare(
            "UPDATE solicitudes_tutorias
             SET estado = 'rechazada', respuesta_tutor = ?
             WHERE id = ? AND tutor_id = ? AND tipo = 'reserva'
               AND estado = 'pendiente'"
        );

        $consulta->bind_param("sii", $respuesta, $solicitud_id, $usuario_id);
        $consulta->execute();
        $actualizadas = $consulta->affected_rows;
        $consulta->close();

        if ($actualizadas !== 1) {
            throw new RuntimeException("No se pudo rechazar la reserva.");
        }

        // No liberar el horario si otra solicitud aún lo necesita.
        $consulta = $conexion->prepare(
            "SELECT
                SUM(CASE WHEN estado = 'confirmada' THEN 1 ELSE 0 END) AS confirmadas,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes
             FROM solicitudes_tutorias
             WHERE disponibilidad_id = ?
               AND tipo = 'reserva'
               AND estado IN ('pendiente', 'confirmada')"
        );

        $consulta->bind_param("i", $disponibilidad_id);
        $consulta->execute();

        $conteos = $consulta->get_result()->fetch_assoc();
        $consulta->close();

        $confirmadas = (int)($conteos['confirmadas'] ?? 0);
        $pendientes = (int)($conteos['pendientes'] ?? 0);

        if ($confirmadas > 0) {
            $nuevo_estado_horario = 'reservado';
        } elseif ($pendientes > 0) {
            $nuevo_estado_horario = 'solicitado';
        } else {
            $nuevo_estado_horario = 'disponible';
        }

        $consulta = $conexion->prepare(
            "UPDATE disponibilidad_tutores
             SET estado = ?
             WHERE id = ? AND tutor_id = ?"
        );

        $consulta->bind_param(
            "sii",
            $nuevo_estado_horario,
            $disponibilidad_id,
            $usuario_id
        );

        $consulta->execute();
        $consulta->close();

        $conexion->commit();
        $transaccion_iniciada = false;

        regresar("La solicitud de tutoría fue rechazada.");
    }

    // Responder una consulta de tema.
    if ($accion === 'responder_consulta') {
        if ($tipo !== 'consulta_tema' || $estado !== 'consulta_pendiente') {
            throw new RuntimeException("Esta consulta ya fue atendida o no puede responderse.");
        }

        if ($respuesta === '') {
            throw new RuntimeException("Escribe una respuesta antes de atender la consulta.");
        }

        $nuevo_estado = 'completada';

        $consulta = $conexion->prepare(
            "UPDATE solicitudes_tutorias
             SET estado = ?, respuesta_tutor = ?
             WHERE id = ? AND tutor_id = ? AND tipo = 'consulta_tema'
               AND estado = 'consulta_pendiente'"
        );

        $consulta->bind_param(
            "ssii",
            $nuevo_estado,
            $respuesta,
            $solicitud_id,
            $usuario_id
        );

        $consulta->execute();
        $actualizadas = $consulta->affected_rows;
        $consulta->close();

        if ($actualizadas !== 1) {
            throw new RuntimeException("No se pudo guardar la respuesta.");
        }

        $conexion->commit();
        $transaccion_iniciada = false;

        regresar("Tu respuesta se guardó correctamente.");
    }

    // Rechazar una consulta de tema.
    if ($accion === 'rechazar_consulta') {
        if ($tipo !== 'consulta_tema' || $estado !== 'consulta_pendiente') {
            throw new RuntimeException("Esta consulta ya fue atendida o no puede rechazarse.");
        }

        $nuevo_estado = 'rechazada';

        $consulta = $conexion->prepare(
            "UPDATE solicitudes_tutorias
             SET estado = ?, respuesta_tutor = ?
             WHERE id = ? AND tutor_id = ? AND tipo = 'consulta_tema'
               AND estado = 'consulta_pendiente'"
        );

        $consulta->bind_param(
            "ssii",
            $nuevo_estado,
            $respuesta,
            $solicitud_id,
            $usuario_id
        );

        $consulta->execute();
        $actualizadas = $consulta->affected_rows;
        $consulta->close();

        if ($actualizadas !== 1) {
            throw new RuntimeException("No se pudo rechazar la consulta.");
        }

        $conexion->commit();
        $transaccion_iniciada = false;

        regresar("La consulta fue rechazada.");
    }

} catch (Throwable $ex) {
    if ($transaccion_iniciada) {
        try {
            $conexion->rollback();
        } catch (Throwable $errorRollback) {
            error_log("Error al revertir transacción: " . $errorRollback->getMessage());
        }
    }

    error_log("Error al procesar solicitud de tutoría: " . $ex->getMessage());

    if ($ex instanceof RuntimeException) {
        regresar($ex->getMessage());
    }

    regresar("Ocurrió un error al procesar la solicitud. Inténtalo nuevamente.");
}
