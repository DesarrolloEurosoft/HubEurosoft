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

$courseId = $_GET['id'] ?? '';
if (!$courseId) {
    echo "<h2>ID de Curso no especificado</h2>";
    exit;
}

$successMsg = '';
$errorMsg = '';

// Procesar Formularios Básicos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Guardar Información General del Curso
    if ($action === 'edit_course_info') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        $trainingRoleIds = $_POST['trainingRoleIds'] ?? [];
        $learningPathIds = $_POST['learningPathIds'] ?? [];
        $certificateId = $_POST['certificateId'] ?? '';
        if (empty($certificateId)) $certificateId = null;

        if ($title) {
            $stmt = $pdo->prepare("UPDATE Course SET title = ?, description = ?, certificateId = ?, updatedAt = NOW() WHERE id = ?");
            if ($stmt->execute([$title, $description, $certificateId, $courseId])) {
                
                // Actualizar Roles Directos vinculados al curso
                $pdo->prepare("DELETE FROM _CourseToTrainingRole WHERE A = ?")->execute([$courseId]);
                if (!empty($trainingRoleIds) && is_array($trainingRoleIds)) {
                    $stmtTR = $pdo->prepare("INSERT INTO _CourseToTrainingRole (A, B) VALUES (?, ?)");
                    foreach ($trainingRoleIds as $rId) { $stmtTR->execute([$courseId, $rId]); }
                }
                
                // Actualizar Learning Paths vinculados al curso
                $pdo->prepare("DELETE FROM LearningPathCourse WHERE courseId = ?")->execute([$courseId]);
                if (!empty($learningPathIds) && is_array($learningPathIds)) {
                    $stmtMaxOrder = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) FROM LearningPathCourse WHERE learningPathId = ?");
                    $stmtLP = $pdo->prepare("INSERT INTO LearningPathCourse (id, courseId, learningPathId, `order`, createdAt, updatedAt) VALUES (?, ?, ?, ?, NOW(), NOW())");
                    foreach ($learningPathIds as $lpId) {
                        $stmtMaxOrder->execute([$lpId]);
                        $nextOrder = $stmtMaxOrder->fetchColumn() + 1;
                        $stmtLP->execute([generateCuid(), $courseId, $lpId, $nextOrder]);
                    }
                }

                $successMsg = "Información del curso actualizada exitosamente.";
            } else {
                $errorMsg = "Error al actualizar curso.";
            }
        } else {
             $errorMsg = "El título es obligatorio.";
        }
    }
    
    // ------ SUBIDA DE PORTADA ------
    if ($action === 'upload_course_cover') {
        if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['cover_image']['tmp_name'];
            $fileName = $_FILES['cover_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            
            if (in_array($fileExtension, $allowedExtensions)) {
                $uploadDir = 'uploads/courses/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                
                $newFileName = 'course_' . $courseId . '_' . time() . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $imageUrl = '/' . $destPath;
                    $stmt = $pdo->prepare("UPDATE Course SET imageUrl = ?, updatedAt = NOW() WHERE id = ?");
                    if ($stmt->execute([$imageUrl, $courseId])) {
                        $successMsg = "Imagen de portada actualizada exitosamente.";
                    } else {
                        $errorMsg = "Error al actualizar la imagen de portada en la base de datos.";
                    }
                } else {
                    $errorMsg = "Error al mover el archivo al servidor.";
                }
            } else {
                $errorMsg = "Formato de imagen inválido. Solo JPG, PNG, WEBP o GIF.";
            }
        } else {
            $errorMsg = "No se ha seleccionado ningún archivo o ha ocurrido un error en la subida.";
        }
    }

    // ------ CRUD MÓDULOS ------
    if ($action === 'create_module') {
        $mTitle = trim($_POST['title'] ?? '');
        $mDesc = trim($_POST['description'] ?? '');
        if ($mTitle) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) FROM Module WHERE courseId = ?");
            $maxOrder->execute([$courseId]);
            $order = $maxOrder->fetchColumn() + 1;
            
            $stmt = $pdo->prepare("INSERT INTO Module (id, courseId, title, description, `order`, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt->execute([generateCuid(), $courseId, $mTitle, $mDesc, $order])) {
                $successMsg = "Módulo agregado al temario.";
            }
        }
    }
    
    if ($action === 'edit_module') {
        $mId = $_POST['module_id'] ?? '';
        $mTitle = trim($_POST['title'] ?? '');
        $mDesc = trim($_POST['description'] ?? '');
        $mOrder = (int)($_POST['order'] ?? 0);
        
        if ($mId && $mTitle) {
            $stmt = $pdo->prepare("UPDATE Module SET title = ?, description = ?, `order` = ?, updatedAt = NOW() WHERE id = ? AND courseId = ?");
            if ($stmt->execute([$mTitle, $mDesc, $mOrder, $mId, $courseId])) {
                $successMsg = "Módulo editado correctamente.";
            }
        }
    }
    
    if ($action === 'delete_module') {
        $mId = $_POST['module_id'] ?? '';
        if ($mId) {
            $stmt = $pdo->prepare("DELETE FROM Module WHERE id = ? AND courseId = ?");
            if ($stmt->execute([$mId, $courseId])) {
                $successMsg = "Módulo eliminado.";
            }
        }
    }

    // ------ CRUD LECCIONES ------
    if ($action === 'create_lesson') {
        $lModId = $_POST['module_id'] ?? '';
        $lTitle = trim($_POST['title'] ?? '');
        $lDesc = trim($_POST['description'] ?? '');
        if ($lModId && $lTitle) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) FROM Lesson WHERE moduleId = ?");
            $maxOrder->execute([$lModId]);
            $order = $maxOrder->fetchColumn() + 1;
            
            $videoUrl = null;
            $documentUrl = null;
            $presentationUrl = null;

            if (isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['lesson_file']['tmp_name'];
                $fileName = $_FILES['lesson_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $uploadDir = 'uploads/lessons/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                
                $newFileName = 'leccion_' . substr(md5(uniqid()), 0, 10) . '.' . $fileExtension;
                $destPath = $uploadDir . $newFileName;
                
                if (move_uploaded_file($fileTmpPath, $destPath)) {
                    $savedPath = '/' . $destPath;
                    
                    if (in_array($fileExtension, ['mp4', 'webm', 'mov', 'avi'])) {
                        $videoUrl = $savedPath;
                    } elseif (in_array($fileExtension, ['pdf', 'doc', 'docx', 'xlsx', 'xls', 'txt'])) {
                        $documentUrl = $savedPath;
                    } elseif (in_array($fileExtension, ['ppt', 'pptx'])) {
                        $presentationUrl = $savedPath;
                    } else {
                        // Fallback (ZIP u otros)
                        $documentUrl = $savedPath;
                    }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO Lesson (id, moduleId, title, description, videoUrl, documentUrl, presentationUrl, `order`, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt->execute([generateCuid(), $lModId, $lTitle, $lDesc, $videoUrl, $documentUrl, $presentationUrl, $order])) {
                $successMsg = "Lección añadida con su archivo base exitosamente.";
            } else {
                $errorMsg = "Error al crear la lección en la fila de datos.";
            }
        }
    }
    
    if ($action === 'edit_lesson') {
        $lId = $_POST['lesson_id'] ?? '';
        $lTitle = trim($_POST['title'] ?? '');
        $lDesc = trim($_POST['description'] ?? '');
        $lContent = trim($_POST['content'] ?? '');
        $lVideo = trim($_POST['videoUrl'] ?? '');
        $lPres = trim($_POST['presentationUrl'] ?? '');
        $lDoc = trim($_POST['documentUrl'] ?? '');
        $lOrder = (int)($_POST['order'] ?? 0);
        
        // Manejar subida de archivo si existe en la edición
        if (isset($_FILES['lesson_file']) && $_FILES['lesson_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['lesson_file']['tmp_name'];
            $fileName = $_FILES['lesson_file']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $uploadDir = 'uploads/lessons/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            
            $newFileName = 'leccion_' . substr(md5(uniqid()), 0, 10) . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $savedPath = '/' . $destPath;
                
                if (in_array($fileExtension, ['mp4', 'webm', 'mov', 'avi'])) {
                    $lVideo = $savedPath;
                } elseif (in_array($fileExtension, ['pdf', 'doc', 'docx', 'xlsx', 'xls', 'txt'])) {
                    $lDoc = $savedPath;
                } elseif (in_array($fileExtension, ['ppt', 'pptx'])) {
                    $lPres = $savedPath;
                } else {
                    $lDoc = $savedPath;
                }
            }
        }
        
        if ($lId && $lTitle) {
            $stmt = $pdo->prepare("UPDATE Lesson SET title = ?, description = ?, content = ?, videoUrl = ?, presentationUrl = ?, documentUrl = ?, `order` = ?, updatedAt = NOW() WHERE id = ?");
            if ($stmt->execute([$lTitle, $lDesc, $lContent, $lVideo, $lPres, $lDoc, $lOrder, $lId])) {
                $successMsg = "Lección actualizada correctamente.";
            }
        }
    }
    
    if ($action === 'delete_lesson') {
        $lId = $_POST['lesson_id'] ?? '';
        if ($lId) {
            $stmt = $pdo->prepare("DELETE FROM Lesson WHERE id = ?");
            if ($stmt->execute([$lId])) {
                $successMsg = "Lección removida definitivamente.";
            }
        }
    }

    // ------ CRUD TOPICS ------
    if ($action === 'create_topic') {
        $tLessId = $_POST['lesson_id'] ?? '';
        $tTitle = trim($_POST['title'] ?? '');
        $tDesc = trim($_POST['description'] ?? '');
        if ($tLessId && $tTitle) {
            $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(`order`), 0) FROM Topic WHERE lessonId = ?");
            $maxOrder->execute([$tLessId]);
            $order = $maxOrder->fetchColumn() + 1;
            
            $stmt = $pdo->prepare("INSERT INTO Topic (id, lessonId, title, description, `order`, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
            if ($stmt->execute([generateCuid(), $tLessId, $tTitle, $tDesc, $order])) {
                $successMsg = "Tema anidado correctamente.";
            }
        }
    }
    
    if ($action === 'edit_topic') {
        $tId = $_POST['topic_id'] ?? '';
        $tTitle = trim($_POST['title'] ?? '');
        $tDesc = trim($_POST['description'] ?? '');
        $tContent = trim($_POST['content'] ?? '');
        $tVideo = trim($_POST['videoUrl'] ?? '');
        $tPres = trim($_POST['presentationUrl'] ?? '');
        $tDoc = trim($_POST['documentUrl'] ?? '');
        $tOrder = (int)($_POST['order'] ?? 0);
        
        if ($tId && $tTitle) {
            $stmt = $pdo->prepare("UPDATE Topic SET title = ?, description = ?, content = ?, videoUrl = ?, presentationUrl = ?, documentUrl = ?, `order` = ?, updatedAt = NOW() WHERE id = ?");
            if ($stmt->execute([$tTitle, $tDesc, $tContent, $tVideo, $tPres, $tDoc, $tOrder, $tId])) {
                $successMsg = "Tema modificado con éxito.";
            }
        }
    }
    
    if ($action === 'delete_topic') {
        $tId = $_POST['topic_id'] ?? '';
        if ($tId) {
            $stmt = $pdo->prepare("DELETE FROM Topic WHERE id = ?");
            if ($stmt->execute([$tId])) {
                $successMsg = "Tema extirpado del sistema.";
            }
        }
    }

    require_once 'utils/assignment_sync.php';
    syncAllCourseAssignments($pdo);
}

