<?php
require 'config/db.php';
$stmt = $pdo->query("SHOW TRIGGERS");
$triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($triggers);
