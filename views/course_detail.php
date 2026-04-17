<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$courseId = $_GET['id'] ?? null;

if (!$courseId) {
    echo "<h2>Error</h2><p>No se especificó un curso.</p>";
    exit;
}

try {
    // Info del Curso
    $stmt = $pdo->prepare("SELECT * FROM Course WHERE id = ?");
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();

    if (!$course) {
        echo "<h2>Error 404</h2><p>Curso no encontrado.</p>";
        exit;
    }

    // Módulos del Curso
    // En MySQL de Prisma, "order" es una palabra reservada, usamos `order`
    $stmtMods = $pdo->prepare("SELECT * FROM Module WHERE courseId = ? ORDER BY `order` ASC");
    $stmtMods->execute([$courseId]);
    $modules = $stmtMods->fetchAll();

    // Determinar permisos según el rol
    $userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
    $isManager = in_array($userRole, ['ADMIN', 'INSTRUCTOR']);

} catch (PDOException $e) {
    $dbError = "Error en base de datos: " . $e->getMessage();
}
?>

<?php if (isset($dbError)): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($dbError); ?></div>
<?php else: ?>

    <a href="index.php?view=courses" class="text-muted" style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
        <i class='bx bx-left-arrow-alt'></i> Volver a mis cursos
    </a>

    <!-- Cabecera del Curso -->
    <div class="card" style="margin-bottom: 2rem; display: flex; flex-wrap: wrap; gap: 2rem;">
        <div style="flex: 1; min-width: 300px;">
            <h1 style="font-size: 2rem; margin-bottom: 1rem; color: var(--text-main);">
                <?php echo htmlspecialchars($course['title']); ?>
            </h1>
            <p style="font-size: 1.05rem; line-height: 1.6; color: var(--text-muted);">
                <?php echo nl2br(htmlspecialchars($course['description'] ?: 'No hay descripción detallada para este curso.')); ?>
            </p>
            <div style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center;">
                <?php if (!$isManager): ?>
                    <button class="btn btn-primary"><i class='bx bx-play'></i> Continuar Aprendizaje</button>
                <?php else: ?>
                    <button class="btn btn-primary"><i class='bx bx-edit'></i> Editar Curso</button>
                    <button class="btn" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-main);"><i class='bx bx-plus'></i> Añadir Módulo</button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($course['imageUrl'])): ?>
            <div style="width: 320px; height: 180px; border-radius: var(--radius-md); background-image: url('<?php echo htmlspecialchars($course['imageUrl']); ?>'); background-size: cover; background-position: center;"></div>
        <?php endif; ?>
    </div>

    <!-- Temario (Módulos y Lecciones) -->
    <h2 style="margin-bottom: 1.5rem;">Resumen del Temario</h2>

    <?php if (count($modules) > 0): ?>
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <?php foreach ($modules as $module): ?>
                
                <div class="card" style="padding: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3 style="margin:0; font-size: 1.2rem;">
                            <span class="text-primary">Módulo <?php echo htmlspecialchars($module['order']); ?>:</span> 
                            <?php echo htmlspecialchars($module['title']); ?>
                        </h3>
                    </div>

                    <p style="font-size: 0.95rem; margin-bottom: 1.5rem; color: var(--text-muted);">
                        <?php echo htmlspecialchars($module['description'] ?: ''); ?>
                    </p>

                    <!-- Lecciones del Módulo -->
                    <div style="background: var(--bg-color); border-radius: var(--radius-md); border: 1px solid var(--border); overflow: hidden;">
                        <?php 
                        // Idealmente la carga de lecciones se hace con JOIN en la consulta principal para no recargar la BD
                        // Por simplicidad en esta arquitectura plana, hacemos lazy loading:
                        $stmtLessons = $pdo->prepare("SELECT * FROM Lesson WHERE moduleId = ? ORDER BY `order` ASC");
                        $stmtLessons->execute([$module['id']]);
                        $lessons = $stmtLessons->fetchAll();
                        ?>

                        <?php if (count($lessons) > 0): ?>
                            <?php foreach ($lessons as $index => $lesson): ?>
                                <div style="padding: 1rem; display: flex; justify-content: space-between; align-items: center; border-bottom: <?php echo ($index == count($lessons)-1) ? 'none' : '1px solid var(--border)'; ?>;">
                                    <div style="display: flex; align-items: center; gap: 1rem;">
                                        <div style="width: 32px; height: 32px; border-radius: 50%; background: white; border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 0.8rem; font-weight: 600; color: var(--text-muted);">
                                            <?php echo htmlspecialchars($lesson['order']); ?>
                                        </div>
                                        <div>
                                            <h4 style="font-size: 0.95rem; margin-bottom: 0.2rem;"><?php echo htmlspecialchars($lesson['title']); ?></h4>
                                            <?php if ($lesson['duration']): ?>
                                                <span style="font-size: 0.8rem; color: var(--text-muted);"><i class='bx bx-time'></i> <?php echo round($lesson['duration'] / 60); ?> minutos</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <?php if (!$isManager): ?>
                                        <a href="lesson.php?id=<?php echo urlencode($lesson['id']); ?>" class="btn" style="background: white; border: 1px solid var(--border); padding: 0.4rem 1rem; font-size: 0.85rem;">
                                            Comenzar
                                        </a>
                                    <?php else: ?>
                                        <div style="display: flex; gap: 0.5rem;">
                                            <a href="#" class="btn" style="background: white; border: 1px solid var(--border); padding: 0.4rem; color: var(--text-muted);" title="Editar Lección"><i class='bx bx-edit'></i></a>
                                            <a href="#" class="btn" style="background: #fee2e2; border: 1px solid #fecaca; padding: 0.4rem; color: #b91c1c;" title="Eliminar"><i class='bx bx-trash'></i></a>
                                        </div>
                                    <?php endif; ?>

                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="padding: 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                                No hay lecciones publicadas en este módulo aún.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>
    <?php else: ?>
         <div class="card" style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <i class='bx bx-folder-open' style="font-size: 3rem; margin-bottom: 1rem;"></i>
            <p>Este curso no tiene módulos estructurados en la base de datos.</p>
        </div>
    <?php endif; ?>

<?php endif; ?>
