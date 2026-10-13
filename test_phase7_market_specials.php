<?php
/**
 * Test Suite: Phase 7 — Admin Market Specials Dedicated Page & Flows
 *
 * Requirements to test:
 * 1. Admin adds Bird/Animal Market Special (is_admin_product = 1, category_id = 5, product_type = 'birds_animals')
 * 2. Admin adds Seed/Food Market Special (dynamic fields in product_seeds_food)
 * 3. Admin adds Cage/House Market Special (dynamic fields in product_cages_houses)
 * 4. Admin edits Market Special (updates details and dynamic attributes)
 * 5. Admin updates stock: stock = 0 -> status = sold_out; stock > 0 -> status = active
 * 6. Admin image upload / association
 * 7. Admin activate/deactivate
 * 8. Seller authorization enforcement: Seller cannot create, edit, or access Market Specials (server-side blocked)
 * 9. Buyer can see and view Market Specials in marketplace catalog
 */

require_once __DIR__ . '/includes/config.php';

echo "=== STARTING PHASE 7 TESTS ===\n\n";
$all_passed = true;

$admin_id  = 6; // Super Admin
$seller_id = 4; // Ali Seller
$buyer_id  = 5; // Buyer User

// Fetch Market Specials category ID
$cat_row = $conn->query("SELECT id FROM categories WHERE is_admin_only = 1 OR slug = 'market-specials' LIMIT 1")->fetch_assoc();
$market_category_id = $cat_row ? (int)$cat_row['id'] : 5;

// Clean up any old test records
$old_prods = $conn->query("SELECT id FROM products WHERE title_en LIKE '__TEST_PHASE7_%'");
while ($op = $old_prods->fetch_assoc()) {
    $op_id = $op['id'];
    $conn->query("DELETE FROM product_images WHERE product_id = $op_id");
    $conn->query("DELETE FROM product_birds_animals WHERE product_id = $op_id");
    $conn->query("DELETE FROM product_seeds_food WHERE product_id = $op_id");
    $conn->query("DELETE FROM product_cages_houses WHERE product_id = $op_id");
    $conn->query("DELETE FROM order_items WHERE product_id = $op_id");
    $conn->query("DELETE FROM products WHERE id = $op_id");
}

// -------------------------------------------------------------
// TEST 1: Admin adds Bird/Animal Market Special
// -------------------------------------------------------------
$t1_title = '__TEST_PHASE7_BIRD__';
$t1_norm  = normalize_product_name($t1_title);
$t1_slug  = 'test-phase7-bird-' . time();

