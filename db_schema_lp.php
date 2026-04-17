<?php
require 'config/database.php';
$tables = ['LearningPath', 'LearningPathCourse', 'UserLearningPath', 'CourseProgress', 'TopicProgress'];
foreach ($tables as $t) {
    echo "--- $t ---\n";
    try {
        $res = $pdo->query("SHOW CREATE TABLE $t")->fetch(PDO::FETCH_ASSOC);
        echo $res['Create Table'];
    } catch (Exception $e) {
         echo "Error: ". $e->getMessage();
    }
    echo "\n\n";
}
