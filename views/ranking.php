<?php
if (!defined('DB_HOST') && !isset($pdo)) { die('Direct access not permitted'); }

$userId = $_SESSION['user_id'];

// ── 1. Datos del usuario ──────────────────────────────────────────────────────
$stmtCmp = $pdo->prepare("SELECT companyId, businessUnitId FROM User WHERE id = ?");
$stmtCmp->execute([$userId]);
$uRow      = $stmtCmp->fetch(PDO::FETCH_ASSOC);
$companyId = $uRow ? $uRow['companyId'] : null;
$buId      = $uRow ? $uRow['businessUnitId'] : null;

// ── Detectar si el usuario es Lector Operativo ───────────────────────────────
$isLectorOp = false;
if ($userId) {
    $stmtLO = $pdo->prepare("SELECT 1 FROM TrainingRole tr JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id WHERE rtu.B = ? AND LOWER(tr.name) LIKE '%lector%operativo%' LIMIT 1");
    $stmtLO->execute([$userId]);
    $isLectorOp = (bool)$stmtLO->fetchColumn();
}
// Etiquetas de contexto según rol
$rankingTitle   = $isLectorOp ? 'Ranking · Lectores Operativos' : 'Ranking';
$tabGeneralLbl  = $isLectorOp ? 'Mi Empresa' : 'General';
$tabTeamLbl     = $isLectorOp ? 'Mi Unidad'  : null; // null = usar $buName

$buName = 'Mi Equipo';
if ($buId) {
    $stmtBN = $pdo->prepare("SELECT name FROM BusinessUnit WHERE id = ?");
    $stmtBN->execute([$buId]);
    $bnRow = $stmtBN->fetch(PDO::FETCH_ASSOC);
    if ($bnRow) $buName = $bnRow['name'];
}

// Nombre de la empresa
$companyName = 'Empresa';
if ($companyId) {
    // Intentar tabla Company primero, luego Organization como fallback
    try {
        $stmtCo = $pdo->prepare("SELECT name FROM Company WHERE id = ? LIMIT 1");
        $stmtCo->execute([$companyId]);
        $coRow = $stmtCo->fetch(PDO::FETCH_ASSOC);
        if ($coRow) $companyName = $coRow['name'];
    } catch (Exception $e) {
        try {
            $stmtCo = $pdo->prepare("SELECT name FROM Organization WHERE id = ? LIMIT 1");
            $stmtCo->execute([$companyId]);
            $coRow = $stmtCo->fetch(PDO::FETCH_ASSOC);
            if ($coRow) $companyName = $coRow['name'];
        } catch (Exception $e2) { /* fallback: 'Empresa' */ }
    }
}

function getDisplayNameR($u) {
    if (!empty($u['firstName'])) return trim($u['firstName'] . ' ' . ($u['lastName'] ?? ''));
    return $u['name'] ?? 'Estudiante';
}

