<?php
session_start();

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/conexion.php';

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$tipo = $_SESSION['tipo'] ?? '';
$nombre = $_SESSION['nombre'] ?? '';
$usuario_id = (int)($_SESSION['id'] ?? 0);
$abrir_chat_id = (int)($_GET['abrir_chat'] ?? 0);


/* =========================================================
   FUNCIONES DEL CHAT INDIVIDUAL
========================================================= */

function responder_json($datos)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

/*
 * Comprueba que la solicitud exista, esté confirmada
 * y pertenezca al alumno o tutor que intenta acceder.
 */
function obtener_solicitud_chat($conexion, $solicitud_id, $usuario_id, $tipo)
{
    $stmt = $conexion->prepare("
        SELECT
            s.id,
            s.alumno_id,
            s.tutor_id,
            o.materia_id,
            s.tema_solicitado,
            o.titulo,
            c.nombre AS materia_nombre,
            ua.nombres AS alumno_nombre,
            ua.apellidos AS alumno_apellidos,
            ut.nombres AS tutor_nombre,
            ut.apellidos AS tutor_apellidos
        FROM solicitudes_tutorias s
        INNER JOIN tutor_ofertas o ON o.id = s.oferta_id
        INNER JOIN catalogo_materias c ON c.id = o.materia_id
        INNER JOIN usuarios ua ON ua.id = s.alumno_id
        INNER JOIN usuarios ut ON ut.id = s.tutor_id
        WHERE s.id = ?
          AND s.tipo = 'reserva'
          AND s.estado = 'confirmada'
          AND (
              (s.alumno_id = ? AND ? = 'Alumno')
              OR
              (s.tutor_id = ? AND ? = 'Tutor')
          )
        LIMIT 1
    ");

    $stmt->bind_param(
        "iisis",
        $solicitud_id,
        $usuario_id,
        $tipo,
        $usuario_id,
        $tipo
    );

    $stmt->execute();
    $resultado = $stmt->get_result();
    $solicitud = $resultado->fetch_assoc();
    $stmt->close();

    return $solicitud ?: null;
}

/*
 * Recupera el chat de una solicitud o lo crea
 * si todavía no existe.
 */
function obtener_chat_id($conexion, $solicitud_id, $materia_id)
{
    $stmt = $conexion->prepare(
        "SELECT id FROM chats WHERE solicitud_id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $solicitud_id);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $chat = $resultado->fetch_assoc();
    $stmt->close();

    if ($chat) {
        return (int)$chat['id'];
    }

    $stmt = $conexion->prepare(
        "INSERT INTO chats (materia_id, solicitud_id) VALUES (?, ?)"
    );
    $stmt->bind_param("ii", $materia_id, $solicitud_id);
    $stmt->execute();

    $chat_id = (int)$stmt->insert_id;
    $stmt->close();

    return $chat_id;
}

/* =========================================================
   API DEL CHAT
========================================================= */

if (isset($_GET['chat_action'])) {
    $accion = $_GET['chat_action'];

    /* LISTAR SOLICITUDES CONFIRMADAS */
    if ($accion === 'list') {
        $stmt = $conexion->prepare("
            SELECT
                s.id AS solicitud_id,
                o.materia_id,
                c.nombre AS materia_nombre,
                s.tema_solicitado,
                u.nombres AS tutor_nombre,
                u.apellidos AS tutor_apellidos
            FROM solicitudes_tutorias s
            INNER JOIN tutor_ofertas o ON o.id = s.oferta_id
            INNER JOIN catalogo_materias c ON c.id = o.materia_id
            INNER JOIN usuarios u ON u.id = s.tutor_id
            WHERE s.tipo = 'reserva'
              AND s.estado = 'confirmada'
              AND (
                  (s.alumno_id = ? AND ? = 'Alumno')
                  OR
                  (s.tutor_id = ? AND ? = 'Tutor')
              )
            ORDER BY s.fecha_actualizacion DESC, s.id DESC
        ");

        $stmt->bind_param("isis", $usuario_id, $tipo, $usuario_id, $tipo);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $chats = [];

        while ($fila = $resultado->fetch_assoc()) {
            $chats[] = [
                'solicitud_id' => (int)$fila['solicitud_id'],
                'materia_id' => (int)$fila['materia_id'],
                'materia_nombre' => $fila['materia_nombre'],
                'tema_solicitado' => $fila['tema_solicitado'],
                'tutor_nombre' => trim(
                    $fila['tutor_nombre'] . ' ' . $fila['tutor_apellidos']
                )
            ];
        }

        $stmt->close();

        responder_json([
            'success' => true,
            'chats' => $chats
        ]);
    }

    /* OBTENER MENSAJES */
    if ($accion === 'messages') {
        $solicitud_id = (int)($_GET['solicitud_id'] ?? 0);

        $solicitud = obtener_solicitud_chat(
            $conexion,
            $solicitud_id,
            $usuario_id,
            $tipo
        );

        if (!$solicitud) {
            responder_json([
                'success' => false,
                'message' => 'No tienes permiso para ver este chat.'
            ]);
        }

        $chat_id = obtener_chat_id(
            $conexion,
            $solicitud_id,
            (int)$solicitud['materia_id']
        );

        $stmt = $conexion->prepare("
            SELECT
                mc.id,
                mc.usuario_id,
                mc.mensaje,
                DATE_FORMAT(mc.fecha, '%H:%i') AS hora,
                u.nombres AS nombre,
                u.apellidos AS apellidos
            FROM mensajes_chat mc
            INNER JOIN usuarios u ON u.id = mc.usuario_id
            WHERE mc.chat_id = ?
            ORDER BY mc.fecha ASC, mc.id ASC
        ");

        $stmt->bind_param("i", $chat_id);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $mensajes = [];

        while ($fila = $resultado->fetch_assoc()) {
            $mensajes[] = [
                'id' => (int)$fila['id'],
                'usuario_id' => (int)$fila['usuario_id'],
                'nombre' => trim(
                    $fila['nombre'] . ' ' . $fila['apellidos']
                ),
                'mensaje' => $fila['mensaje'],
                'hora' => $fila['hora']
            ];
        }

        $stmt->close();

        responder_json([
            'success' => true,
            'solicitud_id' => $solicitud_id,
            'chat_id' => $chat_id,
            'mensajes' => $mensajes
        ]);
    }

    /* ENVIAR MENSAJE */
    if ($accion === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $solicitud_id = (int)($_POST['solicitud_id'] ?? 0);
        $mensaje = trim($_POST['mensaje'] ?? '');

        $solicitud = obtener_solicitud_chat(
            $conexion,
            $solicitud_id,
            $usuario_id,
            $tipo
        );

        if (!$solicitud) {
            responder_json([
                'success' => false,
                'message' => 'No tienes permiso para usar este chat.'
            ]);
        }

        if ($mensaje === '') {
            responder_json([
                'success' => false,
                'message' => 'Escribe un mensaje.'
            ]);
        }

        if (mb_strlen($mensaje) > 2000) {
            responder_json([
                'success' => false,
                'message' => 'El mensaje es demasiado largo.'
            ]);
        }

        $chat_id = obtener_chat_id(
            $conexion,
            $solicitud_id,
            (int)$solicitud['materia_id']
        );

        $stmt = $conexion->prepare("
            INSERT INTO mensajes_chat (chat_id, usuario_id, mensaje)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $chat_id, $usuario_id, $mensaje);
        $stmt->execute();
        $stmt->close();

        responder_json([
            'success' => true
        ]);
    }

    responder_json([
        'success' => false,
        'message' => 'Acción de chat no válida.'
    ]);
}

/* =========================================================
   VARIABLES PARA EL MENÚ
========================================================= */
$mensaje_error = "";
$mostrar_exito = false;

/* =========================================================
   UNIRSE A UNA CLASE-- Ya lo borre
========================================================= */


if (isset($_GET['exito']) && $_GET['exito'] === '1') {
    $mostrar_exito = true;
}

/* =========================================================
   OBTENER LAS MATERIAS DEL USUARIO--YA LO BORRE
========================================================= */
/* =========================================================
   OBTENER INFORMACIÓN DE TUTORÍAS SEGÚN EL ROL
========================================================= */
$tutorias = [];

if ($tipo === "Tutor") {
    $stmt = $conexion->prepare("
        SELECT
            o.id,
            o.titulo,
            o.descripcion,
            o.estado,
            c.nombre AS materia
        FROM tutor_ofertas o
        INNER JOIN catalogo_materias c ON c.id = o.materia_id
        WHERE o.tutor_id = ?
        ORDER BY o.fecha_creacion DESC
    ");

    $stmt->bind_param("i", $usuario_id);
} elseif ($tipo === "Alumno") {
    $stmt = $conexion->prepare("
        SELECT
            s.id,
            s.tema_solicitado AS titulo,
            s.estado,
            s.tipo,
            s.fecha_solicitud,
            u.nombres AS tutor_nombres,
            u.apellidos AS tutor_apellidos,
            c.nombre AS materia
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u ON u.id = s.tutor_id
        LEFT JOIN tutor_ofertas o ON o.id = s.oferta_id
        LEFT JOIN catalogo_materias c ON c.id = o.materia_id
        WHERE s.alumno_id = ?
        ORDER BY s.fecha_solicitud DESC
    ");

    $stmt->bind_param("i", $usuario_id);
}

if (isset($stmt)) {
    $stmt->execute();
    $resultado = $stmt->get_result();

    while ($fila = $resultado->fetch_assoc()) {
        $tutorias[] = $fila;
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menú - TutorTime</title>
    <link rel="stylesheet" href="css/menu.css">
</head>
<body>

<!-- =====================================================
     HEADER
===================================================== -->
<header class="header">
    <div class="user">
        <?php echo e($nombre); ?>
    </div>

    <div class="logo">
        TutorTime
    </div>

    <div class="header-right">
        <?php if ($tipo === "Tutor"): ?>
            <a href="crear_oferta_tutoria.php" class="btn-link">
                <button type="button" class="btn">Ofrecer tutoría</button>
            </a>
            <a href="solicitudes_tutor.php" class="btn-link">
                <button type="button" class="btn">Solicitudes de tutoría</button>
            </a>
        <?php elseif ($tipo === "Alumno"): ?>
            <a href="catalogo_materias.php" class="btn-link">
                <button type="button" class="btn">Buscar tutorías</button>
            </a>
            <a href="mis_solicitudes_tutoria.php" class="btn-link">
                <button type="button" class="btn">Mis solicitudes</button>
            </a>
        <?php endif; ?>

        <button type="button" class="btn" id="btnChat">Chat</button>
    </div>
</header>

<!-- =====================================================
     CONTENIDO PRINCIPAL
===================================================== -->
<div class="main-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <button class="menu-item active" type="button">Inicio</button>
        <button class="menu-item" type="button">Tareas</button>

        <hr>

        <h4 class="sidebar-title">
            <?php echo ($tipo === "Tutor") ? "Mis ofertas" : "Mis tutorías"; ?>
        </h4>

        <?php if (count($tutorias) > 0): ?>
            <?php foreach ($tutorias as $tutoria): ?>
                <div class="class-item">
                    <?php echo e($tutoria['titulo']); ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="no-classes-sidebar">
                <?php echo ($tipo === "Tutor")
                    ? "Todavía no tienes ofertas de tutoría."
                    : "Todavía no tienes solicitudes de tutoría."; ?>
            </p>
        <?php endif; ?>
    </aside>

    <!-- CONTENIDO -->
    <main class="content">
        <h2>
            <?php echo ($tipo === "Tutor")
                ? "Mis ofertas de tutoría"
                : "Mis tutorías"; ?>
        </h2>

        <div class="classes-grid">
            <?php if (count($tutorias) > 0): ?>

                <?php foreach ($tutorias as $tutoria): ?>
                    <div class="class-card">
                        <h3><?php echo e($tutoria['titulo']); ?></h3>

                        <?php if ($tipo === "Tutor"): ?>

                            <p>
                                Materia:
                                <?php echo e($tutoria['materia']); ?>
                            </p>

                            <p>
                                Estado:
                                <?php echo e($tutoria['estado']); ?>
                            </p>

                            <?php if (!empty($tutoria['descripcion'])): ?>
                                <p><?php echo e($tutoria['descripcion']); ?></p>
                            <?php endif; ?>

                        <?php else: ?>

                            <p>
                                Tutor:
                                <?php
                                echo e(
                                    trim(
                                        ($tutoria['tutor_nombres'] ?? '') . ' ' .
                                        ($tutoria['tutor_apellidos'] ?? '')
                                    )
                                );
                                ?>
                            </p>

                            <?php if (!empty($tutoria['materia'])): ?>
                                <p>
                                    Materia:
                                    <?php echo e($tutoria['materia']); ?>
                                </p>
                            <?php endif; ?>

                            <p>
                                Tipo:
                                <?php echo ($tutoria['tipo'] === 'reserva')
                                    ? 'Reserva de tutoría'
                                    : 'Consulta de tema'; ?>
                            </p>

                            <p>
                                Estado:
                                <?php echo e($tutoria['estado']); ?>
                            </p>

                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-classes">
                    <?php if ($tipo === "Tutor"): ?>
                        <h3>Aún no tienes ofertas de tutoría</h3>
                        <p>Utiliza «Ofrecer tutoría» para publicar tu primera oferta.</p>
                    <?php else: ?>
                        <h3>Aún no tienes solicitudes de tutoría</h3>
                        <p>Busca una materia en el catálogo para solicitar una tutoría.</p>
                    <?php endif; ?>
                </div>

            <?php endif; ?>
        </div>
    </main>

</div>

<!-- =====================================================
     PANEL DE CHAT
===================================================== -->
<aside id="chatPanel" class="chat-panel" aria-hidden="true">
    <!-- HEADER DEL CHAT -->
    <div class="chat-header">
        <div>
            <h2>Chats</h2>
            <span>Comunicación de tus clases</span>
        </div>

        <button type="button" id="btnCerrarChat" class="chat-close" aria-label="Cerrar chat">&times;</button>
    </div>

    <!-- CUERPO -->
    <div class="chat-body">
        <!-- LISTA DE CHATS -->
        <div id="chatListView" class="chat-list-view">
            <div class="chat-section-title">Tus chats</div>

            <div id="chatList" class="chat-list">
                <div class="chat-loading">Cargando chats...</div>
            </div>
        </div>

        <!-- CONVERSACIÓN -->
        <div id="chatConversationView" class="chat-conversation-view hidden">
            <!-- BARRA SUPERIOR -->
            <div class="conversation-topbar">
                <button type="button" id="btnVolverChats" class="back-chat">←</button>

                <div>
                    <strong id="conversationTitle">Chat</strong>
                    <span id="conversationSubtitle">Clase</span>
                </div>
            </div>

            <!-- MENSAJES -->
            <div id="messagesContainer" class="messages-container">
                <div class="chat-loading">Selecciona un chat.</div>
            </div>

            <!-- FORMULARIO -->
            <form id="messageForm" class="message-form">
                <input type="text" id="messageInput" name="mensaje" placeholder="Escribe un mensaje..." maxlength="2000" autocomplete="off" required>
                <button type="submit" id="btnEnviarMensaje" aria-label="Enviar mensaje">➤</button>
            </form>
        </div>
    </div>
</aside>

<!-- =====================================================
     MODAL UNIRSE A CLASE-- YA LO BORRE
===================================================== -->

<script>
/* =========================================================
   ELEMENTOS DEL CHAT
========================================================= */
const btnChat = document.getElementById('btnChat');
const btnCerrarChat = document.getElementById('btnCerrarChat');
const chatPanel = document.getElementById('chatPanel');
const chatList = document.getElementById('chatList');
const chatListView = document.getElementById('chatListView');
const chatConversationView = document.getElementById('chatConversationView');
const btnVolverChats = document.getElementById('btnVolverChats');
const conversationTitle = document.getElementById('conversationTitle');
const conversationSubtitle = document.getElementById('conversationSubtitle');
const messagesContainer = document.getElementById('messagesContainer');
const messageForm = document.getElementById('messageForm');
const messageInput = document.getElementById('messageInput');

let chatActual = null;
let intervaloMensajes = null;
const solicitudChatInicial = <?php echo $abrir_chat_id; ?>;

/* =========================================================
   ABRIR PANEL
========================================================= */
function abrirChatPanel() {
    chatPanel.classList.add('open');
    chatPanel.setAttribute('aria-hidden', 'false');
    cargarListaChats();
}

/* =========================================================
   CERRAR PANEL
========================================================= */
function cerrarChatPanel() {
    chatPanel.classList.remove('open');
    chatPanel.setAttribute('aria-hidden', 'true');
    detenerActualizacionMensajes();
}

/* =========================================================
   CARGAR LISTA DE CHATS
========================================================= */
function cargarListaChats() {
    chatList.innerHTML = '<div class="chat-loading">Cargando chats...</div>';

    fetch('menu.php?chat_action=list')
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            chatList.innerHTML = '<div class="chat-empty">No se pudieron cargar los chats.</div>';
            return;
        }

        if (data.chats.length === 0) {
            chatList.innerHTML = '<div class="chat-empty">No tienes chats disponibles todavía.<br><br>Debes pertenecer a una clase para poder hablar en su chat.</div>';
            return;
        }

        chatList.innerHTML = '';

                if (solicitudChatInicial > 0) {
            const chatEncontrado = data.chats.find(
                chat => Number(chat.solicitud_id) === solicitudChatInicial
            );

            if (chatEncontrado) {
                abrirConversacion(chatEncontrado);
                return;
            }
        }

        data.chats.forEach(chat => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'chat-item';

            const icono = document.createElement('div');
            icono.className = 'chat-avatar';
            icono.textContent = chat.materia_nombre.charAt(0).toUpperCase();

            const info = document.createElement('div');
            info.className = 'chat-item-info';

            const nombreClase = document.createElement('strong');
            nombreClase.textContent = chat.tema_solicitado || chat.materia_nombre;
            const nombreTutor = document.createElement('span');

            <?php if ($tipo === 'Tutor'): ?>
                nombreTutor.textContent = 'Chat de tu clase';
            <?php else: ?>
                nombreTutor.textContent = 'Tutor: ' + chat.tutor_nombre;
            <?php endif; ?>

            info.appendChild(nombreClase);
            info.appendChild(nombreTutor);

            item.appendChild(icono);
            item.appendChild(info);

            item.addEventListener('click', () => abrirConversacion(chat));

            chatList.appendChild(item);
        });
    })
    .catch(() => {
        chatList.innerHTML = '<div class="chat-empty">No se pudo conectar con el servidor.</div>';
    });
}

/* =========================================================
   ABRIR CONVERSACIÓN
========================================================= */
function abrirConversacion(chat) {
    chatActual = chat;
    conversationTitle.textContent = chat.materia_nombre;

    <?php if ($tipo === 'Tutor'): ?>
        conversationSubtitle.textContent = 'Chat de la clase';
    <?php else: ?>
        conversationSubtitle.textContent = 'Tutor: ' + chat.tutor_nombre;
    <?php endif; ?>

    chatListView.classList.add('hidden');
    chatConversationView.classList.remove('hidden');

    cargarMensajes(true);
    iniciarActualizacionMensajes();
}

/* =========================================================
   VOLVER A LISTA
========================================================= */
function volverAListaChats() {
    chatActual = null;
    detenerActualizacionMensajes();

    chatConversationView.classList.add('hidden');
    chatListView.classList.remove('hidden');

    cargarListaChats();
}

/* =========================================================
   CARGAR MENSAJES
========================================================= */
function cargarMensajes(scrollAlFinal = false) {
    if (!chatActual) {
        return;
    }

        fetch('menu.php?chat_action=messages&solicitud_id=' + encodeURIComponent(chatActual.solicitud_id))    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            messagesContainer.innerHTML = '<div class="chat-empty">No tienes permiso para ver este chat.</div>';
            return;
        }

        const estabaAbajo = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight < 80;
        messagesContainer.innerHTML = '';

        if (data.mensajes.length === 0) {
            messagesContainer.innerHTML = '<div class="chat-empty conversation-empty">Todavía no hay mensajes.<br>¡Sé el primero en escribir!</div>';
            return;
        }

        data.mensajes.forEach(msg => {
            const fila = document.createElement('div');
            fila.className = 'message-row ' + (msg.usuario_id === <?php echo $usuario_id; ?> ? 'mine' : 'theirs');

            const burbuja = document.createElement('div');
            burbuja.className = 'message-bubble';

            if (msg.usuario_id !== <?php echo $usuario_id; ?>) {
                const autor = document.createElement('div');
                autor.className = 'message-author';
                autor.textContent = msg.nombre;
                burbuja.appendChild(autor);
            }

            const texto = document.createElement('div');
            texto.className = 'message-text';
            texto.textContent = msg.mensaje;

            const hora = document.createElement('div');
            hora.className = 'message-time';
            hora.textContent = msg.hora;

            burbuja.appendChild(texto);
            burbuja.appendChild(hora);
            fila.appendChild(burbuja);

            messagesContainer.appendChild(fila);
        });

        if (scrollAlFinal || estabaAbajo) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    })
    .catch(() => {
        // Evita mostrar errores cada 3 segundos.
    });
}

/* =========================================================
   ACTUALIZACIÓN AUTOMÁTICA
========================================================= */
function iniciarActualizacionMensajes() {
    detenerActualizacionMensajes();
    intervaloMensajes = setInterval(() => cargarMensajes(false), 3000);
}

function detenerActualizacionMensajes() {
    if (intervaloMensajes) {
        clearInterval(intervaloMensajes);
        intervaloMensajes = null;
    }
}

/* =========================================================
   ENVIAR MENSAJE
========================================================= */
messageForm.addEventListener('submit', function(e) {
    e.preventDefault();

    if (!chatActual) {
        return;
    }

    const mensaje = messageInput.value.trim();

    if (!mensaje) {
        return;
    }

    const datos = new FormData();
    datos.append('solicitud_id', chatActual.solicitud_id);
    datos.append('mensaje', mensaje);

    const boton = document.getElementById('btnEnviarMensaje');
    boton.disabled = true;

    fetch('menu.php?chat_action=send', {
        method: 'POST',
        body: datos
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'No se pudo enviar el mensaje.');
            return;
        }

        messageInput.value = '';
        cargarMensajes(true);
        messageInput.focus();
    })
    .catch(() => {
        alert('No se pudo conectar con el servidor.');
    })
    .finally(() => {
        boton.disabled = false;
    });
});

/* =========================================================
   BOTONES DEL CHAT
========================================================= */
btnChat.addEventListener('click', function() {
    if (chatPanel.classList.contains('open')) {
        cerrarChatPanel();
    } else {
        abrirChatPanel();
    }
});
if (solicitudChatInicial > 0) {
    abrirChatPanel();
}

btnCerrarChat.addEventListener('click', cerrarChatPanel);
btnVolverChats.addEventListener('click', volverAListaChats);

/* =========================================================
   MODAL UNIRSE A CLASE -- YA LO BORRE
========================================================= */

</script>
</body>
</html>
<?php
$conexion->close();
?>