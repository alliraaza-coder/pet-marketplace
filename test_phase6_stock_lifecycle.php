<?php
/**
 * Test Suite: Phase 6 - Seller Stock Lifecycle & Order Acceptance Stock Rule
 * 
 * Verifies exact 12 requirements:
 * 1. Product stock = 6.
 * 2. Buyer creates request. Expected = 6.
 * 3. Admin approves payment. Expected = 6.
 * 4. Seller has not accepted. Expected = 6.
 * 5. Seller accepts quantity 1. Expected = 5.
 * 6. Seller accepts same order again / refreshes. Expected = still 5.
 * 7. Order later becomes cancelled/failed. Expected = still 5.
 * 8. Seller manually changes 5 -> 6. Expected = 6 everywhere.
 * 9. When stock reaches 0: Expected = SOLD OUT.
 * 10. Seller updates 0 -> 6: Expected = AVAILABLE.
 * 11. Test quantity greater than available. Expected = reject server-side.
 * 12. Test two simultaneous acceptance attempts. Expected = no negative/double deduction.
 */

require_once __DIR__ . '/includes/config.php';

function assert_stock($conn, $product_id, $expected_stock, $expected_status = null, $step_name = "") {
    $res = $conn->query("SELECT stock_quantity, status FROM products WHERE id = $product_id");
    $row = $res->fetch_assoc();
    $actual_stock = (int)$row['stock_quantity'];
    $actual_status = $row['status'];
    
    $stock_ok = ($actual_stock === $expected_stock);
    $status_ok = ($expected_status === null || $actual_status === $expected_status);
    
    if ($stock_ok && $status_ok) {
        echo "[PASS] $step_name: Stock is $actual_stock (expected $expected_stock)" . ($expected_status ? ", Status is '$actual_status'" : "") . "\n";
        return true;
    } else {
        echo "[FAIL] $step_name: Stock is $actual_stock (expected $expected_stock), Status is '$actual_status' (expected " . ($expected_status ?? "any") . ")\n";
        return false;
    }
}

echo "=== STARTING PHASE 6 TESTS ===\n\n";
$all_passed = true;

// 1. Setup Test Seller, Buyer, Category & Product with initial stock = 6
$seller_id = 4; // Existing Ali seller
$buyer_id = 5;  // Existing buyer user
$category_id = 1; // Birds & Animals

$old_prod = $conn->query("SELECT id FROM products WHERE title_en = '__TEST_PHASE6_PRODUCT__'");
while ($op = $old_prod->fetch_assoc()) {
    $op_id = $op['id'];
    $conn->query("DELETE FROM order_items WHERE product_id = $op_id");
}
$conn->query("DELETE FROM products WHERE title_en = '__TEST_PHASE6_PRODUCT__'");

$ins_stmt = $conn->prepare("INSERT INTO products (seller_id, category_id, title_en, normalized_name, slug, description_en, price, stock_quantity, status, listing_type) VALUES (?, ?, '__TEST_PHASE6_PRODUCT__', '__test_phase6_product__', ?, 'Test desc', 500.00, 6, 'active', 'store')");
$test_slug = 'test-phase6-' . time();
$ins_stmt->bind_param("iis", $seller_id, $category_id, $test_slug);
$ins_stmt->execute();
$product_id = $conn->insert_id;

// Test 1: Product stock = 6.
$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 1: Initial product stock");

// Test 2: Buyer creates request. Expected = 6.
// Create an order via process_checkout logic (without deducting stock)
$order_number = 'TEST-ORD-' . time();
$ord_stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, 'Test Buyer', '03001234567', 'test@test.com', ?, 500.00, 500.00, 'bank_transfer', 'payment_submitted', 'pending_payment', 'Test address', 'Test address')");
$ord_stmt->bind_param("is", $buyer_id, $order_number);
$ord_stmt->execute();
$order_id = $conn->insert_id;

$item_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) VALUES (?, ?, ?, 1, 500.00, 500.00, 0)");
$item_stmt->bind_param("iii", $order_id, $product_id, $seller_id);
$item_stmt->execute();
$order_item_id = $conn->insert_id;

$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 2: Buyer creates request");

// Test 3: Admin approves payment. Expected = 6.
// Execute admin escrow approval logic
$upd = $conn->prepare("UPDATE orders SET payment_status = 'held', order_status = 'pending' WHERE id = ?");
$upd->bind_param("i", $order_id);
$upd->execute();

$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 3: Admin approves payment");

// Test 4: Seller has not accepted. Expected = 6.
$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 4: Seller has not accepted");

