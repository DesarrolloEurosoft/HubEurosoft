<?php
require 'config/database.php';
$col = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='Course' AND COLUMN_NAME='demoFromLessonId'")->fetchColumn();
if ($col) {
    echo "YA EXISTE: demoFromLessonId\n";
} else {
    $pdo->exec("ALTER TABLE Course ADD COLUMN demoFromLessonId VARCHAR(36) DEFAULT NULL AFTER demoUntilLessonId");
    echo "OK: columna demoFromLessonId agregada a Course\n";
}
