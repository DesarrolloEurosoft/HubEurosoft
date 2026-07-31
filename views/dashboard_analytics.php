<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// === CORTAFUEGOS (RBAC) ===
if ($userRole === 'COMPANY_LEADER' || $userRole === 'BUSINESS_UNIT_LEADER') {
    $_GET['company_id'] = $_SESSION['user_company'] ?? 'null';
    if ($userRole === 'BUSINESS_UNIT_LEADER') {
        $_GET['bu_id'] = $_SESSION['user_bu'] ?? 'null';
    }
    if (!empty($_GET['user_id'])) {
        $chkUser = $pdo->prepare("SELECT companyId, businessUnitId FROM User WHERE id = ?");
        $chkUser->execute([$_GET['user_id']]);
        $uScope = $chkUser->fetch(PDO::FETCH_ASSOC);
        if (!$uScope ||
           ($userRole === 'COMPANY_LEADER' && $uScope['companyId'] !== $_SESSION['user_company']) ||
           ($userRole === 'BUSINESS_UNIT_LEADER' && $uScope['businessUnitId'] !== $_SESSION['user_bu'])) {
            unset($_GET['user_id']);
        }
    }
}

$qCompanyId = $_GET['company_id'] ?? null;
$qBuId      = $_GET['bu_id']      ?? null;
$qUserId    = $_GET['user_id']    ?? null;

// Sincronizar logs históricos de inicio de sesión de LoginLog a la columna lastLoginAt en la tabla User si están nulos
try {
    $pdo->query("
        UPDATE User u
        SET u.lastLoginAt = (
            SELECT MAX(l.createdAt)
            FROM LoginLog l
            WHERE l.userId = u.id
        )
        WHERE u.lastLoginAt IS NULL
          AND EXISTS (
              SELECT 1 FROM LoginLog l WHERE l.userId = u.id
          )
    ");
} catch (Exception $e) {}

// ==========================================
// CONDICIONES DE USUARIOS "METRICABLES"
// ==========================================
$metricFilterUser = "(role = 'STUDENT' OR role = 'BUSINESS_UNIT_LEADER' OR (role = 'COMPANY_LEADER' AND (businessUnitId IS NOT NULL OR NOT EXISTS(SELECT 1 FROM BusinessUnit WHERE BusinessUnit.companyId = User.companyId))))";
$metricFilterU    = "(u.role = 'STUDENT' OR u.role = 'BUSINESS_UNIT_LEADER' OR (u.role = 'COMPANY_LEADER' AND (u.businessUnitId IS NOT NULL OR NOT EXISTS(SELECT 1 FROM BusinessUnit WHERE BusinessUnit.companyId = u.companyId))))";

// ==========================================
// HELPER: PROGRESO ABSOLUTO
// ==========================================
function getAbsoluteProgressPct($pdo, $type, $id) {
    global $metricFilterU;
    if ($type === 'COMPANY') {
        $stmt = $pdo->query("SELECT id FROM BusinessUnit WHERE companyId = '$id' AND isActive = 1");
        $bus = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($bus) > 0) {
            $sum = 0;
            foreach ($bus as $buId) { $sum += getAbsoluteProgressPct($pdo, 'BU', $buId); }
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
            FROM User u WHERE $whereClause AND $metricFilterU
        ");
        $students = $stmtBUPct->fetchAll(PDO::FETCH_ASSOC);
        $totalPct = 0; $valid = 0;
        foreach ($students as $s) {
            if ($s['assigned'] > 0) {
                $valid++;
                $c = min((int)$s['completed'], (int)$s['assigned']);
                $totalPct += ($c / (int)$s['assigned']) * 100;
            }
        }
        return $valid > 0 ? round($totalPct / $valid, 1) : 0;
    }
    return 0;
}

// === Tasa de Finalización ===
function getFinalizationRate($pdo, $whereFilter, $metricFilter) {
    $total     = (int)$pdo->query("SELECT COUNT(*) FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE ($whereFilter) AND ($metricFilter))")->fetchColumn();
    $completed = (int)$pdo->query("SELECT COUNT(*) FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE ($whereFilter) AND ($metricFilter)) AND isCompleted = 1")->fetchColumn();
    return ['pct' => $total > 0 ? round(($completed / $total) * 100, 1) : 0, 'done' => $completed, 'total' => $total];
}

// === Engagement Stats ===
function getEngagementStats($pdo, $whereFilter, $metricFilter) {
    $active   = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 3 DAY)")->fetchColumn();
    $atRisk   = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt < DATE_SUB(NOW(), INTERVAL 3 DAY) AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 10 DAY)")->fetchColumn();
    $inactive = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt IS NOT NULL AND lastLoginAt < DATE_SUB(NOW(), INTERVAL 10 DAY)")->fetchColumn();
    $neverIn  = (int)$pdo->query("SELECT COUNT(*) FROM User WHERE ($whereFilter) AND ($metricFilter) AND lastLoginAt IS NULL")->fetchColumn();
    return ['active' => $active, 'atRisk' => $atRisk, 'inactive' => $inactive, 'neverIn' => $neverIn];
}