// 1. Obtener Datos del Curso
$stmt = $pdo->prepare("SELECT id, title, description, imageUrl, certificateId FROM Course WHERE id = ?");
$stmt->execute([$courseId]);
$course = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$course) { echo "<h2>Curso no encontrado</h2>"; exit; }

// Obtener todas las utilidades para las asignaciones
$allRoles = $pdo->query("SELECT id, name FROM TrainingRole ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allPaths = $pdo->query("SELECT id, name FROM LearningPath ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$allCerts = $pdo->query("SELECT id, name FROM Certificate ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Extraer Roles y Rutas previas asignadas al curso
$courseDirectRoles = $pdo->prepare("SELECT B as id FROM _CourseToTrainingRole WHERE A = ?");
$courseDirectRoles->execute([$courseId]);
$c_role_ids = $courseDirectRoles->fetchAll(PDO::FETCH_COLUMN);

$coursePaths = $pdo->prepare("SELECT learningPathId as id FROM LearningPathCourse WHERE courseId = ?");
$coursePaths->execute([$courseId]);
$c_path_ids = $coursePaths->fetchAll(PDO::FETCH_COLUMN);

// Extraer Módulos (Secciones) del Curso
$stmtMods = $pdo->prepare("SELECT id, title, description, `order` FROM Module WHERE courseId = ? ORDER BY `order` ASC");
$stmtMods->execute([$courseId]);
$modules = $stmtMods->fetchAll(PDO::FETCH_ASSOC);
?>

<div style="max-width: 1600px; margin: 0 auto; padding: 2rem; padding-bottom: 8rem; animation: fadeIn 0.5s ease-out; font-family: 'Inter', sans-serif;">
    <header style="margin-bottom: 2rem; border-bottom: 1px solid var(--border); padding-bottom: 1.5rem;">
        
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2rem;">
            <div>
                <h1 style="font-size: 2.2rem; font-weight: 800; color: #111827; letter-spacing: -0.02em; margin-bottom: 0.5rem; display: flex; align-items: center; gap: 0.5rem;">
                    <?= htmlspecialchars($course['title']) ?>
                </h1>
                <p style="color: #6b7280; max-width: 600px; line-height: 1.5; font-size: 0.95rem;">
                    <?= htmlspecialchars($course['description'] ?: 'Sin descripción proporcionada.') ?>
                </p>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 0.5rem; align-items: flex-end;">
                <a href="index.php?view=courses" class="btn" style="background: white; border: 1px solid #e5e7eb; color: #6b7280; font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; padding: 0.4rem 1rem; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    ← Volver
                </a>
                <button class="btn btn-primary" onclick="openModal('modalEditInfo')" style="background: #4f46e5; border-radius: 8px; font-weight: 600; padding: 0.5rem 1rem; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2);">
                    <i class='bx bx-edit' style="margin-right: 4px;"></i> Editar Info. Raíz
                </button>
            </div>
        </div>

        <div style="display: flex; gap: 1.5rem;">
            <!-- Hidden form for uploading cover image -->
            <form id="uploadCoverForm" method="POST" enctype="multipart/form-data" style="display: none;">
                <input type="hidden" name="action" value="upload_course_cover">
                <input type="file" name="cover_image" id="cover_image_input" accept="image/*" onchange="document.getElementById('uploadCoverForm').submit();">
            </form>

            <div onclick="document.getElementById('cover_image_input').click();" style="width: 280px; height: 160px; border-radius: 16px; overflow: hidden; background: linear-gradient(135deg, #e0e7ff, #f3e8ff); border: 1px solid #e5e7eb; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); flex-shrink: 0; cursor: pointer; position: relative;" onmouseover="this.querySelector('.cover-overlay').style.opacity='1'" onmouseout="this.querySelector('.cover-overlay').style.opacity='0'">
                <?php if ($course['imageUrl']): ?>
                    <img src="<?= htmlspecialchars($course['imageUrl']) ?>" alt="Portada" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 4rem;">📚</div>
                <?php endif; ?>
                <div class="cover-overlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.5); opacity: 0; transition: opacity 0.2s; color: white; font-weight: 800; font-size: 0.95rem; z-index: 10;">
                    <i class='bx bx-camera' style="font-size: 1.4rem; margin-right: 6px;"></i> Cambiar Portada
                </div>
            </div>
            
            <div style="flex: 1;">
                <span style="font-size: 0.65rem; font-weight: 900; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.5rem; display: block;">Asignaciones de Seguridad (Roles vinculados)</span>
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <?php if (empty($c_role_ids) && empty($c_path_ids)): ?>
                        <span style="background: #f1f5f9; color: #64748b; padding: 0.3rem 0.8rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700;">ACCESO LIBRE (Cualquier usuario puede ver este curso)</span>
                    <?php endif; ?>
                    
                    <?php 
                        foreach ($allRoles as $r) {
                            if (in_array($r['id'], $c_role_ids)) {
                                echo '<span style="background: #fff7ed; border: 1px solid #ffedd5; color: #ea580c; padding: 0.3rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 4px;">👤 ' . htmlspecialchars($r['name']) . '</span>';
                            }
                        }
                        foreach ($allPaths as $lp) {
                            if (in_array($lp['id'], $c_path_ids)) {
                                echo '<span style="background: #eff6ff; border: 1px solid #dbeafe; color: #2563eb; padding: 0.3rem 0.6rem; border-radius: 8px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 1px 2px rgba(0,0,0,0.05); display: flex; align-items: center; gap: 4px;">🗺️ ' . htmlspecialchars($lp['name']) . '</span>';
                            }
                        }
                    ?>
                </div>
            </div>
        </div>
    </header>

    <?php if ($successMsg): ?> <div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #16a34a;"><?= htmlspecialchars($successMsg) ?></div> <?php endif; ?>
    <?php if ($errorMsg): ?> <div style="background: #fee2e2; color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; font-weight: 600; border-left: 4px solid #dc2626;"><?= htmlspecialchars($errorMsg) ?></div> <?php endif; ?>

    <section>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.5rem; font-weight: 800; color: #1f2937; border-left: 6px solid #4f46e5; padding-left: 0.8rem;">Estructura del Programa</h2>
            <button class="btn btn-primary" onclick="openModal('modalCreateModule')" style="background: #4f46e5; border-radius: 12px; font-weight: 800; padding: 0.7rem 1.2rem; box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);">
                + Añadir Módulo
            </button>
        </div>
        
        <?php if (empty($modules)): ?>
            <div style="background: white; border: 2px dashed #e5e7eb; border-radius: 24px; padding: 4rem; text-align: center; box-shadow: inset 0 2px 4px rgba(0,0,0,0.02);">
                <div style="font-size: 4rem; margin-bottom: 1rem;">📚</div>
                <h3 style="font-size: 1.25rem; font-weight: 800; color: #9ca3af;">Tu curso no tiene secciones aún</h3>
                <p style="color: #d1d5db; max-width: 300px; margin: 0.5rem auto 0; font-size: 0.9rem;">Usa el botón superior para crear el primer Módulo de contenido.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 2rem;">
                <?php foreach ($modules as $mIdx => $module): ?>
                    <div style="position: relative;" class="group">
                        <!-- Cabecera del Módulo -->
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem; padding-left: 1rem; border-left: 8px solid #4f46e5;">
                            <div>
                                <span style="font-size: 0.65rem; font-weight: 900; color: #818cf8; text-transform: uppercase; letter-spacing: 0.2em;">Sección <?= $mIdx + 1 ?> (Orden <?= $module['order'] ?>)</span>
                                <h3 style="font-size: 1.3rem; font-weight: 900; color: #111827; margin: 0.2rem 0;"><?= htmlspecialchars($module['title']) ?></h3>
                                <?php if($module['description']): ?>
                                    <p style="font-size: 0.85rem; color: #9ca3af; margin: 0;"><?= htmlspecialchars($module['description']) ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <button class="btn" style="background: #f1f5f9; color: #64748b; font-weight: 700; font-size: 0.8rem; border-radius: 8px; padding: 0.4rem 0.8rem;" 
                                    onclick="openCreateLesson('<?= htmlspecialchars($module['id']) ?>', '<?= htmlspecialchars(addslashes($module['title'])) ?>')">
                                    + Añadir Lección
                                </button>
                                <button class="btn" style="background: white; color: #374151; border: 1px solid #e5e7eb; font-size: 0.8rem; border-radius: 8px; padding: 0.4rem;" 
                                        onclick="openEditModule('<?= htmlspecialchars($module['id']) ?>', '<?= htmlspecialchars(addslashes($module['title'])) ?>', '<?= htmlspecialchars(addslashes($module['description'] ?? '')) ?>', <?= $module['order'] ?>)" title="Editar Módulo">
                                    ✏️
                                </button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar módulo y todas sus lecciones internamente?');">
                                    <input type="hidden" name="action" value="delete_module">
                                    <input type="hidden" name="module_id" value="<?= htmlspecialchars($module['id']) ?>">
                                    <button type="submit" class="btn" style="background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; padding: 0.4rem;" title="Eliminar Módulo">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Grid de Lecciones -->
                        <div style="margin-left: 1.5rem; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.5rem;">
                            <?php 
                                $stmtLessons = $pdo->prepare("SELECT id, title, description, content, videoUrl, presentationUrl, documentUrl, `order` FROM Lesson WHERE moduleId = ? ORDER BY `order` ASC");
                                $stmtLessons->execute([$module['id']]);
                                $lessons = $stmtLessons->fetchAll(PDO::FETCH_ASSOC);
                                
                                if (empty($lessons)):
                            ?>
                                <div style="grid-column: 1 / -1; background: #fafafa; border: 1px dashed #e5e7eb; border-radius: 16px; padding: 2rem; text-align: center;">
                                    <span style="color: #d1d5db; font-size: 0.85rem; font-weight: 600;">Aún no hay lecciones en este módulo.</span>
                                </div>
                            <?php 
                                else: 
                                    foreach ($lessons as $lIdx => $lesson): 
                            ?>
                                <div style="background: white; border: 1px solid #f1f5f9; border-radius: 20px; padding: 1.5rem; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); transition: all 0.3s; position: relative;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem;">
                                        <div>
                                            <span style="font-size: 0.55rem; font-weight: 900; color: #cbd5e1; text-transform: uppercase; letter-spacing: 0.2em;">Lección <?= $lIdx + 1 ?></span>
                                            <h4 style="font-size: 1.1rem; font-weight: 800; color: #334155; margin: 0.2rem 0;"><?= htmlspecialchars($lesson['title']) ?></h4>
                                        </div>
                                        <div style="display: flex; gap: 0.3rem;">
                                            <button class="btn" style="padding: 0.3rem; font-size: 0.75rem; background: #e0e7ff; color: #4f46e5; border-radius: 6px;" 
                                                onclick="openCreateTopic('<?= htmlspecialchars($lesson['id']) ?>', '<?= htmlspecialchars(addslashes($lesson['title'])) ?>')" title="Añadir Tópico">+ Tópico</button>
                                            <button class="btn" style="padding: 0.3rem; font-size: 0.75rem; background: white; border: 1px solid #e5e7eb; color: #6b7280; border-radius: 6px;"
                                                onclick='openEditLesson(<?= json_encode($lesson) ?>, <?= json_encode($module['title']) ?>)' title="Configurar Lección">✏️</button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Borrar lección?');">
                                                <input type="hidden" name="action" value="delete_lesson">
                                                <input type="hidden" name="lesson_id" value="<?= htmlspecialchars($lesson['id']) ?>">
                                                <button type="submit" class="btn" style="padding: 0.3rem; font-size: 0.75rem; background: #fee2e2; color: #ef4444; border: 1px solid #fecaca; border-radius: 6px;" title="Eliminar Lección">✖</button>
                                            </form>
                                        </div>
                                    </div>
                                    
                                    <?php if ($lesson['description']): ?>
                                        <p style="font-size: 0.75rem; color: #9ca3af; font-style: italic; margin-bottom: 1rem;"><?= htmlspecialchars($lesson['description']) ?></p>
                                    <?php endif; ?>

                                    <?php if ($lesson['videoUrl'] || $lesson['presentationUrl'] || $lesson['documentUrl']): ?>
                                        <div style="display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 1rem;">
                                            <?php if ($lesson['videoUrl']): ?><span style="font-size: 0.6rem; font-weight: 800; background: #fef2f2; border: 1px solid #fee2e2; color: #dc2626; padding: 0.2rem 0.6rem; border-radius: 20px;">🎥 Video</span><?php endif; ?>
                                            <?php if ($lesson['presentationUrl']): ?><span style="font-size: 0.6rem; font-weight: 800; background: #fff7ed; border: 1px solid #ffedd5; color: #ea580c; padding: 0.2rem 0.6rem; border-radius: 20px;">📊 Slides</span><?php endif; ?>
                                            <?php if ($lesson['documentUrl']): ?><span style="font-size: 0.6rem; font-weight: 800; background: #eff6ff; border: 1px solid #dbeafe; color: #2563eb; padding: 0.2rem 0.6rem; border-radius: 20px;">📄 PDF</span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Topics Nesting Map -->
                                    <?php
                                        $stmtTopics = $pdo->prepare("SELECT id, title, description, content, videoUrl, presentationUrl, documentUrl, `order` FROM Topic WHERE lessonId = ? ORDER BY `order` ASC");
                                        $stmtTopics->execute([$lesson['id']]);
                                        $topics = $stmtTopics->fetchAll(PDO::FETCH_ASSOC);

                                        if (!empty($topics)):
                                    ?>
                                        <div style="margin-top: 1rem; border-top: 1px dashed #e2e8f0; padding-top: 0.8rem; display: flex; flex-direction: column; gap: 0.5rem;">
                                            <?php foreach ($topics as $tIdx => $topic): ?>
                                                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem; display: flex; justify-content: space-between; align-items: center;">
                                                    <div>
                                                        <span style="font-size: 0.55rem; font-weight: 800; color: #94a3b8; text-transform: uppercase;">Tema <?= $tIdx + 1 ?></span>
                                                        <h5 style="font-size: 0.85rem; font-weight: 700; color: #475569; margin: 0;"><?= htmlspecialchars($topic['title']) ?></h5>
                                                    </div>
                                                    <div style="display: flex; gap: 0.2rem;">
                                                        <button class="btn" style="padding: 0.2rem; font-size: 0.7rem; color: #64748b; background: white; border: 1px solid #cbd5e1; border-radius: 6px;"
                                                            onclick='openEditTopic(<?= json_encode($topic) ?>, <?= json_encode($lesson['title']) ?>)'>✏️</button>
                                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Borrar Tema definitivamente?');">
                                                            <input type="hidden" name="action" value="delete_topic">
                                                            <input type="hidden" name="topic_id" value="<?= htmlspecialchars($topic['id']) ?>">
                                                            <button type="submit" class="btn" style="padding: 0.2rem; font-size: 0.7rem; color: #ef4444; background: #fee2e2; border: 1px solid #fecaca; border-radius: 6px;">✖</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php 
                                    endforeach; 
                                endif; 
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Modal: Editar Info Básica -->
<div class="modal-overlay" id="modalEditInfo">
    <div class="modal-content" style="max-width: 650px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Información Raíz del Curso</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditInfo')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_course_info">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Título</label>
                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($course['title']) ?>" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Descripción / Objetivos</label>
                <textarea name="description" class="form-control" rows="3" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb; resize: vertical;"><?= htmlspecialchars($course['description'] ?? '') ?></textarea>
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Insignia o Certificado (Opcional)</label>
                <select name="certificateId" class="form-control" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
                    <option value="">-- Sin certificado --</option>
                    <?php foreach ($allCerts as $cert): ?>
                        <option value="<?= htmlspecialchars($cert['id']) ?>" <?= ($course['certificateId'] === $cert['id']) ? 'selected' : '' ?>><?= htmlspecialchars($cert['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 800; font-size: 0.75rem; text-transform: uppercase; color: #ea580c;">Asignación a Roles (Directa)</label>
                    <div style="max-height: 150px; overflow-y: auto; border: 1px solid #ffedd5; padding: 0.8rem; border-radius: 12px; background: #fff7ed;">
                        <?php foreach($allRoles as $r): ?>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; cursor: pointer; color: #9a3412; font-weight: 600;">
                                <input type="checkbox" name="trainingRoleIds[]" value="<?= htmlspecialchars($r['id']) ?>" 
                                    <?= in_array($r['id'], $c_role_ids) ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: #ea580c;">
                                <?= htmlspecialchars($r['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="font-weight: 800; font-size: 0.75rem; text-transform: uppercase; color: #2563eb;">Inclusión en Rutas (Heredada)</label>
                    <div style="max-height: 150px; overflow-y: auto; border: 1px solid #dbeafe; padding: 0.8rem; border-radius: 12px; background: #eff6ff;">
                        <?php foreach($allPaths as $lp): ?>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; font-size: 0.85rem; cursor: pointer; color: #1e40af; font-weight: 600;">
                                <input type="checkbox" name="learningPathIds[]" value="<?= htmlspecialchars($lp['id']) ?>" 
                                    <?= in_array($lp['id'], $c_path_ids) ? 'checked' : '' ?> style="width: 16px; height: 16px; accent-color: #2563eb;">
                                <?= htmlspecialchars($lp['name']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <p style="font-size: 0.75rem; color: #9ca3af; text-align: center; margin-bottom: 1.5rem;">Si dejas ambos listados vacíos, el curso será público para todos los alumnos del sistema.</p>

            <div style="display: flex; justify-content: flex-end; gap: 1rem; border-top: 1px solid #f3f4f6; padding-top: 1.5rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalEditInfo')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);">Guardar Cambios</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Crear Nuevo Módulo -->
<div class="modal-overlay" id="modalCreateModule">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Nueva Sección / Módulo</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreateModule')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_module">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Título del Módulo</label>
                <input type="text" name="title" class="form-control" required placeholder="Ej: Introducción Avanzada" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Breve Descripción (Opcional)</label>
                <input type="text" name="description" class="form-control" placeholder="De qué trata esta sección..." style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalCreateModule')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Añadir Módulo</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Módulo -->
<div class="modal-overlay" id="modalEditModule">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Configurar Módulo</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditModule')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_module">
            <input type="hidden" name="module_id" id="edit_mod_id" value="">
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Título</label>
                <input type="text" name="title" id="edit_mod_title" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151;">Descripción</label>
                    <input type="text" name="description" id="edit_mod_desc" class="form-control" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151;">Orden</label>
                    <input type="number" name="order" id="edit_mod_order" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalEditModule')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Actualizar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    function openEditModule(id, title, desc, order) {
        document.getElementById('edit_mod_id').value = id;
        document.getElementById('edit_mod_title').value = title;
        document.getElementById('edit_mod_desc').value = desc;
        document.getElementById('edit_mod_order').value = order;
        openModal('modalEditModule');
    }
</script>

<!-- Modal: Crear Lección -->
<div class="modal-overlay" id="modalCreateLesson">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Añadir Lección</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreateLesson')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create_lesson">
            <input type="hidden" name="module_id" id="create_less_mod_id" value="">
            
            <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 1rem;">Módulo destino: <span id="create_less_mod_title" style="font-weight: 700; color: #4f46e5;"></span></p>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Título de la Lección</label>
                <input type="text" name="title" class="form-control" required style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Resumen Breve</label>
                <input type="text" name="description" class="form-control" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <div class="form-group" style="margin-bottom: 1.5rem; background: #eff6ff; padding: 1rem; border-radius: 10px; border: 1px dashed #93c5fd;">
                <label class="form-label" style="font-weight: 800; color: #1e40af; display: flex; align-items: center; gap: 0.5rem;">
                    <i class='bx bx-cloud-upload' style="font-size: 1.3rem;"></i> Integrar Archivo (Opcional)
                </label>
                <p style="font-size: 0.75rem; color: #3b82f6; margin-bottom: 0.8rem;">Puedes subir un video (MP4), una presentación (PPT) o un documento (PDF). El sistema lo asignará automáticamente.</p>
                <input type="file" name="lesson_file" class="form-control" accept=".mp4,.webm,.mov,.avi,.pdf,.doc,.docx,.ppt,.pptx,.xlsx,.xls,.txt" style="background: white;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalCreateLesson')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Añadir a Módulo</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Lección -->
<div class="modal-overlay" id="modalEditLesson">
    <div class="modal-content" style="max-width: 600px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Configurar Lección</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditLesson')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="edit_lesson">
            <input type="hidden" name="lesson_id" id="edit_less_id" value="">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Título</label>
                    <input type="text" name="title" id="edit_less_title" class="form-control" required style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Orden Numérico</label>
                    <input type="number" name="order" id="edit_less_order" class="form-control" required style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Resumen o Subtítulo</label>
                <input type="text" name="description" id="edit_less_desc" class="form-control" style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Lectura Guiada (HTML Text)</label>
                <textarea name="content" id="edit_less_content" class="form-control" rows="3" style="padding: 0.6rem; border-radius: 8px; background: #f9fafb; resize: vertical;"></textarea>
            </div>

            <div style="background: #f8fafc; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px dashed #cbd5e1;">
                <h4 style="font-size: 0.75rem; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 0.8rem;">Adjuntos Multimedia (Opcionales)</h4>
                
                <div style="background: #eff6ff; padding: 0.8rem; border-radius: 8px; margin-bottom: 1rem; border: 1px dashed #93c5fd;">
                    <label class="form-label" style="font-weight: 800; color: #1e40af; display: flex; align-items: center; gap: 0.5rem; font-size: 0.8rem;">
                        <i class='bx bx-cloud-upload' style="font-size: 1.1rem;"></i> Reemplazar o Subir Archivo Nuevo
                    </label>
                    <input type="file" name="lesson_file" class="form-control" accept=".mp4,.webm,.mov,.avi,.pdf,.doc,.docx,.ppt,.pptx,.xlsx,.xls,.txt" style="background: white; font-size: 0.8rem; padding: 0.5rem;">
                </div>

                <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Enlace a Video (YouTube/Vimeo)</span>
                        <input type="text" name="videoUrl" id="edit_less_vid" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Presentación (Slides)</span>
                            <input type="text" name="presentationUrl" id="edit_less_pres" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Documento (PDF/Word)</span>
                            <input type="text" name="documentUrl" id="edit_less_doc" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalEditLesson')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Actualizar Lección</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal: Crear Tema -->
<div class="modal-overlay" id="modalCreateTopic">
    <div class="modal-content" style="max-width: 500px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1.5rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Nuevo Tema (Sub-Lección)</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalCreateTopic')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="create_topic">
            <input type="hidden" name="lesson_id" id="create_top_less_id" value="">
            
            <p style="font-size: 0.85rem; color: #6b7280; margin-bottom: 1rem;">Lección Destino: <span id="create_top_less_title" style="font-weight: 700; color: #4f46e5;"></span></p>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Título del Tema</label>
                <input type="text" name="title" class="form-control" required placeholder="Ej: Ejercicio Práctico 1" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>
            
            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label class="form-label" style="font-weight: 700; color: #374151;">Breve Descripción (Opcional)</label>
                <input type="text" name="description" class="form-control" style="padding: 0.8rem; border-radius: 10px; background: #f9fafb;">
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalCreateTopic')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Añadir Tema</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Editar Tema -->
<div class="modal-overlay" id="modalEditTopic">
    <div class="modal-content" style="max-width: 600px; border-radius: 20px;">
        <div class="modal-header" style="margin-bottom: 1rem;">
            <h3 class="modal-title" style="font-weight: 800; font-size: 1.4rem;">Configurar Tema Central</h3>
            <button type="button" class="modal-close" onclick="closeModal('modalEditTopic')"><i class='bx bx-x'></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit_topic">
            <input type="hidden" name="topic_id" id="edit_top_id" value="">
            
            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Título</label>
                    <input type="text" name="title" id="edit_top_title" class="form-control" required style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
                </div>
                <div class="form-group">
                    <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Orden</label>
                    <input type="number" name="order" id="edit_top_order" class="form-control" required style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Resumen o Subtítulo</label>
                <input type="text" name="description" id="edit_top_desc" class="form-control" style="padding: 0.6rem; border-radius: 8px; background: #f9fafb;">
            </div>

            <div class="form-group" style="margin-bottom: 1rem;">
                <label class="form-label" style="font-weight: 700; color: #374151; font-size: 0.8rem;">Lectura de Apoyo (Contenido)</label>
                <textarea name="content" id="edit_top_content" class="form-control" rows="3" style="padding: 0.6rem; border-radius: 8px; background: #f9fafb; resize: vertical;"></textarea>
            </div>

            <div style="background: #fdf2f8; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px dashed #fbcfe8;">
                <h4 style="font-size: 0.75rem; font-weight: 800; color: #db2777; text-transform: uppercase; margin-bottom: 0.8rem;">Recursos Prácticos</h4>
                <div style="display: flex; flex-direction: column; gap: 0.8rem;">
                    <div>
                        <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Video In-Deep</span>
                        <input type="text" name="videoUrl" id="edit_top_vid" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Presentación</span>
                            <input type="text" name="presentationUrl" id="edit_top_pres" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                        </div>
                        <div>
                            <span style="font-size: 0.75rem; font-weight: 700; color: #374151;">Guía Práctica</span>
                            <input type="text" name="documentUrl" id="edit_top_doc" class="form-control" placeholder="https://" style="padding: 0.5rem; font-size: 0.85rem; border-radius: 6px;">
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 1rem;">
                <button type="button" class="btn" style="background: #f3f4f6; color: #4b5563; font-weight: 700; border-radius: 10px;" onclick="closeModal('modalEditTopic')">Cancelar</button>
                <button type="submit" class="btn btn-primary" style="font-weight: 800; padding: 0.6rem 1.5rem; border-radius: 10px;">Actualizar Tema</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openCreateLesson(moduleId, moduleTitle) {
        document.getElementById('create_less_mod_id').value = moduleId;
        document.getElementById('create_less_mod_title').textContent = moduleTitle;
        openModal('modalCreateLesson');
    }

    function openEditLesson(lessonObj, moduleTitle) {
        document.getElementById('edit_less_id').value = lessonObj.id;
        document.getElementById('edit_less_title').value = lessonObj.title || '';
        document.getElementById('edit_less_desc').value = lessonObj.description || '';
        document.getElementById('edit_less_content').value = lessonObj.content || '';
        document.getElementById('edit_less_vid').value = lessonObj.videoUrl || '';
        document.getElementById('edit_less_pres').value = lessonObj.presentationUrl || '';
        document.getElementById('edit_less_doc').value = lessonObj.documentUrl || '';
        document.getElementById('edit_less_order').value = lessonObj.order || 0;
        openModal('modalEditLesson');
    }

    function openCreateTopic(lessonId, lessonTitle) {
        document.getElementById('create_top_less_id').value = lessonId;
        document.getElementById('create_top_less_title').textContent = lessonTitle;
        openModal('modalCreateTopic');
    }

    function openEditTopic(topicObj, lessonTitle) {
        document.getElementById('edit_top_id').value = topicObj.id;
        document.getElementById('edit_top_title').value = topicObj.title || '';
        document.getElementById('edit_top_desc').value = topicObj.description || '';
        document.getElementById('edit_top_content').value = topicObj.content || '';
        document.getElementById('edit_top_vid').value = topicObj.videoUrl || '';
        document.getElementById('edit_top_pres').value = topicObj.presentationUrl || '';
        document.getElementById('edit_top_doc').value = topicObj.documentUrl || '';
        document.getElementById('edit_top_order').value = topicObj.order || 0;
        openModal('modalEditTopic');
    }
</script>

<script>
    // FIX: Mover los modales al final del body para escapar cualquier containing block (overflow, transform, etc.) 
    // y asegurar que el overlay oscurezca el 100% de la ventana real.
    document.addEventListener("DOMContentLoaded", () => {
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            document.body.appendChild(modal);
        });
    });
</script>

<style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
</style>
