<?php
/**
 * Admin Order Details
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];
$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$order_id) {
    $_SESSION['error'] = "Invalid order ID.";
    header('Location: orders.php');
    exit;
}

// Handle Refund / Cancel Action
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refund') {
    $conn->begin_transaction();
    try {
        // Fetch order to verify state
        $chk_stmt = $conn->prepare("SELECT payment_status FROM orders WHERE id = ?");
        $chk_stmt->bind_param("i", $order_id);
        $chk_stmt->execute();
        $chk = $chk_stmt->get_result()->fetch_assoc();

        if ($chk['payment_status'] === 'released' || $chk['payment_status'] === 'seller_payment_received') {
            throw new Exception("Cannot refund an order that has already been paid and released to the seller.");
        }
        if (in_array($chk['payment_status'], ['refund_sent', 'refund_received', 'refunded'])) {
            throw new Exception("Order is already refunded or refund has been sent.");
        }

        // Cancel order -> order_status = cancelled
        $upd_stmt = $conn->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        $upd_stmt->execute();

        log_admin_activity($conn, $admin_id, "Cancelled Order", "Cancelled Order ID: $order_id for refund processing.");
        if (function_exists('log_order_audit')) {
            log_order_audit($conn, $order_id, "Admin cancelled order", "Awaiting refund disbursement in Escrow.", $admin_id);
        }

        $conn->commit();

        if (in_array($chk['payment_status'], ['held', 'payment_submitted'])) {
            $_SESSION['info'] = "Order #$order_id has been cancelled. Please disburse the refund and upload receipt proof in Escrow below.";
            header("Location: escrow.php");
            exit;
        } else {
            $_SESSION['success'] = "Order #$order_id has been cancelled.";
            header("Location: order_details.php?id=$order_id");
            exit;
        }
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Action failed: " . $e->getMessage();
        header("Location: order_details.php?id=$order_id");
        exit;
    }
}

// Fetch Order Information
$sql = "SELECT o.*, 
        b.first_name AS buyer_fn, b.last_name AS buyer_ln, b.email AS buyer_email, b.phone AS buyer_phone
        FROM orders o
        JOIN users b ON o.user_id = b.id
        WHERE o.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    $_SESSION['error'] = "Order not found.";
    header('Location: orders.php');
    exit;
}

// Fetch Order Items and Seller Info
$items_sql = "SELECT oi.*, p.title_en, 
              (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS image, 
              s.first_name AS seller_fn, s.last_name AS seller_ln, s.id AS seller_id
              FROM order_items oi
              JOIN products p ON oi.product_id = p.id
              JOIN users s ON oi.seller_id = s.id
              WHERE oi.order_id = ?";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("i", $order_id);
$items_stmt->execute();
$items = $items_stmt->get_result();

$page_title = "Order Details #" . htmlspecialchars($order['order_number']);
$page_heading = "Order Summary";
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <a href="orders.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Orders</a>
    
    <div>
        <?php if ($order['payment_status'] !== 'released' && $order['payment_status'] !== 'refunded' && $order['order_status'] !== 'cancelled'): ?>
            <form action="order_details.php?id=<?php echo $order_id; ?>" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to refund this order? This will cancel the order and return funds to the buyer.');">
                <input type="hidden" name="action" value="refund">
                <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4"><i class="bi bi-arrow-counterclockwise me-1"></i> Refund Buyer</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Main Order Details -->
    <div class="col-lg-8">
        <!-- Order Header Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h4 class="fw-bold mb-1">Order #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                        <p class="text-muted mb-0"><i class="bi bi-calendar-event me-1"></i> Placed on <?php echo date('F d, Y \a\t h:i A', strtotime($order['created_at'])); ?></p>
                    </div>
                    <div class="text-end">
                        <div class="mb-2">
                            <span class="text-muted small d-block mb-1">Order Status</span>
                            <?php
                            $o_bg = 'bg-secondary';
                            if ($order['order_status'] === 'completed' || $order['order_status'] === 'delivered') $o_bg = 'bg-success';
                            if ($order['order_status'] === 'cancelled') $o_bg = 'bg-danger';
                            if ($order['order_status'] === 'pending') $o_bg = 'bg-warning text-dark';
                            if ($order['order_status'] === 'shipped') $o_bg = 'bg-info text-dark';
                            ?>
                            <span class="badge <?php echo $o_bg; ?> rounded-pill px-3 py-2 fs-6"><?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?></span>
                        </div>
                        <div>
                            <span class="text-muted small d-block mb-1">Payment Status</span>
                            <?php
                            $p_bg = 'bg-secondary';
                            if ($order['payment_status'] === 'held') $p_bg = 'bg-warning text-dark';
                            if ($order['payment_status'] === 'released') $p_bg = 'bg-success';
                            if ($order['payment_status'] === 'refunded') $p_bg = 'bg-danger';
                            ?>
                            <span class="badge <?php echo $p_bg; ?> rounded-pill px-3 py-2 fs-6"><?php echo ucfirst(str_replace('_', ' ', $order['payment_status'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicle & Dispatch Proofs Card (Admin Side) -->
        <?php if (!empty($order['vehicle_number'])): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
            <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold mb-0 text-primary">
                    <i class="bi bi-truck me-2"></i>Delivery Vehicle & Cargo Dispatch Proofs
                </h5>
                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary rounded-pill px-3 py-2">
                    <i class="bi bi-patch-check-fill me-1"></i> Dispatched via Registered Vehicle
                </span>
            </div>
            <div class="card-body p-4">
                <!-- Vehicle & Driver Strip -->
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

                <!-- 3 Verification Photos Grid -->
                <h6 class="fw-bold mb-3 text-dark small text-uppercase"><i class="bi bi-images me-1 text-primary"></i> Verification Photos Submitted by Seller</h6>
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

        <!-- Order Items -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Order Items</h5>
            </div>
            <div class="card-body p-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Seller</th>
                                <th>Price</th>
                                <th>Qty</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($item = $items->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php
                                            $item_img = '';
                                            if (!empty($item['image'])) {
                                                $item_img = BASE_URL . '/assets/uploads/products/' . htmlspecialchars($item['image']);
                                            } else {
                                                $item_img = get_product_image_url($conn, $item['product_id']);
                                            }
                                            ?>
                                            <img src="<?php echo $item_img; ?>" class="rounded me-3 object-fit-cover border" width="55" height="55" onerror="this.src='https://placehold.co/55x55?text=Pet';">
                                            <div>
                                                <a href="product_details.php?id=<?php echo $item['product_id']; ?>" class="fw-bold text-dark text-decoration-none">
                                                    <?php echo htmlspecialchars($item['title_en']); ?>
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <a href="seller_details.php?id=<?php echo $item['seller_id']; ?>" class="text-decoration-none text-muted">
                                            <?php echo htmlspecialchars($item['seller_fn'] . ' ' . $item['seller_ln']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo $site_settings['currency'] . number_format($item['price'], 2); ?></td>
                                    <td><?php echo $item['quantity']; ?></td>
                                    <td class="text-end fw-bold"><?php echo $site_settings['currency'] . number_format($item['price'] * $item['quantity'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="border-top-0">
                            <tr>
                                <td colspan="4" class="text-end text-muted pt-4">Subtotal</td>
                                <td class="text-end pt-4 fw-bold"><?php echo $site_settings['currency'] . number_format($order['subtotal'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end text-muted">Shipping Fee</td>
                                <td class="text-end fw-bold"><?php echo $site_settings['currency'] . number_format($order['shipping_fee'], 2); ?></td>
                            </tr>
                            <tr>
                                <td colspan="4" class="text-end fs-5 fw-bold text-primary">Grand Total</td>
                                <td class="text-end fs-5 fw-bold text-primary"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Timeline -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Order Timeline</h5>
            </div>
            <div class="card-body p-4">
                <ul class="list-unstyled mb-0 position-relative timeline-container ms-3 border-start border-2 border-primary border-opacity-25 pb-3">
                    <?php 
                    // Fetch audit logs once to populate both timeline timestamps and audit log table
                    $audit_stmt = $conn->prepare("
                        SELECT a.*, u.first_name, u.last_name, u.role 
                        FROM order_audits a 
                        LEFT JOIN users u ON a.user_id = u.id 
                        WHERE a.order_id = ? 
                        ORDER BY a.created_at DESC
                    ");
                    $audit_stmt->bind_param("i", $order_id);
                    $audit_stmt->execute();
                    $audit_res = $audit_stmt->get_result();
                    $audits_list = [];
                    while ($al_row = $audit_res->fetch_assoc()) {
                        $audits_list[] = $al_row;
                    }

                    // Helper: find chronological timestamp by action keyword(s)
                    $get_audit_time = function($keywords) use ($audits_list) {
                        if (!is_array($keywords)) {
                            $keywords = [$keywords];
                        }
                        // Search earliest to latest
                        foreach (array_reverse($audits_list) as $a) {
                            foreach ($keywords as $kw) {
                                if (stripos($a['action'], $kw) !== false) {
                                    return $a['created_at'];
                                }
                            }
                        }
                        return '';
                    };

                    // Determine steps based on live audit history and order statuses
                    $steps = [];

                    // 1. Order Placed
                    $steps[] = [
                        'title' => 'Order Placed',
                        'time' => $order['created_at'],
                        'done' => true
                    ];

                    // 2. Payment Received (Escrow)
                    $escrow_audit_time = $get_audit_time(['approved payment', 'placed in escrow']);
                    $escrow_done = !empty($escrow_audit_time) || in_array($order['payment_status'], ['held', 'payout_submitted', 'seller_payment_sent', 'seller_payment_received', 'released', 'refund_submitted', 'refund_sent', 'refund_received', 'refunded']);
                    $steps[] = [
                        'title' => 'Payment Received (Escrow)',
                        'time' => $escrow_audit_time ?: ($escrow_done ? $order['updated_at'] : ''),
                        'done' => $escrow_done
                    ];

                    // 3. Seller Accepted Order
                    $accepted_audit_time = $get_audit_time(['Order accepted by seller', 'accepted by seller', 'Order accepted']);
                    $accepted_done = !empty($accepted_audit_time) || in_array($order['order_status'], ['accepted', 'preparing', 'ready_for_shipment', 'out_for_delivery', 'delivered', 'completed']) || ($order['seller_delivered'] == 1) || ($order['buyer_received'] == 1);
                    $steps[] = [
                        'title' => 'Seller Accepted Order',
                        'time' => $accepted_audit_time,
                        'done' => $accepted_done
                    ];

                    // 4. Out for Delivery
                    $shipped_audit_time = $get_audit_time(['out_for_delivery', 'out for delivery']);
                    $shipped_done = !empty($shipped_audit_time) || in_array($order['order_status'], ['out_for_delivery', 'delivered', 'completed']) || ($order['seller_delivered'] == 1);
                    $steps[] = [
                        'title' => 'Out for Delivery',
                        'time' => $shipped_audit_time,
                        'done' => $shipped_done
                    ];

                    // 5. Delivered
                    $delivered_audit_time = $get_audit_time(['Seller marked delivered', 'Status changed to delivered', 'delivered']);
                    $delivered_done = !empty($delivered_audit_time) || in_array($order['order_status'], ['delivered', 'completed']) || ($order['seller_delivered'] == 1);
                    $steps[] = [
                        'title' => 'Delivered',
                        'time' => $delivered_audit_time,
                        'done' => $delivered_done
                    ];

                    // 6. Buyer Confirmed Receipt
                    $buyer_audit_time = $get_audit_time(['Buyer confirmed receipt', 'confirmed receipt']);
                    $buyer_done = !empty($buyer_audit_time) || ($order['buyer_received'] == 1) || ($order['order_status'] === 'completed');
                    $steps[] = [
                        'title' => 'Buyer Confirmed Receipt',
                        'time' => $buyer_audit_time,
                        'done' => $buyer_done
                    ];

                    // 7. Funds Released to Seller
                    $funds_audit_time = $get_audit_time(['sent payment to seller', 'confirmed payment receipt', 'Funds released']);
                    $funds_fallback_time = !empty($order['seller_payment_received_at']) ? $order['seller_payment_received_at'] : (!empty($order['seller_payment_sent_at']) ? $order['seller_payment_sent_at'] : (!empty($order['payment_released_at']) ? $order['payment_released_at'] : ''));
                    $funds_done = !empty($funds_audit_time) || in_array($order['payment_status'], ['seller_payment_sent', 'seller_payment_received', 'released']) || ($order['seller_payment_received'] == 1);
                    $steps[] = [
                        'title' => 'Funds Released to Seller',
                        'time' => $funds_audit_time ?: ($funds_done ? $funds_fallback_time : ''),
                        'done' => $funds_done
                    ];

                    // Optional: Cancelled
                    if ($order['order_status'] === 'cancelled' || in_array($order['payment_status'], ['refunded', 'refund_sent', 'refund_received'])) {
                        $cancel_audit_time = $get_audit_time(['refunded order', 'cancelled', 'refund']);
                        $steps[] = [
                            'title' => 'Order Cancelled / Refunded',
                            'time' => $cancel_audit_time ?: $order['updated_at'],
                            'done' => true,
                            'is_danger' => true
                        ];
                    }

                    foreach ($steps as $step):
                    ?>
                        <li class="position-relative ps-4 mb-4 <?php echo empty($step['done']) ? 'opacity-50' : ''; ?>">
                            <span class="position-absolute start-0 top-0 translate-middle p-2 rounded-circle <?php echo !empty($step['is_danger']) ? 'bg-danger' : (!empty($step['done']) ? 'bg-primary' : 'bg-secondary'); ?> border border-white border-3"></span>
                            <h6 class="fw-bold mb-1 <?php echo !empty($step['is_danger']) ? 'text-danger' : ''; ?>"><?php echo $step['title']; ?></h6>
                            <?php if (!empty($step['time']) && !empty($step['done'])): ?>
                                <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($step['time'])); ?></small>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <style>
                    .timeline-container li:last-child { margin-bottom: 0 !important; }
                </style>
            </div>
        </div>

        <!-- Audit Logs -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Audit Logs</h5>
            </div>
            <div class="card-body p-4">
                <?php if (!empty($audits_list)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle small mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($audits_list as $al): ?>
                                    <tr>
                                        <td class="text-nowrap text-muted"><?php echo date('M d, y H:i', strtotime($al['created_at'])); ?></td>
                                        <td class="fw-medium text-dark"><?php echo htmlspecialchars($al['action']); ?></td>
                                        <td>
                                            <?php if ($al['user_id']): ?>
                                                <?php echo htmlspecialchars($al['first_name'] . ' ' . $al['last_name']); ?>
                                                <span class="badge bg-secondary ms-1" style="font-size:0.6rem;"><?php echo $al['role']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">System</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted"><?php echo htmlspecialchars($al['details'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">No audit logs available for this order.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <!-- Buyer Details -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold text-muted mb-0">Buyer Information</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-light rounded-circle p-3 me-3 text-primary">
                        <i class="bi bi-person-fill fs-4"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-0"><?php echo htmlspecialchars($order['buyer_fn'] . ' ' . $order['buyer_ln']); ?></h6>
                        <small class="text-muted">Buyer ID: #<?php echo $order['user_id']; ?></small>
                    </div>
                </div>
                <hr>
                <ul class="list-unstyled mb-0 text-muted small">
                    <li class="mb-2"><i class="bi bi-envelope me-2"></i> <?php echo htmlspecialchars($order['buyer_email']); ?></li>
                    <li><i class="bi bi-telephone me-2"></i> <?php echo htmlspecialchars($order['buyer_phone'] ?? 'N/A'); ?></li>
                </ul>
                <div class="mt-3">
                    <a href="buyer_details.php?id=<?php echo $order['user_id']; ?>" class="btn btn-sm btn-outline-primary w-100 rounded-pill">View Buyer Profile</a>
                </div>
            </div>
        </div>

        <!-- Shipping Address -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold text-muted mb-0">Shipping Destination</h6>
            </div>
            <div class="card-body p-4">
                <div class="d-flex align-items-start mb-2">
                    <i class="bi bi-geo-alt text-primary me-2 mt-1"></i>
                    <div>
                        <h6 class="fw-bold mb-1"><?php echo htmlspecialchars($order['shipping_name'] ?? ($order['buyer_fn'] . ' ' . $order['buyer_ln'])); ?></h6>
                        <p class="text-muted small mb-0">
                            <?php 
                            if (!empty($order['shipping_address'])) {
                                echo nl2br(htmlspecialchars($order['shipping_address']));
                            } else {
                                echo "No address provided.";
                            }
                            ?>
                        </p>
                    </div>
                </div>
                <?php if (!empty($order['shipping_phone'])): ?>
                    <p class="text-muted small mb-0 ms-4"><i class="bi bi-telephone me-1"></i> <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Payment Details -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold text-muted mb-0">Payment Summary</h6>
            </div>
            <div class="card-body p-4">
                <ul class="list-unstyled mb-0 small">
                    <li class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Payment Method</span>
                        <span class="fw-bold text-dark"><?php echo strtoupper(str_replace('_', ' ', $order['payment_method'])); ?></span>
                    </li>
                    <li class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Transaction ID</span>
                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($order['transaction_id'] ?? 'N/A'); ?></span>
                    </li>
                    <li class="d-flex justify-content-between">
                        <span class="text-muted">Escrow Status</span>
                        <span class="fw-bold text-dark"><?php echo ucfirst(str_replace('_', ' ', $order['payment_status'])); ?></span>
                    </li>
                </ul>
            </div>
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
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
