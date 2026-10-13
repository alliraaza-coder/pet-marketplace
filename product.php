<?php
/**
 * Legacy product.php route
 * Redirects seamlessly to the unified product_details.php
 */
require_once 'includes/config.php';

$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug       = isset($_GET['slug']) ? sanitize_input($_GET['slug']) : '';

if ($product_id > 0) {
    header('Location: product_details.php?id=' . $product_id, true, 301);
    exit;
} elseif (!empty($slug)) {
    header('Location: product_details.php?slug=' . urlencode($slug), true, 301);
    exit;
} else {
    header('Location: shop.php', true, 302);
    exit;
}
