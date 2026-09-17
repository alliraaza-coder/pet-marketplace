<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
require '../includes/config.php';
$res = $conn->query('SELECT id FROM orders LIMIT 1');
if ($res && $row = $res->fetch_assoc()) {
    $_GET['id'] = $row['id'];
} else {
    $_GET['id'] = 1;
}
try {
    ob_start();
    require 'order_details.php';
    ob_end_clean();
    echo "SUCCESS";
} catch (Throwable $e) {
    echo "FATAL ERROR CAUGHT: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
?>
