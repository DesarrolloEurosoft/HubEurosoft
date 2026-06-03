<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// 1. Verificación Estricta (Solo Eurosoft Admins / Super Administradores)
$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'ROOT_ADMIN', 'SUPERVISOR'])) {
    echo "<div class='alert alert-error'>Acceso Denegado: Zona Exclusiva para Eurosoft.</div>";
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_type') {
    $topicId = $_POST['topic_id'] ?? '';
    $newType = $_POST['new_type'] ?? 'GENERAL';
    $pdo->prepare("UPDATE ForumTopic SET threadType = ? WHERE id = ?")->execute([$newType, $topicId]);
    echo "<script>window.location.href='index.php?view=forum_moderation';</script>";
    exit;
}

// 2. Extracción de Todas Las Discusiones para Filtrado
$sql = "
    SELECT 
        t.id, t.forumId, t.title, t.threadType, t.views, t.isValidatedPractice, t.createdAt, 
        COALESCE(u.name, '[Usuario eliminado]') as authorName,
        COALESCE(u.role, 'STUDENT') as authorRole,
        c.name as companyName,
        bu.name as buName,
        (SELECT COUNT(*) FROM ForumReply WHERE topicId = t.id) as totalReplies,
        f.title as forumName
    FROM ForumTopic t
    LEFT JOIN User u ON t.authorId = u.id
    JOIN Forum f ON t.forumId = f.id
    LEFT JOIN Company c ON f.companyId = c.id
    LEFT JOIN BusinessUnit bu ON f.businessUnitId = bu.id
    ORDER BY t.createdAt DESC
";
$topics = $pdo->query($sql)->fetchAll();

function typeBadge($type) {
    if($type === 'QUESTION')       return "<span style='background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-help-circle'></i> Pregunta</span>";
    if($type === 'IMPROVEMENT')    return "<span style='background:#dcfce7;color:#166534;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-bulb'></i> Propuesta</span>";
    if($type === 'CONTRIBUTION')   return "<span style='background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bxs-star'></i> Aporte</span>";
    if($type === 'GOOD_PRACTICE')  return "<span style='background:#fef9c3;color:#854d0e;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bxs-medal'></i> Buena Práctica</span>";
    if($type === 'METHODOLOGY')    return "<span style='background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-brain'></i> Metodología</span>";
    if($type === 'SUITE_QUESTION') return "<span style='background:#fce7f3;color:#be185d;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-desktop'></i> Dudas Suite</span>";
    if($type === 'HUB_QUESTION')   return "<span style='background:#f3e8ff;color:#7e22ce;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'><i class='bx bx-cube-alt'></i> Duda Hub</span>";
    return "<span style='background:#f1f5f9;color:#64748b;padding:2px 8px;border-radius:12px;font-size:0.7rem;font-weight:700;'>".htmlspecialchars($type)."</span>";
}
?>



<!-- Métricas Generales Automáticas -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
    <div class="card" style="display: flex; gap: 1rem; align-items: center;">
        <div style="background: #eef2ff; color: #4f46e5; width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: bold;">
            <?= count($topics) ?>
        </div>
        <div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Total Hilos</div>
            <div style="font-size: 0.9rem; color: #1e293b; font-weight: 600;">En todo el sistema</div>
        </div>
    </div>
    <div class="card" style="display: flex; gap: 1rem; align-items: center;">
        <div style="background: #fef08a; color: #854d0e; width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: bold;">
            <?php echo count(array_filter($topics, fn($t) => $t['isValidatedPractice'] == 1)); ?>
        </div>
        <div>
            <div style="font-size: 0.8rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Buenas Prácticas</div>
            <div style="font-size: 0.9rem; color: #1e293b; font-weight: 600;">Avaladas y Gamificadas</div>
        </div>
    </div>
</div>

