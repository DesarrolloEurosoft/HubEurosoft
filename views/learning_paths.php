<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userRole = strtoupper($_SESSION['user_role'] ?? '');
if (!in_array($userRole, ['ADMIN', 'INSTRUCTOR'])) {
    echo "<h2>Acceso Denegado</h2><p>Privilegios insuficientes.</p>";
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

$successMsg = '';
$errorMsg = '';

// Procesamiento de Formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_learning_path') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        if ($name) {
            $newId = generateCuid();
            $stmt = $pdo->prepare("INSERT INTO LearningPath (id, name, description, createdAt, updatedAt) VALUES (?, ?, ?, NOW(), NOW())");
            if ($stmt->execute([$newId, $name, $desc])) {
                $successMsg = "Ruta de aprendizaje creada.";
            } else {
                $errorMsg = "Error al crear la ruta.";
            }
        } else {
            $errorMsg = "El título es obligatorio.";
        }
    }

    if ($action === 'edit_learning_path') {
        $lpId = $_POST['learning_path_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        
        $roleIds = $_POST['roleIds'] ?? [];
        $courseIds = $_POST['courseIds'] ?? [];
        $courseOrders = $_POST['courseOrders'] ?? [];

        if ($lpId && $name) {
            $pdo->beginTransaction();
            try {
                // Actualizar Info Básica
                $stmt = $pdo->prepare("UPDATE LearningPath SET name = ?, description = ?, updatedAt = NOW() WHERE id = ?");
                $stmt->execute([$name, $desc, $lpId]);

                // Actualizar Roles Implícitos
                $pdo->prepare("DELETE FROM _LearningPathToTrainingRole WHERE A = ?")->execute([$lpId]);
                if (!empty($roleIds)) {
                    $sRoles = $pdo->prepare("INSERT INTO _LearningPathToTrainingRole (A, B) VALUES (?, ?)");
                    foreach ($roleIds as $rId) { $sRoles->execute([$lpId, $rId]); }
                }

                // Actualizar Secuencia de Cursos Explícita
                $pdo->prepare("DELETE FROM LearningPathCourse WHERE learningPathId = ?")->execute([$lpId]);
                if (!empty($courseIds)) {
                    $sCourses = $pdo->prepare("INSERT INTO LearningPathCourse (id, learningPathId, courseId, `order`, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    foreach ($courseIds as $cId) {
                        $cOrder = isset($courseOrders[$cId]) && $courseOrders[$cId] !== '' ? (int)$courseOrders[$cId] : 0;
                        $sCourses->execute([generateCuid(), $lpId, $cId, $cOrder]);
                    }
                }

                $pdo->commit();
                $successMsg = "Ruta académica actualizada correctamente.";
            } catch (Exception $e) {
                $pdo->rollBack();
                $errorMsg = "Error durante la actualización: " . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_learning_path') {
        $lpId = $_POST['learning_path_id'] ?? '';
        if ($lpId) {
            $stmt = $pdo->prepare("DELETE FROM LearningPath WHERE id = ?");
            if ($stmt->execute([$lpId])) {
                $successMsg = "Ruta eliminada definitivamente.";
            }
        }
    }

    require_once 'utils/assignment_sync.php';
    syncAllCourseAssignments($pdo);
}

// Obtener todas las Rutas
$stmt = $pdo->query("
    SELECT lp.id, lp.name, lp.description, lp.createdAt,
           (SELECT COUNT(*) FROM LearningPathCourse WHERE learningPathId = lp.id) as total_courses,
           (SELECT GROUP_CONCAT(CONCAT(tr.id, '::', tr.name) SEPARATOR '||') 
            FROM _LearningPathToTrainingRole lp2tr 
            JOIN TrainingRole tr ON lp2tr.B = tr.id 
            WHERE lp2tr.A = lp.id) as assigned_roles,
           (SELECT GROUP_CONCAT(CONCAT(c.id, '::', c.title) ORDER BY lpc.`order` ASC SEPARATOR '||')
            FROM LearningPathCourse lpc
            JOIN Course c ON lpc.courseId = c.id
            WHERE lpc.learningPathId = lp.id) as assigned_courses
    FROM LearningPath lp
    ORDER BY lp.createdAt DESC
");
$learningPaths = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Utilidades Globales para Modales
$allCourses = $pdo->query("SELECT id, title FROM Course ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$allRoles = $pdo->query("SELECT id, name FROM TrainingRole ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Mapas Relacionales Inyectados en el DOM para edición fluida
$pathsMap = [];
foreach ($learningPaths as $lp) {
    $sC = $pdo->prepare("SELECT courseId, `order` FROM LearningPathCourse WHERE learningPathId = ?");
    $sC->execute([$lp['id']]);
    $mappedCourses = $sC->fetchAll(PDO::FETCH_ASSOC);
    
    $sR = $pdo->prepare("SELECT B as roleId FROM _LearningPathToTrainingRole WHERE A = ?");
    $sR->execute([$lp['id']]);
    $mappedRoles = $sR->fetchAll(PDO::FETCH_COLUMN);

    $pathsMap[$lp['id']] = [
        'id' => $lp['id'],
        'name' => $lp['name'],
        'description' => $lp['description'],
        'courses' => $mappedCourses,
        'roles' => $mappedRoles
    ];
}
?>

<div style="max-width: 1600px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.7s ease-out;">
    <header style="margin-bottom: 2rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; display: flex; justify-content: space-between; align-items: flex-end;">
        <div>
            <h1 style="font-size: 2rem; font-weight: 800; color: var(--text-main); margin-bottom: 0.5rem;">Rutas de Aprendizaje</h1>
            <p style="color: var(--text-muted); font-size: 0.95rem;">Crea programas secuenciales de estudio y asígnalos masivamente a los roles de la empresa.</p>
        </div>
        <div>
            <button class="btn btn-primary" onclick="openModal('modalCreatePath')" style="padding: 0.6rem 1.2rem; border-radius: 12px; font-weight: 700; box-shadow: 0 4px 14px rgba(79, 70, 229, 0.3);">
                + Nueva Ruta
            </button>
        </div>
    </header>

    <?php if ($successMsg): ?>
        <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #16a34a;"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #dc2626;"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <div style="background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -2px rgba(0, 0, 0, 0.05); overflow: hidden; border: 1px solid #f1f5f9;">
        <?php if (empty($learningPaths)): ?>
            <div style="padding: 4rem 2rem; text-align: center;">
                <h3 style="color: #64748b; font-size: 1.2rem; margin-bottom: 0.5rem;">Sin Rutas de Aprendizaje</h3>
                <p style="color: #94a3b8;">Usa el botón superior para diseñar tu primera secuencia de cursos.</p>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap: 1.5rem; padding: 1.5rem; background: #f8fafc;">
                <?php foreach ($learningPaths as $path): ?>
                    <div style="background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); border: 1px solid #e2e8f0; padding: 1.5rem; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-4px)'; this.style.boxShadow='0 10px 25px -5px rgba(0, 0, 0, 0.1)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.05)';">
                        
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                            <div style="display: flex; align-items: center; gap: 0.8rem;">
                                <div style="width: 42px; height: 42px; background: #e0e7ff; color: #4338ca; border-radius: 10px; font-weight: 900; font-size: 1.2rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                    <i class='bx bx-git-repo-forked'></i>
                                </div>
                                <div>
                                    <h4 style="font-size: 1.05rem; font-weight: 800; color: #1e293b; margin: 0; line-height: 1.3;">
                                        <?php echo htmlspecialchars($path['name']); ?>
                                    </h4>
                                    <?php if ($path['description']): ?>
                                        <p style="font-size: 0.75rem; color: #64748b; margin: 0.2rem 0 0 0; line-height: 1.3; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                            <?php echo htmlspecialchars($path['description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; background: #f8fafc; padding: 0.8rem 1rem; border-radius: 10px; margin-bottom: 1rem; border: 1px solid #f1f5f9;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #e2e8f0; padding-bottom: 0.5rem; margin-bottom: 0.5rem;">
                                <div>
                                    <span style="font-size: 1.2rem; font-weight: 900; color: #4338ca;"><?php echo $path['total_courses']; ?></span>
                                    <span style="font-size: 0.75rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-left: 0.3rem;">Cursos Asignados</span>
                                </div>
                            </div>
                            
                            <div style="display: flex; flex-direction: column; gap: 0.2rem; max-height: 100px; overflow-y: auto;">
                                <?php 
                                    if ($path['assigned_courses']) {
                                        $chunks = explode('||', $path['assigned_courses']);
                                        $step = 1;
                                        foreach ($chunks as $chk) {
                                            $parts = explode('::', $chk);
                                            if (count($parts) === 2) {
                                                echo '<div style="font-size: 0.75rem; color: #475569; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="' . htmlspecialchars($parts[1]) . '">';
                                                echo '<span style="color: #94a3b8; font-size: 0.7rem; font-weight: 700;">' . $step . '.</span> ' . htmlspecialchars($parts[1]);
                                                echo '</div>';
                                                $step++;
                                            }
                                        }
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-size: 0.75rem; font-style: italic;">Sin cursos integrados</span>';
                                    }
                                ?>
                            </div>
                        </div>

                        <div style="flex-grow: 1; margin-bottom: 1.5rem;">
                            <label style="font-size: 0.7rem; color: #94a3b8; font-weight: 800; text-transform: uppercase; margin-bottom: 0.5rem; display: block;">Perfiles de Acceso Remoto</label>
                            <div style="display: flex; flex-wrap: wrap; gap: 0.4rem;">
                                <?php 
                                    if ($path['assigned_roles']) {
                                        $chunks = explode('||', $path['assigned_roles']);
                                        foreach ($chunks as $chk) {
                                            $parts = explode('::', $chk);
                                            if (count($parts) === 2) {
                                                echo '<span style="background: #fff7ed; border: 1px solid #ffedd5; color: #ea580c; padding: 0.3rem 0.6rem; border-radius: 8px; font-size: 0.65rem; font-weight: 800; display: inline-flex; align-items: center; gap: 4px;"><span>👤</span> ' . htmlspecialchars($parts[1]) . '</span>';
                                            }
                                        }
                                    } else {
                                        echo '<span style="color: #cbd5e1; font-size: 0.75rem; font-weight: 600; font-style: italic;">Sin roles enlazados</span>';
                                    }
                                ?>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; padding-top: 1rem; margin-top: auto;">
                            <form method="POST" style="margin: 0;" onsubmit="return confirm('¿Eliminar ruta definitivamente? Los usuarios perderán este mapa de aprendizaje.');">
                                <input type="hidden" name="action" value="delete_learning_path">
                                <input type="hidden" name="learning_path_id" value="<?php echo htmlspecialchars($path['id']); ?>">
                                <button type="submit" style="background: none; border: none; padding: 0.5rem; color: #ef4444; cursor: pointer; border-radius: 8px; transition: background 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='none'" title="Eliminar Ruta">
                                    <i class='bx bx-trash' style="font-size: 1.2rem;"></i>
                                </button>
                            </form>

                            <button onclick="openEditPath('<?php echo htmlspecialchars($path['id']); ?>')" style="display: inline-flex; align-items: center; gap: 0.5rem; color: #4f46e5; border: 1px solid transparent; padding: 0.6rem 1.2rem; border-radius: 10px; font-weight: 700; font-size: 0.85rem; background: #e0e7ff; transition: background 0.2s; cursor: pointer;" onmouseover="this.style.background='#c7d2fe'" onmouseout="this.style.background='#e0e7ff'">
                                ✏️ Modificar
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Crear Ruta -->
<div class="modal-overlay" id="modalCreatePath">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Nueva Ruta Múltiple</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreatePath')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_learning_path">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Nombre de la Curricula</label>
                <input type="text" name="name" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Descripción / Propósito</label>
                <textarea name="description" class="form-control" rows="3" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical;"></textarea>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalCreatePath')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Crear Base</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Ruta y Asignaciones -->
<div class="modal-overlay" id="modalEditPath">
    <div class="modal-content" style="max-width: 800px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Configurar Enlaces de la Ruta</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditPath')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_learning_path">
            <input type="hidden" name="learning_path_id" id="edit_lp_id" value="">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                <div>
                    <div class="form-group" style="margin-bottom: 1rem;">
                        <label class="form-label" style="font-weight: 700; color: #374151;">Nombre de la Ruta</label>
                        <input type="text" name="name" id="edit_lp_name" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700; color: #374151;">Descripción</label>
                        <textarea name="description" id="edit_lp_desc" class="form-control" rows="2" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical;"></textarea>
                    </div>
                    
                    <div class="form-group" style="margin-top: 1.5rem;">
                        <label class="form-label" style="font-weight: 800; font-size: 0.75rem; text-transform: uppercase; color: #ea580c;">Asignar Roles</label>
                        <div style="max-height: 180px; overflow-y: auto; border: 1px solid #ffedd5; padding: 0.8rem; border-radius: 12px; background: #fff7ed;">
                            <?php foreach($allRoles as $r): ?>
                                <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; cursor: pointer; color: #9a3412; font-weight: 600;">
                                    <input type="checkbox" name="roleIds[]" value="<?php echo htmlspecialchars($r['id']); ?>" 
                                        id="role_chk_<?php echo $r['id']; ?>" style="width: 16px; height: 16px; accent-color: #ea580c;">
                                    <?php echo htmlspecialchars($r['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div style="display: flex; flex-direction: column;">
                    <label class="form-label" style="font-weight: 800; font-size: 0.75rem; text-transform: uppercase; color: #2563eb;">Secuencia de Cursos Múltiples</label>
                    <p style="font-size: 0.7rem; color: #64748b; margin-bottom: 0.5rem;">Marca los cursos y asigna un número de prioridad para ordenar la ruta (1, 2, 3...)</p>
                    <div style="flex: 1; overflow-y: auto; border: 1px solid #dbeafe; padding: 0.8rem; border-radius: 12px; background: #eff6ff;">
                        <?php foreach($allCourses as $c): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; padding-bottom: 0.4rem; border-bottom: 1px dashed #bfdbfe;">
                                <label style="display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; cursor: pointer; color: #1e40af; font-weight: 600; flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis;">
                                    <input type="checkbox" name="courseIds[]" value="<?php echo htmlspecialchars($c['id']); ?>" 
                                        id="course_chk_<?php echo $c['id']; ?>" style="width: 14px; height: 14px; accent-color: #2563eb;" onchange="document.getElementById('c_ord_<?php echo $c['id']; ?>').disabled = !this.checked;">
                                    <span title="<?php echo htmlspecialchars($c['title']); ?>" style="overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($c['title']); ?></span>
                                </label>
                                <input type="number" name="courseOrders[<?php echo htmlspecialchars($c['id']); ?>]" id="c_ord_<?php echo $c['id']; ?>" placeholder="Ord." disabled style="width: 60px; padding: 0.2rem 0.4rem; font-size: 0.75rem; border: 1px solid #cbd5e1; border-radius: 6px; margin-left: 0.5rem;">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem; border-top: 1px solid #f3f4f6; padding-top: 1.5rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalEditPath')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);">Ensamblar Ruta</button>
            </div>
        </form>
    </div>
</div>

<script>
    const pathsData = <?php echo json_encode($pathsMap); ?>;

    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openEditPath(id) {
        const data = pathsData[id];
        if (!data) return;

        document.getElementById('edit_lp_id').value = data.id;
        document.getElementById('edit_lp_name').value = data.name;
        document.getElementById('edit_lp_desc').value = data.description || '';

        // Reset roles
        document.querySelectorAll('input[name="roleIds[]"]').forEach(chk => chk.checked = false);
        // Set roles
        data.roles.forEach(roleId => {
            const chk = document.getElementById('role_chk_' + roleId);
            if (chk) chk.checked = true;
        });

        // Reset courses
        document.querySelectorAll('input[name="courseIds[]"]').forEach(chk => {
            chk.checked = false;
            const inputOrd = document.getElementById('c_ord_' + chk.value);
            if (inputOrd) {
                inputOrd.value = '';
                inputOrd.disabled = true;
            }
        });
        
        // Set courses
        data.courses.forEach(mapCourse => {
            const chk = document.getElementById('course_chk_' + mapCourse.courseId);
            const inputOrd = document.getElementById('c_ord_' + mapCourse.courseId);
            if (chk) {
                chk.checked = true;
                if (inputOrd) {
                    inputOrd.disabled = false;
                    inputOrd.value = mapCourse.order;
                }
            }
        });

        openModal('modalEditPath');
    }
</script>

<style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
