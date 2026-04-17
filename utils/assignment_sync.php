<?php
// utils/assignment_sync.php
// Motor Maestro para Sincronización de Matrices de Cursos basado en Privilegios Dinámicos
// Creado automáticamente para Phase 7 (HubEurosoft LMS)

if (!function_exists('syncAllCourseAssignments')) {
    function syncAllCourseAssignments($pdo, $userId = null) {
        $userFilter = '';
        $params = [];
        
        if ($userId) {
            $userFilter = " AND u.B = :uid ";
            $params[':uid'] = $userId;
        }

        try {
            // 1. OBTENER COMBINACIONES VALIDAS SEGÚN REGLAS DE NEGOCIO
            $sqlValid = "
                SELECT DISTINCT u.B as userId, c.A as courseId
                FROM _TrainingRoleToUser u
                JOIN _CourseToTrainingRole c ON c.B = u.A
                WHERE 1=1 $userFilter

                UNION

                SELECT DISTINCT u.B as userId, lpc.courseId
                FROM _TrainingRoleToUser u
                JOIN _LearningPathToTrainingRole p ON p.B = u.A
                JOIN LearningPathCourse lpc ON lpc.learningPathId = p.A
                WHERE 1=1 $userFilter
            ";
            
            $stmtV = $pdo->prepare($sqlValid);
            $stmtV->execute($params);
            $validPairs = $stmtV->fetchAll(PDO::FETCH_ASSOC);

            // Crear mapa de validos = ["userId_courseId" => 1]
            $validMap = [];
            foreach ($validPairs as $vp) {
                $validMap[$vp['userId'] . '_' . $vp['courseId']] = true;
            }

            // 2. OBTENER COMBINACIONES EXISTENTES ACTUALES EN LA BD
            $sqlCurrent = "SELECT id, userId, courseId FROM CourseProgress WHERE 1=1";
            if ($userId) {
                $sqlCurrent .= " AND userId = :uid";
                $stmtC = $pdo->prepare($sqlCurrent);
                $stmtC->execute([':uid' => $userId]);
            } else {
                $stmtC = $pdo->query($sqlCurrent);
            }
            $currentPairs = $stmtC->fetchAll(PDO::FETCH_ASSOC);

            $currentMap = [];
            $idsToDelete = [];
            $revokedPairs = [];

            // 3. IDENTIFICAR AQUELLOS QUE DEBEN SER REVOCADOS (Existen pero ya no son validos)
            foreach ($currentPairs as $cp) {
                $key = $cp['userId'] . '_' . $cp['courseId'];
                $currentMap[$key] = true;
                
                if (!isset($validMap[$key])) {
                    $idsToDelete[] = $cp['id'];
                    $revokedPairs[] = ['u' => $cp['userId'], 'c' => $cp['courseId']];
                }
            }

            $pdo->beginTransaction();

            // EJECUTAR REVOCACIONES DE FORMA SEGURA
            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $pdo->prepare("DELETE FROM CourseProgress WHERE id IN ($placeholders)")->execute($idsToDelete);
                
                // Reiniciar el avance granular exclusivo de estos cursos/usuarios revocados
                foreach ($revokedPairs as $pair) {
                    $u = $pair['u'];
                    $c = $pair['c'];
                    
                    try {
                        // Borrar LessonProgress
                        $pdo->prepare("DELETE lp FROM LessonProgress lp JOIN Lesson l ON lp.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = ? AND lp.userId = ?")->execute([$c, $u]);
                        // Borrar TopicProgress
                        $pdo->prepare("DELETE tp FROM TopicProgress tp JOIN Topic t ON tp.topicId = t.id JOIN Lesson l ON t.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = ? AND tp.userId = ?")->execute([$c, $u]);
                        // Borrar StudentAnswer
                        $pdo->prepare("DELETE sa FROM StudentAnswer sa JOIN Question q ON sa.questionId = q.id JOIN Quiz qz ON q.quizId = qz.id WHERE qz.courseId = ? AND sa.userId = ?")->execute([$c, $u]);
                    } catch (Exception $wEx) {
                        error_log("Error limpiando avance interno de curso revocado: " . $wEx->getMessage());
                    }
                }
            }

            // 4. IDENTIFICAR E INSERTAR LOS QUE FALTAN (Son válidos pero no existen en DB)
            if (!function_exists('generateCuidSync')) {
                function generateCuidSync() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
            }

            $stmtIns = $pdo->prepare("INSERT INTO CourseProgress (id, userId, courseId, isCompleted, quizPassed, quizAttempts, createdAt, updatedAt) VALUES (?, ?, ?, 0, 0, 0, NOW(), NOW())");
            
            foreach ($validPairs as $vp) {
                $key = $vp['userId'] . '_' . $vp['courseId'];
                if (!isset($currentMap[$key])) {
                    // Prevenir error Duplicate Entry por race conditions o por el CUID
                    try {
                        $stmtIns->execute([generateCuidSync(), $vp['userId'], $vp['courseId']]);
                    } catch (Exception $dupEx) { }
                }
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Error en sincronizador bidireccional PHP: " . $e->getMessage());
        }
    }
}
?>
