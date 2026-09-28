<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Desactivar impresión de avisos HTML que puedan corromper el JSON en Safari
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesión no iniciada. Por favor, vuelve a iniciar sesión.']);
    exit();
}

$conexion = new mysqli("localhost", "root", "", "tutortime");

if ($conexion->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos.']);
    exit();
}

$tipo = $_SESSION['tipo'] ?? '';
$usuario_id = $_SESSION['id'] ?? 0;

if ($tipo !== "Alumno") {
    echo json_encode(['success' => false, 'message' => 'Solo los alumnos pueden unirse a una clase.']);
    exit();
}

$codigo = strtoupper(trim($_POST['codigo'] ?? ''));

if ($codigo === "") {
    echo json_encode(['success' => false, 'message' => 'Debes introducir el código de la clase.']);
    exit();
}

// 1. Buscar materia por código
$stmt = $conexion->prepare("SELECT id, nombre FROM materias WHERE codigo = ?");
$stmt->bind_param("s", $codigo);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'No existe ninguna clase con ese código.']);
    exit();
}

$materia = $resultado->fetch_assoc();
$materia_id = $materia['id'];

// 2. Verificar si el alumno ya está inscrito
$stmtCheck = $conexion->prepare("SELECT id FROM alumnos_materias WHERE alumno_id = ? AND materia_id = ?");
$stmtCheck->bind_param("ii", $usuario_id, $materia_id);
$stmtCheck->execute();
$resultadoCheck = $stmtCheck->get_result();

if ($resultadoCheck->num_rows > 0) {
    echo json_encode(['success' => false, 'message' => 'Ya estás inscrito en esta clase.']);
    exit();
}

// 3. Registrar la inscripción
$stmtInsert = $conexion->prepare("INSERT INTO alumnos_materias (alumno_id, materia_id) VALUES (?, ?)");
$stmtInsert->bind_param("ii", $usuario_id, $materia_id);

if ($stmtInsert->execute()) {
    echo json_encode(['success' => true, 'materia' => $materia['nombre']]);
} else {
    echo json_encode(['success' => false, 'message' => 'Ocurrió un error al unirte a la clase.']);
}

$conexion->close();
exit();