<?php
/**
 * Student Dashboard V3 — 1:1 Faithful Translation from Next.js/Tailwind
 * Rev 4: Exact port from React source files
 */

require_once 'utils/student_data.php';
ob_start();
try {
$sd = getStudentDashboardData($pdo, (string)($_SESSION['user_id'] ?? ''));
} catch (\Throwable $e) {
ob_end_clean();
echo '<!-- ERR:'.htmlspecialchars($e->getMessage()).'-->';
$sd = ['user'=>[],'totalPoints'=>0,'level'=>1,'nextLevelXP'=>1000,'currentLevelPoints'=>0,'streak'=>0,'activeCourses'=>[],'coursesCompletedCount'=>0,'coursesActiveCount'=>0,'overallProgress'=>0,'leaderboard'=>[],'userRank'=>0,'totalStudents'=>0,'certificates'=>[],'achievements'=>[],'completedAchievements'=>0,'dashboardAchievements'=>[],'recentActivities'=>[],'learningPath'=>['name'=>'','courses'=>[],'progress'=>0,'completedCount'=>0,'totalCount'=>0]];
}
ob_end_clean();

$user = $sd['user'];
$firstName = $user['firstName'] ?? explode(' ', $user['name'] ?? 'Estudiante')[0];
$lastName = $user['lastName'] ?? '';
$displayName = $firstName ? trim($firstName . ' ' . $lastName) : ($user['name'] ?? 'Usuario');
$userImage = $user['image'] ?? '';

// Resolve image URL for local XAMPP
function resolveImageUrl($url) {
    if (empty($url)) return '';
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath !== '/' && strpos($url, '/uploads') === 0) {
        return $basePath . $url;
    }
    return ltrim($url, '/');
}
$userImageResolved = resolveImageUrl($userImage);

// XP & Level (exact formula from ProfileImageCard.tsx)
$level = floor($sd['totalPoints'] / 1000) + 1;
$currentLevelPoints = $sd['totalPoints'] % 1000;
$progressPercent = ($currentLevelPoints / 1000) * 100;
$nextLevelXP = $level * 1000;

$roleLabel = 'Estudiante'; // trainingRoles[0] if exists
$weekDays = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];
$weeklyHours = [0, 0, 0, 0, 0, 0, 0]; // placeholder

// Ranking (exact from RankingCard.tsx)
$rankingUsers = [];
foreach ($sd['leaderboard'] as $i => $u) {
    $rankingUsers[] = [
        'name' => ($u['firstName'] ?? '') ? ($u['firstName'] . ' ' . mb_substr($u['lastName'] ?? '', 0, 1) . '.') : ($u['name'] ?? 'Usuario'),
        'totalPoints' => $u['totalPoints'] ?? 0,
        'position' => $i + 1,
        'isCurrentUser' => $u['id'] === $_SESSION['user_id'],
    ];
}
$currentUserXP = $sd['totalPoints'];
$firstPlaceXP = $rankingUsers[0]['totalPoints'] ?? 0;
$xpGap = $firstPlaceXP - $currentUserXP;

// Learning path
$lp = $sd['learningPath'];
$stmtAchievs = $pdo->query("SELECT id, title, description, icon, imagePath, color FROM Achievement WHERE isActive = 1 ORDER BY createdAt ASC");
$allAchievements = $stmtAchievs->fetchAll(PDO::FETCH_ASSOC);

// Mapear los logros desbloqueados por el usuario
$stmtUnlocked = $pdo->prepare("SELECT achievementId FROM UserAchievement WHERE userId = ?");
$stmtUnlocked->execute([$_SESSION['user_id']]);
$unlockedAchIds = $stmtUnlocked->fetchAll(PDO::FETCH_COLUMN);

// Combinar Logros intrínsecos (Dashboard) con Medallas (Gamificación BDD)
$combinedItems = [];
$logroItems = []; // Logros basados en reglas del sistema
$medallaItems = []; // Medallas reales de la BD
$achievementsData = $sd['dashboardAchievements'];
foreach($achievementsData as $ach) {
    $item = [
        'isMedal' => false,
        'title' => $ach['title'],
        'description' => $ach['subtitle'],
        'icon' => $ach['icon'],
        'color' => $ach['color'] ?? null,
        'imagePath' => null,
        'completed' => $ach['completed'],
        'isRule' => $ach['isRule'] ?? false,
        'timesObtained' => $ach['timesObtained'] ?? 0
    ];
    $combinedItems[] = $item;
    $logroItems[] = $item;
}
foreach($allAchievements as $ach) {
    $item = [
        'isMedal' => true,
        'title' => $ach['title'],
        'description' => $ach['description'],
        'icon' => $ach['icon'],
        'imagePath' => $ach['imagePath'],
        'color' => $ach['color'] ?? null,
        'completed' => in_array($ach['id'], $unlockedAchIds)
    ];
    $combinedItems[] = $item;
    $medallaItems[] = $item;
}

$completedAchCount = count(array_filter($combinedItems, fn($i) => $i['completed']));
$completedLogroCount = count(array_filter($logroItems, fn($i) => $i['completed']));
$completedMedallaCount = count(array_filter($medallaItems, fn($i) => $i['completed']));

// ── Lector Operativo detection ────────────────────────────────────────
$isLectorOp = false;
try {
    $_loStmt = $pdo->prepare(
        "SELECT 1 FROM _TrainingRoleToUser rtu
         JOIN TrainingRole tr ON rtu.A = tr.id
         WHERE rtu.B = ? AND LOWER(tr.name) LIKE '%lector%operativo%' LIMIT 1"
    );
    $_loStmt->execute([$_SESSION['user_id']]);
    $isLectorOp = (bool)$_loStmt->fetchColumn();
} catch (\Throwable $_loE) { $isLectorOp = false; }

