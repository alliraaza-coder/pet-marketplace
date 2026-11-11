<?php
/**
 * Seller Order Details Page
 * Phase 8 - Seller Payment Received Confirmation
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user = current_user($conn);
$seller_id = $_SESSION['user_id'];

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    $_SESSION['error'] = "Invalid Order ID.";
    header('Location: orders.php');
    exit;
}

// 1. Fetch Order and verify it contains items belonging to this seller
$stmt = $conn->prepare("
    SELECT DISTINCT o.*, u.first_name, u.last_name, u.email as user_email
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users u ON o.user_id = u.id
    WHERE o.id = ? AND oi.seller_id = ?
");
$stmt->bind_param("ii", $order_id, $seller_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    $_SESSION['error'] = "Order not found or unauthorized access.";
    header('Location: orders.php');
    exit;
}

if (in_array($order['payment_status'], ['pending', 'payment_submitted', 'rejected'])) {
    $_SESSION['error'] = "This order is not yet visible. Awaiting Admin payment verification.";
    header('Location: orders.php');
    exit;
}

// 2. Fetch specific items
$item_stmt = $conn->prepare("
    SELECT oi.*, p.title_en, p.breed, p.listing_type,
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ? AND oi.seller_id = ?
");
$item_stmt->bind_param("ii", $order['id'], $seller_id);
$item_stmt->execute();
$order_items = $item_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$seller_subtotal = 0;
foreach ($order_items as $item) {
    $seller_subtotal += $item['total'];
}

// Handle Order Workflow Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['workflow_action'])) {
    verify_csrf();
    $action = sanitize_input($_POST['workflow_action']);
    
    // Restrictions
    if (in_array($order['order_status'], ['cancelled', 'completed', 'delivered']) && $action !== 'confirm_payout') {
        $_SESSION['error'] = "Action not allowed for current order status.";
        header("Location: order_details.php?id=$order_id");
        exit;
    }

    if ($action === 'confirm_payout') {
        if ($order['seller_payment_received'] == 1) {
            $_SESSION['error'] = "You have already confirmed payment receipt for this order.";
        } elseif ($order['payment_status'] !== 'seller_payment_sent') {
            $_SESSION['error'] = "No payment has been sent for this order yet.";
        } else {
            $now = date('Y-m-d H:i:s');
            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE orders SET payment_status = 'seller_payment_received', seller_payment_received = 1, seller_payment_received_at = ?, order_status = 'completed' WHERE id = ? AND seller_payment_received = 0");
                $upd->bind_param("si", $now, $order_id);
                $upd->execute();
                
                if ($upd->affected_rows === 0) throw new Exception("Payment already confirmed or order not found.");
                
                if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Seller confirmed payment received", "Amount: " . $order['seller_payment_amount'] . " via " . $order['seller_payment_method'], $seller_id);
                
                $conn->commit();
                $_SESSION['success'] = "Payment receipt confirmed. Order is now fully completed!";
                $order['payment_status'] = 'seller_payment_received';
                $order['seller_payment_received'] = 1;
                $order['seller_payment_received_at'] = $now;
                $order['order_status'] = 'completed';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Confirmation failed: " . $e->getMessage();
            }
        }
    } elseif ($action === 'accept') {
        // Phase 6: Seller accepts order -> verify stock, deduct atomically, set status
        if ($order['order_status'] === 'accepted') {
            // Already accepted - prevent double deduction / idempotency
            $_SESSION['info'] = "Order is already accepted.";
        } else {
            $conn->begin_transaction();
            try {
                // Re-verify order status with row lock to avoid race conditions
                $chk_order = $conn->prepare("SELECT order_status FROM orders WHERE id = ? FOR UPDATE");
                $chk_order->bind_param("i", $order_id);
                $chk_order->execute();
                $cur_order_row = $chk_order->get_result()->fetch_assoc();
                $chk_order->close();

                if (!$cur_order_row || $cur_order_row['order_status'] === 'accepted') {
                    // Already accepted concurrently
                    $conn->commit();
                    $_SESSION['info'] = "Order is already accepted.";
                } else {
                    // Lock and verify items for this seller that have not been deducted yet
                    $oi_stmt = $conn->prepare("SELECT id, product_id, quantity, stock_deducted FROM order_items WHERE order_id = ? AND seller_id = ? FOR UPDATE");
                    $oi_stmt->bind_param("ii", $order_id, $seller_id);
                    $oi_stmt->execute();
                    $items_to_process = $oi_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $oi_stmt->close();

                    if (empty($items_to_process)) {
                        throw new Exception("No products found for this order.");
                    }

                    // 1. Verify sufficient stock for all items
                    foreach ($items_to_process as $item) {
                        if ($item['stock_deducted'] == 0) {
                            $p_stmt = $conn->prepare("SELECT id, title_en, stock_quantity, status FROM products WHERE id = ? FOR UPDATE");
                            $p_stmt->bind_param("i", $item['product_id']);
                            $p_stmt->execute();
                            $prod = $p_stmt->get_result()->fetch_assoc();
                            $p_stmt->close();

                            if (!$prod) {
                                throw new Exception("Product ID #{$item['product_id']} not found.");
                            }
                            if ($prod['stock_quantity'] < $item['quantity']) {
                                throw new Exception("Insufficient stock for '{$prod['title_en']}'. Available: {$prod['stock_quantity']}, Required: {$item['quantity']}.");
                            }
                        }
                    }

                    // 2. Deduct stock atomically and mark order_items as stock_deducted = 1
                    foreach ($items_to_process as $item) {
                        if ($item['stock_deducted'] == 0) {
                            $deduct_stmt = $conn->prepare(
                                "UPDATE products 
                                 SET status = IF(stock_quantity - ? <= 0, 'sold_out', status),
                                     stock_quantity = stock_quantity - ? 
                                 WHERE id = ? AND stock_quantity >= ?"
                            );
                            $deduct_stmt->bind_param("iiii", $item['quantity'], $item['quantity'], $item['product_id'], $item['quantity']);
                            $deduct_stmt->execute();

                            if ($deduct_stmt->affected_rows === 0) {
                                $deduct_stmt->close();
                                throw new Exception("Could not deduct stock for product ID #{$item['product_id']}. Insufficient stock or concurrent modification.");
                            }
                            $deduct_stmt->close();

                            $mark_oi = $conn->prepare("UPDATE order_items SET stock_deducted = 1 WHERE id = ?");
                            $mark_oi->bind_param("i", $item['id']);
                            $mark_oi->execute();
                            $mark_oi->close();
                        }
                    }

                    // 3. Update order status to accepted
                    $upd_order = $conn->prepare("UPDATE orders SET order_status = 'accepted' WHERE id = ?");
                    $upd_order->bind_param("i", $order_id);
                    $upd_order->execute();
                    $upd_order->close();

                    if (function_exists('log_order_audit')) {
                        log_order_audit($conn, $order_id, "Order accepted by seller", "Stock deducted atomically.", $seller_id);
                    }

                    // Notify Buyer
                    if (function_exists('add_user_notification')) {
                        $msg = "Your order #" . $order['order_number'] . " has been accepted by the seller!";
                        add_user_notification($conn, $order['user_id'], 'order_update', 'Order Accepted', $msg, 'user/order_details.php?id=' . $order_id);
                    }

                    $conn->commit();
                    $_SESSION['success'] = "Order accepted and stock updated successfully.";
                    $order['order_status'] = 'accepted';
                }
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Failed to accept order: " . $e->getMessage();
            }
        }
    } elseif ($action === 'ship') {
        // Enforce vehicle details and 3 mandatory photos before marking out_for_delivery
        if ($order['order_status'] !== 'ready_for_shipment') {
            $_SESSION['error'] = "Order must be in 'Ready for Shipment' status before it can be marked 'Out for Delivery'.";
            header("Location: order_details.php?id=$order_id");
            exit;
        }

        $vehicle_number       = sanitize_input($_POST['vehicle_number'] ?? '');
        $vehicle_owner_number = sanitize_input($_POST['vehicle_owner_number'] ?? '');

        if (empty($vehicle_number) || empty($vehicle_owner_number)) {
            $_SESSION['error'] = "Vehicle Number and Vehicle Owner/Driver Contact Number are strictly compulsory to mark Out for Delivery.";
            header("Location: order_details.php?id=$order_id");
            exit;
        }

        // Validate 3 compulsory photos
        $required_photos = [
            'delivery_proof_packed'  => 'Photo 1: Packed Product / Pet Carrier',
            'delivery_proof_loaded'  => 'Photo 2: Product Loaded in Vehicle',
            'delivery_proof_vehicle' => 'Photo 3: Delivery Vehicle / Cargo'
        ];

        $uploaded_filenames = [];
        $allowed_exts  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $upload_dir    = '../assets/images/delivery/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $validation_errors = [];

        foreach ($required_photos as $field_key => $field_label) {
            if (!isset($_FILES[$field_key]) || $_FILES[$field_key]['error'] !== UPLOAD_ERR_OK) {
                $validation_errors[] = "$field_label is mandatory.";
                continue;
            }

            $tmp_path  = $_FILES[$field_key]['tmp_name'];
            $orig_name = $_FILES[$field_key]['name'];
            $ext       = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $tmp_path);
            finfo_close($finfo);

            if (!in_array($ext, $allowed_exts) || !in_array($mime, $allowed_mimes)) {
                $validation_errors[] = "$field_label must be a valid image file (JPG, PNG, or WEBP).";
                continue;
            }

            if ($_FILES[$field_key]['size'] > 5 * 1024 * 1024) {
                $validation_errors[] = "$field_label size exceeds the 5MB limit.";
                continue;
            }

            $prefix = str_replace('delivery_proof_', '', $field_key);
            $new_name = $prefix . "_{$order_id}_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
            if (move_uploaded_file($tmp_path, $upload_dir . $new_name)) {
                $uploaded_filenames[$field_key] = $new_name;
            } else {
                $validation_errors[] = "Failed to upload $field_label.";
            }
        }

        if (!empty($validation_errors)) {
            foreach ($uploaded_filenames as $fl) {
                @unlink($upload_dir . $fl);
            }
            $_SESSION['error'] = implode("<br>", $validation_errors);
            header("Location: order_details.php?id=$order_id");
            exit;
        }

        $now = date('Y-m-d H:i:s');
        $upd_stmt = $conn->prepare("
            UPDATE orders 
            SET order_status = 'out_for_delivery',
                vehicle_number = ?,
                vehicle_owner_number = ?,
                delivery_proof_packed = ?,
                delivery_proof_loaded = ?,
                delivery_proof_vehicle = ?,
                delivery_dispatched_at = ?
            WHERE id = ?
        ");
        $upd_stmt->bind_param(
            "ssssssi",
            $vehicle_number,
            $vehicle_owner_number,
            $uploaded_filenames['delivery_proof_packed'],
            $uploaded_filenames['delivery_proof_loaded'],
            $uploaded_filenames['delivery_proof_vehicle'],
            $now,
            $order_id
        );

        if ($upd_stmt->execute()) {
            $_SESSION['success'] = "Order is now Out for Delivery! Vehicle details and dispatch verification photos recorded successfully.";
            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Order marked Out for Delivery", "Vehicle: $vehicle_number | Driver: $vehicle_owner_number", $seller_id);
            }
            if (function_exists('add_user_notification')) {
                $msg = "Your order #{$order['order_number']} is now Out for Delivery! Dispatched via Vehicle #{$vehicle_number} (Driver/Owner: {$vehicle_owner_number}).";
                add_user_notification($conn, $order['user_id'], 'order_update', 'Order Out for Delivery', $msg, 'user/order_details.php?id=' . $order_id);
            }
            $order['order_status'] = 'out_for_delivery';
            $order['vehicle_number'] = $vehicle_number;
            $order['vehicle_owner_number'] = $vehicle_owner_number;
            $order['delivery_proof_packed'] = $uploaded_filenames['delivery_proof_packed'];
            $order['delivery_proof_loaded'] = $uploaded_filenames['delivery_proof_loaded'];
            $order['delivery_proof_vehicle'] = $uploaded_filenames['delivery_proof_vehicle'];
            $order['delivery_dispatched_at'] = $now;
            header("Location: order_details.php?id=$order_id");
            exit;
        } else {
            $_SESSION['error'] = "Database update failed: " . $conn->error;
            header("Location: order_details.php?id=$order_id");
            exit;
        }
    } else {
        $new_status = '';
        switch ($action) {
            case 'prepare': $new_status = 'preparing'; break;
            case 'ready': $new_status = 'ready_for_shipment'; break;
            case 'deliver': $new_status = 'delivered'; break;
            case 'cancel': $new_status = 'cancelled'; break;
        }
        
        if ($new_status) {
            $upd_stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
            $upd_stmt->bind_param("si", $new_status, $order_id);
            if ($upd_stmt->execute()) {
                $_SESSION['success'] = "Order status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Status changed to $new_status", "Action by seller", $seller_id);
                $order['order_status'] = $new_status;
                
                if ($new_status === 'delivered') {
                    $conn->query("UPDATE orders SET seller_delivered = 1 WHERE id = $order_id");
                    if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Seller marked delivered", "", $seller_id);
                    $order['seller_delivered'] = 1;
                } elseif ($new_status === 'cancelled') {
                    // Phase 6 Rule: Deducted stock is PERMANENT. NO automatic restoration when order is cancelled.
                    // Seller must manually increase stock via Edit Product if they want to re-list.
                }
                
                // Notify Buyer
                if (function_exists('add_user_notification')) {
                    $msg = "Your order #" . $order['order_number'] . " is now " . ucfirst(str_replace('_', ' ', $new_status));
                    add_user_notification($conn, $order['user_id'], 'order_update', 'Order Status Changed', $msg, 'user/order_details.php?id=' . $order_id);
                }
            } else {
                $_SESSION['error'] = "Failed to update order status.";
            }
        }
    }
}

$page_title = "Order Details #" . htmlspecialchars($order['order_number']);
include '../includes/header.php';
?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0">Order <span class="text-primary">#<?php echo htmlspecialchars($order['order_number']); ?></span></h2>
        <a href="orders.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left me-2"></i>Back to Orders
        </a>
    </div>

    <?php display_messages(); ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-person-lines-fill me-2"></i>Buyer Information</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block mb-1">Name</small>
                            <span class="fw-bold"><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></span>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block mb-1">Location</small>
                            <span class="fw-bold"><?php echo htmlspecialchars($order['shipping_address']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Delivery Workflow Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-truck me-2"></i>Delivery Workflow</h5>
                </div>
                <div class="card-body p-4 text-center">
                    <?php if ($order['seller_delivered'] == 0 && !in_array($order['order_status'], ['completed', 'cancelled'])): ?>
                        <p class="text-muted mb-4">Update the order status below to keep the buyer informed.</p>
                        <form action="" method="POST" class="d-inline-flex gap-2">
                            <?php csrf_field(); ?>
                            <?php if ($order['order_status'] === 'pending' || $order['order_status'] === 'pending_payment'): ?>
                                <button type="submit" name="workflow_action" value="accept" class="btn btn-primary fw-bold">Accept Order</button>
                                <button type="submit" name="workflow_action" value="cancel" class="btn btn-outline-danger fw-bold" onclick="return confirm('Cancel this order?');">Cancel Order</button>
                            <?php elseif ($order['order_status'] === 'accepted'): ?>
                                <button type="submit" name="workflow_action" value="prepare" class="btn btn-info text-white fw-bold">Start Preparing</button>
                            <?php elseif ($order['order_status'] === 'preparing'): ?>
                                <button type="submit" name="workflow_action" value="ready" class="btn btn-warning text-dark fw-bold">Ready for Shipment</button>
                            <?php elseif ($order['order_status'] === 'ready_for_shipment'): ?>
                                <button type="button" class="btn btn-primary fw-bold" data-bs-toggle="modal" data-bs-target="#dispatchModal">
                                    <i class="bi bi-truck me-2"></i>Out for Delivery
                                </button>
                            <?php elseif ($order['order_status'] === 'out_for_delivery'): ?>
                                <button type="submit" name="workflow_action" value="deliver" class="btn btn-success fw-bold" onclick="return confirm('Confirm you have delivered the bird?');">Mark Bird Delivered</button>
                            <?php endif; ?>
                        </form>
                    <?php elseif ($order['seller_delivered'] == 1 && $order['buyer_received'] == 0): ?>
                        <div class="alert alert-info border-0 rounded-3 mb-0">
                            <i class="bi bi-clock-history me-2"></i><strong>Waiting for Buyer</strong><br>
                            You have marked this order as delivered. We are waiting for the buyer to confirm receipt.
                        </div>
                    <?php elseif ($order['seller_delivered'] == 1 && $order['buyer_received'] == 1 && $order['order_status'] !== 'completed'): ?>
                        <div class="alert alert-success border-0 rounded-3 mb-0">
                            <i class="bi bi-check2-all me-2"></i><strong>Delivery Complete</strong><br>
                            Both you and the buyer have confirmed delivery. Admin will process your payment soon.
                        </div>
                    <?php elseif ($order['order_status'] === 'completed'): ?>
                        <div class="alert alert-success border-0 rounded-3 mb-0">
                            <i class="bi bi-star-fill me-2"></i><strong>Order Completed</strong>
                        </div>
                    <?php elseif ($order['order_status'] === 'cancelled'): ?>
                        <div class="alert alert-danger border-0 rounded-3 mb-0">
                            <i class="bi bi-x-circle me-2"></i><strong>Order Cancelled</strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Vehicle & Dispatch Proofs Card (When Dispatched) -->
            <?php if (!empty($order['vehicle_number'])): ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-primary">
                        <i class="bi bi-truck me-2"></i>Vehicle & Dispatch Proofs
                    </h5>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill px-3 py-2">
                        <i class="bi bi-patch-check-fill me-1"></i> Dispatched via Registered Vehicle
                    </span>
                </div>
                <div class="card-body p-4">
                    <!-- Vehicle Info Strip -->
                    <div class="p-3 bg-light rounded-4 border mb-4">
                        <div class="row g-3 align-items-center text-center text-md-start">
                            <div class="col-12 col-md-4">
                                <small class="text-muted d-block" style="font-size: 0.75rem;"><i class="bi bi-123 text-primary me-1"></i>Vehicle / Cargo Number</small>
                                <span class="fw-bold font-monospace fs-6 text-dark d-inline-block bg-white px-3 py-1 rounded-pill border mt-1 shadow-sm">
                                    <?php echo htmlspecialchars($order['vehicle_number']); ?>
                                </span>
                            </div>
                            <div class="col-12 col-md-4">
                                <small class="text-muted d-block" style="font-size: 0.75rem;"><i class="bi bi-telephone-fill text-success me-1"></i>Driver / Owner Contact</small>
                                <a href="tel:<?php echo htmlspecialchars($order['vehicle_owner_number']); ?>" class="btn btn-sm btn-outline-success rounded-pill px-3 py-1 mt-1 fw-bold">
                                    <i class="bi bi-telephone-outbound me-1"></i><?php echo htmlspecialchars($order['vehicle_owner_number']); ?>
                                </a>
                            </div>
                            <div class="col-12 col-md-4 text-md-end">
                                <small class="text-muted d-block" style="font-size: 0.75rem;"><i class="bi bi-clock-history me-1"></i>Dispatched At</small>
                                <span class="text-dark small fw-semibold">
                                    <?php echo !empty($order['delivery_dispatched_at']) ? date('d M Y, h:i A', strtotime($order['delivery_dispatched_at'])) : date('d M Y, h:i A', strtotime($order['updated_at'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- 3 Proof Photos Grid -->
                    <h6 class="fw-bold mb-3 text-dark small text-uppercase"><i class="bi bi-images me-1 text-primary"></i> Verification Photos Submitted</h6>
                    <div class="row g-3">
                        <!-- Proof 1: Packed Product -->
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="card h-100 border rounded-3 p-2 text-center bg-white shadow-sm position-relative">
                                <span class="badge bg-primary bg-opacity-10 text-primary small py-1 px-2 rounded-pill mb-2 fw-semibold">1. Packed Product / Pet</span>
                                <div class="ratio ratio-4x3 rounded-3 overflow-hidden border bg-light mb-2">
                                    <?php if (!empty($order['delivery_proof_packed'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_packed']); ?>" 
                                             class="img-fluid object-fit-cover w-100 h-100 cursor-pointer proof-thumb" 
                                             alt="Packed Product Proof"
                                             onclick="showProofModal(this.src, 'Packed Product / Pet Proof')"
                                             onerror="this.src='https://placehold.co/300x225?text=Packed+Proof';">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center text-muted small">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-light border rounded-pill w-100 small" onclick="showProofModal('<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_packed']); ?>', 'Packed Product / Pet Proof')">
                                    <i class="bi bi-zoom-in me-1 text-primary"></i> View Photo
                                </button>
                            </div>
                        </div>

                        <!-- Proof 2: Loaded in Vehicle -->
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="card h-100 border rounded-3 p-2 text-center bg-white shadow-sm position-relative">
                                <span class="badge bg-success bg-opacity-10 text-success small py-1 px-2 rounded-pill mb-2 fw-semibold">2. Loaded in Vehicle</span>
                                <div class="ratio ratio-4x3 rounded-3 overflow-hidden border bg-light mb-2">
                                    <?php if (!empty($order['delivery_proof_loaded'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_loaded']); ?>" 
                                             class="img-fluid object-fit-cover w-100 h-100 cursor-pointer proof-thumb" 
                                             alt="Loaded in Vehicle Proof"
                                             onclick="showProofModal(this.src, 'Product Loaded in Vehicle Proof')"
                                             onerror="this.src='https://placehold.co/300x225?text=Loaded+Proof';">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center text-muted small">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-light border rounded-pill w-100 small" onclick="showProofModal('<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_loaded']); ?>', 'Product Loaded in Vehicle Proof')">
                                    <i class="bi bi-zoom-in me-1 text-success"></i> View Photo
                                </button>
                            </div>
                        </div>

                        <!-- Proof 3: Delivery Vehicle -->
                        <div class="col-12 col-sm-6 col-md-4">
                            <div class="card h-100 border rounded-3 p-2 text-center bg-white shadow-sm position-relative">
                                <span class="badge bg-info bg-opacity-10 text-info small py-1 px-2 rounded-pill mb-2 fw-semibold">3. Transport Vehicle</span>
                                <div class="ratio ratio-4x3 rounded-3 overflow-hidden border bg-light mb-2">
                                    <?php if (!empty($order['delivery_proof_vehicle'])): ?>
                                        <img src="<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_vehicle']); ?>" 
                                             class="img-fluid object-fit-cover w-100 h-100 cursor-pointer proof-thumb" 
                                             alt="Delivery Vehicle Proof"
                                             onclick="showProofModal(this.src, 'Delivery Vehicle / Cargo Proof')"
                                             onerror="this.src='https://placehold.co/300x225?text=Vehicle+Proof';">
                                    <?php else: ?>
                                        <div class="d-flex align-items-center justify-content-center text-muted small">No Image</div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-light border rounded-pill w-100 small" onclick="showProofModal('<?php echo BASE_URL; ?>/assets/images/delivery/<?php echo htmlspecialchars($order['delivery_proof_vehicle']); ?>', 'Delivery Vehicle / Cargo Proof')">
                                    <i class="bi bi-zoom-in me-1 text-info"></i> View Photo
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Seller Payment Card -->
            <?php if (in_array($order['payment_status'], ['seller_payment_sent', 'seller_payment_received']) || $order['seller_payment_received'] == 1): ?>
            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-success"><i class="bi bi-cash-coin me-2"></i>Your Payment</h5>
                </div>
                <div class="card-body px-4 pb-4">
                    <!-- Payment details panel -->
                    <div class="bg-light rounded-3 p-3 mb-3">
                        <div class="row g-2">
                            <div class="col-6"><small class="text-muted">Payment Method</small><br><strong><?= htmlspecialchars(ucfirst(str_replace('_',' ',$order['seller_payment_method']))) ?></strong></div>
                            <div class="col-6"><small class="text-muted">Amount</small><br><strong class="text-success fs-5"><?= $site_settings['currency'] . number_format($order['seller_payment_amount'], 2) ?></strong></div>
                            <div class="col-6"><small class="text-muted">Transaction ID</small><br><strong><?= htmlspecialchars($order['seller_payment_transaction_id']) ?></strong></div>
                            <div class="col-6"><small class="text-muted">Date Sent</small><br><strong><?= date('d M Y h:i A', strtotime($order['seller_payment_sent_at'])) ?></strong></div>
                        </div>
                        <?php if (!empty($order['seller_payment_receipt'])): ?>
                        <div class="mt-2">
                            <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($order['seller_payment_receipt']) ?>" 
                               target="_blank" class="btn btn-sm btn-outline-primary rounded-pill">
                                <i class="bi bi-eye me-1"></i>View Payment Receipt
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Confirm button or confirmed badge -->
                    <?php if ($order['seller_payment_received'] == 0 && $order['payment_status'] === 'seller_payment_sent'): ?>
                    <form action="" method="POST" onsubmit="return confirm('Confirm you have received this payment?');">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="workflow_action" value="confirm_payout">
                        <button type="submit" class="btn btn-success fw-bold rounded-pill w-100">
                            <i class="bi bi-check-circle me-2"></i>Payment Received
                        </button>
                    </form>
                    <?php elseif ($order['seller_payment_received'] == 1): ?>
                    <div class="alert alert-success border-0 rounded-3 mb-0">
                        <i class="bi bi-check-all me-2"></i><strong>Payment Received</strong>
                        <?php if (!empty($order['seller_payment_received_at'])): ?>
                        <br><small class="text-muted">Confirmed: <?= date('d M Y h:i A', strtotime($order['seller_payment_received_at'])) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($order['payment_status'] === 'held' && !in_array($order['order_status'], ['completed','cancelled'])): ?>
            <div class="alert alert-info border-0 small mt-4">
                <i class="bi bi-info-circle me-1"></i>Payment will be sent by Admin after successful delivery and verification.
            </div>
            <?php endif; ?>

        </div>
        
        <!-- Items Ordered (Belonging to this seller) -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-success"><i class="bi bi-box-seam me-2"></i>My Products</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th>Qty</th>
                                    <th class="pe-4 text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $item): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded-3 overflow-hidden me-2 border flex-shrink-0" style="width: 48px; height: 48px;">
                                                    <?php 
                                                    $item_img_src = !empty($item['image_url']) 
                                                        ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($item['image_url']) 
                                                        : get_product_image_url($conn, $item['product_id']); 
                                                    ?>
                                                    <img src="<?php echo $item_img_src; ?>" 
                                                         alt="<?php echo htmlspecialchars($item['title_en']); ?>" 
                                                         class="w-100 h-100 object-fit-cover"
                                                         onerror="this.src='https://placehold.co/50x50?text=Pet';">
                                                </div>
                                                <div>
                                                    <h6 class="fw-bold mb-0 small text-dark"><?php echo htmlspecialchars($item['title_en']); ?></h6>
                                                    <?php if (!empty($item['breed'])): ?>
                                                        <small class="text-muted d-block" style="font-size:0.72rem;"><?php echo htmlspecialchars($item['breed']); ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>x<?php echo $item['quantity']; ?></td>
                                        <td class="pe-4 text-end fw-bold text-success"><?php echo $site_settings['currency'] . number_format($item['total'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light border-0 p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-dark">My Subtotal:</span>
                        <span class="fw-bold text-success fs-5"><?php echo $site_settings['currency'] . number_format($seller_subtotal, 2); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Out for Delivery: Vehicle & Dispatch Modal -->
<div class="modal fade" id="dispatchModal" tabindex="-1" aria-labelledby="dispatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form action="" method="POST" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="workflow_action" value="ship">
                
                <div class="modal-header bg-primary text-white border-0 py-3 px-4">
                    <h5 class="modal-title fw-bold" id="dispatchModalLabel">
                        <i class="bi bi-truck me-2"></i>Out for Delivery - Vehicle & Dispatch Proofs
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="alert alert-warning border-0 rounded-3 d-flex align-items-center mb-4 p-3">
                        <i class="bi bi-shield-exclamation fs-3 text-warning me-3 flex-shrink-0"></i>
                        <div class="small">
                            <strong>Mandatory Delivery Verification:</strong> You must enter vehicle details and upload all 3 photos (packaging, loaded in vehicle, and transport vehicle). This information is compulsory before this order can be marked Out for Delivery.
                        </div>
                    </div>
                    
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark"><i class="bi bi-123 me-1 text-primary"></i> Vehicle / Cargo Number <span class="text-danger">*</span></label>
                            <input type="text" name="vehicle_number" class="form-control rounded-3" placeholder="e.g. LEA-2024 / Courier Van / Suzuki Bolan" required>
                            <small class="text-muted" style="font-size:0.75rem;">Vehicle registration number or transport cargo details.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark"><i class="bi bi-telephone-fill me-1 text-success"></i> Vehicle Owner / Driver Phone <span class="text-danger">*</span></label>
                            <input type="tel" name="vehicle_owner_number" class="form-control rounded-3" placeholder="e.g. 0300-1234567" required>
                            <small class="text-muted" style="font-size:0.75rem;">Contact number of driver or cargo rider delivering the pet.</small>
                        </div>
                    </div>

                    <h6 class="fw-bold mb-3 text-dark border-bottom pb-2"><i class="bi bi-camera-fill text-primary me-2"></i>Compulsory Verification Photos (All 3 Required)</h6>
                    
                    <div class="row g-3">
                        <!-- Photo 1: Packed Product -->
                        <div class="col-12 col-md-4">
                            <div class="card h-100 border rounded-3 p-3 text-center bg-light">
                                <i class="bi bi-box2-heart text-primary fs-2 mb-2"></i>
                                <span class="fw-bold small d-block mb-1 text-dark">1. Packed Product / Pet <span class="text-danger">*</span></span>
                                <small class="text-muted d-block mb-2" style="font-size:0.72rem;">Safe cage / travel box</small>
                                <input type="file" name="delivery_proof_packed" class="form-control form-control-sm" accept="image/*" required onchange="previewDispatchImg(this, 'previewPacked')">
                                <img id="previewPacked" class="img-thumbnail mt-2 d-none rounded-3 mx-auto" style="max-height: 90px; width: auto; object-fit: cover;">
                            </div>
                        </div>

                        <!-- Photo 2: Loaded in Vehicle -->
                        <div class="col-12 col-md-4">
                            <div class="card h-100 border rounded-3 p-3 text-center bg-light">
                                <i class="bi bi-box-arrow-in-down-right text-success fs-2 mb-2"></i>
                                <span class="fw-bold small d-block mb-1 text-dark">2. Loaded in Vehicle <span class="text-danger">*</span></span>
                                <small class="text-muted d-block mb-2" style="font-size:0.72rem;">Package placed inside vehicle</small>
                                <input type="file" name="delivery_proof_loaded" class="form-control form-control-sm" accept="image/*" required onchange="previewDispatchImg(this, 'previewLoaded')">
                                <img id="previewLoaded" class="img-thumbnail mt-2 d-none rounded-3 mx-auto" style="max-height: 90px; width: auto; object-fit: cover;">
                            </div>
                        </div>

                        <!-- Photo 3: Vehicle / Cargo Image -->
                        <div class="col-12 col-md-4">
                            <div class="card h-100 border rounded-3 p-3 text-center bg-light">
                                <i class="bi bi-truck-front text-info fs-2 mb-2"></i>
                                <span class="fw-bold small d-block mb-1 text-dark">3. Delivery Vehicle <span class="text-danger">*</span></span>
                                <small class="text-muted d-block mb-2" style="font-size:0.72rem;">Vehicle showing number plate</small>
                                <input type="file" name="delivery_proof_vehicle" class="form-control form-control-sm" accept="image/*" required onchange="previewDispatchImg(this, 'previewVehicle')">
                                <img id="previewVehicle" class="img-thumbnail mt-2 d-none rounded-3 mx-auto" style="max-height: 90px; width: auto; object-fit: cover;">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer border-0 bg-light py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i>Confirm Out for Delivery
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Fullscreen Proof Image Modal -->
<div class="modal fade" id="proofViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header py-3 px-4 border-0 bg-dark text-white">
                <h6 class="modal-title fw-bold mb-0" id="proofModalTitle"><i class="bi bi-image me-2"></i>Dispatch Proof Photo</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0 text-center bg-black">
                <img id="proofModalImg" src="" class="img-fluid" style="max-height: 80vh; width: auto; object-fit: contain;">
            </div>
        </div>
    </div>
</div>

<script>
function showProofModal(src, title) {
    document.getElementById('proofModalImg').src = src;
    document.getElementById('proofModalTitle').innerHTML = '<i class="bi bi-image me-2"></i>' + title;
    var modal = new bootstrap.Modal(document.getElementById('proofViewModal'));
    modal.show();
}
function previewDispatchImg(input, targetId) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var img = document.getElementById(targetId);
            img.src = e.target.result;
            img.classList.remove('d-none');
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include '../includes/footer.php'; ?>