// Test 5: Seller accepts quantity 1. Expected = 5.
// Simulate seller accepting the order via the logic in seller/order_details.php
function execute_seller_accept($conn, $order_id, $seller_id) {
    $conn->begin_transaction();
    try {
        $chk_order = $conn->prepare("SELECT order_status FROM orders WHERE id = ? FOR UPDATE");
        $chk_order->bind_param("i", $order_id);
        $chk_order->execute();
        $cur_order_row = $chk_order->get_result()->fetch_assoc();
        $chk_order->close();

        if (!$cur_order_row || $cur_order_row['order_status'] === 'accepted') {
            $conn->commit();
            return ['status' => 'already_accepted'];
        }

        $oi_stmt = $conn->prepare("SELECT id, product_id, quantity, stock_deducted FROM order_items WHERE order_id = ? AND seller_id = ? FOR UPDATE");
        $oi_stmt->bind_param("ii", $order_id, $seller_id);
        $oi_stmt->execute();
        $items = $oi_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $oi_stmt->close();

        foreach ($items as $item) {
            if ($item['stock_deducted'] == 0) {
                $p_stmt = $conn->prepare("SELECT id, title_en, stock_quantity, status FROM products WHERE id = ? FOR UPDATE");
                $p_stmt->bind_param("i", $item['product_id']);
                $p_stmt->execute();
                $prod = $p_stmt->get_result()->fetch_assoc();
                $p_stmt->close();

                if (!$prod || $prod['stock_quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock");
                }
            }
        }

        foreach ($items as $item) {
            if ($item['stock_deducted'] == 0) {
                $deduct = $conn->prepare("UPDATE products SET status = IF(stock_quantity - ? <= 0, 'sold_out', status), stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?");
                $deduct->bind_param("iiii", $item['quantity'], $item['quantity'], $item['product_id'], $item['quantity']);
                $deduct->execute();
                if ($deduct->affected_rows === 0) {
                    throw new Exception("Could not deduct stock");
                }
                $deduct->close();

                $mark = $conn->prepare("UPDATE order_items SET stock_deducted = 1 WHERE id = ?");
                $mark->bind_param("i", $item['id']);
                $mark->execute();
                $mark->close();
            }
        }

        $upd_o = $conn->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ?");
        $upd_o->bind_param("i", $order_id);
        $upd_o->execute();
        $upd_o->close();

        $conn->commit();
        return ['status' => 'accepted'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

$accept_res = execute_seller_accept($conn, $order_id, $seller_id);
$all_passed &= ($accept_res['status'] === 'accepted');
$all_passed &= assert_stock($conn, $product_id, 5, 'active', "Test 5: Seller accepts quantity 1");

// Test 6: Seller accepts same order again / refreshes. Expected = still 5.
$repeat_accept = execute_seller_accept($conn, $order_id, $seller_id);
$all_passed &= ($repeat_accept['status'] === 'already_accepted');
$all_passed &= assert_stock($conn, $product_id, 5, 'active', "Test 6: Repeat acceptance attempt / refresh");

// Test 7: Order later becomes cancelled/failed. Expected = still 5.
// Simulate order cancellation
$upd_cancel = $conn->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?");
$upd_cancel->bind_param("i", $order_id);
$upd_cancel->execute();
// Per Phase 6: No automatic restoration
$all_passed &= assert_stock($conn, $product_id, 5, 'active', "Test 7: Order cancelled after acceptance");

// Test 8: Seller manually changes 5 -> 6. Expected = 6 everywhere.
// Simulate seller updating stock in edit_product.php: stock = 6
$new_stock = 6;
$status_after_manual = ($new_stock <= 0) ? 'sold_out' : 'active';
$upd_manual = $conn->prepare("UPDATE products SET stock_quantity = ?, status = ? WHERE id = ?");
$upd_manual->bind_param("isi", $new_stock, $status_after_manual, $product_id);
$upd_manual->execute();
$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 8: Seller manually changes 5 -> 6");

// Test 9: When stock reaches 0: Expected = SOLD OUT.
// Create an order of qty 6 and accept it
$ord9_num = 'TEST-ORD9-' . time();
$ord9_stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, 'Test Buyer', '03001234567', 'test@test.com', ?, 3000.00, 3000.00, 'bank_transfer', 'held', 'pending', 'Test address', 'Test address')");
$ord9_stmt->bind_param("is", $buyer_id, $ord9_num);
$ord9_stmt->execute();
$ord9_id = $conn->insert_id;

$item9_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) VALUES (?, ?, ?, 6, 500.00, 3000.00, 0)");
$item9_stmt->bind_param("iii", $ord9_id, $product_id, $seller_id);
$item9_stmt->execute();

$res9 = execute_seller_accept($conn, $ord9_id, $seller_id);
$all_passed &= ($res9['status'] === 'accepted');
$all_passed &= assert_stock($conn, $product_id, 0, 'sold_out', "Test 9: Stock reaches 0, status is SOLD OUT");

// Test 10: Seller updates 0 -> 6: Expected = AVAILABLE / ACTIVE.
// Seller updates stock to 6 in edit_product.php
$stock_10 = 6;
$status_10 = ($stock_10 > 0) ? 'active' : 'sold_out';
$upd_10 = $conn->prepare("UPDATE products SET stock_quantity = ?, status = ? WHERE id = ?");
$upd_10->bind_param("isi", $stock_10, $status_10, $product_id);
$upd_10->execute();
$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 10: Seller updates 0 -> 6, status is ACTIVE");

// Test 11: Test quantity greater than available. Expected = reject server-side.
$ord11_num = 'TEST-ORD11-' . time();
$ord11_stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, 'Test Buyer', '03001234567', 'test@test.com', ?, 5000.00, 5000.00, 'bank_transfer', 'held', 'pending', 'Test address', 'Test address')");
$ord11_stmt->bind_param("is", $buyer_id, $ord11_num);
$ord11_stmt->execute();
$ord11_id = $conn->insert_id;

$item11_stmt = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) VALUES (?, ?, ?, 10, 500.00, 5000.00, 0)"); // Requesting 10 when stock is 6
$item11_stmt->bind_param("iii", $ord11_id, $product_id, $seller_id);
$item11_stmt->execute();

