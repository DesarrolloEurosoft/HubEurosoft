<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'COMPANY_LEADER', 'BUSINESS_UNIT_LEADER'])) {
    echo "<h2>Acceso Denegado</h2>"; exit;
}

$companyId = $_GET['company_id'] ?? '';
if (!$companyId) { echo "<h2>Error</h2><p>No se especificó la empresa padre.</p>"; exit; }

$successMsg = ''; $errorMsg = '';
if (!function_exists('generateCuid')) { function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); } }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            if ($name) {
                $cuid = generateCuid();
                $logoPath = null;
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $dest = 'uploads/companies/' . $cuid . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                        $logoPath = $dest;
                    }
                }
                $stmt = $pdo->prepare("INSERT INTO BusinessUnit (id, name, companyId, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$cuid, $name, $companyId, $isActive, $logoPath]);
                $successMsg = "Unidad creada.";
            }
        } 
        elseif ($action === 'edit') {
            $id = $_POST['bu_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $isActive = isset($_POST['isActive']) ? 1 : 0;
            if ($id && $name) {
                $logoUpdateSql = "";
                $params = [$name, $isActive];
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
                    $dest = 'uploads/companies/' . $id . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
                        $logoUpdateSql = ", logoPath = ?";
                        $params[] = $dest;
                    }
                }
                $params[] = $id;
                $params[] = $companyId;
                
                $stmt = $pdo->prepare("UPDATE BusinessUnit SET name = ?, isActive = ?, updatedAt = NOW() $logoUpdateSql WHERE id = ? AND companyId = ?");
                $stmt->execute($params);
                $successMsg = "Unidad actualizada.";
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['bu_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM BusinessUnit WHERE id = ? AND companyId = ?");
            $stmt->execute([$id, $companyId]);
            $successMsg = "Unidad eliminada.";
        }
        elseif ($action === 'toggle_status') {
            $id = $_POST['bu_id'] ?? '';
            $newStatus = $_POST['new_status'] === '1' ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE BusinessUnit SET isActive = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $successMsg = $newStatus ? "Acceso de la unidad de negocio restaurado." : "Login suspendido para los miembros de esta unidad de negocio.";
        }
        elseif ($action === 'assign_leader') {
            $buId = $_POST['bu_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            if ($buId && $userId) {
                $stmt = $pdo->prepare("UPDATE User SET role = 'BUSINESS_UNIT_LEADER', businessUnitId = ?, companyId = ? WHERE id = ?");
                $stmt->execute([$buId, $companyId, $userId]);
                $successMsg = "Jefe asignado correctamente a la Unidad de Negocio.";
            }
        }
        elseif ($action === 'remove_leader') {
            $userId = $_POST['user_id'] ?? '';
            if ($userId) {
                $stmt = $pdo->prepare("UPDATE User SET role = 'STUDENT' WHERE id = ?");
                $stmt->execute([$userId]);
                $successMsg = "Privilegios revocados de la unidad.";
            }
        }
    } catch (PDOException $e) { $errorMsg = "Error: " . $e->getMessage(); }
}

