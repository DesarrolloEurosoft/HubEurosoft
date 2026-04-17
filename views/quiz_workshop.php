<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'INSTRUCTOR'])) {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes.</p>";
    exit;
}

$quizId = $_GET['id'] ?? null;
if (!$quizId) {
    echo "<h2>Error</h2><p>Examen no especificado.</p>";
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Editar Título, Curso y Notas Mínimas
    if ($action === 'edit_quiz') {
        $title = trim($_POST['title'] ?? '');
        $courseIdToAssign = trim($_POST['courseId'] ?? '');
        $minScores = $_POST['min_scores'] ?? []; // Array id_role => porcentaje
        
        if ($title && $courseIdToAssign) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE Quiz SET title = ?, courseId = ?, updatedAt = NOW() WHERE id = ?");
                $stmt->execute([$title, $courseIdToAssign, $quizId]);

                $pdo->prepare("DELETE FROM QuizPassingGrade WHERE quizId = ?")->execute([$quizId]);
                
                $sGrade = $pdo->prepare("INSERT INTO QuizPassingGrade (id, quizId, trainingRoleId, minimumScore, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
                foreach ($minScores as $rId => $scoreVal) {
                    if ($scoreVal !== '' && (int)$scoreVal >= 0 && (int)$scoreVal <= 100) {
                        $sGrade->execute([generateCuid(), $quizId, $rId, (int)$scoreVal]);
                    }
                }
                $pdo->commit();
                $successMsg = "Estructura general del examen y matrícula guardadas exitosamente.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMsg = "Error al actualizar propiedades. Es posible que el curso destino ya posea un examen propio.";
            }
        }
    }

    // Question
    if ($action === 'create_question') {
        $text = trim($_POST['text'] ?? '');
        $type = $_POST['questionType'] ?? 'MULTIPLE_CHOICE';
        
        if ($text) {
            $oStmt = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) + 1 FROM Question WHERE quizId = ?");
            $oStmt->execute([$quizId]);
            $order = $oStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO Question (id, text, questionType, requiresManualGrading, `order`, quizId, createdAt, updatedAt) VALUES (?, ?, ?, 0, ?, ?, NOW(), NOW())");
            $stmt->execute([generateCuid(), $text, $type, $order, $quizId]);
            $successMsg = "Pregunta integrada al examen.";
        }
    }

    if ($action === 'edit_question') {
        $qId = $_POST['question_id'] ?? '';
        $text = trim($_POST['text'] ?? '');
        $type = $_POST['questionType'] ?? 'MULTIPLE_CHOICE';
        if ($qId && $text) {
            $stmt = $pdo->prepare("UPDATE Question SET text = ?, questionType = ?, updatedAt = NOW() WHERE id = ?");
            $stmt->execute([$text, $type, $qId]);
            $successMsg = "Estructura del reactivo reconfigurada exitosamente.";
        }
    }

    if ($action === 'delete_question') {
        $qId = $_POST['question_id'] ?? '';
        if ($qId) {
            $stmt = $pdo->prepare("DELETE FROM Question WHERE id = ?");
            $stmt->execute([$qId]);
            $successMsg = "Pregunta eliminada.";
        }
    }

    // Option (Para MULTIPLE_CHOICE)
    if ($action === 'create_option') {
        $qId = $_POST['question_id'] ?? '';
        $text = trim($_POST['text'] ?? '');
        $isCorrect = isset($_POST['isCorrect']) ? 1 : 0;
        
        if ($qId && $text) {
            $stmt = $pdo->prepare("INSERT INTO `Option` (id, text, isCorrect, questionId, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([generateCuid(), $text, $isCorrect, $qId]);
            $successMsg = "Opción agregada a la evaluación.";
        }
    }

    if ($action === 'edit_option') {
        $optId = $_POST['option_id'] ?? '';
        $text = trim($_POST['text'] ?? '');
        $isCorrect = isset($_POST['isCorrect']) ? 1 : 0;
        if ($optId && $text) {
            $stmt = $pdo->prepare("UPDATE `Option` SET text = ?, isCorrect = ?, updatedAt = NOW() WHERE id = ?");
            $stmt->execute([$text, $isCorrect, $optId]);
            $successMsg = "Variante de respuesta corregida.";
        }
    }

    if ($action === 'delete_option') {
        $optId = $_POST['option_id'] ?? '';
        if ($optId) {
            $stmt = $pdo->prepare("DELETE FROM `Option` WHERE id = ?");
            $stmt->execute([$optId]);
            $successMsg = "Respuesta removida.";
        }
    }

    // Abierta (OPEN_ENDED)
    if ($action === 'edit_open_ended') {
        $qId = $_POST['question_id'] ?? '';
        $expected = trim($_POST['expectedAnswer'] ?? '');
        $reqManual = isset($_POST['requiresManualGrading']) ? 1 : 0;
        if ($qId) {
            $stmt = $pdo->prepare("UPDATE Question SET expectedAnswer = ?, requiresManualGrading = ?, updatedAt = NOW() WHERE id = ? AND questionType = 'OPEN_ENDED'");
            $stmt->execute([$expected, $reqManual, $qId]);
            $successMsg = "Parámetros de calificación abierta configurados.";
        }
    }

    // Matching
    if ($action === 'create_matching_pair') {
        $qId = $_POST['question_id'] ?? '';
        $left = trim($_POST['leftItem'] ?? '');
        $right = trim($_POST['rightItem'] ?? '');
        if ($qId && $left && $right) {
            $oStmt = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) + 1 FROM MatchingPair WHERE questionId = ?");
            $oStmt->execute([$qId]);
            $optOrder = $oStmt->fetchColumn();

            $stmt = $pdo->prepare("INSERT INTO MatchingPair (id, questionId, leftItem, rightItem, `order`, createdAt) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([generateCuid(), $qId, $left, $right, $optOrder]);
            $successMsg = "Nuevo par de columnas establecido.";
        }
    }

    if ($action === 'edit_matching_pair') {
        $pairId = $_POST['pair_id'] ?? '';
        $left = trim($_POST['leftItem'] ?? '');
        $right = trim($_POST['rightItem'] ?? '');
        if ($pairId && $left && $right) {
            $stmt = $pdo->prepare("UPDATE MatchingPair SET leftItem = ?, rightItem = ? WHERE id = ?");
            $stmt->execute([$left, $right, $pairId]);
            $successMsg = "Matriz relacional corregida extiosamente.";
        }
    }

    if ($action === 'delete_matching_pair') {
        $pairId = $_POST['pair_id'] ?? '';
        if ($pairId) {
            $stmt = $pdo->prepare("DELETE FROM MatchingPair WHERE id = ?");
            $stmt->execute([$pairId]);
            $successMsg = "Par relacional destruido correctamente.";
        }
    }
}

