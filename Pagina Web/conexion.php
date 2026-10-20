<?php
// Conexion compartida para todas las paginas de TutorTime.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conexion = new mysqli("localhost", "root", "", "pruebas_tt");
    $conexion->set_charset("utf8mb4");
} catch (mysqli_sql_exception $error) {
    error_log("TutorTime: error de conexion a MySQL: " . $error->getMessage());
    http_response_code(500);

    if (defined('TUTORTIME_RESPUESTA_JSON')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión a la base de datos.'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo 'No fue posible conectar con la base de datos. Verifica que MySQL esté iniciado y que exista la base pruebas_tt.';
    }
    exit();
}
