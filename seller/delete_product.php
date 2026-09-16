<?php
/**
 * Delete Product — Phase 3.1
 * Verifies seller ownership, deletes images from disk, then removes DB records.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$seller_id  = (int)$_SESSION['user_id'];
$product_id = (int)($_GET['id'] ?? 0);

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

// ── Delete image files from disk ─────────────────────────────
$img_res = $conn->query("SELECT image_url FROM product_images WHERE product_id = $product_id");
while ($img = $img_res->fetch_assoc()) {
    $path = '../assets/uploads/products/' . $img['image_url'];
    if (file_exists($path)) {
        @unlink($path);
    }
}

// ── Delete from database (ON DELETE CASCADE handles product_images) ──
$del = $conn->prepare("DELETE FROM products WHERE id = ? AND seller_id = ?");
$del->bind_param('ii', $product_id, $seller_id);
$del->execute();

$_SESSION['success'] = 'Product "' . htmlspecialchars($product['title_en']) . '" has been deleted successfully.';
header('Location: products.php');
exit;
