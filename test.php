<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
require 'config/database.php';

try {
    require 'views/forum_moderation.php';
} catch (Throwable $e) {
    echo "ERROR in forum_moderation: " . $e->getMessage() . "\n";
}

try {
    require 'views/forums.php';
} catch (Throwable $e) {
    echo "ERROR in forums: " . $e->getMessage() . "\n";
}
