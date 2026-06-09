<?php
require_once 'config/database.php';

echo "<h1>🚀 Migración: Demo Mode — demoUntilLessonId</h1><pre>";

try {
    // Verificar si la columna ya existe
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = 'Course' 
                            AND COLUMN_NAME = 'demoUntilLessonId'");
    $check->execute();
    $exists = (int)$check->fetchColumn();

    if ($exists > 0) {
        echo "✅ La columna demoUntilLessonId ya existe en la tabla Course. No se requiere acción.\n";
    } else {
        $pdo->exec("ALTER TABLE Course ADD COLUMN demoUntilLessonId VARCHAR(36) DEFAULT NULL");
        echo "✅ Columna demoUntilLessonId agregada exitosamente a la tabla Course.\n";
    }

    echo "\n<b style='color:green;'>✅ Migración completada. Puedes eliminar este archivo del servidor.</b>";
} catch (PDOException $e) {
    echo "\n<b style='color:red;'>❌ ERROR: " . $e->getMessage() . "</b>";
}

echo "</pre>";
