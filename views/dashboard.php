<?php
// Evitar acceso directo
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// Variables de Rol
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$mode = $_GET['mode'] ?? '';

if ($userRole !== 'STUDENT' && $mode !== 'student') {
    require 'dashboard_analytics.php';
} else {
    require 'dashboard_student.php';
}
?>