<?php
session_start();
require 'config/database.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = strtoupper($_SESSION['user_role'] ?? 'STUDENT');
$isAdmin = ($userRole === 'ADMIN' || $userRole === 'SUPERVISOR' || $userRole === 'ROOT_ADMIN');
$isLeader = ($isAdmin || $userRole === 'COMPANY_LEADER' || $userRole === 'BUSINESS_UNIT_LEADER');

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$targetId = $input['targetId'] ?? '';

if (!$action || !$targetId) {
    echo json_encode(['success' => false, 'error' => 'Faltan parámetros']);
    exit;
}

if (!function_exists('generateCuid')) {
    function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }
}

function checkAndAwardGamification($pdo, $authorId, $actionType, $descLog) {
    // Buscar la Regla de Puntos
    $rule = $pdo->prepare("SELECT points FROM gamificationrule WHERE actionType = ? AND isActive = 1");
    $rule->execute([$actionType]);
    $ruleData = $rule->fetch(PDO::FETCH_ASSOC);

    if ($ruleData && $ruleData['points'] > 0) {
        $pts = (int)$ruleData['points'];
        // Sumar Puntos al Usuario
        $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$pts, $authorId]);
        // Insertar Log de Puntos
        $pdo->prepare("INSERT INTO userpoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, ?, ?, NOW())")->execute([generateCuid(), $authorId, $pts, $actionType, $descLog]);
    }

    // Comprobar si desencadena Medallas (Logros)
    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM userpoints WHERE userId = ? AND actionType = ?");
    $stmtC->execute([$authorId, $actionType]);
    $countHits = (int)$stmtC->fetchColumn();

    $achievements = $pdo->prepare("SELECT * FROM Achievement WHERE targetAction = ? AND isActive = 1");
    $achievements->execute([$actionType]);
    
    while ($ach = $achievements->fetch(PDO::FETCH_ASSOC)) {
        if ($countHits >= (int)$ach['threshold']) {
            // Verificar si ya tiene la medalla
            $checkAch = $pdo->prepare("SELECT COUNT(*) FROM userachievement WHERE userId = ? AND achievementId = ?");
            $checkAch->execute([$authorId, $ach['id']]);
            if ($checkAch->fetchColumn() == 0) {
                // Otorgar medalla
                $pdo->prepare("INSERT INTO userachievement (id, userId, achievementId, unlockedAt) VALUES (?, ?, ?, NOW())")->execute([generateCuid(), $authorId, $ach['id']]);
                // Otorgar bonus de la medalla
                if ($ach['pointsBonus'] > 0) {
                    $bonus = (int)$ach['pointsBonus'];
                    $pdo->prepare("UPDATE User SET totalPoints = COALESCE(totalPoints,0) + ? WHERE id = ?")->execute([$bonus, $authorId]);
                    $pdo->prepare("INSERT INTO userpoints (id, userId, points, actionType, description, createdAt) VALUES (?, ?, ?, ?, ?, NOW())")->execute([generateCuid(), $authorId, $bonus, 'MEDAL_BONUS', "Bono por medalla: " . $ach['title']]);
                }
            }
        }
    }
}

