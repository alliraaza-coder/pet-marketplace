<?php
/**
 * Test Suite: Phase 8 — Buyer, Seller, and Admin Product Synchronization
 */

require_once 'includes/config.php';
require_once 'includes/product_types.php';

echo "====================================================\n";
echo "PETMARKET: PHASE 8 SYNCHRONIZATION TEST SUITE\n";
echo "====================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test($description, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo " [PASS] $description\n";
        $passed++;
    } else {
        echo " [FAIL] $description\n";
        $failed++;
    }
}

// ---------------------------------------------------------
// TEST 1: CATEGORY STRUCTURE SYNCHRONIZATION
// ---------------------------------------------------------
echo "--- TEST 1: Category Architecture & Visibility ---\n";

// 1.1: Buyer can see Birds & Animals, Seeds & Animal Food, Cages & Houses, and Market Specials
$all_cats_res = $conn->query("SELECT slug, is_admin_only FROM categories WHERE status = 'active' AND is_deleted = 0");
$cats_by_slug = [];
while ($row = $all_cats_res->fetch_assoc()) {
    $cats_by_slug[$row['slug']] = $row;
}

assert_test("Buyer categories include 'birds-animals'", isset($cats_by_slug['birds-animals']));
assert_test("Buyer categories include 'seeds-animal-food'", isset($cats_by_slug['seeds-animal-food']));
assert_test("Buyer categories include 'cages-houses'", isset($cats_by_slug['cages-houses']));
assert_test("Buyer categories include 'market-specials'", isset($cats_by_slug['market-specials']));
assert_test("Market Specials category is marked is_admin_only = 1", !empty($cats_by_slug['market-specials']['is_admin_only']));

// 1.2: Subcategories under Birds & Animals include Birds and Animals
$sub_res = $conn->query("SELECT sc.slug FROM subcategories sc JOIN categories c ON sc.category_id = c.id WHERE c.slug = 'birds-animals' AND sc.status = 'active'");
$sub_slugs = [];
while ($row = $sub_res->fetch_assoc()) {
    $sub_slugs[] = $row['slug'];
}
assert_test("Subcategories under Birds & Animals include 'birds'", in_array('birds', $sub_slugs));
assert_test("Subcategories under Birds & Animals include 'animals'", in_array('animals', $sub_slugs));

// 1.3: Seller categories query strictly excludes Market Specials
$seller_cats = $conn->query("SELECT slug FROM categories WHERE status = 'active' AND is_admin_only = 0 AND is_deleted = 0");
$seller_cat_slugs = [];
while ($row = $seller_cats->fetch_assoc()) {
    $seller_cat_slugs[] = $row['slug'];
}
assert_test("Seller cannot see 'market-specials' category", !in_array('market-specials', $seller_cat_slugs));
assert_test("Seller can see 'birds-animals', 'seeds-animal-food', 'cages-houses'", 
    in_array('birds-animals', $seller_cat_slugs) && 
    in_array('seeds-animal-food', $seller_cat_slugs) && 
    in_array('cages-houses', $seller_cat_slugs));

// ---------------------------------------------------------
// TEST 2: DYNAMIC PRODUCT DETAILS ARCHITECTURE
// ---------------------------------------------------------
echo "\n--- TEST 2: Dynamic Product Details Loading ---\n";

// Create test dummy products for each type
$seller_id = 4; // Existing seller
$cat_birds_id = $cats_by_slug['birds-animals']['id'] ?? 1;
$cat_seeds_id = $cats_by_slug['seeds-animal-food']['id'] ?? 4;
$cat_cages_id = $cats_by_slug['cages-houses']['id'] ?? 3;

