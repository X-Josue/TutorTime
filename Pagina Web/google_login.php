
<?php
session_start();

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/google_config.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use Google\Client as GoogleClient;

// Función para mostrar errores de forma sencilla.
function mostrarError($mensaje) {
    echo '<!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Google Login - TutorTime</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="alert alert-danger">
                ' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '
            </div>
            <a href="login.php" class="btn btn-primary">Volver al inicio de sesión</a>
        </div>
    </body>
    </html>';
    exit();
}

// Iniciar sesión de TutorTime después de validar al usuario.
function iniciarSesionTutorTime($usuario) {
    session_regenerate_id(true);

    $_SESSION['id'] = $usuario['id'];
    $_SESSION['correo'] = $usuario['correo'];
    $_SESSION['tipo'] = $usuario['rol'];
    $_SESSION['nombre'] = $usuario['nombres'];

    unset(
        $_SESSION['google_pending_user_id'],
        $_SESSION['google_pending_sub'],
        $_SESSION['google_pending_email'],
        $_SESSION['google_link_csrf']
    );

    header('Location: menu.php');
    exit();
}

// --------------------------------------------------
// 1. Vincular una cuenta existente mediante contraseña
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'link_existing') {

    if (
        !isset($_SESSION['google_link_csrf']) ||
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['google_link_csrf'], $_POST['csrf_token'])
    ) {
        mostrarError('La solicitud no es válida. Intenta nuevamente.');
    }

    if (
        empty($_SESSION['google_pending_user_id']) ||
        empty($_SESSION['google_pending_sub'])
    ) {
        mostrarError('No hay una cuenta pendiente de vinculación.');
    }

    $usuario_id = $_SESSION['google_pending_user_id'];
    $google_sub = $_SESSION['google_pending_sub'];
    $password = $_POST['contrasena'] ?? '';

    $consulta = $conexion->prepare(
        "SELECT id, nombres, correo, contrasena, rol, google_sub
         FROM usuarios WHERE id = ?"
    );

    $consulta->bind_param("i", $usuario_id);
    $consulta->execute();

    $resultado = $consulta->get_result();
    $usuario = $resultado->fetch_assoc();
    $consulta->close();

    if (!$usuario || !password_verify($password, $usuario['contrasena'])) {
        mostrarError('La contraseña de TutorTime es incorrecta.');
    }

    // Evitar asociar una identidad de Google que ya pertenezca a otra cuenta.
    if (
        !empty($usuario['google_sub']) &&
        !hash_equals($usuario['google_sub'], $google_sub)
    ) {
        mostrarError('Esta cuenta de TutorTime ya tiene otra cuenta de Google vinculada.');
    }

    $actualizar = $conexion->prepare(
        "UPDATE usuarios
         SET google_sub = ?
         WHERE id = ? AND google_sub IS NULL"
    );

    $actualizar->bind_param("si", $google_sub, $usuario_id);
    $actualizar->execute();

    // Volver a consultar para confirmar la vinculación.
    $verificar = $conexion->prepare(
        "SELECT id, nombres, correo, rol, google_sub
         FROM usuarios WHERE id = ?"
    );

    $verificar->bind_param("i", $usuario_id);
    $verificar->execute();

    $usuarioActualizado = $verificar->get_result()->fetch_assoc();

    $verificar->close();
    $actualizar->close();

    if (
        !$usuarioActualizado ||
        !hash_equals($google_sub, $usuarioActualizado['google_sub'])
    ) {
        mostrarError('No fue posible vincular la cuenta de Google.');
    }

    iniciarSesionTutorTime($usuarioActualizado);
}

// --------------------------------------------------
// 2. Recibir y verificar la credencial de Google
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    mostrarError('No se recibió una credencial válida de Google.');
}

// Validación CSRF de Google Identity Services.
$csrfCookie = $_COOKIE['g_csrf_token'] ?? '';
$csrfPost = $_POST['g_csrf_token'] ?? '';

if (
    empty($csrfCookie) ||
    empty($csrfPost) ||
    !hash_equals($csrfCookie, $csrfPost)
) {
    mostrarError('La validación de seguridad de Google no fue correcta.');
}

// Verificar el token directamente con Google.
$cliente = new GoogleClient([
    'client_id' => GOOGLE_CLIENT_ID
]);

$datosGoogle = $cliente->verifyIdToken($_POST['credential']);

if (!$datosGoogle) {
    mostrarError('No fue posible verificar tu cuenta de Google.');
}

// Comprobar que Google confirmó el correo.
if (
    !isset($datosGoogle['email_verified']) ||
    $datosGoogle['email_verified'] !== true
) {
    mostrarError('Tu correo de Google no está verificado.');
}

$google_sub = $datosGoogle['sub'];
$correo = strtolower(trim($datosGoogle['email']));

$nombres = $datosGoogle['given_name'] ?? '';
$apellidos = $datosGoogle['family_name'] ?? '';

