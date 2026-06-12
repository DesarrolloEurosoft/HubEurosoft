<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// === CORTAFUEGOS (RBAC) ===
if ($userRole === 'COMPANY_LEADER' || $userRole === 'BUSINESS_UNIT_LEADER') {
    // 1. Forzar nivel de la compañía
    $_GET['company_id'] = $_SESSION['user_company'] ?? 'null';
    
    // 2. Si es Líder de Unidad, Forzar su unidad
    if ($userRole === 'BUSINESS_UNIT_LEADER') {
        $_GET['bu_id'] = $_SESSION['user_bu'] ?? 'null';
    }
    
    // 3. Proteger la vista de Usuarios Individuales (Expedientes ajenos)
    if (!empty($_GET['user_id'])) {
        $chkUser = $pdo->prepare("SELECT companyId, businessUnitId FROM User WHERE id = ?");
        $chkUser->execute([$_GET['user_id']]);
        $uScope = $chkUser->fetch(PDO::FETCH_ASSOC);
        
        if (!$uScope || 
           ($userRole === 'COMPANY_LEADER' && $uScope['companyId'] !== $_SESSION['user_company']) || 
           ($userRole === 'BUSINESS_UNIT_LEADER' && $uScope['businessUnitId'] !== $_SESSION['user_bu'])) {
            // Cancelar acceso al usuario si no le pertenece a su división
            unset($_GET['user_id']);
        }
    }
}

$qCompanyId = $_GET['company_id'] ?? null;
$qBuId = $_GET['bu_id'] ?? null;
$qUserId = $_GET['user_id'] ?? null;

// ==========================================
// CONDICIONES DE USUARIOS "METRICABLES"
// ==========================================
$metricFilterUser = "(role = 'STUDENT' OR role = 'BUSINESS_UNIT_LEADER' OR (role = 'COMPANY_LEADER' AND (businessUnitId IS NOT NULL OR NOT EXISTS(SELECT 1 FROM BusinessUnit WHERE BusinessUnit.companyId = User.companyId))))";
$metricFilterU = "(u.role = 'STUDENT' OR u.role = 'BUSINESS_UNIT_LEADER' OR (u.role = 'COMPANY_LEADER' AND (u.businessUnitId IS NOT NULL OR NOT EXISTS(SELECT 1 FROM BusinessUnit WHERE BusinessUnit.companyId = u.companyId))))";

// ==========================================
// HELPER: CÁLCULOS DE PROGRESO MATEMÁTICO ABSOLUTO
// ==========================================
function getAbsoluteProgressPct($pdo, $type, $id) {
    global $metricFilterU;
    
    if ($type === 'COMPANY') {
        $stmt = $pdo->query("SELECT id FROM BusinessUnit WHERE companyId = '$id' AND isActive = 1");
        $bus = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($bus) > 0) {
            $sum = 0;
            foreach ($bus as $buId) {
                $sum += getAbsoluteProgressPct($pdo, 'BU', $buId);
            }
            return round($sum / count($bus), 1);
        } else {
            return getAbsoluteProgressPct($pdo, 'COMPANY_DIRECT', $id);
        }
    } else if ($type === 'BU') {
        return getAbsoluteProgressPct($pdo, 'STUDENT_LIST', "u.businessUnitId = '$id'");
    } else if ($type === 'BU_GLOBAL_CORP') {
        return getAbsoluteProgressPct($pdo, 'STUDENT_LIST', "u.companyId = '$id' AND u.businessUnitId IS NULL");
    } else if ($type === 'COMPANY_DIRECT') {
        return getAbsoluteProgressPct($pdo, 'STUDENT_LIST', "u.companyId = '$id'");
    } else if ($type === 'STUDENT_LIST') {
        $whereClause = $id; 
        $stmtBUPct = $pdo->query("
            SELECT u.id,
                   (SELECT COUNT(DISTINCT lpc.courseId)
                    FROM _TrainingRoleToUser tru
                    JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                    JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                    WHERE tru.B = u.id) as assigned,
                   (SELECT COUNT(DISTINCT cp.courseId)
                    FROM CourseProgress cp
                    WHERE cp.userId = u.id AND cp.isCompleted = 1) as completed
            FROM User u
            WHERE $whereClause AND $metricFilterU
        ");
        $studentsInList = $stmtBUPct->fetchAll(PDO::FETCH_ASSOC);

        $totalPct = 0;
        $validStudentsCount = 0;
        foreach ($studentsInList as $s) {
            if ($s['assigned'] > 0) {
                $validStudentsCount++;
                $c = min((int)$s['completed'], (int)$s['assigned']);
                $totalPct += ($c / (int)$s['assigned']) * 100;
            }
        }
        return $validStudentsCount > 0 ? round($totalPct / $validStudentsCount, 1) : 0;
    }
    return 0;
}

// === KPI: Tasa de Finalización ===
function getFinalizationRate($pdo, $whereFilter, $metricFilter) {
    $total     = (int)$pdo->query("SELECT COUNT(*) FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE ($whereFilter) AND ($metricFilter))")->fetchColumn();
    $completed = (int)$pdo->query("SELECT COUNT(*) FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE ($whereFilter) AND ($metricFilter)) AND isCompleted = 1")->fetchColumn();
    return ['pct' => $total > 0 ? round(($completed / $total) * 100, 1) : 0, 'done' => $completed, 'total' => $total];
}

// === KPI: Índice de Engagement ===
function getEngagementStats($pdo, $whereFilter, $metricFilter) {
    $active   = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $atRisk   = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt < DATE_SUB(NOW(), INTERVAL 7 DAY) AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchColumn();
    $inactive = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND (lastLoginAt IS NULL OR lastLoginAt < DATE_SUB(NOW(), INTERVAL 14 DAY))")->fetchColumn();
    return ['active' => $active, 'atRisk' => $atRisk, 'inactive' => $inactive];
}

