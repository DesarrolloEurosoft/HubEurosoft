<?php
require 'config/database.php';
$stmt = $pdo->query("DESCRIBE ForumReply");
foreach($stmt->fetchAll() as $row) {
    echo $row['Field'] . "\n";
}
