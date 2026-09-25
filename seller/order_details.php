<?php
/**
 * Seller Order Details Page
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require seller role
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

// 2. Fetch specific items from this order that belong to this seller
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

// Calculate Seller's Share/Total in this Order
$seller_subtotal = 0;
foreach ($order_items as $item) {
    $seller_subtotal += $item['total'];
}

// Handle Order Workflow Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['workflow_action'])) {
    if (in_array($order['order_status'], ['cancelled', 'completed', 'delivered'])) {
        $_SESSION['error'] = "Action not allowed for current order status.";
        header("Location: order_details.php?id=$order_id");
        exit;
    }

    $action = sanitize_input($_POST['workflow_action']);
    $new_status = '';
    
    switch ($action) {
        case 'accept':
            $new_status = 'accepted';
            break;
        case 'prepare':
            $new_status = 'preparing';
            break;
        case 'ready':
            $new_status = 'ready_for_shipment';
            break;
        case 'ship':
            $new_status = 'out_for_delivery';
            break;
        case 'deliver':
            $new_status = 'delivered';
            break;
        case 'cancel':
            $new_status = 'cancelled';
            break;
    }
    
    if ($new_status) {
        $upd_stmt = $conn->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $upd_stmt->bind_param("si", $new_status, $order_id);
        if ($upd_stmt->execute()) {
            $_SESSION['success'] = "Order status updated to " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
            log_order_audit($conn, $order_id, "Status changed to $new_status", "Action by seller", $seller_id);
            $order['order_status'] = $new_status;
            
            if ($new_status === 'delivered') {
                $conn->query("UPDATE orders SET seller_delivered = 1 WHERE id = $order_id");
                log_order_audit($conn, $order_id, "Seller marked delivered", "", $seller_id);
                $order['seller_delivered'] = 1;
            } elseif ($new_status === 'cancelled') {
                // Restore Stock
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

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="fw-bold mb-0 text-success">Order #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                        <span class="text-muted small">Placed on <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                    <a href="orders.php" class="btn btn-outline-success rounded-pill btn-sm px-3">
                        <i class="bi bi-arrow-left me-1"></i>Back to Orders
                    </a>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="row g-4">
                        <!-- Customer / Contact Details -->
                        <div class="col-md-6 border-end">
                            <h5 class="fw-bold text-success mb-3"><i class="bi bi-person me-2"></i>Customer Information</h5>
                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['shipping_name']); ?></p>
                            <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                            <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($order['shipping_email']); ?></p>
                            <p class="mb-3"><strong>Account Email:</strong> <?php echo htmlspecialchars($order['user_email']); ?></p>
                            
                            <h5 class="fw-bold text-success mb-2"><i class="bi bi-geo-alt me-2"></i>Shipping Address</h5>
                            <p class="mb-0 text-muted"><?php echo htmlspecialchars($order['shipping_address']); ?></p>
                        </div>
                        
                        <!-- Status Update Actions -->
                        <div class="col-md-6">
                            <h5 class="fw-bold text-success mb-3"><i class="bi bi-gear me-2"></i>Order Actions</h5>
                            <div class="bg-light p-3 rounded-3 border">
                                <p class="mb-2"><strong>Current Status:</strong> 
                                    <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 rounded-pill fs-6">
                                        <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($order['order_status']))); ?>
                                    </span>
                                </p>
                                <p class="mb-3"><strong>Payment Status:</strong> 
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-2 py-1 rounded-pill">
                                        <?php echo ucfirst(htmlspecialchars($order['payment_status'])); ?>
                                    </span>
                                </p>

                                <form action="" method="POST" class="d-grid gap-2">
                                    <?php if ($order['order_status'] === 'pending'): ?>
                                        <button type="submit" name="workflow_action" value="accept" class="btn btn-primary fw-bold">Accept Order</button>
                                        <button type="submit" name="workflow_action" value="cancel" class="btn btn-outline-danger fw-bold" onclick="return confirm('Cancel this order? This cannot be undone.');">Cancel Order (Bird Unavailable)</button>
                                    <?php elseif ($order['order_status'] === 'accepted'): ?>
                                        <button type="submit" name="workflow_action" value="prepare" class="btn btn-info text-white fw-bold">Start Preparing</button>
                                    <?php elseif ($order['order_status'] === 'preparing'): ?>
                                        <button type="submit" name="workflow_action" value="ready" class="btn btn-warning text-dark fw-bold">Ready For Shipment</button>
                                    <?php elseif ($order['order_status'] === 'ready_for_shipment'): ?>
                                        <button type="submit" name="workflow_action" value="ship" class="btn btn-warning fw-bold">Mark Out For Delivery</button>
                                    <?php elseif ($order['order_status'] === 'out_for_delivery'): ?>
                                        <button type="submit" name="workflow_action" value="deliver" class="btn btn-success fw-bold" onclick="return confirm('Confirm you have delivered the bird?');">Mark Bird Delivered</button>
                                    <?php elseif ($order['order_status'] === 'delivered'): ?>
                                        <div class="alert alert-success py-2 mb-0 text-center"><i class="bi bi-check-circle me-1"></i> You marked this delivered. Waiting for buyer confirmation.</div>
                                    <?php elseif ($order['order_status'] === 'completed'): ?>
                                        <div class="alert alert-success py-2 mb-0 text-center">Order Completed & Payment Released</div>
                                    <?php elseif ($order['order_status'] === 'cancelled'): ?>
                                        <div class="alert alert-danger py-2 mb-0 text-center">Order Cancelled</div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Ordered (Belonging to this seller) -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-success"><i class="bi bi-box-seam me-2"></i>My Products in this Order</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th class="pe-4 text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $item): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded-3 overflow-hidden me-3" style="width: 50px; height: 50px;">
                                                    <?php if (!empty($item['image_url'])): ?>
                                                        <img src="../assets/uploads/products/<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title_en']); ?>" class="img-fluid w-100 h-100 object-fit-cover">
                                                    <?php else: ?>
                                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="bi bi-image"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <h6 class="fw-bold mb-0 small"><?php echo htmlspecialchars($item['title_en']); ?></h6>
                                                    <?php if (!empty($item['breed'])): ?>
                                                        <span class="text-muted small">Breed: <?php echo htmlspecialchars($item['breed']); ?></span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-light text-muted border py-0 px-2 mt-1 small text-uppercase" style="font-size: 0.65rem;"><?php echo htmlspecialchars($item['listing_type']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo $site_settings['currency'] . number_format($item['price'], 2); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td class="pe-4 text-end fw-bold text-success"><?php echo $site_settings['currency'] . number_format($item['total'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="card-footer bg-light border-0 p-4">
                    <div class="row justify-content-end">
                        <div class="col-md-5 text-end">
                            <span class="text-muted fw-bold me-2">Your Items Total:</span>
                            <span class="fw-bold text-success fs-5"><?php echo $site_settings['currency'] . number_format($seller_subtotal, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

