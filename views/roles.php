<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN'])) {
    echo "<h2>Acceso Denegado</h2><p>Solo los administradores globales pueden gestionar roles de entrenamiento.</p>";
    exit;
}

$successMsg = '';
$errorMsg = '';

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

// Procesar CRUD de Roles Formativos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            if ($name) {
                // Verificar nombre único
                $stmtCheck = $pdo->prepare("SELECT id FROM TrainingRole WHERE name = ?");
                $stmtCheck->execute([$name]);
                if ($stmtCheck->fetch()) {
                    $errorMsg = "Ya existe un rol con ese nombre.";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO TrainingRole (id, name, createdAt, updatedAt) VALUES (?, ?, NOW(), NOW())");
                    $stmt->execute([generateCuid(), $name]);
                    $successMsg = "Rol formativo creado correctamente.";
                }
            } else {
                $errorMsg = "El nombre del rol es obligatorio.";
            }
        } 
        elseif ($action === 'edit') {
            $id = $_POST['role_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            if ($id && $name) {
                $stmtCheck = $pdo->prepare("SELECT id FROM TrainingRole WHERE name = ? AND id != ?");
                $stmtCheck->execute([$name, $id]);
                if ($stmtCheck->fetch()) {
                    $errorMsg = "Ya existe un rol con ese nombre.";
                } else {
                    $stmt = $pdo->prepare("UPDATE TrainingRole SET name = ?, updatedAt = NOW() WHERE id = ?");
                    $stmt->execute([$name, $id]);
                    $successMsg = "Rol actualizado.";
                }
            }
        }
        elseif ($action === 'delete') {
            $id = $_POST['role_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM TrainingRole WHERE id = ?");
            $stmt->execute([$id]);
            $successMsg = "Rol eliminado correctamente.";
        }
    } catch (PDOException $e) {
        $errorMsg = "Error en base de datos: " . $e->getMessage();
    }

    require_once 'utils/assignment_sync.php';
    syncAllCourseAssignments($pdo);
}

// Cargar Roles y Métricas
try {
    // Left Join with Prisma's transparent Many-To-Many relationship table for explicit count
    $stmt = $pdo->query("
        SELECT r.id, r.name, r.createdAt, 
               COUNT(DISTINCT u.B) as userCount,
               COUNT(DISTINCT c.A) as courseCount
        FROM TrainingRole r
        LEFT JOIN _TrainingRoleToUser u ON r.id = u.A
        LEFT JOIN _CourseToTrainingRole c ON r.id = c.B
        GROUP BY r.id
        ORDER BY r.name ASC
    ");
    $roles = $stmt->fetchAll();
} catch (PDOException $e) {
    // Si la tabla de intersección no existe en este instante por fallos de sincronización con prisma, proveer un default preventivo
    try {
        $stmt = $pdo->query("SELECT id, name, createdAt, 0 as userCount, 0 as courseCount FROM TrainingRole ORDER BY name ASC");
        $roles = $stmt->fetchAll();
    } catch (PDOException $ex) {
        $dbError = "Error crítico de base de datos: " . $ex->getMessage();
        $roles = [];
    }
}
?>

<style>
.roles-page-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem; }
@media(max-width:768px) {
    .roles-page-header { flex-direction:column; align-items:flex-start; gap:1rem; }
    .roles-page-header .btn { width:100%; justify-content:center; }
    .roles-page-header p { font-size: 0.85rem; }
}
</style>

<div class="roles-page-header">
    <div>
        <h2>Roles de Entrenamiento</h2>
        <p>Los perfiles formativos controlan qué empleados tienen acceso a qué rutas o grupos de cursos cerrados.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modalCreate')" style="white-space:nowrap; display:inline-flex; align-items:center; gap:0.4rem; padding:0.6rem 1.2rem; border-radius:12px; font-weight:700; box-shadow:0 4px 14px rgba(255,106,0,0.35);">
        <i class='bx bx-plus'></i> Nuevo Rol
    </button>
</div>

<?php if ($successMsg): ?><div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?= htmlspecialchars($successMsg) ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errorMsg) ?></div><?php endif; ?>
<?php if (isset($dbError)): ?><div class="alert alert-error"><?= htmlspecialchars($dbError) ?></div><?php endif; ?>

<div class="card" style="padding: 0; overflow: visible;">
    <div class="table-responsive">
        <table class="data-table table-card-mode">
            <thead>
                <tr>
                    <th>Nombre del Perfil</th>
                    <th style="text-align: center;">Usuarios con Perfil</th>
                    <th style="text-align: center;">Cursos Asignados</th>
                    <th>Fecha de Creación</th>
                    <th style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($roles) > 0): ?>
                    <?php foreach ($roles as $role): ?>
                        <tr>
                            <td data-label="Perfil">
                                <div style="font-weight: 500; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                                    <i class='bx bx-briefcase-alt-2' style="color: var(--primary);"></i>
                                    <?= htmlspecialchars($role['name']) ?>
                                </div>
                            </td>
                            <td data-label="Usuarios">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 0.3rem; color: var(--text-muted); font-size: 0.9rem;">
                                    <?= htmlspecialchars($role['userCount']) ?> <i class='bx bx-group'></i>
                                </div>
                            </td>
                            <td data-label="Cursos">
                                <div style="display: flex; align-items: center; justify-content: center; gap: 0.3rem; color: var(--text-muted); font-size: 0.9rem;">
                                    <?= htmlspecialchars($role['courseCount']) ?> <i class='bx bx-book-open'></i>
                                </div>
                            </td>
                            <td data-label="Creado">
                                <?= date('d/M/Y', strtotime($role['createdAt'])) ?>
                            </td>
                            <td style="text-align: right; display: flex; justify-content: flex-end; gap: 0.3rem;">
                                <!-- Botón Editar -->
                                <button class="btn" style="padding: 0.4rem; background: var(--bg-color); color: var(--text-muted);" 
                                    onclick="openEditModal('<?= htmlspecialchars($role['id']) ?>', '<?= htmlspecialchars(addslashes($role['name'])) ?>')" title="Editar Nombre">
                                    <i class='bx bx-edit'></i>
                                </button>
                                
                                <!-- Botón Eliminar -->
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar permanente el Rol Formativo? Esto no borrará a los usuarios, simplemente cortará sus vínculos con los cursos pertenecientes a este rol.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="role_id" value="<?= htmlspecialchars($role['id']) ?>">
                                    <button type="submit" class="btn" style="padding: 0.4rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;" title="Eliminar Rol">
                                        <i class='bx bx-trash'></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 3rem; color: var(--text-muted);">No hay roles formativos definidos.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODALES ================= -->

<!-- Modal: Crear Rol -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Nuevo Rol de Entrenamiento</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Identificador del Rol</label>
                <input type="text" name="name" class="form-control" placeholder="Ej: Vendedores, Consultores HR..." required>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Crear Perfil</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Rol -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Editar Rol Formativo</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="role_id" id="edit_id" value="">
            <div class="form-group">
                <label class="form-label">Nombre del Rol</label>
                <input type="text" name="name" id="edit_name" class="form-control" required>
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
    
    function openEditModal(id, name) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        openModal('modalEdit');
    }
</script>
