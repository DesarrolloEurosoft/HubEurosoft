<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$userId = $_SESSION['user_id'] ?? '';
$isAdmin = in_array($userRole, ['ADMIN', 'ROOT_ADMIN', 'SUPERVISOR']);
$isCoLeader = ($userRole === 'COMPANY_LEADER');
$isBuLeader = ($userRole === 'BUSINESS_UNIT_LEADER');

$myCompanyId = $_SESSION['user_company'] ?? null;
$myBuId = $_SESSION['user_bu'] ?? null;

// Helper
if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

// =========================================
// HANDLER: Crear tema inline (Lector Operativo)
// =========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lo_create_topic' && $userId) {
    $loForumId  = trim($_POST['lo_forum_id'] ?? '');
    $threadType = trim($_POST['threadType']  ?? 'QUESTION');
    $title      = trim($_POST['title']       ?? '');
    $content    = trim($_POST['content']     ?? '');

    if ($loForumId && $title && $content) {
        $newId = generateCuid();
        $pdo->prepare("INSERT INTO ForumTopic (id, forumId, authorId, title, content, views, threadType, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, 0, ?, NOW(), NOW())")
            ->execute([$newId, $loForumId, $userId, $title, $content, $threadType]);
        echo "<script>window.location.href='index.php?view=forum_topic&forum_id=".urlencode($loForumId)."&topic_id=".urlencode($newId)."';</script>";
        exit;
    }
}

$step = $_GET['step'] ?? null;
$viewCompanyId = $_GET['filter_co'] ?? null;
$viewBuId = $_GET['filter_bu'] ?? null;

// =========================================
// 1. RESOLUCIÓN DE ÁMBITO POR ROL
// =========================================
if ($isAdmin) {
    if (!$step) $step = 'companies';
} elseif ($isCoLeader) {
    $viewCompanyId = $myCompanyId; // Siempre forzado a su empresa
    // Validar que el filter_bu del GET pertenezca a su empresa
    if ($viewBuId && $viewBuId !== 'corporativo') {
        $stmtBuOwn = $pdo->prepare("SELECT id FROM BusinessUnit WHERE id = ? AND companyId = ?");
        $stmtBuOwn->execute([$viewBuId, $myCompanyId]);
        if (!$stmtBuOwn->fetch()) $viewBuId = null; // BU ajena -> reset
    }
    if (!$step) $step = 'bus';
} else {
    // BUSINESS_UNIT_LEADER, STUDENT, INSTRUCTOR...
    $viewCompanyId = $myCompanyId;
    $viewBuId = $myBuId; // Forzado a su propia BU, no puede cambiarla
    if (!$step) $step = 'forums';
}

