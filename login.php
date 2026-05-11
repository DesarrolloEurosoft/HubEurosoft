<?php
session_start();
require 'config/database.php'; // Importar conexión PDO

$SECRET_KEY = "Hubeurosoft_2026_Secure_Key_!@#";

// Autologin con cookie "Recordarme"
if (!isset($_SESSION['user_id']) && isset($_COOKIE['hubeurosoft_remember']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $decrypted = openssl_decrypt($_COOKIE['hubeurosoft_remember'], 'AES-128-ECB', $SECRET_KEY);
    if ($decrypted) {
        $stmt = $pdo->prepare("SELECT u.*, c.isActive as companyActive, c.name as companyName, bu.name as buName FROM User u LEFT JOIN Company c ON u.companyId = c.id LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id WHERE u.id = ? LIMIT 1");
        $stmt->execute([$decrypted]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && ($user['role'] === 'ADMIN' || !isset($user['companyActive']) || $user['companyActive'] == 1)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_company'] = $user['companyId'];
            $_SESSION['user_bu'] = $user['businessUnitId'];
            $_SESSION['user_company_name'] = $user['companyName'] ?? null;
            $_SESSION['user_bu_name'] = $user['buName'] ?? null;
            
            $stmtRole = $pdo->prepare("SELECT tr.name FROM TrainingRole tr JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id WHERE rtu.B = ? LIMIT 1");
            $stmtRole->execute([$user['id']]);
            $trainingRoleName = strtoupper(trim($stmtRole->fetchColumn() ?: ''));
            
            if ($trainingRoleName === 'LECTOR OPERATIVO') {
                header('Location: index.php?view=courses');
            } else {
                header('Location: index.php');
            }
            exit;
        }
    }
}

$error = '';

// Procesamiento de login real a la Base de Datos
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $companyParam = trim($_POST['companyParam'] ?? '');
    $buParam = trim($_POST['buParam'] ?? '');
    
    try {
        // Obtenemos los datos del usuario, compañía y unidad de negocio
        $sql = "
            SELECT u.*, c.isActive as companyActive, c.name as companyName, bu.name as buName 
            FROM User u 
            LEFT JOIN Company c ON u.companyId = c.id 
            LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id
            WHERE u.email = ? 
        ";
        $params = [$loginInput];

        if (!empty($companyParam)) {
            // Permitir inicio de sesión con Nickname filtrado por la compañía en la URL
            $sql .= " OR (u.nickname = ? AND REPLACE(REPLACE(LOWER(c.name), ' ', ''), '-', '') = ? ";
            $cleanCompany = str_replace([' ', '-'], '', strtolower($companyParam));
            $params[] = $loginInput;
            $params[] = $cleanCompany;
            
            if (!empty($buParam)) {
                $sql .= " AND REPLACE(REPLACE(LOWER(bu.name), ' ', ''), '-', '') = ? ";
                $cleanBU = str_replace([' ', '-'], '', strtolower($buParam));
                $params[] = $cleanBU;
            }
            $sql .= ") ";
        } else {
            // Sin URL personalizada: verificar si hay duplicados globales de nickname
            $sql .= " OR u.nickname = ? ";
            $params[] = $loginInput;
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($results) > 1) {
                // Hay múltiples coincidencias. Verificamos si alguna coincide por EMAIL exacto.
                $exactMatch = false;
                foreach ($results as $r) {
                    if (strtolower(trim($r['email'])) === strtolower($loginInput)) {
                        $exactMatch = $r;
                        break;
                    }
                }
                
                if ($exactMatch) {
                    $user = $exactMatch;
                } else {
                    $error = "Tu usuario existe en múltiples instituciones. Por favor, usa el enlace de acceso personalizado que te proporcionó tu empresa.";
                    $user = false;
                }
            } elseif (count($results) === 1) {
                $user = $results[0];
            } else {
                $user = false;
            }
        }

        if (!empty($companyParam)) {
            $sql .= " LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Verificar la contraseña encriptada (bcrypt)
        if ($user && password_verify($password, $user['passwordHash'])) {
            
            // Si no es SuperAdmin, verificar que su empresa no esté suspendida
            if ($user['role'] !== 'ADMIN' && isset($user['companyActive']) && $user['companyActive'] == 0) {
                $error = "El acceso para tu institución ha sido temporalmente suspendido.";
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_company'] = $user['companyId'];
                $_SESSION['user_bu'] = $user['businessUnitId'];
                $_SESSION['user_company_name'] = $user['companyName'] ?? null;
                $_SESSION['user_bu_name'] = $user['buName'] ?? null;
                
                // --- COOKIE RECORDARME ---
                if (!empty($_POST['remember'])) {
                    $encrypted = openssl_encrypt($user['id'], 'AES-128-ECB', $SECRET_KEY);
                    setcookie('hubeurosoft_remember', $encrypted, time() + (86400 * 30), "/"); // Expira en 30 días
                }
            
            // ----------- BITÁCORA DE ACCESOS (TRACKING) -----------
            if (!function_exists('generateCuid')) {
                function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
            }
            
            // Obtener IP Real
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ip = explode(',', $ip)[0]; // Limpiar proxy múltiple
            
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
            
            try {
                // --- CALCULAR RACHA (STREAK) ANTES DE INSERTAR EL LOG DE HOY ---
                $checkToday = $pdo->prepare("SELECT COUNT(*) FROM LoginLog WHERE userId = ? AND DATE(createdAt) = CURDATE()");
                $checkToday->execute([$user['id']]);
                if ($checkToday->fetchColumn() == 0) {
                    // No se ha logueado hoy. ¿Se logueó ayer?
                    $checkYesterday = $pdo->prepare("SELECT COUNT(*) FROM LoginLog WHERE userId = ? AND DATE(createdAt) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
                    $checkYesterday->execute([$user['id']]);
                    if ($checkYesterday->fetchColumn() > 0) {
                        // Racha continua
                        $pdo->prepare("UPDATE User SET streakCount = streakCount + 1 WHERE id = ?")->execute([$user['id']]);
                    } else {
                        // Racha se reinicia
                        $pdo->prepare("UPDATE User SET streakCount = 1 WHERE id = ?")->execute([$user['id']]);
                    }
                }
                // -------------------------------------------------------------

                $stmtLog = $pdo->prepare("INSERT INTO LoginLog (id, userId, ipAddress, userAgent, createdAt) VALUES (?, ?, ?, ?, NOW())");
                $stmtLog->execute([generateCuid(), $user['id'], trim($ip), substr($userAgent, 0, 500)]);
                
                // --- GAMIFICATION: DAILY_LOGIN ---
                $ruleDaily = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'DAILY_LOGIN' AND isActive = 1")->fetch();
                if ($ruleDaily) {
                    $ptsDaily = (int)$ruleDaily['points'];
                    // Verifica si ya se logueó hoy (buscando puntos otorgados hoy)
                    $chkDaily = $pdo->prepare("SELECT COUNT(*) FROM UserPoints WHERE userId = ? AND actionType = 'DAILY_LOGIN' AND DATE(createdAt) = CURDATE()");
                    $chkDaily->execute([$user['id']]);
                    if ($chkDaily->fetchColumn() == 0) {
                        $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'DAILY_LOGIN', 'Recompensa de Acceso Diario', NOW())")->execute([generateCuid(), $user['id'], $ptsDaily]);
                        // Opcional: Sumar al totalPoints del usuario
                        $pdo->prepare("UPDATE User SET totalPoints = totalPoints + ? WHERE id = ?")->execute([$ptsDaily, $user['id']]);
                    }
                }
                // ---------------------------------
            } catch (Exception $e) {
                error_log("Error guardando bitácora de acceso: " . $e->getMessage());
            }
            
            require_once 'utils/assignment_sync.php';
            syncAllCourseAssignments($pdo, $user['id']);
            
            require_once 'utils/gamification_engine.php';
            evaluateUserAchievements($pdo, $user['id']);
            // ------------------------------------------------------

            // Redirigir según TrainingRole
            $stmtRole = $pdo->prepare("
                SELECT tr.name FROM TrainingRole tr
                JOIN _TrainingRoleToUser rtu ON rtu.A = tr.id
                WHERE rtu.B = ?
                LIMIT 1
            ");
            $stmtRole->execute([$user['id']]);
            $trainingRoleName = strtoupper(trim($stmtRole->fetchColumn() ?: ''));

            if ($trainingRoleName === 'LECTOR OPERATIVO') {
                header('Location: index.php?view=courses');
            } else {
                header('Location: index.php');
            }
            exit;
            } // Cerramos la verificación de companyActive
        } else {
            if (empty($error)) {
                $error = "Credenciales incorrectas. Verifica tu correo y contraseña.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error al conectar con la base de datos: Asegúrate de correr setup.php primero.";
    }
}

// Obtener los parámetros 'c' (compañía) y 'u' (unidad de negocio) de la URL
$companyQuery = $_GET['c'] ?? '';
$buQuery = $_GET['u'] ?? '';
$displayCompany = htmlspecialchars(urldecode($companyQuery));
$displayBU = htmlspecialchars(urldecode($buQuery));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - HubEurosoft</title>
    <link rel="icon" type="image/png" href="assets/images/favicon.png">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --bg-body: #f3f4f6;
            --bg-card: #151b2b; /* Dark Navy similar to image */
            --primary: #f97316;
            --primary-hover: #ea580c;
            --input-bg: #f8fafc;
            --text-light: #9ca3af;
            --text-white: #ffffff;
            --border-radius: 12px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', sans-serif;
        }

        body {
            background-color: var(--bg-body);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* Top Logo Space */
        .brand-logo {
            margin-bottom: 1.5rem;
            text-align: center;
            display: flex;
            justify-content: center;
        }
        
        .brand-logo img {
            max-height: 64px;
            object-fit: contain;
        }

        .auth-card {
            background-color: var(--bg-card);
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            position: relative;
        }

        .auth-header {
            margin-bottom: 2rem;
        }

        .auth-header h2 {
            color: var(--text-white);
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 0.2rem;
            letter-spacing: -0.02em;
        }

        .auth-header p {
            color: var(--text-light);
            font-size: 0.85rem;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(239, 68, 68, 0.2);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            color: var(--text-light);
            font-size: 0.7rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            color: #64748b;
            font-size: 1.2rem;
        }

        .input-wrapper .left-icon {
            left: 1rem;
        }

        .input-wrapper .right-icon {
            right: 1rem;
            cursor: pointer;
            transition: color 0.2s;
        }

        .input-wrapper .right-icon:hover {
            color: #1e293b;
        }

        .form-control {
            width: 100%;
            background-color: var(--input-bg);
            border: 2px solid transparent;
            border-radius: var(--border-radius);
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            font-size: 0.95rem;
            color: #1e293b;
            font-weight: 500;
            transition: all 0.2s;
            outline: none;
        }

        .form-control:focus {
            border-color: rgba(249, 115, 22, 0.5);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
        }

        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .checkbox-wrapper input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .checkbox-wrapper span {
            color: var(--text-light);
            font-size: 0.8rem;
        }

        .forgot-link {
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 600;
            transition: color 0.2s;
        }

        .forgot-link:hover {
            color: var(--primary-hover);
        }

        .btn-submit {
            width: 100%;
            background: linear-gradient(to right, #f97316, #fb923c);
            color: white;
            border: none;
            padding: 0.9rem;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-submit:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .footer-text {
            margin-top: 2rem;
            font-size: 0.75rem;
            color: #9ca3af;
            text-align: center;
            font-weight: 500;
        }
    </style>
</head>
<body>

    <div style="display:flex;flex-direction:column;align-items:center;width:100%;max-width:420px;">
    <!-- Fallback Logo / Actual Logo -->
    <div class="brand-logo" style="width:100%;">
        <img src="assets/images/logo.png" alt="Hub Eurosoft" onerror="this.outerHTML='<h1 style=\'color:#1a1d2e; font-weight:900; font-size: 2.5rem; letter-spacing:-0.03em;\'><i class=\'bx bxs-graduation\' style=\'color:#f97316;\'></i> Hub<span style=\'color:#f97316;\'>Eurosoft</span></h1>'">
    </div>

    <!-- Login Card -->
    <div class="auth-card" style="margin-top:2rem;">
        <div class="auth-header">
            <h2>Iniciar sesión</h2>
            <?php if ($companyQuery && $buQuery): ?>
                <p>Portal exclusivo de <strong style="color: var(--primary);"><?= $displayCompany ?></strong> - <?= $displayBU ?></p>
            <?php elseif ($companyQuery): ?>
                <p>Portal de acceso exclusivo para <strong style="color: var(--primary);"><?= $displayCompany ?></strong></p>
            <?php else: ?>
                <p>Accede a tu cuenta corporativa</p>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class='bx bxs-error-circle'></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php 
            $actionUrl = "login.php";
            if ($companyQuery) {
                $actionUrl .= '?c=' . urlencode($companyQuery);
                if ($buQuery) $actionUrl .= '&u=' . urlencode($buQuery);
            }
        ?>
        <form action="<?= $actionUrl ?>" method="POST">
            <input type="hidden" name="companyParam" value="<?= htmlspecialchars($companyQuery) ?>">
            <input type="hidden" name="buParam" value="<?= htmlspecialchars($buQuery) ?>">
            
            <div class="form-group">
                <label for="email" class="form-label">Correo o Nickname</label>
                <div class="input-wrapper">
                    <i class='bx bx-user left-icon'></i>
                    <input type="text" id="email" name="email" class="form-control" placeholder="juan.perez o tu@empresa.com" required>
                </div>
            </div>

            <div class="form-group">
                <label for="password" class="form-label">Contraseña</label>
                <div class="input-wrapper">
                    <i class='bx bx-lock-alt left-icon'></i>
                    <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
                    <i class='bx bx-show right-icon' id="togglePassword" title="Mostrar contraseña"></i>
                </div>
            </div>

        <?php 
            $forgotUrl = "forgot_password.php";
            if ($companyQuery) {
                $forgotUrl .= '?c=' . urlencode($companyQuery);
                if ($buQuery) $forgotUrl .= '&u=' . urlencode($buQuery);
            }
        ?>

        <div class="options-row">
            <label class="checkbox-wrapper">
                <input type="checkbox" name="remember">
                <span>Recordarme</span>
            </label>
            <a href="<?= $forgotUrl ?>" class="forgot-link">¿Olvidaste tu contraseña?</a>
        </div>

            <button type="submit" class="btn-submit">
                Iniciar Sesión <i class='bx bx-right-arrow-alt' style="font-size: 1.2rem;"></i>
            </button>
        </form>
    </div>
    </div><!-- /container -->

    <div class="footer-text">
        &copy; <?= date("Y") ?> Hub Eurosoft. Todos los derechos reservados.
    </div>

    <script>
        // Funcionalidad para mostrar/ocultar contraseña
        const togglePassword = document.getElementById('togglePassword');
        const passwordField = document.getElementById('password');

        togglePassword.addEventListener('click', function () {
            const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordField.setAttribute('type', type);
            
            // Toggle icon
            this.classList.toggle('bx-show');
            this.classList.toggle('bx-hide');
            this.style.color = type === 'text' ? '#f97316' : '#64748b';
        });
    </script>
</body>
</html>
