<?php
// NOTA: Esta vista se inyecta desde index.php, por lo que session, DB y header ya están presentes.
$courseId = $_GET['course_id'] ?? null;
$lessonIdQuery = $_GET['lesson_id'] ?? null;

if (!$courseId) {
    echo "<div style='padding: 2rem;'><h2>Error</h2><p>Falta el ID del Curso.</p></div>";
    return;
}

// 1. Fetch Course
$stmtC = $pdo->prepare("SELECT id, title, demoUntilLessonId FROM Course WHERE id = ?");
$stmtC->execute([$courseId]);
$course = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$course) { echo "<div style='padding: 2rem;'><h2>Error</h2><p>Curso no encontrado.</p></div>"; return; }

// 1.5. Verificación Estricta de Secuencia (Candados Globales)
require_once 'utils/student_data.php';
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$isAdmin = in_array($userRole, ['ADMIN', 'INSTRUCTOR']);

if (!$isAdmin) {
    $pathsObj = getAllEvaluatedLearningPaths($pdo, $_SESSION['user_id'], null);
    $courseGloballyLocked = false;
    $courseFoundInPaths = false;

    foreach ($pathsObj as $path) {
        foreach ($path['courses'] as $pc) {
            if ($pc['id'] === $courseId) {
                $courseFoundInPaths = true;
                if (empty($pc['isLocked'])) {
                    $courseGloballyLocked = false;
                    break 2; // Está desbloqueado en al menos una ruta
                } else {
                    $courseGloballyLocked = true; // Está bloqueado en esta ruta
                }
            }
        }
    }

    if ($courseFoundInPaths && $courseGloballyLocked) {
         echo "<script>alert('Acceso Denegado: Debes terminar los cursos anteriores en tu ruta formativa antes de tomar este.'); window.location.href='index.php?view=courses';</script>";
         exit;
    }
}

// 2. Fetch Curriculum
$stmtMods = $pdo->prepare("SELECT id, title, `order` FROM Module WHERE courseId = ? ORDER BY `order` ASC, createdAt ASC");
$stmtMods->execute([$courseId]);
$modulesRaw = $stmtMods->fetchAll(PDO::FETCH_ASSOC);

$curriculum = [];
$allLessonsFlat = [];
foreach ($modulesRaw as $m) {
    $stmtL = $pdo->prepare("SELECT id, title, description, content, videoUrl, `order` FROM Lesson WHERE moduleId = ? ORDER BY `order` ASC, createdAt ASC");
    $stmtL->execute([$m['id']]);
    $lessonsRaw = $stmtL->fetchAll(PDO::FETCH_ASSOC);
    $m['lessons'] = $lessonsRaw;
    $curriculum[] = $m;
    foreach ($lessonsRaw as $l) {
        $allLessonsFlat[] = $l;
    }
}

// 3. Progreso
$lessonIds = array_column($allLessonsFlat, 'id');
$progressMap = [];
if (!empty($lessonIds)) {
    $placeholders = implode(',', array_fill(0, count($lessonIds), '?'));
    $userId = $_SESSION['user_id'];
    $stmtP = $pdo->prepare("SELECT lessonId, isCompleted, videoProgress FROM LessonProgress WHERE userId = ? AND lessonId IN ($placeholders)");
    $params = array_merge([$userId], $lessonIds);
    $stmtP->execute($params);
    $progressRows = $stmtP->fetchAll(PDO::FETCH_ASSOC);
    foreach ($progressRows as $pr) {
        $progressMap[$pr['lessonId']] = $pr;
    }
}

// 4. Activa y Bloqueos
$activeLessonIndex = 0;
$lockedIndexes = [];
$previousCompleted = true;
$completedCount = 0;

for ($i = 0; $i < count($allLessonsFlat); $i++) {
    $lid = $allLessonsFlat[$i]['id'];
    $isComp = !empty($progressMap[$lid]['isCompleted']);
    if ($isComp) $completedCount++;
    if (!$previousCompleted && !$isComp) { $lockedIndexes[$i] = true; }
    if ($lessonIdQuery === $lid) { $activeLessonIndex = $i; }
    if (!$lessonIdQuery && !$isComp && $previousCompleted) { $activeLessonIndex = $i; }
    if (!$isComp) { $previousCompleted = false; }
}

$totalCount = count($allLessonsFlat);
$coursePercent = $totalCount > 0 ? floor(($completedCount / $totalCount) * 100) : 0;