try {
    $pdo->beginTransaction();

    if ($action === 'toggle_like') {
        // Verificar existencia de la respuesta
        $stmtRep = $pdo->prepare("SELECT authorId FROM ForumReply WHERE id = ?");
        $stmtRep->execute([$targetId]);
        $rep = $stmtRep->fetch(PDO::FETCH_ASSOC);

        if (!$rep) throw new Exception("Respuesta no encontrada");
        if ($rep['authorId'] === $userId) throw new Exception("No puedes darte Like a ti mismo");

        // Buscar si ya le ha dado Like
        $checkLike = $pdo->prepare("SELECT id FROM ForumReplyLike WHERE replyId = ? AND userId = ?");
        $checkLike->execute([$targetId, $userId]);
        $existing = $checkLike->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Quitar Like
            $pdo->prepare("DELETE FROM ForumReplyLike WHERE id = ?")->execute([$existing['id']]);
            $pdo->prepare("UPDATE ForumReply SET likesCount = GREATEST(likesCount - 1, 0) WHERE id = ?")->execute([$targetId]);
            $newCount = $pdo->query("SELECT likesCount FROM ForumReply WHERE id = '$targetId'")->fetchColumn();
            $pdo->commit();
            echo json_encode(['success' => true, 'liked' => false, 'count' => (int)$newCount]);
        } else {
            // Dar Like
            $pdo->prepare("INSERT INTO ForumReplyLike (id, replyId, userId, createdAt) VALUES (?, ?, ?, NOW())")->execute([generateCuid(), $targetId, $userId]);
            $pdo->prepare("UPDATE ForumReply SET likesCount = likesCount + 1 WHERE id = ?")->execute([$targetId]);
            $newCount = $pdo->query("SELECT likesCount FROM ForumReply WHERE id = '$targetId'")->fetchColumn();
            $pdo->commit();
            echo json_encode(['success' => true, 'liked' => true, 'count' => (int)$newCount]);
        }
        exit;
    } 
    
    elseif ($action === 'mark_helpful') {
        $stmtRep = $pdo->prepare("SELECT r.authorId, r.isHelpful, r.helpfulVotesCount, t.authorId as topicAuthorId FROM ForumReply r JOIN ForumTopic t ON r.topicId = t.id WHERE r.id = ?");
        $stmtRep->execute([$targetId]);
        $rep = $stmtRep->fetch(PDO::FETCH_ASSOC);

        if (!$rep) throw new Exception("Respuesta no encontrada");
        if ($rep['authorId'] === $userId) throw new Exception("No puedes votar por tu propia respuesta");

        // Buscar si ya votó
        $checkVote = $pdo->prepare("SELECT id FROM ForumReplyHelpfulVote WHERE replyId = ? AND userId = ?");
        $checkVote->execute([$targetId, $userId]);
        $existing = $checkVote->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
             // Si ya votó, podríamos permitirle "quitar el voto", pero la instrucción indica que solo puede darlo.
             throw new Exception("Ya has votado que esta respuesta es útil");
        }

        // Registrar Voto
        $pdo->prepare("INSERT INTO ForumReplyHelpfulVote (id, replyId, userId) VALUES (?, ?, ?)")->execute([generateCuid(), $targetId, $userId]);
        
        $newVotesCount = (int)$rep['helpfulVotesCount'] + 1;
        
        // Voto de Oro: El ADMIN u Organizador corona instantáneamente la respuesta como útil.
        if ($isLeader) {
             $newVotesCount = max($newVotesCount, 5); 
        }

        $pdo->prepare("UPDATE ForumReply SET helpfulVotesCount = ? WHERE id = ?")->execute([$newVotesCount, $targetId]);
        
        // Si la respuesta alcanza el umbral de 5 votos y no estaba coronada aún:
        $earned = false;
        if ($newVotesCount >= 5 && $rep['isHelpful'] == 0) {
            $pdo->prepare("UPDATE ForumReply SET isHelpful = 1 WHERE id = ?")->execute([$targetId]);
            checkAndAwardGamification($pdo, $rep['authorId'], 'HELPFUL_ANSWER', "La comunidad coronó tu respuesta como Útil (+5 votos).");
            $earned = true;
        }
        
        $pdo->commit();
        echo json_encode([
            'success' => true, 
            'voted' => true, 
            'count' => $newVotesCount, 
            'earned' => $earned, 
            'message' => $earned ? 'Respuesta coronada como útil y recompensas otorgadas' : 'Voto registrado'
        ]);
        exit;
    }
    
    elseif ($action === 'mark_practice') {
        if (!$isLeader) throw new Exception("Solo líderes y administradores pueden aprobar buenas prácticas.");

        $stmtTop = $pdo->prepare("SELECT authorId, isValidatedPractice FROM ForumTopic WHERE id = ?");
        $stmtTop->execute([$targetId]);
        $top = $stmtTop->fetch(PDO::FETCH_ASSOC);

        if (!$top) throw new Exception("Hilo no encontrado");
        if ($top['authorId'] === $userId) throw new Exception("No puedes validar tu propio tema como buena práctica");
        if ($top['isValidatedPractice'] == 1) throw new Exception("El tema ya estaba validado como buena práctica");

        $pdo->prepare("UPDATE ForumTopic SET isValidatedPractice = 1 WHERE id = ?")->execute([$targetId]);
        checkAndAwardGamification($pdo, $top['authorId'], 'VALIDATED_PRACTICE', "Tu hilo fue validado Oficialmente como Buena Práctica.");
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Práctica Oficialmente Aprobada con recompensas']);
        exit;
    }

    throw new Exception("Acción Desconocida");

} catch (Exception $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
