<?php
session_start();
require 'config/database.php';

// Intentar crear la tabla si no existe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS PasswordReset (
        id INT AUTO_INCREMENT PRIMARY KEY,
        userId VARCHAR(191) NOT NULL,
        token VARCHAR(191) NOT NULL,
        expiresAt DATETIME NOT NULL,
        createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (token),
        INDEX (userId)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
} catch (Exception $e) {}

$error = '';
$success = '';

$companyQuery = $_GET['c'] ?? '';
$buQuery = $_GET['u'] ?? '';
$displayCompany = htmlspecialchars(urldecode($companyQuery));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim($_POST['email'] ?? '');
    $cParam = trim($_POST['companyParam'] ?? '');
    $uParam = trim($_POST['buParam'] ?? '');

    try {
        $sql = "SELECT u.*, c.name as companyName, bu.name as buName FROM User u LEFT JOIN Company c ON u.companyId = c.id LEFT JOIN BusinessUnit bu ON u.businessUnitId = bu.id WHERE u.email = ?";
        $params = [$loginInput];

        if (!empty($cParam)) {
            $sql .= " OR (u.nickname = ? AND REPLACE(REPLACE(LOWER(c.name), ' ', ''), '-', '') = ? ";
            $params[] = $loginInput;
            $params[] = str_replace([' ', '-'], '', strtolower($cParam));
            if (!empty($uParam)) {
                $sql .= " AND REPLACE(REPLACE(LOWER(bu.name), ' ', ''), '-', '') = ? ";
                $params[] = str_replace([' ', '-'], '', strtolower($uParam));
            }
            $sql .= ") LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $sql .= " OR u.nickname = ?";
            $params[] = $loginInput;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($results) > 1) {
                $exactMatch = false;
                foreach ($results as $r) {
                    if (strtolower(trim($r['email'])) === strtolower($loginInput)) {
                        $exactMatch = $r; break;
                    }
                }
                if ($exactMatch) {
                    $user = $exactMatch;
                } else {
                    $error = "Tu usuario existe en múltiples instituciones. Por favor, vuelve a intentar usando el enlace personalizado de tu empresa.";
                    $user = false;
                }
            } elseif (count($results) === 1) {
                $user = $results[0];
            } else {
                $user = false;
            }
        }

        if ($user && empty($error)) {
            $userEmail = trim($user['email']);
            $isFakeEmail = (strpos($userEmail, '@hubeurosoft.com') !== false || strpos($userEmail, '@hubeurosoft.interno') !== false);
            
            if ($isFakeEmail) {
                // Generar notificación para Admins y Líderes
                $message = "El usuario " . $user['name'] . " (" . $user['nickname'] . ") ha solicitado recuperar su contraseña.";
                
                // Buscar Admins globales
                $stmtAdmins = $pdo->query("SELECT id FROM User WHERE role = 'ADMIN'");
                $admins = $stmtAdmins->fetchAll(PDO::FETCH_COLUMN);
                
                // Buscar Líderes de la misma empresa o BU
                $leadersQuery = "SELECT id FROM User WHERE (role = 'COMPANY_LEADER' AND companyId = ?) OR (role = 'BUSINESS_UNIT_LEADER' AND businessUnitId = ?)";
                $stmtLeaders = $pdo->prepare($leadersQuery);
                $stmtLeaders->execute([$user['companyId'], $user['businessUnitId']]);
                $leaders = $stmtLeaders->fetchAll(PDO::FETCH_COLUMN);
                $adminUrl = 'index.php?view=students&search=' . urlencode($user['nickname']);
                if (!empty($user['companyName'])) $adminUrl .= '&c=' . urlencode($user['companyName']);
                if (!empty($user['buName'])) $adminUrl .= '&u=' . urlencode($user['buName']);
                
                $metaAdmin = json_encode(['url' => $adminUrl]);
                
                if (!function_exists('generateCuid')) {
                    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
                }

                $stmtInsertNotif = $pdo->prepare("INSERT INTO notification (id, userId, type, title, message, metadata, isRead, createdAt) VALUES (?, ?, 'WARNING', 'Recuperación de Contraseña', ?, ?, 0, NOW())");
                
                foreach ($admins as $adminId) {
                    $stmtInsertNotif->execute([generateCuid(), $adminId, $message, $metaAdmin]);
                }
                foreach ($leaders as $leaderId) {
                    $stmtInsertNotif->execute([generateCuid(), $leaderId, $message, null]);
                }
                
                $success = "Se ha enviado una notificación a tus administradores y líderes para que restablezcan tu acceso. Por favor, comunícate con ellos.";
                
            } else {
                // Generar token para correo real
                $token = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $stmtToken = $pdo->prepare("INSERT INTO PasswordReset (userId, token, expiresAt) VALUES (?, ?, ?)");
                $stmtToken->execute([$user['id'], $token, $expiresAt]);
                
                // Enviar correo con URL dinámica
                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $resetLink = "{$protocol}://{$host}{$basePath}/reset_password.php?token={$token}";
                
                $subject = "Recuperación de Contraseña - HubEurosoft";
                $messageBody = "Hola " . $user['name'] . ",\n\n";
                $messageBody .= "Has solicitado restablecer tu contraseña. Haz clic en el siguiente enlace o cópialo en tu navegador (válido por 1 hora):\n\n";
                $messageBody .= $resetLink . "\n\n";
                $messageBody .= "Si no solicitaste este cambio, puedes ignorar este correo.\n";
                
                $headers = "From: noreply@hubeurosoft.com\r\n";
                $headers .= "Reply-To: noreply@hubeurosoft.com\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();
                
                @mail($userEmail, $subject, $messageBody, $headers);
                
                $success = "Se ha enviado un enlace de recuperación a tu correo electrónico. Por favor, revisa tu bandeja de entrada y tu carpeta de Correo No Deseado (SPAM).";
            }
        } else {
            if (empty($error)) {
                $error = "No se encontró ninguna cuenta asociada a esa información.";
            }
        }
    } catch (Exception $e) {
        $error = "Ocurrió un error al procesar la solicitud: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - HubEurosoft</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        :root {
            --primary: #f97316;
            --bg-color: #f8fafc;
            --surface: #ffffff;
            --text-main: #1e293b;
            --text-light: #64748b;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .auth-card {
            background: var(--surface);
            padding: 3rem;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
            width: 100%;
            max-width: 420px;
        }
        .form-control {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 2.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            box-sizing: border-box;
            outline: none;
        }
        .input-wrapper { position: relative; display: flex; align-items: center; margin-bottom: 1.5rem; }
        .input-wrapper i.left-icon { position: absolute; left: 1rem; color: #64748b; font-size: 1.2rem; }
        .btn-submit {
            width: 100%;
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.9rem;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .alert {
            padding: 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
        }
        .alert-error { background: #fee2e2; color: #ef4444; }
        .alert-success { background: #dcfce7; color: #16a34a; }
        a.back-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <h2 style="margin-top: 0; margin-bottom: 0.5rem;">Recuperar Contraseña</h2>
        <?php if ($companyQuery): ?>
            <p style="color: var(--text-light); font-size: 0.85rem; margin-bottom: 2rem;">Portal de <strong style="color: var(--primary);"><?= $displayCompany ?></strong></p>
        <?php else: ?>
            <p style="color: var(--text-light); font-size: 0.85rem; margin-bottom: 2rem;">Ingresa tu correo o nickname para recuperar tu acceso.</p>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php else: ?>
            <form action="" method="POST">
                <input type="hidden" name="companyParam" value="<?= htmlspecialchars($companyQuery) ?>">
                <input type="hidden" name="buParam" value="<?= htmlspecialchars($buQuery) ?>">
                <div class="input-wrapper">
                    <i class='bx bx-user left-icon'></i>
                    <input type="text" name="email" class="form-control" placeholder="juan.perez o tu@empresa.com" required>
                </div>
                <button type="submit" class="btn-submit">Solicitar Recuperación</button>
            </form>
        <?php endif; ?>
        
        <a href="login.php<?= $companyQuery ? '?c='.urlencode($companyQuery).( $buQuery ? '&u='.urlencode($buQuery) : '' ) : '' ?>" class="back-link">
            <i class='bx bx-arrow-back'></i> Volver al Inicio de Sesión
        </a>
    </div>
</body>
</html>
