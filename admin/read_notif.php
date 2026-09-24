<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
    $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE is_read = 0");
    header('Location: index.php');
    exit;
}

if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // get link before updating
    $stmt = $conn->prepare("SELECT link FROM admin_notifications WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    $upd = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE id = ?");
    $upd->bind_param("i", $id);
    $upd->execute();
    
    if ($res && !empty($res['link'])) {
        header('Location: ' . $res['link']);
    } else {
        header('Location: index.php');
    }
    exit;
}

header('Location: index.php');
exit;
