<?php
session_start();
require 'config/database.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = '';
$user = null;

if (!$token) {
    $error = "Enlace inválido o incompleto.";
} else {
    try {
        $stmt = $pdo->prepare("SELECT pr.userId, u.name, u.email FROM PasswordReset pr JOIN User u ON pr.userId = u.id COLLATE utf8mb4_unicode_ci WHERE pr.token = ? AND pr.expiresAt > NOW()");
        $stmt->execute([$token]);
        $resetData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resetData) {
            $error = "El enlace ha caducado o no es válido. Por favor, solicita uno nuevo.";
        } else {
            $user = $resetData;
        }
    } catch (Exception $e) {
        $error = "Error al verificar el token: " . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    
    if (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres.";
    } elseif ($password !== $confirm) {
        $error = "Las contraseñas no coinciden.";
    } else {
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE User SET passwordHash = ?, updatedAt = NOW() WHERE id = ?")->execute([$hash, $user['userId']]);
            $pdo->prepare("DELETE FROM PasswordReset WHERE userId = ?")->execute([$user['userId']]);
            
            // Enviar correo de confirmación solo si es un correo real
            $isFakeEmail = (strpos($user['email'], '@hubeurosoft.com') !== false || strpos($user['email'], '@hubeurosoft.interno') !== false);
            
            if (!$isFakeEmail) {
                $subject = "Confirmación de Cambio de Contraseña - HubEurosoft";
                $messageBody = "Hola " . $user['name'] . ",\n\n";
                $messageBody .= "Te confirmamos que la contraseña de tu cuenta ha sido actualizada exitosamente.\n";
                $messageBody .= "Si no realizaste este cambio, por favor contacta inmediatamente a tu administrador.\n\n";
                $messageBody .= "Saludos,\nEl equipo de HubEurosoft.";
                
                $headers = "From: noreply@hubeurosoft.com\r\n";
                $headers .= "Reply-To: noreply@hubeurosoft.com\r\n";
                $headers .= "X-Mailer: PHP/" . phpversion();
                
                @mail($user['email'], $subject, $messageBody, $headers);
            }
            
            $success = "¡Tu contraseña ha sido actualizada exitosamente! Ya puedes iniciar sesión.";
        } catch (Exception $e) {
            $error = "Error al actualizar la contraseña.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Contraseña - HubEurosoft</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #f97316; --bg-color: #f8fafc; --surface: #ffffff; --text-main: #1e293b; --text-light: #64748b; }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg-color); color: var(--text-main); margin: 0; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .auth-card { background: var(--surface); padding: 3rem; border-radius: 16px; box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05); width: 100%; max-width: 420px; }
        .form-control { width: 100%; padding: 0.8rem 1rem; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.95rem; box-sizing: border-box; outline: none; margin-bottom: 1.5rem; }
        .btn-submit { width: 100%; background: var(--primary); color: white; border: none; padding: 0.9rem; border-radius: 8px; font-size: 1rem; font-weight: 600; cursor: pointer; }
        .alert { padding: 1rem; border-radius: 8px; font-size: 0.85rem; margin-bottom: 1.5rem; }
        .alert-error { background: #fee2e2; color: #ef4444; }
        .alert-success { background: #dcfce7; color: #16a34a; }
        a.back-link { display: block; text-align: center; margin-top: 1rem; color: var(--text-light); text-decoration: none; font-size: 0.85rem; }
    </style>
</head>
<body>
    <div class="auth-card">
        <h2 style="margin-top: 0; margin-bottom: 0.5rem;">Nueva Contraseña</h2>
        <p style="color: var(--text-light); font-size: 0.85rem; margin-bottom: 2rem;">Ingresa una nueva contraseña para la cuenta vinculada a <?= htmlspecialchars($user['email'] ?? '') ?>.</p>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <a href="login.php" class="btn-submit" style="display: block; text-align: center; text-decoration: none; box-sizing: border-box;">Ir a Iniciar Sesión</a>
        <?php elseif ($user): ?>
            <form action="" method="POST">
                <input type="password" name="password" class="form-control" placeholder="Nueva Contraseña" required>
                <input type="password" name="confirm" class="form-control" placeholder="Confirmar Contraseña" required>
                <button type="submit" class="btn-submit">Guardar Contraseña</button>
            </form>
        <?php else: ?>
            <a href="forgot_password.php" class="btn-submit" style="display: block; text-align: center; text-decoration: none; box-sizing: border-box;">Volver a Intentar</a>
        <?php endif; ?>
    </div>
</body>
</html>
