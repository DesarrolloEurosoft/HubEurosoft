<?php
session_start();
require 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
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

$userId = $_SESSION['user_id'];
$quizId = $input['quizId'] ?? '';
$courseId = $input['courseId'] ?? '';
$userAnswers = $input['answers'] ?? []; // Formato: [ questionId => answerId (o "true"/"false") ]

if (!$quizId || !$courseId) {
    echo json_encode(['success' => false, 'error' => 'Faltan parámetros del curso o examen.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Extraer todas las preguntas del examen y sus respuestas correctas para evitar trampa Front-End
    $stmtQ = $pdo->prepare("
        SELECT q.id, q.questionType, q.order,
               (SELECT id FROM `Option` WHERE questionId = q.id AND isCorrect = 1 LIMIT 1) as correctOptionId,
               (SELECT isCorrect FROM `Option` WHERE questionId = q.id AND isCorrect = 1 LIMIT 1) as correctBoolValue
        FROM Question q
        WHERE q.quizId = ?
    ");
    $stmtQ->execute([$quizId]);
    $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

    if (count($questions) === 0) {
        echo json_encode(['success' => false, 'error' => 'El examen no tiene preguntas vigentes.']);
        exit;
    }

    $totalQuestions = count($questions);
    $correctCount = 0;
    $hasOpenEnded = false;

    // Obtener y preparar el CourseProgress para enlazar las respuestas
    $stmtCP = $pdo->prepare("SELECT id, quizAttempts, quizPassed, isCompleted FROM CourseProgress WHERE userId = ? AND courseId = ?");
    $stmtCP->execute([$userId, $courseId]);
    $cpRows = $stmtCP->fetchAll(PDO::FETCH_ASSOC);

    if (!function_exists('generateCuid')) {
        function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
    }

    if (count($cpRows) > 0) {
        $cp = $cpRows[0];
        $cpId = $cp['id'];
        $newAttempts = (int)($cp['quizAttempts'] ?? 0) + 1;
    } else {
        $cpId = generateCuid();
        $newAttempts = 1;
        $insCP = $pdo->prepare("INSERT INTO CourseProgress (id, userId, courseId, isCompleted, quizScore, quizPassed, quizAttempts, createdAt, updatedAt) VALUES (?, ?, ?, 1, NULL, 0, 1, NOW(), NOW())");
        $insCP->execute([$cpId, $userId, $courseId]);
    }

    // Limpiar respuestas anteriores para este intento
    $del = $pdo->prepare("DELETE FROM StudentAnswer WHERE courseProgressId = ?");
    $del->execute([$cpId]);

    $insAns = $pdo->prepare("INSERT INTO StudentAnswer (id, userId, questionId, courseProgressId, selectedOptionId, textAnswer, matchingAnswer, isCorrect, createdAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

    foreach ($questions as $q) {
        $qId = $q['id'];
        $submittedAns = $userAnswers[$qId] ?? null;
        
        if ($q['questionType'] === 'OPEN_ENDED') {
            $hasOpenEnded = true;
        }

        $saId = generateCuid();
        $sa_selOptId = null;
        $sa_text = null;
        $sa_match = null;
        $sa_correct = 0;

        if ($submittedAns !== null) {
                if ($q['questionType'] === 'MULTIPLE_CHOICE') {
                    if ($submittedAns == $q['correctOptionId']) {
                        $correctCount++;
                        $sa_correct = 1;
                    }
                    $sa_selOptId = $submittedAns;
                } else if ($q['questionType'] === 'TRUE_FALSE') {
                    if ($submittedAns == $q['correctOptionId']) {
                        $correctCount++;
                        $sa_correct = 1;
                    }
                    $sa_selOptId = $submittedAns;
            } else if ($q['questionType'] === 'MATCHING') {
                $smp = $pdo->prepare("SELECT id FROM MatchingPair WHERE questionId = ?");
                $smp->execute([$qId]);
                $realPairs = $smp->fetchAll(PDO::FETCH_COLUMN);
                
                if (is_array($submittedAns) && count($realPairs) > 0) {
                    $sa_match = json_encode($submittedAns);
                    $allMatch = true;
                    foreach ($realPairs as $pid) {
                        if (!isset($submittedAns[$pid]) || $submittedAns[$pid] !== $pid) {
                            $allMatch = false;
                            break;
                        }
                    }
                    if ($allMatch) {
                        $correctCount++;
                        $sa_correct = 1;
                    }
                }
            } else if ($q['questionType'] === 'OPEN_ENDED') {
                $sa_text = (string)$submittedAns;
            }
        }
        
        // Guardar la respuesta física
        $insAns->execute([$saId, $userId, $qId, $cpId, $sa_selOptId, $sa_text, $sa_match, $sa_correct]);
    }

    // 2. Calcular porcentaje obtenido
    $finalScore = round(($correctCount / $totalQuestions) * 100);

    // 3. Determinar Puntaje Mínimo Requerido según el Perfil Operativo del Estudiante
    $stmtMinScore = $pdo->prepare("
        SELECT MIN(qpg.minimumScore) 
        FROM QuizPassingGrade qpg
        JOIN _TrainingRoleToUser tru ON qpg.trainingRoleId = tru.A
        WHERE qpg.quizId = ? AND tru.B = ?
    ");
    $stmtMinScore->execute([$quizId, $userId]);
    $passingScore = $stmtMinScore->fetchColumn();
    if (!$passingScore) {
        $passingScore = 80; // Hard fallback si el líder no asignó perfil
    }

    if ($hasOpenEnded) {
        $quizPassed = 0;
        $finalScoreDB = null; // Bandera de pendiente
        $message = 'Preguntas capturadas. Tu evaluación de ensayo ha sido enviada a Mesa de Control para calificación manual.';
    } else {
        $quizPassed = ($finalScore >= $passingScore) ? 1 : 0;
        $finalScoreDB = $finalScore;
        $message = $quizPassed ? '¡Examen Aprobado Oficialmente!' : 'No alcanzaste el mínimo, sigue intentando.';
    }

    // 4. Actualizar CourseProgress
    $upd = $pdo->prepare("UPDATE CourseProgress SET quizScore = ?, quizPassed = ?, quizAttempts = ?, updatedAt = NOW() WHERE id = ?");
    $upd->execute([$finalScoreDB, $quizPassed, $newAttempts, $cpId]);

    // 5. Otorgar Puntos de Gamificación (Solo la primera vez que aprueba)
    if ($quizPassed == 1) {
        $wasPassed = isset($cp['quizPassed']) && $cp['quizPassed'] == 1;
        if (!$wasPassed) {
            try {
                $stmtQn = $pdo->prepare("SELECT title FROM Course WHERE id = ?");
                $stmtQn->execute([$courseId]);
                $qnTitle = $stmtQn->fetchColumn() ?: 'Curso';
                $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'COURSE_COMPLETED', 'Evaluación Aprobada', ?, NOW())")->execute([generateCuid(), $userId, "Has superado la evaluación de '$qnTitle'."]);
                // 5.1 COURSE_COMPLETED
                $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'COURSE_COMPLETED' AND isActive = 1")->fetch();
                if ($rule && $rule['points'] > 0) {
                    $pts = (int)$rule['points'];
                    $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                    $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'COURSE_COMPLETED', 'Examen y Curso Aprobados Oficialmente', NOW())")->execute([generateCuid(), $userId, $pts]);
                }

                $uData = $pdo->prepare("SELECT businessUnitId, companyId FROM User WHERE id = ?");
                $uData->execute([$userId]);
                $uRow = $uData->fetch();
                $buId = $uRow ? $uRow['businessUnitId'] : null;
                $companyId = $uRow ? $uRow['companyId'] : null;

                $hasBusinessUnits = false;
                if ($companyId) {
                    $stmtChKBU = $pdo->prepare("SELECT COUNT(*) FROM BusinessUnit WHERE companyId = ?");
                    $stmtChKBU->execute([$companyId]);
                    $hasBusinessUnits = ($stmtChKBU->fetchColumn() > 0);
                }

                // 5.2 FIRST_IN_COURSE
                if ($hasBusinessUnits) {
                    $targetBuSql = $buId ? "= ?" : "IS NULL";
                    $targetBuParam = $buId ? [$buId] : [];
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND u.businessUnitId $targetBuSql AND cp.quizPassed = 1 AND cp.userId != ?");
                    $argsFirstC = array_merge([$courseId, $companyId], $targetBuParam, [$userId]);
                    $chkFirstC->execute($argsFirstC);
                    if ($chkFirstC->fetchColumn() == 0) {
                        $ruleFirstC = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_BU_COURSE' AND isActive = 1")->fetch();
                        if ($ruleFirstC && $ruleFirstC['points'] > 0) {
                            $pts = (int)$ruleFirstC['points'];
                            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_BU_COURSE', 'Bonus: Primero en tu Unidad en terminar este curso', NOW())")->execute([generateCuid(), $userId, $pts]);
                        }
                    }
                } elseif ($companyId && !$hasBusinessUnits) {
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND cp.quizPassed = 1 AND cp.userId != ?");
                    $chkFirstC->execute([$courseId, $companyId, $userId]);
                    if ($chkFirstC->fetchColumn() == 0) {
                        $ruleFirstC = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_COMPANY_COURSE' AND isActive = 1")->fetch();
                        if ($ruleFirstC && $ruleFirstC['points'] > 0) {
                            $pts = (int)$ruleFirstC['points'];
                            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_COMPANY_COURSE', 'Bonus: Primero en tu Empresa en terminar este curso', NOW())")->execute([generateCuid(), $userId, $pts]);
                        }
                    }
                }



                // 5.3 LEARNING_PATH_COMPLETED & FIRST_IN_BU_PATH
                $paths = $pdo->prepare("
                    SELECT DISTINCT lpc.learningPathId 
                    FROM LearningPathCourse lpc
                    JOIN _LearningPathToTrainingRole lptr ON lptr.A = lpc.learningPathId
                    JOIN _TrainingRoleToUser trtu ON trtu.A = lptr.B
                    WHERE lpc.courseId = ? AND trtu.B = ?
                ");
                $paths->execute([$courseId, $userId]);
                $lpRows = $paths->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lpRows as $lp) {
                    $pathId = $lp['learningPathId'];
                    $chkPath = $pdo->prepare("SELECT COUNT(lpc.courseId) FROM LearningPathCourse lpc WHERE lpc.learningPathId = ? AND lpc.courseId NOT IN (SELECT courseId FROM CourseProgress WHERE userId = ? AND (quizPassed = 1 OR isCompleted = 1))");
                    $chkPath->execute([$pathId, $userId]);
                    if ($chkPath->fetchColumn() == 0) {
                        $firma = "Ruta_$pathId";
                        $chkAlready = $pdo->prepare("SELECT COUNT(*) FROM UserPoints WHERE userId = ? AND actionType = 'LEARNING_PATH_COMPLETED' AND description LIKE ?");
                        $chkAlready->execute([$userId, "%$firma%"]);
                        
                        if ($chkAlready->fetchColumn() == 0) {
                            $stmtPn = $pdo->prepare("SELECT name FROM LearningPath WHERE id = ?");
                            $stmtPn->execute([$pathId]);
                            $pathName = $stmtPn->fetchColumn() ?: "Ruta";
                            
                            $rulePath = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'LEARNING_PATH_COMPLETED' AND isActive = 1")->fetch();
                            if ($rulePath && $rulePath['points'] > 0) {
                                $pts = (int)$rulePath['points'];
                                $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                                $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'LEARNING_PATH_COMPLETED', ?, NOW())")->execute([generateCuid(), $userId, $pts, "Has finalizado el 100% de la ruta '$pathName' con éxito. [$firma]"]);
                                $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'MEDAL_UNLOCKED', 'Regla de Puntos Conseguida', ?, NOW())")->execute([generateCuid(), $userId, "Ganaste +$pts XP adicionales por completar la ruta '$pathName'."]);
                            }

                            if ($hasBusinessUnits) {
                                $targetBuSql = $buId ? "= ?" : "IS NULL";
                                $targetBuParam = $buId ? [$buId] : [];
                                $chkFirstP = $pdo->prepare("SELECT COUNT(up.id) FROM UserPoints up JOIN User u ON up.userId = u.id WHERE up.actionType IN ('FIRST_IN_BU_PATH','LEARNING_PATH_COMPLETED') AND up.description LIKE ? AND u.companyId = ? AND u.businessUnitId $targetBuSql AND up.userId != ?");
                                $argsFirstP = array_merge(["%$firma%"], [$companyId], $targetBuParam, [$userId]);
                                $chkFirstP->execute($argsFirstP);
                                if ($chkFirstP->fetchColumn() == 0) {
                                    $ruleFirstP = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_BU_PATH' AND isActive = 1")->fetch();
                                    if ($ruleFirstP && $ruleFirstP['points'] > 0) {
                                        $pts = (int)$ruleFirstP['points'];
                                        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_BU_PATH', 'Bonus Especial: Primer graduado de la ruta en la Unidad.', NOW())")->execute([generateCuid(), $userId, $pts]);
                                    }
                                }
                            } elseif ($companyId && !$hasBusinessUnits) {
                                $chkFirstP = $pdo->prepare("SELECT COUNT(up.id) FROM UserPoints up JOIN User u ON up.userId = u.id WHERE up.actionType IN ('FIRST_IN_COMPANY_PATH','LEARNING_PATH_COMPLETED') AND up.description LIKE ? AND u.companyId = ? AND up.userId != ?");
                                $chkFirstP->execute(["%$firma%", $companyId, $userId]);
                                if ($chkFirstP->fetchColumn() == 0) {
                                    $ruleFirstP = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'FIRST_IN_COMPANY_PATH' AND isActive = 1")->fetch();
                                    if ($ruleFirstP && $ruleFirstP['points'] > 0) {
                                        $pts = (int)$ruleFirstP['points'];
                                        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'FIRST_IN_COMPANY_PATH', 'Bonus Especial: Primer graduado de la ruta en la Empresa.', NOW())")->execute([generateCuid(), $userId, $pts]);
                                    }
                                }
                            }
                        }
                    }
                }
            } catch(Exception $e) {
                error_log("Gamification Error: " . $e->getMessage());
            }

            require_once 'utils/gamification_engine.php';
            evaluateUserAchievements($pdo, $userId);
        }
    }

    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'score' => $hasOpenEnded ? 'Pendiente' : $finalScore,
        'passed' => (bool)$quizPassed,
        'passingScore' => (int)$passingScore,
        'correctCount' => $hasOpenEnded ? '---' : $correctCount,
        'totalQuestions' => $totalQuestions,
        'pendingManual' => $hasOpenEnded,
        'message' => $message
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Quiz Submit Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Excepción Crítica en DB']);
}
