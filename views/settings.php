<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }
require_once __DIR__ . '/settings_student_v3.php';

if (strtoupper($_SESSION['user_role'] ?? '') === 'STUDENT') { return; }
$userId = $_SESSION['user_id'] ?? null;

try {
    $stmt = $pdo->prepare("SELECT * FROM User WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    $user = null;
    $dbError = "Error al cargar el perfil.";
}
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <div>
        <h2>Configuración de Cuenta</h2>
        <p>Gestiona tu perfil personal y preferencias del sistema.</p>
    </div>
    <button class="btn btn-primary">Guardar Cambios</button>
</div>

<div style="display: flex; gap: 2rem; flex-wrap: wrap;">
    
    <!-- Columna Izquierda: Perfil -->
    <div style="flex: 1; min-width: 300px;">
        <div class="card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Información Personal</h3>
            
            <div style="display: flex; align-items: center; gap: 1.5rem; margin-bottom: 2rem;">
                <div style="width: 80px; height: 80px; border-radius: 50%; background-color: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 600;">
                    <?php echo substr($user['name'] ?? 'A', 0, 1); ?>
                </div>
                <div>
                    <button class="btn" style="background: white; border: 1px solid var(--border); margin-bottom: 0.5rem;">Cambiar Avatar</button>
                    <p style="font-size: 0.8rem; color: var(--text-muted);">JPG o PNG. Máx 2MB.</p>
                </div>
            </div>

            <form>
                <div class="form-group">
                    <label class="form-label">Nombre Completo</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Correo Electrónico</label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" readonly style="background-color: var(--bg-color); cursor: not-allowed;">
                </div>
                <div class="form-group">
                    <label class="form-label">Rol Asignado</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['role'] ?? ''); ?>" readonly style="background-color: var(--bg-color); cursor: not-allowed;">
                </div>
            </form>
        </div>
    </div>

    <!-- Columna Derecha: Seguridad y Sistema -->
    <div style="flex: 1; min-width: 300px;">
        <div class="card" style="margin-bottom: 2rem;">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Seguridad</h3>
            <form>
                <div class="form-group">
                    <label class="form-label">Contraseña Actual</label>
                    <input type="password" class="form-control" placeholder="••••••••">
                </div>
                <div class="form-group">
                    <label class="form-label">Nueva Contraseña</label>
                    <input type="password" class="form-control" placeholder="Ingresa nueva contraseña">
                </div>
                <button class="btn btn-primary" style="margin-top: 0.5rem;">Actualizar Contraseña</button>
            </form>
        </div>
        
        <div class="card">
            <h3 style="margin-bottom: 1.5rem; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;">Preferencias</h3>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <div>
                    <h4 style="font-size: 0.95rem; margin-bottom: 0.2rem;">Notificaciones por Email</h4>
                    <p style="font-size: 0.8rem; color: var(--text-muted);">Recibir avisos de nuevos cursos disponibles.</p>
                </div>
                <input type="checkbox" checked style="width: 20px; height: 20px;">
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h4 style="font-size: 0.95rem; margin-bottom: 0.2rem;">Tema Oscuro</h4>
                    <p style="font-size: 0.8rem; color: var(--text-muted);">Aplica un diseño de alto contraste a la plataforma.</p>
                </div>
                <input type="checkbox" style="width: 20px; height: 20px;">
            </div>
        </div>
    </div>

</div>
