<?php
/**
 * Admin Seller Details
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];
$seller_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$seller_id) {
    $_SESSION['error'] = "Invalid seller ID.";
    header('Location: sellers.php');
    exit;
}

// Fetch Seller Details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'seller' AND is_deleted = 0");
$stmt->bind_param("i", $seller_id);
$stmt->execute();
$seller = $stmt->get_result()->fetch_assoc();

if (!$seller) {
    $_SESSION['error'] = "Seller not found or has been deleted.";
    header('Location: sellers.php');
    exit;
}

// Fetch Seller Metrics
$metrics_stmt = $conn->prepare("
    SELECT 
        (SELECT COUNT(*) FROM products WHERE seller_id = ? AND is_deleted = 0) AS total_products,
        (SELECT COUNT(*) FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.seller_id = ?) AS total_orders,
        (SELECT COALESCE(SUM(grand_total), 0) FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.seller_id = ? AND o.payment_status = 'released') AS released_earnings,
        (SELECT COALESCE(SUM(grand_total), 0) FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.seller_id = ? AND o.payment_status = 'held') AS pending_earnings
");
$metrics_stmt->bind_param("iiii", $seller_id, $seller_id, $seller_id, $seller_id);
$metrics_stmt->execute();
$metrics = $metrics_stmt->get_result()->fetch_assoc();

// Fetch Seller Products (Limit 10 for preview)
$products_stmt = $conn->prepare("
    SELECT id, title_en, price, stock_quantity, status, created_at 
    FROM products 
    WHERE seller_id = ? AND is_deleted = 0 
    ORDER BY created_at DESC LIMIT 10
");
$products_stmt->bind_param("i", $seller_id);
$products_stmt->execute();
$products = $products_stmt->get_result();

// Fetch Seller Orders (Limit 10 for preview)
$orders_stmt = $conn->prepare("
    SELECT o.id, o.order_number, o.grand_total, o.payment_status, o.order_status, o.created_at 
    FROM orders o 
    JOIN order_items oi ON o.id = oi.order_id 
    WHERE oi.seller_id = ? 
    GROUP BY o.id
    ORDER BY o.created_at DESC LIMIT 10
");
$orders_stmt->bind_param("i", $seller_id);
$orders_stmt->execute();
$orders = $orders_stmt->get_result();

$page_title = "Seller Details - " . htmlspecialchars($seller['first_name'] . ' ' . $seller['last_name']);
$page_heading = "Seller Details";
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
    <a href="sellers.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Sellers</a>
</div>

<div class="row g-4">
    <!-- Seller Profile Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-4">
                <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($seller['profile_pic'] ?? 'default-user.png'); ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($seller['first_name'].' '.$seller['last_name']); ?>'" class="rounded-circle mb-3 border shadow-sm" style="width: 120px; height: 120px; object-fit: cover;">
                
                <h4 class="fw-bold mb-1">
                    <?php echo htmlspecialchars($seller['first_name'] . ' ' . $seller['last_name']); ?>
                    <?php if ($seller['is_verified']): ?>
                        <i class="bi bi-patch-check-fill text-primary" title="Verified Seller"></i>
                    <?php endif; ?>
                </h4>
                <p class="text-muted mb-3">Seller ID: #<?php echo $seller['id']; ?></p>
                
                <?php if ($seller['status'] === 'active'): ?>
                    <span class="badge bg-success rounded-pill px-3 py-2 mb-4">Active Account</span>
                <?php else: ?>
                    <span class="badge bg-danger rounded-pill px-3 py-2 mb-4"><?php echo ucfirst($seller['status']); ?></span>
                <?php endif; ?>

                <ul class="list-group list-group-flush text-start mb-4">
                    <li class="list-group-item px-0"><i class="bi bi-envelope text-muted me-2"></i> <?php echo htmlspecialchars($seller['email']); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-telephone text-muted me-2"></i> <?php echo htmlspecialchars($seller['phone'] ?? 'Not provided'); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-geo-alt text-muted me-2"></i> <?php echo htmlspecialchars($seller['address'] ?? 'Unknown Address'); ?><br><small class="text-muted ms-4"><?php echo htmlspecialchars($seller['city'] ?? ''); ?></small></li>
                    <li class="list-group-item px-0"><i class="bi bi-calendar-event text-muted me-2"></i> Joined: <?php echo date('M d, Y', strtotime($seller['created_at'])); ?></li>
                </ul>

                <div class="d-grid gap-2">
                    <?php if ($seller['is_verified']): ?>
                        <form action="sellers.php" method="POST">
                            <input type="hidden" name="action" value="unverify">
                            <input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>">
                            <button type="submit" class="btn btn-outline-secondary w-100 fw-bold rounded-pill mb-2">Remove Verification</button>
                        </form>
                    <?php else: ?>
                        <form action="sellers.php" method="POST">
                            <input type="hidden" name="action" value="verify">
                            <input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>">
                            <button type="submit" class="btn btn-outline-primary w-100 fw-bold rounded-pill mb-2">Verify Seller</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($seller['status'] === 'active'): ?>
                        <form action="sellers.php" method="POST">
                            <input type="hidden" name="action" value="suspend">
                            <input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>">
                            <button type="submit" class="btn btn-warning w-100 fw-bold rounded-pill" onclick="return confirm('Suspend this seller account?');">Suspend Account</button>
                        </form>
                    <?php else: ?>
                        <form action="sellers.php" method="POST">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="seller_id" value="<?php echo $seller['id']; ?>">
                            <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill">Activate Account</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Metrics & Listings -->
    <div class="col-lg-8">
        <!-- Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-md-3">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 text-center rounded-4">
                    <div class="text-secondary mb-2"><i class="bi bi-box-seam fs-2"></i></div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($metrics['total_products']); ?></h3>
                    <small class="text-muted">Products</small>
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="card border-0 shadow-sm bg-white p-3 h-100 text-center rounded-4">
                    <div class="text-primary mb-2"><i class="bi bi-cart fs-2"></i></div>
                    <h3 class="fw-bold mb-0"><?php echo number_format($metrics['total_orders']); ?></h3>
                    <small class="text-muted">Orders</small>
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="card border-0 shadow-sm bg-warning text-dark p-3 h-100 text-center rounded-4">
                    <div class="text-dark-50 mb-2"><i class="bi bi-hourglass-split fs-2"></i></div>
                    <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($metrics['pending_earnings'], 0); ?></h4>
                    <small class="text-dark-50">Pending Escrow</small>
                </div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="card border-0 shadow-sm bg-success text-white p-3 h-100 text-center rounded-4">
                    <div class="text-white-50 mb-2"><i class="bi bi-wallet2 fs-2"></i></div>
                    <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($metrics['released_earnings'], 0); ?></h4>
                    <small class="text-white-50">Released Earnings</small>
                </div>
            </div>
        </div>

        <!-- Recent Products Table -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4 d-flex justify-content-between">
                <h5 class="fw-bold mb-0">Recent Products</h5>
                <a href="products.php?q=<?php echo urlencode($seller['first_name']); ?>" class="btn btn-sm btn-outline-secondary rounded-pill">View All</a>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Product Name</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($products && $products->num_rows > 0): ?>
                                <?php while ($p = $products->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?php echo htmlspecialchars($p['title_en']); ?></td>
                                        <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></td>
                                        <td><?php echo $p['stock_quantity']; ?></td>
                                        <td>
                                            <?php
                                            $badge_class = 'bg-secondary';
                                            if ($p['status'] === 'active') $badge_class = 'bg-success';
                                            if ($p['status'] === 'pending') $badge_class = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> rounded-pill">
                                                <?php echo ucfirst($p['status']); ?>
                                            </span>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <a href="product_details.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">View</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No products listed by this seller.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Orders Table -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4 d-flex justify-content-between">
                <h5 class="fw-bold mb-0">Recent Orders (Seller View)</h5>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Order #</th>
                                <th>Date</th>
                                <th>Payment Status</th>
                                <th>Order Status</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders && $orders->num_rows > 0): ?>
                                <?php while ($o = $orders->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($o['order_number']); ?></td>
                                        <td><small class="text-muted"><?php echo date('d M Y', strtotime($o['created_at'])); ?></small></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo ucfirst(str_replace('_', ' ', $o['payment_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'bg-secondary';
                                            if ($o['order_status'] === 'completed' || $o['order_status'] === 'delivered') $badge_class = 'bg-success';
                                            if ($o['order_status'] === 'cancelled') $badge_class = 'bg-danger';
                                            if ($o['order_status'] === 'pending') $badge_class = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> rounded-pill">
                                                <?php echo ucfirst(str_replace('_', ' ', $o['order_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">View</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted">No orders found for this seller's products.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
