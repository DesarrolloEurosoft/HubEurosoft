<?php
// lesson.php (Legacy Router)
// Redirige todo el tráfico web a la nueva arquitectura integrada de vistas (Suite Mode)

$courseId = $_GET['course_id'] ?? $_GET['id'] ?? '';
$lessonId = $_GET['lesson_id'] ?? '';

if (empty($courseId)) {
    header("Location: index.php?view=courses");
    exit;
}

$url = "index.php?view=lesson&course_id=" . urlencode($courseId);
if (!empty($lessonId)) {
    $url .= "&lesson_id=" . urlencode($lessonId);
}

header("Location: " . $url);
exit;
?>
