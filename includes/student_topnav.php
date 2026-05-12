<?php
/**
 * Student Top Navigation V3
 * Nav bar con pills de navegación (Inicio, Mis Cursos, Ranking, Certificados, Foros)
 */

$currentView = $view ?? 'dashboard';
$isAdminNav = isset($isAdmin) && $isAdmin;
$isLeaderNav = isset($isLeader) && $isLeader;

if ($isAdminNav) {
    $navItems = [
        ['view' => 'dashboard', 'label' => 'Estadísticas', 'icon' => 'bx bx-bar-chart-alt-2'],
        ['view' => 'companies', 'label' => 'Clientes', 'icon' => 'bx bx-buildings'],
        ['view' => 'roles', 'label' => 'Roles', 'icon' => 'bx bx-briefcase'],
        ['view' => 'students', 'label' => 'Usuarios', 'icon' => 'bx bx-group'],
        ['view' => 'courses', 'label' => 'Cursos', 'icon' => 'bx bx-book-open'],
        ['view' => 'learning_paths', 'label' => 'Rutas', 'icon' => 'bx bx-network-chart'],
        ['view' => 'quizzes', 'label' => 'Evaluaciones', 'icon' => 'bx bx-task'],
        ['view' => 'forums', 'label' => 'Foros', 'icon' => 'bx bx-conversation'],
        ['view' => 'forum_moderation', 'label' => 'Moderación', 'icon' => 'bx bx-shield-quarter'],
        ['view' => 'gamification', 'label' => 'Puntuación', 'icon' => 'bx bx-medal'],
        ['view' => 'certificates', 'label' => 'Certificados', 'icon' => 'bx bx-certification'],
    ];
} elseif ($isLeaderNav) {
    $navItems = [
        ['view' => 'dashboard', 'label' => 'Estadísticas', 'icon' => 'bx bx-bar-chart-alt-2'],
        ['view' => 'dashboard&mode=student', 'label' => 'Mi Progreso', 'icon' => 'bx bx-grid-alt'],
        ['view' => 'courses', 'label' => 'Cursos', 'icon' => 'bx bx-play-circle'],
        ['view' => 'ranking', 'label' => 'Ranking', 'icon' => 'bx bx-trophy'],
        ['view' => 'forums', 'label' => 'Foros', 'icon' => 'bx bx-conversation'],
    ];
} else {
    $navItems = [
        ['view' => 'dashboard', 'label' => 'Inicio', 'icon' => 'bx bx-home-alt'],
        ['view' => 'courses', 'label' => 'Mis Cursos', 'icon' => 'bx bx-book-open'],
        ['view' => 'ranking', 'label' => 'Ranking', 'icon' => 'bx bx-trophy'],
        ['view' => 'certificates', 'label' => 'Certificados', 'icon' => 'bx bx-award'],
        ['view' => 'forums', 'label' => 'Foros', 'icon' => 'bx bx-conversation'],
    ];
}

// Initials Logic
$parts = explode(' ', trim($userName ?? 'User'));
$initials = '';
if (count($parts) > 1) {
    $initials = mb_substr($parts[0], 0, 1, 'UTF-8') . mb_substr($parts[1], 0, 1, 'UTF-8');
} else {
    $initials = mb_substr($parts[0], 0, 2, 'UTF-8');
}
$initials = mb_strtoupper($initials, 'UTF-8');

