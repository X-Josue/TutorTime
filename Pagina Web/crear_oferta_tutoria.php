
<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['tipo'] ?? '') !== 'Tutor') {
    header("Location: menu.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

if (empty($_SESSION['csrf_oferta'])) {
    $_SESSION['csrf_oferta'] = bin2hex(random_bytes(32));
}

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$materias = [];
$error_tablas = false;
$error_formulario = '';
$nombre_oferta = '';
$descripcion = '';
$temas_texto = '';
$materia_seleccionada = (int)($_POST['materia_id'] ?? 0);

$fechas = $_POST['fechas'] ?? [];
$horas_inicio = $_POST['horas_inicio'] ?? [];
$horas_fin = $_POST['horas_fin'] ?? [];
$horarios = [];

if (!is_array($fechas)) $fechas = [];
if (!is_array($horas_inicio)) $horas_inicio = [];
if (!is_array($horas_fin)) $horas_fin = [];

// Cargar las materias disponibles.
try {
    $res = $conexion->query(
        "SELECT m.id, m.nombre, c.nombre AS categoria
         FROM catalogo_materias m
         INNER JOIN categorias c ON c.id = m.categoria_id
         WHERE m.activa = 1 AND c.activa = 1
         ORDER BY c.nombre, m.nombre"
    );

    while ($fila = $res->fetch_assoc()) {
        $materias[] = $fila;
    }
} catch (mysqli_sql_exception $e) {
    $error_tablas = true;
}

// Procesar el formulario.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error_tablas) {

    if (!hash_equals(
        $_SESSION['csrf_oferta'],
        (string)($_POST['csrf_token'] ?? '')
    )) {
        $error_formulario = 'Formulario inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $nombre_oferta = trim($_POST['titulo'] ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $temas_texto = trim($_POST['temas'] ?? '');
        $tutor_id = (int)$_SESSION['id'];

        if ($materia_seleccionada <= 0 || $nombre_oferta === '') {
            $error_formulario = 'Selecciona una materia y escribe un título.';
        }

        // Comprobar que la materia exista.
        if ($error_formulario === '') {
            $stmt = $conexion->prepare(
                "SELECT id
                 FROM catalogo_materias
                 WHERE id = ? AND activa = 1"
            );

            $stmt->bind_param("i", $materia_seleccionada);
            $stmt->execute();

            if ($stmt->get_result()->num_rows === 0) {
                $error_formulario = 'La materia seleccionada no está disponible.';
            }

            $stmt->close();
        }

        // Revisar los horarios recibidos.
        $cantidad = max(
            count($fechas),
            count($horas_inicio),
            count($horas_fin)
        );

        for ($i = 0; $i < $cantidad && $error_formulario === ''; $i++) {
            $fecha = trim((string)($fechas[$i] ?? ''));
            $inicio = trim((string)($horas_inicio[$i] ?? ''));
            $fin = trim((string)($horas_fin[$i] ?? ''));

            // Ignorar filas completamente vacías.
            if ($fecha === '' && $inicio === '' && $fin === '') {
                continue;
            }

            if ($fecha === '' || $inicio === '' || $fin === '') {
                $error_formulario = 'Completa todos los campos de cada horario.';
                break;
            }

            $fecha_valida = DateTime::createFromFormat('!Y-m-d', $fecha);

            if (!$fecha_valida || $fecha_valida->format('Y-m-d') !== $fecha) {
                $error_formulario = 'La fecha de un horario no es válida.';
                break;
            }

            if ($fecha < date('Y-m-d')) {
                $error_formulario = 'No puedes registrar fechas pasadas.';
                break;
            }

            if (
                !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $inicio) ||
                !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $fin) ||
                $fin <= $inicio
            ) {
                $error_formulario = 'Revisa las horas de inicio y fin.';
                break;
            }

            if ($fecha === date('Y-m-d') && $inicio <= date('H:i')) {
                $error_formulario = 'La hora de inicio debe ser futura.';
                break;
            }

            $horarios[] = [
                'fecha' => $fecha,
                'inicio' => $inicio . ':00',
                'fin' => $fin . ':00'
            ];
        }

        if ($error_formulario === '' && count($horarios) === 0) {
            $error_formulario = 'Agrega al menos un horario.';
        }

        // Evitar horarios superpuestos en el formulario.
        for ($i = 0; $i < count($horarios) && $error_formulario === ''; $i++) {
            for ($j = $i + 1; $j < count($horarios); $j++) {
                if (
                    $horarios[$i]['fecha'] === $horarios[$j]['fecha'] &&
                    $horarios[$i]['inicio'] < $horarios[$j]['fin'] &&
                    $horarios[$i]['fin'] > $horarios[$j]['inicio']
                ) {
                    $error_formulario = 'Hay horarios superpuestos.';
                    break;
                }
            }
        }

        // Guardar todo si no hay errores.
        if ($error_formulario === '') {
            $transaccion_iniciada = false;

            try {
                $conexion->begin_transaction();
                $transaccion_iniciada = true;

                // Evitar que dos publicaciones del mismo tutor
                // revisen sus horarios al mismo tiempo.
                $bloqueo = $conexion->prepare(
                    "SELECT id
                     FROM usuarios
                     WHERE id = ?
                     FOR UPDATE"
                );

                $bloqueo->bind_param("i", $tutor_id);
                $bloqueo->execute();
                $bloqueo->close();

                // Comprobar cruces con horarios ya registrados.
                $cruce = $conexion->prepare(
                    "SELECT id
                     FROM disponibilidad_tutores
                     WHERE tutor_id = ?
                       AND fecha = ?
                       AND hora_inicio < ?
                       AND hora_fin > ?
                     LIMIT 1"
                );

                foreach ($horarios as $horario) {
                    $cruce->bind_param(
                        "isss",
                        $tutor_id,
                        $horario['fecha'],
                        $horario['fin'],
                        $horario['inicio']
                    );

                    $cruce->execute();

                    if ($cruce->get_result()->num_rows > 0) {
                        $error_formulario =
                            'Uno de los horarios se cruza con otro que ya tienes registrado.';
                        break;
                    }
                }

                $cruce->close();

                if ($error_formulario !== '') {
                    $conexion->rollback();
                    $transaccion_iniciada = false;
                } else {
                    // Crear la oferta.
                    $stmt = $conexion->prepare(
                        "INSERT INTO tutor_ofertas
                            (tutor_id, materia_id, titulo, descripcion, estado)
                         VALUES (?, ?, ?, ?, 'publicada')"
                    );

                    $stmt->bind_param(
                        "iiss",
                        $tutor_id,
                        $materia_seleccionada,
                        $nombre_oferta,
                        $descripcion
                    );

                    $stmt->execute();
                    $oferta_id = $stmt->insert_id;
                    $stmt->close();

                    // Guardar los temas.
                    $lineas = preg_split('/\r\n|\r|\n/', $temas_texto);
                    $temas = [];

                    foreach ($lineas as $linea) {
                        $tema = trim($linea);

                        if ($tema !== '') {
                            $temas[] = mb_substr($tema, 0, 150, 'UTF-8');
                        }
                    }

                    $temas = array_unique($temas);

                    if (count($temas) > 0) {
                        $stmtTema = $conexion->prepare(
                            "INSERT INTO tutor_oferta_temas (oferta_id, tema)
                             VALUES (?, ?)"
                        );

                        foreach ($temas as $tema) {
                            $stmtTema->bind_param("is", $oferta_id, $tema);
                            $stmtTema->execute();
                        }

                        $stmtTema->close();
                    }

                    // Guardar los horarios relacionados con la oferta.
                    $stmtHorario = $conexion->prepare(
                        "INSERT INTO disponibilidad_tutores
                            (tutor_id, oferta_id, fecha, hora_inicio, hora_fin, estado)
                         VALUES (?, ?, ?, ?, ?, 'disponible')"
                    );

                    foreach ($horarios as $horario) {
                        $stmtHorario->bind_param(
                            "iisss",
                            $tutor_id,
                            $oferta_id,
                            $horario['fecha'],
                            $horario['inicio'],
                            $horario['fin']
                        );

                        $stmtHorario->execute();
                    }

                    $stmtHorario->close();

                    $conexion->commit();
                    $transaccion_iniciada = false;

                    header("Location: menu.php?oferta_creada=1");
                    exit();
                }
            } catch (Throwable $e) {
                if ($transaccion_iniciada) {
                    $conexion->rollback();
                }

                $error_formulario =
                    'No se pudo guardar la oferta. Revisa los datos e inténtalo de nuevo.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicar oferta de tutoría | TutorTime</title>
    <link rel="stylesheet" href="css/catalogo_tutorias.css">

    <style>
        .horario {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
            margin: 15px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }

        .horario label {
            display: block;
            margin-bottom: 6px;
        }

        .horario input {
            width: 100%;
            box-sizing: border-box;
        }

        @media (max-width: 650px) {
            .horario {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>

<header class="site-header">
    <a class="brand" href="menu.php">TutorTime</a>

    <div class="header-actions">
        <span class="welcome"><?= e($_SESSION['nombre'] ?? 'Tutor') ?></span>
        <a class="button button-light" href="menu.php">Volver al menú</a>
    </div>
</header>

<main class="page-container narrow-container">

    <section class="hero hero-compact">
        <p class="eyebrow">Perfil de tutor</p>
        <h1>Publica una oferta de tutoría</h1>
        <p>Indica la materia y los temas en los que puedes brindar apoyo individual.</p>
    </section>

    <?php if ($error_tablas): ?>

        <section class="notice notice-warning">
            <h2>Falta preparar la base de datos</h2>
            <p>No se pudieron cargar las materias. Revisa que las tablas del catálogo estén disponibles.</p>
        </section>

    <?php else: ?>

        <?php if ($error_formulario !== ''): ?>
            <div class="notice notice-error">
                <?= e($error_formulario) ?>
            </div>
        <?php endif; ?>

        <form class="form-card" method="POST" action="crear_oferta_tutoria.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e($_SESSION['csrf_oferta']) ?>"
            >

            <label for="materia_id">Materia</label>
            <select id="materia_id" name="materia_id" required>
                <option value="">Selecciona una materia</option>

                <?php foreach ($materias as $materia): ?>
                    <option
                        value="<?= (int)$materia['id'] ?>"
                        <?= $materia_seleccionada === (int)$materia['id'] ? 'selected' : '' ?>
                    >
                        <?= e($materia['categoria'] . ' — ' . $materia['nombre']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="titulo">Título de tu oferta</label>
            <input
                id="titulo"
                name="titulo"
                maxlength="120"
                required
                value="<?= e($nombre_oferta) ?>"
                placeholder="Ej. Apoyo en programación con C"
            >

            <label for="descripcion">Descripción</label>
            <textarea
                id="descripcion"
                name="descripcion"
                rows="4"
                placeholder="Explica cómo puedes ayudar al alumno."
            ><?= e($descripcion) ?></textarea>

            <label for="temas">Temas que puedes ofrecer</label>
            <textarea
                id="temas"
                name="temas"
                rows="6"
                placeholder="Escribe un tema por línea:&#10;Variables y tipos de datos&#10;Ciclos y condicionales&#10;Arreglos y funciones"
            ><?= e($temas_texto) ?></textarea>

            <p class="field-help">
                Puedes agregar varios temas, uno por línea.
                El alumno también podrá consultarte por otros temas.
            </p>

            <h2>Horarios disponibles</h2>

            <p class="field-help">
                Agrega las fechas y horas en las que puedes dar tutoría.
                Puedes registrar varios horarios.
            </p>

            <div id="lista-horarios">
                <?php
                $cantidad_filas = max(
                    1,
                    count($fechas),
                    count($horas_inicio),
                    count($horas_fin)
                );

                for ($i = 0; $i < $cantidad_filas; $i++):
                ?>
                    <div class="horario">
                        <div>
                            <label>Fecha</label>
                            <input
                                type="date"
                                name="fechas[]"
                                min="<?= e(date('Y-m-d')) ?>"
                                value="<?= e($fechas[$i] ?? '') ?>"
                                required
                            >
                        </div>

                        <div>
                            <label>Hora de inicio</label>
                            <input
                                type="time"
                                name="horas_inicio[]"
                                value="<?= e($horas_inicio[$i] ?? '') ?>"
                                required
                            >
                        </div>

                        <div>
                            <label>Hora de fin</label>
                            <input
                                type="time"
                                name="horas_fin[]"
                                value="<?= e($horas_fin[$i] ?? '') ?>"
                                required
                            >
                        </div>

                        <button
                            type="button"
                            class="button button-light quitar-horario"
                        >
                            Quitar
                        </button>
                    </div>
                <?php endfor; ?>
            </div>

            <button
                type="button"
                class="button button-light"
                id="agregar-horario"
            >
                + Agregar otro horario
            </button>

            <button class="button button-full" type="submit">
                Publicar oferta y horarios
            </button>

        </form>
    <?php endif; ?>

</main>

<script>
const listaHorarios = document.getElementById('lista-horarios');
const botonAgregar = document.getElementById('agregar-horario');

if (listaHorarios && botonAgregar) {

    botonAgregar.addEventListener('click', function () {
        const nuevoHorario = document.createElement('div');
        nuevoHorario.className = 'horario';

        nuevoHorario.innerHTML = `
            <div>
                <label>Fecha</label>
                <input type="date" name="fechas[]" min="<?= e(date('Y-m-d')) ?>" required>
            </div>

            <div>
                <label>Hora de inicio</label>
                <input type="time" name="horas_inicio[]" required>
            </div>

            <div>
                <label>Hora de fin</label>
                <input type="time" name="horas_fin[]" required>
            </div>

            <button type="button" class="button button-light quitar-horario">
                Quitar
            </button>
        `;

        listaHorarios.appendChild(nuevoHorario);
    });

    listaHorarios.addEventListener('click', function (event) {
        if (event.target.classList.contains('quitar-horario')) {
            const horarios = listaHorarios.querySelectorAll('.horario');

            if (horarios.length > 1) {
                event.target.parentElement.remove();
            } else {
                alert('Debes dejar al menos un horario.');
            }
        }
    });
}
</script>

</body>
</html>
