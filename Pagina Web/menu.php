<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

$conexion = new mysqli("localhost", "root", "", "tutortime");

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}

$tipo = $_SESSION['tipo'] ?? '';
$nombre = $_SESSION['nombre'] ?? '';
$usuario_id = $_SESSION['id'] ?? 0;

$mensaje_error = "";
$mostrar_exito = false;

/* =========================================================
   PROCESAR UNIRSE A CLASE (FORMULARIO DIRECTO)
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['unirse_clase_submit'])) {
    if ($tipo !== "Alumno") {
        $mensaje_error = "Solo los alumnos pueden unirse a una clase.";
    } else {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

        if (empty($codigo)) {
            $mensaje_error = "Debes introducir el código de la clase.";
        } else {
            // 1. Buscar materia por código
            $stmt = $conexion->prepare("SELECT id, nombre FROM materias WHERE codigo = ?");
            $stmt->bind_param("s", $codigo);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows === 0) {
                $mensaje_error = "No existe ninguna clase con ese código.";
            } else {
                $materia = $res->fetch_assoc();
                $materia_id = $materia['id'];

                // 2. Validar si ya está inscrito
                $stmtCheck = $conexion->prepare("SELECT id FROM alumnos_materias WHERE alumno_id = ? AND materia_id = ?");
                $stmtCheck->bind_param("ii", $usuario_id, $materia_id);
                $stmtCheck->execute();

                if ($stmtCheck->get_result()->num_rows > 0) {
                    $mensaje_error = "Ya estás inscrito en esta clase.";
                } else {
                    // 3. Registrar al alumno
                    $stmtIns = $conexion->prepare("INSERT INTO alumnos_materias (alumno_id, materia_id) VALUES (?, ?)");
                    $stmtIns->bind_param("ii", $usuario_id, $materia_id);

                    if ($stmtIns->execute()) {
                        header("Location: menu.php?exito=1");
                        exit();
                    } else {
                        $mensaje_error = "Ocurrió un error al unirte a la clase.";
                    }
                }
            }
        }
    }
}

// Detectar parámetro de éxito tras redirección limpia
if (isset($_GET['exito']) && $_GET['exito'] === '1') {
    $mostrar_exito = true;
}

/* =========================================================
   OBTENER MATERIAS DEL USUARIO
========================================================= */
if ($tipo === "Tutor") {
    $stmt = $conexion->prepare("SELECT * FROM materias WHERE tutor_id = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $usuario_id);
} else {
    $stmt = $conexion->prepare("
        SELECT materias.*
        FROM materias
        INNER JOIN alumnos_materias ON materias.id = alumnos_materias.materia_id
        WHERE alumnos_materias.alumno_id = ?
        ORDER BY materias.fecha_creacion DESC
    ");
    $stmt->bind_param("i", $usuario_id);
}

$stmt->execute();
$resultado = $stmt->get_result();
$materias = [];

while ($m = $resultado->fetch_assoc()) {
    $materias[] = $m;
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Menu - TutorTime</title>
    <link rel="stylesheet" href="css/menu.css">

    <!-- ESTILOS INLINE DE MODALES PARA EVITAR FALLOS DE RUTA -->
    <style>
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            background-color: rgba(0, 0, 0, 0.65);
            z-index: 99999;
            justify-content: center;
            align-items: center;
        }

        .modal-overlay.active {
            display: flex !important;
        }

        .modal-card {
            background-color: #ffffff;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 400px;
            position: relative;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
            text-align: center;
        }

        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            background: none;
            border: none;
            font-size: 26px;
            cursor: pointer;
            color: #666;
        }

        .modal-close:hover {
            color: #000;
        }

        .class-code-input {
            width: 100%;
            padding: 12px;
            margin: 15px 0;
            border: 1px solid #ccc;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 1rem;
        }

        .error-message {
            background-color: #ffe6e6;
            color: #d9534f;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 12px;
            font-size: 0.9rem;
            border: 1px solid #f5c6cb;
            text-align: center;
        }

        .success-icon {
            font-size: 45px;
            color: #28a745;
            margin-bottom: 10px;
        }

        .modal-submit {
            background-color: #007bff;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            width: 100%;
        }

        .modal-submit:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="header">
    <div class="user">
        <?php echo htmlspecialchars($nombre); ?>
    </div>

    <div class="logo">
        TutorTime
    </div>

    <div class="header-right">
        <?php if ($tipo === "Tutor"): ?>
            <a href="crear_materias.php">
                <button type="button" class="btn">Crear clase</button>
            </a>
        <?php else: ?>
            <button type="button" class="btn" id="btnUnirseClase">Unirse a clase</button>
        <?php endif; ?>

        <button type="button" class="btn">Chat</button>
    </div>
</header>

<!-- CONTENEDOR PRINCIPAL -->
<div class="main-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <button class="menu-item active">Inicio</button>
        <button class="menu-item">Tareas</button>
        <hr>
        <h4 class="sidebar-title">Tus clases</h4>

        <?php if (count($materias) > 0): ?>
            <?php foreach ($materias as $materia): ?>
                <div class="class-item">
                    <?php echo htmlspecialchars($materia['nombre']); ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-classes-sidebar">No tienes clases todavía.</p>
        <?php endif; ?>
    </aside>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="content">
        <h2>Tus clases</h2>

        <div class="classes-grid">
            <?php if (count($materias) > 0): ?>
                <?php foreach ($materias as $materia): ?>
                    <div class="class-card">
                        <h3><?php echo htmlspecialchars($materia['nombre']); ?></h3>
                        <p><?php echo htmlspecialchars($materia['descripcion']); ?></p>
                        <small>Código: <strong><?php echo htmlspecialchars($materia['codigo']); ?></strong></small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-classes">
                    <h3>No tienes clases todavía</h3>
                    <?php if ($tipo === "Tutor"): ?>
                        <p>Crea tu primera clase para comenzar.</p>
                    <?php else: ?>
                        <p>Únete a una clase utilizando el código proporcionado por tu tutor.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

</div>

<?php if ($tipo === "Alumno"): ?>

<!-- MODAL 1: UNIRSE A CLASE -->
<div id="modalClase" class="modal-overlay <?php echo ($mensaje_error !== '') ? 'active' : ''; ?>">
    <div class="modal-card">
        <button type="button" class="modal-close" id="cerrarModal">&times;</button>
        <h2>Unirse a una clase</h2>
        <p>Introduce el código proporcionado por tu tutor.</p>

        <?php if ($mensaje_error !== ''): ?>
            <div class="error-message"><?php echo htmlspecialchars($mensaje_error); ?></div>
        <?php endif; ?>

        <form method="POST" action="menu.php">
            <input
                type="text"
                name="codigo"
                class="class-code-input"
                placeholder="Ejemplo: PRO-5800"
                maxlength="20"
                required
                autocomplete="off"
            >

            <button type="submit" name="unirse_clase_submit" class="modal-submit">
                Unirse a clase
            </button>
        </form>
    </div>
</div>

<!-- MODAL 2: CONFIRMACIÓN DE ÉXITO -->
<div id="modalExito" class="modal-overlay <?php echo ($mostrar_exito) ? 'active' : ''; ?>">
    <div class="modal-card">
        <div class="success-icon">&#10004;</div>
        <h2>¡Operación Exitosa!</h2>
        <p>La materia se añadió con éxito.</p>
        <br>
        <a href="menu.php" style="text-decoration: none;">
            <button type="button" class="modal-submit">Aceptar</button>
        </a>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var btnAbrir = document.getElementById("btnUnirseClase");
    var modalClase = document.getElementById("modalClase");
    var btnCerrar = document.getElementById("cerrarModal");

    if (btnAbrir && modalClase) {
        btnAbrir.addEventListener("click", function (e) {
            e.preventDefault();
            modalClase.classList.add("active");
        });
    }

    if (btnCerrar && modalClase) {
        btnCerrar.addEventListener("click", function () {
            modalClase.classList.remove("active");
        });
    }
});
</script>

<?php endif; ?>

</body>
</html>

<?php
$conexion->close();
?>