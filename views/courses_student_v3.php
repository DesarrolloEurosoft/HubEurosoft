<?php
/**
 * V3 Student Courses View — 1:1 from ClientMisCursos.tsx
 * This file replaces lines 339-611 of courses.php (student rendering)
 * Backend data ($allCoursesFlat, $totalCourses, etc.) is prepared above.
 */

function resolveImgUrl($url) {
    if (empty($url)) return '';
    $basePath = dirname($_SERVER['SCRIPT_NAME']);
    if ($basePath !== '/' && strpos($url, '/uploads') === 0) return $basePath . $url;
    return ltrim($url, '/');
}

function getStatusCfg($status) {
    switch ($status) {
        case 'completed': return ['label'=>'Completado','bg'=>'#f0fdf4','text'=>'#15803d','border'=>'#bbf7d0','icon'=>'bx-check-circle'];
        case 'in-progress': return ['label'=>'En Progreso','bg'=>'#fff7ed','text'=>'#FF6A00','border'=>'#fed7aa','icon'=>'bx-play-circle'];
        case 'quiz-pending': return ['label'=>'Examen Pendiente','bg'=>'#fef2f2','text'=>'#dc2626','border'=>'#fecaca','icon'=>'bx-error-circle'];
        case 'locked': return ['label'=>'Bloqueado','bg'=>'#f9fafb','text'=>'#374151','border'=>'#e5e7eb','icon'=>'bx-lock'];
        default: return ['label'=>'Disponible','bg'=>'#eff6ff','text'=>'#1d4ed8','border'=>'#bfdbfe','icon'=>'bx-play-circle'];
    }
}
?>