// Verificar si existe Examen del Curso
$stmtQuiz = $pdo->prepare("SELECT id FROM Quiz WHERE courseId = ?");
$stmtQuiz->execute([$courseId]);
$quizAssigned = $stmtQuiz->fetchColumn();

// Verificar estado del examen si hay uno
$quizData = null;
if ($quizAssigned) {
    $stmtQProg = $pdo->prepare("SELECT quizPassed, quizScore FROM CourseProgress WHERE courseId = ? AND userId = ?");
    $stmtQProg->execute([$courseId, $_SESSION['user_id']]);
    $quizData = $stmtQProg->fetch(PDO::FETCH_ASSOC);
}

if (isset($lockedIndexes[$activeLessonIndex])) {
    for ($i = 0; $i < count($allLessonsFlat); $i++) {
        if (!isset($lockedIndexes[$i]) && empty($progressMap[$allLessonsFlat[$i]['id']]['isCompleted'])) {
            $activeLessonIndex = $i;
            break;
        }
    }
}

// Demo paywall: calcular el índice límite
$demoLimitFlatIndex = -1; // -1 = sin límite
if (!empty($course['demoUntilLessonId']) && !$isAdmin) {
    foreach ($allLessonsFlat as $dIdx => $dL) {
        if ($dL['id'] === $course['demoUntilLessonId']) {
            $demoLimitFlatIndex = $dIdx;
            break;
        }
    }
}
$showPaywall = ($demoLimitFlatIndex >= 0 && $activeLessonIndex > $demoLimitFlatIndex);

$lesson = $allLessonsFlat[$activeLessonIndex] ?? null;
if (!$lesson) {
    if (count($allLessonsFlat) > 0) {
        $lesson = $allLessonsFlat[count($allLessonsFlat) - 1]; 
        $activeLessonIndex = count($allLessonsFlat) - 1;
    } else {
        echo "<div style='padding:2rem;'><h1>Este curso no tiene contenido aún.</h1><a href='index.php?view=courses'>Volver</a></div>"; return;
    }
}

$lessonProgressData = $progressMap[$lesson['id']] ?? ['isCompleted' => 0, 'videoProgress' => 0];
$isLessonCompleted = (bool)$lessonProgressData['isCompleted'];
$savedVideoProgress = (float)$lessonProgressData['videoProgress'];
$prevLesson = $activeLessonIndex > 0 ? $allLessonsFlat[$activeLessonIndex - 1] : null;
$nextLesson = $activeLessonIndex < count($allLessonsFlat) - 1 ? $allLessonsFlat[$activeLessonIndex + 1] : null;
$isNextLocked = !$isLessonCompleted;
// isNextDemo: se computa aqui porque depende de $nextLesson
$isNextDemo = ($demoLimitFlatIndex >= 0 && $nextLesson !== null && ($activeLessonIndex >= $demoLimitFlatIndex));
?>

