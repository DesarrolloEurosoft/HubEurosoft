<?php
session_start();
require 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Usuario no autenticado']);
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
$lessonId = $input['lessonId'] ?? null;
$courseId = $input['courseId'] ?? null;
$videoProgress = isset($input['videoProgress']) ? (float)$input['videoProgress'] : null;
$isCompleted = isset($input['isCompleted']) ? (int)$input['isCompleted'] : 0;

if (!$lessonId || !$courseId || $videoProgress === null) {
    echo json_encode(['success' => false, 'error' => 'Datos insuficientes recibidos']);
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

try {
    $pdo->beginTransaction();

    // 1. Guardar Avance o Crear Registro de Lección
    $stmtFind = $pdo->prepare("SELECT id, isCompleted, videoProgress FROM LessonProgress WHERE userId = ? AND lessonId = ?");
    $stmtFind->execute([$userId, $lessonId]);
    $existing = $stmtFind->fetch(PDO::FETCH_ASSOC);

    // Evitar que un isCompleted existente sea sobrescrito por accidente si mandan 0
    $finalCompleted = $isCompleted;
    if ($existing && $existing['isCompleted'] == 1) {
        $finalCompleted = 1; 
    }

    // El videoProgress máximo guardado
    $finalProgress = $videoProgress;
    if ($existing && $existing['videoProgress'] > $videoProgress) {
        // En teoría, a veces el heartbeat manda info menor si retrocedieron. Podemos dejar que rescriba el progreso actual real, o guardar el tope máximo. Para auto-save resumé usual, siempre guardamos la pocision real en el reproductor.
        // Pero guardaremos la máxima historia que ha visto para el antifraude, ufff... 
        // Mejor: almacenemos el tiempo real del reproductor (a menos que haya completado el video). 
        if ($finalCompleted == 1 && $videoProgress < $existing['videoProgress']) {
           // Si ya acabó, no importa.
        } else {
           // Guardamos el progres de tiempo real reportado por el timeupdate, a menos que el antifraude backend lo impida. Por el diseño front-end, confiaremos en lo que manda el evento real de timeupdate.
        }
    }

    if ($existing) {
        $stmtUpd = $pdo->prepare("UPDATE LessonProgress SET videoProgress = ?, isCompleted = ?, updatedAt = NOW() WHERE id = ?");
        $stmtUpd->execute([$finalProgress, $finalCompleted, $existing['id']]);
    } else {
        $newProgId = generateCuid();
        $stmtIns = $pdo->prepare("INSERT INTO LessonProgress (id, userId, lessonId, isCompleted, videoProgress, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmtIns->execute([$newProgId, $userId, $lessonId, $finalCompleted, $finalProgress]);
    }

    if ($finalCompleted == 1 && (!$existing || $existing['isCompleted'] == 0)) {
        $stmtLn = $pdo->prepare("SELECT title FROM Lesson WHERE id = ?");
        $stmtLn->execute([$lessonId]);
        $lnTitle = $stmtLn->fetchColumn();
        if ($lnTitle) {
            $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'LESSON_COMPLETED', 'Lección Completada', ?, NOW())")->execute([generateCuid(), $userId, "Has completado '$lnTitle'."]);
        }
    }

    // 2. Revisar e Impactar el Progreso del Curso si la lección recién se completó
    // Primero, garantizar que haya un registro general para el "En Progreso" visual del perfil.
    $stmtCourseP = $pdo->prepare("SELECT id, isCompleted FROM CourseProgress WHERE userId = ? AND courseId = ?");
    $stmtCourseP->execute([$userId, $courseId]);
    $cProg = $stmtCourseP->fetch(PDO::FETCH_ASSOC);

    if (!$cProg) {
        $newCpId = generateCuid();
        $stmtInsC = $pdo->prepare("INSERT INTO CourseProgress (id, userId, courseId, isCompleted, createdAt, updatedAt) VALUES (?, ?, ?, 0, NOW(), NOW())");
        $stmtInsC->execute([$newCpId, $userId, $courseId]);
        $cProg = ['id' => $newCpId, 'isCompleted' => 0];
    }

    if ($finalCompleted === 1 && (!$existing || $existing['isCompleted'] == 0)) {
        // Recalcular métricas maestras si rompió una frontera
        $stmtAll = $pdo->prepare("
            SELECT COUNT(l.id) as totalLessons
            FROM Lesson l 
            JOIN Module m ON l.moduleId = m.id 
            WHERE m.courseId = ?
        ");
        $stmtAll->execute([$courseId]);
        $totalLessons = $stmtAll->fetchColumn() ?: 0;
        
        $stmtDone = $pdo->prepare("
            SELECT COUNT(lp.id) as completedCount
            FROM LessonProgress lp
            JOIN Lesson l ON lp.lessonId = l.id
            JOIN Module m ON l.moduleId = m.id
            WHERE m.courseId = ? AND lp.userId = ? AND lp.isCompleted = 1
        ");
        $stmtDone->execute([$courseId, $userId]);
        $completedLessons = $stmtDone->fetchColumn() ?: 0;

        $newCoursePercent = $totalLessons > 0 ? floor(($completedLessons / $totalLessons) * 100) : 0;
        $courseCompleted = ($newCoursePercent >= 100) ? 1 : 0;

        if ($courseCompleted && $cProg['isCompleted'] == 0) {
            $stmtUpdC = $pdo->prepare("UPDATE CourseProgress SET isCompleted = 1, updatedAt = NOW() WHERE id = ?");
            $stmtUpdC->execute([$cProg['id']]);

            $stmtCn = $pdo->prepare("SELECT title FROM Course WHERE id = ?");
            $stmtCn->execute([$courseId]);
            $cnTitle = $stmtCn->fetchColumn();
            if ($cnTitle) {
                $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'COURSE_COMPLETED', 'Curso Completado', ?, NOW())")->execute([generateCuid(), $userId, "Felicidades, has concluido '$cnTitle'."]);
            }

            // <-- START GAMIFICATION POINTS -->
            try {
                // 5.1 COURSE_COMPLETED
                $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'COURSE_COMPLETED' AND isActive = 1")->fetch();
                if ($rule && $rule['points'] > 0) {
                    $pts = (int)$rule['points'];
                    $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                    $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'COURSE_COMPLETED', 'Curso Completado Satisfactoriamente', NOW())")->execute([generateCuid(), $userId, $pts]);
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
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND u.businessUnitId $targetBuSql AND cp.isCompleted = 1 AND cp.userId != ?");
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
                    $chkFirstC = $pdo->prepare("SELECT COUNT(cp.id) FROM CourseProgress cp JOIN User u ON cp.userId = u.id WHERE cp.courseId = ? AND u.companyId = ? AND cp.isCompleted = 1 AND cp.userId != ?");
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
                    $chkPath = $pdo->prepare("SELECT COUNT(lpc.courseId) FROM LearningPathCourse lpc WHERE lpc.learningPathId = ? AND lpc.courseId NOT IN (SELECT courseId FROM CourseProgress WHERE userId = ? AND isCompleted = 1)");
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
            // <-- END GAMIFICATION POINTS -->

            require_once 'utils/gamification_engine.php';
            evaluateUserAchievements($pdo, $userId);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'savedPercent' => $finalProgress]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Progress Save Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Excepción en base de datos']);
}
