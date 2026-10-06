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

$conexion->set_charset("utf8mb4");

$tipo = $_SESSION['tipo'] ?? '';
$nombre = $_SESSION['nombre'] ?? '';
$usuario_id = (int)($_SESSION['id'] ?? 0);

/* =========================================================
   FUNCIONES DEL CHAT
========================================================= */

function responder_json($datos)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit();
}

/*
    Verifica que el usuario realmente tenga acceso
    al chat de determinada materia.
*/
function usuario_puede_ver_chat($conexion, $usuario_id, $tipo, $materia_id)
{
    if ($tipo === 'Tutor') {
        $stmt = $conexion->prepare("SELECT id FROM materias WHERE id = ? AND tutor_id = ? LIMIT 1");
        $stmt->bind_param("ii", $materia_id, $usuario_id);
    } else {
        $stmt = $conexion->prepare("SELECT m.id FROM materias m INNER JOIN alumnos_materias am ON am.materia_id = m.id WHERE m.id = ? AND am.alumno_id = ? LIMIT 1");
        $stmt->bind_param("ii", $materia_id, $usuario_id);
    }

    $stmt->execute();
    $permitido = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $permitido;
}

/*
    Busca el chat de una materia.
    Si por alguna razón la materia todavía no tiene
    chat, lo crea automáticamente.
*/
function obtener_chat_id($conexion, $materia_id)
{
    $stmt = $conexion->prepare("SELECT id FROM chats WHERE materia_id = ? LIMIT 1");
    $stmt->bind_param("i", $materia_id);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $chat = $resultado->fetch_assoc();
    $stmt->close();

    if (!$chat) {
        $stmt = $conexion->prepare("INSERT INTO chats (materia_id) VALUES (?)");
        $stmt->bind_param("i", $materia_id);
        $stmt->execute();
        $chat_id = $stmt->insert_id;
        $stmt->close();

        return $chat_id;
    }

    return (int)$chat['id'];
}

/* =========================================================
   API DEL CHAT
   Todo se maneja desde menu.php
========================================================= */

