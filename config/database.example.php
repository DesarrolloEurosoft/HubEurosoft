<?php
// EJEMPLO DE CONFIGURACIÓN - ESTE ARCHIVO SÍ SE SUBE A GIT
// Los desarrolladores deben copiar este archivo, renombrarlo a `database.php`
// y cambiar las credenciales para apuntar a su MySQL local o VPS beta.
// IMPORTANTE: `database.php` está ignorado en .gitignore y nunca debe subirse.

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); 
define('DB_NAME', 'hubeurosoft_db');

date_default_timezone_set('America/Mexico_City');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    $pdo->exec("SET time_zone = '-06:00'");
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