// ── Forum stats para Lector Operativo (acotados al foro LO accesible) ───────
$loForumTopics      = 0;
$loForumReplies     = 0;
$loForumLikes       = 0;
$loLastTopicTitle   = null;
$loLastTopicId      = null;
$loLastTopicForumId = null;
$loLastTopicDate    = null;
$loPendingTopicTitle   = null;
$loPendingTopicId      = null;
$loPendingTopicForumId = null;
$loPendingReplies      = 0;
if ($isLectorOp) {
    try {
        // Obtener el ID del foro LO al que tiene acceso este usuario
        // (mismo patrón que forums.php: targetRole = ID del TrainingRole LO)
        $stLORoleId = $pdo->prepare(
            "SELECT id FROM TrainingRole WHERE LOWER(name) LIKE '%lector%operativo%' LIMIT 1"
        );
        $stLORoleId->execute();
        $loRoleId = $stLORoleId->fetchColumn();

        // Foro exclusivo LO (solo el foro con targetRole = ID del rol LO, de su empresa)
        $loForumIds = [];
        if ($loRoleId) {
            $stLoForums = $pdo->prepare(
                "SELECT id FROM Forum
                 WHERE targetRole = ?
                   AND companyId = (SELECT companyId FROM User WHERE id = ? LIMIT 1)"
            );
            $stLoForums->execute([$loRoleId, $_SESSION['user_id']]);
            $loForumIds = $stLoForums->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (!empty($loForumIds)) {
            $ph = implode(',', array_fill(0, count($loForumIds), '?'));

            // Temas publicados en esos foros
            $stFT = $pdo->prepare("SELECT COUNT(*) FROM ForumTopic WHERE authorId = ? AND forumId IN ($ph)");
            $stFT->execute(array_merge([$_SESSION['user_id']], $loForumIds));
            $loForumTopics = (int)$stFT->fetchColumn();

            // Respuestas dadas en esos foros
            $stFR = $pdo->prepare(
                "SELECT COUNT(*) FROM ForumReply WHERE authorId = ?
                 AND topicId IN (SELECT id FROM ForumTopic WHERE forumId IN ($ph))"
            );
            $stFR->execute(array_merge([$_SESSION['user_id']], $loForumIds));
            $loForumReplies = (int)$stFR->fetchColumn();

            // Likes recibidos en esos foros
            $stFL = $pdo->prepare(
                "SELECT COALESCE(SUM(likes),0) FROM ForumTopic WHERE authorId = ? AND forumId IN ($ph)"
            );
            $stFL->execute(array_merge([$_SESSION['user_id']], $loForumIds));
            $loForumLikes = (int)$stFL->fetchColumn();

            // Último tema publicado
            $stLT = $pdo->prepare(
                "SELECT id, title, forumId, createdAt FROM ForumTopic
                 WHERE authorId = ? AND forumId IN ($ph)
                 ORDER BY createdAt DESC LIMIT 1"
            );
            $stLT->execute(array_merge([$_SESSION['user_id']], $loForumIds));
            $loLastTopic = $stLT->fetch();
            if ($loLastTopic) {
                $loLastTopicTitle   = $loLastTopic['title'];
                $loLastTopicId      = $loLastTopic['id'];
                $loLastTopicForumId = $loLastTopic['forumId'];
                $loLastTopicDate    = $loLastTopic['createdAt'];
            }

            // Hilo pendiente de respuesta (último tema propio donde la última reply no es suya)
            $stPT = $pdo->prepare("
                SELECT t.id, t.title, t.forumId,
                       (SELECT COUNT(*) FROM ForumReply WHERE topicId = t.id) AS replies,
                       (SELECT MAX(r2.createdAt) FROM ForumReply r2 WHERE r2.topicId = t.id) AS lastReplyAt,
                       (SELECT r3.authorId FROM ForumReply r3 WHERE r3.topicId = t.id ORDER BY r3.createdAt DESC LIMIT 1) AS lastReplyAuthor
                FROM ForumTopic t
                WHERE t.authorId = ? AND t.forumId IN ($ph)
                  AND EXISTS (SELECT 1 FROM ForumReply r WHERE r.topicId = t.id AND r.authorId != ?)
                HAVING lastReplyAuthor != ?
                ORDER BY lastReplyAt DESC
                LIMIT 1
            ");
            $stPT->execute(array_merge([$_SESSION['user_id']], $loForumIds, [$_SESSION['user_id'], $_SESSION['user_id']]));
            $loPendingRow = $stPT->fetch();
            if ($loPendingRow) {
                $loPendingTopicTitle   = $loPendingRow['title'];
                $loPendingTopicId      = $loPendingRow['id'];
                $loPendingTopicForumId = $loPendingRow['forumId'];
                $loPendingReplies      = (int)$loPendingRow['replies'];
            }
        }
    } catch (\Throwable $e) {}
}
?>

<!-- ═══ WELCOME SECTION (exact from page.tsx:238-284) ═══ -->
<div style="max-width:1920px;margin:0 auto;padding:1rem 1.5rem;">

    <!-- Welcome heading -->
    <div style="margin-bottom:1.5rem;">
        <h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 1rem 0;">
            Bienvenido, <?= htmlspecialchars($firstName ?: 'Estudiante') ?>
        </h1>

        <!-- StatPills + KPI Cards row (flex-row from md) -->
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem 1.5rem;">
            
            <?php if (!$isLectorOp): ?>
            <!-- StatPills -->
            <div style="display:flex;align-items:center;gap:0.75rem;overflow-x:auto;">
                <!-- Cursos Activos: bg-gray-900 text-white -->
                <div style="display:flex;align-items:center;gap:0.75rem;flex-shrink:0;">
                    <div style="background:#111827;color:white;padding:6px 16px;border-radius:999px;font-size:0.875rem;font-weight:600;"><?= count($sd['activeCourses']) ?></div>
                    <span style="font-size:0.875rem;color:#4b5563;white-space:nowrap;">Cursos Activos</span>
                </div>
                <!-- Certificaciones: bg-[#FFD93D] text-gray-900 -->
                <div style="display:flex;align-items:center;gap:0.75rem;flex-shrink:0;">
                    <div style="background:#FFD93D;color:#111827;padding:6px 16px;border-radius:999px;font-size:0.875rem;font-weight:600;"><?= count($sd['certificates']) ?></div>
                    <span style="font-size:0.875rem;color:#4b5563;white-space:nowrap;">Certificaciones</span>
                </div>
                <!-- Progreso General: bg-gray-300 text-gray-700 -->
                <div style="display:flex;align-items:center;gap:0.75rem;flex-shrink:0;">
                    <div style="background:#d1d5db;color:#374151;padding:6px 16px;border-radius:999px;font-size:0.875rem;font-weight:600;"><?= $sd['overallProgress'] ?>%</div>
                    <span style="font-size:0.875rem;color:#4b5563;white-space:nowrap;">Progreso General</span>
                </div>
            </div>
            <?php endif; ?>

            <div style="flex:1;"></div>

            <?php if (!$isLectorOp): ?>
            <!-- KPI Cards -->
            <div style="display:grid;grid-template-columns:repeat(3,auto);gap:0.75rem;">
                <div style="background:white;border-radius:1rem;padding:0.75rem 1rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <i class='bx bxs-hot' style="color:#FF6A00;font-size:1.25rem;"></i>
                        <div>
                            <p style="font-size:1.25rem;font-weight:700;color:#111827;margin:0;line-height:1.2;"><?= $sd['streak'] ?></p>
                            <p style="font-size:0.65rem;color:#6b7280;margin:0;">Días de racha</p>
                        </div>
                    </div>
                </div>
                <div style="background:white;border-radius:1rem;padding:0.75rem 1rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <i class='bx bx-book-open' style="color:#FF6A00;font-size:1.25rem;"></i>
                        <div>
                            <p style="font-size:1.25rem;font-weight:700;color:#111827;margin:0;line-height:1.2;"><?= $sd['coursesCompletedCount'] ?></p>
                            <p style="font-size:0.65rem;color:#6b7280;margin:0;">Cursos Completados</p>
                        </div>
                    </div>
                </div>
                <div style="background:white;border-radius:1rem;padding:0.75rem 1rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <i class='bx bx-medal' style="color:#FF6A00;font-size:1.25rem;"></i>
                        <div>
                            <p style="font-size:1.25rem;font-weight:700;color:#111827;margin:0;line-height:1.2;"><?= $completedAchCount ?></p>
                            <p style="font-size:0.65rem;color:#6b7280;margin:0;">Medallas</p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ═══ BENTO GRID 3-9 layout (exact from page.tsx:287) ═══ -->
    <div style="display:grid;grid-template-columns:3fr 9fr;gap:1.25rem;">

        <!-- === LEFT COLUMN (3 cols) === -->
        <div style="display:flex;flex-direction:column;gap:1.25rem;">

            <!-- ProfileImageCard (exact from ProfileImageCard.tsx) -->
            <div style="height:340px;position:relative;border-radius:1.5rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);
                background:<?= $userImageResolved ? '#e5e7eb' : 'linear-gradient(135deg,#FF6A00,#FFA500)' ?>;">
                
                <?php if ($userImageResolved): ?>
                    <img src="<?= htmlspecialchars($userImageResolved) ?>" alt="<?= htmlspecialchars($displayName) ?>" 
                         style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <!-- fallback: from-gray-200 to-gray-300 with User icon placeholder -->
                    <div style="display:none;width:100%;height:100%;background:linear-gradient(135deg,#e5e7eb,#d1d5db);align-items:center;justify-content:center;">
                        <i class='bx bx-user' style="font-size:6rem;color:rgba(255,255,255,0.4);"></i>
                    </div>
                <?php else: ?>
                    <!-- No image: from-[#FF6A00] to-[#FFA500] with User icon (exact from line 68) -->
                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;">
                        <i class='bx bx-user' style="font-size:6rem;color:rgba(255,255,255,0.4);"></i>
                    </div>
                <?php endif; ?>

                <!-- Hover overlay: "Cambiar Foto" (exact from line 74-82) -->
                <div class="v3-profile-hover" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);opacity:0;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:10;transition:opacity 0.3s;"
                     onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'" onclick="document.getElementById('dashAvatarInput').click()">
                    <div style="background:rgba(255,255,255,0.2);backdrop-filter:blur(12px);color:white;padding:8px 16px;border-radius:12px;font-size:0.875rem;font-weight:700;display:flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,0.3);">
                        <i class='bx bx-camera' style="font-size:1rem;"></i>
                        <span id="dashAvatarBtnText">Cambiar Foto</span>
                    </div>
                </div>
                <input type="file" id="dashAvatarInput" accept="image/jpeg, image/png, image/webp" style="display:none;" onchange="uploadDashAvatar(this)">

                <!-- NIVEL badge (exact: bg-[#FF6A00], rounded-full, border-2 border-white) -->
                <div style="position:absolute;top:16px;left:16px;z-index:20;background:#FF6A00;color:white;padding:4px 12px;border-radius:999px;font-size:0.75rem;font-weight:900;box-shadow:0 4px 6px rgba(0,0,0,0.15);border:2px solid white;display:flex;align-items:center;gap:6px;">
                    <i class='bx bx-trophy' style="font-size:0.75rem;"></i> NIVEL <?= $level ?>
                </div>

                <!-- EDITAR button (exact: bg-white/20 backdrop-blur-md, rounded-lg) -->
                <a href="index.php?view=settings" style="position:absolute;top:16px;right:16px;z-index:20;background:rgba(255,255,255,0.2);backdrop-filter:blur(12px);color:white;padding:6px 12px;border-radius:8px;font-size:0.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,0.3);transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.3)'" onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                    <i class='bx bx-edit-alt' style="font-size:0.75rem;"></i> EDITAR
                </a>

                <!-- Bottom overlay (exact: from-black/80 via-black/50 to-transparent, p-5) -->
                <div style="position:absolute;bottom:0;left:0;right:0;padding:1.25rem;background:linear-gradient(to top,rgba(0,0,0,0.8) 0%,rgba(0,0,0,0.5) 50%,transparent 100%);z-index:15;">
                    <div style="margin-bottom:0.75rem;">
                        <h3 style="color:white;font-weight:700;font-size:1.125rem;margin:0;"><?= htmlspecialchars($displayName) ?></h3>
                        <?php if (!empty($sd['trainingRoles'])): ?>
                            <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;">
                                <?php foreach($sd['trainingRoles'] as $tr): ?>
                                    <span style="background:rgba(255,106,0,0.8);color:white;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:600;border:1px solid rgba(255,255,255,0.2);"><?= htmlspecialchars($tr) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p style="color:rgba(255,255,255,0.8);font-size:0.875rem;margin:2px 0 0;"><?= $roleLabel ?></p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="color:rgba(255,255,255,0.7);font-size:0.75rem;font-weight:600;">Experiencia</span>
                            <span style="color:white;font-size:0.75rem;font-weight:700;"><?= number_format($sd['totalPoints']) ?> XP</span>
                        </div>
                        <div style="width:100%;background:rgba(255,255,255,0.2);border-radius:999px;height:8px;overflow:hidden;margin:6px 0 4px;">
                            <div style="height:100%;width:<?= $progressPercent ?>%;background:#FF6A00;border-radius:999px;box-shadow:0 0 8px rgba(255,106,0,0.5);"></div>
                        </div>
                        <div style="text-align:right;">
                            <span style="color:rgba(255,255,255,0.6);font-size:0.625rem;font-weight:600;"><?= $currentLevelPoints ?> / 1,000 XP</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RankingCard (exact from RankingCard.tsx) -->
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;"><?= $isLectorOp ? 'Ranking · Lectores Operativos' : 'Ranking' ?></h3>
                </div>
                <div style="display:flex;flex-direction:column;gap:0.5rem;">
                    <?php foreach (array_slice($rankingUsers, 0, 3) as $rUser): 
                        // Position badge colors (exact): 1=yellow-400, 2=gray-300, 3=#FF6A00
                        if ($rUser['position'] === 1) { $badgeBg = '#facc15'; }
                        elseif ($rUser['position'] === 2) { $badgeBg = '#d1d5db'; }
                        else { $badgeBg = '#FF6A00'; }
                        
                        // Row highlight for current user (exact: from-orange-50 to-yellow-50, ring-2 ring-[#FF6A00])
                        $rowBg = $rUser['isCurrentUser'] 
                            ? 'background:linear-gradient(to right,#fff7ed,#fefce8);box-shadow:0 0 0 2px #FF6A00;' 
                            : 'background:#f9fafb;';
                    ?>
                    <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;border-radius:12px;transition:all 0.2s;<?= $rowBg ?>">
                        <!-- Position badge: rounded-lg (not circle!) -->
                        <div style="width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;color:white;background:<?= $badgeBg ?>;flex-shrink:0;">
                            <?= $rUser['position'] ?>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <p style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($rUser['name']) ?></p>
                            <p style="font-size:0.75rem;color:#6b7280;margin:0;"><?= number_format($rUser['totalPoints']) ?> XP</p>
                        </div>
                        <?php if ($rUser['position'] === 1): ?>
                            <i class='bx bxs-trophy' style="color:#eab308;font-size:1rem;flex-shrink:0;"></i>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($xpGap > 0): ?>
                <div style="margin-top:1rem;padding:0.75rem;background:linear-gradient(to right,#fff7ed,#fefce8);border-radius:12px;text-align:center;">
                    <p style="font-size:0.75rem;color:#4b5563;margin:0;">A <span style="font-weight:700;color:#FF6A00;"><?= number_format($xpGap) ?> XP</span> del 1er lugar</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actividad Reciente -->
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Actividad Reciente</h3>
                </div>
                <?php if (empty($sd['recentActivities'])): ?>
                    <p style="font-size:0.875rem;color:#9ca3af;text-align:center;padding:1rem 0;">Sin actividad reciente</p>
                <?php else: ?>
                    <div style="display:flex;flex-direction:column;gap:1rem;">
                    <?php foreach ($sd['recentActivities'] as $act): 
                        // Icon config (exact from ActivityCard.tsx)
                        $actBg = '#ecfdf5'; $actColor = '#10b981'; $actIcon = 'bx bx-check-circle';
                        if ($act['type'] === 'achievement') { $actBg = '#fff7ed'; $actColor = '#f97316'; $actIcon = 'bx bxs-hot'; }
                        elseif ($act['type'] === 'certificate') { $actBg = '#faf5ff'; $actColor = '#a855f7'; $actIcon = 'bx bx-award'; }
                        
                        $actLabel = 'Completaste el módulo';
                        if ($act['type'] === 'achievement') $actLabel = 'Desbloqueaste logro';
                        elseif ($act['type'] === 'certificate') $actLabel = 'Obtuviste certificado';
                    ?>
                    <div style="display:flex;gap:0.75rem;">
                        <div style="width:32px;height:32px;border-radius:50%;background:<?= $actBg ?>;color:<?= $actColor ?>;display:flex;align-items:center;justify-content:center;border:2px solid white;box-shadow:0 1px 2px rgba(0,0,0,0.05);flex-shrink:0;">
                            <i class="<?= $actIcon ?>" style="font-size:1rem;"></i>
                        </div>
                        <div style="flex:1;padding-top:2px;">
                            <p style="font-size:0.875rem;font-weight:700;color:#1f2937;margin:0;line-height:1.3;"><?= $actLabel ?></p>
                            <p style="font-size:0.875rem;font-weight:700;color:#FF6A00;margin:2px 0 0;"><?= htmlspecialchars($act['desc']) ?></p>
                            <p style="font-size:0.75rem;color:#9ca3af;margin:4px 0 0;"><?= htmlspecialchars(strtolower($act['time'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Certificaciones (LO) / Logros (NON-LO) -->
            <?php if ($isLectorOp): ?>
            <div style="background:white;border-radius:1.5rem;padding:1rem 1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Certificaciones <?php if(count($sd['certificates'])>0): ?><span style="background:#FF6A00;color:white;font-size:0.6rem;font-weight:800;padding:2px 7px;border-radius:999px;margin-left:4px;"><?= count($sd['certificates']) ?></span><?php endif; ?></h3>
                    <a href="index.php?view=certificates" style="width:24px;height:24px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#4b5563;font-size:0.75rem;">↗</a>
                </div>
                <?php if (empty($sd['certificates'])): ?>
                    <div style="display:flex;align-items:center;gap:0.625rem;padding:0.5rem 0.75rem;background:#f9fafb;border-radius:0.875rem;margin-bottom:0.75rem;">
                        <i class='bx bx-award' style="font-size:1.25rem;color:#d1d5db;flex-shrink:0;"></i>
                        <p style="font-size:0.78rem;color:#9ca3af;margin:0;line-height:1.3;">Completa cursos para obtener certificados</p>
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($sd['certificates'], 0, 1) as $cert): ?>
                    <div style="background:linear-gradient(135deg,#FF6A00,#FFA500);border-radius:1rem;padding:0.75rem;color:white;margin-bottom:0.75rem;">
                        <div style="display:flex;align-items:center;gap:0.625rem;">
                            <div style="width:30px;height:30px;background:rgba(255,255,255,0.2);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class='bx bx-award' style="font-size:1rem;"></i>
                            </div>
                            <div>
                                <h4 style="font-weight:700;font-size:0.8rem;margin:0;"><?= htmlspecialchars($cert['certName'] ?? 'Certificado') ?></h4>
                                <p style="font-size:0.68rem;color:rgba(255,255,255,0.8);margin:2px 0 0;"><?= $cert['issuedAt'] ? date('M Y', strtotime($cert['issuedAt'])) : '' ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <a href="index.php?view=certificates" style="display:block;width:100%;background:#f3f4f6;color:#374151;padding:6px 0;border-radius:10px;font-size:0.8rem;font-weight:600;text-align:center;text-decoration:none;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">Ver certificaciones</a>
            </div>


            <?php else: // non-LO sees Logros ?>
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Logros</h3>
                    <span style="font-size:0.7rem;color:#9ca3af;font-weight:600;"><?= $completedLogroCount ?>/<?= count($logroItems) ?></span>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                    <?php foreach ($logroItems as $logro): ?>
                    <div title="<?= htmlspecialchars($logro['title'] . ' — ' . $logro['description']) ?>" style="display:flex;align-items:center;gap:0.35rem;padding:0.3rem 0.6rem;border-radius:2rem;font-size:0.7rem;font-weight:600;transition:all 0.2s;cursor:default;
                        <?php if ($logro['completed']): ?>
                            background:<?= htmlspecialchars($logro['color'] ?? '#f97316') ?>18;color:<?= htmlspecialchars($logro['color'] ?? '#f97316') ?>;border:1.5px solid <?= htmlspecialchars($logro['color'] ?? '#f97316') ?>35;
                        <?php else: ?>
                            background:#f9fafb;color:#c0c0c0;border:1.5px solid #e5e7eb;
                        <?php endif; ?>">
                        <i class="<?= htmlspecialchars($logro['icon'] ?? 'bx bxs-star') ?>" style="font-size:0.8rem;"></i>
                        <span style="white-space:nowrap;"><?= htmlspecialchars($logro['title']) ?></span>
                        <?php if ($logro['completed'] && ($logro['timesObtained'] ?? 0) > 1): ?>
                            <span style="background:<?= htmlspecialchars($logro['color'] ?? '#f97316') ?>;color:white;font-size:0.5rem;font-weight:800;padding:1px 5px;border-radius:6px;">x<?= $logro['timesObtained'] ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- === RIGHT COLUMN (9 cols) === -->
        <div style="display:flex;flex-direction:column;gap:1.25rem;min-width:0;">

            <!-- Top Row: Racha + Forum card (2 cols, forum spans 2) -->
            <div style="display:grid;grid-template-columns:1fr 2fr;gap:1.25rem;">

                <?php if ($isLectorOp):
                $streakVal = $sd['streak'];
                if ($streakVal >= 30) $sMsg = "¡30+ días! Eres un ejemplo.";
                elseif ($streakVal >= 14) $sMsg = "¡Dos semanas de racha!";
                elseif ($streakVal >= 7)  $sMsg = "¡Una semana entera!";
                elseif ($streakVal >= 3)  $sMsg = "¡{$streakVal} días seguidos!";
                elseif ($streakVal === 1) $sMsg = "¡Primer día! Vuelve mañana.";
                else $sMsg = "Entra hoy y comienza tu racha.";
                ?>
                <div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1.5rem;padding:1.5rem 1.25rem;border:1px solid #374151;position:relative;overflow:hidden;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;">
                    <div style="position:absolute;top:0;left:50%;width:180px;height:180px;background:rgba(255,106,0,0.12);border-radius:50%;transform:translate(-50%,-60%);filter:blur(48px);pointer-events:none;"></div>
                    <div style="position:absolute;bottom:0;left:50%;width:120px;height:120px;background:rgba(234,179,8,0.08);border-radius:50%;transform:translate(-50%,50%);filter:blur(36px);pointer-events:none;"></div>
                    <div style="position:relative;z-index:10;width:100%;">
                        <div style="display:flex;align-items:center;justify-content:center;gap:0.4rem;margin-bottom:0.5rem;">
                            <i class='bx bxs-hot' style="color:#FF6A00;font-size:1rem;"></i>
                            <span style="font-size:0.7rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;">Racha</span>
                        </div>
                        <p style="font-size:4rem;font-weight:800;color:white;margin:0;line-height:1;letter-spacing:-0.03em;"><?= $streakVal ?></p>
                        <p style="font-size:0.75rem;color:#6b7280;margin:6px 0 1rem;"><?= $streakVal===1?'día':'días' ?> seguidos</p>
                        <div style="display:flex;align-items:center;justify-content:center;gap:5px;margin-bottom:1rem;">
                            <?php
                            $todayIdx2 = (int)date('N') - 1;
                            $dayLabels = ['L','M','X','J','V','S','D'];
                            for ($di=0;$di<7;$di++):
                                $isAct = ($di<=$todayIdx2 && ($todayIdx2-$di)<$streakVal);
                                $isToday2 = ($di===$todayIdx2);
                            ?>
                            <div style="display:flex;flex-direction:column;align-items:center;gap:3px;">
                                <div style="width:20px;height:5px;border-radius:999px;background:<?= $isAct?'linear-gradient(to right,#FF6A00,#FFA500)':'rgba(255,255,255,0.1)' ?>;<?= $isToday2?'box-shadow:0 0 6px rgba(255,106,0,0.7);':''  ?>"></div>
                                <span style="font-size:0.55rem;color:<?= $isToday2?'#FF6A00':'#4b5563' ?>;font-weight:<?= $isToday2?'700':'400' ?>"><?= $dayLabels[$di] ?></span>
                            </div>
                            <?php endfor; ?>
                        </div>
                        <div style="background:rgba(255,106,0,0.12);border:1px solid rgba(255,106,0,0.25);border-radius:0.75rem;padding:0.5rem 0.75rem;">
                            <p style="font-size:0.7rem;color:#fb923c;margin:0;"><?= htmlspecialchars($sMsg) ?></p>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- hidden for non-LO — placeholder so grid still works -->
                <div style="display:none;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                        <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Progreso Semanal</h3>
                    </div>
                    
                    <div style="margin-bottom:1rem;">
                        <p style="font-size:1.875rem;font-weight:800;color:#111827;margin:0;letter-spacing:-0.02em;"><?= $sd['weeklyProgress']['thisWeekTotal'] ?> XP</p>
                        <p style="font-size:0.75rem;color:<?= $sd['weeklyProgress']['trendColor'] ?>;font-weight:600;margin:4px 0 0;display:flex;align-items:center;gap:0.25rem;"><i class='bx <?= $sd['weeklyProgress']['trendClass'] ?>'></i> <?= htmlspecialchars($sd['weeklyProgress']['changeText']) ?></p>
                    </div>
                    
                    <div style="display:flex;align-items:flex-end;justify-content:space-between;height:96px;gap:0.5rem;">
                        <?php 
                        $days = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];
                        $heights = $sd['weeklyProgress']['heights'];
                        $rawPts = $sd['weeklyProgress']['rawPoints'];
                        foreach ($days as $i => $d): 
                            $h = max(4, $heights[$i]); // at least 4% so we see a tiny bump
                            $isToday = ((int)date('N') - 1) === $i;
                            $color = $isToday ? 'linear-gradient(to top, #FF6A00, #FFA500)' : '#f3f4f6';
                            $tooltip = "{$rawPts[$i]} XP";
                        ?>
                            <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:0.5rem;height:100%;cursor:help;" title="<?= $tooltip ?>">
                                <!-- Bar container -->
                                <div style="width:100%;flex:1;background:transparent;border-radius:6px;display:flex;align-items:flex-end;justify-content:center;">
                                    <div style="width:100%;max-width:32px;height:<?= $h ?>%;background:<?= $color ?>;border-radius:6px;transition:height 0.5s;"></div>
                                </div>
                                <span style="font-size:0.75rem;color:<?= $isToday ? '#FF6A00' : '#6b7280' ?>;font-weight:<?= $isToday ? '700' : '500' ?>;"><?= $d ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isLectorOp): ?>
                <!-- LO: Forum Interaction Card -->
                <div style="background:white;border-radius:1.5rem;padding:1.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <!-- Header -->
                    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:1.25rem;">
                        <div style="width:32px;height:32px;border-radius:8px;background:#fff7ed;display:flex;align-items:center;justify-content:center;">
                            <i class='bx bx-message-rounded-dots' style="font-size:1.1rem;color:#FF6A00;"></i>
                        </div>
                        <h3 style="font-size:0.875rem;font-weight:700;color:#111827;margin:0;">Mi Actividad en el Foro</h3>
                    </div>
                    <!-- 3 KPIs -->
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;margin-bottom:1.25rem;">
                        <div style="background:#fff7ed;border-radius:1rem;padding:0.875rem;text-align:center;">
                            <p style="font-size:1.875rem;font-weight:800;color:#111827;margin:0;line-height:1;"><?= $loForumTopics ?></p>
                            <p style="font-size:0.7rem;color:#6b7280;margin:5px 0 0;font-weight:600;">Temas publicados</p>
                        </div>
                        <div style="background:#fff7ed;border-radius:1rem;padding:0.875rem;text-align:center;">
                            <p style="font-size:1.875rem;font-weight:800;color:#111827;margin:0;line-height:1;"><?= $loForumReplies ?></p>
                            <p style="font-size:0.7rem;color:#6b7280;margin:5px 0 0;font-weight:600;">Respuestas dadas</p>
                        </div>
                        <div style="background:#fff7ed;border-radius:1rem;padding:0.875rem;text-align:center;">
                            <p style="font-size:1.875rem;font-weight:800;color:#111827;margin:0;line-height:1;"><?= $loForumLikes ?></p>
                            <p style="font-size:0.7rem;color:#6b7280;margin:5px 0 0;font-weight:600;">Likes recibidos</p>
                        </div>
                    </div>
                    <!-- Sección inferior: hilo pendiente + botón nueva discusión -->
                    <div style="display:flex;flex-direction:column;gap:0.625rem;margin-top:0.25rem;">

                        <?php if ($loPendingTopicTitle): ?>
                        <!-- Hilo pendiente de respuesta -->
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.15rem;">
                            <div style="width:6px;height:6px;border-radius:50%;background:#f59e0b;flex-shrink:0;"></div>
                            <span style="font-size:0.68rem;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.05em;">Esperando tu respuesta</span>
                        </div>
                        <a href="index.php?view=forum_topic&forum_id=<?= urlencode($loPendingTopicForumId) ?>&topic_id=<?= urlencode($loPendingTopicId) ?>" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;background:#fffbeb;border:1px solid #fde68a;border-radius:0.875rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.borderColor='#fbbf24';this.style.background='#fef3c7'" onmouseout="this.style.borderColor='#fde68a';this.style.background='#fffbeb'">
                            <div style="width:32px;height:32px;border-radius:8px;background:#fef3c7;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                <i class='bx bx-comment-detail' style="font-size:1rem;color:#d97706;"></i>
                            </div>
                            <div style="min-width:0;flex:1;">
                                <p style="font-size:0.8rem;font-weight:600;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($loPendingTopicTitle) ?></p>
                                <p style="font-size:0.7rem;color:#92400e;margin:2px 0 0;font-weight:600;"><?= $loPendingReplies ?> respuesta<?= $loPendingReplies !== 1 ? 's' : '' ?> · toca para responder</p>
                            </div>
                            <i class='bx bx-chevron-right' style="color:#fbbf24;flex-shrink:0;"></i>
                        </a>
                        <?php elseif ($loLastTopicTitle): ?>
                        <!-- Sin pendientes: mostrar último hilo -->
                        <a href="index.php?view=forum_topic&forum_id=<?= urlencode($loLastTopicForumId) ?>&topic_id=<?= urlencode($loLastTopicId) ?>" style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 1rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:0.875rem;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.borderColor='#fed7aa';this.style.background='#fff7ed'" onmouseout="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
                            <i class='bx bx-time-five' style="color:#9ca3af;font-size:1rem;flex-shrink:0;"></i>
                            <div style="min-width:0;flex:1;">
                                <p style="font-size:0.8rem;font-weight:600;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($loLastTopicTitle) ?></p>
                                <p style="font-size:0.7rem;color:#9ca3af;margin:2px 0 0;">Último tema &middot; <?= $loLastTopicDate ? date('d/m/Y', strtotime($loLastTopicDate)) : '' ?></p>
                            </div>
                            <i class='bx bx-chevron-right' style="color:#d1d5db;flex-shrink:0;"></i>
                        </a>
                        <?php endif; ?>

                        <!-- Botón Nueva Discusión -->
                        <a href="index.php?view=forums" onclick="setTimeout(()=>document.getElementById('modalCT')?.classList.add('active'),300);return true;"
                           style="display:flex;align-items:center;justify-content:center;gap:0.5rem;padding:0.65rem 1rem;background:linear-gradient(135deg,#FF6A00,#FFA500);color:white;font-weight:700;font-size:0.8rem;border-radius:0.875rem;text-decoration:none;transition:all 0.2s;margin-top:0.125rem;"
                           onmouseover="this.style.transform='translateY(-1px)';this.style.boxShadow='0 4px 12px rgba(255,106,0,0.35)'"
                           onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='none'">
                            <i class='bx bx-edit-alt' style="font-size:1rem;"></i> Nueva discusión
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <!-- ExperienceCard -->
                <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                        <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Experiencia</h3>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <i class='bx bxs-bolt-circle' style="color:#FF6A00;font-size:1.25rem;"></i>
                            <div>
                                <p style="font-size:1.5rem;font-weight:700;color:#111827;margin:0;"><?= number_format($sd['totalPoints']) ?></p>
                                <p style="font-size:0.75rem;color:#6b7280;margin:0;">Total XP</p>
                            </div>
                        </div>
                        <div style="background:#fff7ed;padding:6px 12px;border-radius:999px;">
                            <p style="font-size:0.75rem;font-weight:600;color:#FF6A00;margin:0;">Nivel <?= $level ?></p>
                        </div>
                    </div>
                    <div style="margin-top:1rem;">
                        <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#6b7280;margin-bottom:8px;">
                            <span><?= number_format($sd['totalPoints']) ?> XP</span>
                            <span><?= number_format($nextLevelXP) ?> XP</span>
                        </div>
                        <div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
                            <div style="height:100%;width:<?= min($progressPercent, 100) ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!$isLectorOp): ?>
                <!-- CertificatesCard — solo para NON-LO en top row -->
                <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                        <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Certificaciones</h3>
                        <a href="index.php?view=certificates" style="width:24px;height:24px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#4b5563;font-size:0.75rem;">↗</a>
                    </div>
                    <?php if (empty($sd['certificates'])): ?>
                        <div style="text-align:center;padding:1rem 0;">
                            <i class='bx bx-award' style="font-size:2rem;color:#d1d5db;display:block;margin:0 auto 8px;"></i>
                            <p style="font-size:0.875rem;color:#9ca3af;margin:0;">Completa cursos para obtener certificados</p>
                        </div>
                    <?php else: ?>
                        <?php foreach (array_slice($sd['certificates'], 0, 1) as $cert): ?>
                        <div style="background:linear-gradient(135deg,#FF6A00,#FFA500);border-radius:1rem;padding:1rem;color:white;margin-bottom:0.75rem;">
                            <div style="display:flex;align-items:flex-start;gap:0.75rem;margin-bottom:0.75rem;">
                                <div style="width:40px;height:40px;background:rgba(255,255,255,0.2);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class='bx bx-award' style="font-size:1.25rem;"></i>
                                </div>
                                <div>
                                    <h4 style="font-weight:700;font-size:0.875rem;margin:0;"><?= htmlspecialchars($cert['certName'] ?? 'Certificado') ?></h4>
                                    <p style="font-size:0.75rem;color:rgba(255,255,255,0.8);margin:4px 0 0;"><?= $cert['issuedAt'] ? date('M Y', strtotime($cert['issuedAt'])) : '' ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <a href="index.php?view=certificates" style="display:block;width:100%;background:#f3f4f6;color:#374151;padding:8px 0;border-radius:12px;font-size:0.875rem;font-weight:600;text-align:center;text-decoration:none;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">Ver todas las certificaciones</a>
                </div>
                <?php endif; // !$isLectorOp in top row ?>
            </div><!-- end top row grid -->

            <!-- CoursesCard (exact from CoursesCard.tsx: grid-cols-2, image fallback) -->
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Mis Cursos Activos</h3>
                    <a href="index.php?view=courses" style="width:24px;height:24px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;text-decoration:none;color:#4b5563;font-size:0.75rem;">↗</a>
                </div>
                <?php if (empty($sd['activeCourses'])): ?>
                <div style="text-align:center;padding:2rem 0;">
                    <div style="width:64px;height:64px;background:#fff7ed;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem;">
                        <?php if ($sd['coursesCompletedCount'] > 0): ?>
                            <i class='bx bx-party' style="font-size:2rem;color:#10b981;"></i>
                        <?php else: ?>
                            <i class='bx bx-play-circle' style="font-size:2rem;color:#FF6A00;"></i>
                        <?php endif; ?>
                    </div>
                    <p style="font-size:0.875rem;color:#6b7280;margin:0;">
                        <?php if ($sd['coursesCompletedCount'] > 0): ?>
                            <strong style="color:#10b981;display:block;margin-bottom:4px;">¡Felicidades!</strong>
                            Estás al día con todos tus cursos asignados
                        <?php else: ?>
                            Aún no tienes cursos activos
                        <?php endif; ?>
                    </p>
                </div>
                <?php else: ?>
                <div class="v3-horizontal-scroll">
                    <?php foreach (array_slice($sd['activeCourses'], 0, 8) as $course): 
                        $courseImg = resolveImageUrl($course['imageUrl'] ?? '');
                    ?>
                    <a href="index.php?view=lesson&course_id=<?= urlencode($course['id']) ?>" style="text-decoration:none;color:inherit;" class="v3-course-card">
                        <!-- Image: h-32, rounded-2xl, bg-gradient from-orange-100 to-yellow-100 -->
                        <div style="position:relative;height:128px;border-radius:1rem;overflow:hidden;margin-bottom:0.75rem;background:linear-gradient(135deg,#ffedd5,#fefce8);">
                            <?php if ($courseImg): ?>
                                <img src="<?= htmlspecialchars($courseImg) ?>" alt="<?= htmlspecialchars($course['title']) ?>" 
                                     style="width:100%;height:100%;object-fit:cover;transition:transform 0.5s;"
                                     onerror="this.style.display='none'">
                            <?php else: ?>
                                <!-- Fallback: from-[#FF6A00]/20 to-[#FFA500]/20 with PlayCircle -->
                                <div style="width:100%;height:100%;background:linear-gradient(135deg,rgba(255,106,0,0.2),rgba(255,165,0,0.2));display:flex;align-items:center;justify-content:center;">
                                    <i class='bx bx-play-circle' style="font-size:2.5rem;color:rgba(255,106,0,0.4);"></i>
                                </div>
                            <?php endif; ?>
                            <!-- Dark gradient overlay on image -->
                            <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.5),transparent);"></div>
                            <!-- Play button (opacity-0, visible on hover) -->
                            <div class="v3-play-overlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:40px;height:40px;background:rgba(255,255,255,0.3);backdrop-filter:blur(12px);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.3s;">
                                <i class='bx bx-play-circle' style="font-size:1.25rem;color:white;"></i>
                            </div>
                        </div>
                        <!-- Title -->
                        <h4 style="font-weight:600;color:#111827;font-size:0.875rem;margin:0 0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($course['title']) ?></h4>
                        <!-- Progress bar -->
                        <div style="display:flex;align-items:center;gap:8px;">
                            <div style="flex:1;height:6px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
                                <div style="height:100%;width:<?= $course['progress'] ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div>
                            </div>
                            <span style="font-size:0.75rem;font-weight:600;color:#FF6A00;"><?= $course['progress'] ?>%</span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- LearningPathCard -->
            <?php if (!empty($lp['name']) && !$isLectorOp): ?>
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Ruta de Aprendizaje</h3>
                </div>
                <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $lp['progress'] ?>%</p>
                
                <!-- Segments: progress bars for each individual course in the learning path -->
                <div style="display:flex;gap:8px;margin:1rem 0;">
                    <?php foreach ($lp['courses'] as $segIdx => $pCourse): 
                        $pct = $pCourse['progress'] ?? 0;
                        $segColor = '#FFD93D';
                    ?>
                        <div style="flex:1;height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden;">
                            <div style="height:100%;width:<?= $pct ?>%;background:<?= $segColor ?>;border-radius:999px;transition:width 0.5s ease-out;"></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Path name + count -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <p style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($lp['name']) ?></p>
                    <p style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;"><?= $lp['completedCount'] ?>/<?= $lp['totalCount'] ?></p>
                </div>

                <!-- Horizontal list (exact: bg-gray-900 rounded-2xl p-4 with flex-row scroll) -->
                <div style="background:#111827;border-radius:1rem;padding:1rem;display:flex;gap:0.75rem;overflow-x:auto;scrollbar-width:thin;">
                    <?php foreach ($lp['courses'] as $idx => $pathCourse): 
                        $isLocked = !empty($pathCourse['isLocked']);
                        $clickUrl = $isLocked ? '#' : 'index.php?view=lesson&course_id='.urlencode($pathCourse['id'] ?? '');
                        $cursorStyle = $isLocked ? 'cursor:not-allowed; opacity:0.6;' : 'cursor:pointer;';
                    ?>
                    <a href="<?= $clickUrl ?>" style="text-decoration:none; display:flex;align-items:center;min-width:260px;max-width:320px;flex-shrink:0;gap:0.75rem;padding:10px 12px;border-radius:12px;transition:background 0.2s;border:1px solid #374151;background:transparent;<?= $cursorStyle ?>" <?= !$isLocked ? "onmouseover=\"this.style.background='#1f2937'\" onmouseout=\"this.style.background='transparent'\"" : "" ?>>
                        <!-- Circle: completed=bg-[#FFD93D], else bg-gray-700 with border circle -->
                        <div style="width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;
                            <?= $pathCourse['completed'] ? 'background:#FFD93D;' : ($isLocked ? 'background:#1f2937;' : 'background:#374151;') ?>">
                            <?php if ($pathCourse['completed']): ?>
                                <i class='bx bx-check-circle' style="font-size:0.875rem;color:#111827;"></i>
                            <?php elseif ($isLocked): ?>
                                <i class='bx bxs-lock-alt' style="font-size:0.875rem;color:#6b7280;"></i>
                            <?php else: ?>
                                <div style="width:16px;height:16px;border:2px solid #6b7280;border-radius:50%;"></div>
                            <?php endif; ?>
                        </div>
                        <div style="flex:1;min-width:0;text-align:left;">
                            <p style="font-size:0.875rem;color:white;font-weight:600;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($pathCourse['title']) ?></p>
                            <?php if ($pathCourse['subtitle']): ?>
                                <p style="font-size:0.75rem;color:#9ca3af;margin:2px 0 0;"><?= $isLocked ? 'Bloqueado' : $pathCourse['subtitle'] . ' &bull; ' . ($pathCourse['progress'] ?? 0) . '%' ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($pathCourse['completed']): ?>
                            <div style="width:8px;height:8px;background:#FFD93D;border-radius:50%;flex-shrink:0;"></div>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Medallas/Logros bottom row -->
            <?php if ($isLectorOp):
                $loMedalEarned = array_values(array_filter($medallaItems, fn($m) => $m['completed']));
                $loMedalLocked = array_values(array_filter($medallaItems, fn($m) => !$m['completed']));
                $loMedalShow  = array_slice(array_merge($loMedalEarned, array_slice($loMedalLocked,0,max(0,6-count($loMedalEarned)))),0,6);
                $loEarned2    = array_values(array_filter($logroItems, fn($l) => $l['completed']));
                $loPending2   = count($logroItems) - count($loEarned2);
                $loShowcase2  = array_slice($loEarned2, 0, 3);
                $cLO = ['bg-indigo-500'=>'linear-gradient(135deg,#6366f1,#818cf8)','bg-rose-500'=>'linear-gradient(135deg,#f43f5e,#fb7185)','bg-sky-500'=>'linear-gradient(135deg,#0ea5e9,#38bdf8)','bg-yellow-500'=>'linear-gradient(135deg,#eab308,#facc15)','bg-amber-500'=>'linear-gradient(135deg,#f59e0b,#fbbf24)','bg-green-500'=>'linear-gradient(135deg,#22c55e,#4ade80)','bg-purple-500'=>'linear-gradient(135deg,#a855f7,#c084fc)','bg-orange-500'=>'linear-gradient(135deg,#f97316,#fb923c)','bg-red-500'=>'linear-gradient(135deg,#ef4444,#f87171)','bg-teal-500'=>'linear-gradient(135deg,#14b8a6,#2dd4bf)','bg-blue-500'=>'linear-gradient(135deg,#3b82f6,#60a5fa)','bg-pink-500'=>'linear-gradient(135deg,#ec4899,#f472b6)'];
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
            <!-- Medallas Card -->
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Medallas</h3>
                </div>
                <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:0.75rem;">
                <?php foreach ($loMedalShow as $mItem):
                    $mRaw = $mItem['color'] ?? '';
                    $mColor = $cLO[$mRaw] ?? (strpos($mRaw,'#')===0||strpos($mRaw,'linear')===0 ? $mRaw : 'linear-gradient(135deg,#FF6A00,#FFA500)');
                    $mImg = $mItem['completed'] ? ($mItem['imagePath'] ?? '') : '';
                    $mEscT = htmlspecialchars(addcslashes((string)$mItem['title'],"'\r\n\\\""),ENT_QUOTES);
                    $mEscD = htmlspecialchars(addcslashes((string)$mItem['description'],"'\r\n\\\""),ENT_QUOTES);
                    $mEscIc = htmlspecialchars(addcslashes((string)$mItem['icon'],"'\r\n\\\""),ENT_QUOTES);
                ?>
                <?php if ($mItem['completed']): ?>
                <div onclick="window.openMedalViewer('<?= $mEscT ?>','<?= $mEscD ?>','<?= $mEscIc ?>','<?= $mEscIc ?>')" style="padding:0.875rem 0.5rem;border-radius:1rem;text-align:center;position:relative;background:<?= $mColor ?>;box-shadow:0 4px 12px rgba(0,0,0,0.12);cursor:pointer;transition:all 0.25s;" onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 20px rgba(0,0,0,0.18)'" onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 12px rgba(0,0,0,0.12)'">
                    <div style="position:absolute;top:-4px;right:-4px;width:18px;height:18px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2;"><span style="color:white;font-size:0.6rem;font-weight:bold;">✓</span></div>
                    <div style="height:44px;display:flex;align-items:center;justify-content:center;margin-bottom:6px;">
                        <?php if (!empty($mImg)): ?>
                        <img src="<?= htmlspecialchars(ltrim($mImg,'/')) ?>" style="width:36px;height:36px;object-fit:cover;border-radius:50%;border:2px solid rgba(255,255,255,0.5);" alt="">
                        <?php else: ?>
                        <i class="<?= htmlspecialchars($mItem['icon']??'bx bxs-medal') ?>" style="font-size:1.75rem;color:white;text-shadow:0 2px 6px rgba(0,0,0,0.2);"></i>
                        <?php endif; ?>
                    </div>
                    <h4 style="font-weight:700;font-size:0.72rem;color:white;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($mItem['title']) ?></h4>
                </div>
                <?php else: ?>
                <div style="padding:0.875rem 0.5rem;border-radius:1rem;text-align:center;background:#f3f4f6;cursor:default;">
                    <div style="height:44px;display:flex;align-items:center;justify-content:center;margin-bottom:4px;opacity:0.2;">
                        <i class="<?= htmlspecialchars($mItem['icon']??'bx bxs-medal') ?>" style="font-size:1.75rem;color:#6b7280;"></i>
                    </div>
                    <div style="width:14px;height:14px;background:#d1d5db;border-radius:50%;margin:0 auto 5px;display:flex;align-items:center;justify-content:center;"><i class='bx bxs-lock-alt' style="font-size:0.5rem;color:#9ca3af;"></i></div>
                    <h4 style="font-weight:600;font-size:0.65rem;color:#9ca3af;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($mItem['title']) ?></h4>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
                </div>
                <?php if (count($medallaItems) > 6): ?>
                <div style="text-align:right;margin-top:0.75rem;"><a href="index.php?view=achievements" style="font-size:0.75rem;color:#FF6A00;font-weight:600;text-decoration:none;">Ver todas (<?= count($medallaItems) ?>) →</a></div>
                <?php endif; ?>
                <div style="margin-top:1rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.7rem;color:#6b7280;margin-bottom:5px;"><span>Colección</span><span style="font-weight:700;color:#FF6A00;"><?= $completedMedallaCount ?>/<?= count($medallaItems) ?></span></div>
                    <div style="height:5px;background:#f3f4f6;border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?= count($medallaItems)>0?round(($completedMedallaCount/count($medallaItems))*100):0 ?>%;background:linear-gradient(90deg,#FF6A00,#FFA500);border-radius:999px;transition:width 1s;"></div></div>
                </div>
            </div>
            <!-- Logros Card (right) — Propuesta A: Próximo Logro -->
            <?php
                $loLastEarned = !empty($loEarned2) ? $loEarned2[count($loEarned2)-1] : null;
                $loNextPending = null;
                foreach ($logroItems as $lItem) {
                    if (!$lItem['completed']) { $loNextPending = $lItem; break; }
                }
                $loProgressPct = count($logroItems) > 0 ? round((count($loEarned2)/count($logroItems))*100) : 0;
            ?>
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;display:flex;flex-direction:column;justify-content:space-between;">
                <!-- Header -->
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Logros</h3>
                    <span style="font-size:0.7rem;color:#9ca3af;font-weight:600;"><?= count($loEarned2) ?>/<?= count($logroItems) ?></span>
                </div>

                <?php if ($loLastEarned === null): ?>
                <!-- Empty state: show first logro to unlock -->
                <?php $loFirst = $logroItems[0] ?? null; $loFirstColor = $loFirst['color'] ?? '#FF6A00'; if (strpos($loFirstColor,'#')!==0 && strpos($loFirstColor,'linear')!==0) $loFirstColor='#FF6A00'; ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:0.5rem 0;">
                    <div style="width:80px;height:80px;border-radius:50%;background:<?= htmlspecialchars($loFirstColor) ?>12;border:2px dashed <?= htmlspecialchars($loFirstColor) ?>50;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem;animation:logroPulse 2s ease-in-out infinite;">
                        <i class="<?= htmlspecialchars($loFirst['icon'] ?? 'bx bxs-star') ?>" style="font-size:2.2rem;color:<?= htmlspecialchars($loFirstColor) ?>;opacity:0.5;"></i>
                    </div>
                    <p style="font-size:0.72rem;font-weight:700;color:#374151;margin:0 0 4px;"><?= htmlspecialchars($loFirst['title'] ?? 'Primer Logro') ?></p>
                    <p style="font-size:0.65rem;color:#9ca3af;margin:0 0 1rem;line-height:1.4;"><?= htmlspecialchars(mb_strimwidth($loFirst['description'] ?? '', 0, 55, '...')) ?></p>
                    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:0.75rem;padding:0.4rem 0.75rem;">
                        <p style="font-size:0.65rem;color:#ea580c;margin:0;font-weight:600;">¡Completa actividades para ganar tu primer logro!</p>
                    </div>
                </div>

                <?php else: ?>
                <!-- Logro más reciente ganado — protagonista -->
                <?php
                    $leFeat = $loLastEarned;
                    $leFeatColor = $leFeat['color'] ?? '#FF6A00';
                    if (strpos($leFeatColor,'#')!==0 && strpos($leFeatColor,'linear')!==0) $leFeatColor='#FF6A00';
                    $leFeatGrad = strpos($leFeatColor,'linear')===0 ? $leFeatColor : "linear-gradient(135deg,{$leFeatColor},{$leFeatColor}cc)";
                ?>
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;text-align:center;margin-bottom:1rem;">
                    <!-- Big icon -->
                    <div style="width:88px;height:88px;border-radius:50%;background:<?= $leFeatGrad ?>;box-shadow:0 8px 24px <?= htmlspecialchars($leFeatColor) ?>40;display:flex;align-items:center;justify-content:center;margin:0 auto 0.75rem;position:relative;transition:transform 0.3s;" onmouseover="this.style.transform='scale(1.06)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="<?= htmlspecialchars($leFeat['icon'] ?? 'bx bxs-star') ?>" style="font-size:2.5rem;color:white;text-shadow:0 2px 8px rgba(0,0,0,0.2);"></i>
                        <div style="position:absolute;bottom:-2px;right:-2px;width:26px;height:26px;background:#22c55e;border-radius:50%;border:3px solid white;display:flex;align-items:center;justify-content:center;">
                            <span style="color:white;font-size:0.7rem;font-weight:bold;">✓</span>
                        </div>
                    </div>
                    <p style="font-size:0.65rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 4px;">Último logro</p>
                    <p style="font-size:0.9rem;font-weight:800;color:#111827;margin:0 0 4px;line-height:1.2;"><?= htmlspecialchars($leFeat['title']) ?></p>
                    <p style="font-size:0.65rem;color:#6b7280;margin:0;line-height:1.4;"><?= htmlspecialchars(mb_strimwidth($leFeat['description'] ?? '', 0, 55, '...')) ?></p>
                </div>

                <?php if ($loNextPending !== null): ?>
                <!-- Próximo logro por desbloquear -->
                <?php
                    $loNpColor = $loNextPending['color'] ?? '#6b7280';
                    if (strpos($loNpColor,'#')!==0 && strpos($loNpColor,'linear')!==0) $loNpColor='#6b7280';
                ?>
                <div style="background:#f9fafb;border-radius:1rem;padding:0.65rem 0.875rem;display:flex;align-items:center;gap:0.625rem;margin-bottom:0.875rem;">
                    <div style="width:34px;height:34px;border-radius:50%;background:#f3f4f6;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <i class="<?= htmlspecialchars($loNextPending['icon'] ?? 'bx bxs-lock-alt') ?>" style="font-size:1rem;color:#9ca3af;"></i>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <p style="font-size:0.6rem;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;margin:0;">Próximo</p>
                        <p style="font-size:0.75rem;font-weight:700;color:#374151;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($loNextPending['title']) ?></p>
                    </div>
                    <i class='bx bxs-lock-alt' style="font-size:0.875rem;color:#d1d5db;flex-shrink:0;"></i>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Progress bar -->
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:0.65rem;color:#9ca3af;margin-bottom:5px;font-weight:600;">
                        <span>Progreso</span>
                        <span style="color:#FF6A00;"><?= $loProgressPct ?>%</span>
                    </div>
                    <div style="height:6px;background:#f3f4f6;border-radius:999px;overflow:hidden;">
                        <div style="height:100%;width:<?= $loProgressPct ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;transition:width 1s ease-in-out;"></div>
                    </div>
                </div>
            </div>
            </div><!-- /lo-grid-5050 -->
            <?php else: ?>
            <!-- AchievementsCompact (Standard Medallas) -->
            <div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;min-width:0;width:100%;box-sizing:border-box;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
                    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Medallas</h3>
                </div>
                <!-- Contenedor grid para ver múltiples filas sin forzar scroll -->
                <div style="display:grid;grid-template-columns:repeat(auto-fill, minmax(140px, 1fr));gap:0.75rem;padding-top:0.5rem;padding-bottom:0.5rem;">
                    <?php 
                    $colorResolver = [
                        'bg-indigo-500' => 'linear-gradient(135deg, #6366f1, #818cf8)',
                        'bg-rose-500' => 'linear-gradient(135deg, #f43f5e, #fb7185)',
                        'bg-sky-500' => 'linear-gradient(135deg, #0ea5e9, #38bdf8)',
                        'bg-yellow-500' => 'linear-gradient(135deg, #eab308, #facc15)',
                        'bg-amber-500' => 'linear-gradient(135deg, #f59e0b, #fbbf24)',
                        'bg-green-500' => 'linear-gradient(135deg, #22c55e, #4ade80)',
                        'bg-purple-500' => 'linear-gradient(135deg, #a855f7, #c084fc)',
                        'bg-orange-500' => 'linear-gradient(135deg, #f97316, #fb923c)',
                        'bg-red-500' => 'linear-gradient(135deg, #ef4444, #f87171)',
                        'bg-teal-500' => 'linear-gradient(135deg, #14b8a6, #2dd4bf)',
                        'bg-blue-500' => 'linear-gradient(135deg, #3b82f6, #60a5fa)',
                        'bg-pink-500' => 'linear-gradient(135deg, #ec4899, #f472b6)',
                    ];
                    foreach ($medallaItems as $item): 
                        $displayImagePath = $item['completed'] ? ($item['imagePath'] ?? '') : '';
                        $escTitle = htmlspecialchars(addcslashes((string)$item['title'], "'\r\n\\\""), ENT_QUOTES);
                        $escDesc = htmlspecialchars(addcslashes((string)$item['description'], "'\r\n\\\""), ENT_QUOTES);
                        $escImg = htmlspecialchars(addcslashes((string)$displayImagePath, "'\r\n\\\""), ENT_QUOTES);
                        $escIcon = htmlspecialchars(addcslashes((string)$item['icon'], "'\r\n\\\""), ENT_QUOTES);
                        $rawColor = $item['color'] ?? '';
                        $resolvedColor = $colorResolver[$rawColor] ?? (strpos($rawColor,'#')===0||strpos($rawColor,'linear')===0 ? $rawColor : 'linear-gradient(135deg,#FF6A00,#FFA500)');
                    ?>
                    <div <?= $item['completed'] ? "onclick=\"window.openMedalViewer('{$escTitle}', '{$escDesc}', '{$escImg}', '{$escIcon}')\"" : "" ?> style="padding:0.75rem 0.5rem;border-radius:1rem;text-align:center;position:relative;transition:all 0.3s ease; cursor:<?= $item['completed'] ? 'pointer' : 'default' ?>;
                        <?php if ($item['completed']): ?>
                            background:<?= $resolvedColor ?>;box-shadow:0 4px 15px rgba(0,0,0,0.15);
                        <?php else: ?>
                            background:#f3f4f6;opacity:0.5;filter:grayscale(100%);
                        <?php endif; ?>"
                        <?= $item['completed'] ? "onmouseover=\"this.style.transform='translateY(-4px) scale(1.03)';this.style.boxShadow='0 8px 25px rgba(0,0,0,0.2)'\" onmouseout=\"this.style.transform='translateY(0) scale(1)';this.style.boxShadow='0 4px 15px rgba(0,0,0,0.15)'\"" : "" ?>>
                        <?php if ($item['completed']): ?>
                            <div style="position:absolute;top:-4px;right:-4px;width:20px;height:20px;background:#22c55e;border-radius:50%;display:flex;align-items:center;justify-content:center;z-index:2;box-shadow:0 2px 6px rgba(34,197,94,0.4);">
                                <span style="color:white;font-size:0.75rem;font-weight:bold;">✓</span>
                            </div>
                        <?php endif; ?>
                        <div style="display:flex;justify-content:center;margin-bottom:8px;height:40px;align-items:center;">
                            <?php if(!empty($displayImagePath)): ?>
                                <img src="<?= htmlspecialchars(ltrim($displayImagePath, '/')) ?>" alt="Medalla" style="width:40px;height:40px;object-fit:cover;border-radius:50%;filter:drop-shadow(0 4px 8px rgba(0,0,0,0.3));border:2px solid rgba(255,255,255,0.6);transition:transform 0.3s;" onmouseover="this.style.transform='scale(1.15)'" onmouseout="this.style.transform='scale(1)'">
                            <?php else: ?>
                                <i class="<?= htmlspecialchars($item['icon'] ?? 'bx bxs-medal') ?>" style="font-size:1.75rem;<?= $item['completed'] ? 'color:white;text-shadow:0 2px 8px rgba(0,0,0,0.25);' : 'color:#b0b0b0;' ?>"></i>
                            <?php endif; ?>
                        </div>
                        <h4 style="font-weight:700;font-size:0.75rem;margin:0 0 4px;line-height:1.2;<?= $item['completed'] ? 'color:white;text-shadow:0 1px 3px rgba(0,0,0,0.15);' : 'color:#9ca3af;' ?>"><?= htmlspecialchars($item['title']) ?></h4>
                        <p style="font-size:0.65rem;margin:0;line-height:1.2;<?= $item['completed'] ? 'color:rgba(255,255,255,0.85);' : 'color:#c0c0c0;' ?>"><?= htmlspecialchars(mb_strimwidth($item['description'], 0, 45, "...")) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <!-- Barra de Progreso General -->
                <div style="margin-top:1.25rem;">
                    <div style="display:flex;align-items:center;justify-content:space-between;font-size:0.75rem;color:#4b5563;margin-bottom:8px;font-weight:500;">
                        <span>Progreso de Colección Completa</span>
                        <span style="font-weight:800;color:#FF6A00;font-size:0.85rem;"><?= $completedMedallaCount ?> de <?= count($medallaItems) ?></span>
                    </div>
                    <div style="height:8px;background:#f3f4f6;border-radius:999px;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,0.05);">
                        <div style="height:100%;width:<?= count($medallaItems) > 0 ? round(($completedMedallaCount / count($medallaItems)) * 100) : 0 ?>%;background:linear-gradient(90deg,#FF6A00,#FFA500);border-radius:999px;transition:width 1s ease-in-out;"></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<style>
.v3-course-card:hover .v3-play-overlay { opacity: 1 !important; }
.v3-course-card:hover img { transform: scale(1.1); }

@keyframes logroPulse {
    0%, 100% { transform: scale(1); opacity: 0.9; }
    50% { transform: scale(1.05); opacity: 1; }
}

/* Horizontal scrolling for active courses */
.v3-horizontal-scroll {
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 1rem;
    padding-bottom: 0.75rem;
    scroll-snap-type: x mandatory;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.v3-horizontal-scroll::-webkit-scrollbar {
    height: 6px;
}
.v3-horizontal-scroll::-webkit-scrollbar-track {
    background: transparent;
}
.v3-horizontal-scroll::-webkit-scrollbar-thumb {
    background: #e5e7eb;
    border-radius: 4px;
}
.v3-horizontal-scroll::-webkit-scrollbar-thumb:hover {
    background: #d1d5db;
}
.v3-horizontal-scroll > a {
    flex: 0 0 calc(25% - 0.75rem); /* Modificado para acomodar 4 cursos en una sola vista sin scroll if possible */
    scroll-snap-align: start;
    white-space: normal;
    display: flex;
    flex-direction: column;
}
</style>

<!-- Modal Visor de Medalla para el Estudiante -->
<div id="medalViewerModal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(17,24,39,0.7);z-index:9999;display:none;align-items:center;justify-content:center;opacity:0;transition:opacity 0.2s;">
    <div style="background:white;border-radius:1.5rem;padding:3rem 2.5rem;width:95%;max-width:480px;text-align:center;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);position:relative;transform:scale(0.95);transition:transform 0.2s;" id="medalViewerContent">
        <button onclick="closeMedalViewer()" style="position:absolute;top:1rem;right:1rem;background:#f3f4f6;border:none;border-radius:50%;width:36px;height:36px;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:#6b7280;cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">&times;</button>
        <div id="medalViewerImage" style="width:220px;height:220px;margin:0 auto 2rem;display:flex;align-items:center;justify-content:center;position:relative;">
            <!-- Dinámico -->
        </div>
        <h3 id="medalViewerTitle" style="font-size:1.5rem;font-weight:800;color:#111827;margin-bottom:0.75rem;">Título Medalla</h3>
        <p id="medalViewerDesc" style="font-size:0.95rem;color:#4b5563;line-height:1.5;margin-bottom:0;">Descripción detallada.</p>
    </div>
</div>

<script>
window.openMedalViewer = function(title, desc, imagePath, icon) {
    document.getElementById('medalViewerTitle').textContent = title;
    document.getElementById('medalViewerDesc').textContent = desc;
    
    let imgContainer = document.getElementById('medalViewerImage');
    if (imagePath && imagePath.trim() !== '') {
        let cleanPath = imagePath.startsWith('/') ? imagePath.substring(1) : imagePath;
        imgContainer.innerHTML = `<img src="${cleanPath}" style="width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 6px 8px rgba(0,0,0,0.3));">`;
    } else {
        let iconClass = icon && icon.trim() !== '' ? icon.trim() : 'bx bxs-medal';
        imgContainer.innerHTML = `<i class="${iconClass}" style="font-size:5rem;color:#FF6A00;text-shadow:0 4px 6px rgba(0,0,0,0.15);"></i>`;
    }
    
    let modal = document.getElementById('medalViewerModal');
    let content = document.getElementById('medalViewerContent');
    modal.style.display = 'flex';
    void modal.offsetWidth;
    modal.style.opacity = '1';
    content.style.transform = 'scale(1)';
};

window.closeMedalViewer = function() {
    let modal = document.getElementById('medalViewerModal');
    let content = document.getElementById('medalViewerContent');
    modal.style.opacity = '0';
    content.style.transform = 'scale(0.95)';
    setTimeout(() => { modal.style.display = 'none'; }, 200);
};


function uploadDashAvatar(input) {
    if (!input.files || input.files.length === 0) return;
    const file = input.files[0];
    const btnText = document.getElementById('dashAvatarBtnText');
    const originalText = btnText.innerText;
    
    btnText.innerText = 'Subiendo...';
    
    const formData = new FormData();
    formData.append('type', 'avatar');
    formData.append('image', file);
    
    fetch('upload_profile.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.location.reload();
        } else {
            alert('Error: ' + data.message);
            btnText.innerText = originalText;
        }
    })
    .catch(err => {
        alert('Error conectando al servidor');
        btnText.innerTextle.display = 'none'; }, 200);
}
</script>