<div style="max-width:1920px;margin:0 auto;padding:1rem 1.5rem 2rem;">
    <h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 1.5rem 0;">Mis Cursos</h1>

    <!-- Filters + Search -->
    <div style="background:white;border-radius:1rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;margin-bottom:1.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;">
            <div style="display:flex;align-items:center;gap:8px;background:#f3f4f6;border-radius:999px;padding:8px;" id="v3FilterContainer">
                <button class="v3-filter active" data-filter="all" onclick="v3Filter(this)">Todos (<?= $totalCourses ?>)</button>
                <button class="v3-filter" data-filter="in-progress" onclick="v3Filter(this)">En Progreso (<?= $inProgressCourses ?>)</button>
                <button class="v3-filter" data-filter="locked" onclick="v3Filter(this)">Bloqueados (<?= count(array_filter($allCoursesFlat, fn($c)=>$c['status']==='locked')) ?>)</button>
            </div>
            <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:280px;max-width:28rem;">
                <div style="position:relative;flex:1;">
                    <i class='bx bx-search' style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i>
                    <input type="text" id="v3SearchInput" placeholder="Buscar cursos..." oninput="v3ApplyFilters()" style="width:100%;padding:10px 16px 10px 44px;background:#f9fafb;border-radius:999px;border:1px solid #e5e7eb;color:#111827;font-size:0.875rem;outline:none;" onfocus="this.style.boxShadow='0 0 0 2px #FF6A00';this.style.borderColor='#FF6A00'" onblur="this.style.boxShadow='none';this.style.borderColor='#e5e7eb'">
                </div>
                <div style="display:flex;align-items:center;gap:4px;background:#f3f4f6;border-radius:999px;padding:4px;">
                    <button id="v3BtnGrid" onclick="v3SetView('grid')" style="padding:8px;border-radius:50%;border:none;cursor:pointer;background:#FF6A00;color:white;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;align-items:center;justify-content:center;"><i class='bx bx-grid-alt'></i></button>
                    <button id="v3BtnList" onclick="v3SetView('list')" style="padding:8px;border-radius:50%;border:none;cursor:pointer;background:transparent;color:#6b7280;display:flex;align-items:center;justify-content:center;"><i class='bx bx-list-ul'></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Course Grid -->
    <div id="v3CourseContainer" style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:1.5rem;">
    <?php foreach ($allCoursesFlat as $idx => $c):
        $isLocked = $c['status'] === 'locked';
        $badge = getStatusCfg($c['status']);
        $imgUrl = resolveImgUrl($c['imageUrl'] ?? '');
        $clickUrl = $isLocked ? '#' : ($c['status'] === 'quiz-pending' ? 'index.php?view=take_quiz&course_id='.urlencode($c['id']) : 'index.php?view=lesson&course_id='.urlencode($c['id']));
        $btnLabel = $c['status']==='completed' ? 'Revisar' : ($isLocked ? 'Bloqueado' : ($c['status']==='quiz-pending' ? 'Presentar Examen' : 'Continuar'));
        $btnIcon = $c['status']==='completed' ? 'bx-check-circle' : ($isLocked ? 'bx-lock' : 'bx-play-circle');
    ?>
    <div class="v3-course" data-title="<?= strtolower(htmlspecialchars($c['title'])) ?>" data-status="<?= $c['status'] ?>"
         style="background:white;border-radius:1rem;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;transition:all 0.3s;<?= $isLocked ? 'opacity:0.6;' : 'cursor:pointer;' ?>"
         <?= !$isLocked ? "onmouseover=\"this.style.boxShadow='0 20px 25px -5px rgba(0,0,0,0.1)';this.style.transform='translateY(-4px)'\" onmouseout=\"this.style.boxShadow='0 1px 2px rgba(0,0,0,0.05)';this.style.transform='translateY(0)'\"" : '' ?>
         <?= !$isLocked ? "onclick=\"window.location.href='$clickUrl'\"" : '' ?>>
        <div style="position:relative;height:192px;overflow:hidden;">
            <?php if ($imgUrl): ?>
                <img src="<?= htmlspecialchars($imgUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;transition:transform 0.5s;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:linear-gradient(135deg,#fff7ed,#fefce8);"><i class='bx bx-book-open' style="font-size:2.5rem;color:rgba(255,106,0,0.3);"></i></div>
            <?php else: ?>
                <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#fff7ed,#fefce8);"><i class='bx bx-book-open' style="font-size:2.5rem;color:rgba(255,106,0,0.3);"></i></div>
            <?php endif; ?>
            <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.6),transparent);"></div>
            <?php if ($isLocked): ?><div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;"><i class='bx bx-lock' style="font-size:1.5rem;color:rgba(255,255,255,0.7);"></i></div><?php endif; ?>
            <div style="position:absolute;top:16px;left:16px;background:<?= $badge['bg'] ?>;border:1px solid <?= $badge['border'] ?>;padding:6px 12px;border-radius:8px;backdrop-filter:blur(4px);display:flex;align-items:center;gap:6px;">
                <i class='bx <?= $badge['icon'] ?>' style="font-size:0.875rem;color:<?= $badge['text'] ?>;"></i>
                <span style="font-size:0.75rem;font-weight:600;color:<?= $badge['text'] ?>;"><?= $badge['label'] ?></span>
            </div>
            <?php if (!empty($c['description'])): ?>
            <div style="position:absolute;bottom:16px;left:16px;"><span style="font-size:0.75rem;color:rgba(255,255,255,0.9);background:rgba(255,255,255,0.2);backdrop-filter:blur(4px);padding:4px 12px;border-radius:999px;"><?= htmlspecialchars(mb_strimwidth($c['description'],0,30,'…')) ?></span></div>
            <?php endif; ?>
        </div>
        <div style="padding:1.25rem;">
            <h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0 0 8px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($c['title']) ?></h3>
            <div style="display:flex;align-items:center;gap:1rem;font-size:0.875rem;color:#6b7280;margin-bottom:1rem;">
                <span style="display:flex;align-items:center;gap:6px;"><i class='bx bx-book-open'></i> <?= $c['lessonsCount'] ?> lecciones</span>
                <span style="display:flex;align-items:center;gap:6px;"><i class='bx bx-time-five'></i> <?= $c['duration'] ?></span>
            </div>
            <div style="margin-bottom:1rem;">
                <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#4b5563;margin-bottom:8px;">
                    <span>Progreso</span>
                    <span style="font-weight:700;color:#FF6A00;"><?= $c['progressPercent'] ?>%</span>
                </div>
                <div style="height:8px;background:#f3f4f6;border-radius:999px;overflow:hidden;">
                    <div style="height:100%;width:<?= $c['progressPercent'] ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div>
                </div>
            </div>
            <a href="<?= $clickUrl ?>" onclick="event.stopPropagation()" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border-radius:12px;font-weight:600;font-size:0.875rem;text-decoration:none;transition:all 0.3s;
                <?= $isLocked ? 'background:#f3f4f6;color:#9ca3af;pointer-events:none;' : 'background:linear-gradient(to right,#FF6A00,#FFA500);color:white;' ?>"
                <?= !$isLocked ? "onmouseover=\"this.style.boxShadow='0 10px 15px rgba(255,106,0,0.3)'\" onmouseout=\"this.style.boxShadow='none'\"" : '' ?>>
                <i class='bx <?= $btnIcon ?>'></i> <?= $btnLabel ?>
            </a>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <?php if (empty($allCoursesFlat)): ?>
    <div style="background:white;border-radius:1rem;padding:3rem;border:1px solid #f3f4f6;text-align:center;margin-bottom:1.5rem;">
        <i class='bx bx-book-open' style="font-size:2.5rem;color:#d1d5db;display:block;margin-bottom:1rem;"></i>
        <p style="font-size:0.875rem;font-weight:600;color:#6b7280;">No hay cursos disponibles</p>
        <p style="font-size:0.75rem;color:#9ca3af;margin:4px 0 0;">Contacta a soporte.</p>
    </div>
    <?php endif; ?>

    <!-- Hero: 2 Column Grid -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">

        <!-- Left: Dark Card -->
        <div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1rem;padding:1.5rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);border:1px solid #374151;color:white;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;right:0;width:128px;height:128px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(40px);"></div>
            <div style="position:absolute;bottom:0;left:0;width:96px;height:96px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(-50%,50%);filter:blur(40px);"></div>
            <div style="position:relative;z-index:10;">
                <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
                    <div style="width:48px;height:48px;border-radius:1rem;background:rgba(255,106,0,0.2);display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,106,0,0.3);">
                        <i class='bx bx-target-lock' style="color:#FF6A00;font-size:1.5rem;"></i>
                    </div>
                    <div>
                        <h2 style="font-size:1.125rem;font-weight:700;color:white;margin:0;">Ruta de Aprendizaje</h2>
                        <p style="font-size:0.875rem;color:#9ca3af;margin:0;">Cursos asignados</p>
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem;">
                    <div style="text-align:center;"><p style="font-size:1.5rem;font-weight:700;color:white;margin:0;"><?= $totalCourses ?></p><p style="font-size:0.75rem;color:#9ca3af;margin:0;">Total</p></div>
                    <div style="text-align:center;"><p style="font-size:1.5rem;font-weight:700;color:#FF6A00;margin:0;"><?= $inProgressCourses ?></p><p style="font-size:0.75rem;color:#9ca3af;margin:0;">En Progreso</p></div>
                    <div style="text-align:center;"><p style="font-size:1.5rem;font-weight:700;color:#4ade80;margin:0;"><?= $completedCourses ?></p><p style="font-size:0.75rem;color:#9ca3af;margin:0;">Completados</p></div>
                </div>
                <div style="margin-bottom:1rem;">
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;color:#d1d5db;margin-bottom:8px;">
                        <span style="font-weight:500;">Progreso Total</span>
                        <span style="font-weight:700;color:#FF6A00;"><?= $overallProgress ?>%</span>
                    </div>
                    <div style="height:12px;background:rgba(255,255,255,0.1);border-radius:999px;overflow:hidden;">
                        <div style="height:100%;width:<?= $overallProgress ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div>
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,0.05);border-radius:12px;padding:12px 16px;border:1px solid rgba(255,255,255,0.1);">
                    <i class='bx bx-briefcase' style="color:#FF6A00;font-size:1.125rem;"></i>
                    <div>
                        <p style="font-size:0.75rem;color:#9ca3af;margin:0;">Rol</p>
                        <p style="font-size:0.875rem;font-weight:700;color:white;margin:0;"><?= htmlspecialchars($assignedRoleName) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Continue Card -->
        <div style="background:white;border-radius:1rem;padding:1.5rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;">
                <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;">
                    <i class='bx bx-play-circle' style="color:white;font-size:1.5rem;"></i>
                </div>
                <div>
                    <h2 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">Continúa donde lo dejaste</h2>
                    <p style="font-size:0.875rem;color:#6b7280;margin:0;">Última actividad reciente</p>
                </div>
            </div>
            <?php if ($nextCourseData): ?>
                <div style="background:linear-gradient(135deg,#f9fafb,#f3f4f6);border-radius:12px;padding:1rem;margin-bottom:1rem;border:1px solid #e5e7eb;">
                    <h3 style="font-weight:700;color:#111827;margin:0 0 8px;font-size:1rem;"><?= htmlspecialchars($nextCourseData['title']) ?></h3>
                    <div style="display:flex;align-items:center;gap:12px;font-size:0.875rem;color:#4b5563;margin-bottom:12px;">
                        <span style="display:flex;align-items:center;gap:6px;"><i class='bx bx-book-open'></i> <?= $nextCourseData['lessonsCount'] ?> lecciones</span>
                        <span style="display:flex;align-items:center;gap:6px;"><i class='bx bx-time-five'></i> <?= $nextCourseData['duration'] ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#4b5563;margin-bottom:6px;">
                        <span>Progreso del curso</span>
                        <span style="font-weight:700;color:#FF6A00;"><?= $nextCourseData['progressPercent'] ?>%</span>
                    </div>
                    <div style="height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;">
                        <div style="height:100%;width:<?= $nextCourseData['progressPercent'] ?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div>
                    </div>
                </div>
                <a href="index.php?view=lesson&course_id=<?= urlencode($nextCourseData['id']) ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;background:linear-gradient(to right,#FF6A00,#FFA500);color:white;font-weight:700;padding:12px;border-radius:12px;text-decoration:none;font-size:1rem;" onmouseover="this.style.boxShadow='0 10px 15px rgba(255,106,0,0.3)'" onmouseout="this.style.boxShadow='none'">
                    Continuar Aprendiendo <i class='bx bx-right-arrow-alt'></i>
                </a>
            <?php else: ?>
                <div style="background:#f9fafb;border-radius:12px;padding:2rem;text-align:center;">
                    <i class='bx bx-book-open' style="font-size:2rem;color:#d1d5db;display:block;margin-bottom:12px;"></i>
                    <p style="font-size:0.875rem;color:#6b7280;margin:0;">No hay cursos en progreso</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
