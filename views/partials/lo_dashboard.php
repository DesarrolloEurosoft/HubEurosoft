<?php /* LO Dashboard v3 — included from dashboard_student.php */ ?>
<div style="margin-bottom:1.5rem;">
  <h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 0.25rem;">Hola, <?= htmlspecialchars($firstName ?: 'Operador') ?> 👋</h1>
  <p style="color:#6b7280;font-size:0.875rem;margin:0;"><?= date('l, j \d\e F') ?></p>
</div>

<div style="display:grid;grid-template-columns:3fr 9fr;gap:1.25rem;">

<?php /* ── LEFT COLUMN ── */ ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;">

<?php /* Profile Card (sin barra de XP) */ ?>
<div style="height:340px;position:relative;border-radius:1.5rem;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);background:<?= $userImageResolved ? '#e5e7eb' : 'linear-gradient(135deg,#FF6A00,#FFA500)' ?>;">
  <?php if ($userImageResolved): ?>
    <img src="<?= htmlspecialchars($userImageResolved) ?>" alt="" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
    <div style="display:none;width:100%;height:100%;background:linear-gradient(135deg,#e5e7eb,#d1d5db);align-items:center;justify-content:center;"><i class='bx bx-user' style="font-size:6rem;color:rgba(255,255,255,0.4);"></i></div>
  <?php else: ?>
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><i class='bx bx-user' style="font-size:6rem;color:rgba(255,255,255,0.4);"></i></div>
  <?php endif; ?>
  <div class="v3-profile-hover" style="position:absolute;inset:0;background:rgba(0,0,0,0.3);opacity:0;display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:10;transition:opacity 0.3s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'" onclick="document.getElementById('loAvatar').click()">
    <div style="background:rgba(255,255,255,0.2);backdrop-filter:blur(12px);color:white;padding:8px 16px;border-radius:12px;font-size:0.875rem;font-weight:700;display:flex;align-items:center;gap:8px;border:1px solid rgba(255,255,255,0.3);"><i class='bx bx-camera'></i> Cambiar Foto</div>
  </div>
  <input type="file" id="loAvatar" accept="image/jpeg,image/png,image/webp" style="display:none;" onchange="uploadDashAvatar(this)">
  <div style="position:absolute;top:16px;left:16px;z-index:20;background:#FF6A00;color:white;padding:4px 12px;border-radius:999px;font-size:0.75rem;font-weight:900;box-shadow:0 4px 6px rgba(0,0,0,0.15);border:2px solid white;display:flex;align-items:center;gap:6px;"><i class='bx bx-trophy' style="font-size:0.75rem;"></i> NIVEL <?= $level ?></div>
  <a href="index.php?view=settings" style="position:absolute;top:16px;right:16px;z-index:20;background:rgba(255,255,255,0.2);backdrop-filter:blur(12px);color:white;padding:6px 12px;border-radius:8px;font-size:0.75rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,0.3);"><i class='bx bx-edit-alt'></i> EDITAR</a>
  <div style="position:absolute;bottom:0;left:0;right:0;padding:1.25rem;background:linear-gradient(to top,rgba(0,0,0,0.8) 0%,rgba(0,0,0,0.5) 50%,transparent 100%);z-index:15;">
    <h3 style="color:white;font-weight:700;font-size:1.125rem;margin:0 0 6px;"><?= htmlspecialchars($displayName) ?></h3>
    <span style="background:rgba(255,106,0,0.8);color:white;padding:2px 8px;border-radius:999px;font-size:0.7rem;font-weight:600;border:1px solid rgba(255,255,255,0.2);">Lector Operativo</span>
  </div>
</div>

