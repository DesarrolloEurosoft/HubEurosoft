<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// Control de Acceso
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$isAdmin = in_array($userRole, ['ADMIN', 'INSTRUCTOR']);

if ($isAdmin) {

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

$successMsg = '';
$errorMsg = '';

// Procesar CRUD Básico de Cursos (Crear y Eliminar)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_course') {
        $title = trim($_POST['title'] ?? '');
        if ($title) {
            $newId = generateCuid();
            $stmt = $pdo->prepare("INSERT INTO Course (id, title, createdAt, updatedAt) VALUES (?, ?, NOW(), NOW())");
            if ($stmt->execute([$newId, $title])) {
                // Redirigir directamente al motor de edición (Workshop)
                echo "<script>window.location.href = 'index.php?view=course_workshop&id=" . urlencode($newId) . "';</script>";
                exit;
            } else {
                $errorMsg = "Error interno al crear perfil del curso.";
            }
        }
    }
    
    // Aquí podrías procesar 'delete_course' si lo deseas...
    if ($action === 'delete_course') {
        $delId = $_POST['course_id'] ?? '';
        if ($delId) {
            $stmt = $pdo->prepare("DELETE FROM Course WHERE id = ?");
            if ($stmt->execute([$delId])) {
                $successMsg = "Curso eliminado satisfactoriamente.";
            } else {
                $errorMsg = "Error al eliminar el curso, verifica las relaciones restrictivas.";
            }
        }
    }

    // ── DUPLICAR CURSO (copia completa: curso + módulos + lecciones + tópicos) ──
    if ($action === 'duplicate_course') {
        $srcId = $_POST['course_id'] ?? '';
        if ($srcId) {
            try {
                $pdo->beginTransaction();

                // 1. Copiar el curso raíz
                $srcCourse = $pdo->prepare("SELECT title, description, imageUrl, certificateId FROM Course WHERE id = ?");
                $srcCourse->execute([$srcId]);
                $orig = $srcCourse->fetch(PDO::FETCH_ASSOC);

                $newCourseId = generateCuid();
                $pdo->prepare("INSERT INTO Course (id, title, description, imageUrl, certificateId, demoUntilLessonId, createdAt, updatedAt)
                               VALUES (?, ?, ?, ?, ?, NULL, NOW(), NOW())")
                   ->execute([$newCourseId, $orig['title'] . ' (Copia)', $orig['description'], $orig['imageUrl'], $orig['certificateId']]);

                // 2. Copiar módulos
                $srcMods = $pdo->prepare("SELECT id, title, description, `order` FROM Module WHERE courseId = ? ORDER BY `order` ASC");
                $srcMods->execute([$srcId]);
                $modules = $srcMods->fetchAll(PDO::FETCH_ASSOC);

                foreach ($modules as $mod) {
                    $newModId = generateCuid();
                    $pdo->prepare("INSERT INTO Module (id, courseId, title, description, `order`, createdAt, updatedAt)
                                  VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
                       ->execute([$newModId, $newCourseId, $mod['title'], $mod['description'], $mod['order']]);

                    // 3. Copiar lecciones del módulo
                    $srcLessons = $pdo->prepare("SELECT id, title, description, content, videoUrl, documentUrl, presentationUrl, `order` FROM Lesson WHERE moduleId = ? ORDER BY `order` ASC");
                    $srcLessons->execute([$mod['id']]);
                    $lessons = $srcLessons->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($lessons as $les) {
                        $newLesId = generateCuid();
                        $pdo->prepare("INSERT INTO Lesson (id, moduleId, title, description, content, videoUrl, documentUrl, presentationUrl, `order`, createdAt, updatedAt)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                           ->execute([$newLesId, $newModId, $les['title'], $les['description'], $les['content'], $les['videoUrl'], $les['documentUrl'], $les['presentationUrl'], $les['order']]);

                        // 4. Copiar tópicos de la lección
                        $srcTopics = $pdo->prepare("SELECT title, description, content, videoUrl, documentUrl, presentationUrl, `order` FROM Topic WHERE lessonId = ? ORDER BY `order` ASC");
                        $srcTopics->execute([$les['id']]);
                        $topics = $srcTopics->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($topics as $top) {
                            $pdo->prepare("INSERT INTO Topic (id, lessonId, title, description, content, videoUrl, documentUrl, presentationUrl, `order`, createdAt, updatedAt)
                                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                               ->execute([generateCuid(), $newLesId, $top['title'], $top['description'], $top['content'], $top['videoUrl'], $top['documentUrl'], $top['presentationUrl'], $top['order']]);
                        }
                    }
                }

                $pdo->commit();
                // Redirigir directo al Workshop del nuevo curso
                echo "<script>window.location.href = 'index.php?view=course_workshop&id=" . urlencode($newCourseId) . "';</script>";
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMsg = "Error al duplicar el curso: " . $e->getMessage();
            }
        }
    }

    require_once 'utils/assignment_sync.php';
    syncAllCourseAssignments($pdo);
}

// 1. Fetching all courses
$stmt = $pdo->query("
    SELECT c.id, c.title, c.description, c.imageUrl, c.createdAt,
           (SELECT COUNT(m.id) FROM Module m WHERE m.courseId = c.id) as modulesCount,
           (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as lessonsCount,
           
           (SELECT GROUP_CONCAT(CONCAT(tr.id, '::', tr.name) SEPARATOR '||')
            FROM _CourseToTrainingRole c2tr
            JOIN TrainingRole tr ON c2tr.B = tr.id
            WHERE c2tr.A = c.id) as directRoles,
            
           (SELECT GROUP_CONCAT(CONCAT(tr.id, '::', tr.name) SEPARATOR '||')
            FROM LearningPathCourse lpc
            JOIN LearningPath lp ON lpc.learningPathId = lp.id
            JOIN _LearningPathToTrainingRole lp2tr ON lp.id = lp2tr.A
            JOIN TrainingRole tr ON lp2tr.B = tr.id
            WHERE lpc.courseId = c.id) as pathRoles

    FROM Course c
    ORDER BY c.createdAt DESC
");
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

function parseRoles($rolesString) {
    if (!$rolesString) return [];
    $roles = [];
    $pairs = explode('||', $rolesString);
    foreach ($pairs as $pair) {
        $parts = explode('::', $pair);
        if (count($parts) == 2) {
            // Deduplicate by ID
            $roles[$parts[0]] = $parts[1];
        }
    }
    return $roles;
}
?>

<style>
.courses-outer { max-width: 1600px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.7s ease-out; }
.courses-cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr)); gap:1.5rem; padding:1.5rem; background:#f8fafc; }
@media(max-width:768px) {
    .courses-outer { padding: 1rem; padding-bottom: 6rem; }
    .courses-cards-grid { grid-template-columns:1fr; gap:1rem; padding:1rem; }
}
@keyframes fadeIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }
</style>

<div class="courses-outer">


    <?php if ($successMsg): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border-left: 4px solid #16a34a;">
            <?php echo htmlspecialchars($successMsg); ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 500; border-left: 4px solid #dc2626;">
            <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: visible; border: 1px solid rgba(0,0,0,0.04);">
        <div class="courses-cards-grid">
            <!-- Add-Card para nuevo curso -->
            <div onclick="openModal('modalCreateCourse')" style="border: 2px dashed #cbd5e1; border-radius: 16px; background: transparent; cursor: pointer; display: flex; flex-direction: column; justify-content: center; align-items: center; color: #64748b; transition: all 0.2s; min-height: 250px;" onmouseover="this.style.borderColor='#6366f1'; this.style.color='#4f46e5'; this.style.background='#eef2ff'; this.style.transform='translateY(-3px)';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.color='#64748b'; this.style.background='transparent'; this.style.transform='translateY(0)';">
                <i class='bx bx-plus' style="font-size: 3.5rem; margin-bottom: 0.5rem; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"></i>
                <span style="font-size: 1.1rem; font-weight: 800; letter-spacing: -0.01em;">Diseñar Nuevo Curso</span>
            </div>
            
            <?php if (count($courses) > 0): ?>
                <?php foreach ($courses as $course): 
                    $directRoles = parseRoles($course['directRoles']);
                    $pathRolesRaw = parseRoles($course['pathRoles']);
                    
                    // Deduplicate
                    $pathRoles = [];
                    foreach ($pathRolesRaw as $pid => $pname) {
                        if (!isset($directRoles[$pid])) {
                            $pathRoles[$pid] = $pname;
                        }
                    }
                ?>
                    <div style="background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; padding: 1.5rem; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 10px 25px -5px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.05)';">
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.8rem;">
                                <div style="width: 42px; height: 42px; background: #eff6ff; color: #2563eb; border-radius: 10px; font-weight: 900; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <i class='bx bx-book-reader'></i>
                                </div>
                                <h4 style="font-size: 1.05rem; font-weight: 800; color: #1e293b; margin: 0; line-height: 1.3;">
                                    <?php echo htmlspecialchars($course['title']); ?>
                                </h4>
                            </div>
                        </div>

                        <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 0.8rem 1rem; border-radius: 10px; margin-bottom: 1rem; border: 1px solid #f1f5f9;">
                            <div>
                                <span style="font-size: 1.2rem; font-weight: 900; color: #4f46e5;"><?php echo (int)$course['modulesCount']; ?></span>
                                <span style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-left: 0.3rem;">Módulos</span>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 1.2rem; font-weight: 900; color: #0284c7;"><?php echo (int)$course['lessonsCount']; ?></span>
                                <span style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-left: 0.3rem;">Lecciones</span>
                            </div>
                        </div>

                        <div style="flex-grow: 1; margin-bottom: 1.5rem;">
                            <label style="font-size: 0.7rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-bottom: 0.5rem; display: block;">Perfiles Asignados</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                                <?php foreach ($directRoles as $rid => $rname): ?>
                                    <span style="font-size: 0.65rem; font-weight: 800; background: #fff7ed; border: 1px solid #ffedd5; color: #ea580c; padding: 0.3rem 0.6rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;" title="Asignación Directa">
                                        <span>👤</span> <?php echo mb_strtoupper(htmlspecialchars($rname)); ?>
                                    </span>
                                <?php endforeach; ?>
                                
                                <?php foreach ($pathRoles as $rid => $rname): ?>
                                    <span style="font-size: 0.65rem; font-weight: 800; background: #eff6ff; border: 1px solid #dbeafe; color: #2563eb; padding: 0.3rem 0.6rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 4px;" title="Vía Ruta de Aprendizaje">
                                        <span>🗺️</span> <?php echo mb_strtoupper(htmlspecialchars($rname)); ?>
                                    </span>
                                <?php endforeach; ?>

                                <?php if (empty($directRoles) && empty($pathRoles)): ?>
                                    <span style="font-size: 0.65rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; background: #f1f5f9; padding: 0.3rem 0.6rem; border-radius: 8px;">
                                        Acceso Libre
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 1rem; margin-top: auto;">
                            <div style="display:flex; gap:0.4rem; align-items:center;">
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('\u00bfEliminar este curso y todo su contenido?');">
                                    <input type="hidden" name="action" value="delete_course">
                                    <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course['id']); ?>">
                                    <button type="submit" style="background: none; border: none; padding: 0.5rem; color: #ef4444; cursor: pointer; border-radius: 8px; transition: background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'" title="Eliminar Curso">
                                        <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                                    </button>
                                </form>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('\u00bfDuplicar este curso con todo su contenido?');">
                                    <input type="hidden" name="action" value="duplicate_course">
                                    <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($course['id']); ?>">
                                    <button type="submit" style="background: none; border: none; padding: 0.5rem; color: #6366f1; cursor: pointer; border-radius: 8px; transition: background 0.2s;" onmouseover="this.style.background='#e0e7ff'" onmouseout="this.style.background='none'" title="Duplicar Curso">
                                        <i class='bx bx-copy' style="font-size: 1.2rem;"></i>
                                    </button>
                                </form>
                            </div>

                            <a href="index.php?view=course_workshop&id=<?php echo urlencode($course['id']); ?>" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #4f46e5; text-decoration: none; padding: 0.6rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 0.85rem; background: #e0e7ff; transition: background 0.2s;" onmouseover="this.style.background='#c7d2fe'" onmouseout="this.style.background='#e0e7ff'">
                                ✏️ Configurar Curso
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Modal: Crear Nuevo Curso -->
<div class="modal-overlay" id="modalCreateCourse">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header">
            <h3 class="modal-title" style="font-weight: 800;">Nombrar Nuevo Curso</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreateCourse')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_course">
            
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1.5rem;">
                Nombra tu nuevo curso formativo. Al crearlo serás redirigido al espacio de trabajo (Workshop) para construir su temario paso a paso.
            </p>
            
            <div class="form-group">
                <label class="form-label" style="font-weight: 600;">Título del Curso</label>
                <input type="text" name="title" class="form-control" required placeholder="Ej: Inducción Corporativa 2024" style="padding: 0.8rem; border-radius: 10px;">
            </div>
            
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main); font-weight: 600;" onclick="closeModal('modalCreateCourse')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 700; padding: 0.6rem 1.5rem; border-radius: 10px;">Continuar al Workshop ➔</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>

