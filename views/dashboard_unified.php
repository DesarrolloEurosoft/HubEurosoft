<?php
// views/dashboard_unified.php
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once 'config/database.php';

$userId = $_SESSION['user_id'];
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$isAdmin = in_array($userRole, ['ADMIN', 'INSTRUCTOR']);
$isLeader = in_array($userRole, ['COMPANY_LEADER', 'BUSINESS_UNIT_LEADER']);

// 0. Sincronizar Cursos en Tiempo Real (Evita cursos huérfanos si sus roles cambiaron)
require_once 'utils/assignment_sync.php';
syncAllCourseAssignments($pdo, $userId);

// 1. User Info
$stmtU = $pdo->prepare("SELECT name, email, image FROM User WHERE id = ?");
$stmtU->execute([$userId]);
$user = $stmtU->fetch(PDO::FETCH_ASSOC);

$full_name = htmlspecialchars($user['name'] ?? 'Usuario');
$first_name = explode(' ', trim($full_name))[0];

// Fallbacks de imagen si no tiene real
$avatar_url = !empty($user['image']) ? htmlspecialchars(ltrim($user['image'], '/')) : 'https://ui-avatars.com/api/?name='.urlencode($first_name).'&background=f59e0b&color=fff&size=200';

$roleNameStr = "Estudiante";
if($isAdmin) $roleNameStr = "Administrador Global";
if($isLeader) $roleNameStr = "Líder Corporativo";

// 2. Metrics (Cursos, Certificaciones)
$stmtC = $pdo->prepare("SELECT COUNT(*) FROM CourseProgress WHERE userId = ? AND (isCompleted = 0 OR isCompleted IS NULL)");
$stmtC->execute([$userId]);
$activeCoursesCount = $stmtC->fetchColumn();
$labelCourses = "Cursos Activos";

if($isAdmin || $isLeader) {
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM Course");
    $stmtC->execute();
    $activeCoursesCount = $stmtC->fetchColumn();
    $labelCourses = "Cursos Totales";
}

$stmtCert = $pdo->prepare("SELECT COUNT(*) FROM IssuedCertificate WHERE studentId = ?");
$stmtCert->execute([$userId]);
$certsCount = $stmtCert->fetchColumn();

// Cursos Terminados (Graduaciones) se calcularán más adelante desde la ruta activa
$labelCompleted = "Cursos Terminados";

// 3. XP and Gamification
$stmtG = $pdo->prepare("SELECT totalPoints, streakCount FROM User WHERE id = ?");
$stmtG->execute([$userId]);
$gam = $stmtG->fetch(PDO::FETCH_ASSOC) ?: ['totalPoints' => 0, 'streakCount' => 0];
$totalXP = (int)$gam['totalPoints'];
$level = max(1, floor(sqrt($totalXP / 100)) + 1); // Reverse logic from xpForLevel

// Progreso Nivel
function xpForLevel($lvl) { return ($lvl * $lvl) * 100; }
$xpBase = xpForLevel($level - 1);
$xpNext = xpForLevel($level);
$xpProgress = $totalXP - $xpBase;
$xpNeeded = $xpNext - $xpBase;
$xpPercent = min(100, max(0, ($xpNeeded > 0) ? ($xpProgress / $xpNeeded)*100 : 100));

// 4. Ranking (Top 3 Locales)
$stmtRank = $pdo->query("SELECT id, name, totalPoints FROM User WHERE role = 'STUDENT' ORDER BY totalPoints DESC LIMIT 3");
$ranking = $stmtRank->fetchAll(PDO::FETCH_ASSOC);

$deltaXP = 0;
if (count($ranking) > 0 && $ranking[0]['id'] !== $userId) {
    // Si no somos el rey actual
    $deltaXP = max(0, (int)$ranking[0]['totalPoints'] - $totalXP);
}

// 5. Recent Activity
// HubEurosoft_Classic usa `ActivityLog` o lo simulamos si no existe, como está en test fallando
$stmtAct = $pdo->prepare("SELECT id FROM CourseProgress WHERE userId = ? LIMIT 0");
$activities = []; // Disable for now to prevent fatal error on ActivityLog missing

// 6. Active Courses (Looping banners) aligned with student path logic
require_once 'utils/student_data.php';
$stmtCompany = $pdo->prepare("SELECT companyId FROM User WHERE id = ?");
$stmtCompany->execute([$userId]);
$cId = $stmtCompany->fetchColumn();

