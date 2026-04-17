<?php
function getStudentDashboardData(PDO $pdo, string $userId): array {
    $data = [];
    $stmt = $pdo->prepare("SELECT id, name, firstName, lastName, email, image, role, companyId FROM User WHERE id = ?");
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
    $stmt = $pdo->prepare("
        SELECT c.id as courseId, c.title, c.imageUrl, cp.isCompleted as courseCompleted,
               (SELECT MIN(`order`) FROM LearningPathCourse WHERE courseId = c.id) as pathOrder
        FROM CourseProgress cp 
        JOIN Course c ON c.id = cp.courseId 
        WHERE cp.userId = ? 
        ORDER BY pathOrder ASC, c.createdAt ASC
    ");
    $stmt->execute([$userId]); $courses = $stmt->fetchAll();
    $activeCourses = []; $completedCount = 0;
    foreach ($courses as $course) {
        $progress = calculateCourseProgress($pdo, $userId, $course["courseId"]);
        $activeCourses[] = ["id"=>$course["courseId"],"title"=>$course["title"],"imageUrl"=>$course["imageUrl"],"progress"=>$progress,"completed"=>(bool)$course["courseCompleted"]];
        if ($course["courseCompleted"]) $completedCount++;
    }
    $data["activeCourses"] = $activeCourses;
    $data["coursesCompletedCount"] = $completedCount;
    $data["coursesActiveCount"] = count($activeCourses) - $completedCount;
    $data["overallProgress"] = count($activeCourses) > 0 ? round(array_sum(array_column($activeCourses,"progress")) / count($activeCourses)) : 0;
    $companyId = $data["user"]["companyId"] ?? null;
    if ($companyId) {
        $stmt = $pdo->prepare("SELECT u.id,u.name,u.firstName,u.lastName,u.image,u.totalPoints FROM User u WHERE u.companyId=? AND u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 10");
        $stmt->execute([$companyId]);
    } else {
        $stmt = $pdo->prepare("SELECT u.id,u.name,u.firstName,u.lastName,u.image,u.totalPoints FROM User u WHERE u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 10");
        $stmt->execute();
    }
    $data["leaderboard"] = $stmt->fetchAll();
    $data["userRank"] = 0; $data["totalStudents"] = 0;
    if ($companyId) { $stmt=$pdo->prepare("SELECT COUNT(*) FROM User WHERE companyId=? AND role='STUDENT'"); $stmt->execute([$companyId]); $data["totalStudents"]=(int)$stmt->fetchColumn(); }
    foreach ($data["leaderboard"] as $i => $u) { if ($u["id"]===$userId) { $data["userRank"]=$i+1; break; } }
    try {
        $stmt=$pdo->prepare("SELECT uc.id,uc.issuedAt,c.name as certName FROM UserCertificate uc JOIN Certificate c ON c.id=uc.certificateId WHERE uc.userId=? ORDER BY uc.issuedAt DESC");
        $stmt->execute([$userId]); $data["certificates"]=$stmt->fetchAll();
    } catch (\Throwable $e) { $data["certificates"]=[]; }
    try {
        $stmt=$pdo->prepare("SELECT ua.id,ua.unlockedAt,a.title as achName,a.description,a.icon FROM UserAchievement ua JOIN Achievement a ON a.id=ua.achievementId WHERE ua.userId=? ORDER BY ua.unlockedAt DESC");
        $stmt->execute([$userId]); $data["achievements"]=$stmt->fetchAll(); $data["completedAchievements"]=count($data["achievements"]);
    } catch (\Throwable $e) { $data["achievements"]=[]; $data["completedAchievements"]=0; }
    $data["dashboardAchievements"] = [
        ["icon"=>"bx bx-bolt-circle","title"=>"Imparable","subtitle"=>"Completa ".max(6,$completedCount)." cursos","completed"=>$completedCount>=6,"color"=>"#f59e0b"],
        ["icon"=>"bx bx-book-open","title"=>"Primeros Pasos","subtitle"=>"Completa tu primer curso","completed"=>$completedCount>=1,"color"=>"#3b82f6"],
        ["icon"=>"bx bx-hot","title"=>"En Racha","subtitle"=>$data["streak"]." dias seguidos","completed"=>$data["streak"]>=2,"color"=>"#ef4444"],
        ["icon"=>"bx bx-trophy","title"=>"Pionero","subtitle"=>"Se el primero del ranking","completed"=>$data["userRank"]===1,"color"=>"#8b5cf6"],
    ];
    $data["recentActivities"] = getRecentActivities($pdo, $userId);
    $data["learningPath"] = getLearningPath($pdo, $userId, $companyId);
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
function getLearningPath(PDO $pdo, string $userId, ?string $companyId): array {
    $empty=["name"=>"","courses"=>[],"progress"=>0,"completedCount"=>0,"totalCount"=>0];
    
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
    
    $stmt=$pdo->prepare("SELECT courseId, isCompleted, updatedAt FROM CourseProgress WHERE userId = ?");
    $stmt->execute([$userId]);
    $userProgressMap = [];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userProgressMap[$row['courseId']] = $row;
    }
    
    $stmt=$pdo->prepare("SELECT lpc.learningPathId, lpc.courseId, lpc.`order` as sortOrder, c.title, c.imageUrl FROM LearningPathCourse lpc JOIN Course c ON c.id=lpc.courseId");
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
        
        foreach ($pathCourses as $pc) {
            $courseId = $pc['courseId'];
            $completed = false;
            
            if (isset($userProgressMap[$courseId])) {
                $up = $userProgressMap[$courseId];
                $completed = (bool)$up['isCompleted'];
                if ($up['updatedAt'] > $maxActivity) {
                    $maxActivity = $up['updatedAt'];
                }
            }
            if ($completed) $done++;
            
            // Requirimiento: obtener el porcentaje granular del curso
            $coursePct = calculateCourseProgress($pdo, $userId, $courseId);
            $accumulatedPercentage += $coursePct;
            
            $coursesFormatted[]=["id"=>$courseId,"title"=>$pc['title'],"imageUrl"=>$pc['imageUrl'],"subtitle"=>"Curso ".$pc['sortOrder'],"completed"=>$completed,"progress"=>$coursePct];
        }
        
        // Obtener porcentaje de la ruta calculando sobre la media de los cursos
        $progress = $total > 0 ? round($accumulatedPercentage / $total) : 0;
        
        $evaluatedPaths[] = [
            'name' => $path['name'], 'courses' => $coursesFormatted, 'progress' => $progress,
            'completedCount' => $done, 'totalCount' => $total, 'lastActivity' => $maxActivity, 'createdAt' => $path['createdAt']
        ];
    }
    
    if (empty($evaluatedPaths)) return $empty;
    
    usort($evaluatedPaths, function($a, $b) {
        if ($a['progress'] !== $b['progress']) return $b['progress'] <=> $a['progress'];
        if ($a['lastActivity'] !== $b['lastActivity']) return $b['lastActivity'] <=> $a['lastActivity'];
        return $b['createdAt'] <=> $a['createdAt'];
    });
    
    $best = $evaluatedPaths[0];
    return ["name"=>$best['name'], "courses"=>$best['courses'], "progress"=>$best['progress'], "completedCount"=>$best['completedCount'], "totalCount"=>$best['totalCount']];
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