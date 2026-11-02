
<?php

session_start();

// =====================================================
// 1. VERIFICAR SESION
// =====================================================

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit;
}

// Evitar que el navegador guarde esta página en caché.
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

require_once "conexion.php";

// Funcion para proteger los datos que se muestran en HTML.
function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

// =====================================================
// 2. COMPROBAR ROL
// =====================================================

$usuario_id = (int) $_SESSION['id'];
$rol = $_SESSION['tipo'] ?? '';

if ($rol !== 'Alumno' && $rol !== 'Tutor') {
    http_response_code(403);
    die("No tienes permiso para acceder a esta página.");
}

// =====================================================
// 3. OBTENER ID DE LA SOLICITUD
// =====================================================

$solicitud_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$solicitud_id || $solicitud_id <= 0) {
    http_response_code(400);
    die("La solicitud no es válida.");
}

// =====================================================
// 4. COMPROBAR QUE PERTENECE AL USUARIO
// =====================================================

$sql = "SELECT
            s.id,
            s.alumno_id,
            s.tutor_id,
            s.sala_videollamada,
            s.modalidad,
            u.nombres,
            u.apellidos
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u ON u.id = ?
        WHERE s.id = ?
          AND (s.alumno_id = ? OR s.tutor_id = ?)
          AND s.tipo = 'reserva'
          AND s.estado = 'confirmada'
          AND s.modalidad = 'virtual'
          AND s.sala_videollamada IS NOT NULL
          AND s.sala_videollamada <> ''";

$stmt = $conexion->prepare($sql);

$stmt->bind_param(
    "iiii",
    $usuario_id,
    $solicitud_id,
    $usuario_id,
    $usuario_id
);

$stmt->execute();

$resultado = $stmt->get_result();

$solicitud = $resultado->fetch_assoc();

$stmt->close();

// =====================================================
// 5. VALIDAR ACCESO
// =====================================================

if (!$solicitud) {
    http_response_code(403);
    die("No tienes permiso para acceder a esta videollamada.");
}

// Comprobar que el rol coincida con la solicitud.
$esTutor = ($usuario_id === (int)$solicitud['tutor_id']);
$esAlumno = ($usuario_id === (int)$solicitud['alumno_id']);

if (
    ($rol === 'Tutor' && !$esTutor) ||
    ($rol === 'Alumno' && !$esAlumno)
) {
    http_response_code(403);
    die("Tu rol no corresponde a esta solicitud.");
}

// =====================================================
// 6. PREPARAR INFORMACION
// =====================================================

$nombre = trim(
    $solicitud['nombres'] . ' ' . $solicitud['apellidos']
);

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Videollamada - TutorTime</title>

    <style>

        body {
            margin: 0;
            padding: 20px;
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            text-align: center;
        }

        h1 {
            color: #333;
        }

        #estado {
            margin: 15px;
            color: #555;
        }

        #meet {
            width: 100%;
            max-width: 1100px;
            height: 650px;
            margin: 20px auto;
            background: #222;
            border-radius: 10px;
            overflow: hidden;
        }

        .volver {
            display: inline-block;
            margin: 10px;
            padding: 10px 18px;
            background: #444;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }

        .volver:hover {
            background: #222;
        }

    </style>

</head>

<body>

    <h1>Videollamada TutorTime</h1>

    <p>
        Tutoría número: <?= (int)$solicitud['id'] ?>
    </p>

    <p>
        Participante: <?= e($nombre) ?>
    </p>

    <p id="estado">
        Preparando la videollamada...
    </p>

    <div id="meet"></div>

    <a href="menu.php" class="volver">
        Volver al menú
    </a>

    <script>

        // ID de la solicitud actual.
        const solicitudId = <?= (int)$solicitud['id'] ?>;

        const estado = document.getElementById("estado");

        // =================================================
        // INICIAR VIDEOLLAMADA
        // =================================================

        async function iniciarVideollamada() {

            try {

                estado.textContent = "Solicitando acceso a JaaS...";

                // Obtener el token generado por PHP.
                const respuesta = await fetch(
                    "generar_token.php?id=" + solicitudId,
                    {
                        method: "GET",
                        credentials: "same-origin",
                        cache: "no-store"
                    }
                );

                const datos = await respuesta.json();

                if (!respuesta.ok) {
                    throw new Error(
                        datos.error || "No tienes permiso para entrar."
                    );
                }

                const token = datos.token;
                const sala = datos.room;

                if (!token || !sala || !datos.name) {
                    throw new Error(
                        "La información de la videollamada está incompleta."
                    );
                }

                estado.textContent = "Conectando con JaaS...";

                // =================================================
                // CARGAR API DE JAAS
                // =================================================

                const script = document.createElement("script");

                script.src = "https://8x8.vc/external_api.js";

                // Esperar a que cargue la API.
                await new Promise((resolve, reject) => {

                    script.onload = resolve;

                    script.onerror = () => {
                        reject(
                            new Error("No se pudo cargar la API de JaaS.")
                        );
                    };

                    document.body.appendChild(script);

                });

                // Comprobar que la API esté disponible.
                if (typeof JitsiMeetExternalAPI === "undefined") {
                    throw new Error("La API de JaaS no está disponible.");
                }

                // =================================================
                // CONFIGURAR VIDEOLLAMADA
                // =================================================

                const opciones = {

                    roomName: sala,

                    jwt: token,

                    width: "100%",

                    height: 650,

                    parentNode: document.querySelector("#meet"),

                    userInfo: {
                        displayName: datos.name
                    },

                    lang: "es"

                };

                // Crear la reunión.
                const api = new JitsiMeetExternalAPI(
                    "8x8.vc",
                    opciones
                );

                // =================================================
                // EVENTOS DE LA VIDEOLLAMADA
                // =================================================

                api.addEventListener(
                    "videoConferenceJoined",
                    function() {

                        estado.textContent =
                            "Estás dentro de la videollamada.";

                    }
                );

                api.addEventListener(
                    "videoConferenceLeft",
                    function() {

                        estado.textContent =
                            "Has salido de la videollamada.";

                    }
                );

            } catch (error) {

                console.error("Error en videollamada:", error);

                estado.textContent = error.message;

            }

        }

        // Iniciar videollamada.
        iniciarVideollamada();

    </script>

</body>
</html>
