<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$userId = $_SESSION['user_id'] ?? '';
$isAdmin = in_array($userRole, ['ADMIN', 'ROOT_ADMIN', 'SUPERVISOR']);

$myCompanyId = $_SESSION['user_company'] ?? null;
$myBuId = $_SESSION['user_bu'] ?? null;

$isCoLeader = ($userRole === 'COMPANY_LEADER');
$isBuLeader = ($userRole === 'BUSINESS_UNIT_LEADER');

// Funciones Helper
if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

function getRoleBadge($role) {
    if (in_array($role, ['ROOT_ADMIN', 'ADMIN', 'SUPERVISOR'])) {
        return "<span style='background: #fee2e2; color: #b91c1c; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;'><i class='bx bx-shield'></i> Administrador</span>";
    } elseif ($role === 'COMPANY_LEADER') {
        return "<span style='background: #fef08a; color: #854d0e; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;'><i class='bx bx-briefcase'></i> Líder Compañía</span>";
    } elseif (in_array($role, ['BUSINESS_UNIT_LEADER', 'INSTRUCTOR'])) {
        return "<span style='background: #dcfce7; color: #166534; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;'><i class='bx bx-store'></i> Líder Unidad</span>";
    } else {
        return "<span style='background: #e0f2fe; color: #0369a1; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase;'>Estudiante</span>";
    }
}

if (!function_exists('typeBadge')) {
    function typeBadge($type) {
        // Nuevos tipos (modelo de 3 intenciones)
        if($type === 'QUESTION')     return "<span style='background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-help-circle'></i> Pregunta</span>";
        if($type === 'IMPROVEMENT')  return "<span style='background:#dcfce7;color:#166534;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-bulb'></i> Propuesta</span>";
        if($type === 'CONTRIBUTION') return "<span style='background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bxs-star'></i> Aporte</span>";
        // Tipos legados (compatibilidad)
        if($type === 'GOOD_PRACTICE')    return "<span style='background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bxs-medal'></i> Buena Práctica</span>";
        if($type === 'METHODOLOGY')      return "<span style='background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-brain'></i> Metodología</span>";
        if($type === 'SUITE_QUESTION')   return "<span style='background:#fce7f3;color:#be185d;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-desktop'></i> Dudas Suite</span>";
        if($type === 'HUB_QUESTION')     return "<span style='background:#f3e8ff;color:#7e22ce;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-cube-alt'></i> Duda Hub</span>";
        if($type === 'CLIENT_INSTRUCTION') return "<span style='background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-flag'></i> Instrucción</span>";
        return "<span style='background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'>".htmlspecialchars($type)."</span>";
    }
}


$forumId = $_GET['forum_id'] ?? '';
$topicId = $_GET['topic_id'] ?? '';
$mode = $topicId ? 'read' : 'list';

// Detectar si el usuario actual es LECTOR OPERATIVO
$isLectorOp = false;
if ($userId) {
    $stmtLO = $pdo->prepare("SELECT 1 FROM TrainingRole tr JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id WHERE rtu.B = ? AND LOWER(tr.name) LIKE '%lector%operativo%' LIMIT 1");
    $stmtLO->execute([$userId]);
    $isLectorOp = (bool)$stmtLO->fetchColumn();
}


// =========================================
// 1. Validar Permisos al Foro
// =========================================
if (!$forumId) {
    echo "<div class='alert alert-error'>No se ha especificado un foro válido.</div>";
    return;
}

$stmtF = $pdo->prepare("SELECT * FROM Forum WHERE id = ?");
$stmtF->execute([$forumId]);
$forum = $stmtF->fetch();

if (!$forum) {
    echo "<div class='alert alert-error'>El foro solicitado no existe.</div>";
    return;
}

$hasPostingRights = true;

// Check RBAC
if (!$isAdmin) {
    // Guardia: si la sesión no tiene companyId, bloquear a no-admins
    if (!$myCompanyId) {
        echo "<div class='alert alert-error'>Acceso no disponible: tu sesión no está vinculada a una organización. Vuelve a iniciar sesión.</div>";
        return;
    }
    if ($forum['companyId'] !== $myCompanyId) {
        echo "<div class='alert alert-error'>Acceso Denegado: Este foro pertenece a otra compañía.</div>";
        return;
    }
    
    // Si no es COMPANY_LEADER, tiene que respetar el scope de BU (Si aplica)
    if (!$isCoLeader) {
        if ($forum['businessUnitId'] !== null && $forum['businessUnitId'] !== $myBuId) {
            echo "<div class='alert alert-error'>Acceso Denegado: Este foro pertenece a otra unidad de negocio.</div>";
            return;
        }
    }
    
    // Todos los no administradores revisan sus permisos de creación contra TrainingRole
    $isLoForum = false;
    if ($forum['targetRole'] !== 'GENERAL') {
        // Detectar si es el foro específico de Lector Operativo
        $stmtIsLO = $pdo->prepare("SELECT id FROM TrainingRole WHERE id = ? AND LOWER(name) LIKE '%lector%operativo%'");
        $stmtIsLO->execute([$forum['targetRole']]);
        $isLoForum = (bool)$stmtIsLO->fetchColumn();

        $stmtTR = $pdo->prepare("SELECT A FROM _TrainingRoleToUser WHERE B = ? AND A = ?");
        $stmtTR->execute([$userId, $forum['targetRole']]);
        if (!$stmtTR->fetch()) {
            $hasPostingRights = false;
            // El Foro LO es accesible para toda la BU: pueden leer y responder, pero no crear temas.
            // Otros foros de rol: solo líderes pueden ver en modo lectura.
            if (!$isLoForum && !$isCoLeader && !$isBuLeader) {
                echo "<div class='alert alert-error'>Acceso Denegado: No cuentas con el Perfil Formativo requerido para esta sala.</div>";
                return;
            }
        }
    }
}
// Visitante al foro LO: no es LO, pero tiene acceso para leer y responder
$isVisitorToLoForum = ($isLoForum ?? false) && !$isLectorOp && !$isAdmin && !$isCoLeader && !$isBuLeader;

