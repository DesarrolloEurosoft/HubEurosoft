<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$courseId = $_GET['course_id'] ?? null;
$userId = $_SESSION['user_id'];

if (!$courseId) {
    echo "<div style='padding:2rem;text-align:center;'><h2>Error 400</h2><p>Parámetro de curso faltante en el vector de evaluación.</p></div>";
    return;
}

try {
    // 1. Obtener Entidad Curso y Quiz
$stmtCQ = $pdo->prepare("
    SELECT c.id as courseId, c.title as courseTitle, q.id as quizId, q.title as quizTitle
    FROM Course c
    JOIN Quiz q ON c.id = q.courseId
    WHERE c.id = ?
");
$stmtCQ->execute([$courseId]);
$data = $stmtCQ->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo "<div style='padding:4rem;text-align:center;'><h2 style='color:#475569;'>Sin Examen Final</h2><p style='color:#94a3b8;'>El administrador aún no despliega las rúbricas para este curso.</p><a href='index.php?view=courses' style='color:#4f46e5;font-weight:700;'>Regresar</a></div>";
    return;
}

$quizId = $data['quizId'];

// 2. Obtener Perfil de Aprobación Mínima (En base a TrainingRole del estudiante actual)
$stmtMinScore = $pdo->prepare("
    SELECT MIN(qpg.minimumScore) 
    FROM QuizPassingGrade qpg
    JOIN _TrainingRoleToUser tru ON qpg.trainingRoleId = tru.A
    WHERE qpg.quizId = ? AND tru.B = ?
");
$stmtMinScore->execute([$quizId, $userId]);
$passingScore = $stmtMinScore->fetchColumn();
if (!$passingScore) $passingScore = 80; // Hard Rule Default

// 3. Revisar los Récords Anteriores del Estudiante (Si está reintentando)
$stmtRecord = $pdo->prepare("SELECT quizScore, quizPassed, quizAttempts FROM CourseProgress WHERE courseId = ? AND userId = ?");
$stmtRecord->execute([$courseId, $userId]);
$record = $stmtRecord->fetch(PDO::FETCH_ASSOC);

$prevScore = $record ? $record['quizScore'] : null;
$prevPassed = $record ? $record['quizPassed'] : 0;
$prevAttempts = $record ? $record['quizAttempts'] : 0;

// 4. Bajar Todas Las Preguntas sin la marca Correcta para Evitar Trampa UI
$stmtQ = $pdo->prepare("SELECT id, text, questionType FROM Question WHERE quizId = ? ORDER BY `order` ASC, id ASC");
$stmtQ->execute([$quizId]);
$questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

foreach ($questions as &$q) {
    if ($q['questionType'] === 'MULTIPLE_CHOICE') {
        $stmtOpts = $pdo->prepare("SELECT id, text FROM `Option` WHERE questionId = ? ORDER BY createdAt ASC");
        $stmtOpts->execute([$q['id']]);
        $opts = $stmtOpts->fetchAll(PDO::FETCH_ASSOC);
        $q['options'] = $opts;
    } elseif ($q['questionType'] === 'MATCHING') {
        $stmtMatch = $pdo->prepare("SELECT id, leftItem, rightItem FROM MatchingPair WHERE questionId = ? ORDER BY `order` ASC");
        $stmtMatch->execute([$q['id']]);
        $pairs = $stmtMatch->fetchAll(PDO::FETCH_ASSOC);
        
        $rightOptions = [];
        foreach ($pairs as $p) {
            $rightOptions[] = ['id' => $p['id'], 'text' => $p['rightItem']];
        }
        shuffle($rightOptions);
        
        $q['matchingPairs'] = $pairs;
        $q['rightOptions'] = $rightOptions;
    }
}
unset($q);

$totalQuestions = count($questions);

} catch (Exception $e) {
    echo "<div style='padding:3rem;background:#fee2e2;color:#991b1b;border:2px solid #ef4444;margin:2rem;border-radius:12px;font-family:monospace;'>";
    echo "<h3>CRITICAL FATAL ERROR</h3>";
    echo "<p>" . $e->getMessage() . "</p>";
    echo "</div>";
    return;
}
?>

<style>
    .tq-layout { background: #f8fafc; min-height: calc(100vh - 60px); display: flex; flex-direction: column; font-family: 'Inter', sans-serif;}
    .tq-header { background: #1e293b; color: white !important; padding: 2.5rem 2rem 4rem 2rem; border-bottom: 4px solid #f97316; display: flex; flex-direction: column; gap: 1rem;}
    .tq-header h1, .tq-header p, .tq-header i { color: white !important; }
    .tq-header p { color: #f1f5f9 !important; }
    .tq-header-title { display: flex; align-items: center; justify-content: space-between; max-width: 900px; margin: 0 auto; width: 100%;}
    .tq-back { color: #cbd5e1 !important; text-decoration: none; font-size: 0.85rem; font-weight: 700; display: inline-flex; align-items: center; gap: 0.3rem;}
    .tq-back:hover { color: white !important; }
    
    .tq-stats { max-width: 900px; margin: 0 auto; width: 100%; display: flex; gap: 1rem; align-items: center;}
    .tq-badge { background: rgba(255,255,255,0.1); padding: 0.5rem 1rem; border-radius: 99px; font-size: 0.8rem; font-weight: 800; display: inline-flex; align-items: center; gap: 0.5rem; border: 1px solid rgba(255,255,255,0.05);}
    .tq-badge-score { background: rgba(249, 115, 22, 0.2); color: #fed7aa; border-color: rgba(249, 115, 22, 0.3); }

    .tq-body { max-width: 900px; margin: -2rem auto 4rem auto; width: 100%; padding: 0 1rem; position: relative;}
    
    .tq-history { background: white; border-radius: 16px; padding: 1.5rem; margin-bottom: 2rem; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; <?php if(!$prevAttempts) echo 'display:none;'; ?>}
    
    .tq-question-card { background: white; border-radius: 16px; padding: 2.5rem; margin-bottom: 2rem; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; position: relative;}
    .tq-q-number { position: absolute; left: 0; top: 1.5rem; background: #6366f1; color: white; padding: 0.4rem 1rem 0.4rem 0.6rem; border-radius: 0 99px 99px 0; font-size: 0.75rem; font-weight: 900;}
    .tq-q-text { font-size: 1.15rem; font-weight: 700; color: #1e293b; margin: 1.5rem 0 2rem 0; line-height: 1.5;}
    
    .tq-options { display: flex; flex-direction: column; gap: 0.8rem;}
    .tq-option { display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid #e2e8f0; border-radius: 12px; cursor: pointer; transition: all 0.2s;}
    .tq-option:hover { border-color: #cbd5e1; background: #f8fafc; }
    .tq-option input[type="radio"] { width: 1.2rem; height: 1.2rem; accent-color: #f97316; cursor: pointer;}
    .tq-option.selected { border-color: #f97316; background: #fff7ed; box-shadow: 0 4px 10px rgba(249, 115, 22, 0.1);}
    
    .tq-alert { padding: 1rem 1.5rem; border-radius: 12px; font-weight: 600; margin-bottom: 2rem; display: none; align-items: center; justify-content: space-between;}
    .tq-alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;}
    .tq-alert-fail { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;}
</style>

<div class="tq-layout">
    <div class="tq-header">
        <div class="tq-header-title">
            <div>
                <a href="index.php?view=courses" class="tq-back"><i class='bx bx-left-arrow-alt'></i> ABORTAR EXAMEN</a>
                <h1 style="margin: 0.5rem 0 0 0; font-size: 2.2rem; font-weight: 900; letter-spacing: -0.02em; color: white !important;"><?= htmlspecialchars($data['quizTitle']) ?></h1>
                <p style="margin: 0.5rem 0 0 0; color: #cbd5e1 !important; font-size: 0.95rem;">Correspondiente al programa: <strong style="color: white !important;"><?= htmlspecialchars($data['courseTitle']) ?></strong></p>
            </div>
            <i class='bx bxs-graduation' style="font-size: 4rem; color: rgba(255,255,255,0.15) !important;"></i>
        </div>
        <div class="tq-stats">
            <span class="tq-badge tq-badge-score"><i class='bx bx-target-lock'></i> Requerido: <?= $passingScore ?>%</span>
            <span class="tq-badge"><i class='bx bx-list-ol'></i> <?= $totalQuestions ?> Preguntas</span>
        </div>
    </div>

    <div class="tq-body">
        
        <div class="tq-history">
            <div>
                <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; color: #0f172a;">Registro de Progreso Histórico</h3>
                <p style="margin: 0; font-size: 0.85rem; color: #64748b;">
                    Llevas <strong><?= (int)$prevAttempts ?> intentos</strong> consumidos. Tu calificación activa en el sistema es 
                    📝 <strong style="color: <?= $prevPassed ? '#059669' : '#dc2626' ?>"><?= (int)$prevScore ?>%</strong>.
                </p>
            </div>
            <?php if($prevAttempts > 0 && $prevPassed): ?>
                <span style="background: #10b981; color: white; padding: 0.5rem 1rem; border-radius: 10px; font-weight: 800; font-size: 0.8rem; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);">ESTADO: APROBADO</span>
            <?php elseif($prevAttempts > 0 && !$prevPassed): ?>
                <span style="background: #ef4444; color: white; padding: 0.5rem 1rem; border-radius: 10px; font-weight: 800; font-size: 0.8rem; box-shadow: 0 4px 10px rgba(239, 68, 68, 0.3);">ESTADO: REPROBADO</span>
            <?php endif; ?>
        </div>
        
        <div id="alertBox" class="tq-alert">
            <span id="alertMsg"></span>
            <button onclick="window.location.href='index.php?view=courses'" style="background: rgba(0,0,0,0.1); border: none; padding: 0.5rem 1rem; border-radius: 8px; font-weight: 700; cursor: pointer; color: inherit;">Regresar al Catálogo ➔</button>
        </div>

        <?php if($totalQuestions > 0): ?>
            <form id="quizForm">
                <?php foreach($questions as $index => $q): ?>
                    <div class="tq-question-card">
                        <div class="tq-q-number">BLOQUE <?= $index + 1 ?></div>
                        <div class="tq-q-text"><?= nl2br(htmlspecialchars($q['text'])) ?></div>
                        
                        <div class="tq-options">
                            <?php if($q['questionType'] === 'MULTIPLE_CHOICE'): ?>
                                <?php foreach($q['options'] as $opt): ?>
                                    <label class="tq-option">
                                        <input type="radio" name="q_<?= $q['id'] ?>" value="<?= htmlspecialchars($opt['id']) ?>" required>
                                        <span style="font-size: 0.95rem; font-weight: 500; color: #334155;"><?= htmlspecialchars($opt['text']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            <?php elseif($q['questionType'] === 'MATCHING'): ?>
                                <div style="display:flex; flex-direction:column; gap:1rem;">
                                    <?php foreach($q['matchingPairs'] as $pair): ?>
                                        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:1rem; align-items:center; background:#f8fafc; padding:1.2rem; border:1px solid #e2e8f0; border-radius:12px;">
                                            <div style="font-weight:700; color:#1e293b; font-size:0.95rem;"><?= htmlspecialchars($pair['leftItem']) ?></div>
                                            <select name="q_<?= $q['id'] ?>_<?= $pair['id'] ?>" style="padding:0.8rem; border-radius:8px; border:1px solid #cbd5e1; outline:none; background:white; font-weight:600; color:#334155; cursor:pointer;" required>
                                                <option value="">-- Correlacionar Opción --</option>
                                                <?php foreach($q['rightOptions'] as $ropt): ?>
                                                    <option value="<?= htmlspecialchars($ropt['id']) ?>"><?= htmlspecialchars($ropt['text']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif($q['questionType'] === 'OPEN_ENDED'): ?>
                                <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                    <textarea name="q_<?= $q['id'] ?>" class="form-control" rows="5" placeholder="Desarrolla aquí tu respuesta argumentativa..." required style="width:100%; padding:1rem; border-radius:12px; border:2px solid #e2e8f0; outline:none; font-family:'Inter', sans-serif; resize:vertical; font-size:1rem;"></textarea>
                                    <span style="font-size:0.75rem; color:#64748b; font-weight:600;"><i class='bx bx-info-circle'></i> Las preguntas de ensayo serán enviadas a la Mesa Evaluadora de Instructores para su validación manual.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <div style="text-align: right; margin-top: 2rem;">
                    <button type="submit" id="btnSubmitQuiz" style="background: #f97316; color: white; padding: 1rem 2rem; border-radius: 12px; border: none; font-size: 1.1rem; font-weight: 800; cursor: pointer; transition: all 0.2s; box-shadow: 0 10px 20px rgba(249, 115, 22, 0.3);">
                        <i class='bx bx-paper-plane'></i> ENVIAR EVALUACIÓN AL SERVIDOR
                    </button>
                </div>
            </form>
        <?php else: ?>
            <div style="text-align: center; padding: 4rem; background: white; border-radius: 16px;">
                <h3 style="color: #64748b;">Aviso Importante</h3>
                <p style="color: #94a3b8;">El examen general está estructurado temporalmente sin banco de reactivos. Contacta a un administrador.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const form = document.getElementById('quizForm');
    
    // UI Selector Highlights
    if (form) {
        form.addEventListener('change', (e) => {
            if(e.target.type === 'radio') {
                const group = document.querySelectorAll(`input[name="${e.target.name}"]`);
                group.forEach(input => {
                    input.closest('.tq-option').classList.remove('selected');
                });
                if(e.target.checked) {
                    e.target.closest('.tq-option').classList.add('selected');
                }
            }
        });
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            if(!confirm('¿Estás 100% seguro de tus respuestas? Una vez enviadas, el resultado final afectará tu puntaje en vivo.')) return;
            
            const btn = document.getElementById('btnSubmitQuiz');
            const originalBtnHtml = btn.innerHTML;
            btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Calificando Motor Remoto...";
            btn.disabled = true;
            btn.style.background = '#94a3b8';
            btn.style.boxShadow = 'none';
            btn.style.cursor = 'not-allowed';
            
            const rawData = new FormData(form);
            const answers = {};
            for(let [key, value] of rawData.entries()) {
                if(key.startsWith('q_') && key.split('_').length === 3) {
                    const parts = key.split('_');
                    const qId = parts[1];
                    const pairId = parts[2];
                    if(!answers[qId]) answers[qId] = {};
                    answers[qId][pairId] = value;
                } else {
                    const pureQid = key.replace('q_', '');
                    answers[pureQid] = value;
                }
            }
            
            try {
                const res = await fetch('api_submit_quiz.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        courseId: "<?= $courseId ?>",
                        quizId: "<?= $quizId ?>",
                        answers: answers
                    })
                });
                
                const data = await res.json();
                
                if(data.success) {
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                    
                    const alertBox = document.getElementById('alertBox');
                    const alertMsg = document.getElementById('alertMsg');
                    
                    alertBox.style.display = 'flex';
                    if (data.pendingManual) {
                        alertBox.className = 'tq-alert';
                        alertBox.style.background = '#e0f2fe';
                        alertBox.style.color = '#0369a1';
                        alertBox.style.borderColor = '#bae6fd';
                        alertMsg.innerHTML = `<i class='bx bx-time-five' style="font-size:1.5rem; vertical-align:middle; margin-right:0.5rem;"></i> ${data.message}`;
                        
                        btn.style.display = 'none';
                        const options = document.querySelectorAll('.tq-option input, .tq-option textarea, .tq-option select');
                        options.forEach(opt => opt.disabled = true);
                        
                    } else if (data.passed) {
                        alertBox.className = 'tq-alert tq-alert-success';
                        alertMsg.innerHTML = `<i class='bx bxs-party' style="font-size:1.5rem; vertical-align:middle; margin-right:0.5rem;"></i> ¡Felicidades, la Máquina te ha dictaminado APROBADO con un <strong>${data.score}%</strong> (${data.correctCount}/${data.totalQuestions} aciertos)! Tu ruta de aprendizaje ha sido actualizada exitosamente.`;
                        
                        btn.style.display = 'none';
                        const options = document.querySelectorAll('.tq-option input');
                        options.forEach(opt => opt.disabled = true);
                        
                    } else {
                        alertBox.className = 'tq-alert tq-alert-fail';
                        alertMsg.innerHTML = `<i class='bx bxs-error-circle' style="font-size:1.5rem; vertical-align:middle; margin-right:0.5rem;"></i> Logro Fallido. Has procesado un <strong>${data.score}%</strong> de rendimiento, el cual es inferior a la norma corporativa dictaminada de ${data.passingScore}%. Puedes intentarlo nuevamente.`;
                        
                        btn.disabled = false;
                        btn.innerHTML = "<i class='bx bx-refresh'></i> REINTENTAR EVALUACIÓN (BORRÓN Y CUENTA NUEVA)";
                        btn.style.background = '#ea580c';
                        btn.style.boxShadow = '0 10px 20px rgba(234, 88, 12, 0.3)';
                        btn.style.cursor = 'pointer';
                        
                        // Clear radios
                        form.reset();
                        const selectedOpts = document.querySelectorAll('.tq-option.selected');
                        selectedOpts.forEach(opt => opt.classList.remove('selected'));
                    }
                    
                } else {
                    alert('CRITICAL ERROR: ' + (data.error || 'Server connection failed.'));
                    btn.disabled = false;
                    btn.innerHTML = originalBtnHtml;
                    btn.style.background = '#f97316';
                    btn.style.cursor = 'pointer';
                }
                
            } catch(e) {
                console.error(e);
                alert("Error de integridad de red al intentar mandar respuestas. Contactar Mesa de Servicio.");
                btn.disabled = false;
                btn.innerHTML = originalBtnHtml;
                btn.style.background = '#f97316';
                btn.style.cursor = 'pointer';
            }
        });
    }
</script>
