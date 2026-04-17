<?php
function getStudentDashboardData(PDO $pdo, string $userId): array {
    $data = [];
    $stmt = $pdo->prepare("SELECT id, name, firstName, lastName, email, image, role, companyId, businessUnitId FROM User WHERE id = ?");
    $stmt->execute([$userId]); $data["user"] = $stmt->fetch() ?: [];
    
    $stmt = $pdo->prepare("SELECT tr.name FROM TrainingRole tr JOIN _TrainingRoleToUser tru ON tru.A = tr.id WHERE tru.B = ?");
    $stmt->execute([$userId]);
    $data["trainingRoles"] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    
    $stmt = $pdo->prepare("SELECT totalPoints FROM User WHERE id = ?");
    $stmt->execute([$userId]); $data["totalPoints"] = (int) $stmt->fetchColumn();
    $data["level"] = floor($data["totalPoints"] / 1000) + 1;
    $data["nextLevelXP"] = $data["level"] * 1000;
    $data["currentLevelPoints"] = $data["totalPoints"] % 1000;
    $data["streak"] = calculateStreak($pdo, $userId);
    $allPathsObj = getAllEvaluatedLearningPaths($pdo, $userId, $data["user"]["companyId"] ?? null);
    
    if (empty($allPathsObj)) {
        $data["learningPath"] = ["name"=>"","courses"=>[],"progress"=>0,"completedCount"=>0,"totalCount"=>0];
    } else {
        $inProgressPaths = array_filter($allPathsObj, function($p) { return $p['progress'] < 100 && $p['completedCount'] < $p['totalCount']; });
        $candidates = !empty($inProgressPaths) ? $inProgressPaths : $allPathsObj;
        
        usort($candidates, function($a, $b) {
            if ($a['lastActivity'] !== $b['lastActivity']) return $b['lastActivity'] <=> $a['lastActivity'];
            if ($a['progress'] !== $b['progress']) return $b['progress'] <=> $a['progress'];
            return $b['createdAt'] <=> $a['createdAt'];
        });
        $best = $candidates[0];
        $data["learningPath"] = ["name"=>$best['name'], "courses"=>$best['courses'], "progress"=>$best['progress'], "completedCount"=>$best['completedCount'], "totalCount"=>$best['totalCount']];
    }
    
    $globalCoursesAssoc = [];
    $statusWeight = [ 'completed' => 5, 'in-progress' => 3, 'available' => 2, 'locked' => 1 ];
    $completedCount = 0;
    $allProgresses = [];
    
    foreach ($allPathsObj as $path) {
        foreach ($path['courses'] as $pc) {
            $cId = $pc['id'];
            $st = $pc['completed'] ? 'completed' : ($pc['isLocked'] ? 'locked' : ($pc['progress'] > 0 ? 'in-progress' : 'available'));
            
            if (!isset($globalCoursesAssoc[$cId])) {
                $pc['statusName'] = $st;
                $globalCoursesAssoc[$cId] = $pc;
            } else {
                if ($statusWeight[$st] > $statusWeight[$globalCoursesAssoc[$cId]['statusName']]) {
                    $pc['statusName'] = $st;
                    $globalCoursesAssoc[$cId] = $pc;
                }
            }
        }
    }
    
    $activeCourses = [];
    foreach ($globalCoursesAssoc as $cId => $pc) {
        $allProgresses[] = $pc['progress'];
        if ($pc['completed']) {
            $completedCount++;
        } else if ($pc['statusName'] !== 'locked') {
            $activeCourses[] = [
                "id" => $cId,
                "title" => $pc['title'],
                "imageUrl" => $pc['imageUrl'],
                "progress" => $pc['progress'],
                "completed" => false
            ];
        }
    }
    
    $data["activeCourses"] = $activeCourses;
    $data["coursesCompletedCount"] = $completedCount;
    $data["coursesActiveCount"] = count($activeCourses);
    $data["overallProgress"] = count($allProgresses) > 0 ? round(array_sum($allProgresses) / count($allProgresses)) : 0;
    $companyId = $data["user"]["companyId"] ?? null;
    $userBuId = $data["user"]["businessUnitId"] ?? null;
    if ($companyId && $userBuId) {
        // Filtrar por Business Unit del usuario
        $stmt = $pdo->prepare("SELECT u.id,u.name,u.firstName,u.lastName,u.image,u.totalPoints FROM User u WHERE u.companyId=? AND u.businessUnitId=? AND u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 10");
        $stmt->execute([$companyId, $userBuId]);
    } elseif ($companyId) {
        $stmt = $pdo->prepare("SELECT u.id,u.name,u.firstName,u.lastName,u.image,u.totalPoints FROM User u WHERE u.companyId=? AND u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 10");
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT u.id,u.name,u.firstName,u.lastName,u.image,u.totalPoints FROM User u WHERE u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 10");
        $stmt->execute();
    }
    $data["leaderboard"] = $stmt->fetchAll();
    $data["userRank"] = 0; $data["totalStudents"] = 0;
    if ($companyId && $userBuId) { $stmt=$pdo->prepare("SELECT COUNT(*) FROM User WHERE companyId=? AND businessUnitId=? AND role='STUDENT'"); $stmt->execute([$companyId, $userBuId]); $data["totalStudents"]=(int)$stmt->fetchColumn(); }
    elseif ($companyId) { $stmt=$pdo->prepare("SELECT COUNT(*) FROM User WHERE companyId=? AND role='STUDENT'"); $stmt->execute([$companyId]); $data["totalStudents"]=(int)$stmt->fetchColumn(); }
    foreach ($data["leaderboard"] as $i => $u) { if ($u["id"]===$userId) { $data["userRank"]=$i+1; break; } }
    try {
        $stmt=$pdo->prepare("SELECT uc.id,uc.issuedAt,c.name as certName FROM UserCertificate uc JOIN Certificate c ON c.id=uc.certificateId WHERE uc.userId=? ORDER BY uc.issuedAt DESC");
        $stmt->execute([$userId]); $data["certificates"]=$stmt->fetchAll();
    } catch (\Throwable $e) { $data["certificates"]=[]; }
    try {
        $stmt=$pdo->prepare("SELECT ua.id,ua.unlockedAt,a.title as achName,a.description,a.icon FROM UserAchievement ua JOIN Achievement a ON a.id=ua.achievementId WHERE ua.userId=? ORDER BY ua.unlockedAt DESC");
        $stmt->execute([$userId]); $data["achievements"]=$stmt->fetchAll(); $data["completedAchievements"]=count($data["achievements"]);
    } catch (\Throwable $e) { $data["achievements"]=[]; $data["completedAchievements"]=0; }
    $stmtRules = $pdo->query("SELECT actionType, points FROM GamificationRule WHERE isActive = 1 ORDER BY points DESC LIMIT 15");
    $dbRules = $stmtRules->fetchAll(PDO::FETCH_ASSOC);

    // Contar cuántas veces el usuario ha ganado puntos por cada regla
    $stmtUserRules = $pdo->prepare("SELECT actionType, COUNT(*) as count FROM UserPoints WHERE userId = ? GROUP BY actionType");
    $stmtUserRules->execute([$userId]);
    $userRuleCounts = [];
    foreach($stmtUserRules->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userRuleCounts[$row['actionType']] = (int)$row['count'];
    }
    
    $ruleMap = [
        'COURSE_COMPLETED' => ['title'=>'Aprobar Curso', 'icon'=>'bx bxs-graduation', 'color'=>'#8b5cf6'],
        'LEARNING_PATH_COMPLETED' => ['title'=>'Ruta Completada', 'icon'=>'bx bxs-map-alt', 'color'=>'#eab308'],
        'PATH_COMPLETED' => ['title'=>'Ruta Completada', 'icon'=>'bx bxs-map-alt', 'color'=>'#eab308'],
        'FIRST_IN_BU_COURSE' => ['title'=>'1º Unidad en Curso', 'icon'=>'bx bxs-medal', 'color'=>'#3b82f6'],
        'FIRST_IN_BU_PATH' => ['title'=>'1º Unidad en Ruta', 'icon'=>'bx bxs-trophy', 'color'=>'#ef4444'],
        'FIRST_IN_COMPANY_COURSE' => ['title'=>'1º Empresa en Curso', 'icon'=>'bx bxs-medal', 'color'=>'#3b82f6'],
        'FIRST_IN_COMPANY_PATH' => ['title'=>'1º Empresa en Ruta', 'icon'=>'bx bxs-trophy', 'color'=>'#ef4444'],
        'CERTIFICATE_EARNED' => ['title'=>'Certificado Obtenido', 'icon'=>'bx bxs-certification', 'color'=>'#f59e0b'],
        'TOPIC_COMPLETED' => ['title'=>'Aprobar Tema', 'icon'=>'bx bxs-check-circle', 'color'=>'#10b981'],
        'LESSON_COMPLETED' => ['title'=>'Completar Lección', 'icon'=>'bx bxs-book-reader', 'color'=>'#14b8a6'],
        'QUIZ_PASSED' => ['title'=>'Aprobar Quiz', 'icon'=>'bx bx-list-check', 'color'=>'#6366f1'],
        'FORUM_POST' => ['title'=>'Publicar en Foro', 'icon'=>'bx bxs-message-square-detail', 'color'=>'#8b5cf6'],
        'FORUM_REPLY' => ['title'=>'Responder en Foro', 'icon'=>'bx bxs-message-dots', 'color'=>'#ec4899'],
        'FORUM_TOPIC_LIKE' => ['title'=>'Recibir Me Gusta', 'icon'=>'bx bxs-like', 'color'=>'#f43f5e'],
        'FORUM_REPLY_LIKE' => ['title'=>'Respuesta Útil', 'icon'=>'bx bxs-heart', 'color'=>'#e11d48']
    ];

    $data["dashboardAchievements"] = [];
    foreach ($dbRules as $r) {
        $type = $r['actionType'];
        $pts = $r['points'];
        if ($pts <= 0) continue;
        
        $timesObtained = $userRuleCounts[$type] ?? 0;
        
        $info = $ruleMap[$type] ?? ['title' => ucwords(strtolower(str_replace('_', ' ', $type))), 'icon' => 'bx bxs-star', 'color' => '#10b981'];
        $data["dashboardAchievements"][] = [
            "icon" => $info['icon'],
            "title" => $info['title'],
            "subtitle" => "Ganas +{$pts} XP",
            // Si ya lo obtuvo al menos una vez, lo encendemos (completed=true), si no, gris
            "completed" => ($timesObtained > 0), 
            "timesObtained" => $timesObtained,
            "color" => $info['color'],
            "isRule" => true
        ];
    }
    $data["recentActivities"] = getRecentActivities($pdo, $userId);
    $data["weeklyProgress"] = getWeeklyProgress($pdo, $userId);
    return $data;
}
function calculateStreak(PDO $pdo, string $userId): int {
    try {
        $stmt=$pdo->prepare("SELECT DISTINCT DATE(createdAt) as loginDate FROM LoginLog WHERE userId=? ORDER BY loginDate DESC LIMIT 365");
        $stmt->execute([$userId]); $dates=$stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) { return 0; }
    if (empty($dates)) return 0;
    $streak=0; $today=new DateTime("today");
    foreach ($dates as $d) { $date=new DateTime($d); $expected=(clone $today)->modify("-{$streak} days"); if ($date->format("Y-m-d")===$expected->format("Y-m-d")) $streak++; else break; }
    return $streak;
}
function calculateCourseProgress(PDO $pdo, string $userId, string $courseId): int {
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT l.id) FROM Lesson l JOIN Module m ON m.id=l.moduleId WHERE m.courseId=?");
    $stmt->execute([$courseId]); $total=(int)$stmt->fetchColumn();
    if ($total===0) return 0;
    $stmt=$pdo->prepare("SELECT COUNT(DISTINCT lp.lessonId) FROM LessonProgress lp JOIN Lesson l ON l.id=lp.lessonId JOIN Module m ON m.id=l.moduleId WHERE lp.userId=? AND m.courseId=? AND lp.isCompleted=1");
    $stmt->execute([$userId,$courseId]); $done=(int)$stmt->fetchColumn();
    return round(($done/$total)*100);
}
function getRecentActivities(PDO $pdo, string $userId): array {
    $activities=[];
    $stmt=$pdo->prepare("SELECT tp.updatedAt as date,t.title as topicTitle,l.title as lessonTitle FROM TopicProgress tp JOIN Topic t ON t.id=tp.topicId JOIN Lesson l ON l.id=t.lessonId WHERE tp.userId=? AND tp.isCompleted=1 ORDER BY tp.updatedAt DESC LIMIT 3");
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) { $activities[]=["type"=>"completion","title"=>"Completaste el modulo","desc"=>$row["lessonTitle"]?:$row["topicTitle"],"time"=>timeAgo($row["date"]),"date"=>$row["date"]]; }
    try { $stmt=$pdo->prepare("SELECT ua.unlockedAt as date,a.title as name FROM UserAchievement ua JOIN Achievement a ON a.id=ua.achievementId WHERE ua.userId=? ORDER BY ua.unlockedAt DESC LIMIT 2"); $stmt->execute([$userId]); foreach ($stmt->fetchAll() as $row) { $activities[]=["type"=>"achievement","title"=>"Desbloqueaste logro","desc"=>$row["name"],"time"=>timeAgo($row["date"]),"date"=>$row["date"]]; } } catch (\Throwable $e) {}
    try { $stmt=$pdo->prepare("SELECT uc.issuedAt as date,c.name FROM UserCertificate uc JOIN Certificate c ON c.id=uc.certificateId WHERE uc.userId=? ORDER BY uc.issuedAt DESC LIMIT 2"); $stmt->execute([$userId]); foreach ($stmt->fetchAll() as $row) { $activities[]=["type"=>"certificate","title"=>"Obtuviste certificado","desc"=>$row["name"],"time"=>timeAgo($row["date"]),"date"=>$row["date"]]; } } catch (\Throwable $e) {}
    usort($activities, function($a,$b){ return strtotime($b["date"])-strtotime($a["date"]); });
    return array_slice($activities,0,5);
}
function getAllEvaluatedLearningPaths(PDO $pdo, string $userId, ?string $companyId): array {
        $empty=[];
        
        try { 
            $stmt=$pdo->prepare("
                SELECT DISTINCT lp.id, lp.name, lp.createdAt 
                FROM LearningPath lp
                JOIN _LearningPathToTrainingRole lptr ON lptr.A = lp.id
                JOIN _TrainingRoleToUser trtu ON trtu.A = lptr.B
                WHERE trtu.B = ?
            "); 
            $stmt->execute([$userId]); 
            $paths=$stmt->fetchAll(PDO::FETCH_ASSOC); 
        } catch (\Throwable $e) { 
            return $empty; 
        }
        if (!$paths) return $empty;
        
        $stmt=$pdo->prepare("SELECT courseId, isCompleted, quizPassed, updatedAt FROM CourseProgress WHERE userId = ?");
        $stmt->execute([$userId]);
        $userProgressMap = [];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userProgressMap[$row['courseId']] = $row;
        }
        
        $stmt=$pdo->prepare("SELECT lpc.learningPathId, lpc.courseId, lpc.`order` as sortOrder, c.title, c.imageUrl, (SELECT id FROM Quiz WHERE courseId = c.id LIMIT 1) as quizId FROM LearningPathCourse lpc JOIN Course c ON c.id=lpc.courseId");
        $stmt->execute();
        $lpcMap = [];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lpcMap[$row['learningPathId']][] = $row;
        }
        
        $evaluatedPaths = [];
        
        foreach ($paths as $path) {
            $pathId = $path['id'];
            if (!isset($lpcMap[$pathId])) continue; // Skip empty paths
            
            $pathCourses = $lpcMap[$pathId];
            usort($pathCourses, function($a, $b) { return $a['sortOrder'] <=> $b['sortOrder']; });
            
            $total = count($pathCourses);
            $done = 0;
            $accumulatedPercentage = 0;
            $maxActivity = '0000-00-00 00:00:00';
            $coursesFormatted = [];
            
            $previousCompleted = true; // Secuencia independiente por Ruta

            foreach ($pathCourses as $pc) {
                $courseId = $pc['courseId'];
                $completed = false;
                
                if (isset($userProgressMap[$courseId])) {
                    $up = $userProgressMap[$courseId];
                    if (!empty($pc['quizId']) && empty($up['quizPassed'])) {
                        $completed = false; // Tiene examen pero no lo ha pasado -> no está completado
                    } else {
                        $completed = (bool)$up['isCompleted'];
                    }
                    if ($up['updatedAt'] > $maxActivity) {
                        $maxActivity = $up['updatedAt'];
                    }
                }
                if ($completed) $done++;
                
                // Requirimiento: obtener el porcentaje granular del curso
                $coursePct = calculateCourseProgress($pdo, $userId, $courseId);
                $accumulatedPercentage += $coursePct;
                
                $isLocked = false;
                if ($completed) {
                   $isLocked = false;
                } else {
                   if ($previousCompleted) {
                       $isLocked = false;
                       $previousCompleted = false;
                   } else {
                       $isLocked = true;
                   }
                }
                
                $coursesFormatted[]=["id"=>$courseId,"title"=>$pc['title'],"imageUrl"=>$pc['imageUrl'],"subtitle"=>"Curso ".$pc['sortOrder'],"completed"=>$completed,"progress"=>$coursePct,"isLocked"=>$isLocked];
            }
            
            // Obtener porcentaje de la ruta calculando sobre la media de los cursos
            $progress = $total > 0 ? round($accumulatedPercentage / $total) : 0;
            
            $evaluatedPaths[] = [
                'name' => $path['name'], 'courses' => $coursesFormatted, 'progress' => $progress,
                'completedCount' => $done, 'totalCount' => $total, 'lastActivity' => $maxActivity, 'createdAt' => $path['createdAt']
            ];
        }
        
        return $evaluatedPaths;
    }
