<?php
// V3 Student Profile - pixel-perfect port from Next.js /profile
$userRoleCheck = strtoupper($_SESSION['user_role'] ?? '');
if ($userRoleCheck === 'STUDENT') {
    $userId = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM User WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $user = null; }

    $firstName = $user['firstName'] ?? '';
    $lastName = $user['lastName'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName);
    if (empty($fullName)) $fullName = $user['name'] ?? 'Estudiante';
    $nickname = $user['name'] ?? '';
    $role = $user['role'] ?? 'STUDENT';
    $totalPoints = (int)($user['totalPoints'] ?? 0);
    $imageUrl = $user['image'] ?? '';
    $initial = strtoupper(mb_substr($fullName, 0, 1));

    // Streak
    $streak = 0;
    try {
        $stmtS = $pdo->prepare("SELECT createdAt FROM LoginLog WHERE userId = ? ORDER BY createdAt DESC LIMIT 30");
        $stmtS->execute([$userId]);
        $logins = $stmtS->fetchAll(PDO::FETCH_COLUMN);
        $days = array_unique(array_map(function($d){ return date('Y-m-d', strtotime($d)); }, $logins));
        sort($days);
        $days = array_reverse($days);
        $today = date('Y-m-d');
        if (!empty($days) && ($days[0] === $today || $days[0] === date('Y-m-d', strtotime('-1 day')))) {
            $streak = 1;
            for ($i = 1; $i < count($days); $i++) {
                $expected = date('Y-m-d', strtotime('-' . $i . ' day', strtotime($days[0])));
                if ($days[$i] === $expected) $streak++; else break;
            }
        }
    } catch (Exception $e) {}

    // Handle POST
    $profileMsg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['profile_action'] ?? '') === 'update_profile') {
        $newFirst = trim($_POST['firstName'] ?? '');
        $newLast = trim($_POST['lastName'] ?? '');
        $newNick = trim($_POST['nickname'] ?? '');
        $curPass = $_POST['currentPassword'] ?? '';
        $newPass = $_POST['newPassword'] ?? '';
        $confPass = $_POST['confirmPassword'] ?? '';

        try {
            $pdo->prepare("UPDATE User SET firstName = ?, lastName = ?, name = ?, updatedAt = NOW() WHERE id = ?")->execute([$newFirst, $newLast, $newNick, $userId]);
            $profileMsg = 'ok';

            if (!empty($newPass)) {
                if ($newPass === $confPass) {
                    $hashed = password_hash($newPass, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE User SET password = ? WHERE id = ?")->execute([$hashed, $userId]);
                } else {
                    $profileMsg = 'pass_mismatch';
                }
            }
        } catch (Exception $e) { $profileMsg = 'error'; }

        // Reload data
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $firstName = $user['firstName'] ?? '';
        $lastName = $user['lastName'] ?? '';
        $fullName = trim($firstName . ' ' . $lastName);
        if (empty($fullName)) $fullName = $user['name'] ?? 'Estudiante';
        $nickname = $user['name'] ?? '';
    }
?>

<style>
.v3-profile-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
.v3-profile-cols { display: grid; grid-template-columns: 1fr; gap: 1rem; margin-bottom: 1.5rem; }
.v3-profile-bottom { display: flex; flex-direction: column; gap: 1rem; align-items: center; text-align: center; justify-content: space-between; }
@media (min-width: 1024px) {
    .v3-profile-grid { grid-template-columns: 380px 1fr; }
    .v3-profile-cols { grid-template-columns: 1fr 1fr; }
    .v3-profile-bottom { flex-direction: row; text-align: left; }
}
</style>
<div style="max-width:1920px;margin:0 auto;padding:1rem 1.5rem 2rem;">
<div style="margin-bottom:1.5rem;">
<h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0;">Mi cuenta</h1>
<p style="font-size:0.875rem;color:#6b7280;margin:4px 0 0;">Gestiona tu informaci&oacute;n</p>
</div>

<?php if($profileMsg === 'ok'): ?><div style="padding:12px 16px;background:#dcfce7;border:1px solid #bbf7d0;border-radius:12px;color:#166534;font-size:0.875rem;font-weight:600;margin-bottom:1rem;">Perfil actualizado correctamente.</div><?php elseif($profileMsg === 'pass_mismatch'): ?><div style="padding:12px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:12px;color:#991b1b;font-size:0.875rem;font-weight:600;margin-bottom:1rem;">Las contrase&ntilde;as no coinciden.</div><?php endif; ?>

<form method="POST">
<input type="hidden" name="profile_action" value="update_profile">

<div class="v3-profile-grid">
<!-- LEFT: Avatar Card -->
<div>
    <div style="background:white;border-radius:1rem;padding:2rem;border:1px solid #f3f4f6;text-align:center;margin-bottom:1.5rem;">
        <div style="width:128px;height:128px;border-radius:50%;margin:0 auto 1rem;overflow:hidden;border:4px solid #f3f4f6;background:#111827;display:flex;align-items:center;justify-content:center;">
            <?php if(!empty($imageUrl)):?><img src="<?=htmlspecialchars(ltrim($imageUrl,'/')) ?>" style="width:100%;height:100%;object-fit:cover;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><span style="display:none;font-size:3rem;font-weight:700;color:white;"><?=$initial?></span><?php else:?><span style="font-size:3rem;font-weight:700;color:white;"><?=$initial?></span><?php endif;?>
        </div>
        <h2 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0 0 4px;"><?=htmlspecialchars($fullName)?></h2>
        <p style="font-size:0.875rem;font-weight:600;color:#FF6A00;margin:0;"><?=$role?></p>
        <div style="height:1px;background:#f3f4f6;margin:1.5rem 0;"></div>
        <div style="display:flex;justify-content:center;gap:2rem;">
            <div style="text-align:center;"><p style="font-size:1.5rem;font-weight:700;color:#FF6A00;margin:0;display:flex;align-items:center;justify-content:center;gap:4px;"><i class='bx bx-bolt-circle' style="font-size:1.25rem;"></i> <?=$totalPoints?></p><p style="font-size:0.75rem;color:#6b7280;margin:0;">Puntos XP</p></div>
            <div style="text-align:center;"><p style="font-size:1.5rem;font-weight:700;color:#FF6A00;margin:0;display:flex;align-items:center;justify-content:center;gap:4px;"><i class='bx bx-flame' style="font-size:1.25rem;"></i> <?=$streak?></p><p style="font-size:0.75rem;color:#6b7280;margin:0;">D&iacute;as Racha</p></div>
        </div>
    </div>

    <!-- Security Dark Card -->
    <div style="background:linear-gradient(135deg,#111827,#1f2937);border-radius:1rem;padding:1.5rem;color:white;position:relative;overflow:hidden;">
        <div style="position:absolute;bottom:-20px;right:-20px;opacity:0.1;"><i class='bx bx-check-shield' style="font-size:8rem;"></i></div>
        <div style="position:relative;z-index:10;">
            <h3 style="font-size:1.125rem;font-weight:700;margin:0 0 8px;">Seguridad</h3>
            <p style="font-size:0.875rem;color:#9ca3af;margin:0 0 12px;">Tu cuenta est&aacute; protegida con est&aacute;ndares de encriptaci&oacute;n bancaria.</p>
            <div style="display:flex;align-items:center;gap:8px;"><div style="width:8px;height:8px;border-radius:50%;background:#22c55e;"></div><span style="font-size:0.875rem;font-weight:600;color:#22c55e;">Conexi&oacute;n Segura</span></div>
        </div>
    </div>
</div>

<!-- RIGHT: Forms -->
<div style="background:white;border-radius:1rem;padding:2rem;border:1px solid #f3f4f6;">
    <!-- Personal Data -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;">
        <div style="width:40px;height:40px;border-radius:12px;background:rgba(255,106,0,0.1);display:flex;align-items:center;justify-content:center;"><i class='bx bx-user' style="color:#FF6A00;font-size:1.25rem;"></i></div>
        <h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">Datos Personales</h3>
    </div>
    <div class="v3-profile-cols">
        <div><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Nombre</label><input type="text" name="firstName" value="<?=htmlspecialchars($firstName)?>" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>
        <div><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Apellido</label><input type="text" name="lastName" value="<?=htmlspecialchars($lastName)?>" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>
    </div>
    <div style="margin-bottom:2rem;"><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Nickname Profesional</label><input type="text" name="nickname" value="<?=htmlspecialchars($nickname)?>" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>

    <div style="height:1px;background:#f3f4f6;margin-bottom:2rem;"></div>

    <!-- Security -->
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;">
        <div style="width:40px;height:40px;border-radius:12px;background:rgba(255,106,0,0.1);display:flex;align-items:center;justify-content:center;"><i class='bx bx-lock-alt' style="color:#FF6A00;font-size:1.25rem;"></i></div>
        <h3 style="font-size:1.125rem;font-weight:700;color:#111827;margin:0;">Seguridad</h3>
    </div>
    <div style="margin-bottom:1.5rem;"><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Contrase&ntilde;a Actual</label><input type="password" name="currentPassword" placeholder="&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>
    <div class="v3-profile-cols" style="margin-bottom:0;">
        <div><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Nueva Contrase&ntilde;a</label><input type="password" name="newPassword" placeholder="&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>
        <div><label style="display:block;font-size:0.75rem;font-weight:700;color:#FF6A00;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">Confirmar Nueva</label><input type="password" name="confirmPassword" placeholder="&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;" style="width:100%;padding:12px 16px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;color:#111827;outline:none;" onfocus="this.style.borderColor='#FF6A00'" onblur="this.style.borderColor='#e5e7eb'"></div>
    </div>
</div>
</div>

<!-- Bottom Bar -->
<div class="v3-profile-bottom" style="margin-top:1.5rem;padding:1rem 1.5rem;background:rgba(243,244,246,0.8);backdrop-filter:blur(8px);border-radius:1rem;">
    <p style="font-size:0.875rem;color:#6b7280;margin:0;">Guarda tus cambios antes de salir.</p>
    <div style="display:flex;gap:12px;">
        <a href="index.php?view=dashboard" style="padding:10px 24px;border:1px solid #e5e7eb;border-radius:12px;font-size:0.875rem;font-weight:600;color:#374151;text-decoration:none;background:white;">Cancelar</a>
        <button type="submit" style="padding:10px 24px;background:#FF6A00;color:white;border:none;border-radius:12px;font-size:0.875rem;font-weight:600;cursor:pointer;">Guardar Perfil</button>
    </div>
</div>
</form>
</div>

<?php
    return;
}
?>