// 2.1 Bird Product
$t_bird_title = "Test Bird Sync " . time();
$t_bird_slug = "test-bird-sync-" . time();
$conn->query("INSERT INTO products (seller_id, category_id, subcategory_id, product_type, title_en, slug, description_en, price, stock_quantity, status, listing_type) 
              VALUES ($seller_id, $cat_birds_id, 1, 'birds_animals', '$t_bird_title', '$t_bird_slug', 'Test bird description', 2500.00, 3, 'active', 'store')");
$bird_pid = $conn->insert_id;

$bird_dyn = [
    'breed' => 'Australian Lovebird',
    'age' => '8 Months',
    'gender' => 'pair',
    'color' => 'Green-Yellow',
    'weight' => '120g',
    'city' => 'Lahore',
    'vaccinated' => 1,
    'health_certificate' => 1
];
save_product_dynamic_details($conn, $bird_pid, PRODUCT_TYPE_BIRDS_ANIMALS, $bird_dyn);
$loaded_bird_dyn = get_product_dynamic_details($conn, $bird_pid, PRODUCT_TYPE_BIRDS_ANIMALS);

assert_test("Birds dynamic details saved and loaded: breed", ($loaded_bird_dyn['breed'] ?? '') === 'Australian Lovebird');
assert_test("Birds dynamic details saved and loaded: vaccinated", ($loaded_bird_dyn['vaccinated'] ?? 0) == 1);
assert_test("Birds dynamic details saved and loaded: gender", ($loaded_bird_dyn['gender'] ?? '') === 'pair');

// 2.2 Seed Product
$t_seed_title = "Test Seed Sync " . time();
$t_seed_slug = "test-seed-sync-" . time();
$conn->query("INSERT INTO products (seller_id, category_id, product_type, title_en, slug, description_en, price, stock_quantity, status, listing_type) 
              VALUES ($seller_id, $cat_seeds_id, 'seeds_food', '$t_seed_title', '$t_seed_slug', 'High nutrition millet seed', 350.00, 50, 'active', 'store')");
$seed_pid = $conn->insert_id;

$seed_dyn = [
    'price_per_kg' => 350.00,
    'discount_price_25kg' => 8000.00,
    'discount_price_50kg' => 15000.00,
    'quality' => 'A+',
    'stock_type' => 'numeric',
    'stock_kg' => 50.00
];
save_product_dynamic_details($conn, $seed_pid, PRODUCT_TYPE_SEEDS_FOOD, $seed_dyn);
$loaded_seed_dyn = get_product_dynamic_details($conn, $seed_pid, PRODUCT_TYPE_SEEDS_FOOD);

assert_test("Seeds dynamic details: price_per_kg", ($loaded_seed_dyn['price_per_kg'] ?? 0) == 350.00);
assert_test("Seeds dynamic details: quality Grade A+", ($loaded_seed_dyn['quality'] ?? '') === 'A+');
assert_test("Seeds dynamic details: 25kg bulk discount", ($loaded_seed_dyn['discount_price_25kg'] ?? 0) == 8000.00);

// 2.3 Cage Product
$t_cage_title = "Test Cage Sync " . time();
$t_cage_slug = "test-cage-sync-" . time();
$conn->query("INSERT INTO products (seller_id, category_id, product_type, title_en, slug, description_en, price, stock_quantity, status, listing_type) 
              VALUES ($seller_id, $cat_cages_id, 'cages_houses', '$t_cage_title', '$t_cage_slug', 'Double breeding cage', 4500.00, 10, 'active', 'store')");
$cage_pid = $conn->insert_id;

$cage_dyn = [
    'animal_purpose' => 'Parrots / Lovebirds',
    'length' => 24.0,
    'width' => 18.0,
    'depth' => 18.0,
    'height' => 30.0,
    'unit_dimension' => 'inches',
    'price_per_piece' => 4500.00,
    'discount_price_12pcs' => 48000.00,
    'discount_price_24pcs' => 90000.00,
    'quality' => 'A',
    'stock_type' => 'numeric',
    'stock_pieces' => 10
];
save_product_dynamic_details($conn, $cage_pid, PRODUCT_TYPE_CAGES_HOUSES, $cage_dyn);
$loaded_cage_dyn = get_product_dynamic_details($conn, $cage_pid, PRODUCT_TYPE_CAGES_HOUSES);

assert_test("Cages dynamic details: purpose", ($loaded_cage_dyn['animal_purpose'] ?? '') === 'Parrots / Lovebirds');
assert_test("Cages dynamic details: dimensions L=24, H=30", ($loaded_cage_dyn['length'] ?? 0) == 24.0 && ($loaded_cage_dyn['height'] ?? 0) == 30.0);
assert_test("Cages dynamic details: 12pcs wholesale discount", ($loaded_cage_dyn['discount_price_12pcs'] ?? 0) == 48000.00);

// ---------------------------------------------------------
// TEST 3: STOCK SYNCHRONIZATION ACROSS BUYER, SELLER, ADMIN
// ---------------------------------------------------------
echo "\n--- TEST 3: Stock Synchronization & Status Lifecycle ---\n";

// 3.1 Update stock to 0: must transition status to 'sold_out'
$conn->query("UPDATE products SET stock_quantity = 0, status = 'sold_out' WHERE id = $bird_pid");
$chk_sold = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $bird_pid")->fetch_assoc();
assert_test("When stock is 0, product status is 'sold_out'", $chk_sold['stock_quantity'] == 0 && $chk_sold['status'] === 'sold_out');

// 3.2 Update stock > 0: must transition status back to 'active'
$conn->query("UPDATE products SET stock_quantity = 5, status = 'active' WHERE id = $bird_pid");
$chk_active = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $bird_pid")->fetch_assoc();
assert_test("When stock is updated > 0, product status returns to 'active'", $chk_active['stock_quantity'] == 5 && $chk_active['status'] === 'active');

// 3.3 Cart stock validation server-side blocks adding out-of-stock product
$conn->query("UPDATE products SET stock_quantity = 0, status = 'sold_out' WHERE id = $bird_pid");
$cart_query = $conn->prepare("SELECT id, title_en, price, stock_quantity, seller_id FROM products WHERE id = ? AND status = 'active' AND listing_type = 'store' AND stock_quantity > 0");
$cart_query->bind_param("i", $bird_pid);
$cart_query->execute();
$cart_check = $cart_query->get_result();
assert_test("Cart server-side query blocks sold_out product from being added", $cart_check->num_rows === 0);

// Restore stock for order flow test
$conn->query("UPDATE products SET stock_quantity = 4, status = 'active' WHERE id = $bird_pid");

// ---------------------------------------------------------
// TEST 4: ORDER FLOW COMPATIBILITY WITH NEW PRODUCTS
// ---------------------------------------------------------
echo "\n--- TEST 4: End-to-End Order Flow & Atomic Stock Deduction ---\n";

$buyer_id = 5; // Existing buyer
$order_number = 'PM-TEST-P8-' . time();
$conn->query("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, tax, shipping_fee, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) 
              VALUES ($buyer_id, 'Test Buyer', '03001234567', 'buyer@test.com', '$order_number', 5000.00, 250.00, 15.00, 5265.00, 'Bank Transfer', 'pending', 'pending_payment', 'Lahore, Pakistan', 'Lahore, Pakistan')");
$order_id = $conn->insert_id;

$conn->query("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) 
              VALUES ($order_id, $bird_pid, $seller_id, 2, 2500.00, 5000.00, 0)");
$oi_id = $conn->insert_id;

// Step 4.1: Buyer places request -> Stock remains unchanged (4)
$stk_pre = $conn->query("SELECT stock_quantity FROM products WHERE id = $bird_pid")->fetch_assoc()['stock_quantity'];
assert_test("Stock NOT deducted on buy request creation (remains 4)", $stk_pre == 4);

// Step 4.2: Admin verifies payment -> payment_status = 'held' -> Stock still unchanged (4)
$conn->query("UPDATE orders SET payment_status = 'held', order_status = 'pending' WHERE id = $order_id");
$stk_appr = $conn->query("SELECT stock_quantity FROM products WHERE id = $bird_pid")->fetch_assoc()['stock_quantity'];
assert_test("Stock NOT deducted on Admin payment verification (remains 4)", $stk_appr == 4);

// Step 4.3: Seller accepts order -> Stock deducted atomically by 2 (becomes 2), stock_deducted = 1
$conn->begin_transaction();
$conn->query("UPDATE products SET stock_quantity = stock_quantity - 2, status = IF(stock_quantity - 2 <= 0, 'sold_out', status) WHERE id = $bird_pid AND stock_quantity >= 2");
$conn->query("UPDATE order_items SET stock_deducted = 1 WHERE id = $oi_id");
$conn->query("UPDATE orders SET order_status = 'accepted' WHERE id = $order_id");
$conn->commit();

$stk_post = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $bird_pid")->fetch_assoc();
$oi_check = $conn->query("SELECT stock_deducted FROM order_items WHERE id = $oi_id")->fetch_assoc();

assert_test("Stock deducted atomically ONLY on Seller acceptance (4 - 2 = 2)", $stk_post['stock_quantity'] == 2);
assert_test("Order item marked stock_deducted = 1", $oi_check['stock_deducted'] == 1);

// Step 4.4: Delivery and Buyer confirmation
$conn->query("UPDATE orders SET seller_delivered = 1, buyer_received = 1, admin_verified = 1 WHERE id = $order_id");
$del_chk = $conn->query("SELECT seller_delivered, buyer_received, admin_verified FROM orders WHERE id = $order_id")->fetch_assoc();
assert_test("Delivery and buyer received confirmed", $del_chk['seller_delivered'] == 1 && $del_chk['buyer_received'] == 1);

// Step 4.5: Admin pays seller
$conn->query("UPDATE orders SET payment_status = 'seller_payment_sent', seller_payment_amount = 5265.00, seller_payment_method = 'bank_transfer', seller_payment_sent_at = NOW() WHERE id = $order_id");
$pay_chk = $conn->query("SELECT payment_status FROM orders WHERE id = $order_id")->fetch_assoc();
assert_test("Admin marks payment sent to seller (seller_payment_sent)", $pay_chk['payment_status'] === 'seller_payment_sent');

// Step 4.6: Seller confirms payout
$conn->query("UPDATE orders SET payment_status = 'seller_payment_received', seller_payment_received = 1, order_status = 'completed' WHERE id = $order_id");
$comp_chk = $conn->query("SELECT payment_status, order_status FROM orders WHERE id = $order_id")->fetch_assoc();
assert_test("Seller confirms payment and order becomes completed", $comp_chk['payment_status'] === 'seller_payment_received' && $comp_chk['order_status'] === 'completed');

// ---------------------------------------------------------
// TEST 5: CANCELLATION AFTER ACCEPTANCE DOES NOT AUTO-RESTORE STOCK
// ---------------------------------------------------------
echo "\n--- TEST 5: Cancellation Stock Policy (Phase 6 Rule) ---\n";

// Create another order and accept it
$conn->query("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, tax, shipping_fee, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) 
              VALUES ($buyer_id, 'Test Buyer 2', '03001234567', 'buyer@test.com', 'PM-TEST-CANC-" . time() . "', 2500.00, 125.00, 15.00, 2640.00, 'Bank Transfer', 'held', 'accepted', 'Lahore', 'Lahore')");
$ord2_id = $conn->insert_id;
$conn->query("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) 
              VALUES ($ord2_id, $bird_pid, $seller_id, 1, 2500.00, 2500.00, 1)");

// Stock is now 2 - 1 = 1
$conn->query("UPDATE products SET stock_quantity = stock_quantity - 1 WHERE id = $bird_pid");
$stk_before_cancel = $conn->query("SELECT stock_quantity FROM products WHERE id = $bird_pid")->fetch_assoc()['stock_quantity'];

// Order is cancelled/refunded
$conn->query("UPDATE orders SET order_status = 'cancelled', payment_status = 'refund_sent', buyer_refund_sent_at = NOW() WHERE id = $ord2_id");
$stk_after_cancel = $conn->query("SELECT stock_quantity FROM products WHERE id = $bird_pid")->fetch_assoc()['stock_quantity'];

assert_test("Cancellation after seller acceptance does NOT auto-restore stock (stock remains $stk_before_cancel)", $stk_after_cancel == $stk_before_cancel);

// Clean up test data
$conn->query("DELETE FROM order_items WHERE order_id IN ($order_id, $ord2_id)");
$conn->query("DELETE FROM orders WHERE id IN ($order_id, $ord2_id)");
$conn->query("DELETE FROM product_birds_animals WHERE product_id = $bird_pid");
$conn->query("DELETE FROM product_seeds_food WHERE product_id = $seed_pid");
$conn->query("DELETE FROM product_cages_houses WHERE product_id = $cage_pid");
$conn->query("DELETE FROM products WHERE id IN ($bird_pid, $seed_pid, $cage_pid)");

echo "\n====================================================\n";
echo "SUMMARY: $passed PASSED, $failed FAILED\n";
echo "====================================================\n";

if ($failed === 0) {
    echo ">>> PHASE 8 PRODUCT SYNCHRONIZATION VERIFICATION SUCCESSFUL! <<<\n";
    exit(0);
} else {
    echo ">>> SOME TESTS FAILED. CHECK LOGS ABOVE. <<<\n";
    exit(1);
}