// Permisos de moderación por rol y scope
$canModerate = $isAdmin
    || ($isCoLeader && $forum['companyId'] === $myCompanyId)
    || ($isBuLeader && $forum['businessUnitId'] !== null && $forum['businessUnitId'] === $myBuId);

// =========================================
// 2. Procesar POSTs
// =========================================
$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'create_topic' && !$topicId) {
            $title = trim($_POST['title'] ?? '');
            $content = trim($_POST['content'] ?? '');
            $threadType = $_POST['threadType'] ?? 'GENERAL';
            
            if ($title && $content) {
                $newTid = generateCuid();
                $stmt = $pdo->prepare("INSERT INTO ForumTopic (id, forumId, authorId, title, content, views, threadType, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, 0, ?, NOW(), NOW())");
                $stmt->execute([$newTid, $forumId, $userId, $title, $content, $threadType]);
                $successMsg = "El tema ha sido publicado exitosamente.";
                // Redirigir para evitar F5 resubmit o forzar recarga limpia
                echo "<script>window.location.href='index.php?view=forum_topic&forum_id=".urlencode($forumId)."&topic_id=".urlencode($newTid)."';</script>";
                exit;
            } else {
                $errorMsg = "El título y el contenido son obligatorios.";
            }
        } 
        elseif ($action === 'create_reply') {
            $content = trim($_POST['content'] ?? '');
            $parentId = !empty($_POST['parent_id']) ? $_POST['parent_id'] : null;
            if ($content) {
                $newRid = generateCuid();
                $pdo->prepare("INSERT INTO ForumReply (id, topicId, authorId, content, createdAt, parentReplyId) VALUES (?, ?, ?, ?, NOW(), ?)")->execute([$newRid, $topicId, $userId, $content, $parentId]);
                $pdo->prepare("UPDATE ForumTopic SET updatedAt = NOW() WHERE id = ?")->execute([$topicId]);
                $successMsg = "Respuesta publicada correctamente.";            // --- INYECCIÓN DE SISTEMA DE NOTIFICACIONES ---
                // 1. Obtener a los Autores Implicados (El creador del tema + Todos los que ya comentaron)
                $stmtAuth = $pdo->prepare("
                    SELECT authorId FROM ForumTopic WHERE id = ? 
                    UNION 
                    SELECT authorId FROM ForumReply WHERE topicId = ?
                ");
                $stmtAuth->execute([$topicId, $topicId]);
                $participants = $stmtAuth->fetchAll(PDO::FETCH_COLUMN);
                
                $myUserName = $_SESSION['user_name'] ?? 'Un usuario';
                
                // 2. Disparar notificaciones masivas individuales (excepto para mí mismo)
                if (!empty($participants)) {
                    $stmtNotif = $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, isRead, metadata, createdAt) VALUES (?, ?, 'FORUM_REPLY', ?, ?, 0, ?, NOW())");
                    foreach ($participants as $pId) {
                        if ($pId !== $userId) { // No me envíes a mí mismo mi propia notificación
                            $nId = generateCuid();
                            $nTitle = "Nueva actividad en foro";
                            $nMsg = "$myUserName ha comentado en un hilo de tu interés.";
                            $nMeta = json_encode(['url' => "index.php?view=forum_topic&forum_id=".urlencode($forumId)."&topic_id=".urlencode($topicId)]);
                            $stmtNotif->execute([$nId, $pId, $nTitle, $nMsg, $nMeta]);
                        }
                    }
                }
                // --- FIN SISTEMA DE NOTIFICACIONES ---
                
                $successMsg = "Tu respuesta se ha publicado.";
            } else {
                $errorMsg = "El mensaje no puede estar vacío.";
            }
        }
        elseif ($action === 'delete_topic' && $canModerate) {
            $delId = $_POST['del_id'] ?? '';
            if ($delId) {
                // Cascada: borrar interacciones de respuestas
                $stmtRIds = $pdo->prepare("SELECT id FROM ForumReply WHERE topicId = ?");
                $stmtRIds->execute([$delId]);
                $rIdList = $stmtRIds->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($rIdList)) {
                    $ph = implode(',', array_fill(0, count($rIdList), '?'));
                    $pdo->prepare("DELETE FROM ForumReplyLike WHERE replyId IN ($ph)")->execute($rIdList);
                    $pdo->prepare("DELETE FROM ForumReplyHelpfulVote WHERE replyId IN ($ph)")->execute($rIdList);
                }
                $pdo->prepare("DELETE FROM ForumReply WHERE topicId = ?")->execute([$delId]);
                try { $pdo->prepare("DELETE FROM ForumTopicLike WHERE topicId = ?")->execute([$delId]); } catch(Exception $e) {}
                $pdo->prepare("DELETE FROM ForumTopic WHERE id = ?")->execute([$delId]);
            }
            echo "<script>window.location.href='index.php?view=forum_topic&forum_id=".urlencode($forumId)."';</script>";
            exit;
        }
        elseif ($action === 'delete_reply' && $canModerate) {
            $delId = $_POST['del_id'] ?? '';
            if ($delId) {
                // Cascada: borrar respuestas hijas y sus interacciones
                $stmtCh = $pdo->prepare("SELECT id FROM ForumReply WHERE parentReplyId = ?");
                $stmtCh->execute([$delId]);
                $childIds = $stmtCh->fetchAll(PDO::FETCH_COLUMN);
                foreach ($childIds as $cId) {
                    $pdo->prepare("DELETE FROM ForumReplyLike WHERE replyId = ?")->execute([$cId]);
                    $pdo->prepare("DELETE FROM ForumReplyHelpfulVote WHERE replyId = ?")->execute([$cId]);
                }
                if (!empty($childIds)) { $pdo->prepare("DELETE FROM ForumReply WHERE parentReplyId = ?")->execute([$delId]); }
                $pdo->prepare("DELETE FROM ForumReplyLike WHERE replyId = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM ForumReplyHelpfulVote WHERE replyId = ?")->execute([$delId]);
                $pdo->prepare("DELETE FROM ForumReply WHERE id = ?")->execute([$delId]);
            }
            $successMsg = "Comentario eliminado por Moderación.";
        }
    } catch(PDOException $e) {
        $errorMsg = "Error en Base de Datos: " . $e->getMessage();
    }
}