// Obtener los perfiles formativos del usuario activo para mostrar en el menú
$sessionUserId = $_SESSION['user_id'] ?? '';
$userTrainingRolesStr = '';
if ($sessionUserId && isset($pdo)) {
    try {
        $trStmt = $pdo->prepare("
            SELECT GROUP_CONCAT(tr.name SEPARATOR ', ') 
            FROM TrainingRole tr
            JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id
            WHERE rtu.B = ?
        ");
        $trStmt->execute([$sessionUserId]);
        $userTrainingRolesStr = $trStmt->fetchColumn() ?: 'Sin perfil asignado';
    } catch(Exception $e) {
        $userTrainingRolesStr = 'Desconocido';
    }
}
?>

<nav class="v3-topnav">
    <!-- Logo -->
    <div class="v3-topnav-logo">
        <a href="index.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
            <img src="assets/images/logo.png" alt="Hub Eurosoft" style="height:48px;width:auto;object-fit:contain;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="v3-topnav-logo-icon" style="display:none;">HE</div>
        </a>
    </div>

    <!-- Navigation Pills -->
    <div class="v3-nav-pills">
        <?php foreach ($navItems as $item): 
            $actualViewWithMode = $currentView;
            if (isset($_GET['mode'])) {
                $actualViewWithMode .= '&mode=' . $_GET['mode'];
            }
            
            $isActive = ($actualViewWithMode === $item['view']) || 
                        ($item['view'] === 'forums' && in_array($currentView, ['forums', 'forum_topic'])) || 
                        (strpos($item['view'], '&') === false && $currentView === $item['view'] && !isset($_GET['mode']));
        ?>
            <a href="index.php?view=<?= $item['view'] ?>" 
               class="v3-nav-pill <?= $isActive ? 'active' : '' ?>">
                <i class="<?= $item['icon'] ?>"></i>
                <?= $item['label'] ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <div class="v3-topnav-actions">
        <!-- Mobile hamburger -->
        <button class="v3-topnav-btn v3-topnav-btn-light v3-mobile-toggle" 
                onclick="toggleMobileMenu()" 
                aria-label="Menú">
            <i class='bx bx-menu'></i>
        </button>



        <?php if ($isAdminNav): ?>
            <!-- Bitácora -->
            <a href="index.php?view=activity_log" class="v3-topnav-btn v3-topnav-btn-light" title="Bitácora de Accesos" style="text-decoration:none;">
                <i class='bx bx-receipt'></i>
            </a>
        <?php endif; ?>

        <!-- Notifications -->
        <div class="v3-notif-badge" id="v3-notif-container" style="position: relative;">
            <button class="v3-topnav-btn v3-topnav-btn-light" id="v3-bell-icon" onclick="toggleV3Notifs()">
                <i class='bx bx-bell'></i>
            </button>
            <span id="v3-notif-dot" style="display: none; position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; font-size: 0.65rem; font-weight: 700; width: 18px; height: 18px; border-radius: 50%; align-items: center; justify-content: center; border: 2px solid white; z-index: 10;">0</span>
            
            <!-- Dropdown de notificaciones -->
            <div id="v3-notif-dropdown" style="display: none; position: absolute; top: 120%; right: 0; width: 320px; background: white; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.12); border: 1px solid #f3f4f6; z-index: 1000; overflow: hidden;">
                <div style="padding: 1rem; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; align-items: center; background: #fafafa;">
                    <span style="font-weight: 800; color: #111827; font-size: 0.95rem;">Notificaciones</span>
                    <button onclick="markAllReadV3()" style="background: none; border: none; font-size: 0.75rem; font-weight: 700; color: var(--v3-primary); cursor: pointer;">Marcar Leídas</button>
                </div>
                <div id="v3-notif-list" style="max-height: 350px; overflow-y: auto;">
                    <div style="padding: 2rem; text-align: center; color: #9ca3af; font-size: 0.85rem;">Cargando...</div>
                </div>
                <!-- Link a Historial Modal -->
                <div style="padding: 0.75rem; border-top: 1px solid #f3f4f6; text-align: center; background: #fafafa;">
                    <button onclick="openV3NotifHistory()" style="background: none; border: none; font-size: 0.8rem; font-weight: 700; color: #4b5563; cursor: pointer;">Ver Historial Completo (7 días)</button>
                </div>
            </div>
        </div>

        <!-- User Profile Dropdown -->
        <div id="v3-profile-container" style="position: relative;">
            <button class="v3-topnav-btn" id="v3-profile-toggle" onclick="toggleV3Profile()" title="Mi Cuenta" style="padding: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; background-color: white; border: 1px solid #e5e7eb; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                <?php if (!empty($userAvatar)): ?>
                    <img src="<?= htmlspecialchars(ltrim($userAvatar, '/')) ?>" alt="Perfil" style="width: 100%; height: 100%; object-fit: cover; background: white;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <span style="display:none;font-size: 0.85rem; font-weight: 800;letter-spacing: 1px; color: #ff6a00;"><?= htmlspecialchars($initials) ?></span>
                <?php else: ?>
                    <span style="font-size: 0.85rem; font-weight: 800;letter-spacing: 1px; color: #ff6a00;"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
            </button>
            <div id="v3-profile-dropdown" style="display:none;position:absolute;top:120%;right:0;width:max-content;min-width:200px;max-width:300px;background:white;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.12);border:1px solid #f3f4f6;z-index:1000;overflow:hidden;">
                <div style="padding:0.5rem;">
                    <a href="index.php?view=settings" style="display:flex;flex-direction:column;gap:4px;padding:10px 14px;color:#111827;text-decoration:none;font-size:0.875rem;font-weight:600;border-radius:10px;transition:background 0.2s;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='transparent'">
                        <div style="display:flex;align-items:center;gap:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <i class='bx bx-user-circle' style="font-size:1.2rem;color:#FF6A00;"></i> 
                            <span style="overflow:hidden;text-overflow:ellipsis;">Perfil <span style="font-weight: 400; color: #6b7280; font-size: 0.8rem;">(<?= htmlspecialchars($userName ?? 'User') ?>)</span></span>
                        </div>
                        <div style="padding-left:28px;font-size:0.75rem;color:#8b5cf6;font-weight:600;display:flex;align-items:center;gap:4px;white-space:normal;line-height:1.2;">
                            <i class='bx bx-briefcase-alt-2'></i> <?= htmlspecialchars($userTrainingRolesStr) ?>
                        </div>
                    </a>
                    <div style="height:1px;background:#f3f4f6;margin:4px 0;"></div>
                    <a href="logout.php" style="display:flex;align-items:center;gap:10px;padding:10px 14px;color:#ef4444;text-decoration:none;font-size:0.875rem;font-weight:600;border-radius:10px;transition:background 0.2s;" onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background='transparent'">
                        <i class='bx bx-log-out' style="font-size:1.2rem;"></i> Cerrar Sesi&oacute;n
                    </a>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Mobile Menu Overlay -->
<div class="v3-mobile-menu" id="v3-mobile-menu">
    <div class="v3-mobile-menu-inner">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <div class="v3-topnav-logo">
                <img src="assets/images/logo.png" alt="Hub Eurosoft" style="height:48px;width:auto;object-fit:contain;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="v3-topnav-logo-icon" style="display:none;">HE</div>
            </div>
            <button onclick="toggleMobileMenu()" style="background: none; border: none; font-size: 1.5rem; color: #6b7280; cursor: pointer;">
                <i class='bx bx-x'></i>
            </button>
        </div>
        
        <?php foreach ($navItems as $item): 
            $actualViewWithMode = $currentView;
            if (isset($_GET['mode'])) {
                $actualViewWithMode .= '&mode=' . $_GET['mode'];
            }
            $isActive = ($actualViewWithMode === $item['view']) || (strpos($item['view'], '&') === false && $currentView === $item['view'] && !isset($_GET['mode']));
        ?>
            <a href="index.php?view=<?= $item['view'] ?>" 
               class="v3-mobile-nav-item <?= $isActive ? 'active' : '' ?>">
                <i class="<?= $item['icon'] ?>" style="font-size: 1.2rem;"></i>
                <?= $item['label'] ?>
            </a>
        <?php endforeach; ?>
        
        <?php if ($isAdminNav): ?>
            <a href="index.php?view=activity_log" class="v3-mobile-nav-item <?= ($currentView === 'activity_log') ? 'active' : '' ?>">
                <i class='bx bx-receipt' style="font-size: 1.2rem;"></i>
                Bitácora de Accesos
            </a>
        <?php endif; ?>
        
        <div style="margin-top: auto; padding-top: 1rem; border-top: 1px solid #f3f4f6;">
            <a href="logout.php" class="v3-mobile-nav-item" style="color: #ef4444;">
                <i class='bx bx-log-out' style="font-size: 1.2rem;"></i>
                Cerrar Sesión
            </a>
        </div>
    </div>
</div>

<style>
    @media (max-width: 1023px) {
        .v3-mobile-toggle { display: flex !important; }
    }
</style>

<!-- Modal Historial de Notificaciones -->
<div class="v3-modal-overlay" id="v3-modal-notif-history" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:white;width:90%;max-width:550px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.2);display:flex;flex-direction:column;max-height:80vh;">
        <div style="padding:1.25rem;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
            <h3 style="margin:0;font-size:1.1rem;color:#111827;">Historial de Notificaciones</h3>
            <button onclick="document.getElementById('v3-modal-notif-history').style.display='none'" style="background:none;border:none;font-size:1.5rem;color:#9ca3af;cursor:pointer;"><i class='bx bx-x'></i></button>
        </div>
        <div id="v3-notif-history-list" style="padding:1rem;flex:1;overflow-y:auto;background:#fafafa;">
            <div style="text-align:center;padding:2rem;color:#9ca3af;">Cargando...</div>
        </div>
    </div>
