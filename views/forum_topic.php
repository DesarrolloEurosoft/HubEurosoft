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

// Detectar si el usuario es MODELADOR (puede interactuar con el foro LO)
$isModelador = false;
if ($userId && !$isAdmin && !$isCoLeader && !$isBuLeader && !$isLectorOp) {
    $stmtMod = $pdo->prepare("SELECT 1 FROM TrainingRole tr JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id WHERE rtu.B = ? AND LOWER(tr.name) LIKE '%modelad%' LIMIT 1");
    $stmtMod->execute([$userId]);
    $isModelador = (bool)$stmtMod->fetchColumn();
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
            if ($isLoForum) {
                // Foro LO: solo Modeladores y Líderes pueden acceder para responder.
                // Cualquier otro rol queda bloqueado.
                if (!$isModelador && !$isCoLeader && !$isBuLeader) {
                    echo "<div class='alert alert-error'>Este espacio es exclusivo del equipo Lector Operativo y sus colaboradores directos.</div>";
                    return;
                }
            } else {
                // Otros foros de rol: solo líderes pueden ver en modo lectura.
                if (!$isCoLeader && !$isBuLeader) {
                    echo "<div class='alert alert-error'>Acceso Denegado: No cuentas con el Perfil Formativo requerido para esta sala.</div>";
                    return;
                }
            }
        }
    }
}
// Visitante al foro LO: solo Modeladores y Líderes (no el LO, no admin)
$isVisitorToLoForum = ($isLoForum ?? false) && !$isLectorOp && !$isAdmin && ($isModelador || $isCoLeader || $isBuLeader);

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

<?php
    $displayRole = $forum['targetRole'];
    if ($forum['targetRole'] !== 'GENERAL') {
        $st = $pdo->prepare("SELECT name FROM TrainingRole WHERE id = ?");
        $st->execute([$forum['targetRole']]);
        $trNam = $st->fetchColumn();
        if ($trNam) $displayRole = $trNam;
    }

    // ── Paleta derivada de forumCardStyle() en forums.php ────────────────
    // Misma lógica que el directorio de foros para garantizar sincronía visual.
    $displayRoleLower = strtolower($forum['trName'] ?? $displayRole);
    $targetRole       = $forum['targetRole'];

    if ($targetRole === 'GENERAL') {
        $forumIcon        = 'bx-conversation';
        $forumGradient    = 'linear-gradient(135deg, #3b82f6, #2563eb)';
        $forumAccent      = '#2563eb';
        $forumLightBg     = 'linear-gradient(to right, #eff6ff, #dbeafe)';
        $forumLightBorder = '#bfdbfe';
    } elseif (str_contains($displayRoleLower, 'auditor')) {
        $forumIcon        = 'bx-shield';
        $forumGradient    = 'linear-gradient(135deg, #ef4444, #dc2626)';
        $forumAccent      = '#dc2626';
        $forumLightBg     = 'linear-gradient(to right, #fef2f2, #fee2e2)';
        $forumLightBorder = '#fecaca';
    } elseif (str_contains($displayRoleLower, 'modelad')) {
        $forumIcon        = 'bx-cube-alt';
        $forumGradient    = 'linear-gradient(135deg, #8b5cf6, #7c3aed)';
        $forumAccent      = '#7c3aed';
        $forumLightBg     = 'linear-gradient(to right, #f5f3ff, #ede9fe)';
        $forumLightBorder = '#ddd6fe';
    } elseif (str_contains($displayRoleLower, 'integrad')) {
        $forumIcon        = 'bx-git-branch';
        $forumGradient    = 'linear-gradient(135deg, #14b8a6, #0d9488)';
        $forumAccent      = '#0d9488';
        $forumLightBg     = 'linear-gradient(to right, #f0fdfa, #ccfbf1)';
        $forumLightBorder = '#99f6e4';
    } elseif (str_contains($displayRoleLower, 'lector')) {
        $forumIcon        = 'bx-book-reader';
        $forumGradient    = 'linear-gradient(135deg, #10b981, #059669)';
        $forumAccent      = '#059669';
        $forumLightBg     = 'linear-gradient(to right, #f0fdf4, #dcfce7)';
        $forumLightBorder = '#bbf7d0';
    } elseif (str_contains($displayRoleLower, 'coordinad')) {
        $forumIcon        = 'bx-grid-alt';
        $forumGradient    = 'linear-gradient(135deg, #f59e0b, #d97706)';
        $forumAccent      = '#d97706';
        $forumLightBg     = 'linear-gradient(to right, #fffbeb, #fef3c7)';
        $forumLightBorder = '#fde68a';
    } elseif (str_contains($displayRoleLower, 'analista')) {
        $forumIcon        = 'bx-bar-chart-alt-2';
        $forumGradient    = 'linear-gradient(135deg, #6366f1, #4f46e5)';
        $forumAccent      = '#4f46e5';
        $forumLightBg     = 'linear-gradient(to right, #eef2ff, #e0e7ff)';
        $forumLightBorder = '#c7d2fe';
    } elseif (str_contains($displayRoleLower, 'aprob')) {
        $forumIcon        = 'bx-check-shield';
        $forumGradient    = 'linear-gradient(135deg, #ec4899, #db2777)';
        $forumAccent      = '#db2777';
        $forumLightBg     = 'linear-gradient(to right, #fdf2f8, #fce7f3)';
        $forumLightBorder = '#fbcfe8';
    } elseif (str_contains($displayRoleLower, 'participante')) {
        $forumIcon        = 'bx-group';
        $forumGradient    = 'linear-gradient(135deg, #06b6d4, #0891b2)';
        $forumAccent      = '#0891b2';
        $forumLightBg     = 'linear-gradient(to right, #ecfeff, #cffafe)';
        $forumLightBorder = '#a5f3fc';
    } elseif (str_contains($displayRoleLower, 'ti') || str_contains($displayRoleLower, 'admin ti')) {
        $forumIcon        = 'bx-chip';
        $forumGradient    = 'linear-gradient(135deg, #64748b, #475569)';
        $forumAccent      = '#475569';
        $forumLightBg     = 'linear-gradient(to right, #f8fafc, #f1f5f9)';
        $forumLightBorder = '#cbd5e1';
    } else {
        $forumIcon        = 'bx-star';
        $forumGradient    = 'linear-gradient(135deg, #FF6A00, #FFA500)';
        $forumAccent      = '#FF6A00';
        $forumLightBg     = 'linear-gradient(to right, #fff7ed, #fef3c7)';
        $forumLightBorder = '#fde68a';
    }

