<?php
require 'config/database.php'; // Esto usará PDO

echo "<h1>Instalador de HubEurosoft Classic</h1>";

try {
    // 1. Crear la tabla de usuarios
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);
    echo "<p>✅ Tabla 'users' verificada o creada correctamente.</p>";

    // 2. Verificar si el admin ya existe
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['admin@admin.com']);
    
    if ($stmt->rowCount() == 0) {
        // Encriptar contraseña 'admin'
        $hashed_password = password_hash('admin', PASSWORD_DEFAULT);
        
        $insert = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
        $insert->execute(['Administrador', 'admin@admin.com', $hashed_password, 'admin']);
        
        echo "<p>✅ Usuario Administrador creado exitosamente.</p>";
        echo "<p><strong>Email:</strong> admin@admin.com<br><strong>Contraseña:</strong> admin</p>";
    } else {
        echo "<p>ℹ️ El usuario administrador ya existe en la base de datos.</p>";
    }

    echo "<hr><p>¡Instalación completada! Ya puedes <a href='login.php'>Iniciar Sesión</a>.</p>";

} catch (PDOException $e) {
    echo "<p style='color:red;'>❌ Error durante la instalación: " . $e->getMessage() . "</p>";
    echo "<p>Asegúrate de haber creado la base de datos 'hubeurosoft_db' en tu phpMyAdmin primero.</p>";
}
?>
