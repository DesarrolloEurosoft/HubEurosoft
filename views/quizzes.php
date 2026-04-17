<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'INSTRUCTOR'])) {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes para gestionar evaluaciones.</p>";
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_quiz') {
        $courseId = $_POST['course_id'] ?? '';
        $courseTitle = $_POST['course_title'] ?? 'General';
        
        if ($courseId) {
            $quizId = generateCuid();
            $quizTitle = "Examen de " . $courseTitle;
            $stmt = $pdo->prepare("INSERT INTO Quiz (id, title, courseId, createdAt, updatedAt) VALUES (?, ?, ?, NOW(), NOW())");
            try {
                if ($stmt->execute([$quizId, $quizTitle, $courseId])) {
                    $successMsg = "Examen matriz creado correctamente.";
                }
            } catch (Exception $e) {
                $errorMsg = "Error al inicializar el examen. Puede que ya exista uno.";
            }
        }
    }

    if ($action === 'delete_quiz') {
        $quizId = $_POST['quiz_id'] ?? '';
        if ($quizId) {
            $stmt = $pdo->prepare("DELETE FROM Quiz WHERE id = ?");
            if ($stmt->execute([$quizId])) {
                $successMsg = "Examen eliminado definitivamente.";
            }
        }
    }

    if ($action === 'create_quiz_global') {
        $courseId = $_POST['course_id'] ?? '';
        $customTitle = trim($_POST['quiz_title'] ?? '');
        
        if ($courseId) {
            $quizId = generateCuid();
            
            // Si no me mandaron titulo custom, se lo asocio del curso
            if (!$customTitle) {
                $cStmt = $pdo->prepare("SELECT title FROM Course WHERE id = ?");
                $cStmt->execute([$courseId]);
                $cTitle = $cStmt->fetchColumn();
                $quizTitle = "Examen de " . ($cTitle ?: "General");
            } else {
                $quizTitle = $customTitle;
            }

            $stmt = $pdo->prepare("INSERT INTO Quiz (id, title, courseId, createdAt, updatedAt) VALUES (?, ?, ?, NOW(), NOW())");
            try {
                if ($stmt->execute([$quizId, $quizTitle, $courseId])) {
                    $successMsg = "Nueva evaluación registrada y enlazada correctamente.";
                }
            } catch (Exception $e) {
                $errorMsg = "Error al inicializar el examen centralizado.";
            }
        }
    }
}

