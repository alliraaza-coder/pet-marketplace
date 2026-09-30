<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
define('BASE_URL', 'http://localhost/pet_marketplace');
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'admin';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'update_payment_accounts';
$_POST['pay_jazzcash_name'] = 'Test';
chdir(__DIR__ . '/admin');
try {
    include 'settings.php';
} catch (Throwable $e) {
    echo 'FATAL ERROR: ' . $e->getMessage();
}
