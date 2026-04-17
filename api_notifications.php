<?php
session_start();
require 'config/database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'No autorizado']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

if ($action === 'get') {
    $stmt = $pdo->prepare("SELECT id, type, title, message, metadata, isRead, createdAt FROM notification WHERE userId = ? AND isRead = 0 ORDER BY createdAt DESC LIMIT 10");
    $stmt->execute([$userId]);
    $notifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadCount = count($notifs); // Since we only filtered for isRead = 0

    $parsed = [];
    foreach ($notifs as $n) {
        $metaObj = null;
        if (!empty($n['metadata'])) {
            $metaObj = json_decode($n['metadata'], true);
        }
        $parsed[] = [
            'id' => $n['id'],
            'type' => $n['type'],
            'title' => $n['title'],
            'message' => $n['message'],
            'url' => $metaObj['url'] ?? null,
            'isRead' => (bool)$n['isRead'],
            'createdAt' => $n['createdAt']
        ];
    }

    echo json_encode([
        'status' => 'success',
        'unread' => $unreadCount,
        'data' => $parsed
    ]);
    exit;
}

if ($action === 'mark_read') {
    $notifId = $_POST['notif_id'] ?? '';
    if ($notifId) {
        $stmt = $pdo->prepare("UPDATE notification SET isRead = 1 WHERE id = ? AND userId = ?");
        $stmt->execute([$notifId, $userId]);
    }
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("UPDATE notification SET isRead = 1 WHERE userId = ?");
    $stmt->execute([$userId]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action === 'history') {
    $stmt = $pdo->prepare("SELECT id, type, title, message, metadata, isRead, createdAt FROM notification WHERE userId = ? AND createdAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY createdAt DESC LIMIT 100");
    $stmt->execute([$userId]);
    
    $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $historyParsed = [];
    foreach ($raw as $n) {
        $metaObj = null;
        if (!empty($n['metadata'])) {
            $metaObj = json_decode($n['metadata'], true);
        }
        $historyParsed[] = [
            'id' => $n['id'],
            'type' => $n['type'],
            'title' => $n['title'],
            'message' => $n['message'],
            'url' => $metaObj['url'] ?? null,
            'isRead' => (bool)$n['isRead'],
            'createdAt' => $n['createdAt']
        ];
    }
    
    echo json_encode(['status' => 'success', 'data' => $historyParsed]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Acción no válida']);