// Fetch Quiz & Course Info
$stmt = $pdo->prepare("
    SELECT q.id, q.title, q.courseId, q.createdAt, c.title as courseTitle 
    FROM Quiz q JOIN Course c ON q.courseId = c.id 
    WHERE q.id = ?
");
$stmt->execute([$quizId]);
$quiz = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    echo "<h2>Error 404</h2><p>El examen solicitado ya no existe en la base de datos.</p>";
    exit;
}

// Fetch Minimum Passing Grades mapped by Role
$pGrades = $pdo->prepare("SELECT trainingRoleId, minimumScore FROM QuizPassingGrade WHERE quizId = ?");
$pGrades->execute([$quizId]);
$currentGradesMap = [];
while($g = $pGrades->fetch(PDO::FETCH_ASSOC)) {
    $currentGradesMap[$g['trainingRoleId']] = $g['minimumScore'];
}

$allRoles = $pdo->query("SELECT id, name FROM TrainingRole ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$availableCourses = $pdo->prepare("SELECT id, title FROM Course WHERE id NOT IN (SELECT courseId FROM Quiz WHERE id != ?) ORDER BY title ASC");
$availableCourses->execute([$quizId]);
$allAvailableCourses = $availableCourses->fetchAll(PDO::FETCH_ASSOC);

// Fetch Questions (Drill-Down)
$stmtQ = $pdo->prepare("SELECT * FROM Question WHERE quizId = ? ORDER BY `order` ASC");
$stmtQ->execute([$quizId]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

$optionsMap = [];
$matchingMap = [];
foreach ($questions as $q) {
    if ($q['questionType'] === 'MULTIPLE_CHOICE') {
        $sOpt = $pdo->prepare("SELECT id, text, isCorrect FROM `Option` WHERE questionId = ? ORDER BY createdAt ASC");
        $sOpt->execute([$q['id']]);
        $optionsMap[$q['id']] = $sOpt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($q['questionType'] === 'MATCHING') {
        $sMatch = $pdo->prepare("SELECT id, leftItem, rightItem, `order` FROM MatchingPair WHERE questionId = ? ORDER BY `order` ASC, createdAt ASC");
        $sMatch->execute([$q['id']]);
        $matchingMap[$q['id']] = $sMatch->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div style="max-width: 1300px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.7s ease-out; font-family: 'Inter', sans-serif;">
    <div style="margin-bottom: 1.5rem;">
        <a href="index.php?view=quizzes" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #64748b; text-decoration: none; font-weight: 700; font-size: 0.85rem; padding: 0.5rem 1rem; border-radius: 12px; background: #f1f5f9; transition: all 0.2s;" onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
            <i class='bx bx-left-arrow-alt' style="font-size: 1.2rem;"></i> Volver a Exámenes
        </a>
    </div>

    <header style="margin-bottom: 2rem; padding: 2rem; background: linear-gradient(135deg, #1e1b4b 0%, #4338ca 100%); border-radius: 24px; color: white; box-shadow: 0 10px 30px rgba(67, 56, 202, 0.2); display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <span style="font-size: 0.8rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #a5b4fc; display: block; margin-bottom: 0.5rem;">Examen de Calificación</span>
            <h1 style="font-size: 2.2rem; font-weight: 900; margin: 0; line-height: 1.2;"><?php echo htmlspecialchars($quiz['title']); ?></h1>
            <p style="color: #e0e7ff; margin-top: 0.5rem; font-size: 1rem;"><i class='bx bx-book-bookmark'></i> Curso vinculado: <?php echo htmlspecialchars($quiz['courseTitle']); ?></p>
        </div>
        <div>
            <button class="btn" onclick="openModal('modalEditQuiz')" style="display: inline-flex; align-items: center; gap: 0.5rem; background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); padding: 0.6rem 1.2rem; border-radius: 14px; font-weight: 800; font-size: 0.9rem; backdrop-filter: blur(5px); transition: all 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.25)'" onmouseout="this.style.background='rgba(255,255,255,0.15)'">
                ⚙️ Ajustes y Porcentajes
            </button>
        </div>
    </header>

    <?php if ($successMsg): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 14px; margin-bottom: 2.5rem; font-weight: 600; border-left: 5px solid #10b981; display: flex; align-items: center; gap: 0.5rem;"><i class='bx bx-check-circle' style="font-size: 1.4rem;"></i> <?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 14px; margin-bottom: 2.5rem; font-weight: 600; border-left: 5px solid #ef4444; display: flex; align-items: center; gap: 0.5rem;"><i class='bx bx-error-circle' style="font-size: 1.4rem;"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <!-- Constructor Principal -->
    <section>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.6rem; font-weight: 900; color: #1e293b; margin: 0;">Reactivos del Examen</h2>
            <button class="btn btn-primary" onclick="openModal('modalAddQuestion')" style="display: inline-flex; align-items: center; gap: 0.4rem; font-weight: 800; border-radius: 12px; padding: 0.5rem 1.2rem; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);">
                + Nueva Pregunta
            </button>
        </div>

        <?php if (empty($questions)): ?>
            <div style="background: white; border-radius: 20px; text-align: center; padding: 5rem 2rem; border: 2px dashed #cbd5e1; color: #64748b;">
                <i class='bx bx-task' style="font-size: 4rem; opacity: 0.3; margin-bottom: 1rem;"></i>
                <h3 style="font-size: 1.3rem; margin-bottom: 0.5rem; font-weight: 800; color: #334155;">Lienzo Vacío</h3>
                <p>Usa el botón superior azul para agregar la primera pregunta de opción múltiple.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <?php $stepIdx = 1; ?>
                <?php foreach ($questions as $q): ?>
                    <article style="background: white; border-radius: 20px; padding: 2rem; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #e2e8f0;">
                        <!-- Question Header -->
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 1rem;">
                            <div style="display: flex; gap: 1rem; align-items: flex-start;">
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: #e0e7ff; color: #4338ca; font-weight: 900; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <?php echo $stepIdx++; ?>
                                </div>
                                <div>
                                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #0f172a; margin: 0 0 0.4rem 0; line-height: 1.4;">
                                        <?php echo nl2br(htmlspecialchars($q['text'])); ?>
                                    </h3>
                                    <?php if($q['questionType'] === 'MULTIPLE_CHOICE'): ?>
                                        <span style="font-size: 0.7rem; font-weight: 800; background: #f1f5f9; padding: 0.3rem 0.6rem; border-radius: 8px; color: #64748b; letter-spacing: 0.05em;">OPCIÓN MÚLTIPLE</span>
                                    <?php elseif($q['questionType'] === 'OPEN_ENDED'): ?>
                                        <span style="font-size: 0.7rem; font-weight: 800; background: #fdf2f8; padding: 0.3rem 0.6rem; border-radius: 8px; color: #db2777; letter-spacing: 0.05em;">PREGUNTA ABIERTA</span>
                                    <?php elseif($q['questionType'] === 'MATCHING'): ?>
                                        <span style="font-size: 0.7rem; font-weight: 800; background: #f0fdf4; padding: 0.3rem 0.6rem; border-radius: 8px; color: #16a34a; letter-spacing: 0.05em;">RELACIÓN DE COLUMNAS</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 0.5rem;">
                                <?php if($q['questionType'] === 'OPEN_ENDED'): ?>
                                    <button onclick="openEditOpenEndedModal('<?php echo $q['id']; ?>', `<?php echo htmlspecialchars($q['expectedAnswer'] ?? '', ENT_QUOTES); ?>`, <?php echo $q['requiresManualGrading'] ? 'true' : 'false'; ?>)" style="background: none; border: none; padding: 0.4rem; color: #db2777; cursor: pointer; border-radius: 8px;" onmouseover="this.style.background='#fdf2f8'" onmouseout="this.style.background='none'" title="Configurar Calificación">
                                        <i class='bx bx-cog' style="font-size: 1.2rem;"></i>
                                    </button>
                                <?php endif; ?>
                                <button onclick="openEditQuestionModal('<?php echo $q['id']; ?>', `<?php echo htmlspecialchars($q['text'], ENT_QUOTES); ?>`, '<?php echo $q['questionType']; ?>')" style="background: none; border: none; padding: 0.4rem; color: #3b82f6; cursor: pointer; border-radius: 8px;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='none'" title="Editar Enunciado y Formato">
                                    <i class='bx bx-edit' style="font-size: 1.2rem;"></i>
                                </button>
                                <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Borrar definitivamente esta pregunta y todo su material interno?');">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="question_id" value="<?php echo htmlspecialchars($q['id']); ?>">
                                    <button type="submit" style="background: none; border: none; padding: 0.4rem; color: #ef4444; cursor: pointer; border-radius: 8px;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'" title="Eliminar Pregunta">
                                        <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <?php if($q['questionType'] === 'MULTIPLE_CHOICE'): ?>
                            <!-- Options List (Multiple Choice) -->
                            <div style="background: #f8fafc; border-radius: 14px; padding: 1.5rem; border: 1px dashed #cbd5e1;">
                                <h4 style="font-size: 0.85rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 1rem; letter-spacing: 0.05em;">Respuestas Posibles (<span style="color: #10b981; font-weight: 900;">1 Correcta Mín.</span>)</h4>
                                
                                <div style="display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1.5rem;">
                                    <?php if (!empty($optionsMap[$q['id']])): ?>
                                        <?php foreach ($optionsMap[$q['id']] as $opt): ?>
                                            <div style="display: flex; justify-content: space-between; align-items: center; background: white; border: 1px solid <?php echo $opt['isCorrect'] ? '#34d399' : '#e2e8f0'; ?>; padding: 0.8rem 1.2rem; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: all 0.2s;">
                                                <div style="display: flex; align-items: center; gap: 0.8rem;">
                                                    <?php if($opt['isCorrect']): ?>
                                                        <div style="width: 24px; height: 24px; background: #10b981; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                                                            <i class='bx bx-check'></i>
                                                        </div>
                                                    <?php else: ?>
                                                        <div style="width: 24px; height: 24px; background: #f1f5f9; color: #94a3b8; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1rem; border: 2px solid #e2e8f0;">
                                                            <i class='bx bx-x'></i>
                                                        </div>
                                                    <?php endif; ?>
                                                    <span style="font-size: 0.95rem; font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars($opt['text']); ?></span>
                                                </div>

                                                <div style="display: flex; gap: 0.3rem;">
                                                    <button onclick="openEditOptionModal('<?php echo $opt['id']; ?>', `<?php echo htmlspecialchars($opt['text'], ENT_QUOTES); ?>`, <?php echo $opt['isCorrect'] ? 'true' : 'false'; ?>)" style="background: none; border: none; padding: 0.3rem; color: #94a3b8; cursor: pointer; border-radius: 6px;" onmouseover="this.style.color='#3b82f6'" onmouseout="this.style.color='#94a3b8'" title="Corregir Variante">
                                                        <i class='bx bx-edit' style="font-size: 1.4rem;"></i>
                                                    </button>
                                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Remover esta respuesta?');">
                                                        <input type="hidden" name="action" value="delete_option">
                                                        <input type="hidden" name="option_id" value="<?php echo htmlspecialchars($opt['id']); ?>">
                                                        <button type="submit" style="background: none; border: none; padding: 0.3rem; color: #cbd5e1; cursor: pointer; border-radius: 6px;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#cbd5e1'" title="Eliminar Respuesta">
                                                            <i class='bx bx-x' style="font-size: 1.5rem;"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="padding: 1rem; text-align: center; color: #94a3b8; font-size: 0.85rem; font-style: italic;">Sin respuestas configuradas.</div>
                                    <?php endif; ?>
                                </div>

                                <button onclick="openAddOptionModal('<?php echo htmlspecialchars($q['id']); ?>')" style="display: flex; justify-content: center; width: 100%; border: 2px dashed #cbd5e1; background: transparent; color: #64748b; font-weight: 700; font-size: 0.85rem; padding: 0.8rem; border-radius: 12px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#94a3b8'; this.style.color='#475569';" onmouseout="this.style.background='transparent'; this.style.borderColor='#cbd5e1'; this.style.color='#64748b';">
                                    + Añadir Variante de Respuesta
                                </button>
                            </div>
                        
                        <?php elseif($q['questionType'] === 'OPEN_ENDED'): ?>
                            <div style="background: #fdf2f8; border-radius: 14px; padding: 1.5rem; border: 1px dashed #fbcfe8;">
                                <h4 style="font-size: 0.85rem; font-weight: 800; color: #be185d; text-transform: uppercase; margin-bottom: 1rem; letter-spacing: 0.05em;">Criterios de Calificación (<span style="color: #9d174d; font-weight: 900;">Pregunta Abierta</span>)</h4>
                                
                                <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-bottom: 1rem;">
                                    <?php if($q['requiresManualGrading']): ?>
                                        <div style="background: #fce7f3; color: #9d174d; padding: 0.8rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border-left: 4px solid #be185d; display: flex; align-items: center; gap: 0.5rem;">
                                            <i class='bx bx-user-voice'></i> El instructor deberá validar manualmente y otorgar puntos.
                                        </div>
                                    <?php else: ?>
                                        <div style="background: #ecfdf5; color: #065f46; padding: 0.8rem; border-radius: 8px; font-weight: 700; font-size: 0.85rem; border-left: 4px solid #10b981; display: flex; align-items: center; gap: 0.5rem;">
                                            <i class='bx bx-bot'></i> Calificación Automatizada contra palabras clave.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <label style="font-size: 0.8rem; font-weight: 800; color: #831843;">Palabras Clave (Keywords):</label>
                                <div style="background: white; border: 1px solid #fbcfe8; border-radius: 8px; padding: 1rem; color: #475569; font-size: 0.9rem; min-height: 50px;">
                                    <?php echo $q['expectedAnswer'] ? htmlspecialchars($q['expectedAnswer']) : '<span style="color:#cbd5e1;font-style:italic;">No hay keywords registradas. El Bot fallará toda respuesta por defecto.</span>'; ?>
                                </div>
                            </div>
                        
                        <?php elseif($q['questionType'] === 'MATCHING'): ?>
                            <div style="background: #f0fdf4; border-radius: 14px; padding: 1.5rem; border: 1px dashed #86efac;">
                                <h4 style="font-size: 0.85rem; font-weight: 800; color: #166534; text-transform: uppercase; margin-bottom: 1rem; letter-spacing: 0.05em;">Matriz de Relación Columna Izquierda vs Derecha</h4>
                                
                                <div style="display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1.5rem;">
                                    <?php if (!empty($matchingMap[$q['id']])): ?>
                                        <div style="display: grid; grid-template-columns: 1fr auto 1fr auto; gap: 1rem; align-items: center; font-weight: 800; font-size: 0.75rem; color: #166534; padding: 0 1rem; margin-bottom: 0.5rem; text-transform: uppercase;">
                                            <div>Columna Estática (Concepto)</div>
                                            <div></div>
                                            <div>Columna Arrastrable (Correcta)</div>
                                            <div></div>
                                        </div>
                                        <?php foreach ($matchingMap[$q['id']] as $pair): ?>
                                            <div style="display: grid; grid-template-columns: 1fr auto 1fr auto; gap: 1rem; align-items: center; background: white; border: 1px solid #bbf7d0; padding: 0.6rem 1rem; border-radius: 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                                <div style="background: #f8fafc; padding: 0.5rem; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 0.9rem; font-weight: 600; color: #334155;">
                                                    <?php echo htmlspecialchars($pair['leftItem']); ?>
                                                </div>
                                                <div style="color: #cbd5e1; font-weight: 900;"><i class='bx bx-right-arrow-alt'></i></div>
                                                <div style="background: #ecfdf5; padding: 0.5rem; border-radius: 8px; border: 1px solid #a7f3d0; font-size: 0.9rem; font-weight: 600; color: #047857;">
                                                    <?php echo htmlspecialchars($pair['rightItem']); ?>
                                                </div>

                                                <div style="display: flex; gap: 0.3rem;">
                                                    <button onclick="openEditMatchingPairModal('<?php echo $pair['id']; ?>', `<?php echo htmlspecialchars($pair['leftItem'], ENT_QUOTES); ?>`, `<?php echo htmlspecialchars($pair['rightItem'], ENT_QUOTES); ?>`)" style="background: none; border: none; padding: 0.3rem; color: #94a3b8; cursor: pointer; border-radius: 6px;" onmouseover="this.style.color='#3b82f6'" onmouseout="this.style.color='#94a3b8'" title="Edición Doble de Valores">
                                                        <i class='bx bx-edit' style="font-size: 1.4rem;"></i>
                                                    </button>
                                                    <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Remover este enlace cruzado?');">
                                                        <input type="hidden" name="action" value="delete_matching_pair">
                                                        <input type="hidden" name="pair_id" value="<?php echo htmlspecialchars($pair['id']); ?>">
                                                        <button type="submit" style="background: none; border: none; padding: 0.3rem; color: #cbd5e1; cursor: pointer; border-radius: 6px;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#cbd5e1'" title="Eliminar Par">
                                                            <i class='bx bx-x' style="font-size: 1.5rem;"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="padding: 1rem; text-align: center; color: #94a3b8; font-size: 0.85rem; font-style: italic;">Sin matriz de columnas.</div>
                                    <?php endif; ?>
                                </div>

                                <button onclick="openAddMatchingPairModal('<?php echo htmlspecialchars($q['id']); ?>')" style="display: flex; justify-content: center; width: 100%; border: 2px dashed #86efac; background: transparent; color: #166534; font-weight: 700; font-size: 0.85rem; padding: 0.8rem; border-radius: 12px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#dcfce7'; this.style.borderColor='#4ade80'; this.style.color='#14532d';" onmouseout="this.style.background='transparent'; this.style.borderColor='#86efac'; this.style.color='#166534';">
                                    + Extender Relación de Concepto a Valor
                                </button>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Modal: Ajustes del Examen -->
<div class="modal-overlay" id="modalEditQuiz">
    <div class="modal-content" style="max-width: 600px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.4rem;">Calificaciones Esperadas (Aprobatorias)</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditQuiz')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_quiz">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700;">Título General del Examen</label>
                <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($quiz['title']); ?>" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #1e293b;">Curso Patrón de Certificación</label>
                <select name="courseId" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f1f5f9; border: 1px solid #cbd5e1;">
                    <?php foreach($allAvailableCourses as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['id']); ?>" <?php echo $c['id'] === $quiz['courseId'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom: 1.5rem;">
                <label style="font-size: 0.8rem; font-weight: 900; color: #64748b; text-transform: uppercase; margin-bottom: 1rem; display: block;">Asignación Relacional (% Aprobatorio)</label>
                <p style="font-size: 0.8rem; color: #94a3b8; margin-bottom: 1rem;">Mapea el porcentaje mínimo (0% - 100%) exigido para certificar a los usuarios organizados bajo estos perfiles.</p>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; padding: 1rem; max-height: 250px; overflow-y: auto;">
                    <?php foreach ($allRoles as $role): 
                        $val = isset($currentGradesMap[$role['id']]) ? $currentGradesMap[$role['id']] : '';
                    ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0; border-bottom: 1px dashed #cbd5e1;">
                            <label style="font-size: 0.9rem; font-weight: 700; color: #334155; display: flex; align-items: center; gap: 0.5rem;">
                                <span>👤</span> <?php echo htmlspecialchars($role['name']); ?>
                            </label>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <input type="number" name="min_scores[<?php echo htmlspecialchars($role['id']); ?>]" value="<?php echo htmlspecialchars($val); ?>" min="0" max="100" placeholder="---" style="width: 70px; padding: 0.4rem; text-align: center; font-weight: 800; border-radius: 8px; border: 1px solid #cbd5e1; outline: none;">
                                <span style="font-weight: 900; color: #94a3b8; font-size: 1.2rem;">%</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalEditQuiz')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);">Firmar Ajustes</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Añadir Pregunta -->
<div class="modal-overlay" id="modalAddQuestion">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Insertar Enunciado Abierto</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalAddQuestion')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_question">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700;">Desarrollo del Reactivo</label>
                <textarea name="text" class="form-control" rows="3" required placeholder="Ej: Escribe tu pregunta aquí..." style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700;">Formato Estructural de la Pregunta</label>
                <select name="questionType" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; border: 1px solid #cbd5e1;">
                    <option value="MULTIPLE_CHOICE">Opción Múltiple (Radio/Checks)</option>
                    <option value="OPEN_ENDED">Pregunta Abierta Estricta / Manual</option>
                    <option value="MATCHING">Relación de Columnas y Pares</option>
                </select>
                <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.5rem; line-height: 1.3;">El algoritmo variará automáticamente la arquitectura visual post-guardado según este parámetro.</p>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalAddQuestion')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px;">Forjar Pregunta</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Añadir Respuesta/Opción -->
<div class="modal-overlay" id="modalAddOption">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Construir Variante de Respuesta</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalAddOption')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_option">
            <input type="hidden" name="question_id" id="opt_question_id">
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700;">Texto de la Variante</label>
                <input type="text" name="text" class="form-control" required placeholder="..." style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <label style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.5rem; background: #ecfdf5; padding: 1rem; border-radius: 10px; border: 1px solid #a7f3d0; cursor: pointer; transition: all 0.2s;">
                <input type="checkbox" name="isCorrect" value="1" style="width: 20px; height: 20px; accent-color: #10b981; cursor: pointer;">
                <span style="font-weight: 800; color: #047857;">¿Es la respuesta afirmativa? (Correcta)</span>
            </label>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalAddOption')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background: #ef4444; border: none; font-weight: 800; border-radius: 10px; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);">Inyectar Variante</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Pregunta -->
<div class="modal-overlay" id="modalEditQuestion">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 900;">Editar Enunciado Abierto</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditQuestion')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_question">
            <input type="hidden" name="question_id" id="edit_question_id">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700;">Desarrollo del Reactivo</label>
                <textarea name="text" id="edit_question_text" class="form-control" rows="3" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical;"></textarea>
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700;">Formato Estructural de la Pregunta</label>
                <select name="questionType" id="edit_question_type" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; border: 1px solid #cbd5e1;">
                    <option value="MULTIPLE_CHOICE">Opción Múltiple (Radio/Checks)</option>
                    <option value="OPEN_ENDED">Pregunta Abierta Estricta / Manual</option>
                    <option value="MATCHING">Relación de Columnas y Pares</option>
                </select>
                <p style="font-size: 0.75rem; color: #ef4444; margin-top: 0.5rem; line-height: 1.3;">⚠️ Cuidado: Al cambiar el formato perderás la visualización de los contenedores internos antiguos (Opciones o Matrices) pero no los datos en sí.</p>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalEditQuestion')">Eliminar Cambios</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px;">Sobrescribir Extensión</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Respuesta/Opción -->
<div class="modal-overlay" id="modalEditOption">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Corregir Variante</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditOption')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_option">
            <input type="hidden" name="option_id" id="edit_opt_id">
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700;">Texto de la Variante</label>
                <input type="text" name="text" id="edit_opt_text" class="form-control" required placeholder="..." style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <label style="display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1.5rem; background: #ecfdf5; padding: 1rem; border-radius: 10px; border: 1px solid #a7f3d0; cursor: pointer; transition: all 0.2s;">
                <input type="checkbox" name="isCorrect" id="edit_opt_isCorrect" value="1" style="width: 20px; height: 20px; accent-color: #10b981; cursor: pointer;">
                <span style="font-weight: 800; color: #047857;">¿Es la respuesta afirmativa? (Correcta)</span>
            </label>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalEditOption')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background: #3b82f6; border: none; font-weight: 800; border-radius: 10px; box-shadow: 0 4px 10px rgba(59, 130, 246, 0.3);">Firmar Corrección</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Añadir Par Matching -->
<div class="modal-overlay" id="modalAddMatchingPair">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Forjar Relación Binaria</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalAddMatchingPair')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_matching_pair">
            <input type="hidden" name="question_id" id="matching_question_id">
            
            <p style="font-size: 0.8rem; color: #64748b; margin-bottom: 1.5rem;">Al usuario se le presentarán los conceptos izquierdos fijos y deberá arrastrar aleatoriamente las definiciones derechas.</p>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #0f172a;">Elemento Estático (Concepto)</label>
                <input type="text" name="leftItem" class="form-control" required placeholder="Concepto Fijo..." style="padding: 0.8rem; border-radius: 10px; border: 1px solid #cbd5e1;">
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #166534;">Elemento Arrastrable Correspondiente (Definición)</label>
                <input type="text" name="rightItem" class="form-control" required placeholder="Definición Válida..." style="padding: 0.8rem; border-radius: 10px; border: 1px solid #86efac; background: #f0fdf4;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalAddMatchingPair')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px; background: #16a34a; border: none;">Interconectar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Par Matching -->
<div class="modal-overlay" id="modalEditMatchingPair">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem;">Alterar Variables Existentes</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditMatchingPair')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_matching_pair">
            <input type="hidden" name="pair_id" id="edit_pair_id">
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #0f172a;">Elemento Estático (Concepto)</label>
                <input type="text" name="leftItem" id="edit_pair_left" class="form-control" required placeholder="Concepto Fijo..." style="padding: 0.8rem; border-radius: 10px; border: 1px solid #cbd5e1;">
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #166534;">Elemento Arrastrable Correspondiente (Definición)</label>
                <input type="text" name="rightItem" id="edit_pair_right" class="form-control" required placeholder="Definición Válida..." style="padding: 0.8rem; border-radius: 10px; border: 1px solid #86efac; background: #f0fdf4;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalEditMatchingPair')">Abortar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; border-radius: 10px; background: #3b82f6; border: none;">Reconfigurar Par</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Configurar Pregunta Abierta -->
<div class="modal-overlay" id="modalEditOpenEnded">
    <div class="modal-content" style="max-width: 550px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 900; font-size: 1.3rem; color: #9d174d;">Comportamiento Automático de Evaluación</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditOpenEnded')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_open_ended">
            <input type="hidden" name="question_id" id="open_question_id">
            
            <p style="font-size: 0.85rem; color: #475569; margin-bottom: 1.5rem; line-height: 1.4;">
                Las preguntas abiertas pueden ser validadas por el algoritmo bot cotejando su respuesta con <b>palabras clave requeridas separadas por coma</b>, o pueden ser enviadas a una fila de revisión humana estricta bloqueando la certificación temporalmente.
            </p>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 800;">Palabras Clave de Referencia Exigidas</label>
                <textarea name="expectedAnswer" id="open_expected_val" class="form-control" rows="3" placeholder="Ej: liderazgo, gestión, recursos humanos" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical; border: 1px solid #fbcfe8; outline-color: #f472b6;"></textarea>
            </div>

            <label style="display: flex; align-items: flex-start; gap: 0.8rem; margin-bottom: 1.5rem; background: #fce7f3; padding: 1.2rem; border-radius: 12px; border: 1px dashed #f9a8d4; cursor: pointer;">
                <input type="checkbox" name="requiresManualGrading" value="1" id="open_manual_val" style="width: 22px; height: 22px; accent-color: #db2777; cursor: pointer; margin-top: 2px;">
                <div>
                    <span style="font-weight: 900; color: #831843; display: block; font-size: 0.95rem;">Interceptación Humana Requerida</span>
                    <span style="font-size: 0.75rem; color: #be185d;">El algoritmo ignorará las Keywords y asignará una Calificación Manual Pendiente, la ruta del estudiante se pausará de acuerdo a su puntaje actual sin entregar certificado automático.</span>
                </div>
            </label>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f1f5f9; color: #475569; border-radius: 10px; font-weight: 700;" onclick="closeModal('modalEditOpenEnded')">Abortar</button>
                <button type="submit" class="btn" style="font-weight: 800; border-radius: 10px; background: #db2777; color: white; border: none; box-shadow: 0 4px 10px rgba(219, 39, 119, 0.3);">Firmar Reglas Algorítmicas</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openEditQuestionModal(id, text, type) {
        document.getElementById('edit_question_id').value = id;
        document.getElementById('edit_question_text').value = text;
        document.getElementById('edit_question_type').value = type;
        openModal('modalEditQuestion');
    }

    function openAddOptionModal(qId) {
        document.getElementById('opt_question_id').value = qId;
        openModal('modalAddOption');
    }

    function openEditOptionModal(id, text, isCorrect) {
        document.getElementById('edit_opt_id').value = id;
        document.getElementById('edit_opt_text').value = text;
        document.getElementById('edit_opt_isCorrect').checked = isCorrect;
        openModal('modalEditOption');
    }

    function openAddMatchingPairModal(qId) {
        document.getElementById('matching_question_id').value = qId;
        openModal('modalAddMatchingPair');
    }

    function openEditMatchingPairModal(id, left, right) {
        document.getElementById('edit_pair_id').value = id;
        document.getElementById('edit_pair_left').value = left;
        document.getElementById('edit_pair_right').value = right;
        openModal('modalEditMatchingPair');
    }

    function openEditOpenEndedModal(qId, expectedVal, isManual) {
        document.getElementById('open_question_id').value = qId;
        document.getElementById('open_expected_val').value = expectedVal;
        document.getElementById('open_manual_val').checked = isManual;
        openModal('modalEditOpenEnded');
    }
</script>

<style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
</style>