<?php /* Ranking Card LO */ ?>
<div style="background:white;border-radius:1.5rem;padding:1.25rem;box-shadow:0 1px 2px rgba(0,0,0,0.05);border:1px solid #f3f4f6;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Ranking · Tu Unidad</h3>
    <a href="index.php?view=ranking" style="font-size:0.7rem;color:#FF6A00;text-decoration:none;font-weight:600;">Ver todo ↗</a>
  </div>
  <?php if (empty($loTop3)): ?>
    <p style="font-size:0.875rem;color:#9ca3af;text-align:center;padding:1rem 0;">Sin datos aún</p>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:0.5rem;">
  <?php foreach ($loTop3 as $ri => $ru):
    $rk = $ri+1; $isMe = ($ru['id'] === $_SESSION['user_id']);
    $rNm = trim(($ru['fn']??'').' '.mb_substr($ru['lastName']??'',0,1).'.');
    $rBg = $bCols[$ri] ?? '#6b7280';
    $rSt = $isMe ? 'background:linear-gradient(to right,#fff7ed,#fefce8);box-shadow:0 0 0 2px #FF6A00;' : 'background:#f9fafb;';
  ?>
  <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem;border-radius:12px;<?= $rSt ?>">
    <div style="width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:700;color:<?= $rk<=2?'#111827':'white' ?>;background:<?= $rBg ?>;flex-shrink:0;"><?= $rk ?></div>
    <div style="flex:1;min-width:0;"><p style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($rNm) ?></p></div>
    <?php if ($rk === 1): ?><i class='bx bxs-trophy' style="color:#eab308;font-size:1rem;flex-shrink:0;"></i><?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
  <?php if ($loXpGap > 0): ?>
  <div style="margin-top:1rem;padding:0.75rem;background:linear-gradient(to right,#fff7ed,#fefce8);border-radius:12px;text-align:center;">
    <p style="font-size:0.75rem;color:#4b5563;margin:0;">A <span style="font-weight:700;color:#FF6A00;"><?= number_format($loXpGap) ?> XP</span> del 1er lugar</p>
  </div>
  <?php endif; ?>
  <?php if ($loRankBU > 3): ?>
  <div style="margin-top:0.75rem;padding:0.6rem;background:#f9fafb;border-radius:10px;text-align:center;">
    <p style="font-size:0.75rem;color:#6b7280;margin:0;">Tu posición: <span style="font-weight:700;color:#111827;"><?= $loRankBU ?>°</span> de <?= $loTotalBU ?></p>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

</div><?php /* /left column */ ?>

<?php /* ── RIGHT COLUMN ── */ ?>
<div style="display:flex;flex-direction:column;gap:1.25rem;min-width:0;">

<?php /* Dark Card: Racha + Medallas + Certificados */ ?>
<div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1.5rem;padding:1.75rem 2rem;border:1px solid #374151;position:relative;overflow:hidden;">
  <div style="position:absolute;top:0;right:0;width:220px;height:220px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(48px);pointer-events:none;"></div>
  <div style="position:absolute;bottom:0;left:0;width:180px;height:180px;background:rgba(234,179,8,0.07);border-radius:50%;transform:translate(-50%,50%);filter:blur(48px);pointer-events:none;"></div>
  <div style="position:relative;z-index:10;display:grid;grid-template-columns:1fr 1fr 1fr;gap:2rem;align-items:start;">

    <div>
      <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.5rem;"><i class='bx bxs-hot' style="color:#FF6A00;font-size:1rem;"></i><span style="font-size:0.7rem;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Racha</span></div>
      <p style="font-size:3.75rem;font-weight:800;color:white;margin:0;line-height:1;letter-spacing:-0.02em;"><?= $streak ?></p>
      <p style="font-size:0.8rem;color:#9ca3af;margin:2px 0 0.75rem;"><?= $streak===1?'día':'días' ?> seguidos</p>
      <div style="background:rgba(255,106,0,0.12);border:1px solid rgba(255,106,0,0.25);border-radius:0.75rem;padding:0.6rem 0.75rem;"><p style="font-size:0.75rem;color:#fb923c;margin:0;"><?= htmlspecialchars($streakMsg) ?></p></div>
    </div>

    <div style="border-left:1px solid rgba(255,255,255,0.08);padding-left:2rem;">
      <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.75rem;"><i class='bx bx-medal' style="color:#facc15;font-size:1rem;"></i><span style="font-size:0.7rem;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Medallas</span></div>
      <?php if (empty($topMedals)): ?>
        <p style="font-size:0.8rem;color:#6b7280;margin:0 0 0.5rem;">¡Completa cursos para ganar medallas!</p>
      <?php else: ?>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
        <?php foreach ($topMedals as $med):
          $mC = isset($cRes[$med['color']]) ? $cRes[$med['color']] : (strpos($med['color']??'','#')===0 ? $med['color'] : 'linear-gradient(135deg,#FF6A00,#FFA500)');
        ?>
        <div title="<?= htmlspecialchars($med['title']??'') ?>" style="width:40px;height:40px;border-radius:10px;background:<?= $mC ?>;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 8px rgba(0,0,0,0.25);">
          <?php if (!empty($med['imagePath'])): ?><img src="<?= htmlspecialchars(ltrim($med['imagePath']??'','/')) ?>" style="width:24px;height:24px;object-fit:contain;" alt=""><?php else: ?><i class="<?= htmlspecialchars($med['icon']??'bx bxs-medal') ?>" style="font-size:1.1rem;color:white;"></i><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p style="font-size:0.75rem;color:#6b7280;margin:0;"><?= $completedMedallaCount ?> de <?= count($medallaItems) ?> desbloqueadas</p>
    </div>

    <div style="border-left:1px solid rgba(255,255,255,0.08);padding-left:2rem;">
      <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.75rem;"><i class='bx bx-award' style="color:#a855f7;font-size:1rem;"></i><span style="font-size:0.7rem;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;">Certificados</span></div>
      <?php $certs=$sd['certificates']; $nCerts=count($certs); ?>
      <p style="font-size:3.75rem;font-weight:800;color:white;margin:0;line-height:1;letter-spacing:-0.02em;"><?= $nCerts ?></p>
      <?php if ($nCerts > 0): ?>
        <p style="font-size:0.8rem;color:#c4b5fd;margin:4px 0 0.5rem;">obtenido<?= $nCerts>1?'s':'' ?></p>
        <p style="font-size:0.75rem;color:#6b7280;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($certs[0]['certName']??'Certificado') ?></p>
      <?php else: ?>
        <p style="font-size:0.8rem;color:#6b7280;margin:4px 0 0;">Completa un curso para obtener tu primer certificado.</p>
      <?php endif; ?>
    </div>

  </div>