if (isset($_GET['chat_action'])) {
    $accion = $_GET['chat_action'];

    /* =====================================================
       LISTAR CHATS
    ===================================================== */
    if ($accion === 'list') {
        /*
            Si es Tutor: aparecen los chats de sus propias clases.
            Si es Alumno: aparecen solamente los chats de las clases
            en las que está inscrito.
        */
        if ($tipo === 'Tutor') {
            $stmt = $conexion->prepare("SELECT m.id AS materia_id, m.nombre AS materia_nombre, u.nombres AS tutor_nombre, u.apellidos AS tutor_apellidos FROM materias m INNER JOIN usuarios u ON u.id = m.tutor_id WHERE m.tutor_id = ? ORDER BY m.fecha_creacion DESC");
            $stmt->bind_param("i", $usuario_id);
        } else {
            $stmt = $conexion->prepare("SELECT m.id AS materia_id, m.nombre AS materia_nombre, u.nombres AS tutor_nombre, u.apellidos AS tutor_apellidos FROM materias m INNER JOIN alumnos_materias am ON am.materia_id = m.id INNER JOIN usuarios u ON u.id = m.tutor_id WHERE am.alumno_id = ? ORDER BY m.fecha_creacion DESC");
            $stmt->bind_param("i", $usuario_id);
        }

        $stmt->execute();
        $resultado = $stmt->get_result();
        $chats = [];

        while ($fila = $resultado->fetch_assoc()) {
            $chats[] = [
                'materia_id' => (int)$fila['materia_id'],
                'materia_nombre' => $fila['materia_nombre'],
                'tutor_nombre' => trim($fila['tutor_nombre'] . ' ' . $fila['tutor_apellidos'])
            ];
        }

        $stmt->close();

        responder_json([
            'success' => true,
            'chats' => $chats
        ]);
    }

    /* =====================================================
       OBTENER MENSAJES
    ===================================================== */
    if ($accion === 'messages') {
        $materia_id = (int)($_GET['materia_id'] ?? 0);

        /*
            Seguridad: el usuario debe pertenecer a la clase
            o ser el tutor de esa clase.
        */
        if ($materia_id <= 0 || !usuario_puede_ver_chat($conexion, $usuario_id, $tipo, $materia_id)) {
            responder_json([
                'success' => false,
                'message' => 'No tienes permiso para ver este chat.'
            ]);
        }

        $chat_id = obtener_chat_id($conexion, $materia_id);

        $stmt = $conexion->prepare("SELECT mc.id, mc.usuario_id, mc.mensaje, DATE_FORMAT(mc.fecha, '%H:%i') AS hora, u.nombres AS nombre, u.apellidos AS apellidos FROM mensajes_chat mc INNER JOIN usuarios u ON u.id = mc.usuario_id WHERE mc.chat_id = ? ORDER BY mc.fecha ASC, mc.id ASC");
        $stmt->bind_param("i", $chat_id);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $mensajes = [];

        while ($fila = $resultado->fetch_assoc()) {
            $mensajes[] = [
                'id' => (int)$fila['id'],
                'usuario_id' => (int)$fila['usuario_id'],
                'nombre' => trim($fila['nombre'] . ' ' . $fila['apellidos']),
                'mensaje' => $fila['mensaje'],
                'hora' => $fila['hora']
            ];
        }

        $stmt->close();

        responder_json([
            'success' => true,
            'materia_id' => $materia_id,
            'chat_id' => $chat_id,
            'mensajes' => $mensajes
        ]);
    }

    /* =====================================================
       ENVIAR MENSAJE
    ===================================================== */
    if ($accion === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $materia_id = (int)($_POST['materia_id'] ?? 0);
        $mensaje = trim($_POST['mensaje'] ?? '');

        /* Verificar permisos */
        if ($materia_id <= 0 || !usuario_puede_ver_chat($conexion, $usuario_id, $tipo, $materia_id)) {
            responder_json([
                'success' => false,
                'message' => 'No tienes permiso para usar este chat.'
            ]);
        }

        /* Mensaje vacío */
        if ($mensaje === '') {
            responder_json([
                'success' => false,
                'message' => 'Escribe un mensaje.'
            ]);
        }

        /* Máximo 2000 caracteres */
        if (mb_strlen($mensaje) > 2000) {
            responder_json([
                'success' => false,
                'message' => 'El mensaje es demasiado largo.'
            ]);
        }

        $chat_id = obtener_chat_id($conexion, $materia_id);

        /* Guardar mensaje */
        $stmt = $conexion->prepare("INSERT INTO mensajes_chat (chat_id, usuario_id, mensaje) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $chat_id, $usuario_id, $mensaje);

        if (!$stmt->execute()) {
            $stmt->close();
            responder_json([
                'success' => false,
                'message' => 'No se pudo guardar el mensaje.'
            ]);
        }

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
   UNIRSE A UNA CLASE
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['unirse_clase_submit'])) {
    if ($tipo !== "Alumno") {
        $mensaje_error = "Solo los alumnos pueden unirse a una clase.";
    } else {
        $codigo = strtoupper(trim($_POST['codigo'] ?? ''));

        if ($codigo === '') {
            $mensaje_error = "Debes introducir el código de la clase.";
        } else {
            $stmt = $conexion->prepare("SELECT id, nombre FROM materias WHERE codigo = ? LIMIT 1");
            $stmt->bind_param("s", $codigo);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows === 0) {
                $mensaje_error = "No existe ninguna clase con ese código.";
            } else {
                $materia = $res->fetch_assoc();
                $materia_id = (int)$materia['id'];

                /* Verificar si ya está inscrito */
                $stmtCheck = $conexion->prepare("SELECT id FROM alumnos_materias WHERE alumno_id = ? AND materia_id = ? LIMIT 1");
                $stmtCheck->bind_param("ii", $usuario_id, $materia_id);
                $stmtCheck->execute();

                if ($stmtCheck->get_result()->num_rows > 0) {
                    $mensaje_error = "Ya estás inscrito en esta clase.";
                } else {
                    $stmtIns = $conexion->prepare("INSERT INTO alumnos_materias (alumno_id, materia_id) VALUES (?, ?)");
                    $stmtIns->bind_param("ii", $usuario_id, $materia_id);

                    if ($stmtIns->execute()) {
                        /* Garantizar que exista el chat de la clase. */
                        obtener_chat_id($conexion, $materia_id);

                        header("Location: menu.php?exito=1");
                        exit();
                    } else {
                        $mensaje_error = "Ocurrió un error al unirte a la clase.";
                    }

                    $stmtIns->close();
                }

                $stmtCheck->close();
            }

            $stmt->close();
        }
    }
}