.v3-filter{padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:500;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#4b5563;}
.v3-filter.active{background:white;color:#111827;box-shadow:0 1px 3px rgba(0,0,0,0.1);}
.v3-filter:not(.active):hover{color:#111827;}
</style>
<script>
let v3CurrentFilter='all';
function v3Filter(btn){document.querySelectorAll('.v3-filter').forEach(b=>b.classList.remove('active'));btn.classList.add('active');v3CurrentFilter=btn.getAttribute('data-filter');v3ApplyFilters();}
function v3ApplyFilters(){const q=(document.getElementById('v3SearchInput')?.value||'').toLowerCase();document.querySelectorAll('.v3-course').forEach(card=>{const t=card.getAttribute('data-title'),s=card.getAttribute('data-status');card.style.display=(t.includes(q)&&(v3CurrentFilter==='all'||v3CurrentFilter===s))?'':'none';});}
function v3SetView(mode){const c=document.getElementById('v3CourseContainer'),g=document.getElementById('v3BtnGrid'),l=document.getElementById('v3BtnList');if(mode==='grid'){c.style.gridTemplateColumns='repeat(3,1fr)';g.style.background='#FF6A00';g.style.color='white';l.style.background='transparent';l.style.color='#6b7280';}else{c.style.gridTemplateColumns='1fr';l.style.background='#FF6A00';l.style.color='white';g.style.background='transparent';g.style.color='#6b7280';}}
</script>
