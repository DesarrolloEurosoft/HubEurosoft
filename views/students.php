<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'COMPANY_LEADER'])) {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes para la gestión de usuarios.</p>";
    exit;
}

$successMsg = '';
$errorMsg = '';

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

if (isset($_GET['action']) && $_GET['action'] === 'get_progress') {
    $uid = $_GET['user_id'] ?? '';
    if ($uid) {
        $stmt = $pdo->prepare("
            SELECT c.title as name, 
                   cp.userId, cp.isCompleted, cp.quizPassed, cp.quizScore, cp.quizAttempts,
                   (SELECT COUNT(l.id) FROM Lesson l JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id) as totalLessons,
                   (SELECT COUNT(lp.id) FROM LessonProgress lp JOIN Lesson l ON lp.lessonId = l.id JOIN Module m ON l.moduleId = m.id WHERE m.courseId = c.id AND lp.userId = cp.userId AND lp.isCompleted = 1) as completedLessons
            FROM CourseProgress cp
            JOIN Course c ON cp.courseId = c.id
            WHERE cp.userId = ?
            ORDER BY c.title ASC
        ");
        $stmt->execute([$uid]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        while (ob_get_level()) { ob_end_clean(); } // Purge any HTML
        header('Content-Type: application/json');
        echo json_encode($data);
    }
    exit;
}

// Procesar CRUD de Usuarios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $firstName = trim($_POST['firstName'] ?? '');
            $lastName = trim($_POST['lastName'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');
            $name = trim("$firstName $lastName");
            
            $email = trim($_POST['email'] ?? '');
            $newId = generateCuid();
            if (empty($email)) {
                $email = "{$newId}@hubeurosoft.com";
            }
            
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'STUDENT';
            $companyId = !empty($_POST['companyId']) ? $_POST['companyId'] : null;
            $buId = !empty($_POST['businessUnitId']) ? $_POST['businessUnitId'] : null;
            $trainingRoleIds = $_POST['trainingRoleIds'] ?? [];

            if ($firstName && $password && $email) {
                $isDuplicateNick = false;
                if (!empty($nickname)) {
                    $nickQuery = "SELECT id FROM User WHERE nickname = ?";
                    $nickParams = [$nickname];
                    if ($buId) {
                        $nickQuery .= " AND businessUnitId = ?";
                        $nickParams[] = $buId;
                    } elseif ($companyId) {
                        $nickQuery .= " AND companyId = ? AND businessUnitId IS NULL";
                        $nickParams[] = $companyId;
                    } else {
                        $nickQuery .= " AND companyId IS NULL AND businessUnitId IS NULL";
                    }
                    $stmtNick = $pdo->prepare($nickQuery);
                    $stmtNick->execute($nickParams);
                    if ($stmtNick->fetch()) {
                        $isDuplicateNick = true;
                    }
                }

                $stmtCheck = $pdo->prepare("SELECT id FROM User WHERE email = ?");
                $stmtCheck->execute([$email]);
                
                if ($isDuplicateNick) {
                    $errorMsg = "El Nickname '$nickname' ya está en uso en esta Unidad/Empresa.";
                } elseif ($stmtCheck->fetch()) {
                    $errorMsg = "El correo ya está registrado en otro usuario.";
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    // $newId ya fue generado arriba
                    
                    $stmt = $pdo->prepare("INSERT INTO User (id, name, firstName, lastName, nickname, email, passwordHash, role, companyId, businessUnitId, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    $stmt->execute([$newId, $name, $firstName, $lastName, $nickname, $email, $hash, $role, $companyId, $buId]);
                    
                    if (!empty($trainingRoleIds) && is_array($trainingRoleIds)) {
                        try {
                            $stmtTR = $pdo->prepare("INSERT INTO _TrainingRoleToUser (A, B) VALUES (?, ?)");
                            foreach ($trainingRoleIds as $rId) {
                                $stmtTR->execute([$rId, $newId]);
                            }
                        } catch(Exception $e) {}
                    }
                    
                    $successMsg = "Usuario creado y registrado con éxito.";
                }
            } else {
                $errorMsg = "Nombre, correo y contraseña son obligatorios al crear.";
            }
        } 
        elseif ($action === 'edit') {
            $id = $_POST['user_id'] ?? '';
            $firstName = trim($_POST['firstName'] ?? '');
            $lastName = trim($_POST['lastName'] ?? '');
            $nickname = trim($_POST['nickname'] ?? '');
            $name = trim(trim($_POST['name'] ?? '') ?: trim("$firstName $lastName"));
            
            $email = trim($_POST['email'] ?? '');
            if (empty($email)) {
                $email = "{$id}@hubeurosoft.com";
            }
            
            $password = $_POST['password'] ?? '';
            $role = $_POST['role'] ?? 'STUDENT';
            $companyId = !empty($_POST['companyId']) ? $_POST['companyId'] : null;
            $buId = !empty($_POST['businessUnitId']) ? $_POST['businessUnitId'] : null;
            $trainingRoleIds = $_POST['trainingRoleIds'] ?? [];

            if ($id && $firstName && $email) {
                $isDuplicateNick = false;
                if (!empty($nickname)) {
                    $nickQuery = "SELECT id FROM User WHERE nickname = ? AND id != ?";
                    $nickParams = [$nickname, $id];
                    if ($buId) {
                        $nickQuery .= " AND businessUnitId = ?";
                        $nickParams[] = $buId;
                    } elseif ($companyId) {
                        $nickQuery .= " AND companyId = ? AND businessUnitId IS NULL";
                        $nickParams[] = $companyId;
                    } else {
                        $nickQuery .= " AND companyId IS NULL AND businessUnitId IS NULL";
                    }
                    $stmtNick = $pdo->prepare($nickQuery);
                    $stmtNick->execute($nickParams);
                    if ($stmtNick->fetch()) {
                        $isDuplicateNick = true;
                    }
                }

                $stmtCheck = $pdo->prepare("SELECT id FROM User WHERE email = ? AND id != ?");
                $stmtCheck->execute([$email, $id]);
                
                if ($isDuplicateNick) {
                    $errorMsg = "El Nickname '$nickname' ya está en uso en esta Unidad/Empresa.";
                } elseif ($stmtCheck->fetch()) {
                    $errorMsg = "El correo ya está en uso.";
                } else {
                    if ($password) {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE User SET name=?, firstName=?, lastName=?, nickname=?, email=?, passwordHash=?, role=?, companyId=?, businessUnitId=?, updatedAt=NOW() WHERE id=?");
                        $stmt->execute([$name, $firstName, $lastName, $nickname, $email, $hash, $role, $companyId, $buId, $id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE User SET name=?, firstName=?, lastName=?, nickname=?, email=?, role=?, companyId=?, businessUnitId=?, updatedAt=NOW() WHERE id=?");
                        $stmt->execute([$name, $firstName, $lastName, $nickname, $email, $role, $companyId, $buId, $id]);
                    }

                    try {
                        // Obtener roles actuales para comparar
                        $stmtOrig = $pdo->prepare("SELECT A FROM _TrainingRoleToUser WHERE B = ?");
                        $stmtOrig->execute([$id]);
                        $origRoles = $stmtOrig->fetchAll(PDO::FETCH_COLUMN);
                        sort($origRoles);
                        
                        $newRoles = is_array($trainingRoleIds) ? $trainingRoleIds : [];
                        sort($newRoles);
                        
                        // Si cambiaron los perfiles formativos, reiniciar avance de cursos
                        if ($origRoles !== $newRoles) {
                            $pdo->prepare("DELETE FROM CourseProgress WHERE userId = ?")->execute([$id]);
                            $pdo->prepare("DELETE FROM TopicProgress WHERE userId = ?")->execute([$id]);
                            $pdo->prepare("DELETE FROM LessonProgress WHERE userId = ?")->execute([$id]);
                            $pdo->prepare("DELETE FROM StudentAnswer WHERE userId = ?")->execute([$id]);
                            
                            $pdo->prepare("DELETE FROM _TrainingRoleToUser WHERE B = ?")->execute([$id]);
                            if (!empty($newRoles)) {
                                $stmtInsert = $pdo->prepare("INSERT INTO _TrainingRoleToUser (A, B) VALUES (?, ?)");
                                foreach ($newRoles as $rId) {
                                    $stmtInsert->execute([$rId, $id]);
                                }
                            }
                        }
                    } catch(Exception $e) {}

                    $successMsg = "Información del usuario actualizada.";
                }
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['user_id'] ?? '';
            try { $pdo->prepare("DELETE FROM _TrainingRoleToUser WHERE B = ?")->execute([$id]); } catch(Exception $e){}
            $stmt = $pdo->prepare("DELETE FROM User WHERE id = ?");
            $stmt->execute([$id]);
            $successMsg = "Usuario eliminado permanentemente del sistema.";
        }
        elseif ($action === 'import_csv') {
            if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['csv_file']['tmp_name'];
                
                // Detectar separador
                $firstLine = fgets(fopen($file, 'r'));
                $sep = strpos($firstLine, ';') !== false ? ';' : ',';
                
                $handle = fopen($file, "r");
                $rowNum = 0;
                $imported = 0;
                $skippedRows = []; // Para rastrear errores
                
                // Caches para reducir queries en bucle masivo
                $cCache = []; $bCache = []; $trCache = [];
                
                while (($data = fgetcsv($handle, 1000, $sep)) !== FALSE) {
                    $rowNum++;
                    if ($rowNum === 1) continue; // Ignorar cabeceras
                    if (count($data) < 2) continue;
                    
                    $firstName = trim($data[0] ?? '');
                    $lastName = trim($data[1] ?? '');
                    $nickname = trim($data[2] ?? '');
                    
                    $email = trim($data[3] ?? '');
                    $email = strtolower($email);
                    $password = trim($data[4] ?? '');
                    
                    $appRole = strtoupper(trim($data[5] ?? 'STUDENT'));
                    $companyName = trim($data[6] ?? '');
                    $buName = trim($data[7] ?? '');
                    $trNames = trim($data[8] ?? '');
                    
                    $name = trim("$firstName $lastName");
                    
                    if (!$firstName) continue; // Email is no longer strictly required
                    
                    $compId = null;
                    if ($companyName) {
                        $kC = strtolower($companyName);
                        if (isset($cCache[$kC])) { $compId = $cCache[$kC]; }
                        else {
                            $s = $pdo->prepare("SELECT id FROM Company WHERE name LIKE ? LIMIT 1");
                            $s->execute(["$companyName"]);
                            $r = $s->fetch();
                            if ($r) { $compId = $r['id']; }
                            else {
                                $compId = generateCuid();
                                $pdo->prepare("INSERT INTO Company (id, name, isActive, createdAt, updatedAt) VALUES (?, ?, 1, NOW(), NOW())")->execute([$compId, $companyName]);
                            }
                            $cCache[$kC] = $compId;
                        }
                    }
                    
                    $buId = null;
                    if ($compId && $buName) {
                        $kB = $compId . '_' . strtolower($buName);
                        if (isset($bCache[$kB])) { $buId = $bCache[$kB]; }
                        else {
                            $s = $pdo->prepare("SELECT id FROM BusinessUnit WHERE companyId = ? AND name LIKE ? LIMIT 1");
                            $s->execute([$compId, "$buName"]);
                            $r = $s->fetch();
                            if ($r) { $buId = $r['id']; }
                            else {
                                $buId = generateCuid();
                                $pdo->prepare("INSERT INTO BusinessUnit (id, name, companyId, isActive, createdAt, updatedAt) VALUES (?, ?, ?, 1, NOW(), NOW())")->execute([$buId, $buName, $compId]);
                            }
                            $bCache[$kB] = $buId;
                        }
                    }
                    
                    $finalTrIds = [];
                    if ($trNames) {
                        $arr = explode('|', str_replace(',', '|', $trNames));
                        foreach ($arr as $trn) {
                            $trn = trim($trn);
                            if (!$trn) continue;
                            $kT = strtolower($trn);
                            if (isset($trCache[$kT])) { $finalTrIds[] = $trCache[$kT]; }
                            else {
                                $s = $pdo->prepare("SELECT id FROM TrainingRole WHERE name LIKE ? LIMIT 1");
                                $s->execute(["$trn"]);
                                $r = $s->fetch();
                                if ($r) { 
                                    $tId = $r['id']; 
                                    $trCache[$kT] = $tId;
                                    $finalTrIds[] = $tId;
                                }
                            }
                        }
                    }
                    
                    if (!in_array($appRole, ['STUDENT','ADMIN','COMPANY_LEADER','BUSINESS_UNIT_LEADER'])) $appRole = 'STUDENT';
                    
                    $ex = null;
                    if (strpos($email, '@hubeurosoft.interno') === false && !empty(trim($data[3] ?? ''))) {
                        $stmtCheck = $pdo->prepare("SELECT id FROM User WHERE email = ?");
                        $stmtCheck->execute([$email]);
                        $ex = $stmtCheck->fetch();
                    }
                    
                    if (!$ex && !empty($nickname)) {
                        $nickQuery = "SELECT id FROM User WHERE nickname = ?";
                        $nickParams = [$nickname];
                        if ($buId) {
                            $nickQuery .= " AND businessUnitId = ?";
                            $nickParams[] = $buId;
                        } elseif ($compId) {
                            $nickQuery .= " AND companyId = ? AND businessUnitId IS NULL";
                            $nickParams[] = $compId;
                        } else {
                            $nickQuery .= " AND companyId IS NULL AND businessUnitId IS NULL";
                        }
                        $stmtNick = $pdo->prepare($nickQuery);
                        $stmtNick->execute($nickParams);
                        if ($stmtNick->fetch()) {
                            $skippedRows[] = "Fila $rowNum: El nickname '$nickname' de $firstName ya está en uso.";
                            continue;
                        }
                    }
                    
                    if ($ex) {
                        $uId = $ex['id'];
                        if ($password) {
                            $pdo->prepare("UPDATE User SET name=?, firstName=?, lastName=?, nickname=?, passwordHash=?, role=?, companyId=?, businessUnitId=?, updatedAt=NOW() WHERE id=?")->execute([$name, $firstName, $lastName, $nickname, password_hash($password, PASSWORD_BCRYPT), $appRole, $compId, $buId, $uId]);
                        } else {
                            $pdo->prepare("UPDATE User SET name=?, firstName=?, lastName=?, nickname=?, role=?, companyId=?, businessUnitId=?, updatedAt=NOW() WHERE id=?")->execute([$name, $firstName, $lastName, $nickname, $appRole, $compId, $buId, $uId]);
                        }
                        
                        if ($trNames) {
                            $pdo->prepare("DELETE FROM CourseProgress WHERE userId = ?")->execute([$uId]);
                            $pdo->prepare("DELETE FROM TopicProgress WHERE userId = ?")->execute([$uId]);
                            $pdo->prepare("DELETE FROM LessonProgress WHERE userId = ?")->execute([$uId]);
                            $pdo->prepare("DELETE FROM StudentAnswer WHERE userId = ?")->execute([$uId]);
                            $pdo->prepare("DELETE FROM _TrainingRoleToUser WHERE B = ?")->execute([$uId]);
                            foreach ($finalTrIds as $rId) {
                                $pdo->prepare("INSERT INTO _TrainingRoleToUser (A, B) VALUES (?, ?)")->execute([$rId, $uId]);
                            }
                        }
                    } else {
                        $uId = generateCuid();
                        if (empty($email)) {
                            $email = "{$uId}@hubeurosoft.com";
                        }
                        $realPass = $password ?: '123456';
                        $pdo->prepare("INSERT INTO User (id, name, firstName, lastName, nickname, email, passwordHash, role, companyId, businessUnitId, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")->execute([$uId, $name, $firstName, $lastName, $nickname, $email, password_hash($realPass, PASSWORD_BCRYPT), $appRole, $compId, $buId]);
                        foreach ($finalTrIds as $rId) {
                            $pdo->prepare("INSERT INTO _TrainingRoleToUser (A, B) VALUES (?, ?)")->execute([$rId, $uId]);
                        }
                    }
                    $imported++;
                }
                fclose($handle);
                $successMsg = "Importación Completada: $imported perfiles procesados con éxito. Se generaron Empresas/Unidades automáticamente de ser necesario.";
                
                if (count($skippedRows) > 0) {
                    $errorMsg = "Se omitieron " . count($skippedRows) . " registro(s) por conflictos de Nickname:<br>" . implode("<br>", array_slice($skippedRows, 0, 10)) . (count($skippedRows) > 10 ? "<br>...y otros más." : "");
                }
            } else {
                $errorMsg = "Error al leer el archivo CSV. Asegúrate de que el formato sea válido y no exceda los límites del servidor.";
            }
        }
    } catch (PDOException $e) {
        $errorMsg = "Error en base de datos: " . $e->getMessage();
    }

    require_once 'utils/assignment_sync.php';
    syncAllCourseAssignments($pdo);
}

// Cargar catálogos para los formularios (Listas desplegables)
$allCompanies = $pdo->query("SELECT id, name FROM Company ORDER BY name ASC")->fetchAll();
$allBUs = $pdo->query("SELECT b.id, b.name, b.companyId, c.name as companyName FROM BusinessUnit b LEFT JOIN Company c ON b.companyId = c.id ORDER BY c.name, b.name")->fetchAll();
$allTrainingRoles = $pdo->query("SELECT id, name FROM TrainingRole ORDER BY name ASC")->fetchAll();

// Cargar Usuarios y Relaciones Complejas (Data Table)
try {
    // Usamos GROUP_CONCAT para traer múltiples roles si existieran, y limitamos a 100 para rendimiento de admin.
    $stmt = $pdo->query("
        SELECT u.id, u.name, u.firstName, u.lastName, u.nickname, u.email, u.role, u.createdAt, u.updatedAt,
               u.companyId, u.businessUnitId,
               c.name as companyName, 
               b.name as buName,
               (
                  SELECT GROUP_CONCAT(tr.name SEPARATOR ', ')
                  FROM _TrainingRoleToUser u2tr
                  JOIN TrainingRole tr ON u2tr.A = tr.id
                  WHERE u2tr.B = u.id
               ) as trainingRolesNames,
               (
                  SELECT GROUP_CONCAT(u2tr.A SEPARATOR ',')
                  FROM _TrainingRoleToUser u2tr
                  WHERE u2tr.B = u.id
               ) as trainingRolesIdList
        FROM User u
        LEFT JOIN Company c ON u.companyId = c.id
        LEFT JOIN BusinessUnit b ON u.businessUnitId = b.id
        ORDER BY u.createdAt DESC 
    ");
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback if relations collapse
    try {
        $stmt = $pdo->query("SELECT id, name, firstName, lastName, nickname, email, role, createdAt, updatedAt, companyId, businessUnitId, NULL as companyName, NULL as buName, NULL as trainingRolesNames, NULL as trainingRolesIdList FROM User ORDER BY createdAt DESC");
        $users = $stmt->fetchAll();
    } catch (PDOException $ex) {
        $dbError = "Error al leer usuarios: " . $ex->getMessage();
        $users = [];
    }
}
?>



<?php if ($successMsg): ?><div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<?php if (isset($dbError)): ?><div class="alert alert-error"><?= htmlspecialchars($dbError) ?></div><?php endif; ?>

<div class="card no-hover" style="margin-bottom: 1.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 1rem;">
         <h3 style="font-size: 1.05rem; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 0.5rem;"><i class='bx bx-user' style="color: #6366f1;"></i> Directorio de Usuarios</h3>
         <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
             <button class="btn btn-outline-primary" style="padding: 0.5rem 1rem; font-size: 0.85rem; border-radius: 8px;" onclick="openModal('modalCSV')">
                 <i class='bx bx-upload'></i> Carga Masiva
             </button>
             <button class="btn btn-primary" style="font-weight: 700; font-size: 0.85rem; padding: 0.5rem 1rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(99, 102, 241, 0.2);" onclick="openModal('modalCreate')">
                 <i class='bx bx-user-plus'></i> Registrar
             </button>
         </div>
    </div>
    
    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 250px;">
            <label class="form-label">Buscar Usuario</label>
            <div style="position: relative;">
                <i class='bx bx-search' style="position: absolute; left: 10px; top: 10px; color: #9ca3af; font-size: 1.2rem;"></i>
                <input type="text" id="searchInput" class="form-control" placeholder="Nombre, correo, rol..." onkeyup="filterTable()" style="padding-left: 2.5rem;">
            </div>
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label class="form-label">Filtrar por Cliente</label>
            <select id="filterCompany" class="form-control" onchange="updateFilterBUDropdown()">
                <option value="">-- Todos los Clientes --</option>
                <?php foreach($allCompanies as $c): ?>
                    <option value="<?= htmlspecialchars(strtolower($c['name'])) ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="flex: 1; min-width: 200px;">
            <label class="form-label">Filtrar por Unidad</label>
            <select id="filterBU" class="form-control" onchange="filterTable()">
                <option value="">-- Todas las Unidades --</option>
                <?php foreach($allBUs as $b): ?>
                    <option value="<?= htmlspecialchars(strtolower($b['name'])) ?>"><?= htmlspecialchars($b['name']) ?><?= $b['companyName'] ? ' (' . htmlspecialchars($b['companyName']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
    <div style="background: white; overflow: hidden; border-radius: 24px;">
    <div class="table-responsive" style="margin: 0; border: none;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nombre y Contacto</th>
                    <th>Compañía Padre</th>
                    <th>Unidad Mapeada</th>
                    <th>Training Rol</th>
                    <th>Rol en App</th>
                    <th style="text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody id="usersTableBody">
                <?php if (count($users) > 0): ?>
                    <?php foreach ($users as $user): ?>
                        <tr data-company="<?= htmlspecialchars(strtolower($user['companyName'] ?? '')) ?>" data-bu="<?= htmlspecialchars(strtolower($user['buName'] ?? '')) ?>" data-nickname="<?= htmlspecialchars(strtolower($user['nickname'] ?? '')) ?>">
                            <td>
                                <div style="font-weight: 500; color: var(--text-main);">
                                    <?= htmlspecialchars($user['name'] ?: 'Desconocido') ?>
                                </div>
                                <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 2px;">
                                    <?= htmlspecialchars($user['email']) ?>
                                    <?php if($user['nickname']): ?>
                                    <span style="color:#f97316; margin-left:5px;">| <i class='bx bx-user'></i> <?= htmlspecialchars($user['nickname']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if($user['companyName']): ?>
                                    <div style="display: inline-flex; align-items: center; gap: 0.3rem; background: rgba(59, 130, 246, 0.1); color: #3b82f6; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem;">
                                        <i class='bx bx-buildings'></i> <?= htmlspecialchars($user['companyName']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 0.85rem;">Independiente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($user['buName']): ?>
                                    <div style="display: inline-flex; align-items: center; gap: 0.3rem; background: rgba(16, 185, 129, 0.1); color: #10b981; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem;">
                                        <i class='bx bx-store-alt'></i> <?= htmlspecialchars($user['buName']) ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 0.85rem;">X</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if($user['trainingRolesNames']): ?>
                                    <div style="display: inline-flex; flex-wrap: wrap; gap: 0.3rem;">
                                        <?php foreach(explode(', ', $user['trainingRolesNames']) as $trn): ?>
                                            <div style="display: inline-flex; align-items: center; gap: 0.3rem; background: rgba(139, 92, 246, 0.1); color: #8b5cf6; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.8rem; font-weight: 600;">
                                                <i class='bx bx-briefcase-alt-2'></i> <?= htmlspecialchars($trn) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #9ca3af; font-size: 0.85rem;">Sin Perfil</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    $mapRoles = [
                                        'ADMIN' => ['Admin', '#fce7f3', '#9d174d'],
                                        'COMPANY_LEADER' => ['Líder C.', '#ffedd5', '#c2410c'],
                                        'BUSINESS_UNIT_LEADER' => ['Jefe BU.', '#fef3c7', '#b45309'],
                                        'STUDENT' => ['Estudiante', '#e0e7ff', '#3730a3']
                                    ];
                                    $rp = $mapRoles[$user['role']] ?? [$user['role'], '#f3f4f6', '#4b5563'];
                                ?>
                                <span style="background: <?= $rp[1] ?>; color: <?= $rp[2] ?>; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 600;">
                                    <?= $rp[0] ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: flex; justify-content: flex-end; gap: 0.3rem; align-items: center;">
                                    <button type="button" class="btn" style="padding: 0.4rem; background: #e0e7ff; color: #4f46e5; border: 1px solid #c7d2fe;" 
                                        onclick="openProgressModal('<?= htmlspecialchars($user['id']) ?>', <?= htmlspecialchars(json_encode((string)($user['name'] ?: ''))) ?>, <?= (int)($user['totalPoints'] ?? 0) ?>)"
                                        title="Ver Avance de Cursos">
                                        <i class='bx bx-book-reader'></i>
                                    </button>
                                    
                                    <button class="btn" style="padding: 0.4rem; background: var(--bg-color); color: var(--text-muted);" 
                                        onclick="openEditUser( this.dataset )"
                                        data-id="<?= htmlspecialchars($user['id'] ?? '') ?>"
                                        data-name="<?= htmlspecialchars($user['name'] ?? '') ?>"
                                        data-firstname="<?= htmlspecialchars($user['firstName'] ?? '') ?>"
                                        data-lastname="<?= htmlspecialchars($user['lastName'] ?? '') ?>"
                                        data-nickname="<?= htmlspecialchars($user['nickname'] ?? '') ?>"
                                        data-email="<?= htmlspecialchars($user['email'] ?? '') ?>"
                                        data-role="<?= htmlspecialchars($user['role'] ?? '') ?>"
                                        data-company="<?= htmlspecialchars($user['companyId'] ?? '') ?>"
                                        data-bu="<?= htmlspecialchars($user['businessUnitId'] ?? '') ?>"
                                        data-trlist="<?= htmlspecialchars($user['trainingRolesIdList'] ?? '') ?>"
                                        title="Editar Usuario">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Borrar a este usuario de la base de datos de manera irreversible? Se perderá todo su progreso.');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($user['id'] ?? '') ?>">
                                        <button type="submit" class="btn" style="padding: 0.4rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;" title="Borrar Acceso">
                                            <i class='bx bx-trash'></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 4rem; color: var(--text-muted);">El registro de usuarios está vacío.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</main>

<!-- ================= MODALES ================= -->
<style>
    .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media(max-width: 600px){ .two-cols { grid-template-columns: 1fr; } }
</style>

<!-- Modal: Crear Usuario -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title">Registrar Nuevo Usuario</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="create">
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Nombre(s)</label>
                    <input type="text" name="firstName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Apellidos</label>
                    <input type="text" name="lastName" class="form-control">
                </div>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Apodo / Nickname (Opcional)</label>
                    <input type="text" name="nickname" id="create_nickname" class="form-control" onkeyup="validateNickname('create')">
                    <span id="create_nick_warning" style="display:none; color:#ef4444; font-size:0.75rem; margin-top:4px;"><i class='bx bx-error-circle'></i> El nickname ya está en uso.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Correo (Login) - Opcional</label>
                    <input type="email" name="email" class="form-control" autocomplete="off" placeholder="usuario@empresa.com">
                </div>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Contraseña</label>
                    <input type="password" name="password" class="form-control" required placeholder="•••" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Nivel de Acceso (App)</label>
                    <select name="role" class="form-control" required>
                        <option value="STUDENT" selected>ESTUDIANTE (Normal)</option>
                        <option value="ADMIN">ADMINISTRADOR GLOBAL (Root)</option>
                        <option value="COMPANY_LEADER">LÍDER DE EMPRESA (Stats)</option>
                        <option value="BUSINESS_UNIT_LEADER">LÍDER DE SUCURSAL (Stats)</option>
                    </select>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px dashed var(--border); margin: 1.5rem 0;">

            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Vincular a un Cliente</label>
                    <select name="companyId" id="create_company" class="form-control" onchange="updateBUDropdown('create_company', 'create_bu'); validateNickname('create');">
                        <option value="">-- Sin Cliente (Autónomo) --</option>
                        <?php foreach($allCompanies as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Vincular a Unidad (BU)</label>
                    <select name="businessUnitId" id="create_bu" class="form-control" onchange="validateNickname('create');">
                        <option value="">-- Ignorar Rama --</option>
                        <!-- Opciones pobladas vía JavaScript -->
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Perfiles Formativos (Selección Múltiple)</label>
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid var(--border); padding: 0.8rem; border-radius: 6px; background: var(--bg-color);">
                    <?php foreach($allTrainingRoles as $tr): ?>
                        <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.9rem; cursor: pointer;">
                            <input type="checkbox" name="trainingRoleIds[]" value="<?= htmlspecialchars($tr['id']) ?>" style="width: 16px; height: 16px;">
                            <?= htmlspecialchars($tr['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" id="create_submit_btn" class="btn btn-primary">Registrar Usuario</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Importar CSV -->
<div class="modal-overlay" id="modalCSV">
    <div class="modal-content" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">Carga Masiva de Usuarios</h3>
            <button class="modal-close" onclick="closeModal('modalCSV')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_csv">
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                Las empresas y unidades de negocio que no existan se crearán automáticamente. Nota: Los perfiles formativos (`Training Roles`) especificados deben existir previamente en el sistema o serán ignorados.
            </p>
            <div style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid #3b82f6; padding: 1rem; border-radius: 4px; font-size: 0.85rem; margin-bottom: 1.5rem;">
                <strong>Estructura de Columnas Requerida (La Fila 1 debe ser cabecera):</strong><br><br>
                1. Nombre(s)<br>
                2. Apellidos<br>
                3. Apodo o Nickname (Opcional)<br>
                4. Correo Electrónico (Único, si existe se actualizará el perfil)<br>
                5. Contraseña (En blanco = "123456")<br>
                6. Rol de Sistema (STUDENT, ADMIN, COMPANY_LEADER, BUSINESS_UNIT_LEADER)<br>
                7. Empresa (Texto Exacto al Deseado)<br>
                8. Rama / Unidad de Negocio (Texto Exacto al Deseado)<br>
                9. Training Roles (Múltiples separados por ' | ' o comas)
            </div>
            
            <div class="form-group">
                <label class="form-label">Archivo de Matrícula (.csv o .txt)</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required style="padding: 0.5rem; border: 2px dashed var(--border);">
            </div>
            
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCSV')">Cancelar</button>
                <button type="submit" class="btn btn-primary" onclick="this.innerHTML='<i class=\'bx bx-loader-alt bx-spin\'></i> Importando...';"><i class='bx bx-cloud-upload'></i> Procesar Archivo</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Usuario -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-content" style="max-width: 650px;">
        <div class="modal-header">
            <h3 class="modal-title">Actualizar Ficha de Usuario</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" autocomplete="off">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" id="edit_id" value="">
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Nombre(s)</label>
                    <input type="text" name="firstName" id="edit_firstName" class="form-control" required>
                    <input type="hidden" name="name" id="edit_name">
                </div>
                <div class="form-group">
                    <label class="form-label">Apellidos</label>
                    <input type="text" name="lastName" id="edit_lastName" class="form-control">
                </div>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Apodo / Nickname</label>
                    <input type="text" name="nickname" id="edit_nickname" class="form-control" onkeyup="validateNickname('edit')">
                    <span id="edit_nick_warning" style="display:none; color:#ef4444; font-size:0.75rem; margin-top:4px;"><i class='bx bx-error-circle'></i> El nickname ya está en uso.</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Correo (Login) - Opcional</label>
                    <input type="email" name="email" id="edit_email" class="form-control" autocomplete="off">
                </div>
            </div>
            
            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Contraseña (Vacío = No cambiar)</label>
                    <input type="password" name="password" class="form-control" placeholder="Conservar actual..." autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label">Nivel de Acceso (App)</label>
                    <select name="role" id="edit_role" class="form-control" required>
                        <option value="STUDENT">ESTUDIANTE (Normal)</option>
                        <option value="ADMIN">ADMINISTRADOR GLOBAL (Root)</option>
                        <option value="COMPANY_LEADER">LÍDER DE EMPRESA (Stats)</option>
                        <option value="BUSINESS_UNIT_LEADER">LÍDER DE SUCURSAL (Stats)</option>
                    </select>
                </div>
            </div>

            <hr style="border: 0; border-top: 1px dashed var(--border); margin: 1.5rem 0;">

            <div class="two-cols">
                <div class="form-group">
                    <label class="form-label">Empresa</label>
                    <select name="companyId" id="edit_company" class="form-control" onchange="updateBUDropdown('edit_company', 'edit_bu'); validateNickname('edit');">
                        <option value="">-- Autónomo --</option>
                        <?php foreach($allCompanies as $c): ?>
                            <option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Rama / Unidad</label>
                    <select name="businessUnitId" id="edit_bu" class="form-control" onchange="validateNickname('edit');">
                        <option value="">-- Ignorar Rama --</option>
                        <!-- Opciones pobladas vía JavaScript -->
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Perfiles Formativos (Selección Múltiple)</label>
                <p style="color: #b91c1c; font-size: 0.8rem; margin: 0.5rem 0; font-weight: 500;">
                    <i class='bx bx-error-circle'></i> ¡ATENCIÓN! Modificar los roles formativos activamente seleccionados reiniciará permanentemente su progreso de cursos y evaluaciones.
                </p>
                <div style="max-height: 150px; overflow-y: auto; border: 1px solid var(--border); padding: 0.8rem; border-radius: 6px; background: var(--bg-color);">
                    <?php foreach($allTrainingRoles as $tr): ?>
                        <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.9rem; cursor: pointer;">
                            <input type="checkbox" name="trainingRoleIds[]" value="<?= htmlspecialchars($tr['id']) ?>" class="edit_tr_cb" style="width: 16px; height: 16px;">
                            <?= htmlspecialchars($tr['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalEdit')">Descartar</button>
                <button type="submit" id="edit_submit_btn" class="btn btn-primary">Impactar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ver Progreso Académico (AJAX) -->
<div class="modal-overlay" id="modalProgress">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3 class="modal-title">Expediente de <span id="prog_studentName"></span></h3>
            <button class="modal-close" onclick="closeModal('modalProgress')"><i class='bx bx-x'></i></button>
        </div>
        <div id="prog_loader" style="text-align: center; padding: 2rem;">
            <i class='bx bx-loader-alt bx-spin' style="font-size: 2rem; color: #6366f1;"></i>
            <p style="color: #6b7280; font-size: 0.9rem; margin-top: 0.5rem;">Cargando historial de cursos y evaluaciones...</p>
        </div>
        <div id="prog_content" style="display: none; max-height: 500px; overflow-y: auto;">
            <div id="prog_global_bar" style="margin-bottom: 1.5rem; padding: 0 0.5rem;"></div>
            <table class="data-table" style="width: 100%; border-collapse: collapse;">
                <thead style="background: #f9fafb;">
                    <tr>
                        <th style="padding: 0.8rem; text-align: left; font-size: 0.8rem; color: #6b7280;">Curso Asignado</th>
                        <th style="padding: 0.8rem; text-align: center; font-size: 0.8rem; color: #6b7280;">Avance</th>
                        <th style="padding: 0.8rem; text-align: center; font-size: 0.8rem; color: #6b7280;">Intentos Examen</th>
                        <th style="padding: 0.8rem; text-align: center; font-size: 0.8rem; color: #6b7280;">Cali.</th>
                    </tr>
                </thead>
                <tbody id="prog_tbody"></tbody>
            </table>
        </div>
        <div style="margin-top: 1.5rem; text-align: right;">
            <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalProgress')">Cerrar</button>
        </div>
    </div>
</div>

<script>
    // FIX PANTALLA NEGRA: Evitar que los modales hereden el alto completo de la tabla
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        // Moverlo fuera de cualquier contenedor transformado INMEDIATAMENTE
        document.body.appendChild(modal);
        // Forzar visibilidad suprema
        modal.style.zIndex = '999999';
    });

    // openModal definida más abajo

    async function openProgressModal(userId, name, totalPoints = 0) {
        document.getElementById('prog_studentName').textContent = name;
        document.getElementById('prog_loader').style.display = 'block';
        document.getElementById('prog_content').style.display = 'none';
        
        openModal('modalProgress');
        
        try {
            const response = await fetch('index.php?view=students&action=get_progress&user_id=' + userId);
            const data = await response.json();
            
            const tbody = document.getElementById('prog_tbody');
            tbody.innerHTML = '';
            
            if (!data || data.length === 0) {
                document.getElementById('prog_global_bar').innerHTML = `
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 700; color: #6b7280; margin-bottom: 0.4rem;">
                        <span>Avance Académico Global</span>
                        <span>
                            <i class='bx bxs-star' style="color: #fbbf24; margin-right: 2px;"></i> 
                            <span style="color: #1f2937; margin-right: 8px;">${totalPoints} Pts</span>
                        </span>
                    </div>
                `;
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding: 2rem; color: #9ca3af;">El alumno no tiene roles formativos vinculando cursos.</td></tr>';
            } else {
                let gTotalL = 0;
                let gCompL = 0;
                
                data.forEach(c => {
                    let hasStarted = c.userId !== null;
                    let isComp = c.isCompleted == 1 || c.isCompleted === true || c.isCompleted == "1";
                    
                    let tL = parseInt(c.totalLessons) || 0;
                    let cL = parseInt(c.completedLessons) || 0;
                    let pct = tL > 0 ? Math.round((cL / tL) * 100) : 0;
                    
                    gTotalL += tL;
                    gCompL += (isComp && tL > 0) ? tL : cL;
                    
                    let bgStatus = hasStarted ? `${cL}/${tL} (${pct}%)` : (isComp ? 'Graduado' : '0/0');
                    let attempts = c.quizAttempts > 0 ? c.quizAttempts : '0';
                    let score = c.quizScore !== null ? c.quizScore : '—';
                    let passed = c.quizPassed == 1 || c.quizPassed === true || c.quizPassed == "1";
                    let scColor = passed ? '#16a34a' : (c.quizScore !== null ? '#b91c1c' : '#9ca3af');
                    
                    let tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid #f3f4f6';
                    tr.innerHTML = `
                        <td style="padding: 0.8rem; font-weight: 500; color: #1f2937; font-size: 0.9rem;">
                            ${c.name}
                            <div style="font-size: 0.75rem; color: ${isComp ? '#16a34a' : (hasStarted ? '#3b82f6' : '#9ca3af')}; font-weight: 600; margin-top:0.2rem;">
                                ${isComp ? '<i class="bx bx-check-double"></i> Terminado' : (hasStarted ? '<i class="bx bx-loader-circle bx-spin"></i> En Progreso' : '<i class="bx bx-minus-circle"></i> Sin Iniciar')}
                            </div>
                        </td>
                        <td style="padding: 0.8rem; text-align: center; color: #374151; font-weight: 600; font-size: 0.9rem;">${bgStatus}</td>
                        <td style="padding: 0.8rem; text-align: center; color: #6b7280; font-weight: 600; font-size: 0.9rem;">${attempts}</td>
                        <td style="padding: 0.8rem; text-align: center; color: ${scColor}; font-weight: 800; font-size: 1rem;">${score}</td>
                    `;
                    tbody.appendChild(tr);
                });
                
                let globalPct = gTotalL > 0 ? Math.min(Math.round((gCompL / gTotalL) * 100), 100) : 0;
                let cColor = globalPct === 100 ? '#16a34a' : '#4f46e5';
                document.getElementById('prog_global_bar').innerHTML = `
                    <div style="display: flex; justify-content: space-between; font-size: 0.85rem; font-weight: 700; color: #6b7280; margin-bottom: 0.4rem;">
                        <span>Avance Académico Global</span>
                        <span>
                            <i class='bx bxs-star' style="color: #fbbf24; margin-right: 2px;"></i> 
                            <span style="color: #1f2937; margin-right: 8px;">${totalPoints} Pts</span>
                            <span style="color: ${cColor}; font-size: 1.1rem;">${globalPct}%</span>
                        </span>
                    </div>
                    <div style="width: 100%; background: #e5e7eb; border-radius: 999px; height: 8px; overflow: hidden;">
                        <div style="height: 100%; background: ${cColor}; width: ${globalPct}%; transition: width 0.5s;"></div>
                    </div>
                `;
            }
            document.getElementById('prog_loader').style.display = 'none';
            document.getElementById('prog_content').style.display = 'block';
            
        } catch(e) {
            document.getElementById('prog_loader').innerHTML = '<p style="color:#b91c1c; padding: 2rem;">Error comunicándose con el servidor AJAX.</p>';
        }
    }
    const allBUsData = <?php echo json_encode($allBUs, JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;

    function updateBUDropdown(companySelectId, buSelectId, selectedBuId = '') {
        const companyId = document.getElementById(companySelectId).value;
        const buSelect = document.getElementById(buSelectId);
        
        buSelect.innerHTML = '<option value="">-- Ignorar Rama --</option>';
        
        if (companyId) {
            const filtered = allBUsData.filter(bu => bu.companyId === companyId);
            filtered.forEach(bu => {
                let opt = document.createElement('option');
                opt.value = bu.id;
                opt.textContent = bu.name;
                if (bu.id === selectedBuId) opt.selected = true;
                buSelect.appendChild(opt);
            });
        }
    }

    function openModal(id) {
        if (id === 'modalCreate') {
            document.getElementById('create_company').value = '';
            document.querySelectorAll('#modalCreate input[type=checkbox]').forEach(cb => cb.checked = false);
            updateBUDropdown('create_company', 'create_bu');
            if(document.getElementById('create_nick_warning')) document.getElementById('create_nick_warning').style.display = 'none';
            if(document.getElementById('create_submit_btn')) document.getElementById('create_submit_btn').disabled = false;
        }
        let m = document.getElementById(id);
        m.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    
    function closeModal(id) {
        let m = document.getElementById(id);
        m.classList.remove('active');
        document.body.style.overflow = '';
    }

    // Cerrar modal al hacer clic en el overlay (fondo oscuro)
    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) closeModal(this.id);
        });
    });
    
    function openEditUser(d) {
        document.getElementById('edit_id').value = d.id || '';
        document.getElementById('edit_name').value = d.name || '';
        document.getElementById('edit_firstName').value = d.firstname || '';
        document.getElementById('edit_lastName').value = d.lastname || '';
        document.getElementById('edit_nickname').value = d.nickname || '';
        document.getElementById('edit_email').value = d.email || '';
        document.getElementById('edit_role').value = d.role || 'STUDENT';
        document.getElementById('edit_company').value = d.company || '';
        
        updateBUDropdown('edit_company', 'edit_bu', d.bu || '');
        
        let trArr = (d.trlist || '').split(',');
        let checkboxes = document.querySelectorAll('.edit_tr_cb');
        checkboxes.forEach(cb => {
            cb.checked = trArr.includes(cb.value);
        });
        
        if(document.getElementById('edit_nick_warning')) document.getElementById('edit_nick_warning').style.display = 'none';
        if(document.getElementById('edit_submit_btn')) document.getElementById('edit_submit_btn').disabled = false;
        
        openModal('modalEdit');
    }

    function updateFilterBUDropdown() {
        const companyName = document.getElementById('filterCompany').value.toLowerCase();
        const buSelect = document.getElementById('filterBU');
        
        buSelect.innerHTML = '<option value="">-- Todas las Unidades --</option>';
        
        let filtered = allBUsData;
        if (companyName !== '') {
            filtered = allBUsData.filter(bu => bu.companyName && bu.companyName.toLowerCase() === companyName);
        }
        
        filtered.forEach(bu => {
            let opt = document.createElement('option');
            opt.value = bu.name.toLowerCase();
            opt.textContent = bu.name + (companyName ? '' : (bu.companyName ? ` (${bu.companyName})` : ''));
            buSelect.appendChild(opt);
        });
        
        // Trigger table refresh since company changed
        filterTable();
    }

    function filterTable() {
        let search = document.getElementById('searchInput').value.toLowerCase();
        let company = document.getElementById('filterCompany').value.toLowerCase();
        let bu = document.getElementById('filterBU').value.toLowerCase();
        
        let rows = document.querySelectorAll('#usersTableBody tr');
        rows.forEach(row => {
            if(row.cells.length === 1) return; // Mensaje de vacío
            
            let textContent = row.innerText.toLowerCase();
            let rowCompany = row.getAttribute('data-company') || '';
            let rowBu = row.getAttribute('data-bu') || '';
            
            let matchSearch = search === '' || textContent.includes(search);
            let matchCompany = company === '' || rowCompany === company;
            let matchBu = bu === '' || rowBu === bu;
            
            if (matchSearch && matchCompany && matchBu) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    let nickTimeout = null;
    function validateNickname(mode) {
        if (nickTimeout) clearTimeout(nickTimeout);
        
        let nickEl = document.getElementById(mode + '_nickname');
        let warnEl = document.getElementById(mode + '_nick_warning');
        let btnEl = document.getElementById(mode + '_submit_btn');
        let compEl = document.getElementById(mode + '_company');
        let buEl = document.getElementById(mode + '_bu');
        let editId = mode === 'edit' ? document.getElementById('edit_id').value : '';
        
        if (!nickEl.value.trim()) {
            warnEl.style.display = 'none';
            btnEl.disabled = false;
            return;
        }

        nickTimeout = setTimeout(() => {
            let url = 'api_validate_nickname.php?nickname=' + encodeURIComponent(nickEl.value.trim()) + 
                      '&companyId=' + encodeURIComponent(compEl.value) + 
                      '&buId=' + encodeURIComponent(buEl.value);
            if (editId) url += '&excludeId=' + encodeURIComponent(editId);
            
            fetch(url)
            .then(res => res.json())
            .then(data => {
                if (data.taken) {
                    warnEl.style.display = 'block';
                    btnEl.disabled = true;
                } else {
                    warnEl.style.display = 'none';
                    btnEl.disabled = false;
                }
            })
            .catch(e => console.error("Error validando nickname", e));
        }, 500);
    }

    // Auto-búsqueda y filtros si hay parámetros en la URL
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const searchQuery = urlParams.get('search');
        const cParam = urlParams.get('c');
        const uParam = urlParams.get('u');
        
        let shouldFilter = false;

        if (cParam) {
            let filterCompany = document.getElementById('filterCompany');
            if (filterCompany) {
                // Find matching option
                for (let opt of filterCompany.options) {
                    if (opt.textContent.trim().toLowerCase() === cParam.trim().toLowerCase() || opt.value === cParam.trim().toLowerCase()) {
                        opt.selected = true;
                        updateFilterBUDropdown();
                        shouldFilter = true;
                        break;
                    }
                }
            }
        }

        if (uParam) {
            let filterBU = document.getElementById('filterBU');
            if (filterBU) {
                for (let opt of filterBU.options) {
                    // Extract just the BU name part (before parentheses)
                    let textName = opt.textContent.split('(')[0].trim().toLowerCase();
                    if (textName === uParam.trim().toLowerCase() || opt.value === uParam.trim().toLowerCase()) {
                        opt.selected = true;
                        shouldFilter = true;
                        break;
                    }
                }
            }
        }

        if (searchQuery) {
            let searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.value = searchQuery;
                shouldFilter = true;
            }
        }

        if (shouldFilter) {
            filterTable();
            
            if (searchQuery) {
                // Intentar abrir el primer resultado automáticamente
                setTimeout(() => {
                    let rows = document.querySelectorAll('#usersTableBody tr');
                    let foundBtn = null;
                    for (let row of rows) {
                        if (row.style.display !== 'none') {
                            let rowText = row.innerText.toLowerCase();
                            let nickname = row.getAttribute('data-nickname') || '';
                            if (rowText.includes(searchQuery.toLowerCase().trim()) || nickname === searchQuery.toLowerCase().trim()) {
                                let editBtn = row.querySelector('button[onclick^="openEditUser"]');
                                if (editBtn) {
                                    foundBtn = editBtn;
                                    break;
                                }
                            }
                        }
                    }
                    if (foundBtn) {
                        foundBtn.click();
                    }
                }, 400);
            }
        }
    });
</script>