// Uses strict explicit-only path lookup across ALL assigned paths for accurate metrics
$stmtAllPaths = $pdo->prepare("
    SELECT DISTINCT c.id as courseId, c.title, c.imageUrl as bannerImage
    FROM Course c
    JOIN LearningPathCourse lpc ON c.id = lpc.courseId
    JOIN _LearningPathToTrainingRole lptr ON lpc.learningPathId = lptr.A
    JOIN _TrainingRoleToUser tru ON lptr.B = tru.A
    WHERE tru.B = ?
");
$stmtAllPaths->execute([$userId]);
$unifiedCourses = $stmtAllPaths->fetchAll(PDO::FETCH_ASSOC);

$myActiveCourses = [];
foreach ($unifiedCourses as $courseData) {
    $coursePct = calculateCourseProgress($pdo, $userId, $courseData['courseId']);
    $stmtCp = $pdo->prepare("SELECT isCompleted FROM CourseProgress WHERE userId = ? AND courseId = ?");
    $stmtCp->execute([$userId, $courseData['courseId']]);
    $cp = $stmtCp->fetch(PDO::FETCH_ASSOC);
    
    $isCompleted = (($cp && $cp['isCompleted'] == 1) || $coursePct >= 100);
    
    if (!$isCompleted) {
        $myActiveCourses[] = [
            "courseId" => $courseData["courseId"],
            "title" => $courseData["title"],
            "bannerImage" => $courseData["bannerImage"],
            "progress" => $coursePct
        ];
    }
}

if(($isAdmin || $isLeader) && empty($myActiveCourses)) {
    $stmtMyC = $pdo->query("SELECT id as courseId, title, imageUrl as bannerImage, 100 as progress FROM Course ORDER BY createdAt DESC");
    $myActiveCourses = $stmtMyC->fetchAll(PDO::FETCH_ASSOC);
}

// Global Progress
$generalProg = 0;
if(empty($myActiveCourses) && ($isAdmin || $isLeader)) {
    $generalProg = 100;
} else {
    $stmtCProg = $pdo->prepare("SELECT COUNT(*) as total_courses FROM CourseProgress WHERE userId = ?");
    $stmtCProg->execute([$userId]);
    $progData = $stmtCProg->fetch(PDO::FETCH_ASSOC);
}

// Calculate the precise mathematical average of all courses in the user's trajectory
$generalProg = 0;
$totalPercSum = 0;
$totalCoursesInPath = count($myActiveCourses);
$coursesCompletedCount = 0;

if($totalCoursesInPath > 0) {
    foreach($myActiveCourses as $c) {
        $totalPercSum += $c['progress'];
        if($c['progress'] >= 100) {
            $coursesCompletedCount++;
        }
    }
    // "115 de 200 significa 57.5% de avance"
    $generalProg = round($totalPercSum / $totalCoursesInPath, 1);
}

// 7. Medallas y Logros de Colección
$stmtAchievs = $pdo->query("SELECT id, title, description, icon, imagePath, color FROM Achievement WHERE isActive = 1 ORDER BY createdAt ASC");
$allAchievements = $stmtAchievs->fetchAll(PDO::FETCH_ASSOC);

$stmtMyAch = $pdo->prepare("SELECT achievementId FROM UserAchievement WHERE userId = ?");
$stmtMyAch->execute([$userId]);
$myUnlockedIds = $stmtMyAch->fetchAll(PDO::FETCH_COLUMN);

function timeAgoStr($datetime) {
    if(!$datetime) return 'recientemente';
    $time = strtotime($datetime);
    $diff = time() - $time;
    if($diff < 60) return "hace unos instantes";
    if($diff < 3600) return "hace ".floor($diff/60)." min";
    if($diff < 86400) return "hace ".floor($diff/3600)." hs";
    return "hace ".floor($diff/86400)." días";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Hub Eurosoft Unificado</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <style>
        :root {
            --bg-body: #fdfaf6;
            --text-dark: #1e293b;
            --text-muted: #64748b;
            --primary: #f59e0b; 
            --primary-light: #fffbeb;
            --nav-bg: #0f172a;
            --card-bg: #ffffff;
            --border-rad: 24px;
            --shadow-soft: 0 10px 30px -10px rgba(0,0,0,0.06);
            --shadow-sm: 0 4px 10px rgba(0,0,0,0.03);
            --font-family: 'Inter', sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            background-color: var(--bg-body);
            font-family: var(--font-family);
            color: var(--text-dark);
            -webkit-font-smoothing: antialiased;
            padding-bottom: 4rem;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 0 2rem; }

        /* Navbar Custom */
        .navbar { display: flex; justify-content: space-between; align-items: center; padding: 1.5rem 0; margin-bottom: 2rem; }
        .logo { font-size: 1.5rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem; color: #f59e0b; text-decoration: none;}
        .logo span { color: var(--text-dark); font-weight: 700; }

        .nav-pill { background: var(--nav-bg); border-radius: 40px; display: flex; align-items: center; padding: 0.4rem; gap: 0.2rem; flex-wrap: wrap; justify-content: center;}
        @media(max-width: 768px) { .nav-pill { display: none; } } /* Hide on mobile for now */
        .nav-pill a { color: #94a3b8; text-decoration: none; font-size: 0.85rem; font-weight: 600; padding: 0.6rem 1rem; border-radius: 30px; display: flex; align-items: center; gap: 0.4rem; transition: all 0.2s; white-space: nowrap; }
        .nav-pill a:hover { color: #fff; }
        .nav-pill a.active { background: #ffffff; color: var(--text-dark); }
        .nav-pill a i { font-size: 1.1rem; }

        .nav-icons { display: flex; gap: 1rem; align-items: center; }
        .nav-icon { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: #ffffff; box-shadow: var(--shadow-sm); color: var(--text-muted); font-size: 1.2rem; border: 1px solid #f1f5f9; cursor: pointer; position: relative; text-decoration: none;}
        .nav-icon.avatar { background: var(--primary); color: #fff; font-weight: 600; padding: 2px; overflow: hidden; }
        .badge-dot { position: absolute; top: 0; right: 0; width: 10px; height: 10px; background: #ef4444; border-radius: 50%; border: 2px solid #fff; }

        /* Header Area */
        .header-area { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; flex-wrap: wrap; gap: 1.5rem;}
        .header-left h1 { font-size: 2.2rem; font-weight: 800; margin-bottom: 1rem; letter-spacing: -0.02em; }
        .header-chips { display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap; }
        .chip { padding: 0.5rem 1rem; border-radius: 30px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
        .chip-dark { background: var(--nav-bg); color: #fff; }
        .chip-yellow { background: #fde047; color: #854d0e; }
        .chip-gray { background: #e2e8f0; color: #475569; }
        .chip-val { width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.1); display: flex; align-items:center; justify-content:center; }
        .chip-dark .chip-val { background: rgba(255,255,255,0.2); }

        .header-right { display: flex; gap: 1rem; flex-wrap: wrap;}
        .stat-card { background: var(--card-bg); border-radius: 16px; padding: 1rem 1.2rem; display: flex; align-items: center; gap: 1rem; box-shadow: var(--shadow-soft); border: 1px solid #f8fafc; }
        .stat-card i { font-size: 1.5rem; color: var(--primary); }
        .stat-card .val { font-size: 1.2rem; font-weight: 800; line-height: 1; }
        .stat-card .lbl { font-size: 0.75rem; color: var(--text-muted); font-weight: 600; }

        /* Main Grid */
        .main-grid { display: grid; grid-template-columns: 320px 1fr; gap: 2rem; }
        @media(max-width: 1024px) { .main-grid { grid-template-columns: 1fr; } }

        /* Sidebar */
        .profile-card { position: relative; height: 400px; border-radius: var(--border-rad); overflow: hidden; background: url('<?= $avatar_url ?>') center/cover; box-shadow: var(--shadow-soft); margin-bottom: 1.5rem; background-color: #e2e8f0; cursor: pointer; }
        .hover-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.5); color: white; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; font-size: 2.5rem; backdrop-filter: blur(4px); z-index: 5; flex-direction: column; gap: 0.5rem; }
        .hover-overlay span { font-size: 1rem; font-weight: 700; }
        .profile-card:hover .hover-overlay { opacity: 1; }
        
        .profile-overlay { position: absolute; bottom: 0; left: 0; right: 0; background: linear-gradient(to top, rgba(0,0,0,0.85), transparent); padding: 2.5rem 1.5rem 1.5rem; color: #fff; display: flex; justify-content: space-between; align-items: flex-end; z-index: 10;}
        .profile-overlay h3 { font-size: 1.2rem; font-weight: 700; margin-bottom: 0.2rem; text-shadow: 0 2px 4px rgba(0,0,0,0.5);}
        .profile-overlay p { font-size: 0.85rem; color: #cbd5e1; font-weight: 600;}
        .xp-chip { background: rgba(255,255,255,0.25); backdrop-filter: blur(5px); padding: 0.4rem 0.8rem; border-radius: 20px; font-size: 0.8rem; font-weight: 700; border: 1px solid rgba(255,255,255,0.3); }

        .rk-card { background: var(--card-bg); border-radius: var(--border-rad); padding: 1.5rem; box-shadow: var(--shadow-soft); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .card-title { font-size: 1rem; font-weight: 800; }
        
        .rk-item { display: flex; align-items: center; gap: 1rem; padding: 0.8rem 1rem; border-radius: 16px; margin-bottom: 0.5rem; }
        .rk-item.active { border: 2px solid var(--primary); background: #fffbf0; }
        .rk-rank { width: 28px; height: 28px; border-radius: 50%; background: #f1f5f9; display:flex; align-items:center; justify-content:center; font-size: 0.8rem; font-weight: 700; color: var(--text-muted);}
        .rk-item.active .rk-rank { background: #fcd34d; color: #854d0e; }
        .rk-name { font-size: 0.9rem; font-weight: 700; color: var(--text-dark); }
        .rk-xp { font-size: 0.75rem; color: var(--text-muted); }

        /* Actividad Reciente */
        .act-item { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
        .act-icon { width: 28px; height: 28px; border-radius: 50%; background: #dcfce7; color: #166534; display:flex; align-items:center; justify-content:center; font-size: 1.2rem; flex-shrink:0; }
        .act-txt { font-size: 0.8rem; color: var(--text-dark); line-height: 1.4; }
        .act-time { font-size: 0.7rem; color: var(--text-muted); margin-top: 0.2rem; font-weight: 600;}

        /* Main Content Grid */
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 1.5rem; }
        @media(max-width: 1200px) { .grid-3 { grid-template-columns: 1fr; } }
        
        .b-card { background: var(--card-bg); border-radius: var(--border-rad); padding: 1.5rem; box-shadow: var(--shadow-soft); display: flex; flex-direction: column; justify-content: space-between; }
        .prog-big { font-size: 2.2rem; font-weight: 800; letter-spacing: -0.03em; line-height: 1; margin: 1rem 0 0.2rem; }
        .prog-lbl { font-size: 0.8rem; color: var(--text-muted); margin-bottom: 2rem; font-weight: 600;}

        .days-chart { display: flex; justify-content: space-between; align-items: flex-end; position: relative;}
        .day-col { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; }
        .day-lbl { font-size: 0.7rem; color: #cbd5e1; font-weight: 700; }
        .day-lbl.active { color: var(--primary); }
        .dot-pip { background: #fcd34d; color: #854d0e; font-size: 0.6rem; font-weight: 800; padding: 0.2rem 0.5rem; border-radius: 12px; position: absolute; bottom: 25px; left: 45%; }

        /* XP Card */
        .xp-top { display: flex; align-items: center; gap: 0.5rem; }
        .xp-big { font-size: 1.8rem; font-weight: 800; }
        .xp-lvl { font-size: 0.75rem; background: #fffbeb; color: #d97706; padding: 0.2rem 0.6rem; border-radius: 12px; font-weight: 800; margin-left: auto; }
        .prog-bar-ct { margin: 1.5rem 0 1rem; }
        .prog-labels { display: flex; justify-content: space-between; font-size: 0.7rem; color: var(--text-muted); font-weight: 600; margin-bottom: 0.5rem; }
        .bar-bg { width: 100%; height: 8px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
        .bar-fill { height: 100%; background: var(--primary); border-radius: 4px; transition: width 0.5s;}
        .btn-dark-full { background: var(--nav-bg); color: #fff; border: none; width: 100%; padding: 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: 700; cursor: pointer; text-decoration: none; text-align: center; display: block;}

        /* Certs Card */
        .cert-orange-box { background: var(--primary); padding: 1.5rem 1rem; border-radius: 16px; color: #fff; margin-bottom: 1rem; display: flex; align-items: flex-start; gap: 1rem; }
        .cert-icon { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,0.2); display: flex; align-items:center; justify-content:center; font-size: 1.2rem; flex-shrink: 0;}
        .cert-title { font-weight: 800; font-size: 0.95rem; line-height: 1.3; }
        .cert-date { font-size: 0.7rem; opacity: 0.9; margin-top: 0.2rem; }
        .btn-light-full { background: #f1f5f9; color: var(--text-dark); border: none; width: 100%; padding: 0.8rem; border-radius: 12px; font-size: 0.85rem; font-weight: 700; cursor: pointer; text-decoration: none; text-align: center; display: block;}
        .btn-light-full:hover { background: #e2e8f0; }

        /* Courses Card */
        .course-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem; }
        @media(max-width: 768px) { .course-grid { grid-template-columns: 1fr; } }
        .c-banner { 
            border-radius: 20px; 
            overflow: hidden; 
            border: 2px solid #fff7ed; 
            box-shadow: 0 8px 16px -4px rgba(249, 115, 22, 0.2), 0 4px 6px rgba(0,0,0,0.03); 
            background: #ffffff;
            transition: transform 0.2s;
        }
        .c-banner:hover { transform: translateY(-3px); }
        .cb-img { width: 100%; height: 130px; object-fit: cover; background: #e2e8f0; border-bottom: 1px solid #fcfcfc;}
        .cb-info { padding: 1.2rem 1rem; }
        .cb-title { font-size: 0.95rem; font-weight: 800; margin-bottom: 1rem; line-height: 1.3;}
        .cb-prog-flex { display: flex; align-items: center; gap: 1rem; }
        .cb-prog-pct { font-size: 0.85rem; font-weight: 900; color: var(--primary); }

        /* Ruta Aprendizaje */
        .route-big { font-size: 2.2rem; font-weight: 800; margin-bottom: 1rem; }
        .route-bars { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; }
        .r-bar { height: 8px; flex: 1; background: #f1f5f9; border-radius: 4px; }
        .r-bar.bg-filled { background: var(--text-dark); }
        .route-block { background: var(--nav-bg); border-radius: 12px; padding: 1.2rem; display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem;}
        .route-circle { width: 30px; height: 30px; border-radius: 50%; border: 2px solid #fff; background: rgba(255,255,255,0.1); }

        /* Medallas */
        .medals-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        @media(max-width: 768px) { .medals-grid { grid-template-columns: repeat(2, 1fr); } }
        
        .medal-box { position: relative; border-radius: 20px; padding: 2rem 1rem 1.5rem; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: space-between; min-height: 240px; transition: transform 0.2s; }
        
        .medal-box.orange { 
            background: #ffffff; 
            border: 2px solid #fff7ed;
            box-shadow: 0 8px 16px -4px rgba(249, 115, 22, 0.2), 0 4px 6px rgba(0,0,0,0.03); 
        }
        
        .medal-box.gray { 
            background: linear-gradient(145deg, #f8fafc, #f1f5f9);
            border: 1px solid #f8fafc; 
            box-shadow: inset 0 2px 4px rgba(255,255,255,0.5), 0 5px 15px rgba(0,0,0,0.02);
        }
        
        .medal-circle {
            width: 75px; height: 75px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; position: relative; z-index: 2;
        }
        
        .medal-box.orange .medal-circle {
            background: linear-gradient(135deg, #f97316, #ea580c); 
            color: #fff;
            box-shadow: 0 6px 12px -3px rgba(249, 115, 22, 0.4);
        }
        
        .medal-box.gray .medal-circle {
            background: #cbd5e1; color: #94a3b8;
        }

        .medal-icon { font-size: 2.2rem; }
        
        .medal-title { font-weight: 800; font-size: 0.85rem; margin-bottom: 0.6rem; line-height: 1.3; text-transform: uppercase; color: var(--text-dark); word-break: break-word; letter-spacing: 0.02em;}
        .medal-box.gray .medal-title { color: #64748b; }
        
        .medal-desc { font-size: 0.75rem; color: #475569; font-weight: 500; margin-bottom: 1rem; line-height: 1.4;}
        
        .medal-status { font-size: 0.85rem; font-weight: 800; margin-top: auto; }
        .medal-box.orange .medal-status { color: var(--text-dark); }
        .medal-box.gray .medal-status { color: #94a3b8; font-size: 1.2rem; }
        
        /* Progress Bar Update */
        .medal-lbls { display: flex; justify-content: space-between; align-items: flex-end; font-size: 0.85rem; font-weight: 800; color: var(--text-dark); margin-bottom: 1rem; text-transform: uppercase; }
        .medal-lbls span:last-child { color: var(--text-muted); font-weight: 600; text-transform: none;}
        .medal-progress-ct { background: #ffffff; padding: 2rem; border-radius: var(--border-rad); box-shadow: 0 10px 30px -5px rgba(0,0,0,0.04); margin-top: 1rem; border: 1px solid #f8fafc;}
        .med-bar-bg { width: 100%; height: 16px; background: #e2e8f0; border-radius: 20px; position: relative; }
        .med-bar-fill { height: 100%; background: linear-gradient(to right, #f97316, #fcd34d); border-radius: 20px; box-shadow: 0 2px 6px rgba(249, 115, 22, 0.5); position: relative; }
    </style>
</head>
<body>

<div class="container">
    <div class="navbar">
        <a href="index.php" class="logo" style="display: flex; align-items: center; padding: 0.5rem 0;">
            <img src="assets/images/dashboard_logo.png" alt="Hub Eurosoft" style="height: 48px; object-fit: contain;">
        </a>
        
        <div class="nav-pill">
            <a href="index.php?view=dashboard" class="active"><i class='bx bx-home-alt'></i> Inicio</a>
            <a href="index.php?view=courses"><i class='bx bx-book-open'></i> Cursos</a>
            <a href="index.php?view=forums"><i class='bx bx-conversation'></i> Foros</a>
            
            <?php if($isAdmin || $isLeader): ?>
            <!-- Menú inyectado dinámicamente para Líderes -->
            <div style="width: 1px; height: 16px; background: rgba(255,255,255,0.2); margin: 0 0.5rem;"></div>
            <a href="index.php?view=gamification"><i class='bx bx-medal'></i> Gamificación</a>
            <?php endif; ?>
            
            <?php if($isAdmin): ?>
            <a href="index.php?view=students"><i class='bx bx-group'></i> Usuarios</a>
            <a href="index.php?view=settings"><i class='bx bx-cog'></i> Sistema</a>
            <?php endif; ?>
        </div>
        
        <div class="nav-icons">
            <a href="index.php?view=settings" class="nav-icon" title="Ayuda"><i class='bx bx-support'></i></a>
            <a href="logout.php" class="nav-icon" title="Cerrar Sessión"><i class='bx bx-log-out'></i></a>
            <a href="index.php?view=settings" class="nav-icon avatar">
                <img id="avatarHeaderImg" src="<?= htmlspecialchars($avatar_url) ?>" alt="Me" style="width:100%; height:100%; object-fit: cover; border-radius: 50%;">
            </a>
        </div>
    </div>

    <div class="header-area">
        <div class="header-left">
            <h1>Bienvenido, <?= $first_name ?></h1>
            <div class="header-chips">
                <div class="chip chip-dark"><div class="chip-val"><?= $activeCoursesCount ?></div> <?= $labelCourses ?></div>
                <div class="chip chip-yellow"><div class="chip-val"><?= $certsCount ?></div> Certificaciones</div>
                <div class="chip chip-gray"><?= $generalProg ?>% Progreso General</div>
            </div>
        </div>
        
        <div class="header-right">
            <div class="stat-card">
                <i class='bx bxs-hot'></i>
                <div>
                    <div class="val"><?= (int)$gam['streakCount'] ?></div>
                    <div class="lbl">Días de racha</div>
                </div>
            </div>
            <div class="stat-card">
                <i class='bx bx-book' style="color: #f59e0b;"></i>
                <div>
                    <div class="val"><?= (int)$coursesCompletedCount ?></div>
                    <div class="lbl"><?= $labelCompleted ?></div>
                </div>
            </div>
            <div class="stat-card">
                <i class='bx bx-medal' style="color: #f59e0b;"></i>
                <div>
                    <div class="val"><?= count($myUnlockedIds) ?></div>
                    <div class="lbl">Medallas Ganadas</div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-grid">
        <div class="sidebar">
            <div class="profile-card" id="bannerCover" onclick="document.getElementById('uploadAvatar').click()">
                <div class="hover-overlay" id="hoverOverlayRef">
                    <i class='bx bx-camera'></i>
                    <span>Cambiar Foto</span>
                </div>
                <div class="profile-overlay">
                    <div>
                        <h3><?= $full_name ?></h3>
                        <p><?= $roleNameStr ?></p>
                    </div>
                    <div class="xp-chip"><?= $totalXP ?> XP</div>
                </div>
            </div>

            <div class="rk-card">
                <div class="card-header" style="margin-bottom: 1rem;">
                    <div class="card-title">Ranking Global</div>
                </div>
                
                <?php if(count($ranking) > 0): ?>
                    <?php foreach($ranking as $i => $u): $rnk = $i+1; $isActive = ($u['id'] === $userId); ?>
                    <div class="rk-item <?= $isActive ? 'active' : '' ?>">
                        <div class="rk-rank"><?= $rnk ?></div>
                        <div class="rk-info" style="flex: 1;">
                            <div class="rk-name"><?= htmlspecialchars($u['name']) ?></div>
                            <div class="rk-xp"><?= (int)$u['totalPoints'] ?> XP</div>
                        </div>
                        <?php if($rnk === 1): ?><i class='bx bxs-trophy' style="color: #f59e0b; font-size: 1.2rem;"></i><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.8rem; text-align:center;">El ranking aún se está calculando.</p>
                <?php endif; ?>

                <?php if($deltaXP > 0): ?>
                <div style="background: #fffbeb; padding: 0.8rem; border-radius: 12px; text-align: center; color: #d97706; font-size: 0.8rem; font-weight: 700; margin-top: 1.5rem;">
                    A <span style="color: #b45309;"><?= $deltaXP ?> XP</span> del 1er lugar
                </div>
                <?php endif; ?>
            </div>

            <div class="rk-card" style="margin-top: 1.5rem;">
                <div class="card-title" style="margin-bottom: 1.5rem;">Actividad Reciente</div>
                <?php if(count($activities) > 0): ?>
                    <?php foreach($activities as $act): 
                        $meta = json_decode($act['metadata'], true);
                        $title = htmlspecialchars($meta['itemTitle'] ?? 'Módulo no especificado');
                        $icon = 'bx-check'; $color = '#166534'; $bg = '#dcfce7';
                        if(strpos($act['action'], 'FORUM') !== false) { $icon = 'bx-message-rounded-dots'; $color = '#1e40af'; $bg = '#dbeafe'; }
                    ?>
                    <div class="act-item">
                        <div class="act-icon" style="color:<?= $color ?>; background:<?= $bg ?>"><i class='bx <?= $icon ?>'></i></div>
                        <div>
                            <div class="act-txt">
                                <?= htmlspecialchars(ucfirst(strtolower(str_replace('_', ' ', $act['action'])))) ?> <br>
                                <strong style="color:var(--primary);"><?= $title ?></strong>
                            </div>
                            <div class="act-time"><?= timeAgoStr($act['createdAt']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color:var(--text-muted); font-size:0.8rem; text-align:center;">Aún no hay actividad reciente.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="main-content">
            <div class="grid-3">
                <div class="b-card">
                    <div>
                        <div class="card-header" style="margin-bottom: 0;">
                            <div class="card-title">Progreso General</div>
                        </div>
                        <div class="prog-big"><?= $generalProg ?>%</div>
                        <div class="prog-lbl">Ruta completada</div>
                    </div>
                    
                    <div class="days-chart">
                        <div class="dot-pip">Activo</div>
                        <div class="day-col"><div class="day-lbl">L</div></div>
                        <div class="day-col"><div class="day-lbl">M</div></div>
                        <div class="day-col"><div class="day-lbl active">M</div></div>
                        <div class="day-col"><div class="day-lbl">J</div></div>
                        <div class="day-col"><div class="day-lbl">V</div></div>
                        <div class="day-col"><div class="day-lbl">S</div></div>
                        <div class="day-col"><div class="day-lbl">D</div></div>
                    </div>
                </div>

                <div class="b-card">
                    <div>
                        <div class="card-header" style="margin-bottom: 0;">
                            <div class="card-title">Experiencia</div>
                        </div>
                        <div class="xp-top" style="margin-top: 1rem;">
                            <i class='bx bxs-bolt' style="color: var(--primary); font-size: 1.5rem;"></i>
                            <div class="xp-big"><?= $totalXP ?></div>
                            <div class="xp-lvl">Nivel <?= $level ?></div>
                        </div>
                        <div class="prog-lbl" style="margin-top:0.2rem; margin-bottom: 0;">Total XP Base</div>
                    </div>
                    
                    <div>
                        <div class="prog-bar-ct">
                            <div class="prog-labels"><span><?= $xpBase ?> XP</span><span><?= $xpNext ?> XP</span></div>
                            <div class="bar-bg"><div class="bar-fill" style="width: <?= $xpPercent ?>%;"></div></div>
                        </div>
                        <a href="index.php?view=gamification" class="btn-dark-full">Ver cómo ganar más Puntos</a>
                    </div>
                </div>

                <div class="b-card">
                    <div class="card-header" style="margin-bottom: 1rem;">
                        <div class="card-title">Certificaciones</div>
                    </div>
                    <?php if($certsCount > 0): ?>
                    <div class="cert-orange-box">
                        <div class="cert-icon"><i class='bx bx-award'></i></div>
                        <div>
                            <div class="cert-title">Distintivo Académico</div>
                            <div class="cert-date">Posees credenciales activas<br><br>Hub Eurosoft</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="cert-orange-box" style="background:#f1f5f9; color:var(--text-dark);">
                        <div class="cert-icon" style="background:rgba(0,0,0,0.05);"><i class='bx bx-lock-alt'></i></div>
                        <div>
                            <div class="cert-title">Ninguna obtenida</div>
                            <div class="cert-date" style="color:var(--text-muted)">Completa una ruta<br>para certificarte.</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <a href="index.php?view=certificates" class="btn-light-full" style="margin-top: auto;">Ver todas las certificaciones</a>
                </div>
            </div>

            <div class="b-card">
                <div class="card-header" style="margin-bottom: 0;">
                    <div class="card-title">Continuar Aprendiendo (<?= $labelCourses ?>)</div>
                    <a href="index.php?view=courses" class="arrow-btn" title="Ir al Catálogo"><i class='bx bx-up-arrow-alt' style="transform: rotate(45deg);"></i></a>
                </div>
                
                <div class="course-grid">
                    <?php foreach($myActiveCourses as $c): ?>
                    <div class="c-banner">
                        <img src="<?= htmlspecialchars(ltrim($c['bannerImage'], '/')) ?>" alt="Curso" class="cb-img" onerror="this.src='https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?ixlib=rb-1.2.1&auto=format&fit=crop&w=600&q=80'">
                        <div class="cb-info">
                            <div class="cb-title"><?= htmlspecialchars($c['title']) ?></div>
                            
                            <a href="index.php?view=course_detail&id=<?= $c['courseId'] ?>" class="btn-dark-full" style="background: #0f172a; padding: 0.65rem; border-radius: 12px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; margin-bottom: 0.8rem; transition: background 0.2s;" onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='#0f172a'">
                                Continuar Sesión <i class='bx bx-right-arrow-alt' style="font-size: 1.1rem;"></i>
                            </a>

                            <div class="cb-prog-flex" style="background: #f8fafc; padding: 0.5rem 0.8rem; border-radius: 20px; border: 1px solid #e2e8f0;">
                                <div style="font-size: 0.6rem; font-weight: 800; color: #64748b; text-transform: uppercase;">Avance General</div>
                                <div class="bar-bg" style="flex:1; height: 6px; background: #e2e8f0; margin: 0 0.5rem;"><div class="bar-fill" style="width: <?= (int)$c['progress'] ?>%; height: 6px; box-shadow: none;"></div></div>
                                <div class="cb-prog-pct" style="<?= $c['progress']<100?'color:var(--text-dark);':'' ?>"><?= (int)$c['progress'] ?>%</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($myActiveCourses)): ?>
                        <div style="grid-column: 1 / -1; padding: 2rem; text-align: center; color: var(--text-muted); background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
                            <?php if ($coursesCompletedCount > 0): ?>
                                <i class='bx bx-party' style="font-size: 2.5rem; margin-bottom: 0.5rem; color: #10b981;"></i><br>
                                <span style="font-weight: 700; color: #10b981; font-size: 1.1rem; display:block; margin-bottom: 5px;">¡Felicidades!</span> Has completado todos los cursos. Ya estás al día.
                            <?php else: ?>
                                <i class='bx bx-book-reader' style="font-size: 2rem; margin-bottom: 0.5rem; opacity: 0.5;"></i><br>
                                Aún no te has enrolado en ningún curso.
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- NUEVA PÍLDORA DE RUTA DE APRENDIZAJE / AVANCE GLOBAL -->
            <div class="b-card" style="margin-top: 2rem; padding: 2.2rem; border-radius: 20px;">
                <div style="font-size: 0.85rem; font-weight: 800; color: var(--text-dark); margin-bottom: 0.5rem;">Ruta de Aprendizaje en Progreso</div>
                <div style="font-size: 2.5rem; font-weight: 900; color: var(--text-dark); margin-bottom: 1.5rem; letter-spacing: -0.02em;">
                    <?= $generalProg ?>%
                </div>
                
                <!-- Barra Segmentada -->
                <div style="display: flex; gap: 0.5rem; margin-bottom: 1.5rem;">
                    <?php 
                        $totalSegments = max(1, $totalCoursesInPath); 
                        $filledSegments = $coursesCompletedCount;
                        for($s=0; $s<$totalSegments; $s++):
                            $bgCol = ($s < $filledSegments) ? '#0f172a' : '#e2e8f0';
                    ?>
                    <div style="flex:1; height: 10px; background: <?= $bgCol ?>; border-radius: 6px;"></div>
                    <?php endfor; ?>
                </div>
                
                <!-- Trayecto Activo -->
                <div style="display: flex; justify-content: space-between; font-size: 0.9rem; font-weight: 800; color: var(--text-dark); margin-bottom: 1rem;">
                    <span><?= ($userRole==='COMPANY_LEADER' || $isAdmin) ? 'Trayecto Corporativo' : 'Iniciación Hub Eurosoft' ?></span>
                    <span><?= $coursesCompletedCount ?>/<?= max(1, $totalCoursesInPath) ?></span>
                </div>
                
                <!-- Cursos en Progreso -->
                <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                <?php 
                    $hasInProgress = false;
                    foreach($myActiveCourses as $c): 
                        if($c['progress'] >= 100) continue; // Solo mostrar los que estén en progreso
                        $hasInProgress = true;
                ?>
                <div style="background: #111827; border-radius: 16px; padding: 1.2rem; display: flex; align-items: center; gap: 1rem; box-shadow: 0 10px 25px -5px rgba(17, 24, 39, 0.4);">
                    <div style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.05); border: 2px solid rgba(255,255,255,0.1); display: flex; align-items:center; justify-content: center; flex-shrink: 0;">
                        <div style="width:12px; height:12px; border-radius:50%; background: transparent; border: 2.5px solid #64748b;"></div>
                    </div>
                    <div style="color: #fff; flex: 1;">
                        <div style="font-size: 1rem; font-weight: 800; line-height: 1.2;"><?= htmlspecialchars($c['title']) ?></div>
                        <div style="font-size: 0.8rem; color: #94a3b8; font-weight: 600; margin-top: 0.2rem;">En Progreso (<?= $c['progress'] ?>%)</div>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <?php if(!$hasInProgress && !empty($myActiveCourses)): ?>
                    <p style="font-size: 0.8rem; color: var(--text-muted); font-weight: 500;">¡Has completado todos los cursos de esta ruta!</p>
                <?php endif; ?>
                </div>
            </div>

            <div class="b-card" style="margin-top: 2rem; padding: 2.5rem;">
                <div style="text-transform: uppercase; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); letter-spacing: 0.05em; margin-bottom: 0.5rem;">Mis Logros y Medallas de Gamificación</div>
                <div class="card-title" style="margin-bottom: 2rem; display:flex; align-items:center; gap: 0.5rem; font-size: 1.6rem;"><i class='bx bx-trophy' style="font-size: 2rem;"></i> Mis Logros y Medallas de Gamificación</div>
                
                <div class="medals-grid">
                    <?php foreach($allAchievements as $ach): 
                        $isUnlocked = in_array($ach['id'], $myUnlockedIds);
                    ?>
                    <div class="medal-box <?= $isUnlocked ? 'orange' : 'gray' ?>">
                        <div class="medal-circle" style="overflow: hidden; padding: 0;">
                            <?php if (!empty($ach['imagePath'])): ?>
                                <img src="<?= htmlspecialchars(ltrim($ach['imagePath'], '/')) ?>" alt="Medalla" style="width: 100%; height: 100%; object-fit: cover; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">
                            <?php else: ?>
                                <?php 
                                    $iconClass = $ach['icon'];
                                    $iconMap = [
                                        'Zap' => 'bx bxs-zap',
                                        'BookOpen' => 'bx bx-book-open',
                                        'Flame' => 'bx bxs-flame',
                                        'Medal' => 'bx bxs-medal',
                                        'Rocket' => 'bx bxs-rocket',
                                        'Crown' => 'bx bxs-crown'
                                    ];
                                    if(isset($iconMap[trim($iconClass)])) $iconClass = $iconMap[trim($iconClass)];
                                    if(strpos($iconClass, 'bx') === false) $iconClass = 'bx bxs-star'; // fallback
                                ?>
                                <i class='<?= htmlspecialchars($iconClass) ?> medal-icon'></i>
                            <?php endif; ?>
                        </div>
                        
                        <div style="flex:1;">
                            <div class="medal-title"><?= htmlspecialchars($ach['title']) ?></div>
                            <?php if($isUnlocked): ?>
                            <div class="medal-desc"><?= htmlspecialchars($ach['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="medal-status" style="display:flex; align-items:center; justify-content:center; gap:0.25rem;">
                            <?= $isUnlocked ? "<i class='bx bxs-check-circle' style='color: #22c55e; font-size: 1.4rem;'></i>" : "<i class='bx bx-lock-alt'></i>" ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="height: 1px; background: #e2e8f0; margin: 1.5rem 0 2rem 0;"></div>

                <div class="medal-progress-ct" style="padding: 0; background: transparent; border: none; box-shadow: none; margin-top: 0;">
                    <div class="medal-lbls">
                        <span>PROGRESO TOTAL (<?= count($myUnlockedIds) ?>/<?= count($allAchievements) ?> Medallas Unlocked)</span>
                        <span><?= count($allAchievements)>0 ? number_format((count($myUnlockedIds)/count($allAchievements))*100, 1) : 0 ?>% Completado</span>
                    </div>
                    <div class="med-bar-bg"><div class="med-bar-fill" style="width: <?= count($allAchievements)>0 ? (count($myUnlockedIds)/count($allAchievements))*100 : 0 ?>%;"></div></div>
                </div>
            </div>
            
            <br><br>
        </div>
    </div>
</div>

<!-- Upload Hidden Input -->
<input type="file" id="uploadAvatar" accept="image/jpeg, image/png, image/webp" style="display:none;" onchange="handleProfileUpload(this)">

<script>
function handleProfileUpload(input) {
    if (!input.files || input.files.length === 0) return;
    
    const file = input.files[0];
    const maxSize = 50 * 1024 * 1024; // 50MB
    
    if (file.size > maxSize) {
        alert("La imagen excede el límite de 50MB.");
        input.value = "";
        return;
    }
    
    const overlay = document.getElementById('hoverOverlayRef');
    overlay.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i><span>Subiendo...</span>";
    overlay.style.opacity = '1';
    
    const fd = new FormData();
    fd.append('type', 'avatar');
    fd.append('image', file);
    
    fetch('upload_profile.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            let pureUrl = data.url;
            if(pureUrl.startsWith('/')) pureUrl = pureUrl.substring(1);
            
            // Actualizar la imagen del recuadro principal (BannerCover)
            document.getElementById('bannerCover').style.backgroundImage = `url('${pureUrl}')`;
            
            // Actualizar el header circular pequeñito
            document.getElementById('avatarHeaderImg').src = pureUrl;
        } else {
            alert(data.message || "Error al subir la imagen");
        }
    })
    .catch(err => {
        console.error(err);
        alert("Ocurrió un error inesperado al enviar la imagen al servidor.");
    })
    .finally(() => {
        overlay.innerHTML = "<i class='bx bx-camera'></i><span>Cambiar Foto</span>";
        overlay.style.opacity = '';
        input.value = "";
    });
}
</script>

</body>
</html>
