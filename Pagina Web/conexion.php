
<?php
// Conexion compartida para todas las paginas de TutorTime.

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// En Render se utilizaran variables de entorno.
// En XAMPP se conservaran estos valores locales.
$host = getenv('DB_HOST') ?: 'localhost';
$usuario = getenv('DB_USER') ?: 'root';
$contrasena = getenv('DB_PASSWORD');
$base_datos = getenv('DB_NAME') ?: 'pruebas_tt';
$puerto = (int) (getenv('DB_PORT') ?: 3306);

if ($contrasena === false) {
    $contrasena = '';
}

try {
    $conexion = new mysqli(
        $host,
        $usuario,
        $contrasena,
        $base_datos,
        $puerto
    );

    $conexion->set_charset('utf8mb4');

} catch (mysqli_sql_exception $error) {

    error_log(
        "TutorTime: error de conexion a MySQL: " .
        $error->getMessage()
    );

    http_response_code(500);

    if (defined('TUTORTIME_RESPUESTA_JSON')) {

        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión a la base de datos.'
        ], JSON_UNESCAPED_UNICODE);

    } else {

        echo 'No fue posible conectar con la base de datos.';

    }

    exit();
}