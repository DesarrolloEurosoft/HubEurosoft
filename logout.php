<?php
// Iniciar sesión para poder destruirla
session_start();

// Construir URL de redirección antes de limpiar la sesión
$redirectUrl = "login.php";
if (!empty($_SESSION['user_company_name'])) {
    $redirectUrl .= "?c=" . urlencode($_SESSION['user_company_name']);
    if (!empty($_SESSION['user_bu_name'])) {
        $redirectUrl .= "&u=" . urlencode($_SESSION['user_bu_name']);
    }
}

// Vaciar todas las variables de sesión
$_SESSION = array();

// Destruir la cookie de sesión si existe
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Borrar cookie Recordarme
setcookie('hubeurosoft_remember', '', time() - 3600, "/");

// Finalmente, destruir la sesión
session_destroy();

// Redirigir al inicio de sesión personalizado
header("Location: " . $redirectUrl);
exit;
?>
