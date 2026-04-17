<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'COMPANY_LEADER', 'BUSINESS_UNIT_LEADER'])) {
    echo "<h2>Acceso Denegado</h2><p>No tienes permisos.</p>";
    exit;
}

$successMsg = '';
$errorMsg = '';

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

// Procesar Formulario POST
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
                $stmt = $pdo->prepare("INSERT INTO Company (id, name, isActive, logoPath, updatedAt) VALUES (?, ?, ?, ?, NOW())");
                $stmt->execute([$cuid, $name, $isActive, $logoPath]);
                $successMsg = "Cliente creado exitosamente.";
            }
        } 
        elseif ($action === 'edit') {
            $id = $_POST['company_id'] ?? '';
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

                $stmt = $pdo->prepare("UPDATE Company SET name = ?, isActive = ?, updatedAt = NOW() $logoUpdateSql WHERE id = ?");
                $stmt->execute($params);
                $successMsg = "Cliente actualizado.";
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['company_id'] ?? '';
            $pdo->prepare("DELETE FROM User WHERE companyId = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM BusinessUnit WHERE companyId = ?")->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM Company WHERE id = ?");
            $stmt->execute([$id]);
            $successMsg = "El Cliente, sus Unidades de Negocio y sus Usuarios registrados han sido erradicados del sistema de forma permanente.";
        }
        elseif ($action === 'toggle_status') {
            $id = $_POST['company_id'] ?? '';
            $newStatus = $_POST['new_status'] === '1' ? 1 : 0;
            $stmt = $pdo->prepare("UPDATE Company SET isActive = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $successMsg = $newStatus ? "Acceso de la empresa restaurado." : "Acceso de la empresa suspendido bloqueando el login a sus usuarios.";
        }
        elseif ($action === 'assign_leader') {
            $companyId = $_POST['company_id'] ?? '';
            $userId = $_POST['user_id'] ?? '';
            if ($companyId && $userId) {
                $stmt = $pdo->prepare("UPDATE User SET role = 'COMPANY_LEADER', companyId = ? WHERE id = ?");
                $stmt->execute([$companyId, $userId]);
                $successMsg = "Líder asignado correctamente.";
            }
        }
        elseif ($action === 'remove_leader') {
            $userId = $_POST['user_id'] ?? '';
            if ($userId) {
                $stmt = $pdo->prepare("UPDATE User SET role = 'STUDENT' WHERE id = ?");
                $stmt->execute([$userId]);
                $successMsg = "Privilegios revocados.";
            }
        }
    } catch (PDOException $e) {
        $errorMsg = "Error: " . $e->getMessage();
    }
}

// Cargar Datos
try {
    // Clientes y conteo de Unidades de Negocio y Usuarios
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.isActive, c.createdAt, c.logoPath,
               COUNT(DISTINCT b.id) as unitsCount,
               COUNT(DISTINCT u.id) as usersCount
        FROM Company c
        LEFT JOIN BusinessUnit b ON c.id = b.companyId
        LEFT JOIN User u ON c.id = u.companyId
        GROUP BY c.id
        ORDER BY c.name ASC
    ");
    $companies = $stmt->fetchAll();
    
    // Todos los usuarios (para el select del Modal)
    $stmtU = $pdo->query("SELECT id, name, companyId FROM User ORDER BY name ASC");
    $allUsers = $stmtU->fetchAll();
    
    // Todos los líderes asignados a compañías agrupados
    $stmtL = $pdo->query("SELECT companyId, id, name FROM User WHERE role = 'COMPANY_LEADER' AND companyId IS NOT NULL");
    $rawLeaders = $stmtL->fetchAll();
    $companyLeaders = [];
    foreach ($rawLeaders as $l) {
        $companyLeaders[$l['companyId']][] = $l;
    }

} catch (PDOException $e) {
    $dbError = "Error al leer datos: " . $e->getMessage();
    $companies = []; $allUsers = []; $companyLeaders = [];
}
?>

<script>
    const allUsersData = <?= json_encode($allUsers) ?>;
</script>