// Cargar Datos
try {
    // Empresa Padre
    $stmtC = $pdo->prepare("SELECT name FROM Company WHERE id = ?");
    $stmtC->execute([$companyId]);
    $company = $stmtC->fetch();
    if (!$company) { exit("Error: La empresa padre no existe."); }

    // Unidades y conteo de usuarios
    $stmt = $pdo->prepare("
        SELECT b.id, b.name, b.isActive, b.createdAt, COUNT(u.id) as usersCount 
        FROM BusinessUnit b
        LEFT JOIN User u ON b.id = u.businessUnitId
        WHERE b.companyId = ? 
        GROUP BY b.id
        ORDER BY b.createdAt DESC
    ");
    $stmt->execute([$companyId]);
    $units = $stmt->fetchAll();
    
    // Todos los usuarios formados para el select
    $stmtU = $pdo->prepare("SELECT id, name, businessUnitId FROM User WHERE companyId = ? ORDER BY name ASC");
    $stmtU->execute([$companyId]);
    $allUsers = $stmtU->fetchAll();
    
    // Todos los líderes asignados a BU de esta Company
    $stmtL = $pdo->prepare("SELECT businessUnitId, id, name FROM User WHERE role = 'BUSINESS_UNIT_LEADER' AND businessUnitId IS NOT NULL AND companyId = ?");
    $stmtL->execute([$companyId]);
    $rawLeaders = $stmtL->fetchAll();
    $buLeaders = [];
    foreach ($rawLeaders as $l) {
        $buLeaders[$l['businessUnitId']][] = $l;
    }

} catch (PDOException $e) {
    $dbError = "Error BD: " . $e->getMessage();
    $units = []; $allUsers = []; $buLeaders = [];
}
?>

<script>
    const allUsersDataBU = <?= json_encode($allUsers) ?>;
</script>

<a href="index.php?view=companies" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
    <i class='bx bx-left-arrow-alt'></i> Volver a Clientes
</a>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h2 style="display: flex; align-items: center; gap: 0.5rem;">Unidades de Negocio <span style="color: var(--text-muted); font-size: 1.2rem; font-weight: 500;">(<?= htmlspecialchars($company['name']) ?>)</span></h2>
        <p>Ramificaciones, sucursales o divisiones dependientes.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modalCreate')"><i class='bx bx-plus'></i> Agregar Unidad</button>
</div>

<?php if ($successMsg): ?><div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<?php if (isset($dbError)): ?><div class="alert alert-error"><?= htmlspecialchars($dbError) ?></div><?php endif; ?>

<main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
    <div style="background: white; overflow: hidden; border-radius: 24px;">
    <div class="table-responsive" style="margin: 0; border: none;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Nombre de División</th>
                    <th>Estado</th>
                    <th style="text-align: center;">Usuarios</th>
                    <th>Sub-Jefes Asignados</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($units) > 0): ?>
                    <?php foreach ($units as $unit): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                                    <i class='bx bx-store-alt' style="color: var(--primary);"></i>
                                    <?= htmlspecialchars($unit['name']) ?>
                                </div>
                            </td>
                            <td>
                                <?php if($unit['isActive']): ?>
                                    <span style="background: #dcfce7; color: #166534; padding: 0.25rem 0.5rem; border-radius: 999px; font-size: 0.75rem;">Activo</span>
                                <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 0.25rem 0.5rem; border-radius: 999px; font-size: 0.75rem;">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; justify-content: center; gap: 0.3rem; color: var(--text-muted); font-size: 0.9rem;">
                                    <?= htmlspecialchars($unit['usersCount']) ?> <i class='bx bx-group'></i>
                                </div>
                            </td>
                            <td>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                                    <?php if (!empty($buLeaders[$unit['id']])): ?>
                                        <?php foreach ($buLeaders[$unit['id']] as $ldr): ?>
                                            <div style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.8rem; display: flex; gap: 0.3rem; align-items: center;">
                                                <?= htmlspecialchars($ldr['name'] ?: 'Desconocido') ?>
                                                <form method="POST" style="margin:0; padding:0; display:inline;" onsubmit="return confirm('¿Quitar jefe operativo de esta unidad?');">
                                                    <input type="hidden" name="action" value="remove_leader">
                                                    <input type="hidden" name="user_id" value="<?= htmlspecialchars($ldr['id']) ?>">
                                                    <button type="submit" style="background:none; border:none; color: #ef4444; cursor:pointer; font-size:1rem; line-height:1;"><i class='bx bx-x'></i></button>
                                                </form>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">Sin jefes</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.3rem;">
                                <button class="btn" style="padding: 0.4rem; background: rgba(79, 70, 229, 0.1); color: var(--primary);" 
                                    onclick="openAssignModal('<?= htmlspecialchars($unit['id']) ?>', '<?= htmlspecialchars(addslashes($unit['name'])) ?>')" title="Asignar Jefe de Unidad">
                                    <i class='bx bx-user-plus'></i>
                                </button>
                                
                                <button class="btn" style="padding: 0.4rem; background: var(--bg-color); color: var(--text-muted);" 
                                    onclick="openEditModal('<?= htmlspecialchars($unit['id']) ?>', '<?= htmlspecialchars(addslashes($unit['name'])) ?>', <?= $unit['isActive'] ? 'true' : 'false' ?>)" title="Editar Unidad">
                                    <i class='bx bx-edit'></i>
                                </button>
                                
                                <!-- Botón Toggle Status (Quick Block) -->
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿<?= $unit['isActive'] ? 'Suspender el acceso al portal a todos los miembros de esta rama?' : 'Restaurar el acceso a esta rama?' ?>');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="bu_id" value="<?= htmlspecialchars($unit['id']) ?>">
                                    <input type="hidden" name="new_status" value="<?= $unit['isActive'] ? '0' : '1' ?>">
                                    <button type="submit" class="btn" style="padding: 0.4rem; background: <?= $unit['isActive'] ? '#fef2f2' : '#ecfdf5' ?>; border: 1px solid <?= $unit['isActive'] ? '#fca5a5' : '#6ee7b7' ?>; color: <?= $unit['isActive'] ? '#ef4444' : '#10b981' ?>;" title="<?= $unit['isActive'] ? 'Suspender Accesos' : 'Habilitar Accesos' ?>">
                                        <i class='bx <?= $unit['isActive'] ? 'bx-block' : 'bx-check-circle' ?>'></i>
                                    </button>
                                </form>
                                
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar permanente?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="bu_id" value="<?= htmlspecialchars($unit['id']) ?>">
                                    <button type="submit" class="btn" style="padding: 0.4rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;" title="Eliminar">
                                        <i class='bx bx-trash'></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 2rem;">Sin unidades de negocio.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</main>

