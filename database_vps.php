<?php
// Configuración básica de la base de datos
define('DB_HOST', 'localhost');
define('DB_USER', 'u477481062_usr_iuqZHoXU');
define('DB_PASS', 'xIxRJ|9Wc');
define('DB_NAME', 'u477481062_db_iuqZHoXU');

// Imponer Huso Horario de Ciudad de México en todo el ecosistema
date_default_timezone_set('America/Mexico_City');

try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    // Asegurar que todas las fechas NOW() insertadas en MySQL estén en CDMX
    $pdo->exec("SET time_zone = '-06:00'");
} catch (PDOException $e) {
    // En producción, es mejor registrar este error en un log y mostrar un mensaje genérico.
    // die("Error de conexión a la base de datos.");
    die("Connection failed: " . $e->getMessage());
}
?>