if (isset($_GET['exito']) && $_GET['exito'] === '1') {
    $mostrar_exito = true;
}

/* =========================================================
   OBTENER LAS MATERIAS DEL USUARIO
========================================================= */
if ($tipo === "Tutor") {
    $stmt = $conexion->prepare("SELECT * FROM materias WHERE tutor_id = ? ORDER BY fecha_creacion DESC");
    $stmt->bind_param("i", $usuario_id);
} else {
    $stmt = $conexion->prepare("SELECT materias.* FROM materias INNER JOIN alumnos_materias ON materias.id = alumnos_materias.materia_id WHERE alumnos_materias.alumno_id = ? ORDER BY materias.fecha_creacion DESC");
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
        <?php echo htmlspecialchars($nombre); ?>
    </div>

    <div class="logo">
        TutorTime
    </div>

    <div class="header-right">
        <?php if ($tipo === "Tutor"): ?>
            <a href="crear_materias.php" class="btn-link">
                <button type="button" class="btn">Crear clase</button>
            </a>
        <?php else: ?>
            <button type="button" class="btn" id="btnUnirseClase">Unirse a clase</button>
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

    <!-- CONTENIDO -->
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
     MODAL UNIRSE A CLASE
===================================================== -->
<?php if ($tipo === "Alumno"): ?>
<div id="modalClase" class="modal-overlay <?php echo ($mensaje_error !== '') ? 'active' : ''; ?>">
    <div class="modal-card">
        <button type="button" class="modal-close" id="cerrarModal">&times;</button>

        <h2>Unirse a una clase</h2>
        <p>Introduce el código proporcionado por tu tutor.</p>

        <?php if ($mensaje_error !== ''): ?>
            <div class="error-message">
                <?php echo htmlspecialchars($mensaje_error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="menu.php">
            <input type="text" name="codigo" class="class-code-input" placeholder="Ejemplo: PRO-5800" maxlength="20" required autocomplete="off">
            <button type="submit" name="unirse_clase_submit" class="modal-submit">Unirse a clase</button>
        </form>
    </div>
</div>

<!-- MODAL DE ÉXITO -->
<div id="modalExito" class="modal-overlay <?php echo ($mostrar_exito) ? 'active' : ''; ?>">
    <div class="modal-card">
        <div class="success-icon">&#10004;</div>
        <h2>¡Operación Exitosa!</h2>
        <p>La materia se añadió con éxito.</p>
        <a href="menu.php" class="success-button-link">
            <button type="button" class="modal-submit">Aceptar</button>
        </a>
    </div>
</div>
<?php endif; ?>

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
            nombreClase.textContent = chat.materia_nombre;

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

    fetch('menu.php?chat_action=messages&materia_id=' + encodeURIComponent(chatActual.materia_id))
    .then(response => response.json())
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
    datos.append('materia_id', chatActual.materia_id);
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

btnCerrarChat.addEventListener('click', cerrarChatPanel);
btnVolverChats.addEventListener('click', volverAListaChats);

/* =========================================================
   MODAL UNIRSE A CLASE
========================================================= */
const btnAbrirClase = document.getElementById('btnUnirseClase');
const modalClase = document.getElementById('modalClase');
const btnCerrarClase = document.getElementById('cerrarModal');

if (btnAbrirClase && modalClase) {
    btnAbrirClase.addEventListener('click', function() {
        modalClase.classList.add('active');
    });
}

if (btnCerrarClase && modalClase) {
    btnCerrarClase.addEventListener('click', function() {
        modalClase.classList.remove('active');
    });
}

if (modalClase) {
    modalClase.addEventListener('click', function(e) {
        if (e.target === modalClase) {
            modalClase.classList.remove('active');
        }
    });
}
</script>
</body>
</html>
<?php
$conexion->close();
?>