// =========================================
// 3. Renderizar Vistas
// =========================================
?>

<a href="index.php?view=forums<?= $isAdmin ? '&filter_co='.urlencode($forum['companyId'] ?? '').'&filter_bu='.urlencode($forum['businessUnitId'] ?? '') : '' ?>" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
    <i class='bx bx-left-arrow-alt'></i> Volver al Directorio de Foros
</a>

<?php
    // Obtener nombre del Training Role para despliegue visual
    $displayRole = $forum['targetRole'];
    if ($forum['targetRole'] !== 'GENERAL') {
        $st = $pdo->prepare("SELECT name FROM TrainingRole WHERE id = ?");
        $st->execute([$forum['targetRole']]);
        $trNam = $st->fetchColumn();
        if ($trNam) $displayRole = $trNam;
    }
?>
<div class="page-header" style="margin-bottom: 2rem;">
    <div style="background: var(--bg-color); padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border);">
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
            <div style="background: <?= $forum['targetRole']==='GENERAL' ? '#e0f2fe' : '#fce7f3' ?>; color: <?= $forum['targetRole']==='GENERAL' ? '#0369a1' : '#be185d' ?>; padding: 0.2rem 0.6rem; border-radius: 20px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">
                <?= htmlspecialchars($displayRole) ?>
            </div>
        </div>
        <h2 style="font-weight: 800; color: #1e293b; margin: 0;"><?= htmlspecialchars($forum['title']) ?></h2>
        <p style="color: #64748b; font-size: 0.9rem; margin-top: 0.5rem;"><?= htmlspecialchars($forum['description']) ?></p>
    </div>
</div>

<?php if ($isVisitorToLoForum): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:0.75rem;">
    <i class='bx bx-group' style="font-size:1.5rem;color:#92400e;flex-shrink:0;"></i>
    <div>
        <div style="font-weight:800;color:#92400e;font-size:0.9rem;">Apoyando al Equipo Operativo</div>
        <div style="color:#78350f;font-size:0.8rem;margin-top:0.2rem;">Puedes leer y responder a las preguntas y propuestas de tu equipo. Los temas nuevos solo los puede abrir el Lector Operativo.</div>
    </div>
</div>
<?php endif; ?>
<?php if ($successMsg): ?><div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>

