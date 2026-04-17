<style>
/* Estilos específicos de la bitácora */
.log-header {
    background-color: white;
    padding: 1.5rem 2rem;
    border-radius: var(--radius-md);
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    box-shadow: var(--shadow-sm);
}
.log-header .left-info {
    display: flex;
    align-items: center;
    gap: 1.5rem;
}
.log-header .icon-box {
    width: 48px;
    height: 48px;
    background-color: rgba(249, 115, 22, 0.1); /* Naranja transparente */
    color: var(--primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
}
.log-header .title-block h3 {
    margin: 0;
    font-size: 1.25rem;
    color: #1e293b;
    font-weight: 800;
}
.log-header .title-block p {
    margin: 0;
    font-size: 0.75rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-top: 4px;
}
.system-status {
    padding: 0.4rem 1rem;
    border-radius: 20px;
    background-color: rgba(34, 197, 94, 0.1);
    color: #16a34a;
    font-weight: 700;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

/* Tabla de Registros */
.log-table-container {
    background: white;
    border-radius: var(--radius-md);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.log-table {
    width: 100%;
    border-collapse: collapse;
}
.log-table th {
    background-color: #f8fafc;
    padding: 1rem 1.5rem;
    text-align: left;
    font-size: 0.7rem;
    font-weight: 700;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    border-bottom: 2px solid #e2e8f0;
}
.log-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.log-table tr:hover td {
    background-color: #f8fafc;
}

/* Elementos de la tabla */
.user-cell {
    display: flex;
    align-items: center;
    gap: 1rem;
}
.user-cell .av {
    width: 38px;
    height: 38px;
    background-color: #e2e8f0;
    color: #64748b;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}
.user-cell .user-info {
    display: flex;
    flex-direction: column;
}
.user-cell .user-name {
    font-weight: 800;
    color: #1e293b;
    font-size: 0.9rem;
}
.user-cell .user-email {
    font-size: 0.7rem;
    color: #94a3b8;
    margin-top: 2px;
}

.org-badge {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    font-weight: 800;
    color: #64748b;
    text-transform: uppercase;
}
.org-badge::before {
    content: '';
    display: inline-block;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background-color: var(--primary); /* Punto inicial azul o naranja */
    background-color: #3b82f6; /* Se parece más a la captura */
}

.role-badge {
    padding: 0.3rem 0.6rem;
    border-radius: 20px;
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    border: 1px solid currentColor;
}
.role-badge.admin { color: #ef4444; background: rgba(239,68,68,0.05); }
.role-badge.company { color: #d97706; background: rgba(245,158,11,0.1); border-color: #fcd34d; }
.role-badge.student { color: #3b82f6; background: rgba(59,130,246,0.05); border-color: #e2e8f0; }
.role-badge.instructor { color: #10b981; background: rgba(16,185,129,0.05); }

.ip-pill {
    background: #f1f5f9;
    color: #64748b;
    padding: 0.4rem 0.8rem;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.device-info {
    font-size: 0.75rem;
    font-weight: 800;
    color: #1e293b;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.device-info span.os {
    color: #64748b;
    font-weight: 500;
    font-size: 0.7rem;
}

.access-counter {
    color: #f97316;
    font-weight: 800;
    font-size: 0.9rem;
}

.date-col {
    font-size: 0.8rem;
    font-weight: 700;
    color: #475569;
}
.time-col {
    font-size: 0.8rem;
    font-weight: 800;
    color: #1e293b;
}
</style>

<?php
// Configurar Zona Horaria Estricta de la Ciudad de México
$tz = new DateTimeZone('America/Mexico_City');
$meses = [
    '01' => 'enero', '02' => 'febrero', '03' => 'marzo', '04' => 'abril',
    '05' => 'mayo', '06' => 'junio', '07' => 'julio', '08' => 'agosto',
    '09' => 'septiembre', '10' => 'octubre', '11' => 'noviembre', '12' => 'diciembre'
];

function parseUserAgent($ua) {
    $browser = 'Desconocido';
    $os = 'Desconocido';

    // Browsers
    if (preg_match('/edg/i', $ua)) { $browser = 'Edge'; } 
    elseif (preg_match('/chrome|crios/i', $ua)) { $browser = 'Chrome'; } 
    elseif (preg_match('/firefox|fxios/i', $ua)) { $browser = 'Firefox'; } 
    elseif (preg_match('/safari/i', $ua)) { $browser = 'Safari'; }
    elseif (preg_match('/opera|opr/i', $ua)) { $browser = 'Opera'; }

    // OS
    if (preg_match('/windows|win32/i', $ua)) { $os = 'Windows'; } 
    elseif (preg_match('/macintosh|mac os x/i', $ua)) { $os = 'MacOS'; } 
    elseif (preg_match('/iphone|ipad|ipod/i', $ua)) { $os = 'iOS'; } 
    elseif (preg_match('/android/i', $ua)) { $os = 'Android'; } 
    elseif (preg_match('/linux/i', $ua)) { $os = 'Linux'; }

    return ['browser' => $browser, 'os' => $os];
}

// Consultar el Log
$logs = [];
try {
    // Obtenemos los últimos 200 inicios de sesión detallados
    $query = "
        SELECT 
            l.id as logId,
            u.id as userId,
            u.name,
            u.email,
            u.role,
            l.ipAddress,
            l.userAgent,
            l.createdAt,
            COALESCE(c.name, 'INTERNO') as companyName,
            (SELECT COUNT(*) FROM LoginLog sub WHERE sub.userId = u.id) as totalAccesses
        FROM LoginLog l
        JOIN User u ON l.userId = u.id
        LEFT JOIN Company c ON u.companyId = c.id
        ORDER BY l.createdAt DESC
        LIMIT 200
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($e->getCode() == '42S02') {
        echo "<div class='alert alert-warning'>Aún no existen accesos registrados o la tabla no está creada.</div>";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
    <p style="color: #64748b; font-size: 0.9rem; font-weight: 500; margin: 0;">Historial cronológico de accesos a la plataforma Hub Eurosoft.</p>
    <div style="background: white; padding: 0.5rem 1rem; border-radius: 20px; font-weight: 800; font-size: 0.8rem; color: #f97316; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 0.5rem;">
        <i class='bx bx-calendar'></i>
        <?php echo mb_strtoupper($meses[date('m')]) . ' DE ' . date('Y'); ?>
    </div>
</div>

<div class="log-header">
    <div class="left-info">
        <div class="icon-box">
            <i class='bx bx-time-five'></i>
        </div>
        <div class="title-block">
            <h3>Bitácora de Accesos</h3>
            <p>MONITOREO DE ACTIVIDAD &middot; ÚLTIMOS 200 REGISTROS</p>
        </div>
    </div>
    <div class="system-status">SISTEMA ACTIVO</div>
</div>

<div class="log-table-container">
    <div style="overflow-x: auto;">
        <table class="log-table">
            <thead>
                <tr>
                    <th>Colaborador</th>
                    <th>Organización</th>
                    <th>Rol</th>
                    <th>Dirección IP</th>
                    <th>Dispositivo</th>
                    <th>Accesos</th>
                    <th>Fecha</th>
                    <th>Hora</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 3rem; color: #94a3b8;">
                            Aún no hay registros de inicio de sesión capturados en la base de datos.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): 
                        // Formateo de fecha y hora local ajustada a CDMX
                        $dt = new DateTime($log['createdAt']); // UTC nativo de la DB normalmente
                        // Si la BD guarda en America/Mexico_City directo ignoramos, pero standardizamos
                        // Assuming DB acts as Local/UTC depending on config, we re-apply timezone:
                        $dt->setTimezone($tz);
                        
                        $dia = $dt->format('d');
                        $mesNum = $dt->format('m');
                        $anio = $dt->format('Y');
                        $hora = $dt->format('H:i:s');
                        
                        $fechaLegible = $dia . ' de ' . $meses[$mesNum] . ' de ' . $anio;
                        
                        // Device
                        $device = parseUserAgent($log['userAgent']);
                        
                        // Role Translate
                        $roleTrans = match(strtoupper($log['role'])) {
                            'ROOT_ADMIN', 'ADMIN' => 'A. Global',
                            'COMPANY_LEADER' => 'Líder Compañía',
                            'BUSINESS_UNIT_LEADER' => 'Líder Unidad',
                            'SUPERVISOR' => 'Supervisor',
                            'STUDENT' => 'Estudiante',
                            'INSTRUCTOR' => 'Instructor',
                            default => htmlspecialchars($log['role'])
                        };
                        
                        // Role Css
                        $roleCss = match(strtoupper($log['role'])) {
                            'ROOT_ADMIN', 'ADMIN', 'SUPERVISOR' => 'admin',
                            'COMPANY_LEADER' => 'company',
                            'BUSINESS_UNIT_LEADER', 'INSTRUCTOR' => 'instructor',
                            'STUDENT' => 'student',
                            default => 'student'
                        };
                    ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <div class="av"><i class='bx bx-user'></i></div>
                                    <div class="user-info">
                                        <span class="user-name"><?php echo htmlspecialchars($log['name']); ?></span>
                                        <span class="user-email"><?php echo htmlspecialchars($log['email']); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="org-badge"><?php echo htmlspecialchars($log['companyName']); ?></span>
                            </td>
                            <td>
                                <span class="role-badge <?php echo $roleCss; ?>"><?php echo $roleTrans; ?></span>
                            </td>
                            <td>
                                <div class="ip-pill">
                                    <i class='bx bx-current-location'></i>
                                    <?php echo htmlspecialchars($log['ipAddress']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="device-info">
                                    <?php echo $device['browser']; ?>
                                    <span class="os"><?php echo $device['os']; ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="access-counter"><?php echo $log['totalAccesses']; ?></span>
                            </td>
                            <td class="date-col">
                                <?php echo $fechaLegible; ?>
                            </td>
                            <td class="time-col">
                                <?php echo $hora; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