</style>
<?php 
} else { 
    // ==================================================================================== //
    // LÓGICA DEL ESTUDIANTE: Catálogo "Mis Cursos" (Réplica de ClientMisCursos.tsx)         //
    // ==================================================================================== //
    $userId = $_SESSION['user_id'];
    
    // 1. Fetch user data
    $stmtU = $pdo->prepare("SELECT name, image FROM User WHERE id = ?");
    $stmtU->execute([$userId]);
    $studentData = $stmtU->fetch(PDO::FETCH_ASSOC);

    // 2. Fetch learning paths assigned to user
    $stmtPaths = $pdo->prepare("
        SELECT lp.id, lp.name, lp.description 
        FROM LearningPath lp 
        JOIN _LearningPathToTrainingRole lptr ON lp.id = lptr.A 
        JOIN _TrainingRoleToUser tru ON lptr.B = tru.A 
        WHERE tru.B = ?
    ");
    $stmtPaths->execute([$userId]);
    $learningPaths = $stmtPaths->fetchAll(PDO::FETCH_ASSOC);

    $pathTitles = count($learningPaths) > 0 ? implode(', ', array_column($learningPaths, 'name')) : "Catálogo Oficial";
    $assignedRoleName = count($learningPaths) > 0 ? $learningPaths[0]['name'] : "Libre Acceso";

    $pathsData = [];
    $allCoursesFlat = [];
    $flatAssoc = [];
    
    $statusWeight = [
        'completed' => 5,
        'quiz-pending' => 4,
        'in-progress' => 3,
        'available' => 2,
        'locked' => 1
    ];
    
    foreach ($learningPaths as $lp) {
        $stmtLpc = $pdo->prepare("
            SELECT c.id, c.title, c.description, c.imageUrl, c.demoUntilLessonId, lpc.order as lpOrder,
                   (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as lessonsCount,
                   (SELECT id FROM Quiz WHERE courseId = c.id LIMIT 1) as quizId,
                   (SELECT isCompleted FROM CourseProgress cp WHERE cp.courseId = c.id AND cp.userId = ?) as isCompleted,
                   (SELECT quizPassed FROM CourseProgress cp WHERE cp.courseId = c.id AND cp.userId = ?) as quizPassed,
                   (SELECT COUNT(lp.id) FROM LessonProgress lp JOIN Lesson l ON lp.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id AND lp.userId = ?) as hasProgress
            FROM Course c
            JOIN LearningPathCourse lpc ON c.id = lpc.courseId
            WHERE lpc.learningPathId = ?
            ORDER BY lpc.order ASC
        ");
        $stmtLpc->execute([$userId, $userId, $userId, $lp['id']]);
        $courses = $stmtLpc->fetchAll(PDO::FETCH_ASSOC);
        
        $processedCourses = [];
        $previousCompleted = true; // Secuencia independiente por Ruta
        
        foreach ($courses as $index => $c) {
            $c['xp'] = 100; 
            $c['duration'] = "A tu ritmo";
            $c['progressPercent'] = 0;
            $hasQuiz = !empty($c['quizId']);
            $quizPassed = !empty($c['quizPassed']);

            if (!empty($c['isCompleted'])) {
                if ($hasQuiz && !$quizPassed) {
                   // Vio todas las lecciones, pero no ha pasado el examen
                   $c['status'] = 'quiz-pending';
                   $c['progressPercent'] = 99;
                   $previousCompleted = false; // TRABAR EL SIGUIENTE CURSO
                } else {
                   $c['status'] = 'completed';
                   $c['progressPercent'] = 100;
                }
            } else {
                $totalL = (int)$c['lessonsCount'];
                $compL = (int)$c['hasProgress'];
                if ($totalL > 0) {
                    $pct = round(($compL / $totalL) * 100);
                    $c['progressPercent'] = $pct > 99 ? 99 : $pct;
                } else {
                    $c['progressPercent'] = $compL > 0 ? 50 : 0;
                }

                if ($previousCompleted) {
                    $c['status'] = ($compL > 0) ? 'in-progress' : 'available';
                    $previousCompleted = false; // Trabar los subsiguientes
                } else {
                    $c['status'] = 'locked';
                }
            }
            $processedCourses[] = $c;
            
            $cId = $c['id'];
            if (!isset($flatAssoc[$cId])) {
                $flatAssoc[$cId] = $c;
            } else {
                if ($statusWeight[$c['status']] > $statusWeight[$flatAssoc[$cId]['status']]) {
                    $flatAssoc[$cId] = $c;
                }
            }
        }
        $lp['courses'] = $processedCourses;
        $pathsData[] = $lp;
    }

    $allCoursesFlat = array_values($flatAssoc);
    
    $totalCourses = count($allCoursesFlat);
    $completedCourses = count(array_filter($allCoursesFlat, fn($c) => $c['status'] === 'completed'));
    $inProgressCourses = count(array_filter($allCoursesFlat, fn($c) => $c['status'] === 'in-progress'));
    
    $overallProgress = $totalCourses > 0 ? round(($completedCourses / $totalCourses) * 100) : 0;
    
    $nextCourseData = null;
    foreach($allCoursesFlat as $c) {
        if ($c['status'] === 'in-progress') { $nextCourseData = $c; break; }
    }
    if (!$nextCourseData) {
        foreach($allCoursesFlat as $c) {
            if ($c['status'] === 'available') { $nextCourseData = $c; break; }
        }
    }
    
    require_once __DIR__ . '/courses_student_v3.php';

} // Cierra el else
?>