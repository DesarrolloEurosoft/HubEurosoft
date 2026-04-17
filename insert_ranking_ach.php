<?php
require 'config/database.php';
function generateCuid() { return 'c' . uniqid() . bin2hex(random_bytes(2)); }

$stmt = $pdo->prepare("SELECT id FROM Achievement WHERE targetAction = 'RANKING_FIRST_PLACE'");
$stmt->execute();
if ($stmt->rowCount() == 0) {
    $ins = $pdo->prepare("INSERT INTO Achievement (id, title, description, icon, imagePath, targetAction, threshold, pointsBonus, color, isActive, createdAt, updatedAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $ins->execute([generateCuid(), 'Rey del Ranking', 'Alcanzaste el primer lugar del ranking general en tu Unidad de Negocio.', 'bx bxs-crown', '', 'RANKING_FIRST_PLACE', 1, 500, 'bg-yellow-500', 1]);
    echo "inserted\n";
} else {
    echo "already exists\n";
}
