<?php
require 'database_vps.php';
$stmt = $pdo->query("SHOW TRIGGERS");
$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('triggers_dump.json', json_encode($triggers, JSON_PRETTY_PRINT));
echo "Triggers dumped.";