<style>
    .company-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
    .company-card { background: var(--surface); border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow-md); padding: 1.5rem; display: flex; flex-direction: column; position: relative; }
    .cc-actions { display: flex; justify-content: space-between; gap: 0.5rem; margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1rem; }
    .cc-metrics { display: flex; gap: 1rem; margin-bottom: 1.5rem; }
    .cc-metric-box { flex: 1; background: var(--bg-color); border: 1px solid var(--border); border-radius: 12px; padding: 0.6rem; text-align: center; }
    .cc-actions .btn { display: flex; align-items: center; justify-content: center; padding: 0.4rem !important; transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s; }
    .cc-actions .btn:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); filter: brightness(1.1); }
    .cc-actions .btn i { font-size: 1.6rem; }
    
    .create-card { border: 2px dashed #cbd5e1 !important; background: transparent; cursor: pointer; justify-content: center; align-items: center; color: #64748b; transition: all 0.2s; min-height: 250px; }
    .create-card:hover { border-color: #6366f1 !important; color: #4f46e5; background: #eef2ff; transform: translateY(-3px); box-shadow: var(--shadow-md); }
    .create-card i { font-size: 3.5rem; margin-bottom: 0.5rem; transition: transform 0.2s; }
    .create-card:hover i { transform: scale(1.1); }
    .create-card span { font-size: 1.1rem; font-weight: 800; letter-spacing: -0.01em; }
</style>

<div style="width: 85%; max-width: 1920px; margin: 0 auto; padding: 1rem 0;">
<main style="background: white; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.03); overflow: hidden; border: 1px solid rgba(0,0,0,0.04); margin-bottom: 2rem;">
    <div class="company-grid" style="padding: 1.5rem; background: #f8fafc; margin-bottom: 0;">
        <!-- Add-Card para crear nuevo -->
    <div class="company-card create-card" onclick="openModal('modalCreate')">
        <i class='bx bx-plus'></i>
        <span>Agregar Nuevo</span>
    </div>

    <?php if (count($companies) > 0): ?>
        <?php foreach ($companies as $comp): ?>
            <div class="company-card">
                <!-- Header -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem;">
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <div style="width: 72px; height: 72px; border-radius: 12px; background: rgba(59, 130, 246, 0.1); color: #3b82f6; display: flex; justify-content: center; align-items: center; font-size: 2.2rem; overflow: hidden; flex-shrink: 0; border: 1px solid var(--border);">
                            <?php if (!empty($comp['logoPath'])): ?>
                                <img src="<?= htmlspecialchars(ltrim($comp['logoPath'], '/')) ?>" alt="Logo" style="width: 100%; height: 100%; object-fit: contain; padding: 2px;">
                            <?php else: ?>
                                <i class='bx bx-building'></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h3 style="margin: 0; font-size: 1.35rem; font-weight: 800; color: var(--text-main); line-height: 1.2;"><?= htmlspecialchars($comp['name']) ?></h3>
                        </div>
                    </div>
                    <div>
                        <?php if($comp['isActive']): ?>
                            <span style="background: #dcfce7; color: #166534; padding: 0.3rem 0.8rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">Activo</span>
                        <?php else: ?>
                            <span style="background: #fee2e2; color: #991b1b; padding: 0.3rem 0.8rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700;">Inactivo</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metrics -->
                <div class="cc-metrics" style="margin-bottom: 1rem;">
                    <div class="cc-metric-box">
                        <div style="font-size: 1.25rem; font-weight: 900; color: var(--text-main); line-height: 1; margin-bottom: 0.3rem;"><?= htmlspecialchars($comp['unitsCount']) ?></div>
                        <div style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Unidades</div>
                    </div>
                    <div class="cc-metric-box">
                        <div style="font-size: 1.25rem; font-weight: 900; color: var(--text-main); line-height: 1; margin-bottom: 0.3rem;"><?= htmlspecialchars($comp['usersCount']) ?></div>
                        <div style="font-size: 0.65rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Usuarios</div>
                    </div>
                </div>

                <!-- Leaders -->
                <div style="flex-grow: 1;">
                    <p style="font-size: 0.75rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem;">Directivos Asignados:</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                        <?php if (!empty($companyLeaders[$comp['id']])): ?>
                            <?php foreach ($companyLeaders[$comp['id']] as $ldr): ?>
                                <div style="background: rgba(79, 70, 229, 0.1); color: var(--primary); padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 0.4rem;">
                                    <?= htmlspecialchars($ldr['name'] ?: 'Desconocido') ?>
                                    <form method="POST" style="margin:0; padding:0; display:inline;" onsubmit="return confirm('¿Quitar rol de líder a este usuario?');">
                                        <input type="hidden" name="action" value="remove_leader">
                                        <input type="hidden" name="user_id" value="<?= htmlspecialchars($ldr['id']) ?>">
                                        <button type="submit" style="background:none; border:none; color: #ef4444; cursor:pointer; font-size:1.2rem; line-height:1; display:flex; align-items:center;"><i class='bx bx-x'></i></button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.85rem; font-style: italic;">Sin líderes asignados</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Actions -->
                <div class="cc-actions">
                    <button class="btn" style="flex: 1; padding: 0.6rem; background: #00087F; border: 1px solid #00087F; color: white;" 
                        onclick="openAssignModal('<?= htmlspecialchars($comp['id']) ?>', '<?= htmlspecialchars(addslashes($comp['name'])) ?>')" title="Asignar Líder">
                        <i class='bx bx-user-plus'></i>
                    </button>
                    <a href="index.php?view=business_units&company_id=<?= urlencode($comp['id']) ?>" class="btn" style="flex: 1; padding: 0.6rem; background: #00087F; border: 1px solid #00087F; color: white;" title="Ver Unidades">
                        <i class='bx bx-sitemap'></i>
                    </a>
                    <button class="btn" style="flex: 1; padding: 0.6rem; background: #00087F; border: 1px solid #00087F; color: white;" 
                        onclick="openEditModal('<?= htmlspecialchars($comp['id']) ?>', '<?= htmlspecialchars(addslashes($comp['name'])) ?>', <?= $comp['isActive'] ? 'true' : 'false' ?>)" title="Editar Cliente">
                        <i class='bx bx-edit'></i>
                    </button>
                    
                    <form method="POST" style="flex: 1; display: flex;" onsubmit="return confirm('¿<?= $comp['isActive'] ? 'Suspender accesos de esta empresa?' : 'Restaurar accesos?' ?>');">
                        <input type="hidden" name="action" value="toggle_status">
                        <input type="hidden" name="company_id" value="<?= htmlspecialchars($comp['id']) ?>">
                        <input type="hidden" name="new_status" value="<?= $comp['isActive'] ? '0' : '1' ?>">
                        <button type="submit" class="btn" style="width: 100%; padding: 0.6rem; background: <?= $comp['isActive'] ? 'var(--primary)' : '#00087F' ?>; border: 1px solid <?= $comp['isActive'] ? 'var(--primary)' : '#00087F' ?>; color: white;" title="Cambiar Estado">
                            <i class='bx <?= $comp['isActive'] ? 'bx-block' : 'bx-check-circle' ?>'></i>
                        </button>
                    </form>

                    <form method="POST" style="flex: 1; display: flex;" onsubmit="return confirm('⚠️ ¡ALERTA CRÍTICA! ¿Estás absolutamente seguro de eliminar esta Compañía? Esto también borrará TODAS sus Unidades de Negocio y TODOS sus Usuarios de forma irreversible.');">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="company_id" value="<?= htmlspecialchars($comp['id']) ?>">
                        <button type="submit" class="btn" style="width: 100%; padding: 0.6rem; background: #ef4444; border: 1px solid #ef4444; color: white;" title="Borrar Permanente">
                            <i class='bx bx-trash'></i>
                        </button>
                    </form>
                </div>

            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<!-- ================= MODALES ================= -->

<!-- Modal: Asignar Líder -->
<div class="modal-overlay" id="modalAssignLeader">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Asignar Líder de Compañía</h3>
            <button class="modal-close" onclick="closeModal('modalAssignLeader')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_leader">
            <input type="hidden" name="company_id" id="assign_company_id" value="">
            
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                Selecciona al usuario que administrará <strong><span id="assign_company_name"></span></strong>.
            </p>
            
            <div class="form-group">
                <label class="form-label">Usuario / Estudiante Registrado</label>
                <select name="user_id" id="assign_user_select" class="form-control" required style="cursor: pointer;">
                    <option value="" disabled selected>-- Seleccione un Nombre --</option>
                </select>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalAssignLeader')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Asignar Privilegios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Crear Empresa -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Registrar Nuevo Cliente</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Nombre de la Empresa</label>
                <input type="text" name="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Logotipo (Opcional)</label>
                <input type="file" name="logo" class="form-control" accept="image/*" style="padding: 0.5rem;">
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="isActive" id="isAct" checked style="width: 18px; height: 18px;">
                <label for="isAct" class="form-label" style="margin: 0; cursor: pointer;">Cliente Activo (Permitir accesos)</label>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar Cliente</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Empresa -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Editar Cliente</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="company_id" id="edit_id" value="">
            <div class="form-group">
                <label class="form-label">Nombre de la Empresa</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label class="form-label">Reemplazar Logotipo (Opcional)</label>
                <input type="file" name="logo" class="form-control" accept="image/*" style="padding: 0.5rem;">
                <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.3rem;">Dejar en blanco para mantener la imagen actual.</p>
            </div>
            <div class="form-group" style="display: flex; align-items: center; gap: 0.5rem;">
                <input type="checkbox" name="isActive" id="edit_isActive" style="width: 18px; height: 18px;">
                <label for="edit_isActive" class="form-label" style="margin: 0; cursor: pointer;">Cliente Activo</label>
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
        document.getElementById('assign_company_id').value = id;
        document.getElementById('assign_company_name').innerText = name;
        
        let select = document.getElementById('assign_user_select');
        select.innerHTML = '<option value="" disabled selected>-- Seleccione un Nombre --</option>';
        let count = 0;
        allUsersData.forEach(u => {
            if (u.companyId === id) {
                let opt = document.createElement('option');
                opt.value = u.id;
                opt.innerText = u.name ? u.name : ('Desconocido (ID: ' + u.id + ')');
                select.appendChild(opt);
                count++;
            }
        });
        
        if (count === 0) {
            select.innerHTML = '<option value="" disabled selected>-- No hay estudiantes en esta compañía --</option>';
        }
        
        openModal('modalAssignLeader');
    }
</script>