</div>

<script>
function toggleMobileMenu() {
    document.getElementById('v3-mobile-menu').classList.toggle('active');
}

// Close mobile menu when clicking overlay
document.getElementById('v3-mobile-menu').addEventListener('click', function(e) {
    if (e.target === this) toggleMobileMenu();
});

function toggleV3Profile() {
    const dd = document.getElementById('v3-profile-dropdown');
    dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
}

document.addEventListener('click', function(e) {
    const pc = document.getElementById('v3-profile-container');
    if (pc && !pc.contains(e.target)) {
        document.getElementById('v3-profile-dropdown').style.display = 'none';
    }
});

function toggleV3Profile() {
    const dd = document.getElementById('v3-profile-dropdown');
    dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
}
document.addEventListener('click', function(e) {
    const pc = document.getElementById('v3-profile-container');
    if (pc && !pc.contains(e.target)) {
        document.getElementById('v3-profile-dropdown').style.display = 'none';
    }
});

function toggleV3Notifs() {
    const dd = document.getElementById('v3-notif-dropdown');
    dd.style.display = dd.style.display === 'block' ? 'none' : 'block';
    if (dd.style.display === 'block') fetchV3Notifs();
}

document.addEventListener('click', function(e) {
    const container = document.getElementById('v3-notif-container');
    if (container && !container.contains(e.target)) {
        document.getElementById('v3-notif-dropdown').style.display = 'none';
    }
});