?>

<?php if ($successMsg): ?>
<div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:1rem;padding:0.875rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;"><i class='bx bx-check-circle' style="font-size:1.2rem;"></i><?= htmlspecialchars($successMsg) ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
<div style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:1rem;padding:0.875rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.75rem;"><i class='bx bx-error-circle' style="font-size:1.2rem;"></i><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>


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

<!-- Forum Header -->
<div style="background:<?= $forumGradient ?>;border-radius:1.5rem;padding:1.75rem 2rem;margin-bottom:1.25rem;color:white;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:240px;height:240px;background:rgba(255,255,255,0.07);border-radius:50%;transform:translate(30%,-40%);pointer-events:none;"></div>
    <div style="position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:1.25rem;">
            <div style="width:64px;height:64px;background:rgba(255,255,255,0.18);border-radius:1.25rem;display:flex;align-items:center;justify-content:center;border:1.5px solid rgba(255,255,255,0.3);flex-shrink:0;">
                <i class='bx <?= $forumIcon ?>' style="font-size:2rem;color:white;"></i>
            </div>
            <div>
                <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.375rem;">
                    <span style="background:rgba(255,255,255,0.2);color:white;font-size:0.65rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em;padding:2px 10px;border-radius:999px;border:1px solid rgba(255,255,255,0.3);"><?= htmlspecialchars($displayRole) ?></span>
                    <?php if ($forum['targetRole'] !== 'GENERAL'): ?><span style="background:rgba(255,255,255,0.15);color:rgba(255,255,255,0.9);font-size:0.65rem;font-weight:700;padding:2px 10px;border-radius:999px;">Foro Especializado</span><?php endif; ?>
                </div>
                <h1 style="font-size:1.5rem;font-weight:800;margin:0 0 0.25rem;color:white;"><?= htmlspecialchars($forum['title']) ?></h1>
                <p style="font-size:0.8rem;color:rgba(255,255,255,0.8);margin:0;"><?= htmlspecialchars($forum['description']) ?></p>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.75rem;">
            <?php if ($hasPostingRights || $isAdmin): ?>
            <button onclick="document.getElementById('modalCT').classList.add('active')" style="display:inline-flex;align-items:center;gap:0.5rem;background:white;color:#FF6A00;font-weight:700;font-size:0.875rem;padding:0.7rem 1.25rem;border-radius:1rem;border:none;cursor:pointer;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:all 0.2s;white-space:nowrap;" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                <i class='bx bx-edit-alt'></i> Nueva Discusión
            </button>
            <?php elseif ($isVisitorToLoForum): ?>
            <div style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);color:white;font-size:0.8rem;font-weight:700;padding:0.5rem 1rem;border-radius:999px;"><i class='bx bx-chat'></i> Responde a tu equipo</div>
            <?php else: ?>
            <div style="background:rgba(0,0,0,0.15);color:rgba(255,255,255,0.7);font-size:0.8rem;font-weight:600;padding:0.5rem 1rem;border-radius:999px;"><i class='bx bx-show'></i> Modo Observador</div>
            <?php endif; ?>
            <div style="display:flex;gap:1.25rem;font-size:0.75rem;color:rgba(255,255,255,0.75);">
                <span style="display:flex;align-items:center;gap:0.3rem;"><i class='bx bx-message-square-dots'></i> <?= $tTotal ?> hilos</span>
            </div>
        </div>
    </div>
