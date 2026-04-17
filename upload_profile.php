<?php
// GLOBAL FATAL ERROR HANDLER PARA EVITAR 500 y DESCUBRIR LA CAUSA
error_reporting(E_ALL);
ini_set('display_errors', 0); // No imprimir HTML basura de errores
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(200); // Forzar 200 OK para que fetch() pase
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'PHP FATAL ERROR: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
        ]);
        exit;
    }
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false; // Ignorar errores suprimidos con @ o reportes apagados
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

try {
    session_start();
    require_once 'config/database.php';

    header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$userId = $_SESSION['user_id'];
$type = $_POST['type'] ?? ''; // 'avatar' or 'banner'

if (!in_array($type, ['avatar', 'banner'])) {
    echo json_encode(['success' => false, 'message' => 'Tipo inválido']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Falta el archivo o hubo un error al transmitir la imagen.']);
    exit;
}

$file = $_FILES['image'];
$maxSize = 50 * 1024 * 1024; // 50MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'La imagen supera los 50MB permitidos.']);
    exit;
}

$allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];

// Fallback checking for mime type to avoid 500 errors if fileinfo extension is disabled on Hostinger
if (function_exists('finfo_open')) {
    $fileInfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = @finfo_file($fileInfo, $file['tmp_name']);
    // finfo_close($fileInfo); obsoleto en PHP > 8.1, liberado automáticamente
} elseif (function_exists('mime_content_type')) {
    $mimeType = @mime_content_type($file['tmp_name']);
} else {
    $mimeType = $file['type']; // Fallback directly to HTTP headers
}

if (!$mimeType || !in_array($mimeType, $allowedMimeTypes)) {
    echo json_encode(['success' => false, 'message' => 'Formato no permitido o irreconocible.']);
    exit;
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
if (!$ext) {
    $ext = explode('/', $mimeType)[1];
}
$ext = strtolower($ext);

// Respetar el nombre original pero namespaceado para evitar sobreescritura entre diferentes usuarios que suban "foto.jpg"
$safeOriginalName = preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($file['name']));
$filename = $type . '_' . $userId . '_' . $safeOriginalName;

$uploadDir = 'uploads/profiles/';
if (!file_exists($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        echo json_encode(['success' => false, 'message' => 'Error: El servidor no tiene permisos para crear la carpeta "uploads".']);
        exit;
    }
}

$destination = $uploadDir . $filename;

try {
    // 1. Obtener la foto vieja antes de reemplazarla para no dejar basura en el servidor
    $column = ($type === 'avatar') ? 'image' : 'bannerUrl';
    
    $stmtOld = $pdo->prepare("SELECT $column FROM User WHERE id = ?");
    $stmtOld->execute([$userId]);
    $oldVal = $stmtOld->fetchColumn();
    
    // 2. Si tenía foto vieja y existe físicamente en Hostinger, se ELIMINA primero
    if (!empty($oldVal)) {
        // Normalizar la ruta vieja para intentar ubicarla (ej. /uploads/profiles/foto.jpg a uploads/profiles/foto.jpg)
        $oldFilePath = ltrim($oldVal, '/');
        if (strpos($oldFilePath, 'uploads/profiles/') === 0 && file_exists($oldFilePath)) {
            @unlink($oldFilePath);
        }
    }

    // 3. Subir el archivo nuevo
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $dbPath = '/' . $destination;
        
        $stmt = $pdo->prepare("UPDATE User SET $column = ? WHERE id = ?");
        if ($stmt->execute([$dbPath, $userId])) {
            echo json_encode([
                'success' => true, 
                'url' => htmlspecialchars($dbPath),
                'message' => 'Actualizado exitosamente'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al enlazar la imagen en la BD.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'El servidor no pudo mover el archivo. Revisa los permisos (0777).']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ocurrió un error en el servidor PHP: ' . $e->getMessage()]);
} catch (Throwable $t) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'FALLO INTERNO (Hostinger): ' . $t->getMessage() . ' en línea ' . $t->getLine()]);
}

} catch (\Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'CRITICAL EXCEPTION: ' . $e->getMessage() . ' en linea ' . $e->getLine()]);
}
?>