// Extraemos los Cursos junto a su Quiz correspondiente (relación 1:1 estricta o 1:0)
$stmt = $pdo->query("
    SELECT c.id as courseId, c.title as courseTitle,
           q.id as quizId, q.title as quizTitle, q.createdAt,
           (SELECT COUNT(*) FROM Question WHERE quizId = q.id) as questionCount,
           (SELECT GROUP_CONCAT(CONCAT(tr.name, '::', qpg.minimumScore) SEPARATOR '||')
            FROM QuizPassingGrade qpg
            JOIN TrainingRole tr ON qpg.trainingRoleId = tr.id
            WHERE qpg.quizId = q.id) as passing_grades
    FROM Course c
    LEFT JOIN Quiz q ON c.id = q.courseId
    ORDER BY c.title ASC
");
$coursesWithQuizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$availableCoursesList = array_filter($coursesWithQuizzes, function($c) { return empty($c['quizId']); });

// Bandeja de Calificación Pendiente
$stmtPending = $pdo->query("
    SELECT cp.id as courseProgressId, cp.userId, u.firstName, u.lastName, c.title as courseTitle, q.title as quizTitle,
           (SELECT COUNT(*) FROM StudentAnswer sa WHERE sa.courseProgressId = cp.id AND sa.manualScore IS NULL AND (SELECT questionType FROM Question q2 WHERE q2.id = sa.questionId) = 'OPEN_ENDED') as pendingCount
    FROM CourseProgress cp
    JOIN User u ON cp.userId = u.id
    JOIN Course c ON cp.courseId = c.id
    JOIN Quiz q ON c.id = q.courseId
    WHERE cp.quizScore IS NULL AND cp.isCompleted = 1
    HAVING pendingCount > 0
");
$pendingEvaluations = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

$answersByCp = [];
if (!empty($pendingEvaluations)) {
    $answersQuery = $pdo->query("
        SELECT sa.id as answerId, sa.courseProgressId, sa.textAnswer, q.text as questionText, q.expectedAnswer 
        FROM StudentAnswer sa
        JOIN Question q ON sa.questionId = q.id
        WHERE q.questionType = 'OPEN_ENDED' AND sa.manualScore IS NULL
    ");
    $allPendingAnswers = $answersQuery->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPendingAnswers as $ans) {
        if (!isset($answersByCp[$ans['courseProgressId']])) $answersByCp[$ans['courseProgressId']] = [];
        $answersByCp[$ans['courseProgressId']][] = $ans;
    }
}
?>

<div style="max-width: 1400px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.7s ease-out; font-family: 'Inter', sans-serif;">


    <?php if ($successMsg): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #16a34a;"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #dc2626;"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (!empty($pendingEvaluations)): ?>
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 16px; padding: 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 15px rgba(245, 158, 11, 0.1);">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <div style="width: 50px; height: 50px; background: #fde68a; color: #d97706; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; font-weight: 900;">
                    <i class='bx bxs-inbox'></i>
                </div>
                <div>
                    <h3 style="margin: 0 0 0.3rem 0; color: #92400e; font-size: 1.2rem; font-weight: 900;">Bandeja de Ensayos Abiertos</h3>
                    <p style="margin: 0; color: #b45309; font-size: 0.9rem; font-weight: 500;">Tienes <strong><?= count($pendingEvaluations) ?></strong> exámenes encriptados esperando revisión manual del instructor.</p>
                </div>
            </div>
            <button onclick="openModal('modalPendingTray')" style="background: #d97706; color: white; border: none; padding: 0.8rem 1.5rem; border-radius: 10px; font-weight: 800; font-size: 0.95rem; cursor: pointer; box-shadow: 0 4px 10px rgba(217, 119, 6, 0.3); transition: background 0.2s;" onmouseover="this.style.background='#b45309'" onmouseout="this.style.background='#d97706'">
                Revisar Ensayos ➔
            </button>
        </div>
    <?php endif; ?>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1.5rem;">
            <!-- Add-Card para Nueva Evaluación -->
            <div onclick="openModal('modalCreateQuizGlobal')" style="border: 2px dashed #cbd5e1; border-radius: 16px; background: transparent; cursor: pointer; display: flex; flex-direction: column; justify-content: center; align-items: center; color: #64748b; transition: all 0.2s; min-height: 250px;" onmouseover="this.style.borderColor='#6366f1'; this.style.color='#4f46e5'; this.style.background='#eef2ff'; this.style.transform='translateY(-3px)';" onmouseout="this.style.borderColor='#cbd5e1'; this.style.color='#64748b'; this.style.background='transparent'; this.style.transform='translateY(0)';">
                <i class='bx bx-plus' style="font-size: 3.5rem; margin-bottom: 0.5rem; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"></i>
                <span style="font-size: 1.1rem; font-weight: 800; letter-spacing: -0.01em;">Nueva Evaluación</span>
            </div>

            <?php if (!empty($coursesWithQuizzes)): ?>
                <?php foreach ($coursesWithQuizzes as $row): ?>
                    <div style="background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #f1f5f9; padding: 1.5rem; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 10px 25px -5px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.05)';">
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.8rem;">
                                <div style="width: 42px; height: 42px; background: #e0e7ff; color: #4338ca; border-radius: 10px; font-weight: 900; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <i class='bx bx-book-bookmark'></i>
                                </div>
                                <h4 style="font-size: 1.05rem; font-weight: 800; color: #1e293b; margin: 0; line-height: 1.3;">
                                    <?php echo htmlspecialchars($row['courseTitle']); ?>
                                </h4>
                            </div>
                            <?php if ($row['quizId']): ?>
                                <span style="background: #dcfce7; color: #166534; padding: 0.3rem 0.6rem; border-radius: 20px; font-size: 0.65rem; font-weight: 800; border: 1px solid #bbf7d0; flex-shrink: 0; margin-left: 0.5rem;">ACTIVO</span>
                            <?php else: ?>
                                <span style="background: #f1f5f9; color: #64748b; padding: 0.3rem 0.6rem; border-radius: 20px; font-size: 0.65rem; font-weight: 800; border: 1px solid #e2e8f0; flex-shrink: 0; margin-left: 0.5rem;">INACTIVO</span>
                            <?php endif; ?>
                        </div>

                        <?php if ($row['quizId']): ?>
                            <div style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 0.8rem 1rem; border-radius: 10px; margin-bottom: 1rem;">
                                <div>
                                    <span style="font-size: 1.2rem; font-weight: 900; color: #4338ca;"><?php echo $row['questionCount']; ?></span>
                                    <span style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-left: 0.3rem;">Preguntas</span>
                                </div>
                                <i class='bx bx-check-circle' style="color: #10b981; font-size: 1.4rem;"></i>
                            </div>

                            <div style="flex-grow: 1; margin-bottom: 1.5rem;">
                                <label style="font-size: 0.7rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-bottom: 0.5rem; display: block;">Mínimos Aprobatorios</label>
                                <div style="display: flex; flex-direction: column; gap: 0.4rem;">
                                    <?php 
                                    if (!empty($row['passing_grades'])) {
                                        $pg_chunks = explode('||', $row['passing_grades']);
                                        foreach ($pg_chunks as $chk) {
                                            $parts = explode('::', $chk);
                                            if (count($parts) === 2) {
                                                echo '<div style="font-size: 0.75rem; color: #334155; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #e2e8f0; padding-bottom: 0.3rem;">';
                                                echo '<span style="font-weight: 600; text-overflow: ellipsis; overflow: hidden; white-space: nowrap; max-width: 140px;" title="' . htmlspecialchars($parts[0]) . '">' . htmlspecialchars($parts[0]) . '</span>';
                                                echo '<span style="color: #047857; font-weight: 800; background: #d1fae5; padding: 0.2rem 0.5rem; border-radius: 6px;">' . htmlspecialchars($parts[1]) . '%</span>';
                                                echo '</div>';
                                            }
                                        }
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-size: 0.75rem; font-weight: 500; font-style: italic;">Sin perfiles configurados</span>';
                                    }
                                    ?>
                                </div>
                            </div>

                            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 1rem; margin-top: auto;">
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Destruir el examen y toda su base de preguntas definitivamente?');">
                                    <input type="hidden" name="action" value="delete_quiz">
                                    <input type="hidden" name="quiz_id" value="<?php echo htmlspecialchars($row['quizId']); ?>">
                                    <button type="submit" style="background: none; border: none; padding: 0.5rem; color: #ef4444; cursor: pointer; border-radius: 8px; transition: background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'" title="Eliminar Examen">
                                        <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                                    </button>
                                </form>

                                <a href="index.php?view=quiz_workshop&id=<?php echo urlencode($row['quizId']); ?>" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #ea580c; text-decoration: none; padding: 0.6rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 0.85rem; background: #fff7ed; transition: background 0.2s;" onmouseover="this.style.background='#ffedd5'" onmouseout="this.style.background='#fff7ed'">
                                    ⚙️ Configurar
                                </a>
                            </div>

                        <?php else: ?>
                            
                            <div style="flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem 0; color: #94a3b8;">
                                <i class='bx bx-ghost' style="font-size: 3rem; margin-bottom: 0.5rem; opacity: 0.5;"></i>
                                <span style="font-size: 0.85rem; font-weight: 600; text-align: center;">Este curso no tiene<br>ninguna evaluación final asignada.</span>
                            </div>

                            <div style="border-top: 1px solid #f1f5f9; padding-top: 1rem; margin-top: auto;">
                                <form method="POST" style="margin: 0; width: 100%;">
                                    <input type="hidden" name="action" value="create_quiz">
                                    <input type="hidden" name="course_id" value="<?php echo htmlspecialchars($row['courseId']); ?>">
                                    <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($row['courseTitle']); ?>">
                                    <button type="submit" style="width: 100%; display: inline-flex; justify-content: center; align-items: center; gap: 0.5rem; color: white; border: none; padding: 0.8rem; border-radius: 10px; font-weight: 700; font-size: 0.9rem; background: #10b981; cursor: pointer; transition: background 0.2s; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                                        + Inicializar Examen
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: Creación Global -->
<div class="modal-overlay" id="modalCreateQuizGlobal">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Forjar Nueva Evaluación</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreateQuizGlobal')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_quiz_global">
            
            <p style="font-size: 0.85rem; color: #475569; margin-bottom: 1.5rem; line-height: 1.4;">
                Las evaluaciones son la base para certificar a los usuarios. Primero debes enlazar esta nueva evaluación a uno de tus cursos disponibles.
            </p>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 800; color: #1e293b;">Curso Patrón de Certificación</label>
                <?php if(empty($availableCoursesList)): ?>
                    <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 10px; font-size: 0.85rem; font-weight: 700;">
                        No existen cursos libres. Todos tus cursos ya tienen un examen enlazado.
                    </div>
                <?php else: ?>
                    <select name="course_id" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f1f5f9; border: 1px solid #cbd5e1;">
                        <option value="">-- Asigna a un curso libre --</option>
                        <?php foreach($availableCoursesList as $ac): ?>
                            <option value="<?php echo htmlspecialchars($ac['courseId']); ?>">
                                <?php echo htmlspecialchars($ac['courseTitle']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
            </div>

            <div class="form-group" style="margin-bottom: 2rem;">
                <label class="form-label" style="font-weight: 800; color: #1e293b;">Título Libre del Examen <span style="font-weight:400; color:#94a3b8;">(Opcional)</span></label>
                <input type="text" name="quiz_title" class="form-control" placeholder="Ej: Test Final de Riesgos" style="padding: 0.8rem; border-radius: 10px; border: 1px solid #cbd5e1;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalCreateQuizGlobal')">Abortar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px; background: #6366f1; border: none; box-shadow: 0 4px 10px rgba(99, 102, 241, 0.3);" <?php echo empty($availableCoursesList) ? 'disabled' : ''; ?>>
                    Generar Examen Matriz
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    async function gradeManualAnswer(answerId, score) {
        if(!confirm('¿Otorgar esta puntuación definitiva?')) return;
        try {
            const res = await fetch('api_grade_manual.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ answerId, score })
            });
            const data = await res.json();
            if (data.success) {
                document.getElementById('ans_' + answerId).style.opacity = '0.5';
                document.getElementById('ans_' + answerId).style.pointerEvents = 'none';
                if (data.quizFinished) {
                    alert('¡Examen Concluido! El alumno ha sido certificado con: ' + data.finalScore + '%');
                    location.reload();
                }
            } else {
                alert('Error al calificar: ' + data.error);
            }
        } catch(e) {
            alert('Error interactuando con el motor evaluador remote.');
        }
    }
</script>

<!-- Modal: Bandeja de Pendientes -->
<div class="modal-overlay" id="modalPendingTray">
    <div class="modal-content" style="max-width: 800px; border-radius: 20px; max-height: 90vh; overflow-y: auto;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem; color: #92400e;">Resolución de Ensayos Pendientes</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalPendingTray')"><i class='bx bx-x'></i></button>
        </div>
        
        <div style="display:flex; flex-direction:column; gap:1.5rem;">
            <?php if(!empty($pendingEvaluations)): ?>
                <?php foreach ($pendingEvaluations as $pe): ?>
                    <?php $studentAnswers = $answersByCp[$pe['courseProgressId']] ?? []; ?>
                    <?php if(!empty($studentAnswers)): ?>
                        <div style="border: 2px solid #e2e8f0; border-radius: 12px; padding: 1.5rem; background: #fafafa;">
                            <h4 style="margin: 0 0 1.5rem 0; color: #1e293b; font-size: 1.15rem;">
                                <i class='bx bx-user' style="color: #6366f1;"></i> <?= htmlspecialchars($pe['firstName'] . ' ' . $pe['lastName']) ?> 
                                <span style="font-size: 0.8rem; background: #e0e7ff; color: #4338ca; padding: 0.2rem 0.5rem; border-radius: 6px; margin-left: 0.5rem;"><?= htmlspecialchars($pe['quizTitle']) ?></span>
                            </h4>
                            
                            <?php foreach ($studentAnswers as $sa): ?>
                                <div style="background: white; padding: 1rem; border-radius: 10px; margin-bottom: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #f1f5f9;" id="ans_<?= $sa['answerId'] ?>">
                                    <div style="font-weight: 800; color: #0f172a; margin-bottom: 0.8rem; font-size: 0.95rem;">
                                        Q: <?= nl2br(htmlspecialchars($sa['questionText'])) ?>
                                    </div>
                                    <div style="background: #f8fafc; border: 1px solid #cbd5e1; padding: 1rem; border-radius: 8px; font-style: italic; color: #334155; margin-bottom: 0.8rem; font-size: 0.95rem;">
                                        "<?= nl2br(htmlspecialchars($sa['textAnswer'])) ?>"
                                    </div>
                                    <?php if ($sa['expectedAnswer']): ?>
                                        <div style="font-size: 0.8rem; color: #be185d; margin-bottom: 1rem; background: #fdf2f8; padding: 0.5rem 0.8rem; border-radius: 6px; border: 1px dashed #fbcfe8;">
                                            <i class='bx bx-check-shield'></i> <strong>Directriz Esperada:</strong> <?= htmlspecialchars($sa['expectedAnswer']) ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div style="display: flex; justify-content: flex-end; gap: 0.8rem;">
                                        <button onclick="gradeManualAnswer('<?= $sa['answerId'] ?>', 0)" style="display: inline-flex; align-items: center; gap: 0.3rem; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 800; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#fecaca'" onmouseout="this.style.background='#fee2e2'"><i class='bx bx-x-circle'></i> Fallo (0)</button>
                                        <button onclick="gradeManualAnswer('<?= $sa['answerId'] ?>', 1)" style="display: inline-flex; align-items: center; gap: 0.3rem; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 0.6rem 1.2rem; border-radius: 8px; font-weight: 800; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#bbf7d0'" onmouseout="this.style.background='#dcfce7'"><i class='bx bx-check-circle'></i> Acierto (1)</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="text-align: center; color: #94a3b8; padding: 2rem;">No hay nada encolado en este momento.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