</div>

<?php if ($isVisitorToLoForum): ?>
<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:1rem;padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:0.875rem;">
    <div style="width:38px;height:38px;background:#fde68a;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class='bx bx-group' style="font-size:1.2rem;color:#92400e;"></i></div>
    <div><div style="font-weight:800;color:#92400e;font-size:0.875rem;">Apoyando al Equipo Operativo</div><div style="color:#78350f;font-size:0.78rem;margin-top:0.15rem;">Puedes leer y responder a las preguntas de tu equipo. Los temas nuevos solo los abre el Lector Operativo.</div></div>
</div>
<?php endif; ?>

<!-- Search & Filters -->
<div style="background:white;border-radius:1.25rem;padding:0.875rem 1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div style="flex:1;min-width:180px;position:relative;">
            <i class='bx bx-search' style="position:absolute;left:0.75rem;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:1.05rem;"></i>
            <input type="text" id="topicSearch" oninput="applyTopicFilters()" placeholder="Buscar discusiones..." style="width:100%;padding:0.55rem 1rem 0.55rem 2.25rem;background:#f9fafb;border:1px solid #f3f4f6;border-radius:0.75rem;font-size:0.875rem;color:#111827;outline:none;box-sizing:border-box;transition:border-color 0.2s;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#f3f4f6'">
        </div>
        <div style="display:flex;align-items:center;gap:0.2rem;background:#f3f4f6;border-radius:999px;padding:0.2rem;">
            <button onclick="setTopicFilter('all',this)" id="fpill_all" style="padding:0.45rem 1rem;border-radius:999px;font-size:0.78rem;font-weight:600;border:none;cursor:pointer;background:white;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,0.1);transition:all 0.2s;">Todos</button>
            <button onclick="setTopicFilter('unanswered',this)" id="fpill_unanswered" style="padding:0.45rem 1rem;border-radius:999px;font-size:0.78rem;font-weight:600;border:none;cursor:pointer;background:transparent;color:#6b7280;transition:all 0.2s;">Sin responder</button>
            <button onclick="setTopicFilter('solved',this)" id="fpill_solved" style="padding:0.45rem 1rem;border-radius:999px;font-size:0.78rem;font-weight:600;border:none;cursor:pointer;background:transparent;color:#6b7280;transition:all 0.2s;">Resueltos</button>
        </div>
    </div>
</div>

<!-- Topics Cards -->
<?php if (count($topics) > 0): ?>
<div style="display:flex;flex-direction:column;gap:0.75rem;" id="topicsContainer">
<?php foreach($topics as $t):
    $tExcerpt = mb_strimwidth($t['content'] ?? '', 0, 120, '...');
    $tDateFmt = date('d/m/Y H:i', strtotime($t['lastActivity'] ?? $t['createdAt']));
    $tIsUnansw = (int)$t['replyCount'] === 0 ? '1' : '0';
    $tIsSolved = !empty($t['hasHelpfulReply']) ? '1' : '0';
    $tAName = $t['authorName'] ?? 'U';
    $tInit = strtoupper(mb_substr($tAName,0,1));
    if (strpos($tAName,' ')!==false){$p=explode(' ',$tAName,2);$tInit=strtoupper(mb_substr($p[0],0,1).mb_substr($p[1],0,1));}