// Respaldo por si Google no proporciona nombre o apellidos.
if (empty($nombres)) {
    $nombreCompleto = $datosGoogle['name'] ?? 'Usuario';
    $partes = explode(' ', $nombreCompleto, 2);

    $nombres = $partes[0];
    $apellidos = $partes[1] ?? '';
}

$nombres = mb_substr($nombres, 0, 100);
$apellidos = mb_substr($apellidos, 0, 100);

// --------------------------------------------------
// 3. Buscar si la identidad de Google ya está vinculada
// --------------------------------------------------

$consulta = $conexion->prepare(
    "SELECT id, nombres, correo, rol, google_sub
     FROM usuarios WHERE google_sub = ?"
);

$consulta->bind_param("s", $google_sub);
$consulta->execute();

$usuario = $consulta->get_result()->fetch_assoc();
$consulta->close();

if ($usuario) {
    iniciarSesionTutorTime($usuario);
}

// --------------------------------------------------
// 4. Buscar si el correo ya existe en TutorTime
// --------------------------------------------------

$consulta = $conexion->prepare(
    "SELECT id, nombres, correo, contrasena, rol, google_sub
     FROM usuarios WHERE correo = ? LIMIT 1"
);

$consulta->bind_param("s", $correo);
$consulta->execute();

$usuario = $consulta->get_result()->fetch_assoc();
$consulta->close();

if ($usuario) {

    // Si ya existe otra identidad de Google, no permitir reemplazarla.
    if (!empty($usuario['google_sub'])) {
        mostrarError('Este correo ya está vinculado a otra cuenta de Google.');
    }

    // Solicitar la contraseña actual antes de vincular.
    $_SESSION['google_pending_user_id'] = $usuario['id'];
    $_SESSION['google_pending_sub'] = $google_sub;
    $_SESSION['google_pending_email'] = $correo;
    $_SESSION['google_link_csrf'] = bin2hex(random_bytes(32));

    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Vincular cuenta - TutorTime</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
        <div class="container mt-5">
            <div class="card mx-auto shadow" style="max-width: 450px;">
                <div class="card-body p-4">
                    <h4 class="text-center mb-3">Vincular cuenta de Google</h4>

                    <p>
                        Ya existe una cuenta de TutorTime con el correo:
                    </p>

                    <p class="text-center fw-bold">
                        <?= htmlspecialchars($correo, ENT_QUOTES, 'UTF-8') ?>
                    </p>

                    <p>
                        Para conservar tu cuenta y tu rol actual,
                        introduce tu contraseña de TutorTime.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="action" value="link_existing">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['google_link_csrf'], ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <div class="mb-3">
                            <label class="form-label">Contraseña actual</label>
                            <input
                                type="password"
                                name="contrasena"
                                class="form-control"
                                required
                            >
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            Vincular cuenta
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="login.php">Cancelar</a>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// --------------------------------------------------
// 5. Registrar usuario nuevo según su dominio
// --------------------------------------------------

$partesCorreo = explode('@', $correo);

if (count($partesCorreo) !== 2) {
    mostrarError('El correo recibido no es válido.');
}

$dominio = $partesCorreo[1];

$consulta = $conexion->prepare(
    "SELECT rol
     FROM dominios_institucionales
     WHERE dominio = ? AND estado = 1
     LIMIT 1"
);

$consulta->bind_param("s", $dominio);
$consulta->execute();

$regla = $consulta->get_result()->fetch_assoc();
$consulta->close();

if (!$regla) {
    mostrarError('Tu dominio institucional no está autorizado para utilizar TutorTime.');
}

// Nunca permitir que una regla de dominio cree administradores.
if (!in_array($regla['rol'], ['Alumno', 'Tutor'], true)) {
    mostrarError('El rol asignado al dominio no está permitido.');
}

// Crear contraseña aleatoria, ya que Google será el método de acceso.
$passwordAleatoria = bin2hex(random_bytes(32));
$passwordHash = password_hash($passwordAleatoria, PASSWORD_DEFAULT);

$rol = $regla['rol'];

$insertar = $conexion->prepare(
    "INSERT INTO usuarios
     (nombres, apellidos, correo, contrasena, rol, google_sub)
     VALUES (?, ?, ?, ?, ?, ?)"
);

$insertar->bind_param(
    "ssssss",
    $nombres,
    $apellidos,
    $correo,
    $passwordHash,
    $rol,
    $google_sub
);

$insertar->execute();

$nuevoId = $insertar->insert_id;
$insertar->close();

// Consultar el usuario recién registrado.
$consulta = $conexion->prepare(
    "SELECT id, nombres, correo, rol, google_sub
     FROM usuarios WHERE id = ?"
);

$consulta->bind_param("i", $nuevoId);
$consulta->execute();

$nuevoUsuario = $consulta->get_result()->fetch_assoc();
$consulta->close();

if (!$nuevoUsuario) {
    mostrarError('El usuario fue registrado, pero no fue posible iniciar sesión.');
}

iniciarSesionTutorTime($nuevoUsuario);
