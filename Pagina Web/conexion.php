<?php
// Configuración de la base de datos
$host = "localhost"; 
$dbname = "tutortime"; // El nombre de tu base de datos
$username = "root"; // Usuario por defecto en XAMPP/WAMP/MAMP
$password = ""; // Contraseña por defecto suele estar vacía

try {
    // Intentamos conectar
    $conexion = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Quita las dos diagonales de la siguiente línea solo para probar si funciona:
     echo "¡Conexión exitosa a la base de datos Tutor Time!";

} catch(PDOException $e) {
    // Si falla, mostramos el error
    die("Error de conexión: " . $e->getMessage());
}
?>