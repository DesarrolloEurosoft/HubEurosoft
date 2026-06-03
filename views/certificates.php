<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }
require_once __DIR__ . '/certificates_student_v3.php';

// Control de Acceso
$userRole = strtoupper($_SESSION['user_role'] ?? '');
if ($userRole === 'STUDENT') { return; }
if (!in_array($userRole, ['ADMIN', 'INSTRUCTOR'])) {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes.</p>";
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

if (!function_exists('generateUuid')) {
    function generateUuid() {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000, mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

$successMsg = '';
$errorMsg = '';
$dbError = null;

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_cert') {
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $img = trim($_POST['imageUrl'] ?? '');

            if ($name) {
                $stmt = $pdo->prepare("INSERT INTO Certificate (id, name, description, imageUrl, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
                if ($stmt->execute([generateCuid(), $name, $desc, $img])) {
                    $successMsg = "Certificado creado satisfactoriamente.";
                } else {
                    $errorMsg = "Error al crear certificado.";
                }
            } else {
                $errorMsg = "El nombre es obligatorio.";
            }
        }

        if ($action === 'edit_cert') {
            $id = $_POST['cert_id'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $img = trim($_POST['imageUrl'] ?? '');

            if ($id && $name) {
                $stmt = $pdo->prepare("UPDATE Certificate SET name = ?, description = ?, imageUrl = ?, updatedAt = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $desc, $img, $id])) {
                    $successMsg = "Certificado modificado exitosamente.";
                } else {
                    $errorMsg = "Error al actualizar.";
                }
            }
        }

        if ($action === 'delete_cert') {
            $id = $_POST['cert_id'] ?? '';
            if ($id) {
                $stmt = $pdo->prepare("DELETE FROM Certificate WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $successMsg = "El certificado fue eliminado y desligado de cualquier curso exitosamente.";
                }
            }
        }

        if ($action === 'issue_manual_cert') {
            $userId = $_POST['user_id'] ?? '';
            $certId = $_POST['cert_id'] ?? '';
            $courseIdInput = $_POST['course_id'] ?? '';
            
            // Permitir que courseId sea nulo o esté vacío
            $cId = !empty($courseIdInput) ? $courseIdInput : null;
            
            if ($userId && $certId) {
                // Verificar si ya tiene el certificado emitido para ese curso
                // Nota: la Constraint es UNIQUE(userId, certificateId, courseId), si courseId es null puede crear múltiples, o fallará si sqlite no permite múltiples nulls en constraints compuestas dependiendo de la bd.
                // Lo mejor es buscar si ya existe:
                
                if ($cId) {
                    $check = $pdo->prepare("SELECT id FROM usercertificate WHERE userId = ? AND certificateId = ? AND courseId = ?");
                    $check->execute([$userId, $certId, $cId]);
                } else {
                    $check = $pdo->prepare("SELECT id FROM usercertificate WHERE userId = ? AND certificateId = ? AND courseId IS NULL");
                    $check->execute([$userId, $certId]);
                }
                
                if ($check->rowCount() > 0) {
                    $errorMsg = "El usuario ya cuenta con este certificado.";
                } else {
                    $newId = generateCuid();
                    $code = strtoupper(substr(generateUuid(), 0, 8) . '-' . substr(generateUuid(), 9, 4) . '-' . substr(generateUuid(), 24, 12)); 
                    // código de 26 chars o un uuid normal. Lo dejaremos como UUID v4.
                    $code = generateUuid();
                    
                    $stmt = $pdo->prepare("INSERT INTO usercertificate (id, userId, certificateId, courseId, issuedAt, verificationCode) VALUES (?, ?, ?, ?, NOW(), ?)");
                    if ($stmt->execute([$newId, $userId, $certId, $cId, $code])) {
                        
                        // Añadir bonificación de XP a gamification (Optional, Node.js lo hace via triggers o logic en submit)
                        try {
                            // Buscar regla
                            $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'CERTIFICATE_EARNED' AND isActive = 1")->fetch();
                            if ($rule && $rule['points'] > 0) {
                                $pts = (int)$rule['points'];
                                $pdo->prepare("UPDATE User SET totalPoints = totalPoints + ? WHERE id = ?")->execute([$pts, $userId]);
                                $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'CERTIFICATE_EARNED', 'Certificado Emitido Manualmente', NOW())")
                                    ->execute([generateCuid(), $userId, $pts]);
                            }
                        } catch(Exception $e) {} 
                        
                        $_SESSION['issue_code'] = $code; // Guardar temporalmente para mostrar el botón
                        $successMsg = "Se ha emitido el certificado manual con éxito. Código: $code";
                    } else {
                        $errorMsg = "Error al insertar certificado en la base de datos.";
                    }
                }
            } else {
                $errorMsg = "Faltan datos de usuario o certificado.";
            }
        }

    } catch (PDOException $e) {
        $errorMsg = "Error de DB SQL: " . $e->getMessage();
    }
}

// Loads
try {
    // Info de todos los certificados pre-cargados
    $stmtCerts = $pdo->query("
        SELECT c.id, c.name, c.description, c.imageUrl, c.createdAt,
               (SELECT COUNT(co.id) FROM Course co WHERE co.certificateId = c.id) as courseCount,
               (SELECT COUNT(uc.id) FROM usercertificate uc WHERE uc.certificateId = c.id) as issuedCount
        FROM Certificate c
        ORDER BY c.createdAt DESC
    ");
    $certs = $stmtCerts->fetchAll();

    // Usuarios para emisión manual
    $stmtUsers = $pdo->query("SELECT id, name, email FROM User ORDER BY name ASC LIMIT 500");
    $users = $stmtUsers->fetchAll();
    
    // Cursos para emisión manual
    $stmtCourses = $pdo->query("SELECT id, title, certificateId FROM Course ORDER BY title ASC");
    $courses = $stmtCourses->fetchAll();

} catch (PDOException $e) {
    $dbError = "Error al leer certificados: " . $e->getMessage();
    $certs = [];
}
?>

<style>
/* CSS Moderno Específico para Certificates */
.c-page { max-width: 1400px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.4s ease-out; }
.c-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 2rem; border-bottom: 1px solid #e2e8f0; padding-bottom: 1.5rem; }
.c-title { font-size: 2rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem 0;}
.c-subtitle { font-size: 1rem; color: #64748b; margin:0; }

.c-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 1.5rem; }
.c-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 2rem 1.5rem 1.5rem; text-align: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s; position:relative; overflow:hidden;}
.c-card:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); }
.c-card::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 6px; background: linear-gradient(to right, #f59e0b, #eab308); }

.c-image-wrap { width: 80px; height: 80px; border-radius: 16px; margin: 0 auto 1.5rem; background: #fffbeb; color: #d97706; display: flex; justify-content: center; align-items: center; border: 1px solid #fef3c7; box-shadow: 0 2px 4px rgba(0,0,0,0.03); overflow:hidden;}
.c-image-wrap img { width: 100%; height: 100%; object-fit: contain; padding: 5px;}
.c-icon { font-size: 2.5rem; }

.c-name { font-size: 1.15rem; font-weight: 800; color: #0f172a; margin: 0 0 0.5rem 0; line-height: 1.3;}
.c-desc { font-size: 0.85rem; color: #64748b; margin: 0 0 1.5rem 0; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; min-height: 36px;}
.c-divider { height: 1px; background: #f1f5f9; margin-bottom: 1.5rem; width: 100%; }

.c-stats { display: flex; justify-content: center; gap: 1.5rem; margin-bottom: 1.5rem;}
.c-stat-item { text-align: center; }
.c-stat-val { font-size: 1.25rem; font-weight: 900; color: #4f46e5; display:block; line-height:1;}
.c-stat-lbl { font-size: 0.65rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; margin-top: 0.3rem; display:block;}

.c-footer { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 0.5rem;}
.btn-c-edit { flex: 1; border-radius: 8px; font-weight: 600; font-size: 0.85rem; padding: 0.5rem 1rem; border: 1px solid #e2e8f0; background: #f8fafc; color: #475569; display: flex; align-items: center; justify-content: center; gap: 0.4rem; cursor: pointer; transition: all 0.2s;}
.btn-c-edit:hover { background: #e0e7ff; color: #4f46e5; border-color: #c7d2fe; }

.btn-del-icon { width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #94a3b8; border: none; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
.btn-del-icon:hover { background: #fee2e2; color: #ef4444; }

.btn-mock { background: linear-gradient(to right, #4f46e5, #4338ca); color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 12px; font-weight: 700; cursor: pointer; display:flex; align-items:center; gap:0.4rem; transition: transform 0.2s;}
.btn-mock:hover { transform: scale(1.02); }

.btn-orange { background: linear-gradient(to right, #f97316, #ea580c); color: white; border: none; padding: 0.6rem 1.2rem; border-radius: 12px; font-weight: 700; cursor: pointer; display:flex; align-items:center; gap:0.4rem; transition: transform 0.2s;}
.btn-orange:hover { transform: scale(1.02); }

@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

@media(max-width: 768px) {
    .c-page  { padding: 1rem; padding-bottom: 6rem; }
    .c-header { flex-direction: column; align-items: flex-start; gap: 1rem; }
    .c-header > div:last-child { width: 100%; display: flex; flex-direction: column; gap: 0.75rem; }
    .c-header > div:last-child .btn-mock,
    .c-header > div:last-child .btn-orange { width: 100%; justify-content: center; box-sizing: border-box; }
    .c-grid  { grid-template-columns: 1fr; }
    .c-title { font-size: 1.5rem; }
}
</style>

<div class="c-page">
    <div class="c-header">
        <div>
            <h1 class="c-title">🏅 Diplomas y Certificados</h1>
            <p class="c-subtitle">Crea las plantillas visuales e insignias oficiales que obtendrán tus estudiantes.</p>
        </div>
        <div style="display:flex; gap:1rem;">
            <button class="btn-mock" onclick="openModal('modalIssue')"><i class='bx bx-paper-plane'></i> Emisión Manual</button>
            <button class="btn-orange" onclick="openModal('modalCreate')"><i class='bx bx-plus'></i> Nuevo Diploma</button>
        </div>
    </div>

    <?php if ($successMsg): ?>
        <div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; margin-bottom: 2rem;">
            <?php echo htmlspecialchars($successMsg); ?>
            <?php if (isset($_SESSION['issue_code'])): ?>
                <a href="cert.php?code=<?php echo urlencode($_SESSION['issue_code']); ?>" target="_blank" style="margin-left:1rem; display:inline-flex; align-items:center; gap:4px; background:#fff; color:#166534; padding:2px 8px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.8rem; border:1px solid #bbf7d0;">
                    <i class='bx bx-link-external'></i> Ver Diploma
                </a>
            <?php unset($_SESSION['issue_code']); endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($errorMsg): ?><div class="alert alert-error" style="margin-bottom: 2rem;"><?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>
    <?php if (isset($dbError)): ?><div class="alert alert-error" style="margin-bottom: 2rem;"><?php echo htmlspecialchars($dbError); ?></div><?php endif; ?>

    <?php if (count($certs) > 0): ?>
        <div class="c-grid">
            <?php foreach ($certs as $cert): ?>
                <div class="c-card">
                    <div class="c-image-wrap">
                        <?php if ($cert['imageUrl']): ?>
                            <img src="<?php echo htmlspecialchars($cert['imageUrl']); ?>" alt="Medalla" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                            <i class='bx bx-award c-icon' style="display:none;"></i>
                        <?php else: ?>
                            <i class='bx bx-award c-icon'></i>
                        <?php endif; ?>
                    </div>

                    <h3 class="c-name"><?php echo htmlspecialchars($cert['name']); ?></h3>
                    <p class="c-desc" title="<?php echo htmlspecialchars($cert['description'] ?: ''); ?>">
                        <?php echo htmlspecialchars($cert['description'] ?: 'Sin descripción detallada configurada en el sistema.'); ?>
                    </p>
                    
                    <div class="c-stats">
                        <div class="c-stat-item">
                            <span class="c-stat-val"><?php echo (int)$cert['courseCount']; ?></span>
                            <span class="c-stat-lbl">Cursos</span>
                        </div>
                        <div class="c-stat-item">
                            <span class="c-stat-val" style="color: #ea580c;"><?php echo (int)$cert['issuedCount']; ?></span>
                            <span class="c-stat-lbl">Emitidos</span>
                        </div>
                    </div>

                    <div class="c-divider"></div>
                    
                    <div class="c-footer">
                        <!-- Botón Editar -->
                        <div id="data_c_<?php echo $cert['id']; ?>" 
                             data-id="<?php echo htmlspecialchars($cert['id']); ?>" 
                             data-name="<?php echo htmlspecialchars($cert['name']); ?>" 
                             data-desc="<?php echo htmlspecialchars($cert['description']); ?>" 
                             data-img="<?php echo htmlspecialchars($cert['imageUrl']); ?>"></div>
                             
                        <button class="btn-c-edit" onclick="openEditCert(document.getElementById('data_c_<?php echo $cert['id']; ?>').dataset)">
                            <i class='bx bx-edit-alt'></i> Detalle y Edición
                        </button>

                        <!-- Botón Borrar -->
                        <form method="POST" style="margin:0;" onsubmit="return confirm('¿Borrar definitivamente este Certificado? Esto lo removerá de las configuraciones de los Cursos asociados, pero se mantendrá válido para quienes ya lo hayan obtenido antes.');">
                            <input type="hidden" name="action" value="delete_cert">
                            <input type="hidden" name="cert_id" value="<?php echo htmlspecialchars($cert['id']); ?>">
                            <button type="submit" class="btn-del-icon" title="Eliminar Medalla"><i class='bx bx-trash'></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="background: #fff; padding: 4rem 2rem; text-align: center; border-radius: 20px; border: 2px dashed #e2e8f0;">
            <i class='bx bx-certification' style="font-size: 5rem; color: #cbd5e1; margin-bottom: 1rem; display:block;"></i>
            <h3 style="font-size: 1.5rem; color: #475569; margin-bottom: 0.5rem;">Crea tu primer Certificado</h3>
            <p style="color: #94a3b8; max-width: 400px; margin: 0 auto; line-height:1.6;">Todavía no hay diplomas registrados. Agrega uno nuevo para iniciar la validación y emisión automática en base a las reglas del LMS.</p>
        </div>
    <?php endif; ?>

</div>

<!-- MODAL CREAR -->
<div class="modal-overlay" id="modalCreate">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">🏅 Nuevo Certificado Oficial</h3>
            <button class="modal-close" onclick="closeModal('modalCreate')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_cert">
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">Nombre Comercial del Diploma *</label>
                <input type="text" name="name" class="form-control bg-gray-50" required placeholder="Ej: Especialista en Alta Gerencia">
            </div>
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">Descripción (Opcional)</label>
                <textarea name="description" class="form-control bg-gray-50" rows="3" placeholder="Contexto sobre las habilidades adquiridas o de qué trata."></textarea>
            </div>
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">URL del Logotipo / Sello (Opcional)</label>
                <input type="url" name="imageUrl" class="form-control bg-gray-50 text-sm" placeholder="https://mi-servidor.com/insignia.png">
                <small class="text-muted" style="display:block; margin-top:8px;">Este escudo aparecerá impreso gráficamente en la pantalla de verificación del alumno.</small>
            </div>

            <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalCreate')">Cancelar</button>
                <button type="submit" class="btn btn-orange">Guardar Diploma</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EDITAR -->
<div class="modal-overlay" id="modalEdit">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 class="modal-title">✏️ Modificar Certificado</h3>
            <button class="modal-close" onclick="closeModal('modalEdit')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_cert">
            <input type="hidden" name="cert_id" id="edit_cert_id">
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">Nombre Comercial del Diploma *</label>
                <input type="text" name="name" id="edit_cert_name" class="form-control bg-gray-50" required>
            </div>
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">Descripción (Opcional)</label>
                <textarea name="description" id="edit_cert_desc" class="form-control bg-gray-50" rows="3"></textarea>
            </div>
            
            <div class="form-group pb-2">
                <label class="form-label" style="font-weight:700;">URL del Logotipo / Sello (Opcional)</label>
                <input type="text" name="imageUrl" id="edit_cert_img" class="form-control bg-gray-50 text-sm">
            </div>

            <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalEdit')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="background-color: #4f46e5;">Actualizar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL EMISIÓN MANUAL (MOCK / ISSUE DIRECTO) -->
<div class="modal-overlay" id="modalIssue">
    <div class="modal-content" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title" style="color: #4f46e5;"><i class='bx bx-paper-plane'></i> Emisión Directa</h3>
            <button class="modal-close" onclick="closeModal('modalIssue')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="issue_manual_cert">
            
            <p class="text-sm text-gray-500 mb-4" style="line-height:1.5; margin-bottom: 20px;">
                Fuerza la generación de una credencial para un usuario específico sin que deba completar el curso de manera habitual. Sirve para homologaciones manuales o pruebas del sistema. Se le otorgarán los Puntos XP si están configurados.
            </p>

            <div class="form-group pb-3">
                <label class="form-label" style="font-weight:700;">Estudiante / Usuario *</label>
                <select name="user_id" class="form-control bg-gray-50" required>
                    <option value="">-- Buscar un usuario --</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['id']); ?>"><?php echo htmlspecialchars($u['name'] . ' (' . $u['email'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group pb-3">
                <label class="form-label" style="font-weight:700;">Plantilla a Emitir *</label>
                <select name="cert_id" class="form-control bg-gray-50" required>
                    <option value="">-- Elige el Certificado --</option>
                    <?php foreach ($certs as $c): ?>
                        <option value="<?php echo htmlspecialchars($c['id']); ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group pb-3">
                <label class="form-label" style="font-weight:700;">Vincular al Módulo/Curso (Opcional)</label>
                <select name="course_id" class="form-control bg-gray-50">
                    <option value="">Sin vinculación a curso</option>
                    <?php foreach ($courses as $co): ?>
                        <option value="<?php echo htmlspecialchars($co['id']); ?>"><?php echo htmlspecialchars($co['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn btn-outline" onclick="closeModal('modalIssue')">Cancelar Operación</button>
                <button type="submit" class="btn" style="background: linear-gradient(to right, #4f46e5, #4338ca); color: white; font-weight:700;">¡Emitir Credencial Oficial!</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    
    function openEditCert(data) {
        document.getElementById('edit_cert_id').value = data.id || '';
        document.getElementById('edit_cert_name').value = data.name || '';
        document.getElementById('edit_cert_desc').value = data.desc || '';
        document.getElementById('edit_cert_img').value = data.img || '';
        openModal('modalEdit');
    }
</script>
