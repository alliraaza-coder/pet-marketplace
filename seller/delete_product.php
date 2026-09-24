<?php
/**
 * Delete Product — Phase 3.1
 * Verifies seller ownership, deletes images from disk, then removes DB records.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

// Delete must be a POST action (prevent CSRF via GET links)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: products.php');
    exit;
}
verify_csrf();

$seller_id  = (int)$_SESSION['user_id'];
$product_id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);

if (!$product_id) {
    $_SESSION['error'] = 'Invalid product.';
    header('Location: products.php');
    exit;
}

// ── Verify ownership ─────────────────────────────────────────
$chk = $conn->prepare("SELECT id, title_en FROM products WHERE id = ? AND seller_id = ?");
$chk->bind_param('ii', $product_id, $seller_id);
$chk->execute();
$product = $chk->get_result()->fetch_assoc();

if (!$product) {
    $_SESSION['error'] = 'Product not found or you do not have permission to delete it.';
    header('Location: products.php');
    exit;
}

// ── Soft delete from database ──
$del = $conn->prepare("UPDATE products SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND seller_id = ?");
$del->bind_param('ii', $product_id, $seller_id);
$del->execute();

$_SESSION['success'] = 'Product "' . htmlspecialchars($product['title_en']) . '" has been deleted successfully.';
header('Location: products.php');
exit;
