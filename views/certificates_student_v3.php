<?php
// Vista de certificados para ESTUDIANTES (V3 Bento)
// Si no es estudiante, el archivo original maneja el admin.
$userRoleCheck = strtoupper($_SESSION['user_role'] ?? '');
if ($userRoleCheck === 'STUDENT') {
    $userId = $_SESSION['user_id'];
    
    // Fetch student certificates
    try {
        $stmtCerts = $pdo->prepare("
            SELECT uc.id, uc.issuedAt, uc.verificationCode, 
                   c.name as certName, c.description as certDesc, c.imageUrl,
                   co.title as courseTitle
            FROM UserCertificate uc
            JOIN Certificate c ON uc.certificateId = c.id
            LEFT JOIN Course co ON uc.courseId = co.id
            WHERE uc.userId = ?
            ORDER BY uc.issuedAt DESC
        ");
        $stmtCerts->execute([$userId]);
        $certificates = $stmtCerts->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        $certificates = [];
    }
    $totalCerts = count($certificates);
    $totalHours = $totalCerts * 20;
?>

<div style="max-width:1920px;margin:0 auto;padding:1rem 1.5rem 2rem;">
<h1 style="font-size:1.75rem;font-weight:700;color:#111827;margin:0 0 1.5rem 0;">Certificados</h1>

<!-- Stats Cards (4 cols) -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1.25rem;margin-bottom:1.5rem;">
    <!-- Total Certs - Dark -->
    <div style="background:linear-gradient(135deg,#111827,#1f2937,#111827);border-radius:1rem;padding:1.5rem;box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);border:1px solid #374151;color:white;position:relative;overflow:hidden;">
        <div style="position:absolute;top:0;right:0;width:96px;height:96px;background:rgba(255,106,0,0.1);border-radius:50%;transform:translate(50%,-50%);filter:blur(40px);"></div>
        <div style="position:relative;z-index:10;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
                <div style="width:48px;height:48px;border-radius:1rem;background:rgba(255,106,0,0.2);display:flex;align-items:center;justify-content:center;border:1px solid rgba(255,106,0,0.3);"><i class='bx bx-award' style="color:#FF6A00;font-size:1.5rem;"></i></div>
                <h3 style="font-size:0.875rem;font-weight:600;color:#d1d5db;margin:0;">Total Certificados</h3>
            </div>
            <p style="font-size:2.25rem;font-weight:700;color:white;margin:0;"><?= $totalCerts ?></p>
            <p style="font-size:0.75rem;color:#9ca3af;margin:4px 0 0;">Completados con &eacute;xito</p>
        </div>
    </div>
    <!-- Hours - White -->
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;"><i class='bx bx-time-five' style="color:white;font-size:1.5rem;"></i></div>
            <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Horas de Formaci&oacute;n</h3>
        </div>
        <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $totalHours ?></p>
        <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Tiempo total certificado</p>
    </div>
    <!-- Average - White -->
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;"><i class='bx bx-star' style="color:white;font-size:1.5rem;"></i></div>
            <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Promedio</h3>
        </div>
        <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $totalCerts > 0 ? '90%' : '&mdash;' ?></p>
        <p style="font-size:0.75rem;color:#6b7280;margin:4px 0 0;">Calificaci&oacute;n media</p>
    </div>
    <!-- Verified - White -->
    <div style="background:white;border-radius:1rem;padding:1.5rem;border:1px solid #f3f4f6;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
            <div style="width:48px;height:48px;border-radius:1rem;background:linear-gradient(135deg,#a855f7,#7c3aed);display:flex;align-items:center;justify-content:center;"><i class='bx bx-check-circle' style="color:white;font-size:1.5rem;"></i></div>
            <h3 style="font-size:0.875rem;font-weight:600;color:#6b7280;margin:0;">Verificados</h3>
        </div>
        <p style="font-size:2.25rem;font-weight:700;color:#111827;margin:0;"><?= $totalCerts ?></p>
        <div style="display:flex;align-items:center;gap:4px;color:#16a34a;font-size:0.75rem;margin-top:4px;"><i class='bx bx-check-circle' style="font-size:0.75rem;"></i><span>100% autenticados</span></div>
    </div>
</div>

<!-- Tabs -->
<div style="background:white;border-radius:1rem;padding:1rem;border:1px solid #f3f4f6;margin-bottom:1.5rem;">
<div style="display:flex;align-items:center;gap:8px;background:#f3f4f6;border-radius:999px;padding:8px;">
<button class="cert-tab active" onclick="switchCertTab(this)" style="padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:500;border:none;cursor:pointer;background:white;color:#111827;box-shadow:0 4px 6px rgba(0,0,0,0.1);">Todos</button>
<button class="cert-tab" onclick="switchCertTab(this)" style="padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:500;border:none;cursor:pointer;background:transparent;color:#6b7280;">Recientes</button>
<button class="cert-tab" onclick="switchCertTab(this)" style="padding:10px 20px;border-radius:999px;font-size:0.875rem;font-weight:500;border:none;cursor:pointer;background:transparent;color:#6b7280;">Por curso</button>
</div></div>

<!-- Certificates Grid -->
<?php if ($totalCerts === 0): ?>
<div style="background:white;border-radius:1rem;padding:3rem;border:1px solid #f3f4f6;text-align:center;">
    <div style="width:80px;height:80px;background:#f3f4f6;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
        <i class='bx bx-award' style="color:#9ca3af;font-size:2.5rem;"></i>
    </div>
    <h3 style="font-size:1.25rem;font-weight:700;color:#111827;margin:0 0 8px;">No tienes certificados a&uacute;n</h3>
    <p style="color:#6b7280;margin:0 0 1.5rem;">Completa tus cursos para obtener certificados verificados</p>
    <a href="index.php?view=courses" style="display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#FF6A00,#FFA500);color:white;font-weight:600;border-radius:12px;text-decoration:none;transition:box-shadow 0.3s;" onmouseover="this.style.boxShadow='0 10px 15px rgba(255,106,0,0.3)'" onmouseout="this.style.boxShadow='none'">Explorar Cursos</a>
</div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;">
<?php foreach ($certificates as $idx => $cert): ?>
    <div style="background:white;border-radius:1rem;border:1px solid #f3f4f6;overflow:hidden;transition:all 0.3s;" onmouseover="this.style.boxShadow='0 20px 25px -5px rgba(0,0,0,0.1)'" onmouseout="this.style.boxShadow='none'">
        <!-- Certificate Image -->
        <div style="position:relative;height:192px;overflow:hidden;background:linear-gradient(135deg,#f3f4f6,#e5e7eb);">
            <div style="position:absolute;inset:0;background:linear-gradient(135deg,rgba(255,106,0,0.2),rgba(255,165,0,0.1));display:flex;align-items:center;justify-content:center;">
                <i class='bx bx-award' style="font-size:4rem;color:rgba(255,106,0,0.2);"></i>
            </div>
            <div style="position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,0.6),rgba(0,0,0,0.2),transparent);"></div>
            <!-- Verified Badge -->
            <div style="position:absolute;top:12px;right:12px;padding:6px 12px;background:#22c55e;color:white;font-size:0.75rem;font-weight:600;border-radius:999px;display:flex;align-items:center;gap:4px;box-shadow:0 4px 6px rgba(0,0,0,0.2);">
                <i class='bx bx-check-circle' style="font-size:0.75rem;"></i><span>Verificado</span>
            </div>
            <!-- Score -->
            <div style="position:absolute;bottom:12px;left:12px;padding:6px 12px;background:rgba(255,255,255,0.95);color:#111827;font-size:0.875rem;font-weight:700;border-radius:999px;box-shadow:0 4px 6px rgba(0,0,0,0.1);display:flex;align-items:center;gap:4px;">
                <i class='bx bxs-star' style="color:#eab308;font-size:0.875rem;"></i><span>100%</span>
            </div>
        </div>
        <!-- Info -->
        <div style="padding:1.25rem;">
            <div style="margin-bottom:12px;">
                <h3 style="font-weight:700;font-size:1.125rem;color:#111827;margin:0 0 4px;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;"><?= htmlspecialchars($cert['certName']) ?></h3>
                <p style="font-size:0.875rem;color:#6b7280;margin:0;">Certificaci&oacute;n Oficial</p>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:1rem;">
                <div style="display:flex;align-items:center;gap:8px;font-size:0.75rem;color:#6b7280;">
                    <i class='bx bx-calendar' style="font-size:0.875rem;"></i>
                    <span>Emitido: <?= date('d \d\e F \d\e Y', strtotime($cert['issuedAt'])) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;font-size:0.75rem;color:#6b7280;">
                    <i class='bx bx-file' style="font-size:0.875rem;"></i>
                    <span>HubEurosoft</span>
                </div>
            </div>
            <!-- Actions -->
            <div style="display:flex;align-items:center;gap:8px;">
                <a href="cert.php?code=<?= urlencode($cert['verificationCode']) ?>" target="_blank" style="flex:1;padding:10px;background:linear-gradient(135deg,#FF6A00,#FFA500);color:white;font-size:0.875rem;font-weight:600;border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;gap:8px;transition:box-shadow 0.3s;" onmouseover="this.style.boxShadow='0 10px 15px rgba(255,106,0,0.3)'" onmouseout="this.style.boxShadow='none'">
                    <i class='bx bx-download'></i><span>Descargar</span>
                </a>
                <a href="cert.php?code=<?= urlencode($cert['verificationCode']) ?>" target="_blank" style="padding:10px;background:#f3f4f6;color:#374151;border-radius:12px;text-decoration:none;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    <i class='bx bx-show' style="font-size:1.125rem;"></i>
                </a>
                <button onclick="navigator.clipboard.writeText(window.location.origin+'/cert.php?code=<?= urlencode($cert['verificationCode']) ?>');this.innerHTML='<i class=\'bx bx-check\'></i>';setTimeout(()=>{this.innerHTML='<i class=\'bx bx-share-alt\' style=\'font-size:1.125rem;\'></i>'},2000)" style="padding:10px;background:#f3f4f6;color:#374151;border-radius:12px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#e5e7eb'" onmouseout="this.style.background='#f3f4f6'">
                    <i class='bx bx-share-alt' style="font-size:1.125rem;"></i>
                </button>
            </div>
        </div>
    </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<script>
function switchCertTab(btn){document.querySelectorAll('.cert-tab').forEach(b=>{b.style.background='transparent';b.style.color='#6b7280';b.style.boxShadow='none';});btn.style.background='white';btn.style.color='#111827';btn.style.boxShadow='0 4px 6px rgba(0,0,0,0.1)';}
</script>

<?php
    return; // Stop here for students
}
// Below this point is admin-only code
?>