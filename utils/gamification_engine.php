<?php
// Motor de Gamificación en Tiempo Real
// Se debe incluir y llamar cada vez que el usuario realice una acción clave (Login, Terminar Curso, etc.)

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

function evaluateUserAchievements($pdo, $userId) {
    try {
        // 1. Obtener datos clave del usuario
        $stmtU = $pdo->prepare("SELECT streakCount, totalPoints, image, bannerUrl, companyId, businessUnitId FROM User WHERE id = ?");
        $stmtU->execute([$userId]);
        $u = $stmtU->fetch(PDO::FETCH_ASSOC);

        if (!$u) return; // Si el usuario no existe, abortar

        // 2. Contar Cursos Completados
        $stmtC = $pdo->prepare("SELECT COUNT(*) FROM CourseProgress WHERE userId = ? AND isCompleted = 1");
        $stmtC->execute([$userId]);
        $coursesCompleted = (int)$stmtC->fetchColumn();

        // 3. Obtener todas las medallas activas en el sistema
        $stmtA = $pdo->query("SELECT * FROM Achievement WHERE isActive = 1");
        $achievements = $stmtA->fetchAll(PDO::FETCH_ASSOC);

        // 4. Evaluar cada regla
        foreach ($achievements as $ach) {
            $type = $ach['targetAction'];
            $thresh = (int)$ach['threshold'];
            $awarded = false;
            
            if ($type === 'COURSE_COMPLETED') {
                if ($coursesCompleted >= $thresh) $awarded = true;
            } 
            elseif ($type === 'DAILY_LOGIN') {
                if ((int)$u['streakCount'] >= $thresh) $awarded = true;
            } 
            elseif ($type === 'PROFILE_CUSTOMIZATION') {
                if (!empty($u['image']) || !empty($u['bannerUrl'])) $awarded = true;
            }
            elseif ($type === 'RANKING_FIRST_PLACE') {
                if (!isset($cachedRankings)) $cachedRankings = [];
                $cacheKey = $u['companyId'] . '_' . ($u['businessUnitId'] ?? 'null');
                
                if (!isset($cachedRankings[$cacheKey])) {
                    $buFilter = $u['businessUnitId'] ? "= ?" : "IS NULL";
                    $paramsLocal = $u['businessUnitId'] ? [$u['companyId'], $u['businessUnitId']] : [$u['companyId']];
                    $stmtUsers = $pdo->prepare("SELECT id FROM User WHERE role = 'STUDENT' AND companyId = ? AND businessUnitId $buFilter ORDER BY totalPoints DESC LIMIT 1");
                    $stmtUsers->execute($paramsLocal);
                    $topUser = $stmtUsers->fetchColumn();
                    $cachedRankings[$cacheKey] = $topUser;
                }
                if ($cachedRankings[$cacheKey] === $userId) $awarded = true;
            }

            // Si cumple los requisitos para la medalla
            if ($awarded) {
                // Checar si ya la tiene desbloqueada
                $check = $pdo->prepare("SELECT COUNT(*) FROM UserAchievement WHERE userId = ? AND achievementId = ?");
                $check->execute([$userId, $ach['id']]);
                
                if ($check->fetchColumn() == 0) {
                    // Dar Medalla
                    $ins = $pdo->prepare("INSERT INTO UserAchievement (id, userId, achievementId, unlockedAt) VALUES (?, ?, ?, NOW())");
                    $ins->execute([generateCuid(), $userId, $ach['id']]);
                    
                    // Notificar obtención de medalla
                    $notifMsg = "¡Felicidades! Has desbloqueado la medalla especial: " . $ach['title'];
                    $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, createdAt) VALUES (?, ?, 'MEDAL_UNLOCKED', 'Logro Desbloqueado', ?, NOW())")->execute([generateCuid(), $userId, $notifMsg]);

                    // Otorgar Bonus de Puntos
                    $pts = (int)$ach['pointsBonus'];
                    if ($pts > 0) {
                        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $userId]);
                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'MEDAL_BONUS', ?, NOW())")->execute([generateCuid(), $userId, $pts, "Desbloqueo de Medalla: " . $ach['title']]);
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Fallar silenciosamente para no romper el flujo principal (Login/Evaluación)
        error_log("Gamification Engine Error: " . $e->getMessage());
    }
}
?>