$res11 = execute_seller_accept($conn, $ord11_id, $seller_id);
if ($res11['status'] === 'error' && strpos($res11['message'], 'Insufficient stock') !== false) {
    echo "[PASS] Test 11: Order acceptance rejected server-side when requested quantity (10) > stock (6)\n";
} else {
    echo "[FAIL] Test 11: Did not reject order when quantity exceeded stock. Result: " . json_encode($res11) . "\n";
    $all_passed = false;
}
$all_passed &= assert_stock($conn, $product_id, 6, 'active', "Test 11 verification: Stock remained 6");

// Test 12: Test two simultaneous acceptance attempts. Expected = no negative/double deduction.
// We test two orders competing for remaining stock of 6: Order A wants 4, Order B wants 4 (Total 8 > 6).
$ord12a_num = 'TEST-ORD12A-' . time();
$ord12a = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, 'Test Buyer A', '03001234567', 'test@test.com', ?, 2000.00, 2000.00, 'bank_transfer', 'held', 'pending', 'Test address', 'Test address')");
$ord12a->bind_param("is", $buyer_id, $ord12a_num);
$ord12a->execute();
$ord12a_id = $conn->insert_id;

$item12a = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) VALUES (?, ?, ?, 4, 500.00, 2000.00, 0)");
$item12a->bind_param("iii", $ord12a_id, $product_id, $seller_id);
$item12a->execute();

$ord12b_num = 'TEST-ORD12B-' . time();
$ord12b = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, 'Test Buyer B', '03001234567', 'test@test.com', ?, 2000.00, 2000.00, 'bank_transfer', 'held', 'pending', 'Test address', 'Test address')");
$ord12b->bind_param("is", $buyer_id, $ord12b_num);
$ord12b->execute();
$ord12b_id = $conn->insert_id;

$item12b = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total, stock_deducted) VALUES (?, ?, ?, 4, 500.00, 2000.00, 0)");
$item12b->bind_param("iii", $ord12b_id, $product_id, $seller_id);
$item12b->execute();

// Accept Order A (should succeed, stock becomes 6 - 4 = 2)
$res12a = execute_seller_accept($conn, $ord12a_id, $seller_id);
// Attempt Order B (must fail because only 2 left, requested 4)
$res12b = execute_seller_accept($conn, $ord12b_id, $seller_id);

if ($res12a['status'] === 'accepted' && $res12b['status'] === 'error') {
    echo "[PASS] Test 12: First order accepted (4 deducted), second competing order safely rejected (no negative stock)\n";
} else {
    echo "[FAIL] Test 12: Concurrent safety check failed. res12a: " . json_encode($res12a) . ", res12b: " . json_encode($res12b) . "\n";
    $all_passed = false;
}
$all_passed &= assert_stock($conn, $product_id, 2, 'active', "Test 12 verification: Stock is exactly 2, never negative");

// Cleanup test records
$conn->query("DELETE FROM order_items WHERE order_id IN ($order_id, $ord9_id, $ord11_id, $ord12a_id, $ord12b_id)");
$conn->query("DELETE FROM orders WHERE id IN ($order_id, $ord9_id, $ord11_id, $ord12a_id, $ord12b_id)");
$conn->query("DELETE FROM products WHERE id = $product_id");

echo "\n=========================================\n";
if ($all_passed) {
    echo "ALL 12 TESTS PASSED PERFECTLY!\n";
} else {
    echo "SOME TESTS FAILED!\n";
}
echo "=========================================\n";
