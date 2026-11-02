
<?php
session_start();

if (!isset($_SESSION['id']) || ($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

$tutorId = (int) $_SESSION['id'];

if (empty($_SESSION['csrf_disponibilidad'])) {
    $_SESSION['csrf_disponibilidad'] = bin2hex(random_bytes(32));
}

$mensaje = $_SESSION['mensaje_disponibilidad'] ?? '';
unset($_SESSION['mensaje_disponibilidad']);

function e($valor): string {
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// Registrar un nuevo horario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        empty($_SESSION['csrf_disponibilidad']) ||
        !hash_equals($_SESSION['csrf_disponibilidad'], $token)
    ) {
        $_SESSION['mensaje_disponibilidad'] =
            'La sesión de seguridad expiró. Inténtalo nuevamente.';

        header("Location: gestionar_disponibilidad.php");
        exit();
    }

    $ofertaId = filter_input(INPUT_POST, 'oferta_id', FILTER_VALIDATE_INT);
    $fecha = $_POST['fecha'] ?? '';
    $horaInicio = $_POST['hora_inicio'] ?? '';
    $horaFin = $_POST['hora_fin'] ?? '';

    $fechaValida = is_string($fecha) &&
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) &&
        DateTime::createFromFormat('!Y-m-d', $fecha) !== false &&
        DateTime::createFromFormat('!Y-m-d', $fecha)->format('Y-m-d') === $fecha;

    $horaInicioValida = is_string($horaInicio) &&
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaInicio);

    $horaFinValida = is_string($horaFin) &&
        preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $horaFin);

    if (!$ofertaId || !$fechaValida || !$horaInicioValida || !$horaFinValida) {

        $_SESSION['mensaje_disponibilidad'] =
            'Selecciona una oferta e ingresa una fecha y horas válidas.';

        header("Location: gestionar_disponibilidad.php");
        exit();
    }

    if ($fecha < date('Y-m-d')) {
        $_SESSION['mensaje_disponibilidad'] =
            'No puedes publicar horarios de fechas pasadas.';

        header("Location: gestionar_disponibilidad.php");
        exit();
    }

    if ($horaFin <= $horaInicio) {
        $_SESSION['mensaje_disponibilidad'] =
            'La hora de fin debe ser posterior a la hora de inicio.';

        header("Location: gestionar_disponibilidad.php");
        exit();
    }

    if (
        $fecha === date('Y-m-d') &&
        $horaInicio <= date('H:i')
    ) {
        $_SESSION['mensaje_disponibilidad'] =
            'La hora de inicio debe ser futura.';

        header("Location: gestionar_disponibilidad.php");
        exit();
    }

    try {
        $conexion->begin_transaction();

        // Bloquear al usuario para evitar publicaciones simultáneas.
        $stmt = $conexion->prepare(
            "SELECT id FROM usuarios WHERE id = ? FOR UPDATE"
        );

        $stmt->bind_param("i", $tutorId);
        $stmt->execute();

        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$usuario) {
            throw new RuntimeException('No se encontró el usuario.');
        }

        // Comprobar que la oferta pertenece al tutor y está publicada.
        $stmt = $conexion->prepare(
            "SELECT id
             FROM tutor_ofertas
             WHERE id = ?
               AND tutor_id = ?
               AND estado = 'publicada'
             FOR UPDATE"
        );

        $stmt->bind_param("ii", $ofertaId, $tutorId);
        $stmt->execute();

        $oferta = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oferta) {
            throw new RuntimeException(
                'La oferta seleccionada no existe o no está publicada.'
            );
        }

        // Evitar horarios que se crucen con otros del mismo tutor.
        $stmt = $conexion->prepare(
            "SELECT id
             FROM disponibilidad_tutores
             WHERE tutor_id = ?
               AND fecha = ?
               AND hora_inicio < ?
               AND hora_fin > ?
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->bind_param(
            "isss",
            $tutorId,
            $fecha,
            $horaFin,
            $horaInicio
        );

        $stmt->execute();

        $solapamiento = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($solapamiento) {
            throw new RuntimeException(
                'Ese horario se solapa con otro que ya publicaste.'
            );
        }

        $estado = 'disponible';

        // Guardar el horario relacionado con la oferta seleccionada.
        $stmt = $conexion->prepare(
            "INSERT INTO disponibilidad_tutores
                (tutor_id, oferta_id, fecha, hora_inicio, hora_fin, estado)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "iissss",
            $tutorId,
            $ofertaId,
            $fecha,
            $horaInicio,
            $horaFin,
            $estado
        );

        $stmt->execute();
        $stmt->close();

        $conexion->commit();

        $_SESSION['mensaje_disponibilidad'] =
            'El horario se publicó correctamente y quedó asociado a la oferta.';

    } catch (Throwable $error) {

        try {
            $conexion->rollback();
        } catch (Throwable $errorRollback) {
            error_log($errorRollback->getMessage());
        }

        error_log(
            'TutorTime: error al publicar horario: ' .
            $error->getMessage()
        );

        $_SESSION['mensaje_disponibilidad'] =
            $error instanceof RuntimeException
                ? $error->getMessage()
                : 'No se pudo publicar el horario. Inténtalo nuevamente.';
    }

    header("Location: gestionar_disponibilidad.php");
    exit();
}

