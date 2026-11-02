
<?php
session_start();

// Verificar que el usuario haya iniciado sesión.
if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// Solo los tutores pueden configurar la modalidad.
if (($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header("Location: menu.php");
    exit;
}

require_once "conexion.php";

// Función para regresar a la página de solicitudes.
function regresar($mensaje) {
    $_SESSION['mensaje_tutor'] = $mensaje;
    header("Location: solicitudes_tutor.php");
    exit;
}

// Verificar que la información llegue mediante POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    regresar("Solicitud no válida.");
}

// Comprobar el token de seguridad.
$token = $_POST['csrf_token'] ?? '';

if (
    empty($_SESSION['csrf_responder_solicitud']) ||
    !hash_equals($_SESSION['csrf_responder_solicitud'], $token)
) {
    regresar("Error de seguridad. Intenta nuevamente.");
}

// Obtener y validar los datos.
$solicitud_id = filter_input(INPUT_POST, 'solicitud_id', FILTER_VALIDATE_INT);
$modalidad = $_POST['modalidad'] ?? '';

if (!$solicitud_id || $solicitud_id <= 0) {
    regresar("La solicitud seleccionada no es válida.");
}

if (!in_array($modalidad, ['presencial', 'virtual'], true)) {
    regresar("Selecciona una modalidad válida.");
}

$tutor_id = (int) $_SESSION['id'];

try {

    // Iniciar una transacción para proteger la actualización.
    $conexion->begin_transaction();

    // Comprobar que la solicitud pertenece al tutor
    // y que ya fue confirmada.
    $sql = "SELECT id, modalidad
            FROM solicitudes_tutorias
            WHERE id = ?
              AND tutor_id = ?
              AND tipo = 'reserva'
              AND estado = 'confirmada'
            FOR UPDATE";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param("ii", $solicitud_id, $tutor_id);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $solicitud = $resultado->fetch_assoc();

    $stmt->close();

    if (!$solicitud) {
        $conexion->rollback();
        regresar("No se encontró una reserva confirmada para esta solicitud.");
    }

    // Evitar cambiar una modalidad que ya fue registrada.
    if ($solicitud['modalidad'] !== null) {
        $conexion->rollback();
        regresar("La modalidad de esta tutoría ya fue configurada.");
    }

    // Si la modalidad es virtual, generar un identificador único.
    $sala = null;

    if ($modalidad === 'virtual') {
        $sala = 'tt-' . bin2hex(random_bytes(16));
    }

    // Guardar la modalidad y el identificador de la sala.
    $sql = "UPDATE solicitudes_tutorias
            SET modalidad = ?, sala_videollamada = ?
            WHERE id = ?
              AND tutor_id = ?
              AND tipo = 'reserva'
              AND estado = 'confirmada'
              AND modalidad IS NULL";

    $stmt = $conexion->prepare($sql);
    $stmt->bind_param(
        "ssii",
        $modalidad,
        $sala,
        $solicitud_id,
        $tutor_id
    );

    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        $conexion->rollback();
        regresar("No fue posible guardar la modalidad.");
    }

    $stmt->close();

    // Confirmar los cambios.
    $conexion->commit();

    if ($modalidad === 'virtual') {
        regresar("Modalidad virtual configurada. La sala de videollamada está lista.");
    } else {
        regresar("Modalidad presencial configurada correctamente.");
    }

} catch (Throwable $e) {

    // Deshacer los cambios si ocurre algún error.
    $conexion->rollback();

    error_log("Error al configurar modalidad: " . $e->getMessage());

    regresar("Ocurrió un error al configurar la modalidad.");
}
