<?php
require 'config/database.php';
require_once 'utils/gamification_engine.php';

$stmt = $pdo->query("SELECT id FROM User WHERE role = 'STUDENT'");
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($users as $uId) {
    evaluateUserAchievements($pdo, $uId);
}
echo "Evaluated achievements for " . count($users) . " students.\n";