// Pequeño cortafuegos
if (!$viewCompanyId && !$isAdmin && !$myCompanyId) {
    echo "<div style='width: 85%; max-width: 1920px; margin: 0 auto; padding: 1rem 0;'><div style='padding: 3rem; text-align: center;'><h2>Módulo No Disponible</h2><p>Tu cuenta no está vinculada a ninguna Organización.</p></div></div>";
    return;
}
?>
<div style="width: 100%; padding: 1rem 0;">
<?php
// =========================================
// STEP 1: COMPAÑÍAS (Sólo Admin)
// =========================================
if ($step === 'companies' && $isAdmin): 
    $today = date('Y-m-d');
    $stmtC = $pdo->prepare("SELECT c.id, c.name, c.logoPath,
        (SELECT COUNT(id) FROM BusinessUnit WHERE companyId = c.id) as totalBUs,
        (SELECT IF(COUNT(*) > 0, 1, 0) FROM Forum WHERE companyId = c.id AND businessUnitId IS NULL) as hasCorpLevel,
        (SELECT COUNT(DISTINCT CONCAT(COALESCE(businessUnitId,'__corp__'),'|',targetRole)) FROM Forum WHERE companyId = c.id) as totalForums,
        (SELECT COUNT(t.id) FROM ForumTopic t JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id) as totalTopics,
        (SELECT COUNT(r.id) FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id) as totalReplies,
        (
            SELECT COUNT(DISTINCT u.id)
            FROM User u
            WHERE u.companyId = c.id
            AND (
                EXISTS (SELECT 1 FROM ForumTopic t JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id AND t.authorId = u.id AND DATE(t.createdAt) = ?)
                OR
                EXISTS (SELECT 1 FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id AND r.authorId = u.id AND DATE(r.createdAt) = ?)
            )
        ) as activeUsersToday
        FROM Company c
        ORDER BY c.name ASC");
    $stmtC->execute([$today, $today]);
    $companies = $stmtC->fetchAll(PDO::FETCH_ASSOC);
?>

    <div style="margin-bottom: 1.5rem;">
        <h2 style="font-size: 1.5rem; font-weight: 800; color: #1e293b; margin: 0 0 0.5rem 0;">Directorio de Clientes</h2>
        <p style="color: #64748b; margin: 0;">Selecciona una organización para gestionar sus foros internos.</p>
    </div>
    
    <main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; padding: 1.5rem; background: #f8fafc;">
        <?php foreach($companies as $c): ?>
            <a href="index.php?view=forums&step=bus&filter_co=<?= urlencode($c['id']) ?>" style="display: block; text-decoration: none; color: inherit;">
                <div style="background: white; padding: 1.25rem; border-radius: 16px; height: 100%; display: flex; align-items: center; gap: 1rem; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid #e2e8f0;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <div style="width: 50px; height: 50px; border-radius: 8px; background: #e0e7ff; color: #4f46e5; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0; overflow: hidden; border: 1px solid #e5e7eb;">
                        <?php if (!empty($c['logoPath'])): ?>
                            <img src="<?= htmlspecialchars(ltrim($c['logoPath'], '/')) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain; padding: 2px; background: white;">
                        <?php else: ?>
                            <i class='bx bx-briefcase-alt-2'></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 style="font-size: 1.1rem; color: #1e293b; margin: 0 0 0.2rem 0;"><?= htmlspecialchars($c['name']) ?></h3>
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.5rem; display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                            <span><i class='bx bx-network-chart'></i> <?= (int)$c['totalBUs'] ?> Unidades de Negocio</span>
                            <?php if ($c['hasCorpLevel']): ?>
                            <span style="background:#ede9fe;color:#6d28d9;padding:2px 7px;border-radius:4px;font-size:0.7rem;font-weight:700;"><i class='bx bx-buildings'></i> Corporativo</span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <span style="font-size: 0.7rem; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #475569; font-weight: 600;"><i class='bx bx-conversation'></i> <?= (int)$c['totalForums'] ?> Foros</span>
                            <span style="font-size: 0.7rem; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #475569; font-weight: 600;"><i class='bx bx-list-ul'></i> <?= (int)$c['totalTopics'] ?> Hilos</span>
                            <span style="font-size: 0.7rem; background: #f1f5f9; padding: 3px 6px; border-radius: 4px; color: #475569; font-weight: 600;"><i class='bx bx-message-rounded-dots'></i> <?= (int)$c['totalReplies'] ?> Resp.</span>
                            <span style="font-size: 0.7rem; background: #dcfce7; padding: 3px 6px; border-radius: 4px; color: #166534; font-weight: 700;"><i class='bx bx-user-check'></i> <?= (int)$c['activeUsersToday'] ?> Activos Hoy</span>
                        </div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        </div>
    </main>

<?php
// =========================================
// STEP 2: BUSINESS UNITS (Admin y Company_Leader)
// =========================================
elseif ($step === 'bus' && ($isAdmin || $isCoLeader)): 
    if (!$viewCompanyId) { echo "<script>window.location.href='index.php?view=forums';</script>"; exit; }
    
    // Check Nombre de Compañía
    $stmtCoN = $pdo->prepare("SELECT name FROM Company WHERE id = ?");
    $stmtCoN->execute([$viewCompanyId]);
    $coName = $stmtCoN->fetchColumn();

    $stmtB = $pdo->prepare("SELECT id, name FROM BusinessUnit WHERE companyId = ? ORDER BY name");
    $stmtB->execute([$viewCompanyId]);
    $bus = $stmtB->fetchAll();

    if (count($bus) === 0) {
        // Redirigir velozmente a sus foros directos si es empresa aislada sin BUs formales
        echo "<script>window.location.href='index.php?view=forums&step=forums&filter_co=".urlencode($viewCompanyId)."';</script>";
        exit;
    }

    // Si tiene BUs formales, verificar si también existen alumnos/foros huerfanos (Corporativo) paramotrar tarjeta extra
    $stmtCorpU = $pdo->prepare("SELECT COUNT(*) FROM User WHERE companyId = ? AND businessUnitId IS NULL AND role = 'STUDENT'");
    $stmtCorpU->execute([$viewCompanyId]);
    $hasCorpU = $stmtCorpU->fetchColumn() > 0;

    $stmtCorpF = $pdo->prepare("SELECT COUNT(*) FROM Forum WHERE companyId = ? AND businessUnitId IS NULL");
    $stmtCorpF->execute([$viewCompanyId]);
    $hasCorpF = $stmtCorpF->fetchColumn() > 0;

    if ($hasCorpU || $hasCorpF) {
        $bus[] = ['id' => 'corporativo', 'name' => 'Corporativo'];
    }
?>
    <?php if ($isAdmin): ?>
    <a href="index.php?view=forums&step=companies" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem;">
        <i class='bx bx-left-arrow-alt'></i> Volver a Empresas
    </a>
    <?php endif; ?>

    <div style="margin-bottom: 2rem; display: flex; align-items: center; gap: 0.5rem; color: #64748b; font-weight: 600; font-size: 0.9rem;">
        <i class='bx bx-briefcase-alt-2'></i> <?= htmlspecialchars($coName) ?> <i class='bx bx-chevron-right' style="color:#cbd5e1;"></i> <i class='bx bx-network-chart'></i> Selecciona una Unidad de Negocio
    </div>

    <main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; padding: 1.5rem; background: #f8fafc;">
        <?php foreach($bus as $b): ?>
            <a href="index.php?view=forums&step=forums&filter_co=<?= urlencode($viewCompanyId) ?>&filter_bu=<?= urlencode($b['id']) ?>" style="display: block; text-decoration: none; color: inherit;">
                <div style="background: white; padding: 1.25rem; border-radius: 16px; height: 100%; display: flex; align-items: center; gap: 1rem; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; border: 1px solid #e2e8f0;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='none'; this.style.boxShadow='none';">
                    <div style="width: 50px; height: 50px; border-radius: 8px; background: #fef08a; color: #854d0e; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; flex-shrink: 0;">
                        <i class='bx bx-store'></i>
                    </div>
                    <div>
                        <h3 style="font-size: 1.1rem; color: #1e293b; margin: 0 0 0.2rem 0;"><?= htmlspecialchars($b['name']) ?></h3>
                        <div style="font-size: 0.8rem; color: #64748b;">Ingresar a foros</div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>


<?php
// =========================================
// STEP 3: FOROS
// =========================================
elseif ($step === 'forums'): 

    // Extraer Roles Formativos del Usuario Común
    $myTrainingRoles = [];
    if (!$isAdmin) {
        $stmtTR = $pdo->prepare("SELECT A FROM _TrainingRoleToUser WHERE B = ?");
        $stmtTR->execute([$userId]);
        $myTrainingRoles = $stmtTR->fetchAll(PDO::FETCH_COLUMN);
    }

    // Auto-Provisionamiento (INSERT IGNORE previene duplicados)
    if ($viewCompanyId) {
        $whereSql = "companyId = :co AND ";
        $paramsProv = [':co' => $viewCompanyId];
        if ($viewBuId && $viewBuId !== 'corporativo') {
            $whereSql .= "businessUnitId = :bu";
            $paramsProv[':bu'] = $viewBuId;
        } else {
            $whereSql .= "businessUnitId IS NULL";
            $viewBuId = null; // Normalizar
        }

        $stmtGen = $pdo->prepare("SELECT id FROM Forum WHERE $whereSql AND targetRole = 'GENERAL'");
        $stmtGen->execute($paramsProv);

        if (!$stmtGen->fetch()) {
            $fId = generateCuid();
            $pdo->prepare("INSERT IGNORE INTO Forum (id, companyId, businessUnitId, targetRole, title, description, createdAt, updatedAt) VALUES (?, ?, ?, 'GENERAL', 'Foro General', 'Espacio abierto para debatir, compartir ideas y anuncios.', NOW(), NOW())")
                ->execute([$fId, $viewCompanyId, $viewBuId]);

            // Los foros de TrainingRole SOLO se crean en contexto de BU.
            // El nivel Corporativo (businessUnitId IS NULL) únicamente tiene el Foro General.
            if ($viewBuId) {
                $stmtRoles = $pdo->query("SELECT id, name FROM TrainingRole WHERE name != 'Gestor de Aprendizaje'");
                while($tr = $stmtRoles->fetch()) {
                    $trFId = generateCuid();
                    $title = "Foro de " . $tr['name'];
                    // El foro de Lector Operativo tiene descripción abierta: todos pueden responder
                    $isLoRole = str_contains(strtolower($tr['name']), 'lector') && str_contains(strtolower($tr['name']), 'operativo');
                    $desc = $isLoRole
                        ? 'Responde las dudas y propuestas de tu equipo operativo. Todos pueden participar aquí.'
                        : 'Espacio de colaboración para perfiles formativos: ' . $tr['name'];
                    $pdo->prepare("INSERT IGNORE INTO Forum (id, companyId, businessUnitId, targetRole, title, description, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
                        ->execute([$trFId, $viewCompanyId, $viewBuId, $tr['id'], $title, $desc]);
                }
            }
        }

        // Si el usuario tiene una BU real, asegurar que el foro Corporativo (empresa completa) exista.
        // Esto permite mostrar el panel Corporativo a todos los usuarios con BU sin depender
        // de que un Admin haya navegado al nivel Corporativo previamente.
        if ($viewBuId) {
            $stmtGenCorp = $pdo->prepare("SELECT id FROM Forum WHERE companyId = ? AND businessUnitId IS NULL AND targetRole = 'GENERAL'");
            $stmtGenCorp->execute([$viewCompanyId]);
            if (!$stmtGenCorp->fetch()) {
                $corpId = generateCuid();
                $pdo->prepare("INSERT IGNORE INTO Forum (id, companyId, businessUnitId, targetRole, title, description, createdAt, updatedAt) VALUES (?, ?, NULL, 'GENERAL', 'Foro General Corporativo', 'Espacio de comunicación abierto a toda la empresa.', NOW(), NOW())")
                    ->execute([$corpId, $viewCompanyId]);
            }
        }
    }

    // Extracción de Foros Filtrados
    $forums = [];
    if ($viewCompanyId) {
        $wForm = "companyId = :co AND ";
        $paramsF = [':co' => $viewCompanyId];
        
        if ($viewBuId === 'corporativo') { 
            $wForm .= "businessUnitId IS NULL"; 
        } elseif ($viewBuId) { 
            $wForm .= "businessUnitId = :bu"; 
            $paramsF[':bu'] = $viewBuId;
        } else { 
            $wForm .= "businessUnitId IS NULL"; 
        }

        $isLeaderAdmin = ($isAdmin || $isCoLeader || $isBuLeader);

        // Detectar TrainingRoles del usuario para control de visibilidad del foro LO
        $isLectorOp = false;
        $isModelador = false;
        if (!$isLeaderAdmin && $userId) {
            $stmtRoleNames = $pdo->prepare("SELECT LOWER(tr.name) FROM TrainingRole tr JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id WHERE rtu.B = ?");
            $stmtRoleNames->execute([$userId]);
            foreach ($stmtRoleNames->fetchAll(PDO::FETCH_COLUMN) as $nm) {
                if (str_contains($nm, 'lector') && str_contains($nm, 'operativo')) $isLectorOp = true;
                if (str_contains($nm, 'modelad')) $isModelador = true;
            }
        }

        if (!$isLeaderAdmin) {
            // Obtener el ID del TrainingRole de Lector Operativo
            $stmtLORole = $pdo->prepare("SELECT id FROM TrainingRole WHERE LOWER(name) LIKE '%lector%operativo%' LIMIT 1");
            $stmtLORole->execute();
            $loRoleId = $stmtLORole->fetchColumn();

            if (count($myTrainingRoles) > 0) {
                $inSet = implode(',', array_map(function($id) use ($pdo) { return $pdo->quote($id); }, $myTrainingRoles));
                // El foro LO se incluye solo para Modeladores (los LO ya lo tienen en $inSet por su propio rol)
                $loExtra = ($loRoleId && $isModelador) ? ", " . $pdo->quote($loRoleId) : '';
                $wForm .= " AND targetRole IN ('GENERAL', $inSet$loExtra)";
            } else {
                $loExtra = ($loRoleId && $isModelador) ? " OR targetRole = " . $pdo->quote($loRoleId) : '';
                $wForm .= " AND (targetRole = 'GENERAL'$loExtra)";
            }
        }
        // Si ES Leader o Admin, $wForm tal cual (saca TODOS los foros de esa BU)

        $stmtForums = $pdo->prepare("
            SELECT f.*, 
                   tr.name as trName,
                   (SELECT COUNT(id) FROM ForumTopic WHERE forumId = f.id) as totalTopics,
                   (SELECT COUNT(r.id) FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id WHERE t.forumId = f.id) as totalReplies
            FROM Forum f
            LEFT JOIN TrainingRole tr ON f.targetRole = tr.id
            WHERE $wForm
            ORDER BY targetRole ASC, title ASC
        ");
        $stmtForums->execute($paramsF);
        $forums = $stmtForums->fetchAll();
    }
?>

    <?php if ($isAdmin || $isCoLeader): ?>
        <?php 
            $hasBUs = true;
            if ($viewCompanyId) {
                $stmtxB = $pdo->prepare("SELECT count(id) FROM BusinessUnit WHERE companyId = ?");
                $stmtxB->execute([$viewCompanyId]);
                $hasBUs = $stmtxB->fetchColumn() > 0;
            }
            
            $backToken = "";
            $backText = "Volver a Unidades";
            $showBackBtn = true;
            
            if (!$hasBUs) {
                if ($isAdmin) {
                    $backToken = "&step=companies";
                    $backText = "Volver a Empresas";
                } else {
                    $showBackBtn = false; // CoLeader sin BUs no tiene a donde volver
                }
            } else {
                if ($isAdmin) {
                    $backToken = "&step=bus&filter_co=".urlencode($viewCompanyId);
                } else {
                    $backToken = "&step=bus";
                }
            }
        ?>
        <?php if ($showBackBtn): ?>
        <a href="index.php?view=forums<?= $backToken ?>" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem;">
            <i class='bx bx-left-arrow-alt'></i> <?= htmlspecialchars($backText) ?>
        </a>
        <?php endif; ?>
    <?php endif; ?>

    <?php
    $displayBuName = 'Corporativo';
    if ($viewBuId) {
        if ($viewBuId === 'corporativo') {
            $displayBuName = 'Corporativo';
        } else {
            $stmtBuName = $pdo->prepare("SELECT name FROM BusinessUnit WHERE id = ?");
            $stmtBuName->execute([$viewBuId]);
            $fetchName = $stmtBuName->fetchColumn();
            if ($fetchName) {
                $displayBuName = mb_strtoupper(mb_substr($fetchName, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($fetchName, 1, null, 'UTF-8');
            }
        }
    }
    // Detectar si el usuario es LECTOR OPERATIVO (antes de abrir el <main>)
    $isLectorOp = false;
    if (!$isAdmin && !$isCoLeader && !$isBuLeader && $userId) {
        $stmtLO = $pdo->prepare("
            SELECT tr.name FROM TrainingRole tr
            JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id
            WHERE rtu.B = ? AND LOWER(tr.name) LIKE '%lector%operativo%'
            LIMIT 1
        ");
        $stmtLO->execute([$userId]);
        $isLectorOp = (bool)$stmtLO->fetchColumn();
    }
    ?>
    <?php if ($isLectorOp):
        // Resolver el forum_id del foro LO para redirigir
        $loRedirectForumId = null;
        foreach ($forums as $lf) {
            if ($lf['targetRole'] !== 'GENERAL') { $loRedirectForumId = $lf['id']; break; }
        }
        if (!$loRedirectForumId && !empty($forums)) {
            $loRedirectForumId = $forums[0]['id'];
        }
    ?>
    <?php if ($loRedirectForumId): ?>
    <script>
        // Redirigir al LO directamente a la vista moderna de lista del foro
        window.location.replace('index.php?view=forum_topic&forum_id=<?= urlencode($loRedirectForumId) ?>');
    </script>
    <!-- Fallback visual mientras redirige -->
    <div style="display:flex;align-items:center;justify-content:center;padding:4rem;color:#6b7280;">
        <i class='bx bx-loader-alt bx-spin' style="font-size:2rem;margin-right:0.75rem;color:#10b981;"></i>
        <span style="font-size:0.9rem;font-weight:600;">Cargando tu foro...</span>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:4rem 1rem;color:#9ca3af;">
        <i class='bx bx-comment-x' style="font-size:3rem;margin-bottom:1rem;display:block;"></i>
        <p>Aún no tienes foros asignados.</p>
    </div>
    <?php endif; ?>




                    <i class='bx bx-book-reader' style="font-size:2rem;color:white;"></i>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.375rem;">
                        <span style="background:rgba(255,255,255,0.2);color:white;font-size:0.65rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;padding:2px 10px;border-radius:999px;border:1px solid rgba(255,255,255,0.3);">Lector Operativo</span>
                        <span style="background:rgba(255,255,255,0.15);color:rgba(255,255,255,0.9);font-size:0.65rem;font-weight:700;padding:2px 10px;border-radius:999px;">Foro Especializado</span>
                    </div>
                    <h1 style="font-size:1.5rem;font-weight:800;margin:0 0 0.25rem;color:white;">Foro Lector Operativo</h1>
                    <p style="font-size:0.8rem;color:rgba(255,255,255,0.8);margin:0;">Tu espacio para preguntar, proponer y compartir con tu equipo.</p>
                </div>
            </div>
            <button onclick="document.getElementById('modalLO').classList.add('active')" style="display:inline-flex;align-items:center;gap:0.5rem;background:white;color:#059669;font-weight:700;font-size:0.875rem;padding:0.7rem 1.25rem;border-radius:1rem;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:all 0.2s;white-space:nowrap;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class='bx bx-edit-alt'></i> Nuevo mensaje
            </button>
        </div>
    </div>

    <!-- MODAL INLINE — Solo Lector Operativo -->
    <?php
    // Buscar el foro específico del Lector Operativo (no el General)
    $loSpecificForumId = null;
    $loSpecificForumTitle = 'Tu foro de equipo';
    foreach ($forums as $lf) {
        if ($lf['targetRole'] !== 'GENERAL') {
            $loSpecificForumId = $lf['id'];
            $loSpecificForumTitle = $lf['title'] ?? 'Foro Lector Operativo';
            break;
        }
    }
    // Fallback: si solo existe GENERAL, usarlo
    if (!$loSpecificForumId && !empty($forums)) {
        $loSpecificForumId = $forums[0]['id'];
        $loSpecificForumTitle = $forums[0]['title'];
    }
    ?>
    <div class="modal-overlay" id="modalLO" style="z-index:10000;">
        <div class="modal-content" style="max-width:600px;">
            <div class="modal-header" style="background:#fff7f0;border-bottom:2px solid #ffe4cc;">
                <div>
                    <h3 class="modal-title" style="margin:0;color:#1e293b;">Nuevo mensaje</h3>
                    <p style="margin:0.2rem 0 0;font-size:0.8rem;color:#92400e;font-weight:600;">
                        <i class='bx bx-chat'></i> <?= htmlspecialchars($loSpecificForumTitle) ?>
                    </p>
                </div>
                <button class="modal-close" onclick="document.getElementById('modalLO').classList.remove('active')">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" action="index.php?view=forums">
                <input type="hidden" name="action" value="lo_create_topic">
                <input type="hidden" name="lo_forum_id" value="<?= htmlspecialchars($loSpecificForumId ?? '') ?>">
                <input type="hidden" name="threadType" id="loThreadType" value="">

                <!-- 3 botones de intención -->
                <div class="form-group" style="margin-bottom:1.25rem;">
                    <label class="form-label" style="margin-bottom:0.75rem;display:block;font-weight:700;">¿Qué quieres hacer? <span style="color:#ef4444;">*</span></label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;">
                        <button type="button" onclick="setLOIntent('QUESTION',this)" id="lo_btn_Q"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-help-circle' style="font-size:1.35rem;color:#2563eb;"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.75rem;color:#1e293b;">Tengo una pregunta</div>
                        </button>
                        <button type="button" onclick="setLOIntent('IMPROVEMENT',this)" id="lo_btn_I"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-bulb' style="font-size:1.35rem;color:#d97706;"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.75rem;color:#1e293b;">Tengo una propuesta</div>
                        </button>
                        <button type="button" onclick="setLOIntent('CONTRIBUTION',this)" id="lo_btn_C"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="width:40px;height:40px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-share-alt' style="font-size:1.35rem;color:#FF6A00;"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.75rem;color:#1e293b;">Quiero compartir algo</div>
                        </button>
                    </div>
                    <div id="loIntentErr" style="color:#ef4444;font-size:0.8rem;margin-top:0.5rem;display:none;">Por favor selecciona una opción.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Título</label>
                    <input type="text" name="title" class="form-control" required maxlength="150" placeholder="¿Sobre qué es tu mensaje?">
                </div>
                <div class="form-group">
                    <label class="form-label">Detalle</label>
                    <textarea name="content" class="form-control" style="height:110px;resize:vertical;" required placeholder="Explica con más detalle..."></textarea>
                </div>

                <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1.5rem;">
                    <button type="button" class="btn" style="background:var(--bg-color);color:var(--text-main);"
                        onclick="document.getElementById('modalLO').classList.remove('active')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" onclick="return validateLO()">
                        <i class='bx bx-send'></i> Publicar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function setLOIntent(type, el) {
        document.getElementById('loThreadType').value = type;
        ['lo_btn_Q','lo_btn_I','lo_btn_C'].forEach(id => {
            const b = document.getElementById(id);
            b.style.border = '2px solid #e2e8f0';
            b.style.background = '#f8fafc';
        });
        el.style.border = '2px solid #FF6A00';
        el.style.background = '#fff7f0';
        document.getElementById('loIntentErr').style.display = 'none';
    }
    function validateLO() {
        if (!document.getElementById('loThreadType').value) {
            document.getElementById('loIntentErr').style.display = 'block';
            return false;
        }
        return true;
    }
    </script>

    <?php if (count($forums) > 0):
        // Usar SOLO el foro específico del Lector Operativo (no GENERAL)
        $loFeedForumId = null;
        foreach ($forums as $lff) {
            if ($lff['targetRole'] !== 'GENERAL') { $loFeedForumId = $lff['id']; break; }
        }
        if (!$loFeedForumId && !empty($forums)) $loFeedForumId = $forums[0]['id'];

        // 📌 Respuestas útiles (validadas o pinned del foro LO)
        $stmtPin = $pdo->prepare("
            SELECT t.id, t.title, t.forumId,
                   COALESCE(u.name,'[Usuario eliminado]') as authorName,
                   (SELECT COUNT(*) FROM ForumReply WHERE topicId = t.id) as replies
            FROM ForumTopic t
            LEFT JOIN User u ON t.authorId = u.id
            WHERE t.forumId = ?
              AND (t.isValidatedPractice = 1 OR t.isPinned = 1)
            ORDER BY t.isPinned DESC, t.updatedAt DESC
            LIMIT 5
        ");
        $stmtPin->execute([$loFeedForumId]);
        $pinnedTopics = $stmtPin->fetchAll();

        // 💬 Actividad reciente SOLO del foro LO
        $stmtRec = $pdo->prepare("
            SELECT t.id, t.title, t.forumId, t.updatedAt,
                   COALESCE(u.name,'[Usuario eliminado]') as authorName,
                   (SELECT COUNT(*) FROM ForumReply WHERE topicId = t.id) as replies
            FROM ForumTopic t
            LEFT JOIN User u ON t.authorId = u.id
            WHERE t.forumId = ?
              AND t.isValidatedPractice = 0
            ORDER BY t.updatedAt DESC
            LIMIT 8
        ");
        $stmtRec->execute([$loFeedForumId]);
        $recentTopics = $stmtRec->fetchAll();
    ?>
    <div style="padding:0 0 2rem;">

        <?php if (!empty($pinnedTopics)): ?>
        <!-- RESPUESTAS ÚTILES -->
        <div style="margin-bottom:1.25rem;">
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.875rem;">
                <div style="width:28px;height:28px;background:#dcfce7;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class='bx bxs-star' style="color:#059669;font-size:0.9rem;"></i></div>
                <h3 style="margin:0;font-size:0.9rem;font-weight:800;color:#111827;">Respuestas útiles para tu equipo</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:0.625rem;">
            <?php foreach ($pinnedTopics as $pt): ?>
                <a href="index.php?view=forum_topic&forum_id=<?= urlencode($pt['forumId']) ?>&topic_id=<?= urlencode($pt['id']) ?>"
                   style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:1rem;text-decoration:none;transition:all 0.2s;"
                   onmouseover="this.style.background='#dcfce7';this.style.transform='translateY(-1px)'" onmouseout="this.style.background='#f0fdf4';this.style.transform='translateY(0)'">
                    <div style="min-width:0;">
                        <div style="font-weight:700;font-size:0.875rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($pt['title']) ?></div>
                        <div style="font-size:0.72rem;color:#059669;margin-top:0.2rem;font-weight:600;">por <?= htmlspecialchars($pt['authorName']) ?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.3rem;color:#059669;font-size:0.8rem;font-weight:700;flex-shrink:0;margin-left:1rem;background:#dcfce7;padding:4px 10px;border-radius:999px;">
                        <i class='bx bx-message-rounded-dots'></i> <?= (int)$pt['replies'] ?>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ACTIVIDAD RECIENTE -->
        <?php if (!empty($recentTopics)): ?>
        <div style="margin-bottom:5rem;">
            <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.875rem;">
                <div style="width:28px;height:28px;background:#f1f5f9;border-radius:8px;display:flex;align-items:center;justify-content:center;"><i class='bx bx-time' style="color:#64748b;font-size:0.9rem;"></i></div>
                <h3 style="margin:0;font-size:0.9rem;font-weight:800;color:#111827;">Actividad reciente en tu unidad</h3>
            </div>
            <div style="display:flex;flex-direction:column;gap:0.625rem;">
            <?php foreach ($recentTopics as $rt): ?>
                <a href="index.php?view=forum_topic&forum_id=<?= urlencode($rt['forumId']) ?>&topic_id=<?= urlencode($rt['id']) ?>"
                   style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;background:white;border:1px solid #f3f4f6;border-radius:1rem;text-decoration:none;transition:all 0.2s;box-shadow:0 1px 2px rgba(0,0,0,0.04);"
                   onmouseover="this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)';this.style.borderColor='#bbf7d0';this.style.transform='translateY(-1px)'" onmouseout="this.style.boxShadow='0 1px 2px rgba(0,0,0,0.04)';this.style.borderColor='#f3f4f6';this.style.transform='translateY(0)'">
                    <div style="min-width:0;">
                        <div style="font-weight:600;font-size:0.875rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($rt['title']) ?></div>
                        <div style="font-size:0.72rem;color:#6b7280;margin-top:0.2rem;">por <?= htmlspecialchars($rt['authorName']) ?> · <?= date('d/m H:i', strtotime($rt['updatedAt'])) ?></div>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.3rem;color:#9ca3af;font-size:0.78rem;font-weight:600;flex-shrink:0;margin-left:1rem;">
                        <i class='bx bx-message-rounded-dots'></i> <?= (int)$rt['replies'] ?>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
        <?php elseif (empty($pinnedTopics)): ?>
        <div style="text-align:center;padding:4rem 1rem;">
            <div style="width:64px;height:64px;background:#f0fdf4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;"><i class='bx bx-comment-dots' style="font-size:2rem;color:#10b981;"></i></div>
            <p style="margin:0;font-size:0.9rem;color:#6b7280;font-weight:600;">Aún no hay actividad en tu unidad.<br><span style="font-weight:400;">¡Sé el primero en preguntar!</span></p>
        </div>
        <?php endif; ?>

    </div>

    <!-- FAB — actualizado a verde del sistema LO -->
    <button onclick="document.getElementById('modalLO').classList.add('active')"
       style="position:fixed;bottom:2rem;right:2rem;background:linear-gradient(135deg,#10b981,#059669);color:white;padding:0.9rem 1.4rem;border-radius:50px;border:none;cursor:pointer;font-weight:800;font-size:0.95rem;box-shadow:0 4px 20px rgba(16,185,129,0.45);display:flex;align-items:center;gap:0.5rem;z-index:9999;transition:all 0.2s;font-family:inherit;"
       onmouseover="this.style.transform='scale(1.06)';this.style.boxShadow='0 8px 28px rgba(16,185,129,0.5)'" onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 20px rgba(16,185,129,0.45)'">
        <i class='bx bx-edit' style="font-size:1.1rem;"></i> Nuevo mensaje
    </button>

        </main>


    <?php else: // LO sin foros — estado vacío ?>
        <div style="text-align:center;padding:4rem 1rem;color:#9ca3af;">
            <i class='bx bx-comment-x' style="font-size:3rem;margin-bottom:1rem;display:block;"></i>
            <p>Aún no tienes foros asignados.</p>
        </div>
        </main>

    <?php endif; // fin if(count>0) dentro del bloque LO ?>

<?php else: // ── NON-LO — vista moderna de canales ──

        $buTotalTopics  = array_sum(array_column($forums, 'totalTopics'));
        $buTotalReplies = array_sum(array_column($forums, 'totalReplies'));
        $buTotalForums  = count($forums);

        $myTopicCount = 0;
        if ($userId && $buTotalForums > 0) {
            $fIds = array_column($forums, 'id');
            $ph   = implode(',', array_fill(0, count($fIds), '?'));
            $stMy = $pdo->prepare("SELECT COUNT(*) FROM ForumTopic WHERE authorId = ? AND forumId IN ($ph)");
            $stMy->execute(array_merge([$userId], $fIds));
            $myTopicCount = (int)$stMy->fetchColumn();
        }

        if (!function_exists('forumCardStyle')) {
            function forumCardStyle($trName, $targetRole) {
                $k = strtolower($trName ?: $targetRole);
                if ($targetRole === 'GENERAL')               return ['bx-conversation',   '135deg,#3b82f6,#2563eb'];
                if (str_contains($k,'auditor'))              return ['bx-shield',          '135deg,#ef4444,#dc2626'];
                if (str_contains($k,'modelad'))              return ['bx-cube-alt',        '135deg,#8b5cf6,#7c3aed'];
                if (str_contains($k,'integrad'))             return ['bx-git-branch',      '135deg,#14b8a6,#0d9488'];
                if (str_contains($k,'lector'))               return ['bx-book-reader',     '135deg,#10b981,#059669'];
                if (str_contains($k,'coordinad'))            return ['bx-grid-alt',        '135deg,#f59e0b,#d97706'];
                if (str_contains($k,'analista'))             return ['bx-bar-chart-alt-2', '135deg,#6366f1,#4f46e5'];
                if (str_contains($k,'aprob'))                return ['bx-check-shield',    '135deg,#ec4899,#db2777'];
                if (str_contains($k,'participante'))         return ['bx-group',           '135deg,#06b6d4,#0891b2'];
                if (str_contains($k,'ti') || str_contains($k,'admin ti')) return ['bx-chip','135deg,#64748b,#475569'];
                return                                              ['bx-star',             '135deg,#FF6A00,#FFA500'];
            }
        }

        // Foro Corporativo (GENERAL de empresa, sin BU) — solo para usuarios que SÍ tienen BU asignada.
        // Si el usuario no tiene BU ($myBuId === null), ya está viendo foros a nivel Corporativo
        // como su vista principal, por lo que mostrar este panel causaria duplicados.
        $corpForums = [];
        if (!$isAdmin && !$isCoLeader && $viewCompanyId && $myBuId) {
            $stmtCorp = $pdo->prepare("
                SELECT f.*,
                       tr.name as trName,
                       (SELECT COUNT(id) FROM ForumTopic WHERE forumId = f.id) as totalTopics,
                       (SELECT COUNT(r.id) FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id WHERE t.forumId = f.id) as totalReplies
                FROM Forum f
                LEFT JOIN TrainingRole tr ON f.targetRole = tr.id
                WHERE f.companyId = ?
                  AND f.businessUnitId IS NULL
                  AND f.targetRole = 'GENERAL'
                LIMIT 1
            ");
            $stmtCorp->execute([$viewCompanyId]);
            $corpForums = $stmtCorp->fetchAll();
        }
    ?>

    <h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 1.5rem 0;">Foros</h1>

    <!-- ── STATS — misma estructura que Inicio/Certificados ── -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;width:100%;">


        <!-- Canales — dark card -->
        <div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1rem;padding:1.5rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);border:1px solid #374151;color:white;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:96px;height:96px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(40px);"></div>
            <div style="position:relative;z-index:10;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                    <div style="width:48px;height:48px;border-radius:1rem;background:rgba(255,106,0,0.2);display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,106,0,0.3);">
                        <i class='bx bx-conversation' style="color:#FF6A00;font-size:1.5rem;"></i>
                    </div>
                    <h3 style="font-size:0.875rem;font-weight:600;color:#d1d5db;margin:0;">Foros</h3>
                </div>
                <p style="font-size:2.25rem;font-weight:700;color:white;margin:0;"><?= $buTotalForums ?></p>
                <p style="font-size:0.75rem;color:#9ca3af;margin:4px 0 0;">Foros activos en esta unidad</p>
            </div>
        </div>

        <!-- Hilos -->
        <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;">
                    <i class='bx bx-list-ul' style="color:white;font-size:1.5rem;"></i>
                </div>
                <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Hilos</h3>
            </div>
            <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $buTotalTopics ?></p>
            <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Discusiones totales</p>
        </div>

        <!-- Respuestas -->
        <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#a855f7,#7c3aed);display:flex;align-items:center;justify-content:center;">
                    <i class='bx bx-message-rounded-dots' style="color:white;font-size:1.5rem;"></i>
                </div>
                <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Respuestas</h3>
            </div>
            <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $buTotalReplies ?></p>
            <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Participaciones totales</p>
        </div>

        <!-- Mis temas -->
        <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;">
                    <i class='bx bx-edit' style="color:white;font-size:1.5rem;"></i>
                </div>
                <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Mis temas</h3>
            </div>
            <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $myTopicCount ?></p>
            <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Publicados por mí</p>
        </div>

    </div>

    <!-- ── PANEL CANALES (nuevo panel blanco para las cards) ── -->
    <div style="background:white;border-radius:24px;box-shadow:0 10px 40px rgba(0,0,0,0.03);overflow:hidden;border:1px solid rgba(0,0,0,0.04);margin-bottom:2rem;">

        <!-- Section title -->
        <div style="padding:1.25rem 1.5rem 0.75rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;">
            <div>
                <h2 style="margin:0;font-size:1rem;font-weight:800;color:#1e293b;">Foros de <?= htmlspecialchars($displayBuName) ?></h2>
                <p style="margin:0;font-size:0.78rem;color:#64748b;margin-top:0.15rem;">Selecciona un foro para ver y participar en sus discusiones</p>
            </div>
        </div>

        <!-- Forum cards -->
        <?php if ($buTotalForums === 0): ?>
            <div style="text-align:center;padding:4rem;color:#9ca3af;">
                <i class='bx bx-message-alt-x' style="font-size:3rem;margin-bottom:1rem;display:block;"></i>
                <h3 style="margin:0 0 0.5rem;">Sin Canales Disponibles</h3>
                <p style="margin:0;font-size:0.9rem;">Aún no se han generado canales de discusión para este segmento.</p>
            </div>
        <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:1rem;padding:1.25rem 1.5rem 1.5rem;">
            <?php foreach ($forums as $forum):
                [$icon, $grad] = forumCardStyle($forum['trName'] ?? '', $forum['targetRole']);
                // Detectar si es el foro LO
                $trNameLower = strtolower($forum['trName'] ?? '');
                $isLoCard = str_contains($trNameLower, 'lector') && str_contains($trNameLower, 'operativo');
                // Ocultar card LO para roles no autorizados (que no sean LO, Modelador ni Líder)
                if ($isLoCard && !$isLectorOp && !$isModelador && !$isCoLeader && !$isBuLeader && !$isAdmin) {
                    continue;
                }
                // Mostrar como card de apoyo (ambar) si es Modelador o Líder viendo el foro LO
                $showAsSupport = $isLoCard && !$isLectorOp && ($isModelador || $isCoLeader || $isBuLeader);
            ?>
                <div class="forum-card-wrap">
                <a href="index.php?view=forum_topic&forum_id=<?= urlencode($forum['id']) ?>"
                   style="display:block;text-decoration:none;color:inherit;">
                    <div style="background:<?= $showAsSupport ? '#fffbeb' : '#f8fafc' ?>;border-radius:16px;padding:1.25rem;
                                border:1px solid <?= $showAsSupport ? '#fde68a' : '#e2e8f0' ?>;cursor:pointer;
                                transition:transform 0.2s,box-shadow 0.2s,border-color 0.2s;height:100%;display:flex;flex-direction:column;"
                         onmouseover="this.style.transform='translateY(-4px)';this.style.boxShadow='0 12px 24px -4px rgba(0,0,0,0.1)';this.style.borderColor='<?= $showAsSupport ? '#f59e0b' : '#FF6A00' ?>';this.style.background='white';"
                         onmouseout="this.style.transform='none';this.style.boxShadow='none';this.style.borderColor='<?= $showAsSupport ? '#fde68a' : '#e2e8f0' ?>';this.style.background='<?= $showAsSupport ? '#fffbeb' : '#f8fafc' ?>';">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.9rem;">
                            <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(<?= $grad ?>);
                                        display:flex;align-items:center;justify-content:center;flex-shrink:0;
                                        box-shadow:0 4px 8px rgba(0,0,0,0.15);">
                                <i class='bx <?= $showAsSupport ? 'bx-chat' : $icon ?>' style="font-size:1.3rem;color:white;"></i>
                            </div>
                            <?php if ($forum['targetRole'] === 'GENERAL'): ?>
                            <span style="background:#eff6ff;color:#2563eb;padding:0.2rem 0.5rem;border-radius:20px;font-size:0.6rem;font-weight:800;text-transform:uppercase;">General</span>
                            <?php elseif ($showAsSupport): ?>
                            <span style="background:#fef3c7;color:#92400e;padding:0.2rem 0.5rem;border-radius:20px;font-size:0.6rem;font-weight:800;"><i class='bx bx-group'></i> Apoya</span>
                            <?php endif; ?>
                        </div>
                        <h3 style="font-size:0.95rem;font-weight:800;color:#1e293b;margin:0 0 0.35rem;line-height:1.3;">
                            <?= $showAsSupport ? 'Apoya a tu Operador' : htmlspecialchars($forum['title']) ?>
                        </h3>
                        <p style="font-size:0.78rem;color:<?= $showAsSupport ? '#78350f' : '#64748b' ?>;line-height:1.5;margin:0 0 1rem;
                                   display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;flex:1;">
                            <?= $showAsSupport ? 'Responde las dudas y propuestas de tu equipo. Tu ayuda marca la diferencia.' : htmlspecialchars($forum['description']) ?>
                        </p>
                        <div style="display:flex;gap:0.75rem;border-top:1px solid <?= $showAsSupport ? '#fde68a' : '#e2e8f0' ?>;padding-top:0.75rem;margin-top:auto;">
                            <div style="display:flex;align-items:center;gap:0.3rem;color:#64748b;font-size:0.75rem;font-weight:600;">
                                <i class='bx bx-list-ul' style="color:#3b82f6;"></i>
                                <span><?= (int)$forum['totalTopics'] ?> hilos</span>
                            </div>
                            <div style="display:flex;align-items:center;gap:0.3rem;color:#64748b;font-size:0.75rem;font-weight:600;">
                                <i class='bx bx-message-rounded-dots' style="color:#8b5cf6;"></i>
                                <span><?= (int)$forum['totalReplies'] ?> respuestas</span>
                            </div>
                        </div>
                    </div>
                </a>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div><!-- fin panel foros BU -->
    <?php endif; ?>

    <!-- ── PANEL CORPORATIVO — Foro General de empresa (visible para estudiantes y BU Leader) ── -->
    <?php if (!empty($corpForums)): ?>
    <div style="background:white;border-radius:24px;box-shadow:0 10px 40px rgba(0,0,0,0.03);overflow:hidden;border:1px solid rgba(0,0,0,0.04);margin-top:1.5rem;margin-bottom:2rem;">
        <div style="padding:1.25rem 1.5rem 0.75rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #f1f5f9;">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <div style="width:36px;height:36px;border-radius:10px;background:linear-gradient(135deg,#6366f1,#4f46e5);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class='bx bx-buildings' style="color:white;font-size:1.1rem;"></i>
                </div>
                <div>
                    <h2 style="margin:0;font-size:1rem;font-weight:800;color:#1e293b;">Foros Corporativos</h2>
                    <p style="margin:0;font-size:0.78rem;color:#64748b;margin-top:0.15rem;">Espacio de comunicación abierto a toda la empresa</p>
                </div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:1rem;padding:1.25rem 1.5rem 1.5rem;">
        <?php foreach ($corpForums as $cf):
            [$cfIcon, $cfGrad] = forumCardStyle($cf['trName'] ?? '', $cf['targetRole']);
        ?>
            <div class="forum-card-wrap">
            <a href="index.php?view=forum_topic&forum_id=<?= urlencode($cf['id']) ?>"
               style="display:block;text-decoration:none;color:inherit;">
                <div style="background:#f8fafc;border-radius:16px;padding:1.25rem;border:1px solid #e2e8f0;cursor:pointer;
                            transition:transform 0.2s,box-shadow 0.2s,border-color 0.2s;height:100%;display:flex;flex-direction:column;"
                     onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';this.style.borderColor='#6366f1';"
                     onmouseout="this.style.transform='none';this.style.boxShadow='none';this.style.borderColor='#e2e8f0';">
                    <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:0.75rem;">
                        <div style="width:40px;height:40px;border-radius:10px;background:linear-gradient(<?= $cfGrad ?>);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class='bx <?= $cfIcon ?>' style="color:white;font-size:1.25rem;"></i>
                        </div>
                        <div>
                            <h3 style="margin:0;font-size:0.9rem;font-weight:700;color:#1e293b;"><?= htmlspecialchars($cf['title'] ?? 'Foro General') ?></h3>
                            <span style="font-size:0.72rem;color:#6366f1;font-weight:600;background:#eef2ff;padding:2px 8px;border-radius:999px;">Corporativo</span>
                        </div>
                    </div>
                    <p style="margin:0 0 auto;font-size:0.82rem;color:#64748b;line-height:1.5;"><?= htmlspecialchars($cf['description'] ?? '') ?></p>
                    <div style="display:flex;gap:0.75rem;border-top:1px solid #e2e8f0;padding-top:0.75rem;margin-top:0.75rem;">
                        <div style="display:flex;align-items:center;gap:0.3rem;color:#64748b;font-size:0.75rem;font-weight:600;">
                            <i class='bx bx-list-ul' style="color:#6366f1;"></i>
                            <span><?= (int)$cf['totalTopics'] ?> hilos</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:0.3rem;color:#64748b;font-size:0.75rem;font-weight:600;">
                            <i class='bx bx-message-rounded-dots' style="color:#8b5cf6;"></i>
                            <span><?= (int)$cf['totalReplies'] ?> respuestas</span>
                        </div>
                    </div>
                </div>
            </a>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

<?php endif; // fin if($isLectorOp) - rama no-LO ?>






