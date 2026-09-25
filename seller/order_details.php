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
    } else {
        $new_status = '';
        switch ($action) {
            case 'accept': $new_status = 'accepted'; break;
            case 'prepare': $new_status = 'preparing'; break;
            case 'ready': $new_status = 'ready_for_shipment'; break;
            case 'ship': $new_status = 'out_for_delivery'; break;
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
                    foreach ($order_items as $itm) {
                        $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold', 'active', status) WHERE id = ?");
                        $stock_stmt->bind_param("ii", $itm['quantity'], $itm['product_id']);
                        $stock_stmt->execute();
                    }
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
                                <button type="submit" name="workflow_action" value="ship" class="btn btn-primary fw-bold">Out for Delivery</button>
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
                                            <h6 class="fw-bold mb-0 small"><?php echo htmlspecialchars($item['title_en']); ?></h6>
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

<?php include '../includes/footer.php'; ?>