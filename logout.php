<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Destroy the session
session_unset();
session_destroy();

// Start a new session just to show a success message on the login page
session_start();
$_SESSION['success'] = "You have been successfully logged out.";

header('Location: ' . BASE_URL . '/login.php');
exit;
?>
