<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// Solo Administradores Globales
$userRole = strtoupper($_SESSION['user_role'] ?? '');
if ($userRole !== 'ADMIN') {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes para modificar el sistema de gamificación.</p>";
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

$successMsg = '';
$errorMsg = '';

// =============================================
// Auto-Creación de Tablas (Autonomía de Classic)
// =============================================
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS GamificationRule (
            id VARCHAR(191) NOT NULL PRIMARY KEY,
            actionType VARCHAR(191) NOT NULL UNIQUE,
            points INT NOT NULL DEFAULT 0,
            isActive TINYINT(1) NOT NULL DEFAULT 1,
            createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Achievement (
            id VARCHAR(191) NOT NULL PRIMARY KEY,
            title VARCHAR(191) NOT NULL,
            description VARCHAR(191) DEFAULT NULL,
            icon VARCHAR(191) NOT NULL,
            targetAction VARCHAR(191) NOT NULL,
            threshold INT NOT NULL DEFAULT 0,
            pointsBonus INT NOT NULL DEFAULT 0,
            color VARCHAR(191) NOT NULL DEFAULT 'bg-indigo-500',
            isActive TINYINT(1) NOT NULL DEFAULT 1,
            createdAt DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            updatedAt DATETIME(3) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (PDOException $e) {
    if (empty($errorMsg)) {
        $errorMsg = "Error al verificar/crear tablas: " . $e->getMessage();
    }
}

// =============================================
// Auto-Seed de Reglas Base
// =============================================
try {
    $countRules = $pdo->query("SELECT COUNT(*) FROM GamificationRule")->fetchColumn();
    if ($countRules == 0) {
        $seedRules = [
            ['COURSE_COMPLETED', 100],
            ['LEARNING_PATH_COMPLETED', 200],
            ['FIRST_IN_BU_COURSE', 50],
            ['FIRST_IN_BU_PATH', 100],
            ['CERTIFICATE_EARNED', 150]
        ];
        $stmtInsert = $pdo->prepare("INSERT INTO GamificationRule (id, actionType, points, isActive, createdAt, updatedAt) VALUES (?, ?, ?, 1, NOW(), NOW())");
        foreach($seedRules as $r) {
            $stmtInsert->execute([generateCuid(), $r[0], $r[1]]);
        }
        $successMsg = "Se han inyectado las 5 reglas de bonificación originales.";
    }
} catch (Exception $e) {
    $errorMsg = "Error en Auto-Seed: " . $e->getMessage();
}

// =============================================
// Procesamiento CRUD
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        // --- GAMIFICATION RULES ---
        if ($action === 'create_rule') {
            $actType = strtoupper(trim($_POST['actionType'] ?? ''));
            $pts = (int)($_POST['points'] ?? 0);
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            
            $stmt = $pdo->prepare("INSERT INTO GamificationRule (id, actionType, points, isActive, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([generateCuid(), $actType, $pts, $isActive]);
            $successMsg = "Regla de bonificación creada con éxito.";
        }
        elseif ($action === 'edit_rule') {
            $id = $_POST['rule_id'] ?? '';
            $actType = strtoupper(trim($_POST['actionType'] ?? ''));
            $pts = (int)($_POST['points'] ?? 0);
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            
            $stmt = $pdo->prepare("UPDATE GamificationRule SET actionType=?, points=?, isActive=?, updatedAt=NOW() WHERE id=?");
            $stmt->execute([$actType, $pts, $isActive, $id]);
            $successMsg = "Regla actualizada correctamente.";
        }
        elseif ($action === 'delete_rule') {
            $id = $_POST['rule_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM GamificationRule WHERE id=?");
            $stmt->execute([$id]);
            $successMsg = "Regla de bonificación eliminada.";
        }
        elseif ($action === 'toggle_rule') {
            $id = $_POST['rule_id'] ?? '';
            $val = (int)($_POST['is_active'] ?? 0);
            $stmt = $pdo->prepare("UPDATE GamificationRule SET isActive=?, updatedAt=NOW() WHERE id=?");
            $stmt->execute([$val, $id]);
            $successMsg = "Estado de la Regla actualizado.";
        }
        
        // --- ACHIEVEMENTS ---
        elseif ($action === 'create_ach') {
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? '🏅');
            $target = strtoupper(trim($_POST['targetAction'] ?? ''));
            $threshold = (int)($_POST['threshold'] ?? 1);
            $pointsBonus = (int)($_POST['pointsBonus'] ?? 0);
            $color = trim($_POST['color'] ?? 'bg-indigo-500');
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'assets/images/medals/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $fileName = uniqid('medal_') . '_' . basename($_FILES['image']['name']);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                    $imagePath = '/' . $uploadDir . $fileName;
                }
            }
            
            $stmt = $pdo->prepare("INSERT INTO Achievement (id, title, description, icon, imagePath, targetAction, threshold, pointsBonus, color, isActive, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $stmt->execute([generateCuid(), $title, $desc, $icon, $imagePath, $target, $threshold, $pointsBonus, $color, $isActive]);
            $successMsg = "Medalla/Logro creado con éxito.";
        }
        elseif ($action === 'edit_ach') {
            $id = $_POST['ach_id'] ?? '';
            $title = trim($_POST['title'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $icon = trim($_POST['icon'] ?? '🏅');
            $target = strtoupper(trim($_POST['targetAction'] ?? ''));
            $threshold = (int)($_POST['threshold'] ?? 1);
            $pointsBonus = (int)($_POST['pointsBonus'] ?? 0);
            $color = trim($_POST['color'] ?? 'bg-indigo-500');
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            
            $imagePath = null;
            $updateImage = "";
            $params = [$title, $desc, $icon, $target, $threshold, $pointsBonus, $color, $isActive];
            
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'assets/images/medals/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                $fileName = uniqid('medal_') . '_' . basename($_FILES['image']['name']);
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $fileName)) {
                    $updateImage = ", imagePath=?";
                    $params[] = '/' . $uploadDir . $fileName;
                }
            }
            $params[] = $id;
            
            $stmt = $pdo->prepare("UPDATE Achievement SET title=?, description=?, icon=?, targetAction=?, threshold=?, pointsBonus=?, color=?, isActive=?, updatedAt=NOW() {$updateImage} WHERE id=?");
            $stmt->execute($params);
            $successMsg = "Logro actualizado correctamente.";
        }
        elseif ($action === 'delete_ach') {
            $id = $_POST['ach_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM Achievement WHERE id=?");
            $stmt->execute([$id]);
            $successMsg = "Logro eliminado irreversiblemente.";
        }
        
    } catch (PDOException $e) {
        $errorMsg = "Error de Base de Datos: " . $e->getMessage();
    }
}

// Extraer Datos de la Vista
try {
    $rules = $pdo->query("SELECT * FROM GamificationRule ORDER BY actionType ASC")->fetchAll(PDO::FETCH_ASSOC);
    $achievements = $pdo->query("SELECT * FROM Achievement ORDER BY pointsBonus DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Error al leer esquemas de gamificación: " . $e->getMessage();
    $rules = [];
    $achievements = [];
}

function getFriendlyRuleName($type) {
    $map = [
        'COURSE_COMPLETED' => ['title' => 'Completar un Curso', 'desc' => 'PUNTOS AL TERMINAR UN CURSO CON QUIZ APROBADO.', 'icon' => 'bx bxs-graduation', 'bg' => '#ede9fe', 'color' => '#7c3aed'],
        'LEARNING_PATH_COMPLETED' => ['title' => 'Completar una Ruta', 'desc' => 'PUNTOS AL FINALIZAR TODOS LOS CURSOS DE LA RUTA.', 'icon' => 'bx bxs-rocket', 'bg' => '#fce7f3', 'color' => '#ec4899'],
        'FIRST_IN_BU_COURSE' => ['title' => 'Primero en BU — Curso', 'desc' => 'BONUS POR SER EL PRIMERO EN TU UNIDAD EN COMPLETAR UN CURSO.', 'icon' => 'bx bxs-medal', 'bg' => '#fef3c7', 'color' => '#f59e0b'],
        'FIRST_IN_BU_PATH' => ['title' => 'Primero en BU — Ruta', 'desc' => 'BONUS POR SER EL PRIMERO EN TU UNIDAD EN COMPLETAR UNA RUTA.', 'icon' => 'bx bxs-trophy', 'bg' => '#fef3c7', 'color' => '#d97706'],
        'FIRST_IN_COMPANY_COURSE' => ['title' => 'Primero Empresa — Curso', 'desc' => 'BONUS POR SER EL PRIMERO EN TU EMPRESA EN COMPLETAR UN CURSO.', 'icon' => 'bx bxs-medal', 'bg' => '#dbeafe', 'color' => '#3b82f6'],
        'FIRST_IN_COMPANY_PATH' => ['title' => 'Primero Empresa — Ruta', 'desc' => 'BONUS POR SER EL PRIMERO EN TU EMPRESA EN COMPLETAR UNA RUTA.', 'icon' => 'bx bxs-trophy', 'bg' => '#dbeafe', 'color' => '#2563eb'],
        'CERTIFICATE_EARNED' => ['title' => 'Obtener un Certificado', 'desc' => 'PUNTOS AL RECIBIR UN CERTIFICADO DE FINALIZACIÓN.', 'icon' => 'bx bxs-certification', 'bg' => '#fef9c3', 'color' => '#ca8a04'],
        'DAILY_LOGIN' => ['title' => 'Acceso Diario', 'desc' => 'PUNTOS OTORGADOS AL INGRESAR A LA PLATAFORMA CADA DÍA.', 'icon' => 'bx bxs-flame', 'bg' => '#fee2e2', 'color' => '#ef4444'],
        'QUIZ_PASSED' => ['title' => 'Aprobar Evaluación', 'desc' => 'PUNTOS AL APROBAR UNA EVALUACIÓN O EXAMEN.', 'icon' => 'bx bxs-check-shield', 'bg' => '#dcfce7', 'color' => '#16a34a'],
        'FORUM_POST' => ['title' => 'Participar en Foros', 'desc' => 'PUNTOS POR CREAR TEMAS O RESPONDER EN FOROS.', 'icon' => 'bx bxs-message-rounded-dots', 'bg' => '#e0e7ff', 'color' => '#6366f1'],
        'PROFILE_CUSTOMIZATION' => ['title' => 'Personalizar Perfil', 'desc' => 'PUNTOS POR SUBIR FOTO DE PERFIL O BANNER.', 'icon' => 'bx bxs-user-circle', 'bg' => '#f0fdfa', 'color' => '#14b8a6'],
        'LESSON_COMPLETED' => ['title' => 'Completar Lección', 'desc' => 'PUNTOS POR CADA LECCIÓN TERMINADA.', 'icon' => 'bx bxs-book-reader', 'bg' => '#fff7ed', 'color' => '#ea580c'],
    ];
    return $map[$type] ?? ['title' => "REGLA: " . $type, 'desc' => 'REGLA TÉCNICA O PERSONALIZADA DEL SISTEMA.', 'icon' => 'bx bxs-star', 'bg' => '#f1f5f9', 'color' => '#64748b'];
}
?>

<style>
.g-page { padding: 0.5rem; font-family: 'Inter', sans-serif; }
.g-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem; flex-wrap: wrap; gap: 1rem; }
.g-title { font-size: 2rem; font-weight: 800; color: #111827; display: flex; align-items: center; gap: 0.6rem; margin-bottom: 0.2rem; }
.g-subtitle { font-size: 0.95rem; color: #6b7280; margin: 0; }

/* Tabs Pill Toggle */
.g-tabs { display: flex; background: #e2e8f0; border-radius: 12px; padding: 0.3rem; }
.g-tab-btn { background: transparent; border: none; padding: 0.6rem 1.5rem; font-size: 0.9rem; font-weight: 700; color: #64748b; border-radius: 8px; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
.g-tab-btn.active { background: #fff; color: #ff6b00; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

/* Section Header */
.g-sec-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.g-sec-title { font-size: 1.4rem; font-weight: 800; color: #0f172a; }
.btn-orange { background: #ff6b00; color: #fff; font-weight: 700; border-radius: 8px; padding: 0.6rem 1.2rem; border: none; display: flex; align-items: center; justify-content: center; gap: 0.3rem; cursor: pointer; transition: background 0.2s; }
.btn-orange:hover { background: #ea580c; }
.btn-outline-orange { background: transparent; color: #ff6b00; font-weight: 700; border-radius: 8px; padding: 0.6rem 1.2rem; border: 1px solid #ff6b00; display: flex; align-items: center; justify-content: center; gap: 0.3rem; cursor: pointer; transition: all 0.2s; }
.btn-outline-orange:hover { background: #fff7ed; }

/* Rules Row (Reglas Activas) */
.rules-list { display: flex; flex-direction: column; gap: 1rem; }
.r-row { background: #fff; border: 1px solid #f1f5f9; border-radius: 16px; padding: 1rem 1.5rem; display: flex; align-items: center; box-shadow: 0 1px 3px rgba(0,0,0,0.02); transition: transform 0.2s; }
.r-row:hover { box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
.r-icon { min-width: 50px; height: 50px; background: #f1f5f9; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.6rem; }
.r-content { flex: 1; margin: 0 1.5rem; overflow: hidden; display: flex; flex-direction: column; justify-content: center; min-height: 50px;}
.r-content h4 { margin: 0 0 0.1rem 0; font-size: 1.05rem; font-weight: 800; color: #0f172a; }
.r-content p { margin: 0; font-size: 0.70rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.03em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.r-content small { font-size: 0.65rem; color: #cbd5e1; font-family: monospace; }
.r-title-clickable { cursor: pointer; transition: color 0.2s;}
.r-title-clickable:hover { color: #ff6b00; }

.r-points { display: flex; align-items: center; gap: 0.4rem; font-size: 1.25rem; font-weight: 800; color: #0f172a; margin-right: 1.5rem; min-width: 60px; justify-content: flex-end;}
.r-points span { font-size: 0.60rem; font-weight: 800; color: #f59e0b; background: #fef3c7; padding: 0.2rem 0.4rem; border-radius: 4px; }

/* Switch Toggle Custom */
.switch { position: relative; display: inline-block; width: 44px; height: 24px; margin-right: 0.5rem;}
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);}
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: 0 1px 2px rgba(0,0,0,0.2); }
input:checked + .slider { background-color: #10b981; box-shadow: inset 0 1px 2px rgba(16,185,129,0.3); }
input:checked + .slider:before { transform: translateX(20px); }

.r-actions { display: flex; align-items: center; gap: 0.5rem; }
.btn-del { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #cbd5e1; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
.btn-del:hover { background: #fee2e2; color: #ef4444; }

/* Badges Grid (Medallas) */
.m-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.5rem; }
.m-card { background: #fff; border: 1px solid #f1f5f9; border-radius: 24px; padding: 2.5rem 1.5rem 1.5rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); display: flex; flex-direction: column; transition: transform 0.2s; }
.m-card:hover { transform: translateY(-3px); box-shadow: 0 8px 16px rgba(0,0,0,0.04); }
.m-icon-top { width: 64px; height: 64px; border-radius: 16px; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; margin: 0 auto 1.2rem; color: #fff; font-weight: bold; background-color: #ff6b00; }
.m-title-clickable { font-size: 1.2rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem 0; cursor: pointer; transition: color 0.2s;}
.m-title-clickable:hover { color: #ff6b00; }
.m-desc { font-size: 0.85rem; color: #64748b; margin: 0 0 1.5rem 0; min-height: 40px; }
.m-divider { height: 1px; background: #f1f5f9; margin-bottom: 1.5rem; width: 100%; }
.m-footer { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 1rem; }
.m-pills { display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-start; flex: 1; overflow:hidden;}
.pill-meta { font-size: 0.65rem; font-weight: 800; color: #ea580c; background: #fff7ed; padding: 0.4rem 0.8rem; border-radius: 20px; text-transform: uppercase; text-align: left; white-space: nowrap; overflow:hidden; text-overflow:ellipsis; max-width: 100%;}
.pill-bono { font-size: 0.65rem; font-weight: 800; color: #4f46e5; background: #e0e7ff; padding: 0.4rem 0.8rem; border-radius: 20px; text-transform: uppercase; text-align: left; white-space: nowrap; max-width: 100%;}
.m-actions { display: flex; flex-direction: row; gap: 0.2rem; align-items: center; }
.pill-on { font-size: 0.85rem; font-weight: 800; color: #10b981; padding: 0 0.3rem;}
.pill-off { font-size: 0.85rem; font-weight: 800; color: #9ca3af; padding: 0 0.3rem;}

.hidden { display: none !important; }
</style>

<div class="g-page">
    <div class="g-header">
        <div>
            <div class="g-title"><i class='bx bxs-star' style='color:#f59e0b;'></i> Gamificación</div>
            <p class="g-subtitle">Gestiona el motor de gamification, puntos y medallas coleccionables.</p>
        </div>
        <div class="g-tabs">
            <button class="g-tab-btn active" id="btnTabRules" onclick="switchTab('rules')">Reglas de Puntos</button>
            <button class="g-tab-btn" id="btnTabBadges" onclick="switchTab('badges')">Logros y Medallas</button>
        </div>
    </div>

    <?php if ($successMsg): ?><div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>
    <?php if (isset($dbError)): ?><div class="alert alert-error"><?php echo htmlspecialchars($dbError); ?></div><?php endif; ?>

    <!-- VISTA: REGLAS DE PUNTOS -->
    <div id="viewRules">
        <div class="g-sec-header">
            <div class="g-sec-title">Reglas Activas</div>
            <div style="display:flex; gap:1rem; align-items:center;">
                <span style="font-size:0.75rem; color:#94a3b8; font-weight:600;">Las reglas se gestionan desde el sistema</span>
            </div>
        </div>

        <div class="rules-list">
            <?php foreach ($rules as $r): 
                $f = getFriendlyRuleName($r['actionType']);
            ?>
            <div class="r-row">
                <div class="r-icon" style="background: <?= htmlspecialchars($f['bg'] ?? '#f1f5f9') ?>; color: <?= htmlspecialchars($f['color'] ?? '#64748b') ?>"><i class='<?= htmlspecialchars($f['icon']) ?>'></i></div>
                <div class="r-content">
                    <h4 class="r-title-clickable" onclick="openEditRule(document.getElementById('edit_r_<?= $r['id'] ?>').dataset)"><?= htmlspecialchars($f['title']) ?></h4>
                    <p title="<?= htmlspecialchars($f['desc']) ?>"><?= htmlspecialchars($f['desc']) ?></p>
                    <small><?= htmlspecialchars($r['actionType']) ?></small>
                    <!-- Contenedor invisible para data attributes -->
                    <div id="edit_r_<?= $r['id'] ?>" data-id="<?= htmlspecialchars($r['id']) ?>" data-action="<?= htmlspecialchars($r['actionType']) ?>" data-points="<?= htmlspecialchars($r['points']) ?>" data-active="<?= htmlspecialchars($r['isActive']) ?>"></div>
                </div>
                <div class="r-points">
                    <?= htmlspecialchars($r['points']) ?> <span>PTS</span>
                </div>
                <div class="r-actions">
                    <form method="POST" style="margin:0; height:24px;">
                        <input type="hidden" name="action" value="toggle_rule">
                        <input type="hidden" name="rule_id" value="<?= htmlspecialchars($r['id']) ?>">
                        <input type="hidden" name="is_active" value="<?= $r['isActive'] ? 0 : 1 ?>">
                        <label class="switch">
                            <input type="checkbox" onchange="this.form.submit()" <?= $r['isActive'] ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </form>
                    
                    <button class="btn-del" style="color: #64748b; background: #f8fafc;" title="Editar Regla" onclick="openEditRule(document.getElementById('edit_r_<?= $r['id'] ?>').dataset)"><i class='bx bx-pencil'></i></button>
                    
                    <form method="POST" style="margin:0;" onsubmit="return confirm('¿Eliminar esta regla?');">
                        <input type="hidden" name="action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?= htmlspecialchars($r['id']) ?>">
                        <button type="submit" class="btn-del" title="Borrar"><i class='bx bx-trash'></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($rules) === 0): ?>
                <div style="text-align:center; padding: 3rem; color: #94a3b8; font-weight:600;">No hay reglas de bonificación definidas.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- VISTA: LOGROS Y MEDALLAS -->
    <div id="viewBadges" class="hidden">
        <div class="g-sec-header">
            <div class="g-sec-title">Medallas Disponibles</div>
            <button class="btn-orange" onclick="openModal('modalCreateAch')">+ Crear Medalla</button>
        </div>

        <div class="m-grid">
            <?php foreach ($achievements as $ach): 
                 // Inferir un color si es tailwind o hex. Por defecto, naranja o extraemos uno.
                 $bgCol = '#ff6b00'; 
                 if (strpos($ach['color'], 'indigo') !== false) $bgCol = '#4f46e5';
                 elseif (strpos($ach['color'], 'green') !== false) $bgCol = '#10b981';
                 elseif (strpos($ach['color'], 'yellow') !== false || strpos($ach['color'], 'amber') !== false) $bgCol = '#f59e0b';
                 elseif (strpos($ach['color'], 'red') !== false || strpos($ach['color'], 'rose') !== false) $bgCol = '#ef4444';
                 elseif (strpos($ach['color'], 'blue') !== false || strpos($ach['color'], 'sky') !== false) $bgCol = '#0ea5e9';
                 elseif (strpos($ach['color'], '#') === 0) $bgCol = $ach['color'];
            ?>
            <div class="m-card">
                <div class="m-icon-top" style="background: <?= htmlspecialchars($bgCol) ?>; opacity: <?= $ach['isActive'] ? '1' : '0.5' ?>; overflow: hidden; position: relative; padding: 0;">
                    <?php if (!empty($ach['imagePath'])): ?>
                        <img src="<?= htmlspecialchars(ltrim($ach['imagePath'], '/')) ?>" alt="Medalla" style="width: 100%; height: 100%; object-fit: cover; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">
                    <?php else: ?>
                        <div style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%;">
                        <?php 
                            $iVal = trim($ach['icon']) ?: 'bx bxs-medal';
                            $iconMap = ['Crown'=>'bx bx-crown','Rocket'=>'bx bx-rocket','Zap'=>'bx bxs-bolt','Medal'=>'bx bx-medal','Flame'=>'bx bxs-flame','BookOpen'=>'bx bx-book-open','Star'=>'bx bx-star','Trophy'=>'bx bx-trophy'];
                            if (array_key_exists($iVal, $iconMap)) echo "<i class='" . htmlspecialchars($iconMap[$iVal]) . "'></i>";
                            elseif (strpos($iVal, 'bx') !== false) echo "<i class='" . htmlspecialchars($iVal) . "'></i>";
                            else echo htmlspecialchars($iVal);
                        ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h4 class="m-title-clickable" style="opacity: <?= $ach['isActive'] ? '1' : '0.5' ?>;" onclick="openEditAch(document.getElementById('edit_a_<?= $ach['id'] ?>').dataset)"><?= htmlspecialchars($ach['title']) ?></h4>
                <p class="m-desc"><?= htmlspecialchars($ach['description'] ?: 'Sin descripción') ?></p>
                <div class="m-divider"></div>
                
                <div id="edit_a_<?= $ach['id'] ?>" data-id="<?= htmlspecialchars($ach['id']) ?>" data-title="<?= htmlspecialchars($ach['title']) ?>" data-desc="<?= htmlspecialchars($ach['description']) ?>" data-icon="<?= htmlspecialchars($ach['icon']) ?>" data-target="<?= htmlspecialchars($ach['targetAction']) ?>" data-threshold="<?= htmlspecialchars($ach['threshold']) ?>" data-pts="<?= htmlspecialchars($ach['pointsBonus']) ?>" data-color="<?= htmlspecialchars($ach['color']) ?>" data-active="<?= htmlspecialchars($ach['isActive']) ?>"></div>

                <div class="m-footer">
                    <div class="m-pills">
                        <div class="pill-meta" title="META: <?= htmlspecialchars($ach['threshold']) ?> <?= htmlspecialchars(str_replace('_', ' ', $ach['targetAction'])) ?>">META: <?= htmlspecialchars($ach['threshold']) ?> <?= htmlspecialchars(str_replace('_', ' ', $ach['targetAction'])) ?></div>
                        <div class="pill-bono">BONO: +<?= htmlspecialchars($ach['pointsBonus']) ?> XP</div>
                    </div>
                    <div class="m-actions">
                        <div class="<?= $ach['isActive'] ? 'pill-on' : 'pill-off' ?>"><?= $ach['isActive'] ? 'ON' : 'OFF' ?></div>
                        <button class="btn-del" style="color: #64748b; background: #f8fafc;" title="Editar Medalla" onclick="openEditAch(document.getElementById('edit_a_<?= $ach['id'] ?>').dataset)"><i class='bx bx-pencil'></i></button>
                        <form method="POST" style="margin:0;" onsubmit="return confirm('¿Borrar medalla?');">
                            <input type="hidden" name="action" value="delete_ach">
                            <input type="hidden" name="ach_id" value="<?= htmlspecialchars($ach['id']) ?>">
                            <button type="submit" class="btn-del" title="Borrar Medalla"><i class='bx bx-trash'></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (count($achievements) === 0): ?>
                <div style="grid-column: 1 / -1; text-align:center; padding: 3rem; color: #94a3b8; font-weight:600;">Sin logros configurados.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tabId) {
    document.getElementById('viewRules').classList.add('hidden');
    document.getElementById('viewBadges').classList.add('hidden');
    document.getElementById('btnTabRules').classList.remove('active');
    document.getElementById('btnTabBadges').classList.remove('active');
    
    if (tabId === 'rules') {
        document.getElementById('viewRules').classList.remove('hidden');
        document.getElementById('btnTabRules').classList.add('active');
    } else {
        document.getElementById('viewBadges').classList.remove('hidden');
        document.getElementById('btnTabBadges').classList.add('active');
    }
}
</script>

<!-- ========================================== -->
<!-- MODALES: Reglas de Bonificación            -->
<!-- ========================================== -->

<!-- Modal Create Rule -->
<div class="modal-overlay" id="modalCreateRule">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3 class="modal-title">Agregar Regla de XP</h3>
            <button class="modal-close" onclick="closeModal('modalCreateRule')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_rule">
            
            <div class="form-group">
                <label class="form-label">Acción Target (CÓDIGO SISTEMA)</label>
                <input type="text" name="actionType" class="form-control" required placeholder="Ej: LOG_IN_DAILY">
                <p style="font-size: 0.75rem; color: #6b7280; margin-top: 5px;">Este código es consumido por los triggers del backend (ej. <code>COURSE_COMPLETED</code>).</p>
            </div>
            
            <div class="form-group">
                <label class="form-label">Puntos Otorga (XP)</label>
                <input type="number" name="points" class="form-control" value="50" required min="1">
            </div>
            
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:500;">
                    <input type="checkbox" name="isActive" checked style="width: 16px; height: 16px;"> Habilitar Regla Activa
                </label>
            </div>
            
            <div style="margin-top: 1.5rem; text-align: right;">
                <button type="submit" class="btn btn-primary">Crear Regla</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Rule -->
<div class="modal-overlay" id="modalEditRule">
    <div class="modal-content" style="max-width: 450px;">
        <div class="modal-header">
            <h3 class="modal-title">Editar Regla XP</h3>
            <button class="modal-close" onclick="closeModal('modalEditRule')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_rule">
            <input type="hidden" name="rule_id" id="edit_rule_id">
            
            <div class="form-group">
                <label class="form-label">Acción Target (CÓDIGO SISTEMA)</label>
                <input type="text" name="actionType" id="edit_rule_action" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Puntos Otorga (XP)</label>
                <input type="number" name="points" id="edit_rule_points" class="form-control" required min="1">
            </div>
            
            <div class="form-group">
                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:500;">
                    <input type="checkbox" name="isActive" id="edit_rule_active" style="width: 16px; height: 16px;"> Regla Activa
                </label>
            </div>
            
            <div style="margin-top: 1.5rem; text-align: right;">
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODALES: Logros y Medallas                 -->
<!-- ========================================== -->

<!-- Modal Create Achievement -->
<div class="modal-overlay" id="modalCreateAch">
    <div class="modal-content" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title">Establecer Nueva Medalla</h3>
            <button class="modal-close" onclick="closeModal('modalCreateAch')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_ach">
            <input type="hidden" name="icon" id="create_ach_icon_val" value="bx bxs-medal">
            <input type="hidden" name="color" id="create_ach_color_val" value="bg-indigo-500">
            <div style="display:flex; flex-direction:column; gap:1.25rem;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group" style="margin:0;"><label class="form-label">Título de la Medalla</label><input type="text" name="title" class="form-control" required placeholder="Ej. Lector Voraz"></div>
                    <div class="form-group" style="margin:0;"><label class="form-label">Descripción</label><input type="text" name="description" class="form-control" placeholder="Completa 5 cursos con éxito"></div>
                </div>
                <div style="padding:1rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;">
                    <h4 style="margin:0 0 0.8rem 0; font-size:0.85rem; color:#374151; font-weight:700;"><i class='bx bxs-target-lock' style='color:#ef4444;'></i> Meta de Desbloqueo</h4>
                    <p style="font-size:0.7rem; color:#9ca3af; margin:0 0 0.8rem 0;">La medalla se desbloquea cuando el estudiante cumple la regla seleccionada X veces.</p>
                    <div style="display:flex; gap:1rem; align-items:flex-end;">
                        <div class="form-group" style="margin:0; width:100px;"><label class="form-label">Veces</label><input type="number" name="threshold" class="form-control" value="1" min="1" required style="text-align:center; font-weight:700; font-size:1.1rem;"></div>
                        <div class="form-group" style="margin:0; flex:1;"><label class="form-label">Regla a Cumplir</label>
                            <select name="targetAction" class="form-control" required style="cursor:pointer;">
                                <?php foreach($rules as $r): $fn = getFriendlyRuleName($r['actionType']); ?>
                                <option value="<?= htmlspecialchars($r['actionType']) ?>"><?= htmlspecialchars($fn['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group" style="margin:0;"><label class="form-label">Imagen / GIF (opcional)</label><input type="file" name="image" class="form-control" accept="image/*"></div>
                    <div class="form-group" style="margin:0;"><label class="form-label">Bono XP al Desbloquear</label><input type="number" name="pointsBonus" class="form-control" value="100" required min="0"></div>
                </div>
                <div><label class="form-label">Ícono de la Medalla</label>
                    <div id="create_icon_picker" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <?php
                        $iconList = ['bx bxs-medal'=>'Medalla','bx bxs-trophy'=>'Trofeo','bx bxs-crown'=>'Corona','bx bxs-star'=>'Estrella','bx bxs-rocket'=>'Cohete','bx bxs-flame'=>'Fuego','bx bxs-graduation'=>'Graduación','bx bxs-book-reader'=>'Lectura','bx bxs-certification'=>'Certificado','bx bxs-check-shield'=>'Verificado','bx bxs-diamond'=>'Diamante','bx bxs-bolt'=>'Rayo','bx bxs-heart'=>'Corazón','bx bxs-user-circle'=>'Perfil','bx bxs-message-rounded-dots'=>'Foro','bx bxs-target-lock'=>'Meta'];
                        foreach($iconList as $cls => $label): ?>
                        <div onclick="selectIcon(this,'create_ach_icon_val')" data-icon="<?= $cls ?>" title="<?= $label ?>" class="icon-opt" style="width:42px;height:42px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid <?= $cls==='bx bxs-medal'?'#ff6b00':'transparent' ?>;transition:all 0.2s;"><i class='<?= $cls ?>' style='font-size:1.25rem;color:#64748b;'></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div><label class="form-label">Color de la Medalla</label>
                    <div id="create_color_picker" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <?php
                        $colorOptions = ['bg-indigo-500'=>'linear-gradient(135deg,#6366f1,#818cf8)','bg-rose-500'=>'linear-gradient(135deg,#f43f5e,#fb7185)','bg-sky-500'=>'linear-gradient(135deg,#0ea5e9,#38bdf8)','bg-yellow-500'=>'linear-gradient(135deg,#eab308,#facc15)','bg-green-500'=>'linear-gradient(135deg,#22c55e,#4ade80)','bg-purple-500'=>'linear-gradient(135deg,#a855f7,#c084fc)','bg-orange-500'=>'linear-gradient(135deg,#f97316,#fb923c)','bg-red-500'=>'linear-gradient(135deg,#ef4444,#f87171)','bg-teal-500'=>'linear-gradient(135deg,#14b8a6,#2dd4bf)','bg-blue-500'=>'linear-gradient(135deg,#3b82f6,#60a5fa)','bg-pink-500'=>'linear-gradient(135deg,#ec4899,#f472b6)','bg-amber-500'=>'linear-gradient(135deg,#f59e0b,#fbbf24)'];
                        foreach($colorOptions as $key => $grad): ?>
                        <div onclick="selectColor(this,'create_ach_color_val')" data-color="<?= $key ?>" style="width:32px;height:32px;border-radius:50%;background:<?= $grad ?>;cursor:pointer;border:3px solid <?= $key==='bg-indigo-500'?'#0f172a':'transparent' ?>;transition:all 0.2s;box-shadow:0 2px 4px rgba(0,0,0,0.15);"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:500;"><input type="checkbox" name="isActive" checked style="width:16px;height:16px;"> Medalla Activa</label>
                    <button type="submit" class="btn btn-primary" style="padding:0.7rem 2rem;">Forjar Medalla</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal Edit Achievement -->
<div class="modal-overlay" id="modalEditAch">
    <div class="modal-content" style="max-width: 680px;">
        <div class="modal-header">
            <h3 class="modal-title">Modificar Medalla</h3>
            <button class="modal-close" onclick="closeModal('modalEditAch')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_ach">
            <input type="hidden" name="ach_id" id="edit_ach_id">
            <input type="hidden" name="icon" id="edit_ach_icon" value="bx bxs-medal">
            <input type="hidden" name="color" id="edit_ach_color" value="bg-indigo-500">
            <div style="display:flex; flex-direction:column; gap:1.25rem;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group" style="margin:0;"><label class="form-label">Título de la Medalla</label><input type="text" name="title" id="edit_ach_title" class="form-control" required></div>
                    <div class="form-group" style="margin:0;"><label class="form-label">Descripción</label><input type="text" name="description" id="edit_ach_desc" class="form-control"></div>
                </div>
                <div style="padding:1rem; background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px;">
                    <h4 style="margin:0 0 0.8rem 0; font-size:0.85rem; color:#374151; font-weight:700;"><i class='bx bxs-target-lock' style='color:#ef4444;'></i> Meta de Desbloqueo</h4>
                    <div style="display:flex; gap:1rem; align-items:flex-end;">
                        <div class="form-group" style="margin:0; width:100px;"><label class="form-label">Veces</label><input type="number" name="threshold" id="edit_ach_threshold" class="form-control" min="1" required style="text-align:center; font-weight:700; font-size:1.1rem;"></div>
                        <div class="form-group" style="margin:0; flex:1;"><label class="form-label">Regla a Cumplir</label>
                            <select name="targetAction" id="edit_ach_target" class="form-control" required style="cursor:pointer;">
                                <?php foreach($rules as $r): $fn = getFriendlyRuleName($r['actionType']); ?>
                                <option value="<?= htmlspecialchars($r['actionType']) ?>"><?= htmlspecialchars($fn['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem;">
                    <div class="form-group" style="margin:0;"><label class="form-label">Cambiar Imagen / GIF</label><input type="file" name="image" class="form-control" accept="image/*"></div>
                    <div class="form-group" style="margin:0;"><label class="form-label">Bono XP al Desbloquear</label><input type="number" name="pointsBonus" id="edit_ach_pts" class="form-control" required min="0"></div>
                </div>
                <div><label class="form-label">Ícono de la Medalla</label>
                    <div id="edit_icon_picker" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <?php foreach($iconList as $cls => $label): ?>
                        <div onclick="selectIcon(this,'edit_ach_icon')" data-icon="<?= $cls ?>" title="<?= $label ?>" class="icon-opt" style="width:42px;height:42px;border-radius:10px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;cursor:pointer;border:2px solid transparent;transition:all 0.2s;"><i class='<?= $cls ?>' style='font-size:1.25rem;color:#64748b;'></i></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div><label class="form-label">Color de la Medalla</label>
                    <div id="edit_color_picker" style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                        <?php foreach($colorOptions as $key => $grad): ?>
                        <div onclick="selectColor(this,'edit_ach_color')" data-color="<?= $key ?>" style="width:32px;height:32px;border-radius:50%;background:<?= $grad ?>;cursor:pointer;border:3px solid transparent;transition:all 0.2s;box-shadow:0 2px 4px rgba(0,0,0,0.15);"></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer; font-weight:500;"><input type="checkbox" name="isActive" id="edit_ach_active" style="width:16px;height:16px;"> Medalla Activa</label>
                    <button type="submit" class="btn btn-primary" style="padding:0.7rem 2rem;">Guardar Cambios</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function selectIcon(el, hiddenId) {
    el.parentElement.querySelectorAll('.icon-opt').forEach(o => o.style.border = '2px solid transparent');
    el.style.border = '2px solid #ff6b00';
    document.getElementById(hiddenId).value = el.dataset.icon;
}
function selectColor(el, hiddenId) {
    el.parentElement.querySelectorAll('div').forEach(o => o.style.borderColor = 'transparent');
    el.style.borderColor = '#0f172a';
    document.getElementById(hiddenId).value = el.dataset.color;
}

function openEditRule(d) {
    document.getElementById('edit_rule_id').value = d.id;
    document.getElementById('edit_rule_action').value = d.action;
    document.getElementById('edit_rule_points').value = d.points;
    document.getElementById('edit_rule_active').checked = (d.active == "1");
    openModal('modalEditRule');
}

function openEditAch(d) {
    document.getElementById('edit_ach_id').value = d.id;
    document.getElementById('edit_ach_title').value = d.title;
    document.getElementById('edit_ach_desc').value = d.desc;
    document.getElementById('edit_ach_icon').value = d.icon;
    document.getElementById('edit_ach_target').value = d.target;
    document.getElementById('edit_ach_threshold').value = d.threshold;
    document.getElementById('edit_ach_pts').value = d.pts;
    document.getElementById('edit_ach_color').value = d.color;
    document.getElementById('edit_ach_active').checked = (d.active == "1");
    document.querySelectorAll('#edit_icon_picker .icon-opt').forEach(o => { o.style.border = '2px solid transparent'; if(o.dataset.icon === d.icon) o.style.border = '2px solid #ff6b00'; });
    document.querySelectorAll('#edit_color_picker div').forEach(o => { o.style.borderColor = 'transparent'; if(o.dataset.color === d.color) o.style.borderColor = '#0f172a'; });
    openModal('modalEditAch');
}
</script>
