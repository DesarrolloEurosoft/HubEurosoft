<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

// Verificar permisos de Super Admin
$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN'])) {
    echo "<h2>Acceso Denegado</h2><p>Solo los administradores globales pueden asignar líderes.</p>";
    exit;
}

$companyId = $_GET['company_id'] ?? null;
$buId = $_GET['bu_id'] ?? null;

if (!$companyId && !$buId) {
    echo "<h2>Error</h2><p>No se especificó la entidad objetivo.</p>";
    exit;
}

$successMsg = '';
$errorMsg = '';

// Determinar el contexto
$type = $buId ? 'bu' : 'company';
$targetId = $buId ?: $companyId;
$roleToAssign = $type === 'bu' ? 'BUSINESS_UNIT_LEADER' : 'COMPANY_LEADER';

// Procesar CRUD de Líderes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_leader') {
            $email = trim($_POST['email'] ?? '');
            
            if (empty($email)) {
                $errorMsg = "Debes proveer el correo electrónico del usuario.";
            } else {
                // Verificar si el usuario existe
                $stmtCheck = $pdo->prepare("SELECT id, role FROM User WHERE email = ?");
                $stmtCheck->execute([$email]);
                $userTarget = $stmtCheck->fetch();
                
                if (!$userTarget) {
                    $errorMsg = "No se encontró ningún usuario registrado con el correo '$email'.";
                } else {
                    // Actualizar el rol y el enlace organizacional
                    if ($type === 'company') {
                        $stmtUpdate = $pdo->prepare("UPDATE User SET role = ?, companyId = ? WHERE id = ?");
                        $stmtUpdate->execute([$roleToAssign, $targetId, $userTarget['id']]);
                    } else {
                        // Para BU, idealmente también guardamos el companyId del padre
                        $stmtBu = $pdo->prepare("SELECT companyId FROM BusinessUnit WHERE id = ?");
                        $stmtBu->execute([$targetId]);
                        $parentCoId = $stmtBu->fetchColumn();
                        
                        $stmtUpdate = $pdo->prepare("UPDATE User SET role = ?, businessUnitId = ?, companyId = ? WHERE id = ?");
                        $stmtUpdate->execute([$roleToAssign, $targetId, $parentCoId, $userTarget['id']]);
                    }
                    $successMsg = "Se ha asignado correctamente como líder a '$email'.";
                }
            }
        } 
        elseif ($action === 'remove_leader') {
            $id = $_POST['user_id'] ?? '';
            // Degradamos a STUDENT pero mantenemos su compañía y unidad intactas
            $stmt = $pdo->prepare("UPDATE User SET role = 'STUDENT' WHERE id = ?");
            $stmt->execute([$id]);
            $successMsg = "Se ha removido el rol de líder correctamente (el usuario se mantiene en su compañía).";
        }
    } catch (PDOException $e) {
        $errorMsg = "Error en base de datos: " . $e->getMessage();
    }
}

// Cargar Datos Contextuales
try {
    if ($type === 'company') {
        $stmtC = $pdo->prepare("SELECT name FROM Company WHERE id = ?");
        $stmtC->execute([$targetId]);
        $entityName = $stmtC->fetchColumn();
        
        $stmtL = $pdo->prepare("SELECT id, name, email, createdAt FROM User WHERE companyId = ? AND role = 'COMPANY_LEADER'");
        $stmtL->execute([$targetId]);
        $leaders = $stmtL->fetchAll();
        
        $backLink = "index.php?view=companies";
        $backText = "Volver a Clientes";
    } else {
        $stmtC = $pdo->prepare("SELECT name, companyId FROM BusinessUnit WHERE id = ?");
        $stmtC->execute([$targetId]);
        $buData = $stmtC->fetch();
        $entityName = $buData['name'] ?? 'Desconocida';
        
        $stmtL = $pdo->prepare("SELECT id, name, email, createdAt FROM User WHERE businessUnitId = ? AND role = 'BUSINESS_UNIT_LEADER'");
        $stmtL->execute([$targetId]);
        $leaders = $stmtL->fetchAll();
        
        $backLink = "index.php?view=business_units&company_id=" . urlencode($buData['companyId'] ?? '');
        $backText = "Volver a Unidades de Negocio";
    }
} catch (PDOException $e) {
    die("Error al consultar datos: " . $e->getMessage());
}
?>

<a href="<?php echo htmlspecialchars($backLink); ?>" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
    <i class='bx bx-left-arrow-alt'></i> <?php echo $backText; ?>
</a>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h2 style="display: flex; align-items: center; gap: 0.5rem;">
            Gestión de Líderes
        </h2>
        <p>Usuarios con privilegios administrativos sobre <strong><?php echo htmlspecialchars($entityName); ?></strong>.</p>
    </div>
    <button class="btn btn-primary" onclick="openModal('modalCreate')">
        <i class='bx bx-user-plus'></i> Asignar Líder
    </button>
</div>

<?php if ($successMsg): ?>
    <div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;"><?php echo htmlspecialchars($successMsg); ?></div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($errorMsg); ?></div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow: hidden;">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Líder</th>
                    <th>Correo Electrónico</th>
                    <th>Fecha de Nombramiento</th>
                    <th style="text-align: right;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($leaders) > 0): ?>
                    <?php foreach ($leaders as $leader): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500; color: var(--text-main); display: flex; align-items: center; gap: 0.5rem;">
                                    <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 0.8rem;">
                                        <?php echo substr($leader['name'] ?: 'A', 0, 1); ?>
                                    </div>
                                    <?php echo htmlspecialchars($leader['name'] ?: 'Sin Nombre'); ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($leader['email']); ?></td>
                            <td><?php echo date('d/M/Y', strtotime($leader['createdAt'])); ?></td>
                            <td style="text-align: right;">
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Estás seguro de quitarle el rango de líder a este usuario? Volverá a ser un estudiante normal.');">
                                    <input type="hidden" name="action" value="remove_leader">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($leader['id']); ?>">
                                    <button type="submit" class="btn" style="padding: 0.4rem; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca;" title="Revocar Privilegios">
                                        <i class='bx bx-user-minus'></i> Revocar
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 3rem; color: var(--text-muted);">
                            No hay líderes asignados actualmente a esta rama u organización.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ================= MODALES ================= -->

<div class="modal-overlay" id="modalCreate">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">Asignar Nuevo Líder</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add_leader">
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
                Ingresa el correo del usuario que asumirá la gerencia de <strong><?php echo htmlspecialchars($entityName); ?></strong>. El usuario debe estar previamente registrado en la plataforma.
            </p>
            <div class="form-group">
                <label class="form-label">Correo Electrónico del Usuario</label>
                <input type="email" name="email" class="form-control" placeholder="ejemplo@correo.com" required>
            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: var(--bg-color); color: var(--text-main);" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" class="btn btn-primary">Buscar y Asignar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
</script>
