<?php
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'GET';
$old_cwd = getcwd();
chdir(__DIR__ . '/admin');

ob_start();
try {
    include 'categories.php';
} catch (Throwable $e) {
    echo "CATEGORIES ERROR: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
}
ob_end_clean();

ob_start();
try {
    include 'reports.php';
} catch (Throwable $e) {
    echo "REPORTS ERROR: " . $e->getMessage() . " on line " . $e->getLine() . "\n";
}
ob_end_clean();
