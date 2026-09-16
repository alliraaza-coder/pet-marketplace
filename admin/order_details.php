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

// Handle Refund Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refund') {
    $conn->begin_transaction();
    try {
        // Fetch order to verify state
        $chk_stmt = $conn->prepare("SELECT payment_status FROM orders WHERE id = ?");
        $chk_stmt->bind_param("i", $order_id);
        $chk_stmt->execute();
        $chk = $chk_stmt->get_result()->fetch_assoc();

        if ($chk['payment_status'] === 'released') {
            throw new Exception("Cannot refund an order that has already been released to the seller.");
        }
        if ($chk['payment_status'] === 'refunded') {
            throw new Exception("Order is already refunded.");
        }

        // Refund Buyer -> payment_status = refunded, order_status = cancelled
        $upd_stmt = $conn->prepare("UPDATE orders SET payment_status = 'refunded', order_status = 'cancelled' WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        $upd_stmt->execute();

        // Restore Stock
        $items_stmt = $conn->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
        $items_stmt->bind_param("i", $order_id);
        $items_stmt->execute();
        $items_res = $items_stmt->get_result();
        $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold', 'active', status) WHERE id = ?");
        while ($item = $items_res->fetch_assoc()) {
            $stock_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
            $stock_stmt->execute();
        }

        // Record Transaction
        $amount_stmt = $conn->prepare("SELECT grand_total FROM orders WHERE id = ?");
        $amount_stmt->bind_param("i", $order_id);
        $amount_stmt->execute();
        $order_amount = $amount_stmt->get_result()->fetch_assoc()['grand_total'];

        $tx_stmt = $conn->prepare("INSERT INTO transactions (order_id, transaction_type, amount, status) VALUES (?, 'refund', ?, 'completed')");
        $tx_stmt->bind_param("id", $order_id, $order_amount);
        $tx_stmt->execute();

        log_admin_activity($conn, $admin_id, "Refunded Order", "Refunded Order ID: $order_id. Amount: $order_amount");
        
        // Add order audit log
        if (function_exists('log_order_audit')) {
            log_order_audit($conn, $order_id, "Admin refunded order", "Escrow closed. Amount: $order_amount", $admin_id);
        }
        
        $conn->commit();
        $_SESSION['success'] = "Order has been refunded to the buyer and cancelled.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Refund failed: " . $e->getMessage();
    }
    header("Location: order_details.php?id=$order_id");
    exit;
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
                                                $item_img = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="50" height="50"><rect width="50" height="50" fill="%23f3f3f3"/><text x="50%" y="50%" font-family="sans-serif" font-size="7" fill="%23aaa" text-anchor="middle" dy=".3em">No Image</text></svg>';
                                            }
                                            ?>
                                            <img src="<?php echo $item_img; ?>" class="rounded me-3 object-fit-cover" width="50" height="50" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'50\' height=\'50\'><rect width=\'50\' height=\'50\' fill=\'%23f3f3f3\'/><text x=\'50%25\' y=\'50%25\' font-family=\'sans-serif\' font-size=\'7\' fill=\'%23aaa\' text-anchor=\'middle\' dy=\'.3em\'>No Image</text></svg>'">
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
                    // Determine steps based on statuses
                    $steps = [];
                    $steps[] = ['title' => 'Order Placed', 'time' => $order['created_at'], 'done' => true];
                    
                    $paid = ($order['payment_status'] !== 'pending');
                    $steps[] = ['title' => 'Payment Received (Escrow)', 'time' => $paid ? $order['updated_at'] : '', 'done' => $paid];
                    
                    $accepted = in_array($order['order_status'], ['accepted', 'shipped', 'delivered', 'completed']);
                    $steps[] = ['title' => 'Seller Accepted Order', 'time' => '', 'done' => $accepted];

                    $shipped = in_array($order['order_status'], ['shipped', 'delivered', 'completed']);
                    $steps[] = ['title' => 'Out for Delivery', 'time' => '', 'done' => $shipped];

                    $delivered = in_array($order['order_status'], ['delivered', 'completed']);
                    $steps[] = ['title' => 'Delivered', 'time' => '', 'done' => $delivered];

                    $completed = ($order['order_status'] === 'completed');
                    $steps[] = ['title' => 'Buyer Confirmed Receipt', 'time' => '', 'done' => $completed];

                    $released = ($order['payment_status'] === 'released');
                    $steps[] = ['title' => 'Funds Released to Seller', 'time' => '', 'done' => $released];

                    if ($order['order_status'] === 'cancelled') {
                        $steps[] = ['title' => 'Order Cancelled / Refunded', 'time' => $order['updated_at'], 'done' => true, 'is_danger' => true];
                    }

                    foreach ($steps as $step):
                    ?>
                        <li class="position-relative ps-4 mb-4 <?php echo empty($step['done']) ? 'opacity-50' : ''; ?>">
                            <span class="position-absolute start-0 top-0 translate-middle p-2 rounded-circle <?php echo !empty($step['is_danger']) ? 'bg-danger' : (!empty($step['done']) ? 'bg-primary' : 'bg-secondary'); ?> border border-white border-3"></span>
                            <h6 class="fw-bold mb-1 <?php echo !empty($step['is_danger']) ? 'text-danger' : ''; ?>"><?php echo $step['title']; ?></h6>
                            <?php if (!empty($step['time'])): ?>
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
                <?php
                $audit_stmt = $conn->prepare("
                    SELECT a.*, u.first_name, u.last_name, u.role 
                    FROM order_audits a 
                    LEFT JOIN users u ON a.user_id = u.id 
                    WHERE a.order_id = ? 
                    ORDER BY a.created_at DESC
                ");
                $audit_stmt->bind_param("i", $order_id);
                $audit_stmt->execute();
                $audits = $audit_stmt->get_result();
                
                if ($audits->num_rows > 0):
                ?>
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
                                <?php while($al = $audits->fetch_assoc()): ?>
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
                                <?php endwhile; ?>
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

<?php include __DIR__ . '/partials/footer.php'; ?>
