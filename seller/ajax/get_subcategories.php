<?php
/**
 * AJAX: Get Subcategories by Category ID
 * Called by add_product.php and edit_product.php via fetch()
 */
require_once '../../includes/config.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode([]);
    exit;
}

$category_id = (int)($_GET['category_id'] ?? 0);

if (!$category_id) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare(
    "SELECT id, name_en FROM subcategories WHERE category_id = ? AND status = 'active' ORDER BY name_en"
);
$stmt->bind_param('i', $category_id);
$stmt->execute();
$result = $stmt->get_result();

$subcategories = [];
while ($row = $result->fetch_assoc()) {
    $subcategories[] = ['id' => $row['id'], 'name_en' => $row['name_en']];
}

echo json_encode($subcategories);
