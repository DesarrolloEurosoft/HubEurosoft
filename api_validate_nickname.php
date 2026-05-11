<?php
require 'config/database.php';

$nickname = $_GET['nickname'] ?? '';
$companyId = !empty($_GET['companyId']) ? $_GET['companyId'] : null;
$buId = !empty($_GET['buId']) ? $_GET['buId'] : null;
$excludeId = !empty($_GET['excludeId']) ? $_GET['excludeId'] : null;

if (empty($nickname)) {
    echo json_encode(['taken' => false]);
    exit;
}

$query = "SELECT id FROM User WHERE nickname = ?";
$params = [$nickname];

if ($excludeId) {
    $query .= " AND id != ?";
    $params[] = $excludeId;
}

if ($buId) {
    $query .= " AND businessUnitId = ?";
    $params[] = $buId;
} elseif ($companyId) {
    $query .= " AND companyId = ? AND businessUnitId IS NULL";
    $params[] = $companyId;
} else {
    $query .= " AND companyId IS NULL AND businessUnitId IS NULL";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);

echo json_encode(['taken' => (bool)$stmt->fetch()]);
