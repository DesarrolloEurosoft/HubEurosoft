<?php
session_start();
header('Content-Type: application/json');

require 'config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No session']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = strtoupper($_SESSION['user_role'] ?? '');
$isAdminLeader = in_array($userRole, ['ADMIN', 'COMPANY_LEADER', 'BUSINESS_UNIT_LEADER']);

$action = $_POST['action'] ?? '';
$targetId = $_POST['target_id'] ?? '';

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

try {
    if (!$isAdminLeader) {
        throw new Exception("Sin permisos de moderación.");
    }
    
    if ($action === 'mark_helpful') {
        $stmt = $pdo->prepare("SELECT authorId, isHelpful FROM ForumReply WHERE id = ?");
        $stmt->execute([$targetId]);
        $reply = $stmt->fetch();
        if (!$reply) throw new Exception("Respuesta no encontrada.");
        if ($reply['isHelpful']) throw new Exception("Ya estaba marcada como útil.");
        
        $pdo->prepare("UPDATE ForumReply SET isHelpful = 1 WHERE id = ?")->execute([$targetId]);
        
        // Gamification HELPFUL_ANSWER
        $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'HELPFUL_ANSWER' AND isActive = 1")->fetch();
        if ($rule && $rule['points'] > 0) {
            $pts = (int)$rule['points'];
            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'HELPFUL_ANSWER', 'Respuesta Útil validada por Moderación', NOW())")
                ->execute([generateCuid(), $reply['authorId'], $pts]);
            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $reply['authorId']]);
        }
    } 
    elseif ($action === 'mark_practice') {
        $stmt = $pdo->prepare("SELECT authorId, isValidatedPractice FROM ForumTopic WHERE id = ?");
        $stmt->execute([$targetId]);
        $topic = $stmt->fetch();
        if (!$topic) throw new Exception("Tema no encontrado.");
        if ($topic['isValidatedPractice']) throw new Exception("Ya estaba validada.");
        
        $pdo->prepare("UPDATE ForumTopic SET isValidatedPractice = 1, threadType = 'GOOD_PRACTICE' WHERE id = ?")->execute([$targetId]);
        
        // Gamification VALIDATED_PRACTICE
        $rule = $pdo->query("SELECT points FROM GamificationRule WHERE actionType = 'VALIDATED_PRACTICE' AND isActive = 1")->fetch();
        if ($rule && $rule['points'] > 0) {
            $pts = (int)$rule['points'];
            $pdo->prepare("INSERT INTO UserPoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, 'VALIDATED_PRACTICE', 'Aporte metodológico aprobado oficialmente', NOW())")
                ->execute([generateCuid(), $topic['authorId'], $pts]);
            $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $topic['authorId']]);
        }
    }
    else {
        throw new Exception("Acción de moderación inválida.");
    }

    echo json_encode(['success' => true]);

} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