// ── 2. Query Mi Equipo ────────────────────────────────────────────────────────
if ($companyId && $buId) {
    $s = $pdo->prepare("
        SELECT u.id, u.name, u.firstName, u.lastName, u.totalPoints, u.image,
               COALESCE(bu.name,'Sin Unidad') as buName,
               (SELECT COUNT(cp.id) FROM CourseProgress cp WHERE cp.userId=u.id AND cp.isCompleted=1) as coursesCompleted
        FROM User u LEFT JOIN BusinessUnit bu ON u.businessUnitId=bu.id
        WHERE u.role='STUDENT' AND u.companyId=? AND u.businessUnitId=?
        ORDER BY u.totalPoints DESC LIMIT 50");
    $s->execute([$companyId, $buId]);
    $usersTeam = $s->fetchAll(PDO::FETCH_ASSOC);
} else { $usersTeam = []; }

// ── 3. Query General ──────────────────────────────────────────────────────────
if ($companyId) {
    $s = $pdo->prepare("
        SELECT u.id, u.name, u.firstName, u.lastName, u.totalPoints, u.image,
               COALESCE(bu.name,'Sin Unidad') as buName,
               (SELECT COUNT(cp.id) FROM CourseProgress cp WHERE cp.userId=u.id AND cp.isCompleted=1) as coursesCompleted
        FROM User u LEFT JOIN BusinessUnit bu ON u.businessUnitId=bu.id
        WHERE u.role='STUDENT' AND u.companyId=?
        ORDER BY u.totalPoints DESC LIMIT 50");
    $s->execute([$companyId]);
    $usersGeneral = $s->fetchAll(PDO::FETCH_ASSOC);
} else {
    $usersGeneral = $pdo->query("
        SELECT u.id, u.name, u.firstName, u.lastName, u.totalPoints, u.image,
               COALESCE(bu.name,'Sin Unidad') as buName,
               (SELECT COUNT(cp.id) FROM CourseProgress cp WHERE cp.userId=u.id AND cp.isCompleted=1) as coursesCompleted
        FROM User u LEFT JOIN BusinessUnit bu ON u.businessUnitId=bu.id
        WHERE u.role='STUDENT' ORDER BY u.totalPoints DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
}
if (empty($usersTeam)) { $usersTeam = $usersGeneral; }

// ── Para Lectores Operativos: reemplazar queries con filtro por TrainingRole ──
if ($isLectorOp) {
    $loJoin = "JOIN _TrainingRoleToUser rtu ON rtu.B = u.id JOIN TrainingRole tr ON rtu.A = tr.id AND LOWER(tr.name) LIKE '%lector%operativo%'";
    if ($companyId && $buId) {
        $s = $pdo->prepare("
            SELECT u.id, u.name, u.firstName, u.lastName, u.totalPoints, u.image,
                   COALESCE(bu.name,'Sin Unidad') as buName,
                   (SELECT COUNT(cp.id) FROM CourseProgress cp WHERE cp.userId=u.id AND cp.isCompleted=1) as coursesCompleted
            FROM User u LEFT JOIN BusinessUnit bu ON u.businessUnitId=bu.id
            $loJoin
            WHERE u.role='STUDENT' AND u.companyId=? AND u.businessUnitId=?
            ORDER BY u.totalPoints DESC LIMIT 50");
        $s->execute([$companyId, $buId]);
        $usersTeam = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($companyId) {
        $s = $pdo->prepare("
            SELECT u.id, u.name, u.firstName, u.lastName, u.totalPoints, u.image,
                   COALESCE(bu.name,'Sin Unidad') as buName,
                   (SELECT COUNT(cp.id) FROM CourseProgress cp WHERE cp.userId=u.id AND cp.isCompleted=1) as coursesCompleted
            FROM User u LEFT JOIN BusinessUnit bu ON u.businessUnitId=bu.id
            $loJoin
            WHERE u.role='STUDENT' AND u.companyId=?
            ORDER BY u.totalPoints DESC LIMIT 50");
        $s->execute([$companyId]);
        $usersGeneral = $s->fetchAll(PDO::FETCH_ASSOC);
    }
    if (empty($usersTeam)) { $usersTeam = $usersGeneral; }
}

// ── 4. Tops ───────────────────────────────────────────────────────────────────
$top5General = array_slice($usersGeneral, 0, 5);
$top3Team    = array_slice($usersTeam,    0, 3);
$top3General = array_slice($usersGeneral, 0, 3);

// ── 5. Mis stats ──────────────────────────────────────────────────────────────
$myRankTeam=0; $myPtsTeam=0; $myDoneTeam=0;
foreach ($usersTeam    as $i=>$u) { if($u['id']===$userId){$myRankTeam=$i+1;$myPtsTeam=(int)$u['totalPoints'];$myDoneTeam=(int)$u['coursesCompleted'];break;} }
$myRankGen=0;  $myPtsGen=0;  $myDoneGen=0;
foreach ($usersGeneral as $i=>$u) { if($u['id']===$userId){$myRankGen=$i+1; $myPtsGen=(int)$u['totalPoints']; $myDoneGen=(int)$u['coursesCompleted']; break;} }

// Puntos para top 3 en cada contexto
$ptsTop3Team = max(0, (isset($top3Team[2])    ? (int)$top3Team[2]['totalPoints']    : 0) - $myPtsTeam + 1);
$ptsTop3Gen  = max(0, (isset($top3General[2]) ? (int)$top3General[2]['totalPoints'] : 0) - $myPtsGen  + 1);

// ── 6. Historial de puntos ────────────────────────────────────────────────────
$stmtH = $pdo->prepare("SELECT id,points,actionType,description,createdAt FROM UserPoints WHERE userId=? ORDER BY createdAt DESC LIMIT 8");
$stmtH->execute([$userId]);
$myPointsHistory = $stmtH->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers de estilo del podio ───────────────────────────────────────────────
$podiumColors = [
    1 => 'linear-gradient(to top,#eab308,#facc15)',
    2 => 'linear-gradient(to top,#9ca3af,#d1d5db)',
    3 => 'linear-gradient(to top,#f97316,#fb923c)',
    4 => 'linear-gradient(to top,#2563eb,#60a5fa)',
    5 => 'linear-gradient(to top,#7c3aed,#c084fc)',
];
$borderColors = [1=>'#facc15',2=>'#9ca3af',3=>'#f97316',4=>'#60a5fa',5=>'#c084fc'];
?>

<div style="max-width:1920px;margin:0 auto;padding:1rem 1.5rem 2rem;">
<h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 1.5rem 0;"><?= htmlspecialchars($rankingTitle) ?></h1>

<!-- ════════════════════════════════════════════════════════════════════════════
     PODIO — carrusel interno: slide 0 = Top 3 Mi Equipo | slide 1 = Top 5 General
     Ambos slides usan el mismo layout de columnas para altura uniforme.
════════════════════════════════════════════════════════════════════════════ -->
<div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1rem;padding:2rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);border:1px solid #374151;margin-bottom:1.5rem;position:relative;overflow:hidden;">
    <div style="position:absolute;top:0;right:0;width:256px;height:256px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(48px);pointer-events:none;"></div>
    <div style="position:absolute;bottom:0;left:0;width:256px;height:256px;background:rgba(234,179,8,0.1);border-radius:50%;transform:translate(-50%,50%);filter:blur(48px);pointer-events:none;"></div>
    <div style="position:relative;z-index:10;">

        <!-- Header navegación -->
        <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:0.75rem;">
            <button onclick="podiumMove(-1)" style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.25);color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" onmouseover="this.style.background='rgba(255,106,0,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'"><i class='bx bx-chevron-left' style="font-size:1.2rem;"></i></button>
            <div style="display:flex;align-items:center;gap:8px;">
                <i class='bx bx-trophy' style="color:#FF6A00;font-size:2rem;"></i>
                <h2 id="podiumTitle" style="font-size:1.5rem;font-weight:700;color:white;margin:0;transition:opacity 0.2s;">Top 3 — <?= htmlspecialchars($buName) ?></h2>
            </div>
            <button onclick="podiumMove(1)" style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.25);color:white;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.2s;flex-shrink:0;" onmouseover="this.style.background='rgba(255,106,0,0.5)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'"><i class='bx bx-chevron-right' style="font-size:1.2rem;"></i></button>
        </div>
        <div style="display:flex;justify-content:center;gap:6px;margin-bottom:1.25rem;">
            <div id="dot0" style="width:8px;height:8px;border-radius:50%;background:#FF6A00;transition:all 0.3s;"></div>
            <div id="dot1" style="width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.3);transition:all 0.3s;"></div>
        </div>

        <!-- ── SLIDE 0: Top 3 Mi Equipo ── -->
        <div id="podiumSlide0">
        <?php if (count($top3Team) >= 3):
            if ($myRankTeam === 1): ?>
            <div style="background:linear-gradient(135deg,rgba(234,179,8,0.2),rgba(250,204,21,0.05));border:1px solid rgba(250,204,21,0.5);border-radius:16px;padding:0.875rem 1.5rem;text-align:center;margin:0 auto 1.25rem auto;max-width:600px;animation:heartbeat 2.5s infinite;">
                <p style="color:#facc15;font-size:1.1rem;font-weight:800;margin:0;display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class='bx bxs-party' style="font-size:1.5rem;"></i>¡Eres el líder de tu equipo! ¡Sigue así!<i class='bx bxs-party' style="font-size:1.5rem;"></i>
                </p>
            </div>
            <?php else: ?><div style="margin-bottom:1.25rem;"></div><?php endif;
            // Orden visual: 2 - 1 - 3
            $p3order = [$top3Team[1],$top3Team[0],$top3Team[2]];
            $p3ranks = [2,1,3];
            $p3h     = [2=>'144px',1=>'192px',3=>'112px'];
        ?>
            <div style="display:flex;align-items:flex-end;justify-content:center;gap:1.5rem;max-width:700px;margin:0 auto;">
            <?php foreach($p3order as $pi=>$pu):
                $rk=$p3ranks[$pi]; $isF=($rk===1);
                $sz=$isF?'96px':'80px'; $nm=getDisplayNameR($pu); ?>
                <div style="flex:1;max-width:200px;">
                    <div style="text-align:center;margin-bottom:0.75rem;">
                        <div style="position:relative;display:inline-block;margin-bottom:10px;">
                            <div style="width:<?=$sz?>;height:<?=$sz?>;border-radius:50%;border:4px solid <?=$borderColors[$rk]?>;overflow:hidden;background:#374151;display:flex;align-items:center;justify-content:center;margin:0 auto;<?=$isF?'box-shadow:0 10px 15px rgba(234,179,8,0.5);':''?>">
                                <?php if(!empty($pu['image'])):?><img src="<?=htmlspecialchars($pu['image']) ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span style="display:none;font-size:1.5rem;font-weight:700;color:white;"><?=strtoupper(mb_substr($nm,0,1))?></span><?php else:?><span style="font-size:1.5rem;font-weight:700;color:white;"><?=strtoupper(mb_substr($nm,0,1))?></span><?php endif;?>
                            </div>
                            <div style="position:absolute;top:-14px;left:-14px;z-index:20;width:<?=$isF?'54px':'46px'?>;height:<?=$isF?'54px':'46px'?>;pointer-events:none;">
                                <img src="assets/images/medal_<?=$rk?>.png" alt="Lugar <?=$rk?>" style="width:100%;height:100%;object-fit:contain;filter:drop-shadow(0 4px 4px rgba(0,0,0,0.2));transform:rotate(-5deg);">
                            </div>
                        </div>
                        <h3 style="font-weight:700;color:white;font-size:<?=$isF?'0.95rem':'0.8rem'?>;margin:0 0 3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($nm)?></h3>
                        <p style="font-size:<?=$isF?'1.75rem':'1.4rem'?>;font-weight:700;color:<?=$isF?'#facc15':'#d1d5db'?>;margin:0;line-height:1;"><?=number_format((int)$pu['totalPoints'])?></p>
                        <p style="font-size:0.7rem;color:#9ca3af;margin:2px 0 0;">puntos</p>
                    </div>
                    <div style="height:<?=$p3h[$rk]?>;background:<?=$podiumColors[$rk]?>;border-radius:0.75rem 0.75rem 0 0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;border-top:4px solid <?=$borderColors[$rk]?>;<?=$isF?'box-shadow:0 10px 15px rgba(234,179,8,0.3);':''?>">
                        <i class='bx <?=$isF?"bx-trophy":"bx-medal"?>' style="color:<?=$isF?'white':'rgba(255,255,255,0.9)'?>;font-size:<?=$isF?'2.75rem':'2.25rem'?>;"></i>
                        <p style="color:rgba(255,255,255,0.9);font-weight:600;font-size:0.75rem;margin:0;"><?=$pu['coursesCompleted']?> cursos</p>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align:center;color:#9ca3af;padding:3rem 0;">No hay suficientes participantes en tu equipo.</p>
        <?php endif; ?>
        </div>

        <!-- ── SLIDE 1: Top 5 General — mismo layout de columnas ── -->
        <div id="podiumSlide1" style="display:none;">
        <?php if (count($top5General) >= 1):
            // Orden visual para 5: 4 - 2 - 1 - 3 - 5  (forma de montaña)
            $p5indices = [3,1,0,2,4]; // índices del array $top5General
            $p5ranks   = [4,2,1,3,5]; // posición real de cada uno
            $p5h       = [1=>'192px',2=>'148px',3=>'112px',4=>'80px',5=>'56px'];
            $p5margin  = [1=>'0px',2=>'48px',3=>'80px',4=>'112px',5=>'136px']; // margin-bottom para alinear bases
        ?>
            <div style="display:flex;align-items:flex-end;justify-content:center;gap:1rem;max-width:800px;margin:0 auto;">
            <?php foreach($p5indices as $pos => $arrIdx):
                if (!isset($top5General[$arrIdx])) continue;
                $tu   = $top5General[$arrIdx];
                $rk   = $p5ranks[$pos];
                $isF  = ($rk === 1);
                $sz   = $isF ? '80px' : ($rk<=2 ? '68px' : '58px');
                $tNm  = getDisplayNameR($tu);
                $isMe = ($tu['id'] === $userId);
            ?>
                <div style="flex:1;max-width:<?=$isF?'160px':($rk<=3?'140px':'120px')?>;<?=$isMe?'filter:drop-shadow(0 0 8px rgba(255,106,0,0.6));':''?>">
                    <div style="text-align:center;margin-bottom:0.6rem;">
                        <div style="position:relative;display:inline-block;margin-bottom:8px;">
                            <div style="width:<?=$sz?>;height:<?=$sz?>;border-radius:50%;border:3px solid <?=$borderColors[$rk]?>;overflow:hidden;background:#374151;display:flex;align-items:center;justify-content:center;margin:0 auto;<?=$isF?'box-shadow:0 8px 20px rgba(234,179,8,0.5);':''?>">
                                <?php if(!empty($tu['image'])):?><img src="<?=htmlspecialchars($tu['image']) ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span style="display:none;font-size:1.1rem;font-weight:700;color:white;"><?=strtoupper(mb_substr($tNm,0,1))?></span><?php else:?><span style="font-size:1.1rem;font-weight:700;color:white;"><?=strtoupper(mb_substr($tNm,0,1))?></span><?php endif;?>
                            </div>
                            <!-- Badge de posición -->
                            <div style="position:absolute;top:-8px;left:-8px;width:24px;height:24px;border-radius:50%;background:<?=$borderColors[$rk]?>;display:flex;align-items:center;justify-content:center;font-size:0.65rem;font-weight:800;color:<?=$rk<=3?'#111827':'white'?>;border:2px solid #111827;z-index:10;"><?=$rk?></div>
                        </div>
                        <h3 style="font-weight:700;color:white;font-size:<?=$isF?'0.875rem':'0.75rem'?>;margin:0 0 2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($tNm)?><?=$isMe?' ★':''?></h3>
                        <p style="font-size:<?=$isF?'1.35rem':'1.1rem'?>;font-weight:700;color:<?=$borderColors[$rk]?>;margin:0;line-height:1;"><?=number_format((int)$tu['totalPoints'])?></p>
                        <p style="font-size:0.65rem;color:#9ca3af;margin:1px 0 0;">puntos</p>
                    </div>
                    <!-- Columna del podio -->
                    <div style="height:<?=$p5h[$rk]?>;background:<?=$podiumColors[$rk]?>;border-radius:0.625rem 0.625rem 0 0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;border-top:3px solid <?=$borderColors[$rk]?>;<?=$isF?'box-shadow:0 8px 15px rgba(234,179,8,0.3);':''?>">
                        <i class='bx <?=$isF?"bx-trophy":($rk<=3?"bx-medal":"bx-ribbon")?>' style="color:rgba(255,255,255,0.95);font-size:<?=$isF?'2.25rem':($rk<=3?'1.875rem':'1.5rem')?>;"></i>
                        <p style="color:rgba(255,255,255,0.9);font-weight:600;font-size:0.65rem;margin:0;"><?=$tu['coursesCompleted']?> cursos</p>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p style="text-align:center;color:#9ca3af;padding:3rem 0;">No hay participantes.</p>
        <?php endif; ?>
        </div>

    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     STATS FILA 1
     [Tu Posición: doble columna BU + General]  [Últimos Puntos]
════════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem;">

    <!-- Tu Posición: muestra ambos rangos simultáneamente -->
    <div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1rem;padding:1.5rem;border:1px solid #374151;color:white;position:relative;overflow:hidden;">
        <div style="position:absolute;top:0;right:0;width:128px;height:128px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(40px);pointer-events:none;"></div>
        <div style="position:relative;z-index:10;">
            <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
                <div style="width:48px;height:48px;border-radius:1rem;background:rgba(255,106,0,0.2);display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,106,0,0.3);">
                    <i class='bx bx-trophy' style="color:#FF6A00;font-size:1.5rem;"></i>
                </div>
                <div>
                    <h2 style="font-size:1.125rem;font-weight:700;color:white;margin:0;">Tu Posición</h2>
                    <p style="font-size:0.8rem;color:#9ca3af;margin:0;"><?= number_format($myPtsGen) ?> puntos totales</p>
                </div>
            </div>

            <!-- Dos columnas: Mi Equipo | General -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:1rem;">
                <!-- Mi Equipo -->
                <div style="background:rgba(255,106,0,0.1);border:1px solid rgba(255,106,0,0.25);border-radius:12px;padding:12px;text-align:center;">
                    <p style="font-size:0.65rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 4px;">Mi Equipo</p>
                    <p style="font-size:2rem;font-weight:800;color:white;margin:0;line-height:1;"><?= $myRankTeam>0 ? $myRankTeam.'°' : '—' ?></p>
                    <p style="font-size:0.7rem;color:#9ca3af;margin:4px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($buName) ?></p>
                    <?php if($myRankTeam>0):?><div style="display:inline-flex;align-items:center;gap:3px;color:#4ade80;font-size:0.7rem;margin-top:4px;"><i class='bx bx-trending-up' style="font-size:0.75rem;"></i>Activo</div><?php endif;?>
                </div>
                <!-- General -->
                <div style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:12px;padding:12px;text-align:center;">
                    <p style="font-size:0.65rem;font-weight:700;color:#facc15;text-transform:uppercase;letter-spacing:0.08em;margin:0 0 4px;">General</p>
                    <p style="font-size:2rem;font-weight:800;color:white;margin:0;line-height:1;"><?= $myRankGen>0 ? $myRankGen.'°' : '—' ?></p>
                    <p style="font-size:0.7rem;color:#9ca3af;margin:4px 0 0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($companyName) ?></p>
                    <?php if($myRankGen>0):?><div style="display:inline-flex;align-items:center;gap:3px;color:#4ade80;font-size:0.7rem;margin-top:4px;"><i class='bx bx-trending-up' style="font-size:0.75rem;"></i>Activo</div><?php endif;?>
                </div>
            </div>

            <!-- Cursos + Para Top 3 -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div style="background:rgba(255,255,255,0.05);border-radius:10px;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);">
                    <p style="font-size:0.7rem;color:#9ca3af;margin:0;">Cursos completados</p>
                    <p style="font-size:1.1rem;font-weight:700;color:white;margin:0;"><?= $myDoneGen ?></p>
                </div>
                <div style="background:rgba(255,255,255,0.05);border-radius:10px;padding:8px 12px;border:1px solid rgba(255,255,255,0.08);">
                    <p style="font-size:0.7rem;color:#9ca3af;margin:0;">Para Top 3 General</p>
                    <p id="mySmallTop3" style="font-size:1.1rem;font-weight:700;color:white;margin:0;"><?= $ptsTop3Gen>0 ? $ptsTop3Gen.' pts' : '<i class=\'bx bx-trophy\' style=\'color:#facc15;\'></i>' ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Últimos Puntos -->
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:0.75rem;margin-bottom:1.25rem;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#FF6A00,#FFA500);display:flex;align-items:center;justify-content:center;">
                <i class='bx bx-bolt-circle' style="color:white;font-size:1.5rem;"></i>
            </div>
            <div>
                <h2 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">&Uacute;ltimos Puntos</h2>
                <p style="font-size:0.875rem;color:#6b7280;margin:0;">Tu actividad reciente</p>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:10px;max-height:265px;overflow-y:auto;">
            <?php if(empty($myPointsHistory)):?>
            <div style="padding:2rem;text-align:center;"><p style="font-size:0.8rem;color:#9ca3af;">Completa desaf&iacute;os para sumar puntos.</p></div>
            <?php else: foreach($myPointsHistory as $pt): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:12px;background:#f9fafb;border:1px solid #e5e7eb;">
                <div style="width:34px;height:34px;border-radius:10px;background:#fff7ed;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class='bx bx-bolt-circle' style="color:#FF6A00;"></i></div>
                <div style="flex:1;min-width:0;">
                    <p style="font-weight:700;font-size:0.85rem;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars(trim(preg_replace('/\[Ruta_.*?\]/', '', $pt['description']??'Puntos'))) ?></p>
                    <div style="display:flex;align-items:center;gap:4px;font-size:0.72rem;color:#9ca3af;margin-top:2px;"><i class='bx bx-time-five' style="font-size:0.6rem;"></i><span><?= date('d M',strtotime($pt['createdAt'])) ?></span></div>
                </div>
                <div style="text-align:right;flex-shrink:0;"><p style="font-size:1.05rem;font-weight:700;color:#FF6A00;margin:0;">+<?=$pt['points']?></p><p style="font-size:0.68rem;color:#9ca3af;margin:0;">pts</p></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     STATS FILA 2: Participantes + Para Top 3  (se actualizan con el tab)
════════════════════════════════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:1rem;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;"><i class='bx bx-group' style="color:white;font-size:1.5rem;"></i></div>
            <div><h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">Participantes</h3><p id="participLabel" style="font-size:0.875rem;color:#6b7280;margin:0;">Activos en el ranking</p></div>
        </div>
        <p id="participCount" style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= count($usersGeneral) ?></p>
        <div style="display:flex;align-items:center;gap:4px;color:#16a34a;font-size:0.875rem;margin-top:8px;"><i class='bx bx-trending-up'></i><span>Ranking activo</span></div>
    </div>
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:1rem;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#a855f7,#7c3aed);display:flex;align-items:center;justify-content:center;"><i class='bx bx-target-lock' style="color:white;font-size:1.5rem;"></i></div>
            <div><h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">Para Top 3</h3><p id="ptsTop3Label" style="font-size:0.875rem;color:#6b7280;margin:0;">Puntos necesarios · General</p></div>
        </div>
        <p id="bigTop3" style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $ptsTop3Gen>0 ? number_format($ptsTop3Gen) : '<span style="color:#eab308;font-size:1.1rem;display:flex;align-items:center;gap:6px;"><i class=\'bx bxs-star bx-tada\'></i>&iexcl;Estás en el podio!</span>' ?></p>
        <p id="bigTop3Sub" style="font-size:0.875rem;color:#6b7280;margin:8px 0 0;"><?= $ptsTop3Gen>0 ? 'Próxima meta: 3ª posición' : '<span style="color:#16a34a;font-weight:700;">¡Mantén ese ritmo!</span>' ?></p>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TABS
════════════════════════════════════════════════════════════════════════════ -->
<div style="background:white;border-radius:1rem;padding:0.875rem;border:1px solid #f3f4f6;margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:8px;background:#f3f4f6;border-radius:999px;padding:5px;">
        <button id="tabGeneral" onclick="switchRankTab('general')"
            style="flex:1;padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;background:white;color:#111827;box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <i class='bx bx-globe' style="margin-right:4px;vertical-align:-2px;"></i><?= htmlspecialchars($tabGeneralLbl) ?>
        </button>
        <button id="tabTeam" onclick="switchRankTab('team')"
            style="flex:1;padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:600;border:none;cursor:pointer;transition:all 0.2s;background:transparent;color:#6b7280;">
            <i class='bx bx-group' style="margin-right:4px;vertical-align:-2px;"></i><?= htmlspecialchars($tabTeamLbl ?? $buName) ?>
        </button>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TABLA GENERAL
════════════════════════════════════════════════════════════════════════════ -->
<div id="tableGeneral">
    <div style="background:white;border-radius:1rem;border:1px solid #f3f4f6;overflow:hidden;">
        <div style="padding:1.25rem;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
            <div><h2 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0;">Clasificación General</h2><p style="font-size:0.875rem;color:#6b7280;margin:0;">Posiciones del 4 en adelante · Toda la empresa</p></div>
            <div style="position:relative;width:200px;"><i class='bx bx-search' style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i><input type="text" id="rankSearchGeneral" placeholder="Filtrar..." oninput="filterRanking('general')" style="width:100%;padding:8px 12px 8px 36px;background:#f9fafb;border-radius:999px;border:1px solid #e5e7eb;font-size:0.875rem;outline:none;box-sizing:border-box;" onfocus="this.style.boxShadow='0 0 0 2px #FF6A00'" onblur="this.style.boxShadow='none'"></div>
        </div>
        <?php $restGen=array_slice($usersGeneral,3); if(empty($restGen)):?>
        <div style="padding:3rem;text-align:center;"><i class='bx bx-trophy' style="font-size:2rem;color:#e5e7eb;display:block;margin-bottom:12px;"></i><p style="font-weight:600;color:#6b7280;">No hay más participantes</p></div>
        <?php else:?><div id="rankingListGeneral">
        <?php foreach($restGen as $ri=>$ru):$rk=$ri+4;$isMe=$ru['id']===$userId;$dn=getDisplayNameR($ru);?>
        <div class="rank-row-general" data-name="<?=strtolower(htmlspecialchars($dn))?>" style="padding:1.25rem;border-bottom:1px solid #f3f4f6;transition:background 0.2s;<?=$isMe?'background:rgba(255,106,0,0.05);border-left:4px solid #FF6A00;':''?>" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='<?=$isMe?'rgba(255,106,0,0.05)':'white'?>'">
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:44px;height:44px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="font-size:1.1rem;font-weight:700;color:#374151;"><?=$rk?></span></div>
                <div style="width:44px;height:44px;border-radius:50%;background:#111827;display:flex;align-items:center;justify-content:center;color:white;overflow:hidden;flex-shrink:0;border:2px solid #e5e7eb;"><?php if(!empty($ru['image'])):?><img src="<?=htmlspecialchars($ru['image']) ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span style="display:none;font-size:1rem;font-weight:700;"><?=strtoupper(mb_substr($dn,0,1))?></span><?php else:?><span style="font-size:1rem;font-weight:700;"><?=strtoupper(mb_substr($dn,0,1))?></span><?php endif;?></div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;"><h3 style="font-weight:700;color:#111827;margin:0;font-size:0.95rem;"><?=htmlspecialchars($dn)?></h3><?php if($isMe):?><span style="padding:2px 8px;background:#FF6A00;color:white;font-size:0.7rem;font-weight:700;border-radius:999px;">Tú</span><?php endif;?></div>
                    <div style="display:flex;align-items:center;gap:1rem;font-size:0.8rem;color:#6b7280;"><span style="display:flex;align-items:center;gap:3px;"><i class='bx bx-star'></i><?=$ru['coursesCompleted']?> cursos</span><span><?=htmlspecialchars($ru['buName']??'Sin Unidad')?></span></div>
                </div>
                <div style="text-align:right;flex-shrink:0;"><p style="font-size:1.4rem;font-weight:700;color:#111827;margin:0;"><?=number_format((int)$ru['totalPoints'])?></p><p style="font-size:0.7rem;color:#6b7280;margin:0;">puntos</p></div>
                <div style="width:30px;flex-shrink:0;"><div style="width:30px;height:30px;border-radius:50%;background:<?=$isMe?'#dcfce7':'#f3f4f6'?>;display:flex;align-items:center;justify-content:center;"><?php if($isMe):?><i class='bx bx-chevron-up' style="color:#16a34a;"></i><?php else:?><span style="color:#9ca3af;font-size:0.85rem;">-</span><?php endif;?></div></div>
            </div>
        </div>
        <?php endforeach;?></div><?php endif;?>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════════
     TABLA MI EQUIPO (oculta)
════════════════════════════════════════════════════════════════════════════ -->
<div id="tableTeam" style="display:none;">
    <div style="background:white;border-radius:1rem;border:1px solid #f3f4f6;overflow:hidden;">
        <div style="padding:1.25rem;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">
            <div><h2 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0;">Clasificación — <?=htmlspecialchars($buName)?></h2><p style="font-size:0.875rem;color:#6b7280;margin:0;">Posiciones del 4 en adelante · Tu unidad</p></div>
            <div style="position:relative;width:200px;"><i class='bx bx-search' style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;"></i><input type="text" id="rankSearchTeam" placeholder="Filtrar..." oninput="filterRanking('team')" style="width:100%;padding:8px 12px 8px 36px;background:#f9fafb;border-radius:999px;border:1px solid #e5e7eb;font-size:0.875rem;outline:none;box-sizing:border-box;" onfocus="this.style.boxShadow='0 0 0 2px #FF6A00'" onblur="this.style.boxShadow='none'"></div>
        </div>
        <?php $restTeam=array_slice($usersTeam,3); if(empty($restTeam)):?>
        <div style="padding:3rem;text-align:center;"><i class='bx bx-trophy' style="font-size:2rem;color:#e5e7eb;display:block;margin-bottom:12px;"></i><p style="font-weight:600;color:#6b7280;">No hay más participantes en tu equipo</p></div>
        <?php else:?><div id="rankingListTeam">
        <?php foreach($restTeam as $ri=>$ru):$rk=$ri+4;$isMe=$ru['id']===$userId;$dn=getDisplayNameR($ru);?>
        <div class="rank-row-team" data-name="<?=strtolower(htmlspecialchars($dn))?>" style="padding:1.25rem;border-bottom:1px solid #f3f4f6;transition:background 0.2s;<?=$isMe?'background:rgba(255,106,0,0.05);border-left:4px solid #FF6A00;':''?>" onmouseover="this.style.background='#f9fafb'" onmouseout="this.style.background='<?=$isMe?'rgba(255,106,0,0.05)':'white'?>'">
            <div style="display:flex;align-items:center;gap:1rem;">
                <div style="width:44px;height:44px;border-radius:50%;background:#f3f4f6;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><span style="font-size:1.1rem;font-weight:700;color:#374151;"><?=$rk?></span></div>
                <div style="width:44px;height:44px;border-radius:50%;background:#111827;display:flex;align-items:center;justify-content:center;color:white;overflow:hidden;flex-shrink:0;border:2px solid #e5e7eb;"><?php if(!empty($ru['image'])):?><img src="<?=htmlspecialchars($ru['image']) ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span style="display:none;font-size:1rem;font-weight:700;"><?=strtoupper(mb_substr($dn,0,1))?></span><?php else:?><span style="font-size:1rem;font-weight:700;"><?=strtoupper(mb_substr($dn,0,1))?></span><?php endif;?></div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;"><h3 style="font-weight:700;color:#111827;margin:0;font-size:0.95rem;"><?=htmlspecialchars($dn)?></h3><?php if($isMe):?><span style="padding:2px 8px;background:#FF6A00;color:white;font-size:0.7rem;font-weight:700;border-radius:999px;">Tú</span><?php endif;?></div>
                    <div style="display:flex;align-items:center;gap:3px;font-size:0.8rem;color:#6b7280;"><i class='bx bx-star'></i><?=$ru['coursesCompleted']?> cursos</div>
                </div>
                <div style="text-align:right;flex-shrink:0;"><p style="font-size:1.4rem;font-weight:700;color:#111827;margin:0;"><?=number_format((int)$ru['totalPoints'])?></p><p style="font-size:0.7rem;color:#6b7280;margin:0;">puntos</p></div>
                <div style="width:30px;flex-shrink:0;"><div style="width:30px;height:30px;border-radius:50%;background:<?=$isMe?'#dcfce7':'#f3f4f6'?>;display:flex;align-items:center;justify-content:center;"><?php if($isMe):?><i class='bx bx-chevron-up' style="color:#16a34a;"></i><?php else:?><span style="color:#9ca3af;font-size:0.85rem;">-</span><?php endif;?></div></div>
            </div>
        </div>
        <?php endforeach;?></div><?php endif;?>
    </div>
</div>

</div><!-- /wrapper -->

<style>
@keyframes heartbeat{0%{transform:scale(1);}14%{transform:scale(1.03);}28%{transform:scale(1);}42%{transform:scale(1.03);}70%{transform:scale(1);}}
</style>

<script>
// ── Datos para actualización dinámica de stats ────────────────────────────────
var _rData = {
    general:{ top3:<?=(int)$ptsTop3Gen?>,  total:<?=count($usersGeneral)?>, label:'Activos en el ranking',       ptsLabel:'Puntos necesarios · General' },
    team:   { top3:<?=(int)$ptsTop3Team?>, total:<?=count($usersTeam)?>,    label:'En tu equipo · <?=addslashes(htmlspecialchars($buName))?>', ptsLabel:'Puntos necesarios · <?=addslashes(htmlspecialchars($buName))?>' }
};

// ── Carrusel del podio ────────────────────────────────────────────────────────
var _pSlide = 0;
var _pTitles = ['Top 3 — <?=addslashes(htmlspecialchars($buName))?>', 'Top 5 — General'];
function podiumMove(dir) {
    _pSlide = (_pSlide + dir + 2) % 2;
    var t = document.getElementById('podiumTitle');
    t.style.opacity = '0';
    setTimeout(function(){
        document.getElementById('podiumSlide0').style.display = _pSlide===0 ? '' : 'none';
        document.getElementById('podiumSlide1').style.display = _pSlide===1 ? '' : 'none';
        t.textContent = _pTitles[_pSlide];
        t.style.opacity = '1';
    }, 150);
    document.getElementById('dot0').style.background = _pSlide===0 ? '#FF6A00' : 'rgba(255,255,255,0.3)';
    document.getElementById('dot1').style.background = _pSlide===1 ? '#FF6A00' : 'rgba(255,255,255,0.3)';
}

// ── Tabs ──────────────────────────────────────────────────────────────────────
function switchRankTab(tab) {
    var isG = tab === 'general';
    var on  = 'white', off = 'transparent';
    document.getElementById('tabGeneral').style.background = isG  ? on : off;
    document.getElementById('tabGeneral').style.color      = isG  ? '#111827' : '#6b7280';
    document.getElementById('tabGeneral').style.boxShadow  = isG  ? '0 4px 6px rgba(0,0,0,0.1)' : 'none';
    document.getElementById('tabTeam').style.background    = !isG ? on : off;
    document.getElementById('tabTeam').style.color         = !isG ? '#111827' : '#6b7280';
    document.getElementById('tabTeam').style.boxShadow     = !isG ? '0 4px 6px rgba(0,0,0,0.1)' : 'none';
    document.getElementById('tableGeneral').style.display  = isG  ? '' : 'none';
    document.getElementById('tableTeam').style.display     = !isG ? '' : 'none';

    var d = isG ? _rData.general : _rData.team;
    document.getElementById('participCount').textContent = d.total;
    document.getElementById('participLabel').textContent = d.label;
    document.getElementById('ptsTop3Label').textContent  = d.ptsLabel;
    var b3  = document.getElementById('bigTop3');
    var b3s = document.getElementById('bigTop3Sub');
    if (d.top3 > 0) {
        b3.textContent  = d.top3.toLocaleString();
        b3s.innerHTML   = 'Próxima meta: 3ª posición';
    } else {
        b3.innerHTML  = '<span style="color:#eab308;font-size:1.1rem;display:flex;align-items:center;gap:6px;"><i class="bx bxs-star bx-tada"></i>¡Estás en el podio!</span>';
        b3s.innerHTML = '<span style="color:#16a34a;font-weight:700;">¡Mantén ese ritmo!</span>';
    }
}

// ── Filtros ───────────────────────────────────────────────────────────────────
function filterRanking(tab) {
    var q = document.getElementById(tab==='general' ? 'rankSearchGeneral' : 'rankSearchTeam').value.toLowerCase();
    document.querySelectorAll('.rank-row-'+tab).forEach(function(r){
        r.style.display = r.getAttribute('data-name').includes(q) ? '' : 'none';
    });
}
</script>