function fetchV3Notifs() {
    fetch('api_notifications.php?action=get')
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            const dot = document.getElementById('v3-notif-dot');
            if (res.unread > 0) {
                dot.style.display = 'flex';
                dot.innerText = res.unread > 9 ? '9+' : res.unread;
            } else {
                dot.style.display = 'none';
            }
            
            const list = document.getElementById('v3-notif-list');
            if (res.data.length === 0) {
                list.innerHTML = '<div style="padding: 2.5rem 1rem; text-align: center; color: #9ca3af;"><i class="bx bx-bell-off" style="font-size: 2rem; opacity: 0.5;"></i><br>No tienes notificaciones nuevas.</div>';
            } else {
                let html = '';
                res.data.forEach(n => {
                    let bg = n.isRead ? 'white' : '#fef3c7';
                    let targetUrlStr = n.url ? `'${n.url}'` : 'null';
                    html += `
                    <div style="display: flex; gap: 0.75rem; padding: 0.875rem 1rem; border-bottom: 1px solid #f3f4f6; background: ${bg}; cursor: pointer;" onclick="clickV3Notif('${n.id}', ${targetUrlStr})">
                        <div style="width: 36px; height: 36px; border-radius: 50%; background: #fff7ed; color: var(--v3-primary); display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                            <i class='bx bx-message-square-detail'></i>
                        </div>
                        <div style="flex: 1;">
                            <div style="font-size: 0.8rem; font-weight: 700; color: #111827;">${n.title}</div>
                            <div style="font-size: 0.75rem; color: #6b7280; margin-top: 2px;">${n.message}</div>
                        </div>
                    </div>`;
                });
                list.innerHTML = html;
            }
        }
    }).catch(() => {});
}

function clickV3Notif(id, redirectUrl = null) {
    const fd = new FormData();
    fd.append('action', 'mark_read');
    fd.append('notif_id', id);
    fetch('api_notifications.php', { method: 'POST', body: fd })
    .then(() => {
        if (redirectUrl) {
            window.location.href = redirectUrl;
        } else {
            fetchV3Notifs();
        }
    });
}

function markAllReadV3() {
    const fd = new FormData();
    fd.append('action', 'mark_all_read');
    fetch('api_notifications.php', { method: 'POST', body: fd })
    .then(() => fetchV3Notifs());
}

function openV3NotifHistory() {
    document.getElementById('v3-notif-dropdown').style.display = 'none';
    document.getElementById('v3-modal-notif-history').style.display = 'flex';
    const list = document.getElementById('v3-notif-history-list');
    list.innerHTML = '<div style="text-align:center;padding:2rem;color:#9ca3af;">Cargando...</div>';
    
    fetch('api_notifications.php?action=history')
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            if (res.data.length === 0) {
                list.innerHTML = '<div style="text-align:center;padding:2rem;color:#9ca3af;"><i class="bx bx-history" style="font-size:2rem;"></i><br>No hay historial en los últimos 7 días.</div>';
            } else {
                let html = '';
                res.data.forEach(n => {
                    let d = new Date(n.createdAt);
                    let datestr = d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
                    let clickHandle = n.url ? `onclick="window.location.href='${n.url}'"` : '';
                    
                    html += `
                    <div ${clickHandle} class="v3-history-item" style="display:flex;gap:0.75rem;padding:1rem;border-bottom:1px solid #e5e7eb;background:white;margin-bottom:8px;border-radius:8px; ${n.url ? 'cursor:pointer;' : ''}">
                        <div style="width:36px;height:36px;border-radius:50%;background:#f3f4f6;color:#6b7280;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <i class='bx bx-message-rounded-dots'></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-size:0.85rem;font-weight:700;color:#111827;">${n.title}</div>
                            <div style="font-size:0.8rem;color:#4b5563;margin-top:2px;">${n.message}</div>
                            <div style="font-size:0.7rem;color:#9ca3af;margin-top:4px;">${datestr}</div>
                        </div>
                    </div>`;
                });
                list.innerHTML = html;
            }
        }
    });
}

// Initial fetch
fetchV3Notifs();
setInterval(fetchV3Notifs, 45000);
</script>
