<?php
require 'config/database.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS PasswordReset (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId VARCHAR(191) NOT NULL,
        token VARCHAR(191) NOT NULL,
        expiresAt DATETIME NOT NULL,
        createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (token),
        INDEX (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "Tabla PasswordReset creada con éxito.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