<?php
// ----------------------------------------------------------------------
// MODO: LISATDO DE HILOS (TOPICS)
// ----------------------------------------------------------------------
if ($mode === 'list'):
    // Paginación de hilos
    $tPage    = max(1, (int)($_GET['page'] ?? 1));
    $tPerPage = 15;
    $tOffset  = ($tPage - 1) * $tPerPage;

    $stmtTCount = $pdo->prepare("SELECT COUNT(id) FROM ForumTopic WHERE forumId = ?");
    $stmtTCount->execute([$forumId]);
    $tTotal = (int)$stmtTCount->fetchColumn();
    $tPages = max(1, (int)ceil($tTotal / $tPerPage));

    $stmtTopics = $pdo->prepare("
        SELECT t.*,
               COALESCE(u.name, '[Usuario eliminado]') as authorName,
               COALESCE(u.email, '') as authorEmail,
               COALESCE(u.role, 'STUDENT') as authorRole,
               COALESCE(bu.name, 'Corporativo') as authorBuName,
               (SELECT COUNT(r.id) FROM ForumReply r WHERE r.topicId = t.id) as replyCount,
               (SELECT r2.createdAt FROM ForumReply r2 WHERE r2.topicId = t.id ORDER BY r2.createdAt DESC LIMIT 1) as lastActivity
        FROM ForumTopic t
        LEFT JOIN User u ON t.authorId = u.id
        LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id
        WHERE t.forumId = ?
        ORDER BY t.isPinned DESC, GREATEST(t.updatedAt, COALESCE((SELECT r2.createdAt FROM ForumReply r2 WHERE r2.topicId = t.id ORDER BY r2.createdAt DESC LIMIT 1), t.updatedAt)) DESC
        LIMIT $tPerPage OFFSET $tOffset
    ");
    $stmtTopics->execute([$forumId]);
    $topics = $stmtTopics->fetchAll();
?>
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
        <h3 style="margin: 0;">Discusiones Activas</h3>
        <?php if ($hasPostingRights || $isAdmin): ?>
        <button class="btn btn-primary" onclick="document.getElementById('modalCT').classList.add('active')">
            <i class='bx bx-edit'></i> Iniciar Nuevo Tema
        </button>
        <?php else: ?>
            <?php if ($isVisitorToLoForum): ?>
            <div style="color:#92400e;font-size:0.8rem;background:#fef3c7;padding:0.5rem 1rem;border-radius:20px;border:1px solid #fde68a;font-weight:700;">
                <i class='bx bx-chat'></i> Responde a tu equipo
            </div>
            <?php else: ?>
            <div style="color: #64748b; font-size: 0.8rem; background: #f1f5f9; padding: 0.5rem 1rem; border-radius: 20px;">
                <i class='bx bx-show'></i> Modo Observador Activado
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Tema</th>
                        <th>Tipo de Hilo</th>
                        <th>Autor</th>
                        <th style="text-align: center;">Respuestas</th>
                        <th style="text-align: right;">Última Actividad</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($topics) > 0): ?>
                        <?php foreach($topics as $t): ?>
                            <tr>
                                <td>
                                    <a href="index.php?view=forum_topic&forum_id=<?= urlencode($forumId) ?>&topic_id=<?= urlencode($t['id']) ?>" style="text-decoration: none; display: block;">
                                        <div style="font-weight: 700; color: #3b82f6; font-size: 1rem; margin-bottom: 0.2rem; display: flex; align-items: center; gap: 0.4rem;">
                                            <?php if($t['isPinned']): ?><i class='bx bxs-pin' style="color: #ef4444;"></i><?php endif; ?>
                                            <?php if($t['isLocked']): ?><i class='bx bxs-lock-alt' style="color: #f59e0b;"></i><?php endif; ?>
                                            <?= htmlspecialchars($t['title']) ?>
                                        </div>
                                    </a>
                                </td>
                                <td>
                                    <div style="margin-top: 0.2rem;"><?= typeBadge($t['threadType']) ?></div>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($t['authorName'] ?: 'Usuario Eliminado') ?></div>
                                    <div style="margin-top: 0.3rem;">
                                        <?= getRoleBadge($t['authorRole']) ?>
                                        <span style="font-size: 0.65rem; color: #475569; background: #f1f5f9; padding: 0.15rem 0.4rem; border-radius: 4px; margin-left: 0.3rem;"><i class='bx bx-store'></i> <?= htmlspecialchars($t['authorBuName']) ?></span>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <span style="background: #f1f5f9; padding: 0.2rem 0.6rem; border-radius: 20px; font-weight: 800; font-size: 0.8rem; color: #64748b;">
                                        <?= (int)$t['replyCount'] ?>
                                    </span>
                                </td>
                                <td style="text-align: right; color: #64748b; font-size: 0.8rem;">
                                    <?= date('d/M/Y H:i', strtotime($t['lastActivity'] ?? $t['createdAt'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" style="text-align: center; padding: 4rem; color: #9ca3af;">No hay discusiones en este foro aún. ¡Sé el primero en publicar!</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($tPages > 1): ?>
    <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:1.25rem;flex-wrap:wrap;">
        <?php
        $baseTopicUrl = 'index.php?view=forum_topic&forum_id='.urlencode($forumId);
        for ($pi = 1; $pi <= $tPages; $pi++):
            $isActive = $pi === $tPage;
            $bgStyle  = $isActive ? 'background:#FF6A00;color:white;' : 'background:#f3f4f6;color:#374151;';
        ?>
        <a href="<?= $baseTopicUrl ?>&page=<?= $pi ?>" style="<?= $bgStyle ?>padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.875rem;"><?= $pi ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <!-- Modal Formulario Tema Nuevo -->
    <div class="modal-overlay" id="modalCT">
        <div class="modal-content" style="max-width: 650px;">
            <div class="modal-header">
                <h3 class="modal-title">Iniciar Nuevo Tema</h3>
                <button class="modal-close" onclick="document.getElementById('modalCT').classList.remove('active')"><i class='bx bx-x'></i></button>
            </div>
            <form method="POST" id="formNewTopic">
                <input type="hidden" name="action" value="create_topic">

                <?php if ($isLectorOp): ?>
                <!-- LECTOR OPERATIVO: 3 botones de intención -->
                <input type="hidden" name="threadType" id="threadTypeInput" value="">
                <div class="form-group">
                    <label class="form-label" style="margin-bottom:0.75rem;display:block;">¿Qué quieres hacer? <span style="color:#ef4444;">*</span></label>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;">
                        <button type="button" onclick="selectIntent('QUESTION',this)" id="btn_QUESTION"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="font-size:1.5rem;margin-bottom:0.3rem;">❓</div>
                            <div style="font-weight:700;font-size:0.8rem;color:#1e293b;">Tengo una pregunta</div>
                        </button>
                        <button type="button" onclick="selectIntent('IMPROVEMENT',this)" id="btn_IMPROVEMENT"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="font-size:1.5rem;margin-bottom:0.3rem;">💡</div>
                            <div style="font-weight:700;font-size:0.8rem;color:#1e293b;">Tengo una propuesta</div>
                        </button>
                        <button type="button" onclick="selectIntent('CONTRIBUTION',this)" id="btn_CONTRIBUTION"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="font-size:1.5rem;margin-bottom:0.3rem;">⭐</div>
                            <div style="font-weight:700;font-size:0.8rem;color:#1e293b;">Quiero compartir algo</div>
                        </button>
                    </div>
                    <div id="intentError" style="color:#ef4444;font-size:0.8rem;margin-top:0.5rem;display:none;">Por favor selecciona una opción.</div>
                </div>

                <?php else: ?>
                <!-- OTROS ROLES: dropdown con los 7 tipos -->
                <div class="form-group">
                    <label class="form-label">Tipo de Hilo <span style="color:#ef4444;">*</span></label>
                    <select name="threadType" class="form-control" required style="cursor:pointer;appearance:auto;">
                        <option value="" disabled selected>-- Selecciona una categoría --</option>
                        <optgroup label="Modelo operativo">
                            <option value="QUESTION">❓ Pregunta general</option>
                            <option value="IMPROVEMENT">💡 Propuesta de mejora</option>
                            <option value="CONTRIBUTION">⭐ Aporte / Compartir</option>
                        </optgroup>
                        <optgroup label="Categorías técnicas">
                            <option value="METHODOLOGY">Duda metodológica</option>
                            <option value="SUITE_QUESTION">Duda técnica sobre la Suite</option>
                            <option value="HUB_QUESTION">Duda sobre HubEurosoft</option>
                            <option value="CLIENT_INSTRUCTION">Instrucción oficial del Cliente</option>
                            <option value="GOOD_PRACTICE">Buena práctica (pendiente validación)</option>
                            <option value="GENERAL">Comunicación general / Otros</option>
                        </optgroup>
                    </select>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label">Título</label>
                    <input type="text" name="title" class="form-control" required placeholder="Describe brevemente tu tema..." maxlength="150">
                </div>
                <div class="form-group">
                    <label class="form-label">Detalle</label>
                    <textarea name="content" class="form-control" style="height: 120px; resize: vertical;" required placeholder="Comparte todos los detalles necesarios..."></textarea>
                </div>
                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="document.getElementById('modalCT').classList.remove('active')">Cancelar</button>
                    <?php if ($isLectorOp): ?>
                    <button type="submit" class="btn btn-primary" onclick="return validateIntent()"><i class='bx bx-send'></i> Publicar</button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-primary"><i class='bx bx-send'></i> Publicar Tema</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <script>
    function selectIntent(type, el) {
        document.getElementById('threadTypeInput').value = type;
        ['btn_QUESTION','btn_IMPROVEMENT','btn_CONTRIBUTION'].forEach(id => {
            const b = document.getElementById(id);
            b.style.border = '2px solid #e2e8f0';
            b.style.background = '#f8fafc';
        });
        el.style.border = '2px solid #FF6A00';
        el.style.background = '#fff7f0';
        document.getElementById('intentError').style.display = 'none';
    }
    function validateIntent() {
        const v = document.getElementById('threadTypeInput');
        if (!v || !v.value) {
            document.getElementById('intentError').style.display = 'block';
            return false;
        }
        return true;
    }
    </script>

<?php 
// ----------------------------------------------------------------------
// MODO: LEER HILO Y COMENTARIOS
// ----------------------------------------------------------------------
else: 
    // Incrementar Vistas solo una vez (simulado en DB)
    $pdo->prepare("UPDATE ForumTopic SET views = views + 1 WHERE id = ?")->execute([$topicId]);

    $stmtTop = $pdo->prepare("SELECT t.*, COALESCE(u.name,'[Usuario eliminado]') as authorName, COALESCE(u.email,'') as authorEmail, COALESCE(u.role,'STUDENT') as authorRole, COALESCE(bu.name, 'Corporativo') as authorBuName FROM ForumTopic t LEFT JOIN User u ON t.authorId = u.id LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id WHERE t.id = ?");
    $stmtTop->execute([$topicId]);
    $topic = $stmtTop->fetch();

    if (!$topic) die("Topic no encontrado");

    // Paginación de respuestas
    $rPage    = max(1, (int)($_GET['rpage'] ?? 1));
    $rPerPage = 25;
    $rOffset  = ($rPage - 1) * $rPerPage;

    $stmtRCount = $pdo->prepare("SELECT COUNT(id) FROM ForumReply WHERE topicId = ?");
    $stmtRCount->execute([$topicId]);
    $rTotal = (int)$stmtRCount->fetchColumn();
    $rPages = max(1, (int)ceil($rTotal / $rPerPage));

    $stmtRep = $pdo->prepare("SELECT r.*, COALESCE(u.name,'[Usuario eliminado]') as authorName, COALESCE(u.role,'STUDENT') as authorRole, COALESCE(bu.name, 'Corporativo') as authorBuName,
        (SELECT COUNT(id) FROM ForumReplyLike l WHERE l.replyId = r.id AND l.userId = ?) as isLikedByMe,
        (SELECT COUNT(id) FROM ForumReplyHelpfulVote v WHERE v.replyId = r.id AND v.userId = ?) as isVotedHelpfulByMe
        FROM ForumReply r LEFT JOIN User u ON r.authorId = u.id LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id WHERE r.topicId = ? ORDER BY r.createdAt ASC
        LIMIT $rPerPage OFFSET $rOffset");
    $stmtRep->execute([$userId, $userId, $topicId]);
    $rawReplies = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

    // Build Tree & Flatten to max depth 3
    $repliesById = [];
    $repliesTree = [];
    foreach ($rawReplies as &$r) {
        $r['children'] = [];
        $repliesById[$r['id']] = &$r;
    }
    foreach ($repliesById as $id => &$r) {
        if ($r['parentReplyId'] && isset($repliesById[$r['parentReplyId']])) {
            $repliesById[$r['parentReplyId']]['children'][] = &$r;
        } else {
            $repliesTree[] = &$r;
        }
    }

    $flattenedReplies = [];
    $flatten = function($nodes, $depth) use (&$flatten, &$flattenedReplies) {
        $maxDepth = 3; 
        $actualDepth = min($depth, $maxDepth);
        foreach ($nodes as $n) {
            $n['displayLevel'] = $actualDepth;
            $flattenedReplies[] = $n;
            if (!empty($n['children'])) {
                $flatten($n['children'], $depth + 1);
            }
        }
    };
    $flatten($repliesTree, 0);

    // Calcular la respuesta más útil de toda la comunidad
    $mostVotedReplyId = null;
    $maxVotes = 0;
    foreach ($rawReplies as $rRaw) {
        if ($rRaw['helpfulVotesCount'] > $maxVotes && $rRaw['helpfulVotesCount'] > 0) {
            $maxVotes = $rRaw['helpfulVotesCount'];
            $mostVotedReplyId = $rRaw['id'];
        }
    }
?>
    <a href="index.php?view=forum_topic&forum_id=<?= urlencode($forumId) ?>" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 2rem; font-weight: 600;">
        <i class='bx bx-subdirectory-left'></i> Salir de la Discusión
    </a>

    <?php if ($mostVotedReplyId): ?>
        <div style="background: #eef2ff; border: 1px solid #c7d2fe; padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between;">
            <div style="display: flex; align-items: center; gap: 0.8rem; color: #4338ca; font-weight: 700; font-size: 0.9rem;">
                <i class='bx bxs-star' style="font-size: 1.2rem; color: #fbbf24;"></i>
                La comunidad ha resaltado una respuesta útil en este hilo.
            </div>
            <a href="#reply-<?= htmlspecialchars($mostVotedReplyId) ?>" class="btn" style="background: #4f46e5; color: white; border: none; font-size: 0.8rem; padding: 0.4rem 1rem; text-decoration: none;">
                Ir a la ganadora (<?= $maxVotes ?> votos)
            </a>
        </div>
    <?php endif; ?>

    <!-- POST ORIGINAL -->
    <div class="card" style="margin-bottom: 2rem; border-left: 4px solid var(--primary); padding-bottom: 1.5rem;">
        <div style="display: flex; justify-content: space-between; margin-bottom: 1.5rem;">
            <div style="display: flex; gap: 1rem; align-items: center;">
                <div style="width: 45px; height: 45px; border-radius: 50%; background: var(--bg-color); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: #9ca3af; border: 1px solid var(--border);">
                    <i class='bx bx-user'></i>
                </div>
                <div>
                    <div style="font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 0.5rem;">
                        <?= htmlspecialchars($topic['authorName']) ?> 
                        <?= getRoleBadge($topic['authorRole']) ?>
                        <span style="font-size:0.75rem; color:#64748b; background:#f1f5f9; padding:0.1rem 0.4rem; border-radius:4px;"><i class='bx bx-store'></i> <?= htmlspecialchars($topic['authorBuName']) ?></span>
                    </div>
                    <div style="font-size: 0.75rem; color: #64748b; font-weight: 600; margin-top: 0.3rem;">Publicado el <?= date('d/m/Y H:i', strtotime($topic['createdAt'])) ?></div>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center;">
                <?php if ($topic['isValidatedPractice'] ?? 0): ?>
                    <div style="background: #fef08a; border: 1px solid #eab308; color: #854d0e; padding: 0.4rem 0.8rem; border-radius: 8px; font-size: 0.75rem; font-weight: 800; text-transform: uppercase; display: flex; align-items: center; gap: 0.3rem;">
                        <i class='bx bxs-medal' style="font-size: 1rem;"></i> Buena Práctica Validada
                    </div>
                <?php elseif (($isAdmin || $isCoLeader || $isBuLeader) && $topic['authorId'] !== $userId): ?>
                    <button onclick="moderateAction('mark_practice', '<?= $topicId ?>')" class="btn" style="background: #fffbeb; color: #d97706; border: 1px solid #fde68a; font-size: 0.8rem; padding: 0.4rem 0.8rem;"><i class='bx bx-check-shield'></i> Aprobar Práctica</button>
                <?php endif; ?>
                
                <?php if ($canModerate): ?>
                    <form method="POST" onsubmit="return confirm('¿Borrar TODO EL TEMA completo? Esta acción eliminará también todas sus respuestas.');" style="margin:0;">
                        <input type="hidden" name="action" value="delete_topic">
                        <input type="hidden" name="del_id" value="<?= htmlspecialchars($topicId) ?>">
                        <button type="submit" class="btn" style="padding: 0.4rem 0.8rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; font-size: 0.8rem;"><i class='bx bx-trash'></i></button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <h3 style="font-size: 1.5rem; margin-bottom: 1rem; color: #1e293b;"><?= htmlspecialchars($topic['title']) ?></h3>
        <div style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.6; color: #334155;"><?= htmlspecialchars($topic['content']) ?></div>
        
        <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid #f1f5f9; display: flex; gap: 1.5rem; color: #9ca3af; font-size: 0.8rem; font-weight: 600;">
            <span><i class='bx bx-show'></i> <?= (int)$topic['views'] ?> vistas</span>
            <span><i class='bx bx-comment-detail'></i> <?= count($rawReplies) ?> respuestas</span>
        </div>
    </div>

    <!-- RESPUESTAS -->
    <?php foreach($flattenedReplies as $r): ?>
        <?php $marginL = $r['displayLevel'] * 3; // 0, 3, 6, 9 rem ?>
        <div id="reply-<?= htmlspecialchars($r['id']) ?>" style="display: flex; gap: 1rem; margin-bottom: 1.5rem; margin-left: <?= $marginL ?>rem;">
            <div style="flex-shrink: 0; width: 35px; height: 35px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #9ca3af;">
                <i class='bx bx-user'></i>
            </div>
            <div style="flex-grow: 1; background: <?= $r['displayLevel'] > 0 ? '#fafafa' : 'white' ?>; padding: 1.2rem; border-radius: 8px; border: 1px solid #e2e8f0; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <div style="display: flex; justify-content: space-between; margin-bottom: 0.8rem; align-items: flex-start;">
                    <div>
                        <span style="font-weight: 700; color: #1e293b; font-size: 0.9rem; margin-right: 0.5rem;"><?= htmlspecialchars($r['authorName']) ?></span>
                        <?= getRoleBadge($r['authorRole']) ?>
                        <span style="font-size:0.7rem; color:#64748b; background:#f1f5f9; padding:0.1rem 0.4rem; border-radius:4px; margin-left:0.5rem;"><i class='bx bx-store'></i> <?= htmlspecialchars($r['authorBuName']) ?></span>
                        <span style="font-size: 0.75rem; color: #9ca3af; margin-left: 0.5rem;"><?= date('d/m/y H:i', strtotime($r['createdAt'])) ?></span>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <?php if ($canModerate): ?>
                            <form method="POST" onsubmit="return confirm('¿Borrar esta respuesta y sus sub-respuestas?');" style="margin:0;">
                                <input type="hidden" name="action" value="delete_reply">
                                <input type="hidden" name="del_id" value="<?= htmlspecialchars($r['id']) ?>">
                                <button type="submit" style="background:none; border:none; cursor:pointer; color:#ef4444; font-size: 1.2rem;"><i class='bx bx-trash'></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="white-space: pre-wrap; font-size: 0.9rem; color: #475569; line-height: 1.5; margin-bottom: 1rem;"><?= htmlspecialchars($r['content']) ?></div>
                <div style="display: flex; gap: 0.8rem; align-items: center; flex-wrap: wrap;">
                    <?php if ($r['authorId'] !== $userId): ?>
                        <button onclick="toggleLike('<?= $r['id'] ?>')" id="likeBtn_<?= $r['id'] ?>" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.75rem; background: <?= $r['isLikedByMe'] ? '#e0e7ff' : '#f1f5f9' ?>; color: <?= $r['isLikedByMe'] ? '#4f46e5' : '#64748b' ?>; border: 1px solid <?= $r['isLikedByMe'] ? '#c7d2fe' : '#e2e8f0' ?>; display:flex; align-items:center; gap:0.2rem;">
                            <i class='bx <?= $r['isLikedByMe'] ? 'bxs-like' : 'bx-like' ?>'></i> 
                            <span id="likeCount_<?= $r['id'] ?>"><?= (int)($r['likesCount'] ?? 0) ?></span>
                        </button>
                    <?php else: ?>
                        <div style="padding: 0.3rem 0.8rem; font-size: 0.75rem; background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; border-radius: 6px; display:flex; align-items:center; gap:0.2rem;" title="No puedes votar tu propia respuesta">
                            <i class='bx bx-like'></i> <?= (int)($r['likesCount'] ?? 0) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($r['isHelpful'] ?? 0): ?>
                        <span style="background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; border-radius: 20px; padding: 0.3rem 0.8rem; font-size: 0.75rem; font-weight: 700; display:flex; align-items:center; gap:0.3rem;">
                            <i class='bx bxs-star'></i> Respuesta Útil Oficial (<?= (int)$r['helpfulVotesCount'] ?>)
                        </span>
                    <?php elseif ($r['authorId'] !== $userId): ?>
                        <button id="helpfulBtn_<?= $r['id'] ?>" onclick="voteHelpful('<?= $r['id'] ?>')" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.75rem; background: <?= $r['isVotedHelpfulByMe'] ? '#dcfce7' : '#f8fafc' ?>; color: <?= $r['isVotedHelpfulByMe'] ? '#166534' : '#64748b' ?>; border: 1px solid <?= $r['isVotedHelpfulByMe'] ? '#bbf7d0' : '#e2e8f0' ?>; display:flex; align-items:center; gap:0.3rem; border-radius: 6px;" title="<?= $r['isVotedHelpfulByMe'] ? 'Ya enviaste tu voto de utilidad' : 'Votar que es una buena recomendación' ?>">
                            <i class='bx <?= $r['isVotedHelpfulByMe'] ? 'bxs-star' : 'bx-star' ?>' style="font-size: 1rem;"></i> Voto Útil (<span id="helpfulCount_<?= $r['id'] ?>"><?= (int)$r['helpfulVotesCount'] ?></span>)
                        </button>
                    <?php endif; ?>

                    <?php if (!$topic['isLocked']): ?>
                        <button onclick="toggleReplyBox('<?= $r['id'] ?>')" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.75rem; background: transparent; color: #64748b; border: 1px solid transparent; display:flex; align-items:center; gap:0.2rem;">
                            <i class='bx bx-message-square-dots'></i> Responder
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Sub-Formulario Oculto de Respuesta Anidada -->
                <div id="replyBox_<?= $r['id'] ?>" style="display: none; margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #e2e8f0;">
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="create_reply">
                        <input type="hidden" name="parent_id" value="<?= $r['id'] ?>">
                        <textarea name="content" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.8rem; height: 60px; resize: vertical; margin-bottom: 0.5rem; font-family: inherit; font-size: 0.85rem;" placeholder="Escribe tu comentario en respuesta a <?= htmlspecialchars($r['authorName']) ?>..."></textarea>
                        <div style="text-align: right;">
                            <button type="button" onclick="toggleReplyBox('<?= $r['id'] ?>')" class="btn" style="padding: 0.3rem 0.8rem; font-size: 0.75rem; background: #f1f5f9; color: #64748b; border:none;">Cancelar</button>
                            <button type="submit" class="btn btn-primary" style="padding: 0.3rem 0.8rem; font-size: 0.75rem; border:none;">Enviar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($rPages > 1): ?>
    <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin:1.5rem 0;flex-wrap:wrap;">
        <?php
        $baseReplyUrl = 'index.php?view=forum_topic&forum_id='.urlencode($forumId).'&topic_id='.urlencode($topicId);
        if ($rPage > 1): ?>
            <a href="<?= $baseReplyUrl ?>&rpage=<?= $rPage - 1 ?>" style="background:#f3f4f6;color:#374151;padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.875rem;">← Anterior</a>
        <?php endif;
        for ($pi = 1; $pi <= $rPages; $pi++):
            $isActive = $pi === $rPage;
            $bgStyle  = $isActive ? 'background:#FF6A00;color:white;' : 'background:#f3f4f6;color:#374151;';
        ?>
            <a href="<?= $baseReplyUrl ?>&rpage=<?= $pi ?>" style="<?= $bgStyle ?>padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.875rem;"><?= $pi ?></a>
        <?php endfor;
        if ($rPage < $rPages): ?>
            <a href="<?= $baseReplyUrl ?>&rpage=<?= $rPage + 1 ?>" style="background:#f3f4f6;color:#374151;padding:7px 14px;border-radius:8px;text-decoration:none;font-weight:600;font-size:0.875rem;">Siguiente →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- DEJAR RESPUESTA -->
    <?php if (!$topic['isLocked']): ?>
        <div style="padding-left: calc(2rem + 35px + 1rem); margin-top: 2rem;">
            <form method="POST" style="background: white; padding: 1.5rem; border-radius: 8px; border: 1px solid #e2e8f0;">
                <input type="hidden" name="action" value="create_reply">
                <h4 style="margin-top:0; margin-bottom: 1rem; font-size: 1rem; color: #1e293b;">Escribir Respuesta</h4>
                <textarea name="content" required style="width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; padding: 0.8rem; height: 100px; resize: vertical; margin-bottom: 1rem; font-family: inherit; font-size: 0.9rem;" placeholder="Redacta tu contribución al hilo..."></textarea>
                <div style="text-align: right;">
                    <button type="submit" class="btn btn-primary"><i class='bx bx-reply'></i> Publicar Respuesta</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <div style="text-align: center; padding: 2rem; color: #f59e0b; background: #fef3c7; border-radius: 8px; margin-top: 2rem;">
            <i class='bx bxs-lock-alt' style="font-size: 1.5rem; margin-bottom: 0.5rem;"></i>
            <h4 style="margin:0;">Tema Cerrado</h4>
            <p style="margin:0; font-size: 0.85rem;">Este hilo ha sido bloqueado por el administrador y no admite más respuestas.</p>
        </div>
    <?php endif; ?>

    <script>
    function moderateAction(act, tId) {
        if (!confirm('¿Estás seguro de registrar esta acción en el sistema? Las acciones de moderación asignan Puntos GP y no se pueden deshacer fácilmente.')) return;
        fetch('api_forum_interaction.php', {
            method: 'POST',
            body: JSON.stringify({ action: act, targetId: tId }),
            headers: { 'Content-Type': 'application/json' }
        }).then(r => r.json()).then(res => {
            if(res.success) {
                alert(res.message);
                location.reload();
            } else {
                alert("Error: " + res.error);
            }
        });
    }

    function voteHelpful(replyId) {
        fetch('api_forum_interaction.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'mark_helpful', targetId: replyId }),
            headers: { 'Content-Type': 'application/json' }
        }).then(r => r.json()).then(res => {
            if(res.success) {
                const btn = document.getElementById('helpfulBtn_' + replyId);
                const count = document.getElementById('helpfulCount_' + replyId);
                const icon = btn.querySelector('i');
                count.innerText = res.count;
                btn.style.background = '#dcfce7';
                btn.style.color = '#166534';
                btn.style.borderColor = '#bbf7d0';
                icon.className = 'bx bxs-star';
                
                if (res.earned) {
                    alert('¡' + res.message + ' La respuesta ya es oficial!');
                    location.reload();
                }
            } else {
                alert(res.error);
            }
        });
    }

    function toggleLike(replyId) {
        fetch('api_forum_interaction.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'toggle_like', targetId: replyId }),
            headers: { 'Content-Type': 'application/json' }
        }).then(r => r.json()).then(res => {
            if(res.success) {
                const btn = document.getElementById('likeBtn_' + replyId);
                const count = document.getElementById('likeCount_' + replyId);
                const icon = btn.querySelector('i');
                count.innerText = res.count;
                if(res.liked) {
                    btn.style.background = '#e0e7ff';
                    btn.style.color = '#4f46e5';
                    btn.style.borderColor = '#c7d2fe';
                    icon.className = 'bx bxs-like';
                } else {
                    btn.style.background = '#f1f5f9';
                    btn.style.color = '#64748b';
                    btn.style.borderColor = '#e2e8f0';
                    icon.className = 'bx bx-like';
                }
            } else {
                alert(res.error);
            }
        });
    }
    function toggleReplyBox(id) {
        let box = document.getElementById('replyBox_' + id);
        if (box.style.display === 'none') {
            box.style.display = 'block';
        } else {
            box.style.display = 'none';
        }
    }
    </script>
<?php endif; ?>