<div class="card" style="padding: 0;">
    <div class="table-responsive">
        <table class="data-table table-card-mode">
            <thead style="background: #f8fafc;">
                <tr>
                    <th>Hilo y Clasificación</th>
                    <th>Cliente</th>
                    <th>Autor</th>
                    <th>Estado</th>
                    <th>Tipo (Editar)</th>
                    <th>Fecha</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($topics)): ?>
                    <tr><td colspan="6" style="text-align:center; padding: 2rem;">No hay actividad comunitaria para moderar todavía.</td></tr>
                <?php else: ?>
                    <?php foreach($topics as $t): ?>
                        <tr>
                            <td data-label="Hilo">
                                <div style="font-weight: 700; color: #1e293b; margin-bottom: 0.3rem; font-size: 0.95rem;">
                                    <a href="index.php?view=forum_topic&forum_id=<?php echo urlencode($t['forumId']); ?>&topic_id=<?= urlencode($t['id']) ?>" style="text-decoration: none; color: inherit;" target="_blank">
                                        <?= htmlspecialchars($t['title']) ?>
                                    </a>
                                </div>
                                <?= typeBadge($t['threadType']) ?>
                            </td>
                            <td data-label="Cliente">
                                <div style="font-size: 0.85rem; font-weight: 600; color: #334155;"><?= htmlspecialchars($t['companyName'] ?: 'Organización General') ?></div>
                                <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.1rem;"><?= htmlspecialchars($t['buName'] ?: '') ?></div>
                            </td>
                            <td data-label="Autor">
                                <div style="font-size: 0.85rem; font-weight: 600; color: #1e293b;"><?= htmlspecialchars($t['authorName']) ?></div>
                                <div style="font-size: 0.75rem; color: #64748b;"><?= htmlspecialchars($t['authorRole']) ?></div>
                            </td>
                            <td data-label="Estado">
                                <div style="font-size: 0.8rem; font-weight: 600; color: #475569;">
                                    <i class='bx bx-message-rounded-dots'></i> <?= $t['totalReplies'] ?> Respuestas
                                </div>
                                <?php if($t['isValidatedPractice']): ?>
                                    <div style="font-size: 0.75rem; color: #166534; font-weight: 700; margin-top: 0.2rem;"><i class='bx bx-check-double'></i> Validado Oficial</div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Tipo">
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="change_type">
                                    <input type="hidden" name="topic_id" value="<?= htmlspecialchars($t['id']) ?>">
                                    <select name="new_type" onchange="this.form.submit()" style="padding: 0.25rem; font-size: 0.75rem; border: 1px solid #cbd5e1; border-radius: 4px; background: white; color: #475569; outline: none; cursor: pointer; width: 100%;">
                                        <optgroup label="Modelo nuevo">
                                            <option value="QUESTION"     <?= $t['threadType'] == 'QUESTION'     ? 'selected' : '' ?>>❓ Pregunta</option>
                                            <option value="IMPROVEMENT"  <?= $t['threadType'] == 'IMPROVEMENT'  ? 'selected' : '' ?>>💡 Propuesta</option>
                                            <option value="CONTRIBUTION" <?= $t['threadType'] == 'CONTRIBUTION' ? 'selected' : '' ?>>⭐ Aporte</option>
                                        </optgroup>
                                        <optgroup label="Tipos anteriores">
                                            <option value="GOOD_PRACTICE"  <?= $t['threadType'] == 'GOOD_PRACTICE'  ? 'selected' : '' ?>>Buena Práctica</option>
                                            <option value="METHODOLOGY"    <?= $t['threadType'] == 'METHODOLOGY'    ? 'selected' : '' ?>>Metodología</option>
                                            <option value="SUITE_QUESTION" <?= $t['threadType'] == 'SUITE_QUESTION' ? 'selected' : '' ?>>Duda Suite</option>
                                            <option value="HUB_QUESTION"   <?= $t['threadType'] == 'HUB_QUESTION'   ? 'selected' : '' ?>>Duda Hub</option>
                                            <option value="GENERAL"        <?= $t['threadType'] == 'GENERAL'        ? 'selected' : '' ?>>General</option>
                                        </optgroup>
                                    </select>
                                </form>
                            </td>
                            <td data-label="Fecha">
                                <div style="font-size: 0.8rem; color: #64748b;"><?= date('d/m/Y H:i', strtotime($t['createdAt'])) ?></div>
                            </td>
                            <td style="text-align: right;">
                                <a href="index.php?view=forum_topic&forum_id=<?= urlencode($t['forumId']) ?>&topic_id=<?= urlencode($t['id']) ?>" class="btn" style="padding: 0.25rem 0.6rem; font-size: 0.75rem; background: #e0f2fe; color: #0284c7; border: 1px solid #bae6fd;">Ver Hilo Completo</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