// === KPI: Roles Críticos de Capacitación ===
function getCriticalRolesData($pdo, $plainFilter, $aliasFilter) {
    global $metricFilterUser, $metricFilterU;
    $roles = ['Modelador', 'Auditor Líder', 'Administrador documental', 'Auditor Interno', 'Coordinador de Auditorias'];
    $results = [];
    foreach ($roles as $rName) {
        $esc = str_replace("'", "\\'", $rName);
        $cnt = (int)$pdo->query("
            SELECT COUNT(DISTINCT u.id) FROM User u
            JOIN _TrainingRoleToUser tru ON tru.B = u.id
            JOIN TrainingRole tr ON tr.id = tru.A
            WHERE tr.name = '$esc' AND ($aliasFilter) AND ($metricFilterU)
        ")->fetchColumn();
        if ($cnt === 0) continue;
        $pct = getAbsoluteProgressPct($pdo, 'STUDENT_LIST',
            "u.id IN (SELECT t2.B FROM _TrainingRoleToUser t2 JOIN TrainingRole r2 ON r2.id = t2.A WHERE r2.name = '$esc') AND ($aliasFilter) AND $metricFilterU"
        );
        $results[] = ['name' => $rName, 'users' => $cnt, 'pct' => (int)$pct,
            'level' => $pct >= 70 ? 'green' : ($pct >= 40 ? 'yellow' : 'red')];
    }
    return $results;
}

$breadcrumbs = [];
$entities = [];
$showBUsGrid = false;
$topUsers = [];
$fullUserList = [];
$assignedCourses = [];
$topTitle = "";
$listTitle = "";
$entityLabel = "";
$kpiLabel2 = "Usuarios Totales";
$kpiLabel3 = "Cursos Completados";
$kpiVal3 = null;
$kpiFinalization  = ['pct' => 0, 'done' => 0, 'total' => 0];
$engagementStats  = ['active' => 0, 'atRisk' => 0, 'inactive' => 0];
$criticalRolesData = [];
$showKpiExtras    = false;

if ($qUserId) {
    // NIVEL 4: EXPEDIENTE ACADÉMICO INDIVIDUAL
    $stmtU = $pdo->prepare("SELECT u.name, u.email, u.companyId, u.businessUnitId, c.name as cName, b.name as bName FROM User u LEFT JOIN Company c ON u.companyId = c.id LEFT JOIN BusinessUnit b ON u.businessUnitId = b.id WHERE u.id = ?");
    $stmtU->execute([$qUserId]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if(!$uData) { die('Usuario no encontrado'); }
    
    $studentName = $uData['name'];
    $companyName = $uData['cName'] ?: 'Corporativo';
    $buName = $uData['bName'] ?: '(Autónomo)';
    
    $breadcrumbs[] = ['label' => 'Métricas Globales', 'url' => 'index.php?view=dashboard'];
    if ($uData['companyId']) {
        $breadcrumbs[] = ['label' => $companyName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($uData['companyId'])];
    }
    if ($uData['businessUnitId']) {
        $breadcrumbs[] = ['label' => $buName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($uData['companyId']) . '&bu_id=' . urlencode($uData['businessUnitId'])];
    }
    
    $title = "Expediente: " . htmlspecialchars($studentName);
    $subtitle = "Desglose de cursos asignados, avance histórico y calificaciones.";
    $entityLabel = "Cursos Asignados";
    $kpiLabel2 = "Promedio Global Exámenes";
    $showBUsGrid = false;
    
    $stmt = $pdo->prepare("
        SELECT c.id, c.title as name, 
               cp.userId, cp.isCompleted, cp.quizPassed, cp.quizScore, cp.updatedAt,
               (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as totalLessons,
               (SELECT COUNT(lp.id) FROM LessonProgress lp JOIN Lesson l ON lp.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id AND lp.userId = cp.userId AND lp.isCompleted = 1) as completedLessons
        FROM CourseProgress cp
        JOIN Course c ON cp.courseId = c.id
        WHERE cp.userId = ?
        ORDER BY c.title ASC
    ");
    $stmt->execute([$qUserId]);
    $assignedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $kpiEntities = count($assignedCourses);
    $kpiCompleted = 0; $kpiPassed = 0;
    $sumScore = 0; $countScore = 0;
    
    foreach($assignedCourses as $ac) {
        if ($ac['isCompleted']) $kpiCompleted++;
        if ($ac['quizPassed']) $kpiPassed++;
        if ($ac['quizScore'] !== null) { $sumScore += $ac['quizScore']; $countScore++; }
    }
    $kpiUsers = $countScore > 0 ? round($sumScore / $countScore, 1) . '%' : 'N/A';
    
} else if ($qCompanyId) {
    $stmtCName = $pdo->prepare("SELECT name FROM Company WHERE id = ?");
    $stmtCName->execute([$qCompanyId]);
    $companyName = $stmtCName->fetchColumn() ?: 'Compañía';
    
    $breadcrumbs[] = ['label' => 'Compañías', 'url' => 'index.php?view=dashboard'];
    
    if ($qBuId) {
        // NIVEL 3: UNIDAD DE NEGOCIO (ESTRICTA) O GLOBAL (CORPORATIVO)
        if ($qBuId === 'global') {
            $buName = 'Corporativo';
            $filterSqlBU = " AND businessUnitId IS NULL ";
            $filterSqlBU_U = " AND u.businessUnitId IS NULL ";
        } else {
            $stmtBName = $pdo->prepare("SELECT name FROM BusinessUnit WHERE id = ?");
            $stmtBName->execute([$qBuId]);
            $buName = $stmtBName->fetchColumn() ?: 'Unidad';
            $filterSqlBU = " AND businessUnitId = '$qBuId' ";
            $filterSqlBU_U = " AND u.businessUnitId = '$qBuId' ";
        }
        
        $breadcrumbs[] = ['label' => $companyName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($qCompanyId)];
        
        $title = "Rendimiento: " . htmlspecialchars($buName);
        $subtitle = "Visión detallada de los estudiantes pertenecientes a la filial.";
        $entityLabel = "N/A";
        
        $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' $filterSqlBU AND $metricFilterUser")->fetchColumn();
        $res = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$qCompanyId' $filterSqlBU AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
        
        $kpiEntities = 0;
        $kpiUsers = $uCount;
        $kpiCompleted = $res['comp'] ?: 0;
        $kpiPassed = $res['pass'] ?: 0;
        $kpiLabel3 = "Avance de Filial";
        $kpiVal3 = $qBuId === 'global' ? getAbsoluteProgressPct($pdo, 'COMPANY', $qCompanyId, " AND businessUnitId IS NULL") . '%' : getAbsoluteProgressPct($pdo, 'BU', $qBuId) . '%';

        // === NUEVOS KPIs (Nivel BU) ===
        $showKpiExtras = true;
        $buPF = $qBuId === 'global'
            ? "companyId = '$qCompanyId' AND businessUnitId IS NULL"
            : "companyId = '$qCompanyId' AND businessUnitId = '$qBuId'";
        $buAF = $qBuId === 'global'
            ? "u.companyId = '$qCompanyId' AND u.businessUnitId IS NULL"
            : "u.companyId = '$qCompanyId' AND u.businessUnitId = '$qBuId'";
        $kpiFinalization   = getFinalizationRate($pdo, $buPF, $metricFilterUser);
        $engagementStats   = getEngagementStats($pdo, $buPF, $metricFilterUser);
        $criticalRolesData = getCriticalRolesData($pdo, $buPF, $buAF);

        $topTitle = "Top 3 Alumnos Destacados (" . htmlspecialchars($buName) . ")";
        $listTitle = "Matrícula Completa (" . htmlspecialchars($buName) . ")";
        
        // Cargar Top 3 y Lista completa con score e isCompleted
        $topUsers = $pdo->query("
            SELECT u.id, u.name, u.email, u.image, u.totalPoints, COALESCE(SUM(CASE WHEN cp.isCompleted=1 THEN 1 ELSE 0 END), 0) as completedCourses, COALESCE(SUM(cp.quizScore), 0) as totalScore
            FROM User u LEFT JOIN CourseProgress cp ON u.id = cp.userId
            WHERE u.companyId = '$qCompanyId' $filterSqlBU_U AND $metricFilterU
            GROUP BY u.id, u.name, u.email, u.image, u.totalPoints ORDER BY u.totalPoints DESC, completedCourses DESC, totalScore DESC LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $fullUserList = $pdo->query("
            SELECT u.id, u.name, u.email, u.totalPoints,
                   (SELECT GROUP_CONCAT(DISTINCT tr.name SEPARATOR ', ') 
                    FROM _TrainingRoleToUser tr2u 
                    JOIN TrainingRole tr ON tr2u.A = tr.id 
                    WHERE tr2u.B = u.id) as trainingRolesNames,
                   (SELECT COUNT(DISTINCT lpc.courseId)
                    FROM _TrainingRoleToUser tru
                    JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                    JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                    WHERE tru.B = u.id) as assignedCount,
                   (SELECT COUNT(DISTINCT cp.courseId)
                    FROM CourseProgress cp
                    WHERE cp.userId = u.id AND cp.isCompleted = 1) as completedCount
            FROM User u
            WHERE u.companyId = '$qCompanyId' $filterSqlBU_U AND $metricFilterU
            ORDER BY u.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
    } else {
        // NIVEL 2: COMPAÑÍA GLOBAL
        $stmt = $pdo->prepare("SELECT id, name, logoPath FROM BusinessUnit WHERE companyId = ? AND isActive = 1");
        $stmt->execute([$qCompanyId]);
        $allBUs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($allBUs) > 0) {
            $showBUsGrid = true;
            $title = "Métricas — " . htmlspecialchars($companyName);
            $subtitle = "Visión transversal de las Unidades de Negocio pertenecientes a esta compañía.";
            $entityLabel = "Unidades de Negocio";
            $topTitle = "Top 3 Usuarios Globales de la Compañía";
            
            foreach ($allBUs as $bu) {
                $buId = $bu['id'];
                $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser")->fetchColumn();
                $pts = $pdo->query("SELECT SUM(totalPoints) FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser")->fetchColumn() ?: 0;
                $res = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass, SUM(quizScore) as scoreSum, COUNT(quizScore) as scoreCount FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
                
                $entities[] = [
                    'id' => $buId, 'name' => $bu['name'], 'logoPath' => $bu['logoPath'], 'usersCount' => $uCount, 'points' => $pts,
                    'completed' => $res['comp'] ?: 0, 'passed' => $res['pass'] ?: 0,
                    'avgScore' => $res['scoreCount'] > 0 ? round($res['scoreSum'] / $res['scoreCount'], 1) : null,
                    'absolutePct' => getAbsoluteProgressPct($pdo, 'BU', $buId)
                ];
            }
            
            $uCountG = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' AND businessUnitId IS NULL AND $metricFilterUser")->fetchColumn();
            if ($uCountG > 0) {
                $ptsG = $pdo->query("SELECT SUM(totalPoints) FROM User WHERE companyId = '$qCompanyId' AND businessUnitId IS NULL AND $metricFilterUser")->fetchColumn() ?: 0;
                $res = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass, SUM(quizScore) as scoreSum, COUNT(quizScore) as scoreCount FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$qCompanyId' AND businessUnitId IS NULL AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
                $entities[] = [
                    'id' => 'global', 'name' => 'Corporativo', 'usersCount' => $uCountG, 'points' => $ptsG,
                    'completed' => $res['comp'] ?: 0, 'passed' => $res['pass'] ?: 0,
                    'avgScore' => $res['scoreCount'] > 0 ? round($res['scoreSum'] / $res['scoreCount'], 1) : null,
                    'absolutePct' => getAbsoluteProgressPct($pdo, 'BU_GLOBAL_CORP', $qCompanyId)
                ];
            }
            
            // Reparación: Ordenar Unidades de Negocio usando los 3 KPIs como criterio de desempate
            usort($entities, function($a, $b) { 
                return [$b['absolutePct'], $b['passed'], $b['points']] <=> [$a['absolutePct'], $a['passed'], $a['points']]; 
            });
        } else {
            // Compañía SIN unidades de negocio
            $showBUsGrid = false;
            $title = "Métricas — " . htmlspecialchars($companyName);
            $subtitle = "Resumen analítico de desempeño.";
            $entityLabel = "N/A";
            $topTitle = "Top 3 Usuarios";
            $listTitle = "Listado General de Usuarios (" . htmlspecialchars($companyName) . ")";
            
            $fullUserList = $pdo->query("
                SELECT u.id, u.name, u.email, u.totalPoints,
                       (SELECT GROUP_CONCAT(DISTINCT tr.name SEPARATOR ', ') 
                        FROM _TrainingRoleToUser tr2u 
                        JOIN TrainingRole tr ON tr2u.A = tr.id 
                        WHERE tr2u.B = u.id) as trainingRolesNames,
                       (SELECT COUNT(DISTINCT lpc.courseId)
                        FROM _TrainingRoleToUser tru
                        JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                        JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                        WHERE tru.B = u.id) as assignedCount,
                       (SELECT COUNT(DISTINCT cp.courseId)
                        FROM CourseProgress cp
                        WHERE cp.userId = u.id AND cp.isCompleted = 1) as completedCount
                FROM User u
                WHERE u.companyId = '$qCompanyId' AND $metricFilterU
                ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $topUsers = $pdo->query("
            SELECT u.id, u.name, u.email, u.image, u.totalPoints, COALESCE(SUM(CASE WHEN cp.isCompleted=1 THEN 1 ELSE 0 END), 0) as completedCourses, COALESCE(SUM(cp.quizScore), 0) as totalScore
            FROM User u LEFT JOIN CourseProgress cp ON u.id = cp.userId
            WHERE u.companyId = '$qCompanyId' AND $metricFilterU
            GROUP BY u.id, u.name, u.email, u.image, u.totalPoints ORDER BY u.totalPoints DESC, completedCourses DESC, totalScore DESC LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($entities)) {
            $kpiEntities = count($entities);
            $kpiUsers = array_sum(array_column($entities, 'usersCount'));
            $kpiCompleted = array_sum(array_column($entities, 'completed'));
            $kpiPassed = array_sum(array_column($entities, 'passed'));
            $kpiLabel3 = "Avance Global";
            $kpiVal3 = getAbsoluteProgressPct($pdo, 'COMPANY', $qCompanyId) . '%';
        } else {
            $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' AND $metricFilterUser")->fetchColumn();
            $res = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$qCompanyId' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
            $kpiEntities = 0; $kpiUsers = $uCount; $kpiCompleted = $res['comp'] ?: 0; $kpiPassed = $res['pass'] ?: 0;
            $kpiLabel3 = "Avance Global";
            $kpiVal3 = getAbsoluteProgressPct($pdo, 'COMPANY_DIRECT', $qCompanyId) . '%';
        }
        // === NUEVOS KPIs (Nivel Empresa) ===
        $showKpiExtras     = true;
        $kpiFinalization   = getFinalizationRate($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $engagementStats   = getEngagementStats($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $criticalRolesData = getCriticalRolesData($pdo, "companyId = '$qCompanyId'", "u.companyId = '$qCompanyId'");
    }
} else {
    // NIVEL 1: PORTAL SUPERADMIN (COMPAÑÍAS)
    $showBUsGrid = true;
    $stmt = $pdo->query("SELECT id, name, logoPath FROM Company WHERE isActive = 1");
    $allCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($allCompanies as $c) {
        $cid = $c['id'];
        $bCount = $pdo->query("SELECT COUNT(id) FROM BusinessUnit WHERE companyId = '$cid' AND isActive = 1")->fetchColumn();
        $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$cid' AND $metricFilterUser")->fetchColumn();
        $ptsC = $pdo->query("SELECT SUM(totalPoints) FROM User WHERE companyId = '$cid' AND $metricFilterUser")->fetchColumn() ?: 0;
        $res = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass, SUM(quizScore) as scoreSum, COUNT(quizScore) as scoreCount FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$cid' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
        
        $entities[] = [
            'id' => $cid, 'name' => $c['name'], 'logoPath' => $c['logoPath'], 'usersCount' => $uCount, 'busCount' => $bCount, 'points' => $ptsC,
            'completed' => $res['comp'] ?: 0, 'passed' => $res['pass'] ?: 0,
            'avgScore' => $res['scoreCount'] > 0 ? round($res['scoreSum'] / $res['scoreCount'], 1) : null,
            'absolutePct' => getAbsoluteProgressPct($pdo, 'COMPANY', $cid)
        ];
    }
    
    // Sort logic con los 3 KPIs (Avance, Aprobados, Puntos)
    usort($entities, function($a, $b) { 
        return [$b['absolutePct'], $b['passed'], $b['points']] <=> [$a['absolutePct'], $a['passed'], $a['points']]; 
    });
    
    $title = "Métricas Globales de Plataforma";
    $subtitle = "Selecciona el panel de una compañía para visualizar los detalles de sus sucursales.";
    $entityLabel = "Compañías";
    
    $kpiEntities = count($entities);
    $kpiUsers = array_sum(array_column($entities, 'usersCount'));
    $kpiCompleted = array_sum(array_column($entities, 'completed'));
    $kpiPassed = array_sum(array_column($entities, 'passed'));
    
    $kpiLabel3 = "Avance Plataforma";
    $allCompanyPcts = array_column($entities, 'absolutePct');
    $kpiVal3 = (count($allCompanyPcts) > 0 ? round(array_sum($allCompanyPcts) / count($allCompanyPcts), 1) : 0) . '%';
}

?>
<style>
    .stats-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .kpi-card { background: white; border-radius: 12px; border: 1px solid #f3f4f6; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04); padding: 1.2rem; display: flex; align-items: center; justify-content: space-between; transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .kpi-card:hover { transform: translateY(-3px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); }
    .kpi-label { font-size: 0.75rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
    .kpi-val { font-size: 1.8rem; font-weight: 900; color: #1f2937; }
    
    .stats-card-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap: 1.5rem; }
    .s-card { background: white; border-radius: 16px; border: 1px solid #f3f4f6; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04); padding: 1.25rem; display: flex; flex-direction: column; gap: 1.25rem; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); text-decoration: none; color: inherit; }
    .s-card.hoverable:hover { box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); transform: translateY(-4px); }
    
    .sc-header { display: flex; justify-content: space-between; align-items: flex-start; }
    .sc-title-wrap { display: flex; gap: 0.75rem; align-items: center; }
    .sc-medal { font-size: 1.5rem; }
    .sc-title { font-weight: 700; color: #111827; font-size: 1.1rem; line-height: 1.2; text-decoration: none !important;}
    .sc-subtitle { font-size: 0.75rem; color: #6b7280; margin-top: 0.2rem; text-decoration: none !important; }
    
    .sc-link { font-size: 0.75rem; color: #6366f1; font-weight: 600; white-space: nowrap; }
    .s-card.hoverable:hover .sc-link { text-decoration: underline; }
    
    .sc-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.5rem; text-align: center; }
    .scm-box { border-radius: 12px; padding: 0.5rem; }
    .scm-val { font-size: 1.1rem; font-weight: 900; }
    .scm-lbl { font-size: 0.65rem; font-weight: 700; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.05em; }
    
    .sc-progress-wrap { width: 100%; background: #f3f4f6; border-radius: 999px; height: 6px; overflow: hidden; }
    .sc-progress-bar { height: 100%; border-radius: 999px; }
    
    .bg-yellow { background-color: #facc15; }
    .bg-gray { background-color: #9ca3af; }
    .bg-amber { background-color: #d97706; }
    .bg-indigo { background-color: #818cf8; }

    /* Grid 2 columnas para Roles + Engagement */
    .kpi-extras-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem; }

    @media(max-width: 768px) {
        .stats-kpi-grid  { grid-template-columns: 1fr 1fr; }
        .stats-card-grid { grid-template-columns: 1fr !important; }
        .kpi-extras-grid { grid-template-columns: 1fr; }
        .kpi-val         { font-size: 1.4rem; }
        .s-card          { padding: 1rem; gap: 1rem; }
        .sc-metrics      { gap: 0.3rem; }
        .scm-val         { font-size: 0.95rem; }
        .scm-lbl         { font-size: 0.6rem; }
    }
</style>

<div style="padding: 1rem 0;">
    <?php if(!empty($breadcrumbs)): ?>
        <nav style="margin-bottom: 1.5rem; font-size: 0.9rem; color: #6b7280; display: flex; gap: 0.5rem; align-items: center;">
            <?php foreach($breadcrumbs as $b): ?>
                <a href="<?= $b['url'] ?>" style="color: #4f46e5; font-weight: 500; text-decoration: none;"><?= $b['label'] ?></a> <i class='bx bx-chevron-right'></i>
            <?php endforeach; ?>
            <span style="font-weight: 600; color: #1f2937;"><?= htmlspecialchars($qBuId ? $buName : $companyName) ?></span>
        </nav>
    <?php endif; ?>



    <!-- KPIs -->
    <main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
        <div style="padding: 1.5rem; background: #f8fafc;">
    <div class="stats-kpi-grid">
        <?php if ($entityLabel !== "N/A"): ?>
        <div class="kpi-card">
            <div>
                <p class="kpi-label"><?= htmlspecialchars($entityLabel) ?></p>
                <p class="kpi-val"><?= $kpiEntities > 0 ? number_format($kpiEntities) : "—" ?></p>
            </div>
            <i class='bx bx-buildings' style="font-size:2rem;color:#6366f1;opacity:0.8;"></i>
        </div>
        <?php endif; ?>
        <div class="kpi-card">
            <div>
                <p class="kpi-label"><?= $kpiLabel2 ?></p>
                <p class="kpi-val"><?= $kpiLabel2 === 'Usuarios Totales' ? number_format($kpiUsers) : $kpiUsers ?></p>
            </div>
            <i class='bx bx-group' style="font-size:2rem;color:#3b82f6;opacity:0.8;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <p class="kpi-label"><?= $kpiLabel3 ?></p>
                <p class="kpi-val"><?= $kpiVal3 !== null ? $kpiVal3 : number_format($kpiCompleted) ?></p>
            </div>
            <i class='bx bx-trending-up' style="font-size:2rem;color:#10b981;opacity:0.8;"></i>
        </div>
        <div class="kpi-card">
            <div>
                <p class="kpi-label">Exámenes Aprobados</p>
                <p class="kpi-val"><?= number_format($kpiPassed) ?></p>
            </div>
            <i class='bx bxs-check-circle' style="font-size:2rem;color:#22c55e;opacity:0.8;"></i>
        </div>
        <?php if ($showKpiExtras && $kpiFinalization['total'] > 0): ?>
        <div class="kpi-card">
            <div>
                <p class="kpi-label">Tasa Finalización</p>
                <p class="kpi-val"><?= $kpiFinalization['pct'] ?>%</p>
                <p style="font-size:0.68rem;color:#9ca3af;margin:0.15rem 0 0;"><?= $kpiFinalization['done'] ?>/<?= $kpiFinalization['total'] ?> cursos</p>
            </div>
            <i class='bx bxs-flag-checkered' style="font-size:2rem;color:#f97316;opacity:0.8;"></i>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($showKpiExtras): ?>
    <div class="kpi-extras-grid">

        <!-- Roles Críticos — Gauge Semicircular -->
        <?php if (!empty($criticalRolesData)): ?>
        <div style="background:white;border-radius:16px;border:1px solid #f1f5f9;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);padding:1.5rem;">
            <h3 style="font-size:0.95rem;font-weight:800;color:#0f172a;margin:0 0 1.4rem;display:flex;align-items:center;gap:0.5rem;">
                <i class='bx bxs-shield-alt-2' style='color:#ef4444;font-size:1.2rem;'></i> Roles Críticos
            </h3>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:0.75rem 0.25rem;">
            <?php foreach($criticalRolesData as $cr):
                $crC  = $cr['level']==='green' ? '#16a34a' : ($cr['level']==='yellow' ? '#d97706' : '#dc2626');
                $crBg = $cr['level']==='green' ? '#f0fdf4' : ($cr['level']==='yellow' ? '#fffbeb' : '#fef2f2');
                $gLen  = round(M_PI * 40, 2); // 125.66
                $gFill = round(($cr['pct'] / 100) * $gLen, 2);
            ?>
                <div style="display:flex;flex-direction:column;align-items:center;">
                    <div style="position:relative;width:100px;height:60px;">
                        <svg width="100" height="58" viewBox="0 0 100 58" aria-hidden="true">
                            <path d="M 10,54 A 40,40 0 0,1 90,54" fill="none" stroke="#f1f5f9" stroke-width="10" stroke-linecap="round"/>
                            <path d="M 10,54 A 40,40 0 0,1 90,54" fill="none" stroke="<?= $crC ?>" stroke-width="10" stroke-linecap="round"
                                stroke-dasharray="<?= $gFill ?> <?= $gLen ?>"/>
                        </svg>
                        <div style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);white-space:nowrap;text-align:center;">
                            <span style="font-size:0.9rem;font-weight:900;color:<?= $crC ?>;"><?= $cr['pct'] ?>%</span>
                        </div>
                    </div>
                    <div style="text-align:center;margin-top:0.35rem;">
                        <div style="font-size:0.68rem;font-weight:700;color:#0f172a;line-height:1.2;word-break:break-word;"><?= htmlspecialchars($cr['name']) ?></div>
                        <div style="font-size:0.6rem;color:#94a3b8;margin-top:0.1rem;"><?= $cr['users'] ?> pers.</div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?><div></div><?php endif; ?>

        <!-- Actividad Reciente — Barras Verticales -->
        <?php
            $totalE   = $engagementStats['active'] + $engagementStats['atRisk'] + $engagementStats['inactive'];
            $maxCount = max($engagementStats['active'], $engagementStats['atRisk'], $engagementStats['inactive'], 1);
            $aBar = $engagementStats['active']   > 0 ? max(round(($engagementStats['active']   / $maxCount) * 100), 8) : 0;
            $rBar = $engagementStats['atRisk']   > 0 ? max(round(($engagementStats['atRisk']   / $maxCount) * 100), 8) : 0;
            $iBar = $engagementStats['inactive'] > 0 ? max(round(($engagementStats['inactive'] / $maxCount) * 100), 8) : 0;
        ?>
        <div style="background:white;border-radius:16px;border:1px solid #f1f5f9;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);padding:1.5rem;">
            <h3 style="font-size:0.95rem;font-weight:800;color:#0f172a;margin:0 0 1.2rem;display:flex;align-items:center;gap:0.5rem;">
                <i class='bx bx-history' style='color:#64748b;font-size:1.2rem;'></i> Actividad Reciente
            </h3>
            <!-- Barras verticales -->
            <div style="display:flex;align-items:flex-end;justify-content:space-around;height:96px;border-bottom:2px solid #f1f5f9;padding:0 8px;gap:8px;margin-bottom:0.6rem;">
                <!-- Activos -->
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;flex:1;height:100%;">
                    <span style="font-size:1.05rem;font-weight:900;color:#16a34a;line-height:1;margin-bottom:5px;"><?= $engagementStats['active'] ?></span>
                    <?php if($aBar > 0): ?>
                    <div style="width:70%;height:<?= $aBar ?>%;background:linear-gradient(180deg,#4ade80,#16a34a);border-radius:5px 5px 0 0;"></div>
                    <?php else: ?>
                    <div style="width:70%;height:3px;background:#f1f5f9;border-radius:5px 5px 0 0;"></div>
                    <?php endif; ?>
                </div>
                <!-- En riesgo -->
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;flex:1;height:100%;">
                    <span style="font-size:1.05rem;font-weight:900;color:#d97706;line-height:1;margin-bottom:5px;"><?= $engagementStats['atRisk'] ?></span>
                    <?php if($rBar > 0): ?>
                    <div style="width:70%;height:<?= $rBar ?>%;background:linear-gradient(180deg,#fcd34d,#d97706);border-radius:5px 5px 0 0;"></div>
                    <?php else: ?>
                    <div style="width:70%;height:3px;background:#f1f5f9;border-radius:5px 5px 0 0;"></div>
                    <?php endif; ?>
                </div>
                <!-- Inactivos -->
                <div style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;flex:1;height:100%;">
                    <span style="font-size:1.05rem;font-weight:900;color:#dc2626;line-height:1;margin-bottom:5px;"><?= $engagementStats['inactive'] ?></span>
                    <?php if($iBar > 0): ?>
                    <div style="width:70%;height:<?= $iBar ?>%;background:linear-gradient(180deg,#f87171,#dc2626);border-radius:5px 5px 0 0;"></div>
                    <?php else: ?>
                    <div style="width:70%;height:3px;background:#f1f5f9;border-radius:5px 5px 0 0;"></div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Etiquetas eje X -->
            <div style="display:flex;justify-content:space-around;padding:0 8px;gap:8px;margin-bottom:0.6rem;">
                <div style="flex:1;text-align:center;">
                    <div style="font-size:0.62rem;font-weight:700;color:#15803d;text-transform:uppercase;">Activos</div>
                    <div style="font-size:0.55rem;color:#94a3b8;margin-top:1px;">últ. 7 días</div>
                </div>
                <div style="flex:1;text-align:center;">
                    <div style="font-size:0.62rem;font-weight:700;color:#b45309;text-transform:uppercase;">En Riesgo</div>
                    <div style="font-size:0.55rem;color:#94a3b8;margin-top:1px;">7-14 días</div>
                </div>
                <div style="flex:1;text-align:center;">
                    <div style="font-size:0.62rem;font-weight:700;color:#b91c1c;text-transform:uppercase;">Inactivos</div>
                    <div style="font-size:0.55rem;color:#94a3b8;margin-top:1px;">+14 días</div>
                </div>
            </div>
            <div style="font-size:0.57rem;color:#cbd5e1;">Basado en último inicio de sesión</div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- GRID DE ENTIDADES (Companies / BUs) -->
    <?php if ($showBUsGrid && !empty($entities)): ?>
        <div class="stats-card-grid" style="margin-bottom: 3rem;">
            <?php foreach ($entities as $idx => $e): 
                $medal = $idx === 0 ? "🥇" : ($idx === 1 ? "🥈" : ($idx === 2 ? "🥉" : "🏬"));
                $pct = max(round($e['absolutePct']), 0);
                $dynHue = round(($pct / 100) * 120);
                
                $href = "#";
                $isHover = false;
                if (!$qCompanyId) {
                    $href = "index.php?view=dashboard&company_id=" . urlencode($e['id']);
                    $isHover = true;
                } else if ($qCompanyId) {
                    $href = "index.php?view=dashboard&company_id=" . urlencode($qCompanyId) . "&bu_id=" . urlencode($e['id']);
                    $isHover = true;
                }
            ?>
                <a href="<?= $href ?>" class="s-card <?= $isHover ? 'hoverable' : '' ?>" style="position: relative;">
                    <?php if ($idx < 3 && empty($qBuId)): ?>
                        <div style="position: absolute; top: -15px; left: -15px; z-index: 10; width: 48px; height: 48px; pointer-events: none;">
                            <img src="assets/images/medal_<?= $idx + 1 ?>.png" alt="Sello de lugar <?= $idx + 1 ?>" style="width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 4px rgba(0,0,0,0.15)); transform: rotate(-5deg);">
                        </div>
                    <?php endif; ?>
                    <div class="sc-header">
                        <div class="sc-title-wrap" style="align-items: center;">
                            <?php if (!empty($e['logoPath'])): ?>
                                <div style="width:44px;height:44px;border-radius:8px;border:1px solid #e5e7eb;overflow:hidden;flex-shrink:0;background:white;padding:2px;position:relative;">
                                    <img src="<?= htmlspecialchars($e['logoPath']) ?>" alt="Logo" style="width:100%;height:100%;object-fit:contain;"
                                         onerror="this.style.display='none';this.parentElement.querySelector('.logo-fallback').style.display='flex';">
                                    <div class="logo-fallback" style="display:none;width:100%;height:100%;align-items:center;justify-content:center;position:absolute;top:0;left:0;">
                                        <i class='bx bx-building-house' style="font-size:1.4rem;color:#9ca3af;"></i>
                                    </div>
                                </div>
                            <?php else: ?>
                                <span class="sc-medal"><?= $medal ?></span>
                            <?php endif; ?>
                            <div>
                                <p class="sc-title"><?= htmlspecialchars($e['name']) ?></p>
                                <p class="sc-subtitle">
                                    <?= number_format($e['usersCount']) ?> usuarios 
                                    <?php if(isset($e['busCount'])) echo "· " . number_format($e['busCount']) . " ramas"; ?>
                                </p>
                            </div>
                        </div>
                        <?php if($isHover): ?>
                            <span class="sc-link"><?= !$qCompanyId ? 'Ver Ramas / Detalle →' : 'Ver Matrícula →' ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="sc-metrics">
                        <div class="scm-box" style="background: #f9fafb;">
                            <p class="scm-val" style="color: #1f2937;"><?= isset($e['points']) ? number_format($e['points']) : number_format($e['completed']) ?></p>
                            <p class="scm-lbl"><?= isset($e['points']) ? 'Puntos' : 'Completados' ?></p>
                        </div>
                        <div class="scm-box" style="background: #f0fdf4;">
                            <p class="scm-val" style="color: #16a34a;"><?= number_format($e['passed']) ?></p>
                            <p class="scm-lbl">Cur. Aprob.</p>
                        </div>
                        <div class="scm-box" style="background: hsl(<?= $dynHue ?>, 80%, 96%);">
                            <p class="scm-val" style="color: hsl(<?= $dynHue ?>, 80%, 40%);"><?= $pct ?>%</p>
                            <p class="scm-lbl">Avance</p>
                        </div>
                    </div>
                    
                    <div class="sc-progress-wrap">
                        <div class="sc-progress-bar" style="background-color: hsl(<?= $dynHue ?>, 80%, 50%); width: <?= $pct ?>%;"></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- TOP USUARIOS (Leaderboard) -->
    <?php if (!empty($topUsers)): ?>
        <h2 style="font-size: 1.4rem; font-weight: 800; margin-top: 1rem; color: #1f2937; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.4rem;">
            <i class='bx bxs-crown' style="color: #f59e0b;"></i> <?= $topTitle ?>
        </h2>
        <div class="stats-card-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); margin-bottom: 3rem;">
            <?php foreach($topUsers as $idx => $user): 
                $medal = $idx === 0 ? "🏆" : ($idx === 1 ? "🥈" : "🥉");
                $bCol = $idx === 0 ? "border-color: #fef08a; background: #fefce8;" : ($idx === 1 ? "border-color: #e5e7eb; background: #f9fafb;" : "border-color: #fed7aa; background: #fff7ed;");
            ?>
                <div class="s-card" style="position: relative; <?= $bCol ?>">
                    <?php if ($idx < 3): ?>
                        <div style="position: absolute; top: -15px; left: -15px; z-index: 10; width: 48px; height: 48px; pointer-events: none;">
                            <img src="assets/images/medal_<?= $idx + 1 ?>.png" alt="Sello de lugar <?= $idx + 1 ?>" style="width: 100%; height: 100%; object-fit: contain; filter: drop-shadow(0 6px 4px rgba(0,0,0,0.15)); transform: rotate(-5deg);">
                        </div>
                    <?php endif; ?>
                    <div class="sc-header" style="align-items: center;">
                        <div class="sc-title-wrap">
                            <div style="width: 44px; height: 44px; border-radius: 50%; border: 2px solid #e5e7eb; overflow: hidden; flex-shrink: 0; background: white; margin-right: 0.5rem; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #9ca3af;">
                                <?php if (!empty($user['image'])): ?>
                                    <img src="<?= htmlspecialchars($user['image']) ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover;">
                                <?php else: ?>
                                    <i class='bx bxs-user'></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="sc-title" style="font-size: 1.2rem; color: #1f2937; margin: 0; font-weight: 700;"><?= htmlspecialchars($user['name']) ?></p>
                                <p class="sc-subtitle" style="margin-top:0; font-size: 0.85rem;"><?= htmlspecialchars($user['email']) ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="sc-metrics" style="grid-template-columns: 1fr 1fr 1fr; gap: 0.5rem;">
                        <div class="scm-box" style="background: white; border: 1px solid rgba(0,0,0,0.05); padding: 0.5rem;">
                            <p class="scm-val" style="color: #fbbf24; font-size: 1.2rem;"><?= number_format($user['totalPoints'] ?? 0) ?></p>
                            <p class="scm-lbl" style="font-size: 0.70rem;">Puntos</p>
                        </div>
                        <div class="scm-box" style="background: white; border: 1px solid rgba(0,0,0,0.05); padding: 0.5rem;">
                            <p class="scm-val" style="color: #16a34a; font-size: 1.2rem;"><?= number_format($user['completedCourses']) ?></p>
                            <p class="scm-lbl" style="font-size: 0.70rem;">Cursos Comp.</p>
                        </div>
                        <div class="scm-box" style="background: white; border: 1px solid rgba(0,0,0,0.05); padding: 0.5rem;">
                            <p class="scm-val" style="color: #4f46e5; font-size: 1.2rem;"><?= number_format($user['totalScore']) ?></p>
                            <p class="scm-lbl" style="font-size: 0.70rem;">Calif. Glob.</p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- MATRÍCULA COMPLETA -->
    <?php 
        $userCourses = [];
        if (!empty($fullUserList)): 
            $uIds = array_column($fullUserList, 'id');
            $placeholders = str_repeat('?,', count($uIds) - 1) . '?';
            $stmtC = $pdo->prepare("
                SELECT c.title as name,
                       assigned.userId,
                       CASE WHEN cp.courseId IS NOT NULL THEN 1 ELSE 0 END as hasStarted,
                       cp.isCompleted, cp.quizPassed, cp.quizScore, cp.quizAttempts,
                       (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as totalLessons,
                       (SELECT COUNT(lp2.id) FROM LessonProgress lp2 JOIN Lesson l2 ON lp2.lessonId = l2.id JOIN Module m2 ON l2.moduleId = m2.id WHERE m2.courseId = c.id AND lp2.userId = assigned.userId AND lp2.isCompleted = 1) as completedLessons
                FROM (
                    SELECT DISTINCT tru.B as userId, lpc.courseId
                    FROM _TrainingRoleToUser tru
                    JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                    JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                    WHERE tru.B IN ($placeholders)
                ) assigned
                JOIN Course c ON c.id = assigned.courseId
                LEFT JOIN CourseProgress cp ON cp.courseId = c.id AND cp.userId = assigned.userId
                ORDER BY c.title ASC
            ");
            $stmtC->execute($uIds);
            foreach($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userCourses[$row['userId']][] = $row;
            }
    ?>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #1f2937; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.4rem;">
            <i class='bx bx-user-pin' style="color: #3b82f6;"></i> <?= $listTitle ?>
        </h2>
        <div class="card" style="padding: 0; overflow: hidden; border-radius: 12px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
            <div class="table-responsive">
                <table class="data-table" style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                        <tr>
                            <th style="padding: 1rem; text-align: left; font-size: 0.85rem; color: #6b7280; font-weight: 600; text-transform: uppercase; width: 20%;">Alumno</th>
                            <th style="padding: 1rem; text-align: left; font-size: 0.85rem; color: #6b7280; font-weight: 600; text-transform: uppercase; width: 20%;">Roles de Formación</th>
                            <th style="padding: 1rem; text-align: left; font-size: 0.85rem; color: #6b7280; font-weight: 600; text-transform: uppercase; width: 60%;">Desglose de Cursos Asignados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($fullUserList as $idx => $user): 
                            $uid = $user['id'];
                            $courses = $userCourses[$uid] ?? [];
                        ?>
                            <tr style="border-bottom: 1px solid #f3f4f6; transition: background 0.2s; background: <?= $idx % 2 === 0 ? 'white' : '#fcfcfc' ?>;">
                                <td style="padding: 1rem; vertical-align: top;">
                                    <div style="font-weight: 700; color: #1f2937; font-size: 1.05rem;"><?= htmlspecialchars($user['name']) ?></div>
                                    <div style="font-size: 0.85rem; color: #6b7280; margin-top: 0.2rem;"><?= htmlspecialchars($user['email']) ?></div>
                                    
                                    <?php 
                                        $assigned = (int)$user['assignedCount'];
                                        $completed = min((int)$user['completedCount'], $assigned);
                                        $globalPct = $assigned > 0 ? min(round(($completed / $assigned) * 100), 100) : 0;
                                        $dynUserHue = round(($globalPct / 100) * 120);
                                    ?>
                                    <?php if($assigned > 0): ?>
                                        <div style="margin-top: 1rem;">
                                            <div style="display: flex; justify-content: space-between; font-size: 0.75rem; font-weight: 700; color: #6b7280; margin-bottom: 0.3rem;">
                                                <span>Total Cursos Completados: <?= $completed ?> / <?= $assigned ?></span>
                                                <span>
                                                    <i class='bx bxs-star' style="color: #fbbf24; margin-right: 2px;"></i> 
                                                    <span style="color: #1f2937; margin-right: 8px;"><?= number_format($user['totalPoints'] ?? 0) ?> Pts</span>
                                                    <span style="color: hsl(<?= $dynUserHue ?>, 80%, 40%);"><?= $globalPct ?>%</span>
                                                </span>
                                            </div>
                                            <div style="width: 100%; background: #e5e7eb; border-radius: 999px; height: 6px; overflow: hidden;">
                                                <div style="height: 100%; background-color: hsl(<?= $dynUserHue ?>, 80%, 50%); width: <?= $globalPct ?>%; transition: width 0.5s;"></div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div style="margin-top: 1rem; color: #9ca3af; font-size: 0.75rem; font-style: italic;">Sin asignación académica formal. Excluido de estadísticas globales.</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem; vertical-align: top;">
                                    <?php if($user['trainingRolesNames']): ?>
                                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                                            <?php foreach(explode(', ', $user['trainingRolesNames']) as $trn): ?>
                                                <div style="display: inline-flex; align-items: center; gap: 0.3rem; background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
                                                    <i class='bx bx-briefcase-alt-2'></i> <?= htmlspecialchars($trn) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: #9ca3af; font-size: 0.85rem; font-style: italic;">Sin Perfil Asignado</div>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 1rem; vertical-align: top;">
                                    <?php if(!empty($courses)): ?>
                                        <div style="display: flex; flex-direction: column; gap: 0.4rem; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 0.5rem;">
                                            <?php foreach($courses as $c): 
                                                $hasStarted = $c['hasStarted'] == 1;
                                                $isComp = $c['isCompleted'] == 1 || $c['isCompleted'] === true;
                                                $passed = $c['quizPassed'] == 1 || $c['quizPassed'] === true;
                                                $tL = (int)$c['totalLessons'];
                                                $cL = (int)$c['completedLessons'];
                                                $pct = $tL > 0 ? round(($cL / $tL) * 100) : 0;
                                                $statusText = $isComp ? 'Graduado' : ($hasStarted ? "En Curso ($pct%)" : '0%');
                                            ?>
                                                <div style="display: grid; grid-template-columns: 3.5fr 1.2fr 1fr 1.2fr; gap: 0.8rem; align-items: center; padding: 0.3rem 0; border-bottom: 1px dashed #f3f4f6; font-size: 0.75rem;">
                                                    <div style="font-weight: 600; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($c['name']) ?>">
                                                        <i class="bx <?= $isComp ? 'bx-check-double' : ($hasStarted ? 'bx-loader-circle bx-spin' : 'bx-minus-circle') ?>" style="color: <?= $isComp ? '#16a34a' : ($hasStarted ? '#3b82f6' : '#9ca3af') ?>; font-size: 1rem; vertical-align: middle;"></i>
                                                        <?= htmlspecialchars($c['name']) ?>
                                                    </div>
                                                    <div style="color:#6b7280;text-align:center;display:flex;flex-direction:column;align-items:center;gap:1px;">
                                                        <span><i class='bx bx-book-open'></i> <b style="color:#1f2937;"><?= $hasStarted ? "$cL/$tL ($pct%)" : "0/0" ?></b></span>
                                                        <span style="font-size:0.6rem;color:#9ca3af;font-weight:700;letter-spacing:0.03em;">LECCIONES</span>
                                                    </div>
                                                    <div style="color:#6b7280;text-align:center;display:flex;flex-direction:column;align-items:center;gap:1px;">
                                                        <span><i class='bx bx-revision'></i> <b style="color:#1f2937;"><?= $c['quizAttempts'] ?: 0 ?></b></span>
                                                        <span style="font-size:0.6rem;color:#9ca3af;font-weight:700;letter-spacing:0.03em;">INTENTOS</span>
                                                    </div>
                                                    <div style="color:#6b7280;text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:1px;">
                                                        <span><i class='bx bx-notepad'></i> <b style="color:<?= $passed ? '#16a34a' : ($c['quizScore'] !== null ? '#b91c1c' : '#1f2937') ?>;"><?= $c['quizScore'] !== null ? $c['quizScore'] : '&mdash;' ?></b></span>
                                                        <span style="font-size:0.6rem;color:#9ca3af;font-weight:700;letter-spacing:0.03em;">CALIFICACI&Oacute;N</span>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div style="color: #9ca3af; font-size: 0.85rem; font-style: italic;">Sin roles formativos ni cursos asignados.</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- LISTA DE CURSOS INDIVIDUAL (Profundidad Máxima Nivel 4) -->
    <?php if ($qUserId && !empty($assignedCourses)): ?>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #1f2937; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.4rem;">
            <i class='bx bx-book-open' style="color: #6366f1;"></i> Desglose Académico del Estudiante
        </h2>
        <div class="stats-card-grid" style="grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));">
            <?php foreach($assignedCourses as $course): 
                $hasStarted = $course['userId'] !== null;
                $isCompleted = $course['isCompleted'] == 1;
                $tL = (int)$course['totalLessons'];
                $cL = (int)$course['completedLessons'];
                $pct = $tL > 0 ? min(round(($cL / $tL) * 100), 100) : 0;
                $bgC = $isCompleted ? 'border-color: #bbf7d0; background: #f0fdf4;' : ($hasStarted ? 'border-color: #bfdbfe; background: #eff6ff;' : 'border-color: #e5e7eb; background: #f9fafb;');
            ?>
                <div class="s-card" style="<?= $bgC ?>">
                    <div class="sc-header">
                        <div class="sc-title-wrap">
                            <div>
                                <p class="sc-title" style="font-size: 1.05rem; text-decoration: none !important;"><?= htmlspecialchars($course['name']) ?></p>
                                <p class="sc-subtitle">
                                    <?php if ($isCompleted): ?>
                                        <span style="color: #16a34a; font-weight: 600;"><i class='bx bx-check-double'></i> Terminó</span>
                                    <?php elseif ($hasStarted): ?>
                                        <span style="color: #3b82f6; font-weight: 600;"><i class='bx bx-loader-circle bx-spin'></i> En Progreso (<?= $pct ?>%)</span>
                                    <?php else: ?>
                                        <span style="color: #9ca3af; font-weight: 500;"><i class='bx bx-minus-circle'></i> No Inicializado</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="sc-metrics" style="grid-template-columns: 1fr 1fr;">
                        <div class="scm-box" style="background: white; border: 1px solid rgba(0,0,0,0.05);">
                            <p class="scm-val" style="color: #1f2937; font-size: 1.2rem;"><?= $hasStarted ? "$cL/$tL" : '0/0' ?></p>
                            <p class="scm-lbl">Lecciones</p>
                        </div>
                        <div class="scm-box" style="background: white; border: 1px solid rgba(0,0,0,0.05);">
                            <p class="scm-val" style="color: <?= $course['quizPassed'] ? '#16a34a' : ($course['quizScore'] !== null ? '#b91c1c' : '#9ca3af') ?>; font-size: 1.2rem;">
                                <?= $course['quizScore'] !== null ? $course['quizScore'] : '—' ?>
                            </p>
                            <p class="scm-lbl">Cali. Examen</p>
                        </div>
                    </div>
                    
                    <div class="sc-progress-wrap" style="height: 8px;">
                        <div class="sc-progress-bar <?= $isCompleted ? 'bg-amber' : 'bg-indigo' ?>" style="width: <?= max($pct, 2) ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        </div>
    </main>
</div>