</div>

<?php /* Row 2: Curso (2fr) + Foro (1fr) */ ?>
<div style="display:grid;grid-template-columns:2fr 1fr;gap:1.25rem;">

<div style="background:white;border-radius:1.5rem;overflow:hidden;border:1px solid #f3f4f6;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
<?php if ($activeCourse):
  $cImg=resolveImageUrl($activeCourse['imageUrl']??''); $cPct=min(100,(int)($activeCourse['progress']??0));?>
  <div style="position:relative;height:180px;background:linear-gradient(135deg,#ffedd5,#fefce8);">
    <?php if($cImg):?><img src="<?=htmlspecialchars($cImg)?>" alt="" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none'"><?php else:?><div style="width:100%;height:100%;background:linear-gradient(135deg,rgba(255,106,0,0.2),rgba(255,165,0,0.2));display:flex;align-items:center;justify-content:center;"><i class='bx bx-play-circle' style="font-size:3.5rem;color:rgba(255,106,0,0.4);"></i></div><?php endif;?>
    <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.7),transparent);"></div>
    <div class="v3-play-overlay" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:48px;height:48px;background:rgba(255,255,255,0.25);backdrop-filter:blur(12px);border-radius:50%;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity 0.3s;"><i class='bx bx-play-circle' style="font-size:1.5rem;color:white;"></i></div>
  </div>
  <div style="padding:1.25rem;">
    <div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.4rem;"><i class='bx bx-play-circle' style="color:#FF6A00;font-size:0.9rem;"></i><span style="font-size:0.7rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.05em;">Continua donde lo dejaste</span></div>
    <h4 style="font-weight:700;color:#111827;margin:0 0 0.5rem;font-size:1rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($activeCourse['title']??'Curso')?></h4>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:1rem;"><div style="flex:1;height:6px;background:#e5e7eb;border-radius:999px;overflow:hidden;"><div style="height:100%;width:<?=$cPct?>%;background:linear-gradient(to right,#FF6A00,#FFA500);border-radius:999px;"></div></div><span style="font-size:0.75rem;font-weight:600;color:#FF6A00;"><?=$cPct?>%</span></div>
    <a href="index.php?view=lesson&course_id=<?=urlencode($activeCourse['id']??'')?>" style="display:inline-flex;align-items:center;gap:0.5rem;background:#FF6A00;color:white;padding:0.6rem 1.25rem;border-radius:999px;font-size:0.875rem;font-weight:700;text-decoration:none;box-shadow:0 4px 6px rgba(255,106,0,0.25);transition:background 0.2s;" onmouseover="this.style.background='#e55a00'" onmouseout="this.style.background='#FF6A00'">Continuar <i class='bx bx-right-arrow-alt'></i></a>
  </div>
