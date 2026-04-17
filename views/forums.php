<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$userId = $_SESSION['user_id'] ?? '';
$isAdmin = ($userRole === 'ADMIN');
$isCoLeader = ($userRole === 'COMPANY_LEADER');
$isBuLeader = ($userRole === 'BUSINESS_UNIT_LEADER');

$myCompanyId = $_SESSION['user_company'] ?? null;
$myBuId = $_SESSION['user_bu'] ?? null;

// Helper
if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
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
    $viewCompanyId = $myCompanyId; // Un Leader no puede navegar a otras compañias
    if (!$step) $step = 'bus';
} else {
    // BUSINESS_UNIT_LEADER, STUDENT, INSTRUCTOR...
    $viewCompanyId = $myCompanyId;
    $viewBuId = $myBuId;
    if (!$step) $step = 'forums';
}

// Pequeño cortafuegos
if (!$viewCompanyId && !$isAdmin && !$myCompanyId) {
    echo "<div style='width: 85%; max-width: 1920px; margin: 0 auto; padding: 1rem 0;'><div style='padding: 3rem; text-align: center;'><h2>Módulo No Disponible</h2><p>Tu cuenta no está vinculada a ninguna Organización.</p></div></div>";
    return;
}
?>
<div style="width: 85%; max-width: 1920px; margin: 0 auto; padding: 1rem 0;">
<?php
// =========================================
// STEP 1: COMPAÑÍAS (Sólo Admin)
// =========================================
if ($step === 'companies' && $isAdmin): 
    $today = date('Y-m-d');
    $stmtC = $pdo->query("SELECT c.id, c.name, c.logoPath, 
        (SELECT COUNT(id) FROM BusinessUnit WHERE companyId = c.id) as totalBUs,
        (SELECT COUNT(id) FROM Forum WHERE companyId = c.id) as totalForums,
        (SELECT COUNT(t.id) FROM ForumTopic t JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id) as totalTopics,
        (SELECT COUNT(r.id) FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id) as totalReplies,
        (
            SELECT COUNT(DISTINCT u.id) 
            FROM User u 
            WHERE u.companyId = c.id 
            AND (
                EXISTS (SELECT 1 FROM ForumTopic t JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id AND t.authorId = u.id AND DATE(t.createdAt) = '$today')
                OR 
                EXISTS (SELECT 1 FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id JOIN Forum f ON t.forumId = f.id WHERE f.companyId = c.id AND r.authorId = u.id AND DATE(r.createdAt) = '$today')
            )
        ) as activeUsersToday
        FROM Company c 
        ORDER BY c.name ASC");
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
                        <div style="font-size: 0.8rem; color: #64748b; margin-bottom: 0.6rem;"><i class='bx bx-network-chart'></i> <?= (int)$c['totalBUs'] ?> Unidades de Negocio</div>
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

    // Auto-Provisionamiento
    if ($viewCompanyId) {
        $whereSql = "companyId = :co AND ";
        $paramsProv = [':co' => $viewCompanyId];
        if ($viewBuId) { $whereSql .= "businessUnitId = :bu"; $paramsProv[':bu'] = $viewBuId;} else { $whereSql .= "businessUnitId IS NULL"; }

        $stmtGen = $pdo->prepare("SELECT id FROM Forum WHERE $whereSql AND targetRole = 'GENERAL'");
        $stmtGen->execute($paramsProv);
        
        if (!$stmtGen->fetch()) {
            $fId = generateCuid();
            $pdo->prepare("INSERT INTO Forum (id, companyId, businessUnitId, targetRole, title, description, createdAt, updatedAt) VALUES (?, ?, ?, 'GENERAL', 'Foro General', 'Espacio abierto para debatir, compartir ideas y anuncios.', NOW(), NOW())")
                ->execute([$fId, $viewCompanyId, $viewBuId]);
            
            $stmtRoles = $pdo->query("SELECT id, name FROM TrainingRole WHERE name != 'Gestor de Aprendizaje'");
            while($tr = $stmtRoles->fetch()) {
                $fId = generateCuid();
                $title = "Foro de " . $tr['name'];
                $desc = "Espacio privado para perfiles formativos: " . $tr['name'];
                $pdo->prepare("INSERT INTO Forum (id, companyId, businessUnitId, targetRole, title, description, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())")
                    ->execute([$fId, $viewCompanyId, $viewBuId, $tr['id'], $title, $desc]);
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
        
        if (!$isLeaderAdmin) {
            // Un estudiante común solo ve GENERAL y SUS propios roles formativos
            if (count($myTrainingRoles) > 0) {
                $inSet = implode(',', array_map(function($id) use ($pdo) { return $pdo->quote($id); }, $myTrainingRoles));
                $wForm .= " AND targetRole IN ('GENERAL', $inSet)";
            } else {
                $wForm .= " AND targetRole = 'GENERAL'";
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
    ?>
    <main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
        <div style="padding: 1.5rem 1.5rem 0 1.5rem; background: #f8fafc;">
            <h2 style="font-size: 1.5rem; font-weight: 800; color: #1e293b; margin: 0 0 0.5rem 0;">Foros <?= htmlspecialchars($displayBuName) ?></h2>
            <p style="color: #64748b; margin: 0;">Explora y participa en las áreas de discusión de tu comunidad.</p>
        </div>

    <?php if (count($forums) === 0): ?>
            <div style="text-align: center; padding: 4rem; color: #9ca3af; background: white; border-top: 1px solid #e2e8f0;">
                <i class='bx bx-message-alt-x' style="font-size: 3rem; margin-bottom: 1rem;"></i>
                <h3>Sin Foros Disponibles</h3>
                <p>Aún no se han generado canales de discusión para este segmento.</p>
            </div>
    <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; padding: 0 1.5rem 1.5rem 1.5rem; background: #f8fafc;">
            <?php foreach ($forums as $forum): ?>
                <a href="index.php?view=forum_topic&forum_id=<?= urlencode($forum['id']) ?>" style="display: block; text-decoration: none; color: inherit;">
                    <div class="card" style="height: 100%; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer;" onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 10px 15px -3px rgba(0,0,0,0.1)';" onmouseout="this.style.transform='none'; this.style.boxShadow='var(--shadow)';">
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 0.8rem;">
                            <div style="background: <?= $forum['targetRole']==='GENERAL' ? '#e0f2fe' : '#fce7f3' ?>; color: <?= $forum['targetRole']==='GENERAL' ? '#0369a1' : '#be185d' ?>; padding: 0.25rem 0.6rem; border-radius: 20px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;">
                                <?= htmlspecialchars($forum['trName'] ?: $forum['targetRole']) ?>
                            </div>
                        </div>
                        
                        <h3 style="font-size: 1.1rem; color: #1e293b; margin-bottom: 0.5rem; font-weight: 800;"><?= htmlspecialchars($forum['title']) ?></h3>
                        <p style="font-size: 0.85rem; color: #64748b; line-height: 1.4; margin-bottom: 1.5rem; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
                            <?= htmlspecialchars($forum['description']) ?>
                        </p>
                        
                        <div style="margin-top: auto; display: flex; gap: 1rem; border-top: 1px solid #f1f5f9; padding-top: 0.8rem;">
                            <div style="display: flex; align-items: center; gap: 0.3rem; color: #64748b; font-size: 0.8rem; font-weight: 600;">
                                <i class='bx bx-list-ul'></i> <?= (int)$forum['totalTopics'] ?> Hilos
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.3rem; color: #64748b; font-size: 0.8rem; font-weight: 600;">
                                <i class='bx bx-message-rounded-dots'></i> <?= (int)$forum['totalReplies'] ?> Respuestas
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
            </div>
        </main>
    <?php endif; ?>

<?php endif; ?>
</div>