?>
<a href="index.php?view=forum_topic&forum_id=<?= urlencode($forumId) ?>&topic_id=<?= urlencode($t['id']) ?>"
   class="topic-card"
   data-title="<?= htmlspecialchars(strtolower($t['title'].' '.$tAName)) ?>"
   data-unanswered="<?= $tIsUnansw ?>"
   data-solved="<?= $tIsSolved ?>"
   style="background:white;border-radius:1.25rem;padding:1.25rem 1.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;text-decoration:none;display:block;transition:all 0.25s;"
   onmouseover="this.style.boxShadow='0 4px 16px rgba(0,0,0,0.09)';this.style.borderColor='#ffedd5';this.style.transform='translateY(-1px)'"
   onmouseout="this.style.boxShadow='0 1px 2px rgba(0,0,0,0.05)';this.style.borderColor='#f3f4f6';this.style.transform='translateY(0)'">
    <div style="display:flex;gap:1rem;align-items:flex-start;">
        <!-- Avatar -->
        <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;color:white;font-size:0.875rem;font-weight:800;flex-shrink:0;"><?= htmlspecialchars($tInit) ?></div>
        <!-- Content -->
        <div style="flex:1;min-width:0;">
            <!-- Badges row -->
            <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                <?php if($t['isPinned']): ?><span style="background:#fff7ed;color:#FF6A00;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:999px;display:inline-flex;align-items:center;gap:3px;"><i class='bx bxs-pin'></i>Fijado</span><?php endif; ?>
                <?php if($t['isLocked']): ?><span style="background:#fef3c7;color:#92400e;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:999px;display:inline-flex;align-items:center;gap:3px;"><i class='bx bxs-lock-alt'></i>Cerrado</span><?php endif; ?>
                <?php if(!empty($t['hasHelpfulReply'])): ?><span style="background:#dcfce7;color:#166534;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:999px;display:inline-flex;align-items:center;gap:3px;"><i class='bx bxs-check-circle'></i>Resuelto</span><?php endif; ?>
                <?= typeBadge($t['threadType']) ?>
            </div>
            <!-- Title -->
            <h3 style="font-size:1rem;font-weight:700;color:#111827;margin:0 0 0.3rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($t['title']) ?></h3>
            <!-- Excerpt -->
            <p style="font-size:0.8rem;color:#6b7280;margin:0 0 0.75rem;line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($tExcerpt) ?></p>
            <!-- Meta row -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                <div style="display:flex;align-items:center;gap:0.5rem;font-size:0.75rem;color:#6b7280;">
                    <span style="font-weight:700;color:#374151;"><?= htmlspecialchars($tAName) ?></span>
                    <?= getRoleBadge($t['authorRole']) ?>
                    <span style="background:#f1f5f9;color:#64748b;font-size:0.65rem;padding:1px 6px;border-radius:4px;"><i class='bx bx-store'></i> <?= htmlspecialchars($t['authorBuName']) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:1rem;font-size:0.75rem;color:#9ca3af;">
                    <span style="display:flex;align-items:center;gap:0.25rem;"><i class='bx bx-message-square-dots'></i><strong style="color:#374151;"><?= (int)$t['replyCount'] ?></strong></span>
                    <span style="display:flex;align-items:center;gap:0.25rem;"><i class='bx bx-show'></i><strong style="color:#374151;"><?= (int)$t['views'] ?></strong></span>
                    <span style="display:flex;align-items:center;gap:0.25rem;"><i class='bx bx-time'></i> <?= $tDateFmt ?></span>
                </div>
            </div>
        </div>
    </div>
</a>
<?php endforeach; ?>
</div>
<?php else: ?>
<div style="background:white;border-radius:1.5rem;padding:4rem 2rem;text-align:center;border:1px solid #f3f4f6;">
    <div style="width:64px;height:64px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;"><i class='bx bx-message-square-dots' style="font-size:2rem;color:#9ca3af;"></i></div>
    <h3 style="font-size:1rem;font-weight:700;color:#374151;margin:0 0 0.5rem;">Aún no hay discusiones</h3>
    <p style="font-size:0.875rem;color:#9ca3af;margin:0;">¡Sé el primero en publicar en este foro!</p>
</div>
<?php endif; ?>

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

<script>
let activeTopicFilter = 'all';
function setTopicFilter(f, btn) {
    activeTopicFilter = f;
    document.querySelectorAll('#fpill_all,#fpill_unanswered,#fpill_solved').forEach(b => {
        b.style.background = 'transparent'; b.style.color = '#6b7280'; b.style.boxShadow = 'none';
    });
    btn.style.background = 'white'; btn.style.color = '#111827'; btn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
    applyTopicFilters();
}
function applyTopicFilters() {
    const q = document.getElementById('topicSearch').value.toLowerCase();
    document.querySelectorAll('.topic-card').forEach(c => {
        const matchText = c.dataset.title.includes(q);
        const matchFilter = activeTopicFilter === 'all'
            || (activeTopicFilter === 'unanswered' && c.dataset.unanswered === '1')
            || (activeTopicFilter === 'solved' && c.dataset.solved === '1');
        c.style.display = matchText && matchFilter ? '' : 'none';
    });
}
</script>


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
                            <div style="width:40px;height:40px;border-radius:10px;background:#dbeafe;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-help-circle' style="font-size:1.35rem;color:#2563eb;"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.8rem;color:#1e293b;">Tengo una pregunta</div>
                        </button>
                        <button type="button" onclick="selectIntent('IMPROVEMENT',this)" id="btn_IMPROVEMENT"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="width:40px;height:40px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-bulb' style="font-size:1.35rem;color:#d97706;"></i>
                            </div>
                            <div style="font-weight:700;font-size:0.8rem;color:#1e293b;">Tengo una propuesta</div>
                        </button>
                        <button type="button" onclick="selectIntent('CONTRIBUTION',this)" id="btn_CONTRIBUTION"
                            style="padding:1rem 0.5rem;border:2px solid #e2e8f0;border-radius:12px;background:#f8fafc;cursor:pointer;text-align:center;transition:all 0.2s;font-family:inherit;">
                            <div style="width:40px;height:40px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;margin:0 auto 0.5rem;">
                                <i class='bx bx-share-alt' style="font-size:1.35rem;color:#FF6A00;"></i>
                            </div>
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

    // Related topics for sidebar
    $stmtRelated = $pdo->prepare("SELECT id, title, (SELECT COUNT(id) FROM ForumReply r WHERE r.topicId = t.id) as rc FROM ForumTopic t WHERE t.forumId = ? AND t.id != ? ORDER BY t.updatedAt DESC LIMIT 4");
    $stmtRelated->execute([$forumId, $topicId]);
    $relatedTopics = $stmtRelated->fetchAll();