// Consultar ofertas publicadas del tutor.
$stmt = $conexion->prepare(
    "SELECT id, titulo
     FROM tutor_ofertas
     WHERE tutor_id = ?
       AND estado = 'publicada'
     ORDER BY fecha_creacion DESC"
);

$stmt->bind_param("i", $tutorId);
$stmt->execute();

$resultadoOfertas = $stmt->get_result();
$ofertas = [];

while ($fila = $resultadoOfertas->fetch_assoc()) {
    $ofertas[] = $fila;
}

$stmt->close();

// Consultar horarios del tutor y su oferta relacionada.
$stmt = $conexion->prepare(
    "SELECT d.id, d.fecha, d.hora_inicio, d.hora_fin,
            d.estado, d.oferta_id, o.titulo AS oferta_titulo
     FROM disponibilidad_tutores d
     LEFT JOIN tutor_ofertas o ON o.id = d.oferta_id
     WHERE d.tutor_id = ?
     ORDER BY d.fecha ASC, d.hora_inicio ASC"
);

$stmt->bind_param("i", $tutorId);
$stmt->execute();

$resultado = $stmt->get_result();
$horarios = [];

while ($fila = $resultado->fetch_assoc()) {
    $horarios[] = $fila;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Gestionar horarios - TutorTime</title>

    <link rel="stylesheet" href="css/menu.css">

    <style>
        .horarios-container {
            max-width: 950px;
            margin: 30px auto;
            padding: 20px;
        }

        .horarios-card {
            padding: 24px;
            margin-bottom: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .08);
        }

        .horarios-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }

        .horarios-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .horarios-form input,
        .horarios-form select {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
        }

        .horarios-form button {
            align-self: end;
            padding: 11px;
            cursor: pointer;
        }

        .tabla-horarios {
            width: 100%;
            border-collapse: collapse;
        }

        .tabla-horarios th,
        .tabla-horarios td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }

        .mensaje-horarios {
            padding: 12px;
            margin-bottom: 18px;
            background: #eef5ff;
            border-radius: 8px;
        }

        .tabla-scroll {
            overflow-x: auto;
        }
    </style>
</head>

<body>

<header class="header">
    <div class="user">
        <?php echo e($_SESSION['nombre'] ?? 'Tutor'); ?>
    </div>

    <div class="logo">TutorTime</div>

    <div class="header-right">
        <a href="menu.php" class="btn-link">
            <button type="button" class="btn">Volver al menú</button>
        </a>
    </div>
</header>

<main class="horarios-container">

    <h1>Gestionar disponibilidad</h1>

    <p>Publica los horarios en los que puedes ofrecer tutorías.</p>

    <?php if ($mensaje !== ''): ?>
        <div class="mensaje-horarios" role="status">
            <?php echo e($mensaje); ?>
        </div>
    <?php endif; ?>

    <section class="horarios-card">

        <h2>Publicar un horario</h2>

        <?php if (count($ofertas) > 0): ?>

            <form method="POST" class="horarios-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo e($_SESSION['csrf_disponibilidad']); ?>"
                >

                <div>
                    <label for="oferta_id">Oferta de tutoría</label>

                    <select id="oferta_id" name="oferta_id" required>
                        <option value="">Selecciona una oferta</option>

                        <?php foreach ($ofertas as $oferta): ?>
                            <option value="<?php echo (int) $oferta['id']; ?>">
                                <?php echo e($oferta['titulo']); ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div>
                    <label for="fecha">Fecha</label>

                    <input
                        type="date"
                        id="fecha"
                        name="fecha"
                        min="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>

                <div>
                    <label for="hora_inicio">Hora de inicio</label>

                    <input
                        type="time"
                        id="hora_inicio"
                        name="hora_inicio"
                        required
                    >
                </div>

                <div>
                    <label for="hora_fin">Hora de fin</label>

                    <input
                        type="time"
                        id="hora_fin"
                        name="hora_fin"
                        required
                    >
                </div>

                <button type="submit" class="btn">
                    Publicar horario
                </button>

            </form>

        <?php else: ?>

            <p>
                Todavía no tienes ofertas publicadas.
                Primero publica una oferta para poder agregarle horarios.
            </p>

            <a href="crear_oferta_tutoria.php" class="btn">
                Crear oferta
            </a>

        <?php endif; ?>

    </section>

    <section class="horarios-card">

        <h2>Mis horarios publicados</h2>

        <?php if (count($horarios) > 0): ?>

            <div class="tabla-scroll">

                <table class="tabla-horarios">

                    <thead>
                        <tr>
                            <th>Oferta</th>
                            <th>Fecha</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                            <th>Estado</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($horarios as $horario): ?>

                            <tr>
                                <td>
                                    <?php
                                    echo e(
                                        $horario['oferta_titulo']
                                        ?? 'Sin oferta asociada'
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo e($horario['fecha']); ?>
                                </td>

                                <td>
                                    <?php echo e(substr($horario['hora_inicio'], 0, 5)); ?>
                                </td>

                                <td>
                                    <?php echo e(substr($horario['hora_fin'], 0, 5)); ?>
                                </td>

                                <td>
                                    <?php echo e($horario['estado']); ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p>Aún no has publicado horarios.</p>

        <?php endif; ?>

    </section>

</main>

</body>
</html>

<?php
$conexion->close();
?>
