<?php
    use Firebase\JWT\JWT;
session_start();

header('Content-Type: application/json; charset=utf-8');

// =====================================================
// 1. VERIFICAR SESION
// =====================================================

if (!isset($_SESSION['id'])) {
    http_response_code(401);

    echo json_encode([
        'error' => 'Debes iniciar sesion en TutorTime.'
    ]);

    exit;
}

// =====================================================
// 2. CARGAR CONFIGURACION
// =====================================================

try {

    // Conexion a la base de datos.
    require_once __DIR__ . '/conexion.php';

    // Dependencias de Composer.
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    // Detectar si las credenciales de JaaS vienen
    // de variables de entorno (Render).
    $jaasAppId = getenv('JAAS_APP_ID');
    $jaasApiKeyId = getenv('JAAS_API_KEY_ID');
    $jaasPrivateKeyPath = getenv('JAAS_PRIVATE_KEY_PATH');

    if (
        $jaasAppId !== false &&
        $jaasApiKeyId !== false
    ) {

        // Configuracion para Render.
        define('JAAS_APP_ID', $jaasAppId);
        define('JAAS_API_KEY_ID', $jaasApiKeyId);

        define(
            'JAAS_PRIVATE_KEY_PATH',
            $jaasPrivateKeyPath !== false
                ? $jaasPrivateKeyPath
                : '/etc/secrets/jaas_private.pem'
        );

    } else {

        // Configuracion local de XAMPP.
        require_once 'C:/xampp/jaas/jaas_config.php';

    }

    // Verificar que existan las constantes.
    if (
        !defined('JAAS_APP_ID') ||
        !defined('JAAS_API_KEY_ID') ||
        !defined('JAAS_PRIVATE_KEY_PATH')
    ) {
        throw new Exception('Configuracion JaaS incompleta.');
    }

    // Cargar biblioteca JWT.


} catch (Throwable $e) {

    error_log(
        'Error cargando configuracion TutorTime: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'error' => 'No fue posible cargar la configuracion del servidor.'
    ]);

    exit;
}

// =====================================================
// 3. VALIDAR DATOS DEL USUARIO
// =====================================================

$usuario_id = (int) $_SESSION['id'];
$rol = $_SESSION['tipo'] ?? '';

if ($rol !== 'Alumno' && $rol !== 'Tutor') {
    http_response_code(403);

    echo json_encode([
        'error' => 'Tu usuario no tiene permiso para entrar.'
    ]);

    exit;
}

// =====================================================
// 4. VALIDAR ID DE SOLICITUD
// =====================================================

$solicitud_id = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$solicitud_id || $solicitud_id <= 0) {
    http_response_code(400);

    echo json_encode([
        'error' => 'El ID de la solicitud no es valido.'
    ]);

    exit;
}

// =====================================================
// 5. CONSULTAR SOLICITUD
// =====================================================

$sql = "SELECT
            s.id,
            s.alumno_id,
            s.tutor_id,
            s.sala_videollamada,
            u.nombres,
            u.apellidos,
            u.correo
        FROM solicitudes_tutorias s
        INNER JOIN usuarios u
            ON u.id = ?
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
// 6. COMPROBAR ACCESO
// =====================================================

if (!$solicitud) {
    http_response_code(403);

    echo json_encode([
        'error' => 'No tienes permiso para acceder a esta videollamada.'
    ]);

    exit;
}

// Comprobar que el rol coincida con la solicitud.
$esTutor = ($usuario_id === (int) $solicitud['tutor_id']);
$esAlumno = ($usuario_id === (int) $solicitud['alumno_id']);

if (
    ($rol === 'Tutor' && !$esTutor) ||
    ($rol === 'Alumno' && !$esAlumno)
) {
    http_response_code(403);

    echo json_encode([
        'error' => 'Tu rol no corresponde a esta solicitud.'
    ]);

    exit;
}

// =====================================================
// 7. PREPARAR DATOS DEL PARTICIPANTE
// =====================================================

$nombre = trim(
    $solicitud['nombres'] . ' ' . $solicitud['apellidos']
);

$nombreSala = $solicitud['sala_videollamada'];

// Solamente el tutor sera moderador.
$moderador = $esTutor;

// =====================================================
// 8. GENERAR TOKEN JWT
// =====================================================

try {

    if (
        !file_exists(JAAS_PRIVATE_KEY_PATH) ||
        !is_readable(JAAS_PRIVATE_KEY_PATH)
    ) {
        throw new Exception('No se encontro la clave privada.');
    }

    $clave = file_get_contents(JAAS_PRIVATE_KEY_PATH);

    if ($clave === false) {
        throw new Exception('No se pudo leer la clave privada.');
    }

    $clavePrivada = openssl_pkey_get_private($clave);

    if ($clavePrivada === false) {
        throw new Exception('La clave privada no es valida.');
    }

    $ahora = time();

    $payload = [

        'aud' => 'jitsi',

        'iss' => 'chat',

        'sub' => JAAS_APP_ID,

        'room' => $nombreSala,

        'nbf' => $ahora - 10,

        'exp' => $ahora + 7200,

        'context' => [

            'user' => [
                'id' => 'tutortime-' . $usuario_id,
                'name' => $nombre,
                'email' => $solicitud['correo'],
                'moderator' => $moderador ? 'true' : 'false'
            ],

            'room' => [
                'regex' => false
            ],

            'features' => [
                'livestreaming' => false,
                'recording' => false,
                'transcription' => false
            ]
        ]
    ];

    $token = JWT::encode(
        $payload,
        $clavePrivada,
        'RS256',
        JAAS_API_KEY_ID
    );

    // Liberar la clave.
    openssl_free_key($clavePrivada);

    // =================================================
    // 9. DEVOLVER DATOS A VIDEOLLAMADA.PHP
    // =================================================

    echo json_encode([

        'token' => $token,

        'room' => JAAS_APP_ID . '/' . $nombreSala,

        'name' => $nombre,

        'moderator' => $moderador

    ]);

} catch (Throwable $e) {

    error_log(
        'Error generando JWT JaaS: ' . $e->getMessage()
    );

    http_response_code(500);

    echo json_encode([
        'error' => 'No fue posible generar el acceso a la videollamada.'
    ]);
}