function timeAgo(string $datetime): string {
    $diff=(new DateTime())->diff(new DateTime($datetime));
    if ($diff->y>=1) return "Hace ".$diff->y." anno".($diff->y>1?"s":"");
    if ($diff->m>=1) return "Hace ".$diff->m." mes".($diff->m>1?"es":"");
    if ($diff->d>=1) return "Hace ".$diff->d." dia".($diff->d>1?"s":"");
    if ($diff->h>=1) return "Hace ".$diff->h." hora".($diff->h>1?"s":"");
    if ($diff->i>=1) return "Hace ".$diff->i." min";
    return "Ahora";
}

function getWeeklyProgress(PDO $pdo, string $userId): array {
    $now = new DateTime();
    $dayOfWeek = (int)$now->format('N'); // 1 (Mon) - 7 (Sun)
    
    $startOfThisWeek = (clone $now)->modify('-' . ($dayOfWeek - 1) . ' days')->setTime(0, 0, 0);
    $startOfLastWeek = (clone $startOfThisWeek)->modify('-7 days');
    
    // Day indexed array (1 to 7) for this week
    $dailyPoints = array_fill(1, 7, 0);
    
    $stmt = $pdo->prepare("SELECT points, createdAt FROM UserPoints WHERE userId = ? AND createdAt >= ?");
    $stmt->execute([$userId, $startOfLastWeek->format('Y-m-d H:i:s')]);
    
    $thisWeekTotal = 0;
    $lastWeekTotal = 0;
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $date = new DateTime($row['createdAt']);
        $points = (int)$row['points'];
        
        if ($date >= $startOfThisWeek) {
            $thisWeekTotal += $points;
            $dayIdx = (int)$date->format('N');
            $dailyPoints[$dayIdx] += $points;
        } else {
            $lastWeekTotal += $points;
        }
    }
    
    $maxDaily = max($dailyPoints);
    $heights = [];
    foreach ($dailyPoints as $dayIdx => $pts) {
        $heights[] = $maxDaily > 0 ? round(($pts / $maxDaily) * 100) : 0; // percentage height
    }
    
    $changePct = 0;
    $trendStr = "Igual que la sem. pasada";
    $trendClass = "bx-minus";
    $trendColor = "#9ca3af";
    if ($lastWeekTotal > 0) {
        $changePct = round((($thisWeekTotal - $lastWeekTotal) / $lastWeekTotal) * 100);
        if ($changePct > 0) {
            $trendStr = "$changePct% más que la sem. pasada";
            $trendClass = "bx-trending-up";
            $trendColor = "#10b981";
        } elseif ($changePct < 0) {
            $trendStr = abs($changePct)."% menos que la sem. pasada";
            $trendClass = "bx-trending-down";
            $trendColor = "#ef4444";
        }
    } else if ($thisWeekTotal > 0) {
        $trendStr = "¡Ganaste más XT que la sem. pasada!";
        $trendClass = "bx-trending-up";
        $trendColor = "#10b981";
    }
    
    return [
        'thisWeekTotal' => $thisWeekTotal,
        'changeText' => $trendStr,
        'trendClass' => $trendClass,
        'trendColor' => $trendColor,
        'heights' => array_values($heights),
        'rawPoints' => array_values($dailyPoints)
    ];
}
?>