?>
<a href="index.php?view=forum_topic&forum_id=<?= urlencode($forumId) ?>" style="display:inline-flex;align-items:center;gap:0.5rem;color:#6b7280;font-size:0.875rem;font-weight:600;text-decoration:none;margin-bottom:1.25rem;transition:color 0.2s;" onmouseover="this.style.color='#111827'" onmouseout="this.style.color='#6b7280'">
    <i class='bx bx-left-arrow-alt'></i> Salir de la Discusión
</a>

<?php if ($mostVotedReplyId): ?>
<div style="background:#fff7ed;border:1px solid #ffedd5;border-radius:1rem;padding:0.875rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
    <div style="display:flex;align-items:center;gap:0.75rem;color:#c2410c;font-weight:700;font-size:0.875rem;">
        <i class='bx bxs-star' style="font-size:1.25rem;color:#FF6A00;"></i>
        La comunidad ha resaltado una respuesta útil en este hilo.
    </div>
    <a href="#reply-<?= htmlspecialchars($mostVotedReplyId) ?>" style="background:#FF6A00;color:white;font-size:0.8rem;padding:0.4rem 1rem;border-radius:0.75rem;text-decoration:none;font-weight:700;white-space:nowrap;">Ver ganadora (<?= $maxVotes ?> votos)</a>
</div>
<?php endif; ?>

<div class="ft-main-grid">
<!-- ── MAIN COLUMN ── -->
<div>
<!-- Post Original -->
<div style="background:white;border-radius:1.5rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.06);border:1px solid #f3f4f6;margin-bottom:1.25rem;">
    <div style="background:<?= $forumLightBg ?>;padding:1.5rem 1.75rem;border-bottom:1px solid <?= $forumLightBorder ?>;">
        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.75rem;flex-wrap:wrap;">
            <?= typeBadge($topic['threadType']) ?>
            <?php if ($topic['isPinned']): ?><span style="background:#fff7ed;color:#FF6A00;font-size:0.65rem;font-weight:700;padding:2px 8px;border-radius:999px;"><i class='bx bxs-pin'></i> Fijado</span><?php endif; ?>
            <?php if ($topic['isValidatedPractice'] ?? 0): ?><span style="background:#fef08a;color:#854d0e;font-size:0.65rem;font-weight:800;padding:2px 10px;border-radius:999px;"><i class='bx bxs-medal'></i> Buena Práctica Validada</span><?php endif; ?>
        </div>
        <h1 style="font-size:1.4rem;font-weight:800;color:#111827;margin:0;"><?= htmlspecialchars($topic['title']) ?></h1>
    </div>
    <div style="padding:1.5rem 1.75rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.25rem;">
            <div style="display:flex;align-items:center;gap:0.875rem;">
                <div style="width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;color:white;font-size:0.875rem;font-weight:800;flex-shrink:0;">
                    <?= strtoupper(mb_substr($topic['authorName'],0,1)) ?>
                </div>
                <div>
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                        <span style="font-weight:700;color:#111827;font-size:0.9rem;"><?= htmlspecialchars($topic['authorName']) ?></span>
                        <?= getRoleBadge($topic['authorRole']) ?>
                        <span style="font-size:0.65rem;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:4px;"><i class='bx bx-store'></i> <?= htmlspecialchars($topic['authorBuName']) ?></span>
                    </div>
                    <div style="font-size:0.75rem;color:#9ca3af;margin-top:0.2rem;"><i class='bx bx-time'></i> <?= date('d/m/Y H:i', strtotime($topic['createdAt'])) ?></div>
                </div>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <?php if (($isAdmin||$isCoLeader||$isBuLeader) && !($topic['isValidatedPractice']??0) && $topic['authorId']!==$userId): ?>
                <button onclick="moderateAction('mark_practice','<?= $topicId ?>')" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:0.5rem;padding:0.35rem 0.75rem;font-size:0.75rem;cursor:pointer;font-weight:700;"><i class='bx bx-check-shield'></i> Aprobar</button>
                <?php endif; ?>
                <?php if ($canModerate): ?>
                <form method="POST" onsubmit="return confirm('¿Borrar todo el tema y sus respuestas?');" style="margin:0;">
                    <input type="hidden" name="action" value="delete_topic">
                    <input type="hidden" name="del_id" value="<?= htmlspecialchars($topicId) ?>">
                    <button type="submit" style="background:#fee2e2;color:#b91c1c;border:1px solid #fecaca;border-radius:0.5rem;padding:0.35rem 0.625rem;font-size:0.875rem;cursor:pointer;"><i class='bx bx-trash'></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <div style="white-space:pre-wrap;font-size:0.925rem;line-height:1.7;color:#374151;"><?= htmlspecialchars($topic['content']) ?></div>
        <div style="margin-top:1.25rem;padding-top:1rem;border-top:1px solid #f3f4f6;display:flex;gap:1.5rem;color:#9ca3af;font-size:0.8rem;font-weight:600;">
            <span style="display:flex;align-items:center;gap:0.3rem;"><i class='bx bx-show'></i> <?= (int)$topic['views'] ?> vistas</span>
            <span style="display:flex;align-items:center;gap:0.3rem;"><i class='bx bx-comment-detail'></i> <?= count($rawReplies) ?> respuestas</span>
        </div>
    </div>
