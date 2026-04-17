<?php
session_start();
require 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array(strtoupper($_SESSION['user_role'] ?? ''), ['ADMIN', 'INSTRUCTOR'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no soportado']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'error' => 'Payload JSON inválido']);
    exit;
}

$answerId = $input['answerId'] ?? '';
$score = isset($input['score']) ? (int)$input['score'] : null;

if (!$answerId || $score === null) {
    echo json_encode(['success' => false, 'error' => 'Faltan parámetros']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Obtener la respuesta y verificar que pertenezca a un progreso
    $stmt = $pdo->prepare("SELECT courseProgressId, userId FROM StudentAnswer WHERE id = ?");
    $stmt->execute([$answerId]);
    $ans = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ans) {
        throw new Exception("Respuesta no encontrada.");
    }

    $cpId = $ans['courseProgressId'];
    $studentId = $ans['userId'];

    // 2. Aplicar la calificación a esta respuesta específica
    $updAns = $pdo->prepare("UPDATE StudentAnswer SET isCorrect = ?, manualScore = ?, gradedAt = NOW() WHERE id = ?");
    $updAns->execute([$score, $score, $answerId]);

    // 3. Verificar si todavía quedan preguntas pendientes de revisar en este Examen (CourseProgress)
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) FROM StudentAnswer sa
        JOIN Question q ON sa.questionId = q.id
        WHERE sa.courseProgressId = ? AND q.questionType = 'OPEN_ENDED' AND sa.manualScore IS NULL
    ");
    $stmtCheck->execute([$cpId]);
    $pendingCount = (int)$stmtCheck->fetchColumn();

    if (!function_exists('generateCuid')) {
        function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
    }

    $quizFinished = false;
    $finalScore = null;

    if ($pendingCount === 0) {
        // 4. ¡Todas las respuestas abiertas han sido calificadas! Toca hacer el corte final de caja.
        $quizFinished = true;

        // Extraer totales
        $stmtTot = $pdo->prepare("
            SELECT COUNT(*) as totalQ, SUM(isCorrect) as totalCorrect 
            FROM StudentAnswer 
            WHERE courseProgressId = ?
        ");
        $stmtTot->execute([$cpId]);
        $tot = $stmtTot->fetch(PDO::FETCH_ASSOC);

        $totalQ = (int)$tot['totalQ'];
        $totalCorrect = (int)$tot['totalCorrect'];
        $finalScore = $totalQ > 0 ? round(($totalCorrect / $totalQ) * 100) : 0;

        // Extraer requerimiento de puntos para este estudiante y este curso
        $stmtCpInfo = $pdo->prepare("SELECT courseId FROM CourseProgress WHERE id = ?");
        $stmtCpInfo->execute([$cpId]);
        $courseId = $stmtCpInfo->fetchColumn();

        $stmtQz = $pdo->prepare("SELECT id FROM Quiz WHERE courseId = ?");
        $stmtQz->execute([$courseId]);
        $quizId = $stmtQz->fetchColumn();

        $stmtMinScore = $pdo->prepare("
            SELECT MIN(qpg.minimumScore) 
            FROM QuizPassingGrade qpg
            JOIN _TrainingRoleToUser tru ON qpg.trainingRoleId = tru.A
            WHERE qpg.quizId = ? AND tru.B = ?
        ");
        $stmtMinScore->execute([$quizId, $studentId]);
        $passingScore = $stmtMinScore->fetchColumn();
        if (!$passingScore) $passingScore = 80;

        $stmtPrev = $pdo->prepare("SELECT quizPassed FROM CourseProgress WHERE id = ?");
        $stmtPrev->execute([$cpId]);
        $wasPassed = $stmtPrev->fetchColumn();

        $quizPassed = ($finalScore >= (int)$passingScore) ? 1 : 0;

        // Actualizar CourseProgress, retirando la bandera de NULL
        $updCp = $pdo->prepare("UPDATE CourseProgress SET quizScore = ?, quizPassed = ?, updatedAt = NOW() WHERE id = ?");
        $updCp->execute([$finalScore, $quizPassed, $cpId]);

        // Otorgar Puntos si pasa por primera vez
        if ($quizPassed == 1 && $wasPassed == 0) {
            try {
                // 1. COURSE_COMPLETED
                $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'COURSE_COMPLETED' AND isActive = 1")->fetch();
                if ($rule && $rule['points'] > 0) {
                    $pts = (int)$rule['points'];
                    $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                    $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'COURSE_COMPLETED', 'Examen Manual Aprobado Oficialmente', NOW())")->execute([generateCuid(), $studentId, $pts]);
                }

                $uData = $pdo->prepare("SELECT businessUnitId, companyId FROM User WHERE id = ?");
                $uData->execute([$studentId]);
                $uRow = $uData->fetch();
                $buId = $uRow ? $uRow['businessUnitId'] : null;
                $companyId = $uRow ? $uRow['companyId'] : null;

                $hasBusinessUnits = false;
                if ($companyId) {
                    $stmtChKBU = $pdo->prepare("SELECT COUNT(*) FROM BusinessUnit WHERE companyId = ?");
                    $stmtChKBU->execute([$companyId]);
                    $hasBusinessUnits = ($stmtChKBU->fetchColumn() > 0);
                }

                // 2. FIRST_IN_COURSE
                if ($hasBusinessUnits) {
                    $targetBuSql = $buId ? "= ?" : "IS NULL";
                    $targetBuParam = $buId ? [$buId] : [];
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND u.businessUnitId $targetBuSql AND cp.quizPassed = 1 AND cp.userId != ?");
                    $argsFirstC = array_merge([$courseId, $companyId], $targetBuParam, [$studentId]);
                    $chkFirstC->execute($argsFirstC);
                    if ($chkFirstC->fetchColumn() == 0) {
                        $ruleFirstC = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_BU_COURSE' AND isActive = 1")->fetch();
                        if ($ruleFirstC && $ruleFirstC['points'] > 0) {
                            $pts = (int)$ruleFirstC['points'];
                            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_BU_COURSE', 'Bonus: Primero en tu Unidad en terminar este curso (Manual)', NOW())")->execute([generateCuid(), $studentId, $pts]);
                        }
                    }
                } elseif ($companyId && !$hasBusinessUnits) {
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND cp.quizPassed = 1 AND cp.userId != ?");
                    $chkFirstC->execute([$courseId, $companyId, $studentId]);
                    if ($chkFirstC->fetchColumn() == 0) {
                        $ruleFirstC = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_COMPANY_COURSE' AND isActive = 1")->fetch();
                        if ($ruleFirstC && $ruleFirstC['points'] > 0) {
                            $pts = (int)$ruleFirstC['points'];
                            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_COMPANY_COURSE', 'Bonus: Primero en tu Empresa en terminar este curso (Manual)', NOW())")->execute([generateCuid(), $studentId, $pts]);
                        }
                    }
                }



                // 3. LEARNING_PATH_COMPLETED & FIRST_IN_BU_PATH
                $paths = $pdo->prepare("SELECT learningPathId FROM LearningPathCourse WHERE courseId = ?");
                $paths->execute([$courseId]);
                $lpRows = $paths->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lpRows as $lp) {
                    $pathId = $lp['learningPathId'];
                    $chkPath = $pdo->prepare("SELECT COUNT(lpc.courseId) FROM LearningPathCourse lpc WHERE lpc.learningPathId = ? AND lpc.courseId NOT IN (SELECT courseId FROM CourseProgress WHERE userId = ? AND (quizPassed = 1 OR isCompleted = 1))");
                    $chkPath->execute([$pathId, $studentId]);
                    
                    if ($chkPath->fetchColumn() == 0) {
                        $firma = "Ruta_$pathId";
                        $chkAlready = $pdo->prepare("SELECT COUNT(*) FROM UserPoints WHERE userId = ? AND actionType = 'LEARNING_PATH_COMPLETED' AND description LIKE ?");
                        $chkAlready->execute([$studentId, "%$firma%"]);
                        
                        if ($chkAlready->fetchColumn() == 0) {
                            $rulePath = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'LEARNING_PATH_COMPLETED' AND isActive = 1")->fetch();
                            if ($rulePath && $rulePath['points'] > 0) {
                                $pts = (int)$rulePath['points'];
                                $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                                $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'LEARNING_PATH_COMPLETED', ?, NOW())")->execute([generateCuid(), $studentId, $pts, "Has finalizado el 100% de la ruta con éxito. [$firma]"]);
                                $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'MEDAL_UNLOCKED', 'Regla de Puntos Conseguida', ?, NOW())")->execute([generateCuid(), $studentId, "Ganaste +$pts XP adicionales por completar la ruta."]);
                            }

                            if ($hasBusinessUnits) {
                                $targetBuSql = $buId ? "= ?" : "IS NULL";
                                $targetBuParam = $buId ? [$buId] : [];
                                $chkFirstP = $pdo->prepare("SELECT COUNT(up.id) FROM UserPoints up JOIN User u ON up.userId = u.id WHERE up.actionType IN ('FIRST_IN_BU_PATH','LEARNING_PATH_COMPLETED') AND up.description LIKE ? AND u.companyId = ? AND u.businessUnitId $targetBuSql AND up.userId != ?");
                                $argsFirstP = array_merge(["%$firma%"], [$companyId], $targetBuParam, [$studentId]);
                                $chkFirstP->execute($argsFirstP);
                                if ($chkFirstP->fetchColumn() == 0) {
                                    $ruleFirstP = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_BU_PATH' AND isActive = 1")->fetch();
                                    if ($ruleFirstP && $ruleFirstP['points'] > 0) {
                                        $pts = (int)$ruleFirstP['points'];
                                        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_BU_PATH', 'Bonus Especial: Primer graduado de la ruta en la Unidad (Manual).', NOW())")->execute([generateCuid(), $studentId, $pts]);
                                    }
                                }
                            } elseif ($companyId && !$hasBusinessUnits) {
                                $chkFirstP = $pdo->prepare("SELECT COUNT(up.id) FROM UserPoints up JOIN User u ON up.userId = u.id WHERE up.actionType IN ('FIRST_IN_COMPANY_PATH','LEARNING_PATH_COMPLETED') AND up.description LIKE ? AND u.companyId = ? AND up.userId != ?");
                                $chkFirstP->execute(["%$firma%", $companyId, $studentId]);
                                if ($chkFirstP->fetchColumn() == 0) {
                                    $ruleFirstP = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_COMPANY_PATH' AND isActive = 1")->fetch();
                                    if ($ruleFirstP && $ruleFirstP['points'] > 0) {
                                        $pts = (int)$ruleFirstP['points'];
                                        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $studentId]);
                                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_COMPANY_PATH', 'Bonus Especial: Primer graduado de la ruta en la Empresa (Manual).', NOW())")->execute([generateCuid(), $studentId, $pts]);
                                    }
                                }
                            }
                        }
                    }
                }

            } catch(Exception $e) {
                error_log("Gamification Error in Grade Manual: " . $e->getMessage());
            }

            require_once 'utils/gamification_engine.php';
            evaluateUserAchievements($pdo, $studentId);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'quizFinished' => $quizFinished,
        'finalScore' => $finalScore,
        'pendingLeft' => $pendingCount
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Manual Grade Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error Crítico: ' . $e->getMessage()]);
}