<style>
    /* Reset page content padding to maximize space for the immersive player */
    .page-content { padding: 0 !important; }
    
    .lesson-layout {
        display: flex;
        align-items: flex-start;
        min-height: calc(100vh - 100px);
        background-color: transparent;
        color: #1e293b;
        font-family: 'Inter', sans-serif;
        gap: 2rem;
    }
    
    .lesson-sidebar {
        width: 330px;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 1.5rem;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        position: sticky;
        top: 20px;
        height: calc(100vh - 40px);
    }
    
    .l-sidebar-header {
        padding: 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        flex-shrink: 0;
    }
    .l-back-link {
        display: inline-flex; align-items: center; gap: 0.2rem;
        color: #ea580c; font-size: 0.75rem; font-weight: 700; text-decoration: none;
        letter-spacing: 0.05em; margin-bottom: 1rem; text-transform: uppercase;
    }
    .l-course-name { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0 0 1.5rem 0; line-height: 1.3;}
    
    .l-prog-container { width: 100%; }
    .l-prog-header { display: flex; justify-content: space-between; font-size: 0.65rem; font-weight: 800; color: #64748b; margin-bottom: 0.4rem; letter-spacing: 0.05em; }
    .l-prog-bar-bg { width: 100%; height: 6px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
    .l-prog-bar-fill { height: 100%; background: linear-gradient(90deg, #6366f1, #a855f7); border-radius: 99px; transition: width 0.5s;}
    .l-prog-text { font-size: 0.65rem; color: #94a3b8; font-weight: 500; margin-top: 0.4rem; }

    .l-curriculum-list {
        flex: 1; overflow-y: auto; padding: 1rem 1.5rem 2rem 1.5rem;
    }
    
    .l-module-group { margin-bottom: 1.5rem; }
    .l-module-title-box { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.8rem; }
    .l-module-badge { background: #fff7ed; color: #ea580c; font-size: 0.65rem; font-weight: 800; padding: 0.2rem 0.5rem; border-radius: 4px; letter-spacing: 0.05em; }
    .l-module-title-text { font-size: 0.85rem; font-weight: 800; color: #0f172a; }
    
    .l-curr-item {
        display: flex; align-items: center; gap: 0.8rem; padding: 0.6rem 0;
        text-decoration: none; color: #334155; position: relative; cursor: pointer;
    }
    .l-curr-item::before { 
        content: ''; position: absolute; left: 10px; top: 25px; bottom: -15px;
        width: 2px; background: #e2e8f0; z-index: 1;
    }
    .l-curr-item:last-child::before { display: none; }
    
    .l-icon {
        width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem; z-index: 2; position: relative; font-weight: 800; flex-shrink: 0;
    }
    .l-icon.completed { background: #10b981; color: white; }
    .l-icon.active { background: #f97316; color: white; box-shadow: 0 0 0 4px #fff7ed; }
    .l-icon.locked { background: #f1f5f9; color: #94a3b8; border: 1px solid #e2e8f0; }
    .l-icon.demo   { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; border: 1px solid #fcd34d; }
    .l-curr-item.demo-locked .l-curr-title { color: #d97706; font-style: italic; }

    /* Paywall card */
    .demo-paywall-wrap { display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:400px; padding:3rem; text-align:center; }
    .demo-paywall-icon { width:80px; height:80px; background:linear-gradient(135deg,#fef3c7,#fed7aa); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 2rem; box-shadow:0 20px 40px rgba(251,191,36,0.2); }
    .demo-paywall-card { background:linear-gradient(135deg,#fff7ed,#fef9f0); border:1px solid #fed7aa; border-radius:20px; padding:2.5rem; max-width:500px; box-shadow:0 10px 40px rgba(251,191,36,0.1); }
    .demo-paywall-tag { font-size:0.65rem; font-weight:900; color:#f59e0b; letter-spacing:0.15em; text-transform:uppercase; margin-bottom:1rem; }
    .demo-paywall-title { font-size:1.5rem; font-weight:900; color:#1e293b; margin:0 0 1rem 0; line-height:1.3; }
    .demo-paywall-desc { color:#64748b; line-height:1.6; margin-bottom:2rem; }
    .demo-paywall-btn { display:inline-flex; align-items:center; gap:0.5rem; background:linear-gradient(135deg,#f97316,#ea580c); color:white; padding:0.9rem 2rem; border-radius:12px; font-weight:800; text-decoration:none; font-size:0.95rem; box-shadow:0 10px 20px rgba(249,115,22,0.3); transition:all 0.2s; }
    .demo-paywall-btn:hover { transform:translateY(-2px); box-shadow:0 15px 30px rgba(249,115,22,0.4); }
    .demo-paywall-url { margin-top:1rem; font-size:0.7rem; color:#94a3b8; }
    
    .l-curr-title { font-size: 0.85rem; line-height: 1.3; font-weight: 500; }
    .l-curr-item.active .l-curr-title { font-weight: 700; color: #0f172a; }
    .l-curr-item.locked .l-curr-title { color: #94a3b8; }
    
    .l-final-exam-lock {
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 1rem;
        display: flex; flex-direction: column; align-items: flex-start; gap: 0.2rem; margin-top: 1rem;
    }

    /* Main Area */
    .lesson-main {
        flex: 1; 
        display: flex; flex-direction: column;
        background: #ffffff;
        padding: 2.5rem;
        border-radius: 1.5rem;
        border: 1px solid #e2e8f0;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
        margin-bottom: 2rem;
    }
    .l-main-inner { max-width: 950px; margin: 0 auto; width: 100%; }
    
    .l-pill {
        background: #fff7ed; color: #ea580c; display: inline-block;
        padding: 0.3rem 0.8rem; border-radius: 99px; font-weight: 800; font-size: 0.7rem;
        letter-spacing: 0.05em; margin-bottom: 1rem; text-transform: uppercase;
    }
    
    .l-title { font-size: 1.8rem; font-weight: 900; color: #1e293b; margin: 0 0 2rem 0; letter-spacing: -0.02em; }
    
    .l-video-box-wrapper {
        background: #ffffff; border-radius: 12px;
        box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.01);
        overflow: hidden; margin-bottom: 1rem; border: 1px solid #e2e8f0;
    }
    .l-video-player { width: 100%; aspect-ratio: 16/9; background: #000; position: relative; display: block; }
    
    .l-tracker { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
    .l-tracker-visto { font-size: 0.75rem; font-weight: 800; color: #ea580c; display: flex; align-items: center; gap: 0.4rem; white-space: nowrap;}
    .l-tracker-bar-bg { flex: 1; height: 6px; background: #e2e8f0; border-radius: 99px; margin: 0 1rem; position: relative;}
    .l-tracker-bar-fill { height: 100%; background: linear-gradient(90deg, #ea580c, #a855f7); border-radius: 99px; width: 0%; transition: width 0.2s linear;}
    
    .btn-restart {
        background: #e2e8f0; border: none; padding: 0.4rem 0.8rem; border-radius: 6px;
        font-size: 0.75rem; font-weight: 700; color: #475569; cursor: pointer;
        display: inline-flex; align-items: center; gap: 0.3rem; transition: all 0.2s;
    }
    .btn-restart:hover { background: #cbd5e1; color: #1e293b; }
    
    .l-warning-box {
        background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 1rem;
        display: flex; align-items: center; gap: 0.8rem; color: #d97706; font-size: 0.85rem; font-weight: 600;
    }
    .l-content-html { line-height: 1.7; color: #334155; font-size: 1.05rem; margin-top: 2rem;}
    
    .l-next-action { text-align: right; margin-top: 1.5rem; }
    .btn-primary { background: #f97316; color: white; padding: 0.8rem 1.5rem; border-radius: 8px; font-weight: 700; border: none; cursor: pointer; transition: all 0.2s; font-size: 0.9rem;}
    .btn-primary:disabled { background: #cbd5e1; color: #64748b; cursor: not-allowed; }
    .pulse-btn { animation: pulse 2s infinite; }
    @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }

    /* ── RESPONSIVE: móvil ≤ 768px ── */
    @media (max-width: 768px) {
        .lesson-layout {
            flex-direction: column;
            gap: 1rem;
            padding: 0.75rem;
        }
        .lesson-sidebar {
            width: 100% !important;
            position: static !important;
            height: auto !important;
            max-height: 50vh;
            border-radius: 1rem;
            overflow-y: auto;
        }
        .lesson-main {
            padding: 1.25rem;
            border-radius: 1rem;
        }
        .l-title { font-size: 1.25rem; margin-bottom: 1rem; }
        .l-main-inner { max-width: 100%; }
        .l-tracker { flex-wrap: wrap; gap: 0.5rem; }
        .l-tracker-bar-bg { flex-basis: 100%; margin: 0; }
    }
</style>

<div class="lesson-layout">

    <!-- Sidebar Curriculum -->
    <aside class="lesson-sidebar">
        <div class="l-sidebar-header">
            <a href="index.php?view=courses" class="l-back-link"><i class='bx bx-left-arrow-alt'></i> VOLVER AL CATÁLOGO</a>
            <h2 class="l-course-name"><?= htmlspecialchars($course['title']) ?></h2>
            
            <div class="l-prog-container">
                <div class="l-prog-header">
                    <span>TU AVANCE</span>
                    <span style="color: #f97316;"><?= $coursePercent ?>%</span>
                </div>
                <div class="l-prog-bar-bg">
                    <div class="l-prog-bar-fill" style="width: <?= $coursePercent ?>%;"></div>
                </div>
                <div class="l-prog-text">
                    <?= $completedCount ?> de <?= $totalCount ?> subtemas completados
                </div>
            </div>
        </div>
        
        <div class="l-curriculum-list">
            <?php 
            $flatIndex = 0;
            foreach ($curriculum as $modIndex => $mod): 
            ?>
                <div class="l-module-group">
                    <div class="l-module-title-box">
                        <span class="l-module-badge">SECCIÓN <?= $modIndex+1 ?></span>
                        <span class="l-module-title-text"><?= htmlspecialchars($mod['title']) ?></span>
                    </div>
                    
                    <?php foreach ($mod['lessons'] as $idx => $l): 
                        $statusData = $progressMap[$l['id']] ?? null;
                        $comp = !empty($statusData['isCompleted']);
                        $isAct = ($flatIndex === $activeLessonIndex);
                        $isLoc = isset($lockedIndexes[$flatIndex]);
                        $isDemo = ($demoLimitFlatIndex >= 0 && $flatIndex > $demoLimitFlatIndex);

                        if ($isDemo) {
                            $iconClass = 'demo';
                            $iconHtml  = "<i class='bx bxs-lock-alt'></i>";
                            $href      = "index.php?view=lesson&course_id={$courseId}&lesson_id={$l['id']}";
                        } else {
                            $iconClass = $comp ? 'completed' : ($isAct ? 'active' : 'locked');
                            $iconHtml  = $comp ? "<i class='bx bx-check'></i>" : ($isLoc ? "<i class='bx bxs-lock-alt'></i>" : ($idx+1));
                            if ($isAct) $iconHtml = ($idx+1);
                            $href = $isLoc ? '#' : "index.php?view=lesson&course_id={$courseId}&lesson_id={$l['id']}";
                        }
                    ?>
                        <a href="<?= $href ?>" class="l-curr-item <?= $isDemo ? 'demo-locked' : ($isLoc ? 'locked' : '') ?> <?= $isAct ? 'active' : '' ?>">
                            <div class="l-icon <?= $iconClass ?>"><?= $iconHtml ?></div>
                            <div class="l-curr-title"><?= htmlspecialchars($l['title']) ?></div>
                        </a>
                    <?php 
                        $flatIndex++;
                    endforeach; ?>
                </div>
            <?php endforeach; ?>
            
            <?php if ($quizAssigned): ?>
                <?php 
                    $canTakeQuiz = ($completedCount >= $totalCount); 
                    $alreadyPassed = isset($quizData['quizPassed']) && $quizData['quizPassed'] == 1;
                ?>
                <a href="<?= $canTakeQuiz ? "index.php?view=take_quiz&course_id={$courseId}" : '#' ?>" class="l-final-exam-lock" style="text-decoration: none; <?= $canTakeQuiz ? 'border-color: #6366f1; background: #e0e7ff; cursor: pointer;' : '' ?>">
                    <div style="font-size: 0.6rem; color: <?= $canTakeQuiz ? '#4338ca' : '#94a3b8' ?>; font-weight: 800; letter-spacing: 0.05em;">
                        <?= $alreadyPassed ? 'APROBADO' : ($canTakeQuiz ? 'DISPONIBLE AHORA' : 'BLOQUEADO') ?>
                    </div>
                    <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; font-weight:600; color: <?= $canTakeQuiz ? '#1e293b' : '#cbd5e1' ?>;">
                        <i class='bx <?= $alreadyPassed ? 'bx-check-circle' : ($canTakeQuiz ? 'bxs-pencil' : 'bxs-lock-alt') ?>' style="color: <?= $alreadyPassed ? '#10b981' : ($canTakeQuiz ? '#f97316' : '#fcd34d') ?>;"></i> 
                        Examen Final <?= $alreadyPassed ? "({$quizData['quizScore']}%)" : '' ?>
                    </div>
                </a>
            <?php else: ?>
                <div class="l-final-exam-lock" style="opacity: 0.5;">
                    <div style="font-size: 0.6rem; color:#94a3b8; font-weight:800; letter-spacing:0.05em;">SIN EXAMEN</div>
                    <div style="display:flex; align-items:center; gap:0.5rem; font-size:0.85rem; font-weight:600; color:#cbd5e1;">
                        <i class='bx bx-ghost'></i> Examen Final
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="lesson-main">
        <div class="l-main-inner">

        <?php if ($showPaywall): ?>
            <!-- ── PANTALLA DE DEMO PAYWALL ── -->
            <div class="demo-paywall-wrap">
                <div class="demo-paywall-icon">
                    <i class='bx bxs-lock-alt' style="font-size:2rem;color:#f59e0b;"></i>
                </div>
                <div class="demo-paywall-card">
                    <div class="demo-paywall-tag">Contenido Premium</div>
                    <h2 class="demo-paywall-title">Has llegado al límite de esta demostración</h2>
                    <p class="demo-paywall-desc">Para acceder al programa completo y continuar tu aprendizaje, contacta a nuestro equipo. Estamos listos para ayudarte.</p>
                    <a href="https://eurosoft.mx/contacto/" target="_blank" class="demo-paywall-btn">
                        <i class='bx bx-envelope'></i> Contactar al Equipo
                    </a>
                    <div class="demo-paywall-url">eurosoft.mx/contacto/</div>
                </div>
            </div>
        <?php else: ?>

            <div class="l-pill">LECCIÓN</div>
            <h1 class="l-title"><?php echo htmlspecialchars($lesson['title']); ?></h1>
            
            <?php 
            $videoUrl = $lesson['videoUrl'] ?? '';
            $videoFormattedUrl = str_starts_with($videoUrl, '/') ? ltrim($videoUrl, '/') : $videoUrl;
            $hasVideo = !empty($videoUrl);
            ?>

            <?php if ($hasVideo): ?>
                <div class="l-video-box-wrapper">
                    <video id="lessonVideo" class="l-video-player" controls autoplay controlsList="nodownload" oncontextmenu="return false;">
                        <source src="<?php echo htmlspecialchars($videoFormattedUrl); ?>" type="video/mp4">
                        Tu navegador no soporta el reproductor de video.
                    </video>
                </div>
                
                <div class="l-tracker">
                    <div class="l-tracker-visto"><span style="color:#f97316; font-size:1rem; line-height:0;">&bull;</span> <span id="percentText">0%</span> VISTO</div>
                    <div class="l-tracker-bar-bg">
                        <div id="trackerBarFill" class="l-tracker-bar-fill"></div>
                    </div>
                    <button class="btn-restart" onclick="restartVideo()"><i class='bx bx-refresh'></i> Reiniciar</button>
                </div>
                
                <div class="l-warning-box">
                    <i class='bx bx-play-circle'></i>
                    <span>Video obligatorio para avanzar — debes visualizar al menos el 90%. No es posible adelantar el video.</span>
                </div>
            <?php else: ?>
                <div style="text-align: center; color: #94a3b8; padding: 4rem 0;">
                    <i class='bx bx-book-open' style="font-size: 4rem; margin-bottom: 1rem; color: #f97316;"></i>
                    <h2 style="color: #1e293b; margin:0;">Lección Teórica</h2>
                    <p>Lee el contenido a continuación para avanzar.</p>
                </div>
            <?php endif; ?>
            
            <div class="l-content-html">
                <?php if ($lesson['description']): ?>
                    <p style="font-weight: 500; font-size:1.1rem;"><?php echo htmlspecialchars($lesson['description']); ?></p>
                <?php endif; ?>
                <?php echo $lesson['content'] ?? ''; ?>
            </div>
            
            <div class="l-next-action">
                <?php if ($isNextDemo && !$isNextLocked): ?>
                    <a href="https://eurosoft.mx/contacto/" target="_blank" class="btn-primary pulse-btn" style="text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;background:linear-gradient(135deg,#f97316,#ea580c);">
                        <i class='bx bx-envelope'></i> Ver Contenido Completo
                    </a>
                <?php else: ?>
                    <button id="btnNextLesson" class="btn-primary <?= !$isNextLocked ? 'pulse-btn' : '' ?>" <?= $isNextLocked ? 'disabled' : '' ?> onclick="goToNextLesson()">
                        <?= $isNextLocked ? '<i class="bx bxs-lock"></i> Completar Lección' : 'Siguiente Lección <i class="bx bx-chevron-right"></i>' ?>
                    </button>
                <?php endif; ?>
            </div>

        <?php endif; // fin showPaywall ?>
        </div>
    </main>
</div>

<script>
    const courseId = "<?= $courseId ?>";
    const lessonId = "<?= $lesson['id'] ?>";
    const nextLessonId = "<?= $nextLesson ? $nextLesson['id'] : '' ?>";
    let isLessonCompleted = <?= $isLessonCompleted ? "true" : "false" ?>;
    let savedVideoProgress = <?= floatval($savedVideoProgress) ?>;
    let maxWatched = savedVideoProgress; 
    const hasVideo = <?= $hasVideo ? 'true' : 'false' ?>;

    const btnNext = document.getElementById('btnNextLesson');
    const video = document.getElementById('lessonVideo');
    const trackerBar = document.getElementById('trackerBarFill');
    const percentText = document.getElementById('percentText');

    function saveProgress(isFinished = false) {
        if (isFinished) isLessonCompleted = true;
        
        // El verdadero maxWatched solo debe ser decidido por el `timeupdate` validado
        
        fetch('api_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                courseId: courseId, lessonId: lessonId,
                videoProgress: maxWatched,
                isCompleted: isLessonCompleted ? 1 : 0
            })
        }).catch(err => console.error(err));
    }

    function unlockNextBtn() {
        if(btnNext && btnNext.disabled) {
            btnNext.disabled = false;
            btnNext.classList.add('pulse-btn');
            btnNext.innerHTML = 'Siguiente Lección <i class="bx bx-chevron-right"></i>';
            if(!nextLessonId) {
                <?php if ($quizAssigned): ?>
                btnNext.innerHTML = '📝 Presentar Examen Final <i class="bx bx-right-arrow-alt"></i>';
                btnNext.style.background = '#6366f1';
                btnNext.style.boxShadow = '0 10px 20px rgba(99, 102, 241, 0.3)';
                <?php else: ?>
                btnNext.innerHTML = '🎉 Terminar Curso';
                btnNext.style.background = '#10b981';
                btnNext.style.boxShadow = '0 10px 20px rgba(16, 185, 129, 0.3)';
                <?php endif; ?>
            }
        }
    }

    function goToNextLesson() {
        if (!nextLessonId) {
            <?php if ($quizAssigned): ?>
            window.location.href = 'index.php?view=take_quiz&course_id=<?= $courseId ?>';
            <?php else: ?>
            alert('¡Felicidades! Has completado todo el curso. No se requiere examen.');
            window.location.href = 'index.php?view=courses';
            <?php endif; ?>
        } else {
            window.location.href = `index.php?view=lesson&course_id=${courseId}&lesson_id=${nextLessonId}`;
        }
    }

    function restartVideo() {
        if(video) {
            video.currentTime = 0;
            video.play();
        }
    }

    if (!hasVideo && !isLessonCompleted) {
        saveProgress(true);
        unlockNextBtn();
    } else if (!hasVideo && isLessonCompleted) {
        unlockNextBtn();
    }

    if (video) {
        video.addEventListener('loadeddata', () => {
            if (savedVideoProgress > 0 && savedVideoProgress < video.duration - 0.5) {
                video.currentTime = savedVideoProgress;
            }
        });

        const timeStep = 5; 
        let lastSaveTime = 0;

        let supposedCurrentTime = savedVideoProgress;

        video.addEventListener('timeupdate', () => {
             const currentTime = video.currentTime;
             if (!video.seeking && !isLessonCompleted) {
                 if (currentTime > maxWatched) {
                     if (currentTime - maxWatched <= 2) {
                         maxWatched = currentTime;
                     } else {
                         // Detectado brinco anormal de timeupdate (network lag bypass) -> regresar a maxWatched
                         video.currentTime = maxWatched; // Disparo Inmediato (puede fallar por buffer pending, pero previene disparo de end)
                         setTimeout(() => { video.currentTime = maxWatched; }, 10); // Disparo Diferido Fuerte
                         return; // Terminar la ejecución de timeupdate inmediatamente
                     }
                 }
             } else if (!video.seeking && isLessonCompleted) {
                 if (currentTime > maxWatched) maxWatched = currentTime;
             }
             
             const pct = video.duration ? (currentTime / video.duration) * 100 : 0;
             if(trackerBar) trackerBar.style.width = pct + '%';
             if(percentText) percentText.innerText = Math.floor(pct) + '%';
             
             if (currentTime - lastSaveTime > timeStep) {
                 lastSaveTime = currentTime;
                 saveProgress(false);
             }

             if (!isLessonCompleted && (maxWatched / video.duration) > 0.90) { 
                 saveProgress(true);
                 unlockNextBtn();
                 const currItem = document.querySelector('.l-curr-item.active .l-icon');
                 if(currItem) {
                     currItem.classList.remove('active');
                     currItem.classList.add('completed');
                     currItem.innerHTML = "<i class='bx bx-check'></i>";
                 }
             }
        });

        video.addEventListener('seeking', () => {
            if (!isLessonCompleted) {
                 if (video.currentTime > maxWatched + 0.5) {
                     video.currentTime = maxWatched; // Disparo Fuerte preventivo
                     // El timeout evita que el navegador ignore la órden de reseteo si está trabado en un "buffer pending"
                     setTimeout(() => { video.currentTime = maxWatched; }, 10);
                 }
            }
        });

        window.addEventListener('beforeunload', () => saveProgress());
    }
</script>