<!-- ================= MODALES ================= -->

<!-- Modal: Asignar Líder -->
<div class="modal-overlay" id="modalAssignLeader">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Asignar Sub-Jefe Operativo</h3>
            <button class="modal-close" onclick="closeModal('modalAssignLeader')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_leader">
            <input type="hidden" name="bu_id" id="assign_bu_id" value="">
            
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                Designa a un empleado como administrador interno de <strong><span id="assign_bu_name"></span></strong>.
            </p>
            
            <div class="form-group">
                <label class="form-label">Usuario del Directorio General</label>
                <select name="user_id" id="assign_user_select_bu" class="form-control" required style="cursor: pointer;">
                    <option value="" disabled selected>-- Buscar Nombre... --</option>
                </select>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalAssignLeader')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Otorgar Accesos</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Crear BU -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Agregar Unidad de Negocio</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Nombre de Unidad</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Logo / Imagen (Opcional)</label>
                <input type="file" name="logo" class="form-control" accept="image/*">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="isActive" id="isActBU" checked style="width: 18px; height: 18px;">
                <label for="isActBU" class="form-label" style="margin: 0;">Unidad Activa</label>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Agregar Unidad</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar BU -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Editar Unidad</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="bu_id" id="edit_id" value="">
            <div class="form-group">
                <label class="form-label">Nombre de Unidad</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Reemplazar Logo / Imagen (Opcional)</label>
                <input type="file" name="logo" class="form-control" accept="image/*">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="isActive" id="edit_isActive" style="width: 18px; height: 18px;">
                <label for="edit_isActive" class="form-label" style="margin: 0;">Unidad Activa</label>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalEdit')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Actualizar Cambios</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    
    function openEditModal(id, name, isActive) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('edit_isActive').checked = isActive;
        openModal('modalEdit');
    }
    
    function openAssignModal(id, name) {
        document.getElementById('assign_bu_id').value = id;
        document.getElementById('assign_bu_name').innerText = name;
        
        let select = document.getElementById('assign_user_select_bu');
        select.innerHTML = '<option value="" disabled selected>-- Buscar Nombre... --</option>';
        let count = 0;
        allUsersDataBU.forEach(u => {
            if (u.businessUnitId === id) {
                let opt = document.createElement('option');
                opt.value = u.id;
                opt.innerText = u.name ? u.name : ('Desconocido (ID: ' + u.id + ')');
                select.appendChild(opt);
                count++;
            }
        });
        
        if (count === 0) {
            select.innerHTML = '<option value="" disabled selected>-- No hay estudiantes en esta unidad --</option>';
        }
        
        openModal('modalAssignLeader');
    }
</script>