</div>

<!-- RESPUESTAS -->
<?php if (!empty($flattenedReplies)): ?>
<div style="margin-bottom:0.5rem;">
    <h2 style="font-size:1rem;font-weight:700;color:#111827;margin:0 0 1rem;"><?= count($rawReplies) ?> Respuestas</h2>
</div>
<?php endif; ?>
<?php foreach($flattenedReplies as $r):
    $rMargin = $r['displayLevel'] * 1.5;
    $rInit = strtoupper(mb_substr($r['authorName'],0,1));
    $rIsHelpful = $r['isHelpful'] ?? 0;
?>
<div id="reply-<?= htmlspecialchars($r['id']) ?>" style="margin-bottom:0.75rem;margin-left:<?= $rMargin ?>rem;">
    <div style="background:<?= $rIsHelpful ? '#f0fdf4' : 'white' ?>;border-radius:1.25rem;padding:1.25rem 1.5rem;border:1px solid <?= $rIsHelpful ? '#bbf7d0' : '#f3f4f6' ?>;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
        <?php if ($rIsHelpful): ?>
        <div style="display:inline-flex;align-items:center;gap:0.5rem;background:#22c55e;color:white;font-size:0.7rem;font-weight:700;padding:4px 12px;border-radius:999px;margin-bottom:0.875rem;">
            <i class='bx bxs-star'></i> Respuesta Útil Oficial (<?= (int)$r['helpfulVotesCount'] ?>)
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:0.875rem;align-items:flex-start;">
            <div style="width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;color:white;font-size:0.8rem;font-weight:800;flex-shrink:0;"><?= htmlspecialchars($rInit) ?></div>
            <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;flex-wrap:wrap;gap:0.5rem;">
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                        <span style="font-weight:700;color:#111827;font-size:0.875rem;"><?= htmlspecialchars($r['authorName']) ?></span>
                        <?= getRoleBadge($r['authorRole']) ?>
                        <span style="font-size:0.65rem;color:#64748b;background:#f1f5f9;padding:1px 6px;border-radius:4px;"><i class='bx bx-store'></i> <?= htmlspecialchars($r['authorBuName']) ?></span>
                        <span style="font-size:0.72rem;color:#9ca3af;"><?= date('d/m/y H:i', strtotime($r['createdAt'])) ?></span>
                    </div>
                    <?php if ($canModerate): ?>
                    <form method="POST" onsubmit="return confirm('¿Borrar esta respuesta y sus sub-respuestas?');" style="margin:0;">
                        <input type="hidden" name="action" value="delete_reply">
                        <input type="hidden" name="del_id" value="<?= htmlspecialchars($r['id']) ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;color:#ef4444;font-size:1.1rem;padding:0;"><i class='bx bx-trash'></i></button>
                    </form>
                    <?php endif; ?>
                </div>
                <div style="white-space:pre-wrap;font-size:0.875rem;color:#374151;line-height:1.65;margin-bottom:0.875rem;"><?= htmlspecialchars($r['content']) ?></div>
                <div style="display:flex;gap:0.625rem;align-items:center;flex-wrap:wrap;">
                    <?php if ($r['authorId'] !== $userId): ?>
                    <button onclick="toggleLike('<?= $r['id'] ?>')" id="likeBtn_<?= $r['id'] ?>" style="display:flex;align-items:center;gap:0.3rem;padding:0.3rem 0.75rem;border-radius:0.625rem;font-size:0.75rem;font-weight:600;cursor:pointer;border:1px solid <?= $r['isLikedByMe'] ? '#c7d2fe' : '#e5e7eb' ?>;background:<?= $r['isLikedByMe'] ? '#e0e7ff' : '#f9fafb' ?>;color:<?= $r['isLikedByMe'] ? '#4f46e5' : '#6b7280' ?>;transition:all 0.2s;">
                        <i class='bx <?= $r['isLikedByMe'] ? 'bxs-like' : 'bx-like' ?>'></i><span id="likeCount_<?= $r['id'] ?>"><?= (int)($r['likesCount']??0) ?></span>
                    </button>
                    <?php else: ?>
                    <div style="display:flex;align-items:center;gap:0.3rem;padding:0.3rem 0.75rem;border-radius:0.625rem;font-size:0.75rem;background:#f9fafb;color:#9ca3af;border:1px solid #e5e7eb;" title="No puedes votar tu propia respuesta"><i class='bx bx-like'></i><?= (int)($r['likesCount']??0) ?></div>
                    <?php endif; ?>

                    <?php if (!$rIsHelpful && $r['authorId'] !== $userId): ?>
                    <button id="helpfulBtn_<?= $r['id'] ?>" onclick="voteHelpful('<?= $r['id'] ?>')" style="display:flex;align-items:center;gap:0.3rem;padding:0.3rem 0.75rem;border-radius:0.625rem;font-size:0.75rem;font-weight:600;cursor:pointer;border:1px solid <?= $r['isVotedHelpfulByMe'] ? '#bbf7d0' : '#e5e7eb' ?>;background:<?= $r['isVotedHelpfulByMe'] ? '#dcfce7' : '#f9fafb' ?>;color:<?= $r['isVotedHelpfulByMe'] ? '#166534' : '#6b7280' ?>;transition:all 0.2s;" title="<?= $r['isVotedHelpfulByMe'] ? 'Ya votaste' : 'Votar como útil' ?>">
                        <i class='bx <?= $r['isVotedHelpfulByMe'] ? 'bxs-star' : 'bx-star' ?>'></i> Útil (<span id="helpfulCount_<?= $r['id'] ?>"><?= (int)$r['helpfulVotesCount'] ?></span>)
                    </button>
                    <?php endif; ?>

                    <?php if (!$topic['isLocked']): ?>
                    <button onclick="toggleReplyBox('<?= $r['id'] ?>')" style="display:flex;align-items:center;gap:0.3rem;padding:0.3rem 0.75rem;border-radius:0.625rem;font-size:0.75rem;font-weight:600;cursor:pointer;border:none;background:transparent;color:#9ca3af;transition:color 0.2s;" onmouseover="this.style.color='#374151'" onmouseout="this.style.color='#9ca3af'">
                        <i class='bx bx-message-square-dots'></i> Responder
                    </button>
                    <?php endif; ?>
                </div>
                <div id="replyBox_<?= $r['id'] ?>" style="display:none;margin-top:0.875rem;padding-top:0.875rem;border-top:1px dashed #e5e7eb;">
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="action" value="create_reply">
                        <input type="hidden" name="parent_id" value="<?= $r['id'] ?>">
                        <textarea name="content" required rows="3" style="width:100%;border:1px solid #e5e7eb;border-radius:0.75rem;padding:0.625rem 0.875rem;font-size:0.85rem;font-family:inherit;resize:vertical;box-sizing:border-box;outline:none;transition:border-color 0.2s;" placeholder="Responde a <?= htmlspecialchars($r['authorName']) ?>..." onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></textarea>
                        <div style="display:flex;justify-content:flex-end;gap:0.5rem;margin-top:0.5rem;">
                            <button type="button" onclick="toggleReplyBox('<?= $r['id'] ?>')" style="padding:0.35rem 0.75rem;border-radius:0.625rem;font-size:0.8rem;background:#f3f4f6;color:#6b7280;border:none;cursor:pointer;">Cancelar</button>
                            <button type="submit" style="padding:0.35rem 0.875rem;border-radius:0.625rem;font-size:0.8rem;background:#FF6A00;color:white;border:none;cursor:pointer;font-weight:700;">Enviar</button>
                        </div>
                    </form>
                </div>
            </div>
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
    <div style="background:white;border-radius:1.5rem;overflow:hidden;border:1px solid #f3f4f6;box-shadow:0 1px 3px rgba(0,0,0,0.06);margin-top:1rem;">
        <div style="background:<?= $forumLightBg ?>;padding:1rem 1.5rem;border-bottom:1px solid <?= $forumLightBorder ?>;">
            <h3 style="font-size:0.9rem;font-weight:700;color:#111827;margin:0;">Tu Respuesta</h3>
        </div>
        <div style="padding:1.25rem 1.5rem;">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="create_reply">
                <textarea name="content" required rows="5" style="width:100%;border:1px solid #e5e7eb;border-radius:0.875rem;padding:0.75rem 1rem;font-size:0.9rem;font-family:inherit;resize:vertical;box-sizing:border-box;outline:none;transition:border-color 0.2s;color:#374151;" placeholder="Comparte tu contribución." onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></textarea>
                <div style="display:flex;justify-content:flex-end;margin-top:0.875rem;">
                    <button type="submit" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.625rem 1.25rem;background:linear-gradient(135deg,#FF6A00,#FFA500);color:white;font-weight:700;font-size:0.875rem;border:none;border-radius:0.875rem;cursor:pointer;transition:all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class='bx bx-send'></i> Publicar Respuesta
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:2rem;background:#fef3c7;border-radius:1rem;border:1px solid #fde68a;margin-top:1rem;">
        <i class='bx bxs-lock-alt' style="font-size:1.5rem;color:#92400e;display:block;margin-bottom:0.5rem;"></i>
        <h4 style="margin:0 0 0.25rem;color:#92400e;">Tema Cerrado</h4>
        <p style="margin:0;font-size:0.85rem;color:#78350f;">Este hilo ha sido bloqueado y no admite más respuestas.</p>
    </div>
    <?php endif; ?>

</div><!-- /main column -->

<!-- ── SIDEBAR ── -->
<div style="position:sticky;top:1rem;">
    <!-- Thread Stats -->
    <div style="background:white;border-radius:1.25rem;padding:1.25rem;border:1px solid #f3f4f6;box-shadow:0 1px 2px rgba(0,0,0,0.05);margin-bottom:1rem;">
        <h3 style="font-size:0.875rem;font-weight:700;color:#111827;margin:0 0 0.875rem;">Estadísticas</h3>
        <div style="display:flex;flex-direction:column;gap:0.625rem;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.8rem;color:#6b7280;display:flex;align-items:center;gap:0.375rem;"><i class='bx bx-show'></i> Vistas</span>
                <span style="font-weight:700;color:#111827;font-size:0.875rem;"><?= (int)$topic['views'] ?></span>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.8rem;color:#6b7280;display:flex;align-items:center;gap:0.375rem;"><i class='bx bx-message-square-dots'></i> Respuestas</span>
                <span style="font-weight:700;color:#111827;font-size:0.875rem;"><?= count($rawReplies) ?></span>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.8rem;color:#6b7280;display:flex;align-items:center;gap:0.375rem;"><i class='bx bx-calendar'></i> Publicado</span>
                <span style="font-weight:600;color:#374151;font-size:0.8rem;"><?= date('d/m/Y', strtotime($topic['createdAt'])) ?></span>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.8rem;color:#6b7280;">Tipo</span>
                <?= typeBadge($topic['threadType']) ?>
            </div>
        </div>
    </div>

    <!-- Related Topics -->
    <?php if (!empty($relatedTopics)): ?>
    <div style="background:white;border-radius:1.25rem;padding:1.25rem;border:1px solid #f3f4f6;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
        <h3 style="font-size:0.875rem;font-weight:700;color:#111827;margin:0 0 0.875rem;">Temas Relacionados</h3>
        <div style="display:flex;flex-direction:column;gap:0.5rem;">
            <?php foreach($relatedTopics as $rt): ?>
            <a href="index.php?view=forum_topic&forum_id=<?= urlencode($forumId) ?>&topic_id=<?= urlencode($rt['id']) ?>" style="display:block;padding:0.75rem;background:#f9fafb;border-radius:0.875rem;text-decoration:none;transition:background 0.2s;" onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background='#f9fafb'">
                <p style="font-size:0.8rem;font-weight:600;color:#374151;margin:0 0 0.25rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($rt['title']) ?></p>
                <p style="font-size:0.7rem;color:#9ca3af;margin:0;"><?= (int)$rt['rc'] ?> respuestas</p>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div><!-- /sidebar -->

</div><!-- /grid -->

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