// === Engagement Users Lists ===
function getEngagementUsers($pdo, $whereFilter, $metricFilter) {
    $base     = "SELECT name, email, lastLoginAt FROM User WHERE ($whereFilter) AND ($metricFilter)";
    $active   = $pdo->query("$base AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 3 DAY) ORDER BY lastLoginAt DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $atRisk   = $pdo->query("$base AND lastLoginAt < DATE_SUB(NOW(), INTERVAL 3 DAY) AND lastLoginAt >= DATE_SUB(NOW(), INTERVAL 10 DAY) ORDER BY lastLoginAt DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $inactive = $pdo->query("$base AND lastLoginAt IS NOT NULL AND lastLoginAt < DATE_SUB(NOW(), INTERVAL 10 DAY) ORDER BY lastLoginAt ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $neverIn  = $pdo->query("$base AND lastLoginAt IS NULL ORDER BY name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $fmt = function($rows) {
        return array_map(function($r) {
            return ['name' => $r['name'], 'email' => $r['email'],
                    'lastLogin' => $r['lastLoginAt'] ? date('d/m/Y H:i', strtotime($r['lastLoginAt'])) : null];
        }, $rows);
    };
    return ['active' => $fmt($active), 'atRisk' => $fmt($atRisk), 'inactive' => $fmt($inactive), 'neverIn' => $fmt($neverIn)];
}

// === Roles Críticos ===
function getCriticalRolesData($pdo, $plainFilter, $aliasFilter) {
    global $metricFilterU;
    // Dinámico: todos los TrainingRoles con al menos 1 usuario en el contexto
    $rolesStmt = $pdo->query("
        SELECT DISTINCT tr.id, tr.name
        FROM TrainingRole tr
        JOIN _TrainingRoleToUser tru ON tru.A = tr.id
        JOIN User u ON u.id = tru.B
        WHERE ($aliasFilter) AND ($metricFilterU)
        ORDER BY tr.name
    ");
    $results = [];
    foreach ($rolesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rid = $row['id'];
        $esc = str_replace("'", "\\'", $row['name']);
        $cnt = (int)$pdo->query("
            SELECT COUNT(DISTINCT u.id) FROM User u
            JOIN _TrainingRoleToUser tru ON tru.B = u.id
            WHERE tru.A = '$rid' AND ($aliasFilter) AND ($metricFilterU)
        ")->fetchColumn();
        if ($cnt === 0) continue;
        $pct = getAbsoluteProgressPct($pdo, 'STUDENT_LIST',
            "u.id IN (SELECT t2.B FROM _TrainingRoleToUser t2 WHERE t2.A = '$rid') AND ($aliasFilter) AND $metricFilterU"
        );
        $results[] = ['name' => $row['name'], 'users' => $cnt, 'pct' => (int)$pct,
            'level' => $pct >= 70 ? 'green' : ($pct >= 40 ? 'yellow' : 'red')];
    }
    return $results;
}

// === NUEVO: Distribución de progreso por estado de usuario ===
function getProgressDistribution($pdo, $whereFilter, $metricFilter) {
    try {
        $stmt = $pdo->query("
            SELECT User.id,
                   (SELECT COUNT(DISTINCT lpc.courseId)
                    FROM _TrainingRoleToUser tru
                    JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                    JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                    WHERE tru.B = User.id) as assigned,
                   (SELECT COUNT(DISTINCT cp2.courseId)
                    FROM CourseProgress cp2
                    WHERE cp2.userId = User.id AND cp2.isCompleted = 1) as completed
            FROM User WHERE ($whereFilter) AND ($metricFilter)
        ");
        $dist = ['completado' => 0, 'enProgreso' => 0, 'sinIniciar' => 0, 'sinPlan' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a = (int)$r['assigned'];
            $c = min((int)$r['completed'], $a);
            if ($a === 0)               $dist['sinPlan']++;
            elseif ($c === $a && $a > 0) $dist['completado']++;
            elseif ($c > 0)              $dist['enProgreso']++;
            else                         $dist['sinIniciar']++;
        }
        return $dist;
    } catch (Exception $e) {
        error_log('getProgressDistribution error: ' . $e->getMessage());
        return ['completado' => 0, 'enProgreso' => 0, 'sinIniciar' => 0, 'sinPlan' => 0];
    }
}

// === NUEVO: Usuarios en riesgo de no completar ===
function getAtRiskUsers($pdo, $whereFilter, $metricFilter) {
    try {
        // Usamos subquery para que el WHERE exterior pueda filtrar los aliases
        // (HAVING con aliases de subconsultas no es fiable en MySQL 5.7)
        $stmt = $pdo->query("
            SELECT sub.name, sub.email, sub.daysSince, sub.assigned, sub.completed
            FROM (
                SELECT u.name, u.email,
                       IFNULL(DATEDIFF(NOW(), u.lastLoginAt), 9999) as daysSince,
                       (SELECT COUNT(DISTINCT lpc.courseId)
                        FROM _TrainingRoleToUser tru
                        JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B
                        JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId
                        WHERE tru.B = u.id) as assigned,
                       (SELECT COUNT(DISTINCT cp2.courseId)
                        FROM CourseProgress cp2
                        WHERE cp2.userId = u.id AND cp2.isCompleted = 1) as completed
                FROM User u
                WHERE ($whereFilter) AND ($metricFilter)
                  AND (u.lastLoginAt IS NULL OR u.lastLoginAt < DATE_SUB(NOW(), INTERVAL 7 DAY))
            ) sub
            WHERE sub.assigned > 0
              AND (sub.completed * 100 / sub.assigned) < 20
            ORDER BY sub.daysSince DESC LIMIT 20
        ");
        return array_map(function($r) {
            $a = (int)$r['assigned'];
            $c = min((int)$r['completed'], $a);
            return ['name' => $r['name'], 'email' => $r['email'],
                    'pct'  => $a > 0 ? round(($c / $a) * 100) : 0,
                    'daysSince' => min((int)$r['daysSince'], 9999)];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        return []; // Si falla el query, retorna lista vacía (no rompe la página)
    }
}

// ==========================================
// VARIABLES GLOBALES
// ==========================================
$breadcrumbs       = [];
$entities          = [];
$showBUsGrid       = false;
$topUsers          = [];
$fullUserList      = [];
$assignedCourses   = [];
$topTitle          = "";
$listTitle         = "";
$entityLabel       = "";
$kpiLabel2         = "Usuarios Totales";
$kpiLabel3         = "Cursos Completados";
$kpiVal3           = null;
$kpiFinalization   = ['pct' => 0, 'done' => 0, 'total' => 0];
$engagementStats   = ['active' => 0, 'atRisk' => 0, 'inactive' => 0, 'neverIn' => 0];
$engagementUsers   = ['active' => [], 'atRisk' => [], 'inactive' => [], 'neverIn' => []];
$criticalRolesData = [];
$showKpiExtras     = false;
$progressDist      = ['completado' => 0, 'enProgreso' => 0, 'sinIniciar' => 0, 'sinPlan' => 0];
$atRiskUsers       = [];
$showListCounter   = false;

// ==========================================
// NIVEL 4: EXPEDIENTE INDIVIDUAL
// ==========================================
if ($qUserId) {
    $stmtU = $pdo->prepare("SELECT u.name, u.email, u.image, u.companyId, u.businessUnitId, c.name as cName, b.name as bName FROM User u LEFT JOIN Company c ON u.companyId = c.id LEFT JOIN BusinessUnit b ON u.businessUnitId = b.id WHERE u.id = ?");
    $stmtU->execute([$qUserId]);
    $uData = $stmtU->fetch(PDO::FETCH_ASSOC);
    if (!$uData) { die('Usuario no encontrado'); }

    $studentName  = $uData['name'];
    $studentEmail = $uData['email'];
    $studentImage = $uData['image'] ?? null;
    $companyName = $uData['cName'] ?: 'Corporativo';
    $buName      = $uData['bName'] ?: '(Autónomo)';

    $breadcrumbs[] = ['label' => 'Analíticas', 'url' => 'index.php?view=dashboard'];
    if ($uData['companyId']) {
        $breadcrumbs[] = ['label' => $companyName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($uData['companyId'])];
    }
    if ($uData['businessUnitId']) {
        $breadcrumbs[] = ['label' => $buName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($uData['companyId']) . '&bu_id=' . urlencode($uData['businessUnitId'])];
    }

    $title       = "Expediente: " . htmlspecialchars($studentName);
    $subtitle    = "Desglose de cursos asignados, avance histórico y calificaciones.";
    $entityLabel = "Cursos Asignados";
    $kpiLabel2   = "Promedio Global Exámenes";
    $showBUsGrid = false;

    $stmt = $pdo->prepare("
        SELECT c.id, c.title as name,
               cp.userId, cp.isCompleted, cp.quizPassed, cp.quizScore, cp.quizAttempts, cp.updatedAt,
               (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as totalLessons,
               (SELECT COUNT(lp.id) FROM LessonProgress lp JOIN Lesson l ON lp.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id AND lp.userId = cp.userId AND lp.isCompleted = 1) as completedLessons
        FROM CourseProgress cp JOIN Course c ON cp.courseId = c.id
        WHERE cp.userId = ? ORDER BY c.title ASC
    ");
    $stmt->execute([$qUserId]);
    $assignedCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $kpiEntities = count($assignedCourses);
    $kpiCompleted = 0; $kpiPassed = 0; $sumScore = 0; $countScore = 0;
    foreach ($assignedCourses as $ac) {
        if ($ac['isCompleted']) $kpiCompleted++;
        if ($ac['quizPassed'])  $kpiPassed++;
        if ($ac['quizScore'] !== null) { $sumScore += $ac['quizScore']; $countScore++; }
    }
    $kpiUsers = $countScore > 0 ? round($sumScore / $countScore, 1) . '%' : 'N/A';

// ==========================================
// NIVEL 2 + 3: CON COMPANY_ID
// ==========================================
} else if ($qCompanyId) {
    $stmtCFull = $pdo->prepare("SELECT name, logoPath FROM Company WHERE id = ?");
    $stmtCFull->execute([$qCompanyId]);
    $cRow = $stmtCFull->fetch(PDO::FETCH_ASSOC);
    $companyName = $cRow['name'] ?? 'Compañía';
    $companyLogo = $cRow['logoPath'] ?? null;
    $breadcrumbs[] = ['label' => 'Analíticas', 'url' => 'index.php?view=dashboard'];

    if ($qBuId) {
        // NIVEL 3: BU
        if ($qBuId === 'global') {
            $buName        = 'Corporativo';
            $filterSqlBU   = " AND businessUnitId IS NULL ";
            $filterSqlBU_U = " AND u.businessUnitId IS NULL ";
        } else {
            $stmtBName = $pdo->prepare("SELECT name FROM BusinessUnit WHERE id = ?");
            $stmtBName->execute([$qBuId]);
            $buName        = $stmtBName->fetchColumn() ?: 'Unidad';
            $filterSqlBU   = " AND businessUnitId = '$qBuId' ";
            $filterSqlBU_U = " AND u.businessUnitId = '$qBuId' ";
        }
        $breadcrumbs[] = ['label' => $companyName, 'url' => 'index.php?view=dashboard&company_id=' . urlencode($qCompanyId)];
        $title       = "Métricas — " . htmlspecialchars($buName);
        $subtitle    = "Visión detallada de los estudiantes de esta unidad.";
        $entityLabel = "N/A";

        $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' $filterSqlBU AND $metricFilterUser")->fetchColumn();
        $res    = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$qCompanyId' $filterSqlBU AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);

        $kpiEntities  = 0;
        $kpiUsers     = $uCount;
        $kpiCompleted = $res['comp'] ?: 0;
        $kpiPassed    = $res['pass'] ?: 0;
        $kpiLabel3    = "Avance de Unidad";
        $kpiVal3      = $qBuId === 'global'
            ? getAbsoluteProgressPct($pdo, 'COMPANY', $qCompanyId) . '%'
            : getAbsoluteProgressPct($pdo, 'BU', $qBuId) . '%';

        $showKpiExtras = true;
        $buPF = $qBuId === 'global'
            ? "companyId = '$qCompanyId' AND businessUnitId IS NULL"
            : "companyId = '$qCompanyId' AND businessUnitId = '$qBuId'";
        $buAF = $qBuId === 'global'
            ? "u.companyId = '$qCompanyId' AND u.businessUnitId IS NULL"
            : "u.companyId = '$qCompanyId' AND u.businessUnitId = '$qBuId'";

        $kpiFinalization   = getFinalizationRate($pdo, $buPF, $metricFilterUser);
        $engagementStats   = getEngagementStats($pdo, $buPF, $metricFilterUser);
        $engagementUsers   = getEngagementUsers($pdo, $buPF, $metricFilterUser);
        $criticalRolesData = getCriticalRolesData($pdo, $buPF, $buAF);
        $progressDist      = getProgressDistribution($pdo, $buPF, $metricFilterUser);
        $atRiskUsers       = getAtRiskUsers($pdo, $buPF, $metricFilterUser);

        $topTitle  = "Top 3 Alumnos (" . htmlspecialchars($buName) . ")";
        $listTitle = "Matrícula Completa (" . htmlspecialchars($buName) . ")";

        $topUsers = $pdo->query("
            SELECT u.id, u.name, u.email, u.image, u.totalPoints,
                   COALESCE(SUM(CASE WHEN cp.isCompleted=1 THEN 1 ELSE 0 END), 0) as completedCourses,
                   COALESCE(SUM(cp.quizScore), 0) as totalScore
            FROM User u LEFT JOIN CourseProgress cp ON u.id = cp.userId
            WHERE u.companyId = '$qCompanyId' $filterSqlBU_U AND $metricFilterU
            GROUP BY u.id, u.name, u.email, u.image, u.totalPoints
            ORDER BY u.totalPoints DESC, completedCourses DESC, totalScore DESC LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);

        $fullUserList = $pdo->query("
            SELECT u.id, u.name, u.email, u.totalPoints,
                   (SELECT GROUP_CONCAT(DISTINCT tr.name SEPARATOR ', ') FROM _TrainingRoleToUser tr2u JOIN TrainingRole tr ON tr2u.A = tr.id WHERE tr2u.B = u.id) as trainingRolesNames,
                   (SELECT COUNT(DISTINCT lpc.courseId) FROM _TrainingRoleToUser tru JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId WHERE tru.B = u.id) as assignedCount,
                   (SELECT COUNT(DISTINCT cp.courseId) FROM CourseProgress cp WHERE cp.userId = u.id AND cp.isCompleted = 1) as completedCount
            FROM User u WHERE u.companyId = '$qCompanyId' $filterSqlBU_U AND $metricFilterU ORDER BY u.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // NIVEL 2: EMPRESA
        $stmt = $pdo->prepare("SELECT id, name, logoPath FROM BusinessUnit WHERE companyId = ? AND isActive = 1");
        $stmt->execute([$qCompanyId]);
        $allBUs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($allBUs) > 0) {
            $showBUsGrid = true;
            $title       = "Métricas — " . htmlspecialchars($companyName);
            $subtitle    = "Visión transversal de las Unidades de Negocio de esta empresa.";
            $entityLabel = "Unidades de Negocio";
            $topTitle    = "Top 3 Usuarios de " . htmlspecialchars($companyName);

            foreach ($allBUs as $bu) {
                $buId   = $bu['id'];
                $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser")->fetchColumn();
                $pts    = $pdo->query("SELECT SUM(totalPoints) FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser")->fetchColumn() ?: 0;
                $res    = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass, SUM(quizScore) as scoreSum, COUNT(quizScore) as scoreCount FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE businessUnitId = '$buId' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
                $entities[] = [
                    'id' => $buId, 'name' => $bu['name'], 'logoPath' => $bu['logoPath'],
                    'usersCount' => $uCount, 'points' => $pts,
                    'completed'  => $res['comp'] ?: 0, 'passed' => $res['pass'] ?: 0,
                    'avgScore'   => $res['scoreCount'] > 0 ? round($res['scoreSum'] / $res['scoreCount'], 1) : null,
                    'absolutePct' => getAbsoluteProgressPct($pdo, 'BU', $buId),
                    'dist'        => getProgressDistribution($pdo, "businessUnitId = '$buId'", $metricFilterUser)
                ];
            }

            // Sin Corporativo fantasma — los usuarios sin BU se muestran en la matrícula de nivel corporativo
            usort($entities, function($a, $b) {
                return [$b['absolutePct'], $b['passed'], $b['points']] <=> [$a['absolutePct'], $a['passed'], $a['points']];
            });

            // Matrícula Corporativo: usuarios de la empresa SIN BU asignada
            $uCountCorp = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' AND businessUnitId IS NULL AND $metricFilterUser")->fetchColumn();
            if ($uCountCorp > 0) {
                $listTitle = "Matrícula Corporativo — " . htmlspecialchars($companyName);
                $showListCounter = true;
                $fullUserList = $pdo->query("
                    SELECT u.id, u.name, u.email, u.totalPoints,
                           (SELECT GROUP_CONCAT(DISTINCT tr.name SEPARATOR ', ') FROM _TrainingRoleToUser tr2u JOIN TrainingRole tr ON tr2u.A = tr.id WHERE tr2u.B = u.id) as trainingRolesNames,
                           (SELECT COUNT(DISTINCT lpc.courseId) FROM _TrainingRoleToUser tru JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId WHERE tru.B = u.id) as assignedCount,
                           (SELECT COUNT(DISTINCT cp.courseId) FROM CourseProgress cp WHERE cp.userId = u.id AND cp.isCompleted = 1) as completedCount
                    FROM User u WHERE u.companyId = '$qCompanyId' AND u.businessUnitId IS NULL AND $metricFilterU ORDER BY u.name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            $showBUsGrid = false;
            $title       = "Métricas — " . htmlspecialchars($companyName);
            $subtitle    = "Resumen analítico de desempeño.";
            $entityLabel = "N/A";
            $topTitle    = "Top 3 Usuarios";
            $listTitle   = "Listado General de Usuarios (" . htmlspecialchars($companyName) . ")";
            $fullUserList = $pdo->query("
                SELECT u.id, u.name, u.email, u.totalPoints,
                       (SELECT GROUP_CONCAT(DISTINCT tr.name SEPARATOR ', ') FROM _TrainingRoleToUser tr2u JOIN TrainingRole tr ON tr2u.A = tr.id WHERE tr2u.B = u.id) as trainingRolesNames,
                       (SELECT COUNT(DISTINCT lpc.courseId) FROM _TrainingRoleToUser tru JOIN _LearningPathToTrainingRole lptr ON tru.A = lptr.B JOIN LearningPathCourse lpc ON lptr.A = lpc.learningPathId WHERE tru.B = u.id) as assignedCount,
                       (SELECT COUNT(DISTINCT cp.courseId) FROM CourseProgress cp WHERE cp.userId = u.id AND cp.isCompleted = 1) as completedCount
                FROM User u WHERE u.companyId = '$qCompanyId' AND $metricFilterU ORDER BY u.name ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        }

        $topUsers = $pdo->query("
            SELECT u.id, u.name, u.email, u.image, u.totalPoints,
                   COALESCE(SUM(CASE WHEN cp.isCompleted=1 THEN 1 ELSE 0 END), 0) as completedCourses,
                   COALESCE(SUM(cp.quizScore), 0) as totalScore
            FROM User u LEFT JOIN CourseProgress cp ON u.id = cp.userId
            WHERE u.companyId = '$qCompanyId' AND $metricFilterU
            GROUP BY u.id, u.name, u.email, u.image, u.totalPoints
            ORDER BY u.totalPoints DESC, completedCourses DESC, totalScore DESC LIMIT 3
        ")->fetchAll(PDO::FETCH_ASSOC);

        // KPIs siempre desde la empresa completa (incluye usuarios con y sin BU)
        $kpiCompanyUsers = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$qCompanyId' AND $metricFilterUser")->fetchColumn();
        $kpiCompanyRes   = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$qCompanyId' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
        if (!empty($entities)) {
            $kpiEntities  = count($entities); // solo BUs reales
            $kpiUsers     = $kpiCompanyUsers; // todos los usuarios de la empresa
            $kpiCompleted = $kpiCompanyRes['comp'] ?: 0;
            $kpiPassed    = $kpiCompanyRes['pass'] ?: 0;
            $kpiLabel3    = "Avance Global";
            $kpiVal3      = getAbsoluteProgressPct($pdo, 'COMPANY', $qCompanyId) . '%';
        } else {
            $kpiEntities = 0;
            $kpiUsers    = $kpiCompanyUsers;
            $kpiCompleted = $kpiCompanyRes['comp'] ?: 0;
            $kpiPassed    = $kpiCompanyRes['pass'] ?: 0;
            $kpiLabel3   = "Avance Global";
            $kpiVal3     = getAbsoluteProgressPct($pdo, 'COMPANY_DIRECT', $qCompanyId) . '%';
        }

        $showKpiExtras     = true;
        $kpiFinalization   = getFinalizationRate($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $engagementStats   = getEngagementStats($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $engagementUsers   = getEngagementUsers($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $criticalRolesData = getCriticalRolesData($pdo, "companyId = '$qCompanyId'", "u.companyId = '$qCompanyId'");
        $progressDist      = getProgressDistribution($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
        $atRiskUsers       = getAtRiskUsers($pdo, "companyId = '$qCompanyId'", $metricFilterUser);
    }

// ==========================================
// NIVEL 1: GLOBAL (ADMIN)
// ==========================================
} else {
    $showBUsGrid  = true;
    $stmt         = $pdo->query("SELECT id, name, logoPath FROM Company WHERE isActive = 1");
    $allCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allCompanies as $c) {
        $cid    = $c['id'];
        $bCount = $pdo->query("SELECT COUNT(id) FROM BusinessUnit WHERE companyId = '$cid' AND isActive = 1")->fetchColumn();
        $uCount = $pdo->query("SELECT COUNT(id) FROM User WHERE companyId = '$cid' AND $metricFilterUser")->fetchColumn();
        $ptsC   = $pdo->query("SELECT SUM(totalPoints) FROM User WHERE companyId = '$cid' AND $metricFilterUser")->fetchColumn() ?: 0;
        $res    = $pdo->query("SELECT SUM(CASE WHEN isCompleted=1 THEN 1 ELSE 0 END) as comp, SUM(CASE WHEN quizPassed=1 THEN 1 ELSE 0 END) as pass, SUM(quizScore) as scoreSum, COUNT(quizScore) as scoreCount FROM CourseProgress WHERE userId IN (SELECT id FROM User WHERE companyId = '$cid' AND $metricFilterUser)")->fetch(PDO::FETCH_ASSOC);
        $entities[] = [
            'id' => $cid, 'name' => $c['name'], 'logoPath' => $c['logoPath'],
            'usersCount' => $uCount, 'busCount' => $bCount, 'points' => $ptsC,
            'completed'  => $res['comp'] ?: 0, 'passed' => $res['pass'] ?: 0,
            'avgScore'   => $res['scoreCount'] > 0 ? round($res['scoreSum'] / $res['scoreCount'], 1) : null,
            'absolutePct' => getAbsoluteProgressPct($pdo, 'COMPANY', $cid)
        ];
    }

    // Críticas primero (menor avance = mayor urgencia)
    usort($entities, function($a, $b) {
        return [$a['absolutePct'], $a['passed'], $a['points']] <=> [$b['absolutePct'], $b['passed'], $b['points']];
    });

    $title       = "Analíticas";
    $subtitle    = "Vista global de todas las empresas. Ordenadas por prioridad de atención.";
    $entityLabel = "Empresas";
    $kpiEntities = count($entities);
    $kpiUsers    = array_sum(array_column($entities, 'usersCount'));
    $kpiCompleted = array_sum(array_column($entities, 'completed'));
    $kpiPassed    = array_sum(array_column($entities, 'passed'));
    $kpiLabel3    = "Avance Plataforma";
    $allPcts      = array_column($entities, 'absolutePct');
    $kpiVal3      = (count($allPcts) > 0 ? round(array_sum($allPcts) / count($allPcts), 1) : 0) . '%';
}

// Pre-cálculos para la vista
$engUsersJson = json_encode($engagementUsers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
$maxEng = max($engagementStats['active'], $engagementStats['atRisk'], $engagementStats['inactive'], $engagementStats['neverIn'], 1);
$aBar = $engagementStats['active']   > 0 ? max(round(($engagementStats['active']   / $maxEng) * 85), 12) : 0;
$rBar = $engagementStats['atRisk']   > 0 ? max(round(($engagementStats['atRisk']   / $maxEng) * 85), 12) : 0;
$iBar = $engagementStats['inactive'] > 0 ? max(round(($engagementStats['inactive'] / $maxEng) * 85), 12) : 0;
$nBar = $engagementStats['neverIn']  > 0 ? max(round(($engagementStats['neverIn']  / $maxEng) * 85), 12) : 0;
?>
<style>
/* ── ANALYTICS MODULE ─────────────────────────────────────────────── */
.an-page { padding:0.5rem 0 2rem; }

/* ── Context Header ─────────────────────────────────────────────────── */
.an-ctx-header {
    display: flex; align-items: center; justify-content: space-between;
    gap: 1rem; flex-wrap: wrap;
    background: white; border-radius: 18px;
    border: 1px solid #f1f5f9; box-shadow: 0 2px 6px rgba(0,0,0,0.05);
    padding: 1.1rem 1.4rem; margin-bottom: 1.5rem;
}
.an-ctx-left { display:flex; align-items:center; gap:1rem; min-width:0; }
.an-ctx-logo {
    width: 52px; height: 52px; border-radius: 13px;
    border: 1px solid #e5e7eb; background: #f9fafb;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; flex-shrink: 0;
}
.an-ctx-logo img { width:100%; height:100%; object-fit:contain; }
.an-ctx-logo i { font-size:1.55rem; color:#9ca3af; }
.an-ctx-avatar {
    width: 52px; height: 52px; border-radius: 50%;
    background: linear-gradient(135deg, #f97316, #4a6bb5);
    display: flex; align-items: center; justify-content: center;
    overflow: hidden; flex-shrink: 0; border: 2px solid #e5e7eb;
}
.an-ctx-avatar img { width:100%; height:100%; object-fit:cover; }
.an-ctx-avatar-initials { font-size:1.2rem; font-weight:900; color:white; }
.an-ctx-info { min-width:0; }
.an-ctx-tag { font-size:0.62rem; font-weight:700; color:#f97316; text-transform:uppercase; letter-spacing:0.07em; margin:0 0 0.18rem; }
.an-ctx-name { font-size:1.3rem; font-weight:900; color:#0f172a; margin:0; line-height:1.2; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.an-ctx-sub { font-size:0.73rem; color:#9ca3af; margin:0.15rem 0 0; }
.an-ctx-right { display:flex; align-items:center; gap:0.75rem; flex-shrink:0; }
.an-btn-export { display:inline-flex; align-items:center; gap:0.4rem; padding:0.55rem 1.15rem; background:#0f172a; color:white; border:none; border-radius:10px; font-size:0.8rem; font-weight:700; cursor:pointer; transition:all 0.2s; white-space:nowrap; }
.an-btn-export:hover { background:#1e293b; transform:translateY(-1px); box-shadow:0 4px 12px rgba(0,0,0,0.2); }

/* ── Breadcrumb Nav ───────────────────────────────────────────────── */
.an-breadcrumb {
    display: flex; align-items: center; gap: 0;
    background: #f8fafc; border: 1px solid #e5e7eb;
    border-radius: 10px; padding: 0.4rem 0.75rem;
    width: fit-content; max-width: 100%;
    margin-bottom: 0.75rem; flex-wrap: wrap;
    overflow: hidden;
}
.an-breadcrumb-item {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.74rem; font-weight: 600; color: #6b7280;
    text-decoration: none; padding: 0.1rem 0.45rem;
    border-radius: 6px; transition: background 0.15s, color 0.15s;
    white-space: nowrap;
}
.an-breadcrumb-item:hover { background: #e5e7eb; color: #0f172a; text-decoration: none; }
.an-breadcrumb-item i { font-size: 0.88rem; }
.an-breadcrumb-sep { color: #d1d5db; font-size: 0.75rem; padding: 0 0.05rem; flex-shrink: 0; }
.an-breadcrumb-current {
    display: inline-flex; align-items: center; gap: 0.3rem;
    font-size: 0.74rem; font-weight: 700; color: #0f172a;
    padding: 0.1rem 0.45rem; white-space: nowrap;
}

/* KPI Cards */
.an-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:0.875rem; margin-bottom:1.5rem; }
.an-kpi-card { background:white; border-radius:16px; border:1px solid #f1f5f9; box-shadow:0 2px 6px rgba(0,0,0,0.05); padding:1rem 1.15rem; display:flex; align-items:center; gap:0.8rem; transition:transform 0.2s,box-shadow 0.2s; }
.an-kpi-card:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,0.08); }
.an-kpi-icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.an-kpi-icon i { font-size:1.3rem; }
.an-kpi-label { font-size:0.65rem; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:0.06em; margin:0 0 0.1rem; line-height:1.3; }
.an-kpi-val { font-size:1.65rem; font-weight:900; color:#0f172a; margin:0; line-height:1; }
.an-kpi-sub { font-size:0.65rem; color:#9ca3af; margin:0.2rem 0 0; }

/* Sections */
.an-section { background:white; border-radius:18px; border:1px solid #f1f5f9; box-shadow:0 2px 6px rgba(0,0,0,0.05); padding:1.4rem; margin-bottom:1.4rem; }
.an-section-title { font-size:0.88rem; font-weight:800; color:#0f172a; margin:0 0 1.1rem; display:flex; align-items:center; gap:0.4rem; flex-wrap:wrap; }

/* Charts Row */
.an-charts-row { display:grid; grid-template-columns:1fr 1fr; gap:1.4rem; margin-bottom:1.4rem; align-items:stretch; }
.an-chart-box { background:white; border-radius:18px; border:1px solid #f1f5f9; box-shadow:0 2px 6px rgba(0,0,0,0.05); padding:1.4rem; }

/* Donut */
.an-donut-wrap { display:flex; align-items:center; gap:1.1rem; flex-wrap:wrap; }
.an-donut-legend { display:flex; flex-direction:column; gap:0.45rem; flex:1; min-width:110px; }
.an-legend-item { display:flex; align-items:center; gap:0.5rem; font-size:0.76rem; color:#374151; }
.an-legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.an-legend-item strong { margin-left:auto; font-weight:900; color:#0f172a; padding-left:0.4rem; font-size:0.82rem; }
.an-donut-print-table { display:none; }

/* Engagement - Premium Bar Chart */
.an-eng-chart { position:relative; display:flex; align-items:flex-end; justify-content:space-around; height:120px; padding:0 0.5rem; gap:12px; margin-bottom:0.6rem; border-bottom:2px solid #f1f5f9; }
.an-eng-bar-wrap { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; flex:1; height:100%; cursor:pointer; }
.an-eng-bar-track { width:100%; display:flex; flex-direction:column; justify-content:flex-end; height:80%; position:relative; }
.an-eng-bar { width:100%; border-radius:6px 6px 0 0; transition:all 0.25s ease; transform-origin:bottom; position:relative; min-height:4px; }
.an-eng-bar-wrap:hover .an-eng-bar { filter:brightness(1.1); }
/* Cursor line */
.an-eng-cursor { position:absolute; top:0; left:50%; transform:translateX(-50%); width:1px; height:100%; background:#e2e8f0; pointer-events:none; opacity:0; transition:opacity 0.15s; }
.an-eng-bar-wrap:hover .an-eng-cursor { opacity:1; }
/* Tooltip */
.an-eng-tooltip { position:absolute; bottom:calc(100% + 8px); left:50%; transform:translateX(-50%); background:white; border:1px solid #e5e7eb; border-radius:10px; padding:0.45rem 0.7rem; box-shadow:0 4px 16px rgba(0,0,0,0.1); white-space:nowrap; pointer-events:none; opacity:0; transition:opacity 0.15s, transform 0.15s; z-index:20; font-size:0.72rem; min-width:100px; }
.an-eng-bar-wrap:hover .an-eng-tooltip { opacity:1; transform:translateX(-50%) translateY(-2px); }
.an-eng-tooltip-label { font-weight:800; color:#0f172a; font-size:0.75rem; display:block; margin-bottom:0.2rem; }
.an-eng-tooltip-val { display:flex; align-items:baseline; gap:0.3rem; }
.an-eng-tooltip-num { font-size:1.1rem; font-weight:900; }
.an-eng-tooltip-desc { font-size:0.65rem; color:#9ca3af; display:block; margin-top:0.15rem; }
.an-eng-labels { display:flex; justify-content:space-around; padding:0.35rem 0.5rem 0; gap:10px; margin-bottom:0.3rem; }
.an-eng-labels > div { flex:1; text-align:center; }
.an-eng-lbl-main { display:block; font-size:0.62rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; }
.an-eng-lbl-sub { display:block; font-size:0.56rem; color:#9ca3af; margin-top:0.1rem; }
.an-chart-hint { font-size:0.63rem; color:#9ca3af; display:flex; align-items:center; gap:0.25rem; margin:0.2rem 0 0; }

/* BU Bars */
.an-bu-bars { display:flex; flex-direction:column; gap:0.6rem; }
.an-bu-row { display:grid; grid-template-columns:160px 1fr 120px; gap:0.8rem; align-items:center; }
.an-bu-label { display:flex; align-items:center; gap:0.4rem; font-size:0.8rem; font-weight:600; color:#374151; min-width:0; }
.an-bu-label > span { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.an-bu-bar-track { height:10px; background:#f1f5f9; border-radius:5px; overflow:hidden; display:flex; }
.an-bu-seg { height:100%; }
.an-bu-stats { display:flex; gap:0.4rem; font-size:0.7rem; font-weight:700; white-space:nowrap; justify-content:flex-end; }
.an-bu-legend { display:flex; gap:0.875rem; flex-wrap:wrap; margin-top:0.65rem; padding-top:0.65rem; border-top:1px solid #f3f4f6; font-size:0.7rem; color:#6b7280; }
.an-bu-legend > span { display:flex; align-items:center; gap:0.3rem; }
.an-bu-legend i { width:10px; height:10px; border-radius:2px; display:inline-block; flex-shrink:0; }
/* Duo grid 2/3 + 1/3 */
.an-duo-grid { display:grid; grid-template-columns:2fr 1fr; gap:1.4rem; margin-bottom:1.4rem; align-items:stretch; }
@media (max-width:900px) { .an-duo-grid { grid-template-columns:1fr; } }

/* Health */
.an-health-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; display:inline-block; }
.an-health-dot.an-health-green  { background:#16a34a; }
.an-health-dot.an-health-yellow { background:#f59e0b; }
.an-health-dot.an-health-red    { background:#ef4444; }
.an-health-badge { font-size:0.65rem; font-weight:700; padding:0.18rem 0.5rem; border-radius:999px; white-space:nowrap; }
.an-health-badge.an-health-green  { background:#dcfce7; color:#15803d; }
.an-health-badge.an-health-yellow { background:#fef9c3; color:#a16207; }
.an-health-badge.an-health-red    { background:#fee2e2; color:#b91c1c; }

/* At-Risk premium */
.an-risk-badge { background:#ef4444; color:white; border-radius:999px; font-size:0.65rem; padding:0.12rem 0.45rem; margin-left:0.25rem; font-weight:800; }
.an-risk-list { display:flex; flex-direction:column; gap:0.45rem; }
.an-risk-row2 { display:flex; align-items:center; gap:0.6rem; padding:0.55rem 0.7rem; background:#fff8f8; border-radius:10px; border:1px solid #fde8e8; transition:background 0.15s; }
.an-risk-row2:hover { background:#fff0f0; }
.an-risk-initials { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.72rem; font-weight:800; letter-spacing:-0.03em; }
.an-risk-info { flex:1; min-width:0; }
.an-risk-name { font-size:0.78rem; font-weight:700; color:#0f172a; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.an-risk-subrow { display:flex; align-items:center; gap:0.35rem; margin-top:3px; }
.an-risk-bar-track { flex:1; height:3px; background:#f1f5f9; border-radius:99px; overflow:hidden; }
.an-risk-bar-fill { height:100%; background:#ef4444; border-radius:99px; }
.an-risk-pct2 { font-size:0.68rem; font-weight:800; color:#dc2626; white-space:nowrap; }
.an-risk-right { display:flex; flex-direction:column; align-items:center; gap:2px; flex-shrink:0; }
.an-risk-days-num { font-size:0.9rem; font-weight:900; color:#b91c1c; line-height:1; }
.an-risk-days-lbl { font-size:0.55rem; color:#9ca3af; white-space:nowrap; }
.an-severity-badge { font-size:0.58rem; font-weight:700; padding:0.1rem 0.35rem; border-radius:999px; white-space:nowrap; }
.an-severity-critico  { background:#fee2e2; color:#b91c1c; }
.an-severity-urgente  { background:#fef3c7; color:#b45309; }

/* Critical Roles */
.an-roles-list { display:flex; flex-direction:column; gap:0.6rem; }
.an-role-row { display:grid; grid-template-columns:185px 1fr auto; gap:0.8rem; align-items:center; }
.an-role-label { font-size:0.8rem; font-weight:600; color:#374151; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.an-role-bar-wrap { height:8px; background:#f1f5f9; border-radius:4px; overflow:hidden; }
.an-role-bar-fill { height:100%; border-radius:4px; }
.an-role-meta { display:flex; align-items:center; gap:0.35rem; justify-content:flex-end; }
.an-role-badge { font-size:0.7rem; font-weight:800; padding:0.18rem 0.45rem; border-radius:6px; white-space:nowrap; }
.an-role-users { font-size:0.66rem; color:#9ca3af; white-space:nowrap; }

/* Entity Grid */
.an-entity-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:1rem; }
.an-entity-card { background:white; border-radius:16px; border:1px solid #f1f5f9; box-shadow:0 2px 6px rgba(0,0,0,0.05); padding:1.1rem; text-decoration:none; color:inherit; display:flex; flex-direction:column; gap:0.65rem; transition:transform 0.25s,box-shadow 0.25s; }
.an-entity-card:hover { transform:translateY(-4px); box-shadow:0 10px 24px rgba(0,0,0,0.09); }
.an-ec-header { display:flex; justify-content:space-between; align-items:center; }
.an-ec-logo { width:40px; height:40px; border-radius:9px; border:1px solid #e5e7eb; background:#f9fafb; display:flex; align-items:center; justify-content:center; overflow:hidden; flex-shrink:0; }
.an-ec-logo img { width:100%; height:100%; object-fit:contain; }
.an-ec-name { font-size:0.98rem; font-weight:800; color:#0f172a; margin:0; line-height:1.3; }
.an-ec-meta { display:flex; gap:0.6rem; flex-wrap:wrap; }
.an-ec-meta span { font-size:0.72rem; color:#6b7280; display:flex; align-items:center; gap:0.2rem; }
.an-ec-progress-label { display:flex; justify-content:space-between; font-size:0.68rem; font-weight:700; color:#6b7280; }
.an-ec-progress-track { height:5px; background:#f1f5f9; border-radius:3px; overflow:hidden; }
.an-ec-progress-fill { height:100%; border-radius:3px; }
.an-pf-green { background:#16a34a; } .an-pf-yellow { background:#f59e0b; } .an-pf-red { background:#ef4444; }
.an-ec-link { font-size:0.7rem; color:#f97316; font-weight:700; margin-top:auto; }
.an-entity-card:hover .an-ec-link { text-decoration:underline; }

/* Top 3 */
.an-top3-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(195px,1fr)); gap:1rem; }
.an-top-card { background:#f9fafb; border-radius:16px; border:2px solid var(--rk,#e5e7eb); padding:1.35rem 1.1rem; text-align:center; display:flex; flex-direction:column; align-items:center; gap:0.55rem; }
.an-top-rank { font-size:0.78rem; font-weight:800; color:#6b7280; display:flex; align-items:center; justify-content:center; gap:0.25rem; }
.an-top-avatar { width:50px; height:50px; border-radius:50%; border:3px solid var(--rk,#e5e7eb); background:white; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.an-top-avatar i { font-size:1.4rem; color:#9ca3af; }
.an-top-avatar img { width:100%; height:100%; object-fit:cover; }
.an-top-name { font-size:0.88rem; font-weight:800; color:#0f172a; margin:0; word-break:break-word; }
.an-top-email { font-size:0.67rem; color:#9ca3af; margin:0; word-break:break-all; }
.an-top-stats { display:flex; gap:0.875rem; }
.an-top-stats div { text-align:center; }
.an-top-stats strong { font-size:1rem; font-weight:900; display:block; }
.an-top-stats small { font-size:0.58rem; color:#9ca3af; text-transform:uppercase; font-weight:700; letter-spacing:0.04em; }

/* User Table */
.an-user-table-wrap { overflow-x:auto; overflow-y:auto; max-height:480px; border-radius:12px; border:1px solid #e5e7eb; }
.an-user-table { width:100%; border-collapse:collapse; font-size:0.8rem; }
.an-user-table thead { position:sticky; top:0; z-index:1; }
.an-user-table thead tr { background:#f9fafb; border-bottom:2px solid #e5e7eb; }
.an-user-table th { padding:0.75rem 0.875rem; text-align:left; font-size:0.65rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; white-space:nowrap; }
.an-user-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background 0.12s; }
.an-user-table tbody tr:hover { background:#fafbfc; }
.an-user-table td { padding:0.75rem 0.875rem; vertical-align:top; }
.an-user-link { display:flex; align-items:center; gap:0.6rem; text-decoration:none; color:inherit; }
.an-user-avatar { width:32px; height:32px; border-radius:9px; background:linear-gradient(135deg,#f97316,#ea580c); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.an-user-avatar i { color:white; font-size:0.9rem; }
.an-user-name { font-size:0.8rem; font-weight:700; color:#0f172a; margin:0; }
.an-user-email { font-size:0.65rem; color:#9ca3af; margin:0; }
.an-user-pct { font-size:0.92rem; font-weight:900; line-height:1; }
.an-user-progress { width:100%; height:4px; background:#f1f5f9; border-radius:3px; overflow:hidden; margin:0.2rem 0 0.12rem; }
.an-user-count { font-size:0.63rem; color:#9ca3af; }
.an-badge-no-plan { font-size:0.65rem; background:#f1f5f9; color:#9ca3af; padding:0.15rem 0.45rem; border-radius:5px; }
.an-roles-wrap { display:flex; flex-wrap:wrap; gap:0.27rem; }
.an-role-tag { font-size:0.65rem; font-weight:600; background:#ede9fe; color:#7c3aed; padding:0.15rem 0.4rem; border-radius:5px; }
.an-courses-mini { display:flex; flex-direction:column; gap:0.27rem; }
.an-course-mini-row { display:grid; grid-template-columns:15px 1fr auto auto; gap:0.3rem; align-items:center; font-size:0.7rem; }
.an-course-mini-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#374151; }
.an-course-mini-stat { color:#9ca3af; white-space:nowrap; }
.an-course-mini-score { font-weight:700; white-space:nowrap; }

/* Informe */
.an-informe { background:#f8fafc; border-radius:14px; border:1px solid #e2e8f0; padding:1.35rem; margin-bottom:1.35rem; }
.an-informe-text { font-size:0.855rem; line-height:1.75; color:#374151; margin:0 0 0.55rem; }
.an-informe-date { font-size:0.65rem; color:#9ca3af; margin:0; display:flex; align-items:center; gap:0.25rem; }

/* Level 4 Courses */
.an-course-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:1rem; }
.an-course-card { border-radius:14px; border:2px solid; padding:1rem; display:flex; flex-direction:column; gap:0.6rem; }
.an-badge-status { font-size:0.7rem; font-weight:700; padding:0.2rem 0.5rem; border-radius:999px; display:inline-flex; align-items:center; gap:0.25rem; }
.an-badge-done { background:#dcfce7; color:#15803d; }
.an-badge-progress { background:#dbeafe; color:#1d4ed8; }
.an-badge-pending { background:#f1f5f9; color:#6b7280; }
.an-cc-name { font-size:0.88rem; font-weight:700; color:#0f172a; margin:0; line-height:1.4; }
.an-cc-stats { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.35rem; text-align:center; }
.an-cc-stats > div { background:white; border-radius:7px; padding:0.35rem; border:1px solid rgba(0,0,0,0.05); }
.an-cc-stats strong { font-size:0.9rem; font-weight:900; color:#0f172a; display:block; }
.an-cc-stats small { font-size:0.56rem; color:#9ca3af; text-transform:uppercase; font-weight:700; letter-spacing:0.04em; }
.an-cc-bar { height:5px; background:#f1f5f9; border-radius:3px; overflow:hidden; }

/* Print */
@media print {
    .an-btn-export,.sidebar,.nav-sidebar,.top-bar,.modal-overlay,.an-chart-hint,#engModal,nav { display:none !important; }
    .an-page { padding:0; }
    .an-kpi-card,.an-section,.an-chart-box,.an-entity-card { box-shadow:none !important; border:1px solid #e5e7eb !important; }
    .an-charts-row { grid-template-columns:1fr 1fr; }
    canvas#progressDonut { display:none; }
    .an-donut-print-table { display:table !important; width:100%; border-collapse:collapse; font-size:11px; }
    .an-donut-print-table td,.an-donut-print-table th { border:1px solid #ccc; padding:4px 8px; }
    .an-entity-grid { grid-template-columns:repeat(3,1fr); }
    .an-top3-grid { grid-template-columns:repeat(3,1fr); }
    .an-risk-list { max-height:none; overflow:visible; }
}

/* Responsive */
@media (max-width:900px) { .an-charts-row { grid-template-columns:1fr; } .an-bu-row { grid-template-columns:130px 1fr; } .an-bu-stats { display:none; } }
@media (max-width:640px) { .an-kpi-grid { grid-template-columns:1fr 1fr; } .an-kpi-val { font-size:1.35rem; } .an-risk-row { grid-template-columns:34px 1fr auto; } .an-risk-days { display:none; } .an-role-row { grid-template-columns:1fr 70px; } .an-role-label { grid-column:1/-1; } .an-entity-grid { grid-template-columns:1fr; } .an-top3-grid { grid-template-columns:1fr; } .an-bu-row { grid-template-columns:1fr; } }

/* ── Diagnóstico de empresas ─────────────────────────────────────────── */
.an-diag-wrap {
    background: white; border-radius: 16px; border: 1px solid #f1f5f9;
    box-shadow: 0 2px 6px rgba(0,0,0,0.05); padding: 1.1rem 1.4rem;
    margin-bottom: 1.25rem;
}
.an-diag-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 0.9rem;
}
.an-diag-total { font-size: 0.72rem; font-weight: 600; color: #9ca3af; }
.an-diag-counts {
    display: flex; gap: 1.5rem; margin-bottom: 0.75rem;
}
.an-diag-count { display: flex; flex-direction: column; }
.an-diag-num { font-size: 1.6rem; font-weight: 900; line-height: 1; }
.an-diag-lbl { font-size: 0.68rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; margin-top: 0.15rem; opacity: 0.85; }
.an-diag-bar {
    display: flex; height: 10px; border-radius: 999px;
    overflow: hidden; gap: 2px; margin-bottom: 0.6rem;
    background: #f1f5f9;
}
.an-diag-seg { height: 100%; transition: width 0.4s ease; border-radius: 2px; }
.an-diag-legend { display: flex; gap: 1rem; flex-wrap: wrap; }
.an-diag-legend span { display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.72rem; font-weight: 600; color: #6b7280; }
.an-diag-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* ── Empty State ──────────────────────────────────────────────────── */
.an-empty-state {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 2.5rem 1rem; gap: 0.6rem; text-align: center;
}
.an-empty-state i { font-size: 2rem; color: #d1d5db; }
.an-empty-state p { font-size: 0.82rem; color: #9ca3af; font-weight: 500; margin: 0; }
.an-empty-state small { font-size: 0.72rem; color: #c4c9d4; }
</style>

<div class="an-page" id="analiticas-report">

    <!-- BREADCRUMB -->
    <?php if (!empty($breadcrumbs)): ?>
    <nav class="an-breadcrumb" aria-label="Ruta de navegación">
        <?php foreach ($breadcrumbs as $idx => $b): ?>
            <?php if ($idx > 0): ?>
            <span class="an-breadcrumb-sep"><i class='bx bx-chevron-right'></i></span>
            <?php endif; ?>
            <a href="<?= $b['url'] ?>" class="an-breadcrumb-item"><?= htmlspecialchars($b['label']) ?></a>
        <?php endforeach; ?>
        <span class="an-breadcrumb-sep"><i class='bx bx-chevron-right'></i></span>
        <span class="an-breadcrumb-current">
            <?= htmlspecialchars($qUserId ? $studentName : ($qBuId ? ($buName ?? '') : ($qCompanyId ? $companyName : ''))) ?>
        </span>
    </nav>
    <?php endif; ?>

    <!-- CONTEXT HEADER -->
    <div class="an-ctx-header">
        <div class="an-ctx-left">

            <?php if ($qUserId): ?>
            <!-- Nivel 4: Expediente de usuario -->
            <div class="an-ctx-avatar">
                <?php if (!empty($studentImage)): ?>
                    <img src="<?= htmlspecialchars($studentImage) ?>" alt="">
                <?php else: ?>
                    <span class="an-ctx-avatar-initials"><?= htmlspecialchars(strtoupper(mb_substr($studentName, 0, 1))) ?></span>
                <?php endif; ?>
            </div>
            <div class="an-ctx-info">
                <p class="an-ctx-tag"><i class='bx bx-user'></i> Expediente de usuario</p>
                <h1 class="an-ctx-name"><?= htmlspecialchars($studentName) ?></h1>
                <p class="an-ctx-sub"><?= htmlspecialchars($studentEmail ?? '') ?></p>
            </div>

            <?php elseif ($qBuId): ?>
            <!-- Nivel 3: Unidad de negocio -->
            <div class="an-ctx-logo">
                <i class='bx bx-sitemap'></i>
            </div>
            <div class="an-ctx-info">
                <p class="an-ctx-tag"><i class='bx bx-building'></i> Unidad de negocio &mdash; <?= htmlspecialchars($companyName) ?></p>
                <h1 class="an-ctx-name"><?= htmlspecialchars($buName ?? '') ?></h1>
                <p class="an-ctx-sub"><?= htmlspecialchars($subtitle) ?></p>
            </div>

            <?php elseif ($qCompanyId): ?>
            <!-- Nivel 2: Empresa -->
            <div class="an-ctx-logo">
                <?php if (!empty($companyLogo) && file_exists($companyLogo)): ?>
                    <img src="<?= htmlspecialchars($companyLogo) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <i class='bx bx-buildings' style="display:none;"></i>
                <?php else: ?>
                    <i class='bx bx-buildings'></i>
                <?php endif; ?>
            </div>
            <div class="an-ctx-info">
                <p class="an-ctx-tag"><i class='bx bx-bar-chart-alt-2'></i> Analíticas de empresa</p>
                <h1 class="an-ctx-name"><?= htmlspecialchars($companyName) ?></h1>
                <p class="an-ctx-sub"><?= htmlspecialchars($subtitle) ?></p>
            </div>

            <?php else: ?>
            <!-- Nivel 1: Vista global -->
            <div class="an-ctx-logo" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-color:#c7d2fe;">
                <i class='bx bx-globe' style="color:#6366f1;"></i>
            </div>
            <div class="an-ctx-info">
                <p class="an-ctx-tag"><i class='bx bx-bar-chart-alt-2'></i> Panel de control</p>
                <h1 class="an-ctx-name">Analíticas</h1>
                <p class="an-ctx-sub"><?= htmlspecialchars($subtitle) ?></p>
            </div>
            <?php endif; ?>

        </div>
        <div class="an-ctx-right">
            <?php if ($qCompanyId): ?>
            <button onclick="exportPDF()" class="an-btn-export" id="btnExportPDF">
                <i class='bx bxs-file-pdf' style='font-size:1.1rem;'></i> Exportar PDF
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- KPI CARDS -->
    <div class="an-kpi-grid">
        <?php if ($entityLabel !== 'N/A' && !empty($kpiEntities) && $kpiEntities > 0): ?>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#ede9fe;"><i class='bx bx-buildings' style="color:#7c3aed;"></i></div>
            <div><p class="an-kpi-label"><?= htmlspecialchars($entityLabel) ?></p><p class="an-kpi-val"><?= number_format($kpiEntities) ?></p></div>
        </div>
        <?php endif; ?>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#dbeafe;"><i class='bx bx-group' style="color:#1d4ed8;"></i></div>
            <div><p class="an-kpi-label"><?= $kpiLabel2 ?></p><p class="an-kpi-val"><?= $kpiLabel2 === 'Usuarios Totales' ? number_format($kpiUsers) : $kpiUsers ?></p></div>
        </div>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#dcfce7;"><i class='bx bx-trending-up' style="color:#15803d;"></i></div>
            <div><p class="an-kpi-label"><?= $kpiLabel3 ?></p><p class="an-kpi-val"><?= $kpiVal3 !== null ? $kpiVal3 : number_format($kpiCompleted) ?></p></div>
        </div>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#d1fae5;"><i class='bx bxs-check-circle' style="color:#059669;"></i></div>
            <div><p class="an-kpi-label">Exámenes Aprobados</p><p class="an-kpi-val"><?= number_format($kpiPassed) ?></p></div>
        </div>
        <?php if ($showKpiExtras && $kpiFinalization['total'] > 0): ?>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#fce7f3;"><i class='bx bxs-flag-checkered' style="color:#be185d;"></i></div>
            <div><p class="an-kpi-label">Tasa Finalización</p><p class="an-kpi-val"><?= $kpiFinalization['pct'] ?>%</p><p class="an-kpi-sub"><?= $kpiFinalization['done'] ?>/<?= $kpiFinalization['total'] ?> cursos</p></div>
        </div>
        <?php endif; ?>
        <?php if ($showKpiExtras && $progressDist['completado'] > 0): ?>
        <div class="an-kpi-card">
            <div class="an-kpi-icon" style="background:#f0fdf4;"><i class='bx bx-award' style="color:#16a34a;"></i></div>
            <div><p class="an-kpi-label">Completaron su ruta</p><p class="an-kpi-val"><?= $progressDist['completado'] ?></p><p class="an-kpi-sub">al 100% ✓</p></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- GRID DE ENTIDADES (L1: empresas, L2: BUs) -->
    <?php if ($showBUsGrid && !empty($entities)): ?>
    <div class="an-section">
    <!-- DIAGNÓSTICO DE EMPRESAS (solo nivel 1) -->
    <?php if (!$qCompanyId):
        $crit  = 0; $warn = 0; $ok = 0;
        foreach ($entities as $e) {
            $p = max(round($e['absolutePct']), 0);
            if ($p >= 70) $ok++;
            elseif ($p >= 40) $warn++;
            else $crit++;
        }
        $total = max($crit + $warn + $ok, 1);
        $pCrit = round($crit / $total * 100);
        $pWarn = round($warn / $total * 100);
        $pOk   = max(100 - $pCrit - $pWarn, 0);
    ?>
    <div class="an-diag-wrap">
        <div class="an-diag-header">
            <span class="an-section-title" style="margin:0;"><i class='bx bx-pulse'></i> Diagnóstico de empresas</span>
            <span class="an-diag-total"><?= $total ?> empresa<?= $total !== 1 ? 's' : '' ?></span>
        </div>
        <div class="an-diag-counts">
            <?php if ($crit > 0): ?>
            <div class="an-diag-count" style="color:#dc2626;">
                <span class="an-diag-num"><?= $crit ?></span>
                <span class="an-diag-lbl">Crítica<?= $crit !== 1 ? 's' : '' ?></span>
            </div>
            <?php endif; ?>
            <?php if ($warn > 0): ?>
            <div class="an-diag-count" style="color:#d97706;">
                <span class="an-diag-num"><?= $warn ?></span>
                <span class="an-diag-lbl">En atención</span>
            </div>
            <?php endif; ?>
            <?php if ($ok > 0): ?>
            <div class="an-diag-count" style="color:#16a34a;">
                <span class="an-diag-num"><?= $ok ?></span>
                <span class="an-diag-lbl">En control</span>
            </div>
            <?php endif; ?>
        </div>
        <div class="an-diag-bar">
            <?php if ($pCrit > 0): ?><div class="an-diag-seg" style="width:<?= $pCrit ?>%;background:#ef4444;" title="<?= $crit ?> críticas (<?= $pCrit ?>%)"></div><?php endif; ?>
            <?php if ($pWarn > 0): ?><div class="an-diag-seg" style="width:<?= $pWarn ?>%;background:#f59e0b;" title="<?= $warn ?> en atención (<?= $pWarn ?>%)"></div><?php endif; ?>
            <?php if ($pOk  > 0): ?><div class="an-diag-seg" style="width:<?= $pOk ?>%;background:#22c55e;" title="<?= $ok ?> en control (<?= $pOk ?>%)"></div><?php endif; ?>
        </div>
        <div class="an-diag-legend">
            <?php if ($crit > 0): ?><span><span class="an-diag-dot" style="background:#ef4444;"></span> <?= $pCrit ?>% Críticas</span><?php endif; ?>
            <?php if ($warn > 0): ?><span><span class="an-diag-dot" style="background:#f59e0b;"></span> <?= $pWarn ?>% Atención</span><?php endif; ?>
            <?php if ($ok  > 0): ?><span><span class="an-diag-dot" style="background:#22c55e;"></span> <?= $pOk ?>% En control</span><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

        <h3 class="an-section-title">
            <?= $qCompanyId ? "<i class='bx bx-sitemap'></i> Unidades de Negocio" : "<i class='bx bx-buildings'></i> Empresas" ?>
            <?php if (!$qCompanyId): ?><span style="font-size:0.7rem;font-weight:500;color:#9ca3af;">&nbsp;— Ordenadas por prioridad de atención</span><?php endif; ?>
        </h3>
        <div class="an-entity-grid">
            <?php foreach ($entities as $e):
                $pct    = max(round($e['absolutePct']),0);
                $hlth   = $pct>=70?'green':($pct>=40?'yellow':'red');
                $hlthLbl = $pct>=70?'En control':($pct>=40?'Atención':'Crítico');
                $href   = !$qCompanyId
                    ? 'index.php?view=dashboard&company_id='.urlencode($e['id'])
                    : 'index.php?view=dashboard&company_id='.urlencode($qCompanyId).'&bu_id='.urlencode($e['id']);
            ?>
            <a href="<?= $href ?>" class="an-entity-card">
                <div class="an-ec-header">
                    <div class="an-ec-logo">
                        <?php if (!empty($e['logoPath']) && file_exists($e['logoPath'])): ?>
                        <img src="<?= htmlspecialchars($e['logoPath']) ?>" alt="" onerror="this.style.display='none';">
                        <?php else: ?>
                        <i class='bx bx-building-house' style="font-size:1.2rem;color:#9ca3af;"></i>
                        <?php endif; ?>
                    </div>
                    <span class="an-health-badge an-health-<?= $hlth ?>"><?= $hlthLbl ?></span>
                </div>
                <p class="an-ec-name"><?= htmlspecialchars($e['name']) ?></p>
                <div class="an-ec-meta">
                    <span><i class='bx bx-group'></i> <?= number_format($e['usersCount']) ?> usuarios</span>
                    <?php if (isset($e['busCount'])): ?><span><i class='bx bx-sitemap'></i> <?= $e['busCount'] ?> BUs</span><?php endif; ?>
                    <?php if (!empty($e['avgScore'])): ?><span><i class='bx bx-notepad'></i> <?= $e['avgScore'] ?>% score</span><?php endif; ?>
                </div>
                <div class="an-ec-progress-label"><span>Avance</span><span><?= $pct ?>%</span></div>
                <div class="an-ec-progress-track"><div class="an-ec-progress-fill an-pf-<?= $hlth ?>" style="width:<?= $pct ?>%;"></div></div>
                <span class="an-ec-link"><?= $qCompanyId?'Ver matrícula →':'Ver detalle →' ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- L2/L3: CHARTS ROW -->
    <?php if ($showKpiExtras): ?>
    <div class="an-charts-row">

        <!-- Distribución de Progreso -->
        <div class="an-chart-box">
            <h3 class="an-section-title"><i class='bx bx-pie-chart-alt-2'></i> Distribución de Progreso</h3>
            <?php
                $pdItems  = [
                    ['label'=>'Completado',  'val'=>(int)$progressDist['completado'],  'color'=>'#16a34a'],
                    ['label'=>'En progreso', 'val'=>(int)$progressDist['enProgreso'],  'color'=>'#f59e0b'],
                    ['label'=>'Sin iniciar', 'val'=>(int)$progressDist['sinIniciar'],  'color'=>'#ef4444'],
                    ['label'=>'Sin ruta',    'val'=>(int)$progressDist['sinPlan'],     'color'=>'#94a3b8'],
                ];
                $pdRealTotal = array_sum(array_column($pdItems,'val'));
                // Total real de usuarios (incluye los que nunca han entrado)
                $pdAllUsers  = (int)$engagementStats['active'] + (int)$engagementStats['atRisk']
                             + (int)$engagementStats['inactive'] + (int)$engagementStats['neverIn'];
                $iPctComp    = $pdRealTotal > 0 ? round($progressDist['completado']/$pdRealTotal*100) : 0;
                $iCtx        = $qBuId ? ($buName??'') : ($companyName??'');
            ?>

            <!-- Dona centrada (patrón referencia) -->
            <div style="display:flex;justify-content:center;margin:0.4rem 0 1.2rem;">
                <div style="position:relative;width:160px;height:160px;">
                    <canvas id="progressDonut" width="160" height="160"></canvas>
                    <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
                        <span style="font-size:1.7rem;font-weight:800;color:#0f172a;line-height:1;"><?= $pdAllUsers ?></span>
                        <span style="font-size:0.63rem;color:#9ca3af;font-weight:500;margin-top:3px;letter-spacing:0.03em;">USUARIOS</span>
                    </div>
                </div>
            </div>

            <!-- Leyenda estilo referencia: dot · nombre · número · barra full -->
            <div style="display:flex;flex-direction:column;gap:0.6rem;margin-bottom:1rem;">
                <?php foreach ($pdItems as $pd):
                    $pct = $pdRealTotal > 0 ? round($pd['val']/$pdRealTotal*100) : 0;
                ?>
                <div>
                    <div style="display:flex;align-items:center;gap:0.45rem;margin-bottom:4px;">
                        <span style="width:7px;height:7px;border-radius:50%;background:<?= $pd['color'] ?>;flex-shrink:0;"></span>
                        <span style="font-size:0.77rem;color:#6b7280;flex:1;"><?= $pd['label'] ?></span>
                        <span style="font-size:0.77rem;font-weight:700;color:#0f172a;"><?= $pd['val'] ?></span>
                        <span style="font-size:0.7rem;color:#9ca3af;min-width:28px;text-align:right;"><?= $pct ?>%</span>
                    </div>
                    <div style="height:3px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                        <div style="height:100%;border-radius:99px;background:<?= $pd['color'] ?>;width:<?= $pct ?>%;transition:width 0.7s ease;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Resumen integrado -->
            <div style="padding-top:0.85rem;border-top:1px solid #f1f5f9;">
                <p style="font-size:0.73rem;font-weight:700;color:#0f172a;margin:0 0 0.5rem;display:flex;align-items:center;gap:0.28rem;"><i class='bx bx-file-blank' style="color:#94a3b8;font-size:0.85rem;"></i> Resumen</p>
                <?php
                    $pComp  = $pdRealTotal > 0 ? round($progressDist['completado'] /$pdRealTotal*100) : 0;
                    $pProg  = $pdRealTotal > 0 ? round($progressDist['enProgreso'] /$pdRealTotal*100) : 0;
                    $pSin   = $pdRealTotal > 0 ? round($progressDist['sinIniciar'] /$pdRealTotal*100) : 0;
                    $pNoPlan= $pdRealTotal > 0 ? round($progressDist['sinPlan']    /$pdRealTotal*100) : 0;
                ?>
                <p class="an-informe-text" style="font-size:0.75rem;line-height:1.7;margin:0 0 0.55rem;">
                    <strong><?= htmlspecialchars($iCtx) ?></strong> cuenta con <strong><?= $pdAllUsers ?></strong> usuario<?= $pdAllUsers!==1?'s':'' ?> en el Hub Eurosoft.
                    <?php if ($pdRealTotal > 0): ?>
                    <br>
                    <span style="color:#16a34a;">●</span> <strong><?= $progressDist['completado'] ?></strong> completaron su ruta&nbsp;<span style="color:#9ca3af;">(<?= $pComp ?>%)</span><?php if ($progressDist['enProgreso']>0): ?> &nbsp;·&nbsp;
                    <span style="color:#f59e0b;">●</span> <strong><?= $progressDist['enProgreso'] ?></strong> en progreso&nbsp;<span style="color:#9ca3af;">(<?= $pProg ?>%)</span><?php endif; ?><?php if ($progressDist['sinIniciar']>0): ?> &nbsp;·&nbsp;
                    <span style="color:#ef4444;">●</span> <strong><?= $progressDist['sinIniciar'] ?></strong> sin iniciar&nbsp;<span style="color:#9ca3af;">(<?= $pSin ?>%)</span><?php endif; ?><?php if ($progressDist['sinPlan']>0): ?> &nbsp;·&nbsp;
                    <span style="color:#94a3b8;">●</span> <strong><?= $progressDist['sinPlan'] ?></strong> sin ruta asignada&nbsp;<span style="color:#9ca3af;">(<?= $pNoPlan ?>%)</span><?php endif; ?>.
                    <?php else: ?>
                    <br>Ningún usuario tiene cursos asignados aún.
                    <?php endif; ?>
                    <?php if (!empty($atRiskUsers)): ?>
                    <br><span style="color:#dc2626;">⚠</span> <strong style="color:#dc2626;"><?= count($atRiskUsers) ?> usuario<?= count($atRiskUsers)!==1?'s':'' ?> en situación crítica</strong> — con ruta asignada, menos del 20% de avance y sin actividad por más de 7 días.
                    <?php else: ?>
                    <br><span style="color:#16a34a;">✓</span> Sin usuarios en situación crítica.
                    <?php endif; ?>
                </p>
                <p class="an-informe-date"><i class='bx bx-calendar'></i> <?= date('d/m/Y \a \l\a\s H:i') ?></p>
            </div>

            <table class="an-donut-print-table">
                <thead><tr><th>Estado</th><th>Usuarios</th><th>%</th></tr></thead>
                <tbody>
                    <?php foreach ($pdItems as $pd): ?>
                    <tr><td><?= $pd['label'] ?></td><td><?= $pd['val'] ?></td><td><?= $pdRealTotal>0?round($pd['val']/$pdRealTotal*100):0 ?>%</td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>



        <!-- Actividad Reciente -->
        <div class="an-chart-box" style="display:flex;flex-direction:column;">
            <h3 class="an-section-title"><i class='bx bx-history'></i> Actividad Reciente</h3>
            <?php
                $engBars = [
                    ['key'=>'active',   'val'=>(int)$engagementStats['active'],   'label'=>'Activos',    'sub'=>'Últimos 3 días',    'color'=>'#16a34a', 'alpha'=>'rgba(22,163,74,0.15)'],
                    ['key'=>'atRisk',   'val'=>(int)$engagementStats['atRisk'],   'label'=>'En riesgo',  'sub'=>'Entre 4 y 10 días', 'color'=>'#d97706', 'alpha'=>'rgba(217,119,6,0.15)'],
                    ['key'=>'inactive', 'val'=>(int)$engagementStats['inactive'], 'label'=>'Inactivos',  'sub'=>'Más de 10 días',    'color'=>'#dc2626', 'alpha'=>'rgba(220,38,38,0.15)'],
                    ['key'=>'neverIn',  'val'=>(int)$engagementStats['neverIn'],  'label'=>'Sin acceso', 'sub'=>'Sin registro',      'color'=>'#64748b', 'alpha'=>'rgba(100,116,139,0.15)'],
                ];
            ?>
            <div style="position:relative;flex:1;min-height:120px;margin-bottom:0.75rem;">
                <canvas id="engChart"></canvas>
            </div>
            <div class="an-eng-labels">
                <?php foreach ($engBars as $b): ?>
                <div onclick="engToggle('<?= $b['key'] ?>')" style="cursor:pointer;">
                    <span class="an-eng-lbl-main" style="color:<?= $b['color'] ?>"><?= $b['label'] ?></span>
                    <span class="an-eng-lbl-sub"><?= $b['sub'] ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="an-chart-hint"><i class='bx bx-hand-up'></i> Clic en una barra para ver la lista</p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
    (function() {
        var engLabels = [<?php foreach($engBars as $b) echo json_encode($b['label']).','; ?>];
        var engSubs   = [<?php foreach($engBars as $b) echo json_encode($b['sub']).','; ?>];
        var engKeys   = [<?php foreach($engBars as $b) echo json_encode($b['key']).','; ?>];
        var engVals   = [<?php foreach($engBars as $b) echo $b['val'].','; ?>];
        var engColors = [<?php foreach($engBars as $b) echo json_encode($b['color']).','; ?>];
        var engAlphas = [<?php foreach($engBars as $b) echo json_encode($b['alpha']).','; ?>];

        var ctx = document.getElementById('engChart');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: engLabels,
                datasets: [{
                    data: engVals,
                    backgroundColor: engAlphas,
                    hoverBackgroundColor: engColors,
                    borderColor: engColors,
                    borderWidth: 0,
                    borderRadius: 7,
                    borderSkipped: false,
                    barPercentage: 0.52,
                    categoryPercentage: 0.7,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 700, easing: 'easeOutQuart' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#ffffff',
                        borderColor: '#e5e7eb',
                        borderWidth: 1,
                        titleColor: '#0f172a',
                        bodyColor: '#6b7280',
                        titleFont: { size: 12, weight: '700', family: 'Inter,sans-serif' },
                        bodyFont: { size: 11, family: 'Inter,sans-serif' },
                        padding: 10,
                        cornerRadius: 8,
                        displayColors: true,
                        boxWidth: 8,
                        boxHeight: 8,
                        callbacks: {
                            title: function(i) { return engLabels[i[0].dataIndex] + ' · ' + engSubs[i[0].dataIndex]; },
                            label: function(i) { return ' ' + i.raw + ' usuarios'; },
                            labelColor: function(i) { return { borderColor: engColors[i.dataIndex], backgroundColor: engColors[i.dataIndex], borderRadius: 3 }; },
                        },
                    },
                },
                scales: {
                    x: {
                        display: false,
                    },
                    y: {
                        grid: { color: '#f3f4f6', lineWidth: 1, drawTicks: false },
                        border: { display: false },
                        ticks: { color: '#9ca3af', font: { size: 10, family: 'Inter,sans-serif' }, maxTicksLimit: 4, padding: 6, precision: 0 },
                        beginAtZero: true,
                    },
                },
                onClick: function(e, els) { if (els.length) engToggle(engKeys[els[0].index]); },
                onHover:  function(e, els) { ctx.style.cursor = els.length ? 'pointer' : 'default'; },
            },
        });
    })();
    </script>

    <!-- GRID: COMPARATIVA + RIESGO (solo Nivel 2A con BUs) -->
    <?php $showComparativa = (!$qBuId && !empty($entities) && isset($entities[0]['dist'])); ?>
    <?php if ($showComparativa && $showKpiExtras): ?>
    <div class="an-duo-grid">

        <!-- Comparativa por Unidad de Negocio — Chart.js horizontal -->
        <?php if ($showComparativa): ?>
        <?php
            $buNames   = [];
            $buComp    = [];
            $buProg    = [];
            $buSin     = [];
            $buNoPlan  = [];
            $buPcts    = [];
            $buTotals  = [];
            foreach ($entities as $e) {
                $d = $e['dist'];
                $tot = (int)$d['completado']+(int)$d['enProgreso']+(int)$d['sinIniciar']+(int)$d['sinPlan'];
                $buNames[]  = $e['name'];
                $buComp[]   = (int)$d['completado'];
                $buProg[]   = (int)$d['enProgreso'];
                $buSin[]    = (int)$d['sinIniciar'];
                $buNoPlan[] = (int)$d['sinPlan'];
                $buPcts[]   = (int)$e['absolutePct'];
                $buTotals[] = $tot;
            }
        ?>
        <div class="an-chart-box" style="display:flex;flex-direction:column;">
            <h3 class="an-section-title"><i class='bx bx-transfer-alt'></i> Comparativa por Unidad de Negocio</h3>
            <p style="font-size:0.72rem;color:#9ca3af;margin:-0.7rem 0 1rem;">
                Usuarios por estado de avance &nbsp;·&nbsp;
                <span style="color:#16a34a;font-weight:700;">■</span> Completado &nbsp;
                <span style="color:#f59e0b;font-weight:700;">■</span> En progreso &nbsp;
                <span style="color:#ef4444;font-weight:700;">■</span> Sin iniciar &nbsp;
                <span style="color:#94a3b8;font-weight:700;">■</span> Sin ruta
            </p>
            <div style="position:relative;flex:1;min-height:<?= max(count($entities)*52, 130) ?>px;">
                <canvas id="buChart"></canvas>
            </div>
        </div>
        <script>
        (function(){
            var bCtx=document.getElementById('buChart');
            if(!bCtx)return;
            var bNames=<?= json_encode($buNames) ?>;
            var bPcts=<?= json_encode($buPcts) ?>;
            var bTotals=<?= json_encode($buTotals) ?>;
            new Chart(bCtx,{
                type:'bar',
                data:{
                    labels:bNames,
                    datasets:[
                        {
                            label:'Completado',
                            data:<?= json_encode($buComp) ?>,
                            backgroundColor:'#16a34a',
                            hoverBackgroundColor:'#15803d',
                            borderRadius:{topLeft:6,bottomLeft:6,topRight:0,bottomRight:0},
                            borderSkipped:false,
                            barPercentage:0.58,
                            categoryPercentage:0.78,
                        },
                        {
                            label:'En progreso',
                            data:<?= json_encode($buProg) ?>,
                            backgroundColor:'#f59e0b',
                            hoverBackgroundColor:'#d97706',
                            borderRadius:0,
                            borderSkipped:false,
                            barPercentage:0.58,
                            categoryPercentage:0.78,
                        },
                        {
                            label:'Sin iniciar',
                            data:<?= json_encode($buSin) ?>,
                            backgroundColor:'#ef4444',
                            hoverBackgroundColor:'#dc2626',
                            borderRadius:0,
                            borderSkipped:false,
                            barPercentage:0.58,
                            categoryPercentage:0.78,
                        },
                        {
                            label:'Sin ruta',
                            data:<?= json_encode($buNoPlan) ?>,
                            backgroundColor:'#cbd5e1',
                            hoverBackgroundColor:'#94a3b8',
                            borderRadius:{topLeft:0,bottomLeft:0,topRight:6,bottomRight:6},
                            borderSkipped:false,
                            barPercentage:0.58,
                            categoryPercentage:0.78,
                        },
                    ]
                },
                options:{
                    indexAxis:'y',
                    responsive:true,
                    maintainAspectRatio:false,
                    animation:{duration:800,easing:'easeOutQuart'},
                    plugins:{
                        legend:{display:false},
                        tooltip:{
                            backgroundColor:'#ffffff',
                            borderColor:'#e5e7eb',
                            borderWidth:1,
                            titleColor:'#0f172a',
                            bodyColor:'#6b7280',
                            titleFont:{size:12,weight:'700',family:'Inter,sans-serif'},
                            bodyFont:{size:11,family:'Inter,sans-serif'},
                            padding:11,cornerRadius:10,displayColors:true,boxWidth:8,boxHeight:8,
                            mode:'index',
                            filter:function(item){return item.raw>0;},
                            callbacks:{
                                title:function(i){
                                    var idx=i[0].dataIndex;
                                    return bNames[idx]+' · '+bTotals[idx]+' usuarios · '+bPcts[idx]+'% avance';
                                },
                                label:function(i){return ' '+i.dataset.label+': '+i.raw;},
                                labelColor:function(i){
                                    var c=['#16a34a','#f59e0b','#ef4444','#cbd5e1'];
                                    return{borderColor:c[i.datasetIndex],backgroundColor:c[i.datasetIndex],borderRadius:3};
                                },
                            },
                        },
                    },
                    scales:{
                        x:{
                            stacked:true,
                            grid:{color:'#f3f4f6',lineWidth:1,drawTicks:false},
                            border:{display:false},
                            ticks:{color:'#9ca3af',font:{size:10,family:'Inter,sans-serif'},padding:4,stepSize:1},
                        },
                        y:{
                            stacked:true,
                            grid:{display:false},
                            border:{display:false},
                            ticks:{
                                color:'#374151',
                                font:{size:11,weight:'600',family:'Inter,sans-serif'},
                                padding:8,
                                callback:function(val,idx){
                                    var p=bPcts[idx];
                                    var n=bNames[idx];
                                    return n.length>18?n.slice(0,17)+'…':n;
                                },
                            },
                        },
                    },
                },
            });
        })();
        </script>
        <?php endif; ?>

        <!-- Riesgo de no continuar — premium -->
        <?php if ($showKpiExtras): ?>
        <div class="an-chart-box" style="border-left:3px solid #fca5a5;">
            <h3 class="an-section-title" style="color:#b91c1c;">
                <i class='bx bx-alarm-exclamation'></i>
                Riesgo de no continuar
                <?php if (!empty($atRiskUsers)): ?><span class="an-risk-badge"><?= count($atRiskUsers) ?></span><?php endif; ?>
            </h3>
            <p style="font-size:0.69rem;color:#9ca3af;margin:-0.5rem 0 0.85rem;line-height:1.5;">Ruta asignada &middot; &lt;20% avance &middot; sin actividad +7 d&iacute;as</p>
            <?php if (!empty($atRiskUsers)): ?>
            <div class="an-risk-list">
                <?php foreach ($atRiskUsers as $ru):
                    $words   = preg_split('/\s+/', trim($ru['name']));
                    $initials = strtoupper(substr($words[0],0,1) . (isset($words[1]) ? substr($words[1],0,1) : ''));
                    $isCrit  = $ru['daysSince'] > 14;
                    $bgInit  = $isCrit ? '#fee2e2' : '#fef3c7';
                    $fgInit  = $isCrit ? '#b91c1c' : '#b45309';
                ?>
                <div class="an-risk-row2">
                    <div class="an-risk-initials" style="background:<?= $bgInit ?>;color:<?= $fgInit ?>;"><?= $initials ?></div>
                    <div class="an-risk-info">
                        <p class="an-risk-name"><?= htmlspecialchars($ru['name']) ?></p>
                        <div class="an-risk-subrow">
                            <div class="an-risk-bar-track"><div class="an-risk-bar-fill" style="width:<?= $ru['pct'] ?>%;"></div></div>
                            <span class="an-risk-pct2"><?= $ru['pct'] ?>%</span>
                            <span class="an-severity-badge <?= $isCrit ? 'an-severity-critico' : 'an-severity-urgente' ?>"><?= $isCrit ? 'Crítico' : 'Urgente' ?></span>
                        </div>
                    </div>
                    <div class="an-risk-right">
                        <span class="an-risk-days-num"><?= min($ru['daysSince'],999) ?></span>
                        <span class="an-risk-days-lbl">días sin acceso</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="an-empty-state">
                <i class='bx bx-check-shield' style="color:#86efac;"></i>
                <p>Sin usuarios en riesgo</p>
                <small>Todos avanzan correctamente.</small>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <!-- GRID: TOP 3 + ROLES CRÍTICOS (1/3 + 2/3) -->
    <?php if ($showKpiExtras || !empty($topTitle)): ?>
    <div class="an-duo-grid" style="grid-template-columns:1fr 2fr;">
        <!-- Top 3 Usuarios (1fr — izquierda) -->
        <?php if (!empty($topTitle)): ?>
        <div class="an-chart-box">
            <h3 class="an-section-title"><i class='bx bxs-crown' style="color:#f59e0b;"></i> <?= htmlspecialchars($topTitle) ?></h3>
            <?php if (!empty($topUsers)): ?>
            <div class="an-top3-grid">
                <?php foreach ($topUsers as $idx => $user):
                    $rk  = $idx+1;
                    $rkC = $rk===1?'#f59e0b':($rk===2?'#9ca3af':'#cd7f32');
                    $rkI = $rk===1?'bxs-medal':($rk===2?'bx-trophy':'bx-award');
                ?>
                <div class="an-top-card" style="--rk:<?= $rkC ?>;">
                    <div class="an-top-rank">#<?= $rk ?> <i class='bx <?= $rkI ?>' style="color:<?= $rkC ?>;font-size:0.95rem;"></i></div>
                    <div class="an-top-avatar">
                        <?php if (!empty($user['image'])): ?><img src="<?= htmlspecialchars($user['image']) ?>" alt=""><?php else: ?><i class='bx bxs-user'></i><?php endif; ?>
                    </div>
                    <p class="an-top-name"><?= htmlspecialchars($user['name']) ?></p>
                    <p class="an-top-email"><?= htmlspecialchars($user['email']) ?></p>
                    <div class="an-top-stats">
                        <div><strong style="color:#f59e0b;"><?= number_format($user['totalPoints']??0) ?></strong><small>Puntos</small></div>
                        <div><strong style="color:#16a34a;"><?= $user['completedCourses'] ?></strong><small>Cursos</small></div>
                        <div><strong style="color:#f97316;"><?= number_format($user['totalScore']) ?></strong><small>Score</small></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="an-empty-state">
                <i class='bx bx-user-x'></i>
                <p>Sin usuarios registrados aún</p>
                <small>El ranking aparecerá cuando haya usuarios activos.</small>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <!-- Roles Críticos — Radar (2fr — derecha) -->
        <?php if ($showKpiExtras): ?>
        <div class="an-chart-box" style="display:flex;flex-direction:column;">
            <h3 class="an-section-title"><i class='bx bxs-shield-alt-2' style="color:#ef4444;"></i> Roles Críticos de Capacitación</h3>
            <?php if (!empty($criticalRolesData)): ?>
            <?php
                $radarLabels   = array_column($criticalRolesData, 'name');
                $radarVals     = array_column($criticalRolesData, 'pct');
                $radarUsers    = array_column($criticalRolesData, 'users');
                $radarPtColors = [];
                foreach ($criticalRolesData as $cr) {
                    $radarPtColors[] = $cr['level']==='green' ? '#16a34a' : ($cr['level']==='yellow' ? '#f59e0b' : '#ef4444');
                }
                $avgPct      = count($radarVals) ? round(array_sum($radarVals)/count($radarVals)) : 0;
                $radarClr    = $avgPct>=70 ? '22,163,74' : ($avgPct>=40 ? '245,158,11' : '239,68,68');
                $radarBorder = $avgPct>=70 ? '#16a34a'   : ($avgPct>=40 ? '#f59e0b'    : '#ef4444');
                $canUseRadar = count($radarLabels) >= 1;
            ?>
            <!-- Promedio global pill + indicadores de salud -->
            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
                <!-- Izquierda: promedio -->
                <div style="display:flex;align-items:center;gap:0.5rem;">
                    <span style="width:9px;height:9px;border-radius:50%;background:<?= $radarBorder ?>;display:inline-block;"></span>
                    <span style="font-size:0.75rem;color:#6b7280;">Promedio global de avance:</span>
                    <span style="font-size:0.82rem;font-weight:900;color:<?= $radarBorder ?>;"><?= $avgPct ?>%</span>
                </div>
                <!-- Derecha: indicadores de salud -->
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.3rem;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;flex-shrink:0;"></span>
                        <span style="font-size:0.68rem;color:#6b7280;">Bien <span style="color:#16a34a;font-weight:700;">(70% o más)</span></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.3rem;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#f59e0b;display:inline-block;flex-shrink:0;"></span>
                        <span style="font-size:0.68rem;color:#6b7280;">En riesgo <span style="color:#f59e0b;font-weight:700;">(40 a 69%)</span></span>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.3rem;">
                        <span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;flex-shrink:0;"></span>
                        <span style="font-size:0.68rem;color:#6b7280;">Crítico <span style="color:#ef4444;font-weight:700;">(menos de 40%)</span></span>
                    </div>
                </div>
            </div>
            <!-- Radar flexible (crece con la card) -->
            <?php if ($canUseRadar): ?>
            <div style="position:relative;flex:1;min-height:300px;">
                <canvas id="rolesRadar"></canvas>
            </div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:0.6rem;flex:1;">
                <?php foreach ($criticalRolesData as $cr):
                    $crC = $cr['level']==='green'?'#16a34a':($cr['level']==='yellow'?'#f59e0b':'#ef4444');
                ?>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.78rem;font-weight:600;color:#374151;margin-bottom:4px;">
                        <span><?= htmlspecialchars($cr['name']) ?></span>
                        <span style="color:<?= $crC ?>;font-weight:800;"><?= $cr['pct'] ?>% · <?= $cr['users'] ?> us.</span>
                    </div>
                    <div style="height:10px;background:#f1f5f9;border-radius:99px;overflow:hidden;">
                        <div style="width:<?= $cr['pct'] ?>%;height:100%;background:<?= $crC ?>;border-radius:99px;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <!-- Leyenda compacta debajo -->
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:0.25rem 1rem;padding-top:0.7rem;margin-top:0.5rem;border-top:1px solid #f1f5f9;">
                <?php foreach ($criticalRolesData as $cr):
                    $crC = $cr['level']==='green'?'#16a34a':($cr['level']==='yellow'?'#f59e0b':'#ef4444');
                ?>
                <div style="display:flex;align-items:center;gap:0.4rem;padding:0.18rem 0;">
                    <span style="width:7px;height:7px;border-radius:50%;background:<?= $crC ?>;flex-shrink:0;"></span>
                    <span style="font-size:0.7rem;color:#374151;font-weight:600;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($cr['name']) ?>"><?= htmlspecialchars($cr['name']) ?></span>
                    <span style="font-size:0.7rem;font-weight:800;color:<?= $crC ?>;white-space:nowrap;"><?= $cr['pct'] ?>%</span>
                    <span style="font-size:0.63rem;color:#9ca3af;white-space:nowrap;"><?= $cr['users'] ?> <?= $cr['users']==1?'usuario':'usuarios' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($canUseRadar): ?>
            <script>
            (function(){
                var rCtx=document.getElementById('rolesRadar');
                if(!rCtx)return;
                var rLabels=<?= json_encode($radarLabels) ?>;
                var rVals=<?= json_encode($radarVals) ?>;
                var rUsers=<?= json_encode($radarUsers) ?>;
                var rPtColors=<?= json_encode($radarPtColors) ?>;
                new Chart(rCtx,{
                    type:'radar',
                    data:{
                        labels:rLabels,
                        datasets:[{
                            label:'Avance',
                            data:rVals,
                            backgroundColor:'rgba(<?= $radarClr ?>,0.12)',
                            borderColor:'<?= $radarBorder ?>',
                            borderWidth:2.5,
                            pointBackgroundColor:rPtColors,
                            pointBorderColor:'#ffffff',
                            pointBorderWidth:2.5,
                            pointRadius:6,
                            pointHoverRadius:9,
                            fill:true,
                        }]
                    },
                    options:{
                        responsive:true,
                        maintainAspectRatio:false,
                        animation:{duration:900,easing:'easeOutQuart'},
                        plugins:{
                            legend:{display:false},
                            tooltip:{
                                backgroundColor:'#ffffff',
                                borderColor:'#e5e7eb',
                                borderWidth:1,
                                titleColor:'#0f172a',
                                bodyColor:'#6b7280',
                                titleFont:{size:12,weight:'700',family:'Inter,sans-serif'},
                                bodyFont:{size:11,family:'Inter,sans-serif'},
                                padding:11,cornerRadius:10,displayColors:true,
                                boxWidth:8,boxHeight:8,
                                callbacks:{
                                    title:function(i){return rLabels[i[0].dataIndex];},
                                    label:function(i){return ' Avance: '+i.raw+'%  ·  '+rUsers[i.dataIndex]+' usuario'+(rUsers[i.dataIndex]!=1?'s':'');},
                                    labelColor:function(i){var c=rPtColors[i.dataIndex];return{borderColor:c,backgroundColor:c,borderRadius:3};},
                                },
                            },
                        },
                        scales:{
                            r:{
                                min:0,max:100,
                                ticks:{display:false,stepSize:25},
                                grid:{color:'rgba(0,0,0,0.055)',lineWidth:1},
                                angleLines:{color:'rgba(0,0,0,0.07)',lineWidth:1},
                                pointLabels:{
                                    color:'#4b5563',
                                    font:{size:11,weight:'600',family:'Inter,sans-serif'},
                                    padding:10,
                                },
                            },
                        },
                    },
                });
            })();
            </script>
            <?php endif; ?>
            <?php else: ?>
            <div class="an-empty-state">
                <i class='bx bx-briefcase'></i>
                <p>Sin roles con usuarios asignados</p>
                <small>Cuando se asignen perfiles formativos aparecerán aquí.</small>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <!-- placeholder: Top 3 ya está arriba -->

    </div>
    <?php endif; // showKpiExtras || topTitle ?>
    <?php endif; // showKpiExtras (outer) ?>

    <!-- GRID DE ENTIDADES: movido arriba del CHARTS ROW -->

    <!-- placeholder moved into duo-grid above -->

    <!-- GRID: RIESGO + MATRÍCULA (Nivel 2B y 3: empresa sin BUs o dentro de BU) -->
    <?php if (!$showComparativa && $showKpiExtras): ?>
    <?php
        $userCourses = [];
        if (!empty($fullUserList)) {
            $uIds = array_column($fullUserList,'id');
            if (!empty($uIds)) {
                $placeholders = str_repeat('?,', count($uIds)-1).'?';
                $stmtC = $pdo->prepare("
                    SELECT c.title as name, assigned.userId,
                           CASE WHEN cp.courseId IS NOT NULL THEN 1 ELSE 0 END as hasStarted,
                           cp.isCompleted, cp.quizPassed, cp.quizScore, cp.quizAttempts,
                           (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId=m.id WHERE m.courseId=c.id) as totalLessons,
                           (SELECT COUNT(lp2.id) FROM LessonProgress lp2 JOIN Lesson l2 ON lp2.lessonId=l2.id JOIN Module m2 ON l2.moduleId=m2.id WHERE m2.courseId=c.id AND lp2.userId=assigned.userId AND lp2.isCompleted=1) as completedLessons
                    FROM (
                        SELECT DISTINCT tru.B as userId, lpc.courseId
                        FROM _TrainingRoleToUser tru
                        JOIN _LearningPathToTrainingRole lptr ON tru.A=lptr.B
                        JOIN LearningPathCourse lpc ON lptr.A=lpc.learningPathId
                        WHERE tru.B IN ($placeholders)
                    ) assigned
                    JOIN Course c ON c.id=assigned.courseId
                    LEFT JOIN CourseProgress cp ON cp.courseId=c.id AND cp.userId=assigned.userId
                    ORDER BY c.title ASC
                ");
                $stmtC->execute($uIds);
                foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $userCourses[$row['userId']][] = $row;
                }
            }
        }
    ?>
    <div class="an-duo-grid" style="grid-template-columns:2fr 1fr;align-items:start;">
        <!-- Matrícula (izquierda, 2fr) -->
        <?php if (!empty($listTitle)): ?>
        <div class="an-chart-box" style="overflow:hidden;">
            <h3 class="an-section-title"><i class='bx bx-user-pin' style="color:#f97316;"></i> <?= htmlspecialchars($listTitle) ?></h3>
            <?php if (!empty($fullUserList)): ?>
            <div class="an-user-table-wrap">
                <table class="an-user-table">
                    <thead><tr><th>Alumno</th><th>Avance</th><th>Roles</th><th>Cursos asignados</th></tr></thead>
                    <tbody>
                        <?php foreach ($fullUserList as $user):
                            $uid  = $user['id'];
                            $crss = $userCourses[$uid] ?? [];
                            $asgn = (int)$user['assignedCount'];
                            $cmpd = min((int)$user['completedCount'],$asgn);
                            $gPct = $asgn>0?min(round(($cmpd/$asgn)*100),100):0;
                            $pClr = $gPct>=70?'#16a34a':($gPct>=40?'#f59e0b':'#ef4444');
                            $cPrm = $qCompanyId?'&company_id='.urlencode($qCompanyId):'';
                        ?>
                        <tr>
                            <td>
                                <a href="index.php?view=dashboard&user_id=<?= urlencode($uid) ?><?= $cPrm ?>" class="an-user-link">
                                    <div class="an-user-avatar"><i class='bx bxs-user'></i></div>
                                    <div style="min-width:0;"><p class="an-user-name"><?= htmlspecialchars($user['name']) ?></p><p class="an-user-email"><?= htmlspecialchars($user['email']) ?></p></div>
                                </a>
                            </td>
                            <td>
                                <?php if ($asgn>0): ?>
                                <div class="an-user-pct" style="color:<?= $pClr ?>;"><?= $gPct ?>%</div>
                                <div class="an-user-progress"><div style="width:<?= $gPct ?>%;background:<?= $pClr ?>;height:100%;border-radius:3px;"></div></div>
                                <div class="an-user-count"><?= $cmpd ?>/<?= $asgn ?> cursos</div>
                                <?php else: ?><span class="an-badge-no-plan">Sin ruta</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['trainingRolesNames']): ?>
                                <div class="an-roles-wrap"><?php foreach(explode(', ',$user['trainingRolesNames']) as $trn): ?><span class="an-role-tag"><?= htmlspecialchars($trn) ?></span><?php endforeach; ?></div>
                                <?php else: ?><span style="color:#9ca3af;font-size:0.75rem;font-style:italic;">Sin perfil</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($crss)): ?>
                                <div class="an-courses-mini">
                                    <?php foreach ($crss as $c):
                                        $hS = $c['hasStarted']==1; $iC = $c['isCompleted']==1;
                                        $tL = (int)$c['totalLessons']; $cL = (int)$c['completedLessons'];
                                        $cIc = $iC?'bx-check-double':($hS?'bx-loader-circle':'bx-minus-circle');
                                        $cCl = $iC?'#16a34a':($hS?'#3b82f6':'#9ca3af');
                                    ?>
                                    <div class="an-course-mini-row">
                                        <i class='bx <?= $cIc ?>' style="color:<?= $cCl ?>;font-size:0.88rem;flex-shrink:0;"></i>
                                        <span class="an-course-mini-name" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></span>
                                        <span class="an-course-mini-stat"><?= $hS?"$cL/$tL":'&mdash;' ?></span>
                                        <?php if ($c['quizScore']!==null): ?><span class="an-course-mini-score" style="color:<?= $c['quizPassed']?'#16a34a':'#b91c1c' ?>;"><?= $c['quizScore'] ?>%</span><?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?><span style="color:#9ca3af;font-size:0.75rem;font-style:italic;">Sin cursos asignados</span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="an-empty-state" style="padding:3rem 1rem;">
                <i class='bx bx-group'></i>
                <p>Sin usuarios matriculados</p>
                <small>Cuando se agreguen usuarios aparecerán aquí.</small>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <!-- Riesgo de no continuar (derecha, 1fr) -->
        <div class="an-chart-box" style="border-left:3px solid #fca5a5;">
            <h3 class="an-section-title" style="color:#b91c1c;">
                <i class='bx bx-alarm-exclamation'></i>
                Riesgo de no continuar
                <?php if (!empty($atRiskUsers)): ?><span class="an-risk-badge"><?= count($atRiskUsers) ?></span><?php endif; ?>
            </h3>
            <p style="font-size:0.69rem;color:#9ca3af;margin:-0.5rem 0 0.85rem;line-height:1.5;">Ruta asignada &middot; &lt;20% avance &middot; sin actividad +7 d&iacute;as</p>
            <?php if (!empty($atRiskUsers)): ?>
            <div class="an-risk-list">
                <?php foreach ($atRiskUsers as $ru):
                    $words    = preg_split('/\s+/', trim($ru['name']));
                    $initials = strtoupper(substr($words[0],0,1) . (isset($words[1]) ? substr($words[1],0,1) : ''));
                    $isCrit   = $ru['daysSince'] > 14;
                    $bgInit   = $isCrit ? '#fee2e2' : '#fef3c7';
                    $fgInit   = $isCrit ? '#b91c1c' : '#b45309';
                ?>
                <div class="an-risk-row2">
                    <div class="an-risk-initials" style="background:<?= $bgInit ?>;color:<?= $fgInit ?>;"><?= $initials ?></div>
                    <div class="an-risk-info">
                        <p class="an-risk-name"><?= htmlspecialchars($ru['name']) ?></p>
                        <div class="an-risk-subrow">
                            <div class="an-risk-bar-track"><div class="an-risk-bar-fill" style="width:<?= $ru['pct'] ?>;"></div></div>
                            <span class="an-risk-pct2"><?= $ru['pct'] ?>%</span>
                            <span class="an-severity-badge <?= $isCrit ? 'an-severity-critico' : 'an-severity-urgente' ?>"><?= $isCrit ? 'Cr&iacute;tico' : 'Urgente' ?></span>
                        </div>
                    </div>
                    <div class="an-risk-right">
                        <span class="an-risk-days-num"><?= min($ru['daysSince'],999) ?></span>
                        <span class="an-risk-days-lbl">d&iacute;as sin acceso</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="an-empty-state">
                <i class='bx bx-check-shield' style="color:#86efac;"></i>
                <p>Sin usuarios en riesgo</p>
                <small>Todos avanzan correctamente.</small>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; // !showComparativa && showKpiExtras ?>

    <!-- MATRÍCULA COMPLETA (solo Nivel 2A: usuarios corporativos sin BU) -->
    <?php if (!empty($listTitle) && $showComparativa):
    $userCourses = [];
    if (!empty($fullUserList)) {
        $uIds = array_column($fullUserList,'id');
        if (!empty($uIds)) {
            $placeholders = str_repeat('?,', count($uIds)-1).'?';
            $stmtC = $pdo->prepare("
                SELECT c.title as name, assigned.userId,
                       CASE WHEN cp.courseId IS NOT NULL THEN 1 ELSE 0 END as hasStarted,
                       cp.isCompleted, cp.quizPassed, cp.quizScore, cp.quizAttempts,
                       (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId=m.id WHERE m.courseId=c.id) as totalLessons,
                       (SELECT COUNT(lp2.id) FROM LessonProgress lp2 JOIN Lesson l2 ON lp2.lessonId=l2.id JOIN Module m2 ON l2.moduleId=m2.id WHERE m2.courseId=c.id AND lp2.userId=assigned.userId AND lp2.isCompleted=1) as completedLessons
                FROM (
                    SELECT DISTINCT tru.B as userId, lpc.courseId
                    FROM _TrainingRoleToUser tru
                    JOIN _LearningPathToTrainingRole lptr ON tru.A=lptr.B
                    JOIN LearningPathCourse lpc ON lptr.A=lpc.learningPathId
                    WHERE tru.B IN ($placeholders)
                ) assigned
                JOIN Course c ON c.id=assigned.courseId
                LEFT JOIN CourseProgress cp ON cp.courseId=c.id AND cp.userId=assigned.userId
                ORDER BY c.title ASC
            ");
            $stmtC->execute($uIds);
            foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $userCourses[$row['userId']][] = $row;
            }
        }
    }
    ?>
    <div class="an-section">
        <h3 class="an-section-title"><i class='bx bx-user-pin' style="color:#f97316;"></i> <?= htmlspecialchars($listTitle) ?><?php if ($showListCounter && !empty($fullUserList)): ?><span style="margin-left:0.6rem;background:#ede9fe;color:#6d28d9;font-size:0.7rem;font-weight:700;padding:2px 10px;border-radius:99px;vertical-align:middle;"><?= count($fullUserList) ?> usuario<?= count($fullUserList) !== 1 ? 's' : '' ?></span><?php endif; ?></h3>
        <?php if (!empty($fullUserList)): ?>
        <div class="an-user-table-wrap">
            <table class="an-user-table">
                <thead><tr><th>Alumno</th><th>Avance</th><th>Roles</th><th>Cursos asignados</th></tr></thead>
                <tbody>
                    <?php foreach ($fullUserList as $user):
                        $uid  = $user['id'];
                        $crss = $userCourses[$uid] ?? [];
                        $asgn = (int)$user['assignedCount'];
                        $cmpd = min((int)$user['completedCount'],$asgn);
                        $gPct = $asgn>0?min(round(($cmpd/$asgn)*100),100):0;
                        $pClr = $gPct>=70?'#16a34a':($gPct>=40?'#f59e0b':'#ef4444');
                        $cPrm = $qCompanyId?'&company_id='.urlencode($qCompanyId):'';
                    ?>
                    <tr>
                        <td>
                            <a href="index.php?view=dashboard&user_id=<?= urlencode($uid) ?><?= $cPrm ?>" class="an-user-link">
                                <div class="an-user-avatar"><i class='bx bxs-user'></i></div>
                                <div style="min-width:0;"><p class="an-user-name"><?= htmlspecialchars($user['name']) ?></p><p class="an-user-email"><?= htmlspecialchars($user['email']) ?></p></div>
                            </a>
                        </td>
                        <td>
                            <?php if ($asgn>0): ?>
                            <div class="an-user-pct" style="color:<?= $pClr ?>;"><?= $gPct ?>%</div>
                            <div class="an-user-progress"><div style="width:<?= $gPct ?>%;background:<?= $pClr ?>;height:100%;border-radius:3px;"></div></div>
                            <div class="an-user-count"><?= $cmpd ?>/<?= $asgn ?> cursos</div>
                            <?php else: ?><span class="an-badge-no-plan">Sin ruta</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['trainingRolesNames']): ?>
                            <div class="an-roles-wrap"><?php foreach(explode(', ',$user['trainingRolesNames']) as $trn): ?><span class="an-role-tag"><?= htmlspecialchars($trn) ?></span><?php endforeach; ?></div>
                            <?php else: ?><span style="color:#9ca3af;font-size:0.75rem;font-style:italic;">Sin perfil</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($crss)): ?>
                            <div class="an-courses-mini">
                                <?php foreach ($crss as $c):
                                    $hS = $c['hasStarted']==1; $iC = $c['isCompleted']==1;
                                    $tL = (int)$c['totalLessons']; $cL = (int)$c['completedLessons'];
                                    $cIc = $iC?'bx-check-double':($hS?'bx-loader-circle':'bx-minus-circle');
                                    $cCl = $iC?'#16a34a':($hS?'#3b82f6':'#9ca3af');
                                ?>
                                <div class="an-course-mini-row">
                                    <i class='bx <?= $cIc ?>' style="color:<?= $cCl ?>;font-size:0.88rem;flex-shrink:0;"></i>
                                    <span class="an-course-mini-name" title="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></span>
                                    <span class="an-course-mini-stat"><?= $hS?"$cL/$tL":'—' ?></span>
                                    <?php if ($c['quizScore']!==null): ?><span class="an-course-mini-score" style="color:<?= $c['quizPassed']?'#16a34a':'#b91c1c' ?>;"><?= $c['quizScore'] ?>%</span><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?><span style="color:#9ca3af;font-size:0.75rem;font-style:italic;">Sin cursos asignados</span><?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="an-empty-state" style="padding:3rem 1rem;">
            <i class='bx bx-group'></i>
            <p>Sin usuarios matriculados</p>
            <small>Cuando se agreguen usuarios a esta empresa aparecerán aquí.</small>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>




    <!-- NIVEL 4: EXPEDIENTE INDIVIDUAL -->
    <?php if ($qUserId && !empty($assignedCourses)): ?>
    <div class="an-section">
        <h3 class="an-section-title"><i class='bx bx-book-open' style="color:#6366f1;"></i> Desglose Académico</h3>
        <div class="an-course-grid">
            <?php foreach ($assignedCourses as $course):
                $hS2 = $course['userId']!==null; $iC2 = $course['isCompleted']==1;
                $tL2 = (int)$course['totalLessons']; $cL2 = (int)$course['completedLessons'];
                $p2  = $tL2>0?min(round(($cL2/$tL2)*100),100):0;
                $cBd = $iC2?'#bbf7d0':($hS2?'#bfdbfe':'#e5e7eb');
                $cBg = $iC2?'#f0fdf4':($hS2?'#eff6ff':'#f9fafb');
            ?>
            <div class="an-course-card" style="border-color:<?= $cBd ?>;background:<?= $cBg ?>;">
                <div>
                    <?php if ($iC2): ?><span class="an-badge-status an-badge-done"><i class='bx bx-check-double'></i> Completado</span>
                    <?php elseif ($hS2): ?><span class="an-badge-status an-badge-progress"><i class='bx bx-loader-circle'></i> En progreso (<?= $p2 ?>%)</span>
                    <?php else: ?><span class="an-badge-status an-badge-pending"><i class='bx bx-minus-circle'></i> Sin iniciar</span><?php endif; ?>
                </div>
                <p class="an-cc-name"><?= htmlspecialchars($course['name']) ?></p>
                <div class="an-cc-stats">
                    <div><strong><?= $hS2?"$cL2/$tL2":'—' ?></strong><small>Lecciones</small></div>
                    <div><strong style="color:<?= $course['quizPassed']?'#16a34a':($course['quizScore']!==null?'#b91c1c':'#9ca3af') ?>;"><?= $course['quizScore']!==null?$course['quizScore'].'%':'—' ?></strong><small>Calificación</small></div>
                    <div><strong><?= $course['quizAttempts']??0 ?></strong><small>Intentos</small></div>
                </div>
                <div class="an-cc-bar"><div style="width:<?= max($p2,2) ?>%;background:<?= $iC2?'#16a34a':'#f97316' ?>;height:100%;border-radius:3px;"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal Engagement -->
<div class="modal-overlay" id="engModal"><div class="modal-content" style="max-width:640px;"></div></div>


<script>
(function(){
    var pCtx=document.getElementById('progressDonut');
    if(!pCtx)return;
    var pVals=[<?= (int)$progressDist['completado'] ?>,<?= (int)$progressDist['enProgreso'] ?>,<?= (int)$progressDist['sinIniciar'] ?>,<?= (int)$progressDist['sinPlan'] ?>];
    var pLabels=['Completado','En progreso','Sin iniciar','Sin ruta'];
    var pColors=['#16a34a','#f59e0b','#ef4444','#94a3b8'];
    var pSum=pVals.reduce(function(a,b){return a+b;},0);
    var isEmpty=pSum===0;
    new Chart(pCtx,{
        type:'doughnut',
        data:{
            labels:pLabels,
            datasets:[{
                data:isEmpty?[1]:pVals,
                backgroundColor:isEmpty?['#f1f5f9']:pColors,
                hoverBackgroundColor:isEmpty?['#e2e8f0']:pColors,
                borderWidth:0,
                borderRadius:4,
                hoverOffset:5,
                spacing:isEmpty?0:3,
            }]
        },
        options:{
            cutout:'72%',
            responsive:true,
            maintainAspectRatio:false,
            animation:{duration:700,easing:'easeOutQuart'},
            plugins:{
                legend:{display:false},
                tooltip:{
                    enabled:!isEmpty,
                    backgroundColor:'#ffffff',
                    borderColor:'#e5e7eb',
                    borderWidth:1,
                    titleColor:'#0f172a',
                    bodyColor:'#6b7280',
                    titleFont:{size:12,weight:'700',family:'Inter,sans-serif'},
                    bodyFont:{size:11,family:'Inter,sans-serif'},
                    padding:10,
                    cornerRadius:8,
                    displayColors:true,
                    boxWidth:8,
                    boxHeight:8,
                    callbacks:{
                        title:function(i){return pLabels[i[0].dataIndex];},
                        label:function(i){var p=pSum>0?Math.round(i.raw/pSum*100):0;return ' '+i.raw+' usuarios · '+p+'%';},
                        labelColor:function(i){return{borderColor:pColors[i.dataIndex],backgroundColor:pColors[i.dataIndex],borderRadius:3};},
                    },
                },
            },
        },
    });
})();

(function(){
    var data=<?= $engUsersJson ?>;
    var cfg={active:{label:'Activos (\u2264 3 d\u00edas)',color:'#15803d',bg:'#f0fdf4'},atRisk:{label:'En Riesgo (4\u201310 d\u00edas)',color:'#b45309',bg:'#fffbeb'},inactive:{label:'Inactivos (> 10 d\u00edas)',color:'#b91c1c',bg:'#fef2f2'},neverIn:{label:'Sin Registro (nunca)',color:'#475569',bg:'#f8fafc'}};
    var current=null;
    document.addEventListener('DOMContentLoaded',function(){
        var modal=document.getElementById('engModal');
        if(!modal)return;
        document.body.appendChild(modal);
        modal.addEventListener('click',function(e){if(e.target===modal)engClose();});
    });
    window.engToggle=function(cat){
        if(current===cat){engClose();return;}
        current=cat;
        var users=data[cat]||[];
        var c=cfg[cat];
        var modal=document.getElementById('engModal');
        var mc=modal.querySelector('.modal-content');
        var html='<div class="modal-header" style="background:'+c.bg+';margin:-2rem -2rem 1.5rem;padding:1rem 1.5rem;border-radius:12px 12px 0 0;">';
        html+='<div style="display:flex;align-items:center;gap:0.6rem;"><div style="width:4px;height:26px;border-radius:4px;background:'+c.color+';"></div>';
        html+='<div><h3 class="modal-title" style="color:'+c.color+';font-size:0.9rem;">'+c.label+'</h3>';
        html+='<div style="font-size:0.72rem;color:#6b7280;margin-top:1px;">'+users.length+' alumno(s)</div></div></div>';
        html+='<button class="modal-close" onclick="engClose()"><i class="bx bx-x"></i></button></div>';
        if(!users.length){
            html+='<p style="color:#94a3b8;font-style:italic;font-size:0.85rem;">Sin alumnos en esta categor\u00eda.</p>';
        } else {
            html+='<div class="table-responsive"><table class="data-table"><thead><tr><th>#</th><th>Alumno</th><th>Correo</th><th style="text-align:center;">\u00daltimo Acceso</th></tr></thead><tbody>';
            users.forEach(function(u,i){
                html+='<tr><td style="color:#94a3b8;font-size:0.78rem;">'+(i+1)+'</td>';
                html+='<td style="font-weight:600;color:var(--text-main);">'+(u.name||'\u2014')+'</td>';
                html+='<td style="font-size:0.8rem;">'+(u.email||'\u2014')+'</td>';
                html+='<td style="text-align:center;font-weight:700;color:'+c.color+';font-size:0.82rem;white-space:nowrap;">'+(u.lastLogin||'\u2014')+'</td></tr>';
            });
            html+='</tbody></table></div>';
        }
        mc.innerHTML=html;
        modal.classList.add('active');
        document.body.style.overflow='hidden';
    };
    window.engClose=function(){
        var modal=document.getElementById('engModal');
        if(modal){modal.classList.remove('active');document.body.style.overflow='';}
        current=null;
    };
})();
</script>

<!-- ═══ OVERLAY PDF LOADING ═══ -->
<div id="pdfOverlay" style="
    display:none; position:fixed; inset:0; z-index:99999;
    background:rgba(15,23,42,0.75); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; flex-direction:column; gap:1rem;
">
    <div style="
        background:white; border-radius:20px; padding:2.5rem 3rem;
        text-align:center; box-shadow:0 25px 60px rgba(0,0,0,0.3);
        display:flex; flex-direction:column; align-items:center; gap:1rem;
    ">
        <div style="
            width:52px; height:52px; border-radius:50%;
            border:4px solid #e5e7eb; border-top-color:#f97316;
            animation:pdfSpin 0.8s linear infinite;
        "></div>
        <p style="font-weight:800; font-size:1rem; color:#0f172a; margin:0;">Generando PDF...</p>
        <p style="font-size:0.8rem; color:#64748b; margin:0;" id="pdfStatus">Capturando gráficas</p>
    </div>
</div>
<style>
@keyframes pdfSpin { to { transform: rotate(360deg); } }
</style>

<!-- ═══ LIBRERÍAS PDF ═══ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<script>
async function exportPDF() {
    const overlay  = document.getElementById('pdfOverlay');
    const statusEl = document.getElementById('pdfStatus');
    const btn      = document.getElementById('btnExportPDF');

    overlay.style.display = 'flex';
    btn.disabled = true;
    await new Promise(r => setTimeout(r, 150));

    // Elementos de UI a ocultar mientras capturamos
    const uiHide = [
        '#btnExportPDF', '.an-chart-hint', '#engModal',
        '.student-v3-topnav', '.v3-topnav', 'footer', '.student-v3-footer',
        '.v3-page-content > *:not(#analiticas-report)'
    ];
    const hiddenEls = [];
    uiHide.forEach(sel => {
        document.querySelectorAll(sel).forEach(el => {
            hiddenEls.push({ el, vis: el.style.visibility, disp: el.style.display });
            el.style.visibility = 'hidden';
        });
    });

    // Scroll al inicio para que Chart.js tenga los canvas visibles
    const mainEl = document.querySelector('main') || window;
    const prevScroll = mainEl.scrollTop ?? 0;
    if (mainEl.scrollTo) mainEl.scrollTo(0, 0);
    else window.scrollTo(0, 0);

    await new Promise(r => setTimeout(r, 300)); // Esperar re-render de charts

    try {
        const { jsPDF } = window.jspdf;

        // ── Contenedor a capturar ──────────────────────────────────────────
        const report = document.getElementById('analiticas-report');

        statusEl.textContent = 'Capturando contenido completo…';

        const fullCanvas = await html2canvas(report, {
            scale        : 2,
            useCORS      : true,
            allowTaint   : true,
            backgroundColor: '#f8fafc',
            logging      : false,
            scrollX      : 0,
            scrollY      : 0,
            width        : report.scrollWidth,
            height       : report.scrollHeight,
            windowWidth  : report.scrollWidth,
            windowHeight : report.scrollHeight,
            ignoreElements: el =>
                el.id === 'pdfOverlay' ||
                el.id === 'engModal'   ||
                el.classList.contains('an-chart-hint') ||
                el.classList.contains('student-v3-topnav') ||
                el.classList.contains('v3-topnav')
        });

        statusEl.textContent = 'Componiendo PDF…';
        await new Promise(r => setTimeout(r, 50));

        // ── Configuración A4 ──────────────────────────────────────────────
        const pageW    = 210;
        const pageH    = 297;
        const margin   = 10;
        const hdrH     = 16;   // header oscuro
        const ftrH     = 10;   // footer claro
        const contentW = pageW - margin * 2;
        const contentH = pageH - margin * 2 - hdrH - ftrH; // area útil por página

        // Pixels por mm (basado en el ancho)
        const pxPerMm  = fullCanvas.width / contentW;
        const sliceH   = Math.round(contentH * pxPerMm); // alto de slice en px

        const pdf      = new jsPDF({ orientation:'portrait', unit:'mm', format:'a4' });
        const now      = new Date();
        const dateStr  = now.toLocaleDateString('es-MX', {day:'2-digit', month:'long', year:'numeric'});
        const fileName = `analiticas_eurosoft_${now.getFullYear()}${String(now.getMonth()+1).padStart(2,'0')}${String(now.getDate()).padStart(2,'0')}.pdf`;

        let yPx    = 0;
        let pageNum = 0;
        const totalH = fullCanvas.height;

        while (yPx < totalH) {
            pageNum++;

            if (pageNum > 1) pdf.addPage();

            // ── Header ─────────────────────────────────────────────────────
            pdf.setFillColor(15, 23, 42);
            pdf.rect(0, 0, pageW, hdrH, 'F');
            pdf.setTextColor(255, 255, 255);
            pdf.setFontSize(pageNum === 1 ? 10 : 8);
            pdf.setFont('helvetica', 'bold');
            pdf.text('Hub Eurosoft — Reporte de Analíticas', margin, hdrH - 4);
            pdf.setFontSize(7);
            pdf.setFont('helvetica', 'normal');
            pdf.text(dateStr, pageW - margin, hdrH - 4, { align:'right' });

            // ── Slice del canvas ───────────────────────────────────────────
            const thisSliceH = Math.min(sliceH, totalH - yPx);
            const sliceCanvas = document.createElement('canvas');
            sliceCanvas.width  = fullCanvas.width;
            sliceCanvas.height = thisSliceH;
            const ctx = sliceCanvas.getContext('2d');
            ctx.fillStyle = '#f8fafc';
            ctx.fillRect(0, 0, sliceCanvas.width, sliceCanvas.height);
            ctx.drawImage(fullCanvas, 0, yPx, fullCanvas.width, thisSliceH,
                                      0, 0,   fullCanvas.width, thisSliceH);

            const imgData = sliceCanvas.toDataURL('image/jpeg', 0.93);
            const sliceHmm = (thisSliceH / pxPerMm);
            pdf.addImage(imgData, 'JPEG', margin, hdrH + 2, contentW, sliceHmm);

            // ── Footer ─────────────────────────────────────────────────────
            pdf.setFillColor(241, 245, 249);
            pdf.rect(0, pageH - ftrH, pageW, ftrH, 'F');
            pdf.setTextColor(148, 163, 184);
            pdf.setFontSize(7);
            pdf.setFont('helvetica', 'normal');
            pdf.text('Generado por Hub Eurosoft · Confidencial', margin, pageH - 3.5);
            pdf.text(`Pág. ${pageNum}`, pageW - margin, pageH - 3.5, { align:'right' });

            yPx += sliceH;
        }

        statusEl.textContent = 'Descargando…';
        await new Promise(r => setTimeout(r, 80));
        pdf.save(fileName);

    } catch(err) {
        console.error('Error PDF:', err);
        alert('Error al generar el PDF. Intenta de nuevo.');
    } finally {
        // Restaurar visibilidad
        hiddenEls.forEach(({ el, vis, disp }) => {
            el.style.visibility = vis;
            el.style.display    = disp;
        });
        // Restaurar scroll
        if (mainEl.scrollTo) mainEl.scrollTo(0, prevScroll);
        overlay.style.display = 'none';
        btn.disabled = false;
    }
}
</script>
