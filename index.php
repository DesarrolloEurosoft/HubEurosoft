<?php
error_reporting(0);
ini_set('display_errors', '0');
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) { 
    header('Location: login.php'); 
    exit; 
}
require 'config/database.php';

// Sistema de Enrutamiento Frontal
$view = $_GET['view'] ?? 'dashboard';

// Diccionario de Paginación para Header Dinámico
$pageTitles = [
    'dashboard' => ['Dashboard Estadístico', 'CONTROL MAESTRO'],
    'companies' => ['Gestión de Clientes', 'ORGANIZACIÓN CORPORATIVA'],
    'roles' => ['Perfiles y Roles', 'CONTROL DE ACCESOS'],
    'students' => ['Directorio de Usuarios', 'ASIGNACIONES Y MÉTRICAS'],
    'courses' => ['Catálogo de Cursos', 'OFERTA ACADÉMICA'],
    'learning_paths' => ['Rutas de Aprendizaje', 'CURRÍCULAS ESTRUCTURADAS'],
    'quizzes' => ['Evaluaciones', 'BANCO DE PREGUNTAS'],
    'certificates' => ['Certificados Emitidos', 'DIPLOMAS Y LOGROS'],
    'gamification' => ['Puntos y Medallas', 'GAMIFICACIÓN'],
    'forums' => ['Foros de Discusión', 'APRENDIZAJE APLICADO'],
    'forum_topic' => ['Hilo de Discusión', 'APRENDIZAJE APLICADO'],
    'forum_moderation' => ['Moderación Eurosoft', 'HOMOLOGACIÓN DE PROCESOS'],
    'settings' => ['Configuración General', 'PREFERENCIAS Y SISTEMA'],
    'lesson' => ['Visor de Lección', 'APRENDIZAJE EN CURSO'],
    'take_quiz' => ['Evaluación Final', 'CERTIFICACIÓN ACADÉMICA'],
    'activity_log' => ['Panel de Control', 'CONTROL MAESTRO']
];
$activeTitle = $pageTitles[$view][0] ?? 'Módulo no Definido';
$activeSubtitle = $pageTitles[$view][1] ?? 'PROCESO INTERNO';

// Interceptor de Endpoints AJAX puros (Evita imprimir HTML)
if ($view === 'students' && isset($_GET['action']) && $_GET['action'] === 'get_progress') {
    include 'views/students.php';
    exit;
}

// Fetch User Avatar
$stmtAvatar = $pdo->prepare("SELECT name, image FROM User WHERE id = ?");
$stmtAvatar->execute([$_SESSION['user_id']]);
$userRow = $stmtAvatar->fetch(PDO::FETCH_ASSOC);
$userAvatar = $userRow['image'] ?? null;
$userName = $userRow['name'] ?? 'Usuario';

include 'includes/header.php';

// Detectar rol del usuario
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$isAdmin = in_array($userRole, ['ADMIN', 'INSTRUCTOR']);
$isLeader = in_array($userRole, ['COMPANY_LEADER', 'BUSINESS_UNIT_LEADER']);
$isStudent = ($userRole === 'STUDENT');

// Protección de vistas por rol
$allowedStudentViews = ['dashboard', 'courses', 'lesson', 'settings', 'take_quiz', 'forums', 'forum_topic', 'ranking', 'certificates'];
$allowedLeaderViews = ['dashboard', 'courses', 'lesson', 'settings', 'take_quiz', 'forums', 'forum_topic', 'forum_moderation', 'ranking'];

if ($isStudent && !in_array($view, $allowedStudentViews)) { $view = 'dashboard'; }
elseif ($isLeader && !in_array($view, $allowedLeaderViews)) { $view = 'dashboard'; }

// ═══════════════════════════════════════════════════════════
// LAYOUT UNIFICADO V3 (Para todos los roles)
// ═══════════════════════════════════════════════════════════
include 'includes/student_topnav.php';
?>
<main style="flex: 1; overflow-y: auto;">
    <div class="v3-page-content">
        <?php
        $view_file = "views/{$view}.php";
        
        // Enrutamiento condicional para Dashboard
        if ($view === 'dashboard') {
            if ($isStudent) {
                include 'views/dashboard_student.php';
            } else {
                include 'views/dashboard.php';
            }
        } elseif (file_exists($view_file)) {
            include $view_file;
        } else {
            echo "<h2>📍 Módulo en construcción</h2><p>La vista '<strong>{$view}</strong>' aún no ha sido encontrada en el sistema.</p>";
        }
        ?>
    </div>
</main>
<?php
include 'includes/footer.php';
exit;