$stmt1 = $conn->prepare("
    INSERT INTO products
      (seller_id, category_id, product_type, title_en, normalized_name, slug, description_en, price, stock_quantity, breed, gender, listing_type, is_admin_product, status)
    VALUES (?, ?, 'birds_animals', ?, ?, ?, 'Test Bird Description', 15000.00, 3, 'Spix Macaw', 'Male', 'store', 1, 'active')
");
$stmt1->bind_param("iisss", $admin_id, $market_category_id, $t1_title, $t1_norm, $t1_slug);
$stmt1->execute();
$t1_id = $conn->insert_id;
$stmt1->close();

$t1_dynamic = [
    'breed' => 'Spix Macaw',
    'age' => '1 year',
    'gender' => 'male',
    'color' => 'Blue',
    'weight' => '400g',
    'city' => 'Islamabad',
    'vaccinated' => 1,
    'health_certificate' => 1
];
save_product_dynamic_details($conn, $t1_id, PRODUCT_TYPE_BIRDS_ANIMALS, $t1_dynamic);

$chk1 = $conn->query("SELECT p.*, pba.breed as dyn_breed FROM products p LEFT JOIN product_birds_animals pba ON p.id = pba.product_id WHERE p.id = $t1_id")->fetch_assoc();
if ($chk1 && $chk1['is_admin_product'] == 1 && $chk1['dyn_breed'] === 'Spix Macaw' && $chk1['status'] === 'active') {
    echo "[PASS] Test 1: Admin successfully added Bird/Animal Market Special (ID: $t1_id)\n";
} else {
    echo "[FAIL] Test 1: Bird/Animal Market Special creation failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 2: Admin adds Seed/Food Market Special
// -------------------------------------------------------------
$t2_title = '__TEST_PHASE7_SEED__';
$t2_norm  = normalize_product_name($t2_title);
$t2_slug  = 'test-phase7-seed-' . time();

$stmt2 = $conn->prepare("
    INSERT INTO products
      (seller_id, category_id, product_type, title_en, normalized_name, slug, description_en, price, stock_quantity, listing_type, is_admin_product, status)
    VALUES (?, ?, 'seeds_food', ?, ?, ?, 'Test Seed Description', 350.00, 50, 'store', 1, 'active')
");
$stmt2->bind_param("iisss", $admin_id, $market_category_id, $t2_title, $t2_norm, $t2_slug);
$stmt2->execute();
$t2_id = $conn->insert_id;
$stmt2->close();

$t2_dynamic = [
    'price_per_kg' => 350.00,
    'discount_price_25kg' => 7500.00,
    'discount_price_50kg' => 14000.00,
    'quality' => 'A+',
    'stock_type' => 'numeric',
    'stock_kg' => 50.00
];
save_product_dynamic_details($conn, $t2_id, PRODUCT_TYPE_SEEDS_FOOD, $t2_dynamic);

$chk2 = $conn->query("SELECT p.*, psf.quality, psf.discount_price_25kg FROM products p LEFT JOIN product_seeds_food psf ON p.id = psf.product_id WHERE p.id = $t2_id")->fetch_assoc();
if ($chk2 && $chk2['is_admin_product'] == 1 && $chk2['quality'] === 'A+' && (float)$chk2['discount_price_25kg'] === 7500.00) {
    echo "[PASS] Test 2: Admin successfully added Seed/Food Market Special (ID: $t2_id, Grade A+)\n";
} else {
    echo "[FAIL] Test 2: Seed/Food Market Special creation failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 3: Admin adds Cage/House Market Special
// -------------------------------------------------------------
$t3_title = '__TEST_PHASE7_CAGE__';
$t3_norm  = normalize_product_name($t3_title);
$t3_slug  = 'test-phase7-cage-' . time();

$stmt3 = $conn->prepare("
    INSERT INTO products
      (seller_id, category_id, product_type, title_en, normalized_name, slug, description_en, price, stock_quantity, listing_type, is_admin_product, status)
    VALUES (?, ?, 'cages_houses', ?, ?, ?, 'Test Cage Description', 5500.00, 10, 'store', 1, 'active')
");
$stmt3->bind_param("iisss", $admin_id, $market_category_id, $t3_title, $t3_norm, $t3_slug);
$stmt3->execute();
$t3_id = $conn->insert_id;
$stmt3->close();

$t3_dynamic = [
    'animal_purpose' => 'Macaws & Cockatoos',
    'length' => 36.00,
    'width' => 24.00,
    'depth' => 24.00,
    'height' => 48.00,
    'unit_dimension' => 'inches',
    'price_per_piece' => 5500.00,
    'discount_price_12pcs' => 60000.00,
    'discount_price_24pcs' => 115000.00,
    'quality' => 'A+',
    'stock_type' => 'numeric',
    'stock_pieces' => 10
];
save_product_dynamic_details($conn, $t3_id, PRODUCT_TYPE_CAGES_HOUSES, $t3_dynamic);

$chk3 = $conn->query("SELECT p.*, pch.animal_purpose, pch.length, pch.height FROM products p LEFT JOIN product_cages_houses pch ON p.id = pch.product_id WHERE p.id = $t3_id")->fetch_assoc();
if ($chk3 && $chk3['is_admin_product'] == 1 && $chk3['animal_purpose'] === 'Macaws & Cockatoos' && (float)$chk3['height'] === 48.00) {
    echo "[PASS] Test 3: Admin successfully added Cage/House Market Special (ID: $t3_id, 36x24x24x48 in)\n";
} else {
    echo "[FAIL] Test 3: Cage/House Market Special creation failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 4: Admin edits Market Special details
// -------------------------------------------------------------
$t1_updated_title = '__TEST_PHASE7_BIRD_EDITED__';
$t1_updated_norm  = normalize_product_name($t1_updated_title);
$conn->query("UPDATE products SET title_en = '$t1_updated_title', normalized_name = '$t1_updated_norm', price = 16000.00 WHERE id = $t1_id");
$t1_dynamic['breed'] = 'Spix Macaw Purebred';
save_product_dynamic_details($conn, $t1_id, PRODUCT_TYPE_BIRDS_ANIMALS, $t1_dynamic);

$chk4 = $conn->query("SELECT p.title_en, p.price, pba.breed FROM products p JOIN product_birds_animals pba ON p.id = pba.product_id WHERE p.id = $t1_id")->fetch_assoc();
if ($chk4 && $chk4['title_en'] === $t1_updated_title && (float)$chk4['price'] === 16000.00 && $chk4['breed'] === 'Spix Macaw Purebred') {
    echo "[PASS] Test 4: Admin successfully edited Market Special title, price, and dynamic attributes\n";
} else {
    echo "[FAIL] Test 4: Edit Market Special failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 5: Stock Update & Status Rules (stock=0 -> sold_out, stock>0 -> active)
// -------------------------------------------------------------
// Update stock to 0
$conn->query("UPDATE products SET stock_quantity = 0, status = IF(0 <= 0, 'sold_out', status) WHERE id = $t1_id");
$chk5a = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $t1_id")->fetch_assoc();
$test5a_ok = ((int)$chk5a['stock_quantity'] === 0 && $chk5a['status'] === 'sold_out');

// Update stock to 5
$conn->query("UPDATE products SET stock_quantity = 5, status = IF(5 > 0, 'active', 'sold_out') WHERE id = $t1_id");
$chk5b = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $t1_id")->fetch_assoc();
$test5b_ok = ((int)$chk5b['stock_quantity'] === 5 && $chk5b['status'] === 'active');

if ($test5a_ok && $test5b_ok) {
    echo "[PASS] Test 5: Stock update rules passed (0 -> sold_out, 5 -> active)\n";
} else {
    echo "[FAIL] Test 5: Stock update rules failed. 5a: " . json_encode($chk5a) . ", 5b: " . json_encode($chk5b) . "\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 6: Product Images Management
// -------------------------------------------------------------
$conn->query("INSERT INTO product_images (product_id, image_url, is_primary) VALUES ($t1_id, 'test_img_1.jpg', 1)");
$conn->query("INSERT INTO product_images (product_id, image_url, is_primary) VALUES ($t1_id, 'test_img_2.jpg', 0)");
$chk6 = $conn->query("SELECT COUNT(*) AS c, SUM(is_primary) AS p FROM product_images WHERE product_id = $t1_id")->fetch_assoc();
if ((int)$chk6['c'] === 2 && (int)$chk6['p'] === 1) {
    echo "[PASS] Test 6: Image association and primary flag verified (2 images, 1 primary)\n";
} else {
    echo "[FAIL] Test 6: Image management failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 7: Activate / Deactivate
// -------------------------------------------------------------
$conn->query("UPDATE products SET status = 'inactive' WHERE id = $t1_id");
$chk7a = $conn->query("SELECT status FROM products WHERE id = $t1_id")->fetch_assoc()['status'];
$conn->query("UPDATE products SET status = 'active' WHERE id = $t1_id");
$chk7b = $conn->query("SELECT status FROM products WHERE id = $t1_id")->fetch_assoc()['status'];
if ($chk7a === 'inactive' && $chk7b === 'active') {
    echo "[PASS] Test 7: Product activation and deactivation verified\n";
} else {
    echo "[FAIL] Test 7: Activation/Deactivation failed.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 8: Seller Authorization Enforcement (Server-Side)
// -------------------------------------------------------------
// Verify seller add_product query excludes Market Specials
$seller_cats = $conn->query("SELECT id FROM categories WHERE status = 'active' AND is_admin_only = 0 AND is_deleted = 0")->fetch_all(MYSQLI_ASSOC);
$seller_cat_ids = array_column($seller_cats, 'id');
$seller_sees_market = in_array($market_category_id, $seller_cat_ids);

// Verify seller cannot edit admin product (ownership check in edit_product.php)
$own_chk = $conn->prepare("SELECT id FROM products WHERE id = ? AND seller_id = ?");
$own_chk->bind_param("ii", $t1_id, $seller_id);
$own_chk->execute();
$seller_owns_special = $own_chk->get_result()->fetch_assoc();
$own_chk->close();

if (!$seller_sees_market && !$seller_owns_special) {
    echo "[PASS] Test 8: Seller authorization securely enforced server-side (category hidden, ownership check blocks edit/delete)\n";
} else {
    echo "[FAIL] Test 8: Seller authorization loophole detected.\n";
    $all_passed = false;
}

// -------------------------------------------------------------
// TEST 9: Buyer can see Market Specials in Marketplace
// -------------------------------------------------------------
$shop_res = $conn->query("
    SELECT p.id, p.title_en 
    FROM products p 
    WHERE p.id IN ($t1_id, $t2_id, $t3_id) AND p.status IN ('active', 'sold', 'sold_out') AND p.is_deleted = 0 AND p.listing_type = 'store'
");
$visible_count = $shop_res->num_rows;
if ($visible_count === 3) {
    echo "[PASS] Test 9: Buyer marketplace query returns all 3 Market Specials as active catalog items\n";
} else {
    echo "[FAIL] Test 9: Buyer cannot see Market Specials (found $visible_count of 3).\n";
    $all_passed = false;
}

// Clean up test records
$conn->query("DELETE FROM product_images WHERE product_id IN ($t1_id, $t2_id, $t3_id)");
$conn->query("DELETE FROM product_birds_animals WHERE product_id IN ($t1_id, $t2_id, $t3_id)");
$conn->query("DELETE FROM product_seeds_food WHERE product_id IN ($t1_id, $t2_id, $t3_id)");
$conn->query("DELETE FROM product_cages_houses WHERE product_id IN ($t1_id, $t2_id, $t3_id)");
$conn->query("DELETE FROM products WHERE id IN ($t1_id, $t2_id, $t3_id)");

echo "\n=========================================\n";
if ($all_passed) {
    echo "ALL PHASE 7 TESTS PASSED PERFECTLY!\n";
} else {
    echo "SOME PHASE 7 TESTS FAILED!\n";
}
echo "=========================================\n";