<?php else:?>
  <div style="padding:3rem;text-align:center;"><i class='bx bx-play-circle' style="font-size:3rem;color:#e5e7eb;display:block;margin-bottom:1rem;"></i><p style="font-weight:600;color:#6b7280;margin:0;">No tienes cursos activos</p></div>
<?php endif;?>
</div>

<div style="background:white;border-radius:1.5rem;padding:1.25rem;border:1px solid #f3f4f6;box-shadow:0 1px 2px rgba(0,0,0,0.05);">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;gap:0.5rem;"><i class='bx bx-conversation' style="color:#FF6A00;font-size:1.1rem;"></i><h3 style="font-size:0.875rem;font-weight:600;color:#111827;margin:0;">Tu espacio</h3></div>
    <?php if($loForumId):?><a href="index.php?view=forum_topic&forum_id=<?=urlencode($loForumId)?>" style="font-size:0.7rem;color:#FF6A00;text-decoration:none;font-weight:600;">Ver foro</a><?php endif;?>
  </div>
  <?php if(empty($loFeed)):?>
  <div style="text-align:center;padding:1.5rem 0.5rem;"><i class='bx bx-message-square-dots' style="font-size:2rem;color:#e5e7eb;display:block;margin-bottom:0.5rem;"></i><p style="font-size:0.8rem;color:#9ca3af;margin:0 0 1rem;">Se el primero en publicar hoy.</p><?php if($loForumId):?><a href="index.php?view=forum_topic&forum_id=<?=urlencode($loForumId)?>" style="display:inline-flex;align-items:center;gap:0.4rem;background:#FF6A00;color:white;padding:0.5rem 1rem;border-radius:999px;font-size:0.8rem;font-weight:700;text-decoration:none;"><i class='bx bx-plus'></i> Publicar</a><?php endif;?></div>
  <?php else:?>
  <div style="display:flex;flex-direction:column;gap:0.5rem;">
  <?php foreach($loFeed as $fi):
    $tC=['QUESTION'=>'#3b82f6','IMPROVEMENT'=>'#f59e0b','CONTRIBUTION'=>'#FF6A00'];
    $tI=['QUESTION'=>'bx-help-circle','IMPROVEMENT'=>'bx-bulb','CONTRIBUTION'=>'bxs-star'];
    $tc=$tC[$fi['threadType']]??'#6b7280'; $ti=$tI[$fi['threadType']]??'bx-chat';
    $df=time()-strtotime($fi['updatedAt']);
    $ta=$df<3600?floor($df/60).'m':($df<86400?floor($df/3600).'h':floor($df/86400).'d');?>
  <a href="index.php?view=forum_topic&forum_id=<?=urlencode($fi['forumId'])?>&topic_id=<?=urlencode($fi['id'])?>" style="display:flex;align-items:center;gap:0.6rem;padding:0.6rem;border-radius:10px;background:#f9fafb;text-decoration:none;color:inherit;transition:background 0.15s;" onmouseover="this.style.background='#f3f4f6'" onmouseout="this.style.background='#f9fafb'">
    <div style="width:28px;height:28px;border-radius:7px;background:<?=$tc?>20;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class='bx <?=$ti?>' style="color:<?=$tc?>;font-size:0.9rem;"></i></div>
    <div style="flex:1;min-width:0;"><p style="font-weight:600;font-size:0.8rem;color:#111827;margin:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($fi['title'])?></p><p style="font-size:0.68rem;color:#9ca3af;margin:0;"><?=htmlspecialchars($fi['authorName'])?> - <?=$ta?></p></div>
    <i class='bx bx-chevron-right' style="color:#d1d5db;flex-shrink:0;"></i>
  </a>
  <?php endforeach;?>
  </div>
  <?php if($loForumId):?><div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #f3f4f6;text-align:right;"><a href="index.php?view=forum_topic&forum_id=<?=urlencode($loForumId)?>" style="display:inline-flex;align-items:center;gap:0.4rem;background:#FF6A00;color:white;padding:0.5rem 1rem;border-radius:999px;font-size:0.8rem;font-weight:700;text-decoration:none;" onmouseover="this.style.background='#e55a00'" onmouseout="this.style.background='#FF6A00'"><i class='bx bx-plus'></i> Nueva publicacion</a></div><?php endif;?>
  <?php endif;?>
</div>

</div><?php /* /row 2 */ ?>
</div><?php /* /right column */ ?>
</div><?php /* /main grid */ ?>
