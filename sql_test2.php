<?php
require 'config/database.php';
$stmt = $pdo->query("DESCRIBE ForumTopic");
foreach($stmt->fetchAll() as $row) {
    echo $row['Field'] . "\n";
}
