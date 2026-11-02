<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require user login and user role
require_role('user');

$user = current_user($conn);
$user_id = $user['id'];

// 1. Fetch User Metrics (Accurate Real-Time Breakdown)
$stmt_metrics = $conn->prepare("
    SELECT 
        COUNT(*) AS total_buy_requests,
        COALESCE(SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_purchases,
        COALESCE(SUM(CASE WHEN order_status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END), 0) AS active_orders,
        COALESCE(SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_orders,
        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.user_id = ? AND o.order_status = 'completed') AS pets_purchased,
        COALESCE(SUM(CASE WHEN order_status = 'completed' THEN grand_total ELSE 0 END), 0) AS total_purchase_amount,
        COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent','refund_received') OR order_status = 'cancelled' THEN COALESCE(buyer_refund_amount, 0) ELSE 0 END), 0) AS refunded_amount,
        COALESCE(SUM(CASE WHEN payment_status = 'payment_submitted' THEN grand_total ELSE 0 END), 0) AS pending_payments
    FROM orders WHERE user_id = ?
");
$stmt_metrics->bind_param("ii", $user_id, $user_id);
$stmt_metrics->execute();
$metrics = $stmt_metrics->get_result()->fetch_assoc();

// 2. Fetch Wishlist Items
$stmt_wl = $conn->prepare("SELECT COUNT(id) as total_wishlist FROM wishlists WHERE user_id = ?");
$stmt_wl->bind_param("i", $user_id);
$stmt_wl->execute();
$total_wishlist = $stmt_wl->get_result()->fetch_assoc()['total_wishlist'];

// 3. Fetch Pending Reviews
$stmt_pr = $conn->prepare("
    SELECT COUNT(oi.id) as pending_reviews 
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    WHERE o.user_id = ? 
      AND o.order_status = 'delivered' 
      AND NOT EXISTS (
          SELECT 1 FROM reviews r WHERE r.product_id = oi.product_id AND r.user_id = o.user_id
      )
");
$stmt_pr->bind_param("i", $user_id);
$stmt_pr->execute();
$pending_reviews = $stmt_pr->get_result()->fetch_assoc()['pending_reviews'];

// 4. Fetch Recent Orders with Product Information
$stmt_ro = $conn->prepare("
    SELECT o.id, o.order_number, o.created_at, o.order_status, o.grand_total, o.payment_method, o.payment_status,
           p.title_en AS product_title,
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image
    FROM orders o 
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ? 
    GROUP BY o.id
    ORDER BY o.created_at DESC 
    LIMIT 5
");
$stmt_ro->bind_param("i", $user_id);
$stmt_ro->execute();
$recent_orders = $stmt_ro->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $user['profile_pic']; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" alt="Profile Picture" class="rounded-circle mb-3 border border-3 border-success" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                    <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="account.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-credit-card-2-back me-2"></i> Payment Account</a>
                    <a href="refunds.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-arrow-return-left me-2"></i> My Refunds</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <?php
                        $buyer_notif_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
                        $buyer_notif_stmt->bind_param("i", $user_id);
                        $buyer_notif_stmt->execute();
                        $buyer_unread = $buyer_notif_stmt->get_result()->fetch_assoc()['c'];
                    ?>
                    <a href="../notifications.php" class="list-group-item list-group-item-action py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bell me-2"></i> Notifications</span>
                        <?php if ($buyer_unread > 0): ?><span class="badge bg-danger rounded-pill"><?php echo $buyer_unread; ?></span><?php endif; ?>
                    </a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>
            
            <!-- Page Title -->
            <div class="mb-4">
                <h3 class="fw-bold mb-1">
                    Buyer Dashboard
                </h3>
                <p class="text-muted mb-0 small">
                    Welcome back, <strong><?php echo htmlspecialchars($user['first_name']); ?></strong>!
                    Here is an overview of your purchases and activity.
                </p>
            </div>

            <!-- Row 1: Order Lifecycle Stats -->
            <div class="row g-3 mb-4">
                <!-- Total Orders -->
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-2 text-center bg-white border-start border-4 border-secondary stat-card">
                        <div class="text-secondary mb-2"><i class="bi bi-cart3 fs-3"></i></div>
                        <h4 class="fw-bold mb-0"><?php echo number_format($metrics['total_buy_requests'] ?? 0); ?></h4>
                        <span class="text-muted small">Total Orders Placed</span>
                    </div>
                </div>
                <!-- Completed Purchases -->
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-2 text-center bg-white border-start border-4 border-success stat-card">
                        <div class="text-success mb-2"><i class="bi bi-check-circle fs-3"></i></div>
                        <h4 class="fw-bold mb-0 text-success"><?php echo number_format($metrics['completed_purchases'] ?? 0); ?></h4>
                        <span class="text-muted small">Completed Purchases</span>
                    </div>
                </div>
                <!-- Active Orders -->
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-2 text-center bg-white border-start border-4 border-primary stat-card">
                        <div class="text-primary mb-2"><i class="bi bi-box-seam fs-3"></i></div>
                        <h4 class="fw-bold mb-0 text-primary"><?php echo number_format($metrics['active_orders'] ?? 0); ?></h4>
                        <span class="text-muted small">Active Orders</span>
                    </div>
                </div>
                <!-- Cancelled Orders -->
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-2 text-center bg-white border-start border-4 border-danger stat-card">
                        <div class="text-danger mb-2"><i class="bi bi-x-circle fs-3"></i></div>
                        <h4 class="fw-bold mb-0 text-danger"><?php echo number_format($metrics['cancelled_orders'] ?? 0); ?></h4>
                        <span class="text-muted small">Cancelled Orders</span>
                    </div>
                </div>
            </div>

            <!-- Row 2: Shopping & Activity -->
            <div class="row g-3 mb-4">
                <!-- Pets Purchased -->
                <div class="col-12 col-md-4">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-3 d-flex flex-row align-items-center bg-white border-start border-4 border-info stat-card">
                        <div class="bg-info bg-opacity-10 rounded-circle p-3 me-3 text-info"><i class="bi bi-emoji-heart-eyes fs-3"></i></div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark"><?php echo number_format($metrics['pets_purchased'] ?? 0); ?></h4>
                            <span class="text-muted small">Pets Purchased</span>
                        </div>
                    </div>
                </div>
                <!-- Wishlist Items -->
                <div class="col-6 col-md-4">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-3 d-flex flex-row align-items-center bg-white border-start border-4 border-danger stat-card">
                        <div class="bg-danger bg-opacity-10 rounded-circle p-3 me-3 text-danger"><i class="bi bi-heart fs-3"></i></div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark"><?php echo number_format($total_wishlist); ?></h4>
                            <span class="text-muted small">Wishlist Items</span>
                        </div>
                    </div>
                </div>
                <!-- Pending Reviews -->
                <div class="col-6 col-md-4">
                    <div class="card border-0 shadow-sm h-100 rounded-4 py-3 px-3 d-flex flex-row align-items-center bg-white border-start border-4 border-warning stat-card">
                        <div class="bg-warning bg-opacity-10 rounded-circle p-3 me-3 text-warning"><i class="bi bi-star-half fs-3"></i></div>
                        <div>
                            <h4 class="fw-bold mb-0 text-dark"><?php echo number_format($pending_reviews); ?></h4>
                            <span class="text-muted small">Pending Reviews</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Row 3: Payments & Refunds Summary -->
            <div class="row g-3 mb-4">
                <!-- Total Purchase Amount -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-success text-white p-3 h-100 rounded-4 text-center stat-card">
                        <h6 class="text-white-50 mb-1">Total Purchase Amount</h6>
                        <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs') . number_format($metrics['total_purchase_amount'] ?? 0, 0); ?></h4>
                        <small class="text-white-50" style="font-size:0.75rem;">Completed Orders</small>
                    </div>
                </div>
                <!-- Refunded Amount -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-danger text-white p-3 h-100 rounded-4 text-center stat-card">
                        <h6 class="text-white-50 mb-1">Refunded Amount</h6>
                        <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs') . number_format($metrics['refunded_amount'] ?? 0, 0); ?></h4>
                        <small class="text-white-50" style="font-size:0.75rem;">Returned Funds</small>
                    </div>
                </div>
                <!-- Pending Payments -->
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm bg-warning text-dark p-3 h-100 rounded-4 text-center stat-card">
                        <h6 class="text-dark-50 mb-1">Pending Payment Verification</h6>
                        <h4 class="fw-bold mb-0"><?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs') . number_format($metrics['pending_payments'] ?? 0, 0); ?></h4>
                        <small class="text-dark-50" style="font-size:0.75rem;">Awaiting Admin Approval</small>
                    </div>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">Recent Orders</h5>
                    <a href="orders.php" class="btn btn-sm btn-link text-success text-decoration-none">View All</a>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Product</th>
                                    <th>Date</th>
                                    <th>Order Status</th>
                                    <th>Payment</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_orders)): ?>
                                    <?php foreach ($recent_orders as $order): 
                                        $badge_class = 'bg-secondary bg-opacity-10 text-secondary';
                                        switch ($order['order_status']) {
                                            case 'pending_payment':
                                            case 'pending': $badge_class = 'bg-warning bg-opacity-10 text-warning'; break;
                                            case 'accepted': $badge_class = 'bg-primary bg-opacity-10 text-primary'; break;
                                            case 'preparing': 
                                            case 'ready_for_shipment': $badge_class = 'bg-info bg-opacity-10 text-info'; break;
                                            case 'out_for_delivery': $badge_class = 'bg-primary bg-opacity-10 text-primary'; break;
                                            case 'delivered': 
                                            case 'completed': $badge_class = 'bg-success bg-opacity-10 text-success'; break;
                                            case 'cancelled': $badge_class = 'bg-danger bg-opacity-10 text-danger'; break;
                                        }

                                        $ps = $order['payment_status'];
                                        if ($ps === 'pending') {
                                            $ps_label = 'Pending Payment';
                                            $ps_cls   = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                        } elseif ($ps === 'payment_submitted') {
                                            $ps_label = 'Payment Submitted';
                                            $ps_cls   = 'bg-info bg-opacity-10 text-info border border-info';
                                        } elseif (in_array($ps, ['held', 'seller_payment_sent', 'seller_payment_received', 'released', 'payout_submitted'])) {
                                            $ps_label = 'Payment Delivered';
                                            $ps_cls   = 'bg-success bg-opacity-10 text-success border border-success';
                                        } elseif ($ps === 'rejected') {
                                            $ps_label = 'Payment Rejected';
                                            $ps_cls   = 'bg-danger bg-opacity-10 text-danger border border-danger';
                                        } elseif (in_array($ps, ['refund_submitted', 'refund_sent', 'refund_received', 'refunded'])) {
                                            $ps_label = 'Payment Refunded';
                                            $ps_cls   = 'bg-secondary bg-opacity-10 text-secondary border border-secondary';
                                        } else {
                                            $ps_label = 'Pending Payment';
                                            $ps_cls   = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                        }

                                        $ro_img = !empty($order['product_image']) 
                                            ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($order['product_image']) 
                                            : 'https://placehold.co/40x40?text=Pet';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-dark">#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="<?php echo $ro_img; ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 40px; height: 40px;" onerror="this.src='https://placehold.co/40x40?text=Pet';">
                                                <span class="small fw-semibold text-dark text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($order['product_title'] ?? ''); ?>">
                                                    <?php echo htmlspecialchars($order['product_title'] ?? 'Product'); ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?> px-2 py-1 rounded-pill fw-semibold"><?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $ps_cls; ?> px-2 py-1 rounded-pill" style="font-size: 0.7rem;"><?php echo $ps_label; ?></span>
                                        </td>
                                        <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></td>
                                        <td><a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">View</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center py-4 text-muted">You have not placed any orders yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
