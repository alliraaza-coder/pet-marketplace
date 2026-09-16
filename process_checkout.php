<?php
/**
 * Checkout Processing Script
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

// 1. Security Check: Validate Login
if (!is_logged_in()) {
    $_SESSION['error'] = "You must be logged in to place an order.";
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. Security Check: Validate Cart
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    $_SESSION['error'] = "Your cart is empty. Please add items to your cart before checking out.";
    header('Location: cart.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}

// 3. Inputs Sanitization
$shipping_first_name = sanitize_input($_POST['shipping_first_name'] ?? '');
$shipping_last_name  = sanitize_input($_POST['shipping_last_name'] ?? '');
$shipping_phone      = sanitize_input($_POST['shipping_phone'] ?? '');
$shipping_email      = sanitize_input($_POST['shipping_email'] ?? '');
$shipping_address    = sanitize_input($_POST['shipping_address'] ?? '');
$shipping_city       = sanitize_input($_POST['shipping_city'] ?? '');
$shipping_state      = sanitize_input($_POST['shipping_state'] ?? '');
$shipping_zip_code   = sanitize_input($_POST['shipping_zip_code'] ?? '');
$shipping_country    = sanitize_input($_POST['shipping_country'] ?? '');
$payment_method      = sanitize_input($_POST['payment_method'] ?? 'Bank Transfer');

// Validate Required Fields
if (empty($shipping_first_name) || empty($shipping_last_name) || empty($shipping_phone) || 
    empty($shipping_email) || empty($shipping_address) || empty($shipping_city) || 
    empty($shipping_state) || empty($shipping_zip_code) || empty($shipping_country)) {
    $_SESSION['error'] = "Please fill in all required shipping fields.";
    header('Location: checkout.php');
    exit;
}

// Assemble full shipping contact name & address string
$shipping_name = $shipping_first_name . ' ' . $shipping_last_name;
$full_shipping_address = $shipping_address . ', ' . $shipping_city . ', ' . $shipping_state . ' ' . $shipping_zip_code . ', ' . $shipping_country;

// Handle Billing Address
$same_as_shipping = isset($_POST['same_as_shipping']) && $_POST['same_as_shipping'] == '1';
if ($same_as_shipping) {
    $full_billing_address = $full_shipping_address;
} else {
    $billing_address  = sanitize_input($_POST['billing_address'] ?? '');
    $billing_city     = sanitize_input($_POST['billing_city'] ?? '');
    $billing_state    = sanitize_input($_POST['billing_state'] ?? '');
    $billing_zip_code = sanitize_input($_POST['billing_zip_code'] ?? '');
    $billing_country  = sanitize_input($_POST['billing_country'] ?? '');
    
    if (empty($billing_address) || empty($billing_city) || empty($billing_state) || empty($billing_zip_code) || empty($billing_country)) {
        $_SESSION['error'] = "Please fill in all required billing fields.";
        header('Location: checkout.php');
        exit;
    }
    $full_billing_address = $billing_address . ', ' . $billing_city . ', ' . $billing_state . ' ' . $billing_zip_code . ', ' . $billing_country;
}

// Validate Payment Method Choice
$valid_payment_methods = ['Bank Transfer', 'JazzCash', 'EasyPaisa'];
if (!in_array($payment_method, $valid_payment_methods)) {
    $_SESSION['error'] = "Invalid payment method selected.";
    header('Location: checkout.php');
    exit;
}

// 4. Cart calculations
$cart_items = $_SESSION['cart'];
$subtotal = 0;

// Initial order status
$order_status = 'pending_payment';

// Payment status should start as pending until buyer submits proof
$payment_status = 'pending';

// Start Transaction to guarantee complete processing or rollback
$conn->begin_transaction();

try {
    // Lock and check stock/prices for all products in cart
    $validated_items = [];
    foreach ($cart_items as $id => $item) {
        $stmt = $conn->prepare("SELECT id, seller_id, title_en, price, stock_quantity, status FROM products WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $item['id']);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        
        if (!$product || $product['status'] !== 'active') {
            throw new Exception("Product '" . ($product['title_en'] ?? $item['title']) . "' is no longer available.");
        }
        
        if ($product['stock_quantity'] < $item['quantity']) {
            throw new Exception("Insufficient stock for '" . $product['title_en'] . "'. Only " . $product['stock_quantity'] . " units are available.");
        }
        
        $item_total = $product['price'] * $item['quantity'];
        $subtotal += $item_total;
        
        $validated_items[] = [
            'id' => $product['id'],
            'seller_id' => $product['seller_id'],
            'title' => $product['title_en'],
            'price' => $product['price'],
            'quantity' => $item['quantity'],
            'total' => $item_total,
            'new_stock' => $product['stock_quantity'] - $item['quantity']
        ];
    }
    
    // Order totals
    $shipping_fee = 15.00;
    $tax = $subtotal * 0.05;
    $grand_total = $subtotal + $shipping_fee + $tax;
    
    // Generate Unique Order Number
    $order_number = 'PM-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    
    // 5. Save Order Table Record (using updated columns from update_phase3_2.sql)
    $stmt = $conn->prepare("INSERT INTO orders (user_id, shipping_name, shipping_phone, shipping_email, order_number, subtotal, tax, shipping_fee, grand_total, payment_method, payment_status, order_status, shipping_address, billing_address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("issssddddsssss", $user_id, $shipping_name, $shipping_phone, $shipping_email, $order_number, $subtotal, $tax, $shipping_fee, $grand_total, $payment_method, $payment_status, $order_status, $full_shipping_address, $full_billing_address);
    $stmt->execute();
    $order_id = $conn->insert_id;
    
    // 6. Save Order Items & Reduce Stock & Update product status if out of stock
    $stmt_item = $conn->prepare("INSERT INTO order_items (order_id, product_id, seller_id, quantity, price, total) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_stock = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE id = ?");
    $stmt_sold = $conn->prepare("UPDATE products SET status = 'sold' WHERE id = ?");
    
    foreach ($validated_items as $item) {
        // Save Order Item
        $stmt_item->bind_param("iiiidd", $order_id, $item['id'], $item['seller_id'], $item['quantity'], $item['price'], $item['total']);
        $stmt_item->execute();
        
        // Reduce stock
        $stmt_stock->bind_param("ii", $item['new_stock'], $item['id']);
        $stmt_stock->execute();
        
        // If stock is zero, mark product as 'sold'
        if ($item['new_stock'] === 0) {
            $stmt_sold->bind_param("i", $item['id']);
            $stmt_sold->execute();
        }
    }
    
    // Commit transaction
    $conn->commit();
    
    // 7. Clear Cart
    unset($_SESSION['cart']);
    
    // Redirect to success page
    header('Location: order_success.php?order_num=' . urlencode($order_number));
    exit;
    
} catch (Exception $e) {
    // Rollback changes on database failures or validations
    $conn->rollback();
    $_SESSION['error'] = "Order Processing Error: " . $e->getMessage();
    header('Location: checkout.php');
    exit;
}
?>
