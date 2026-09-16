<?php
/**
 * Seller Dashboard â€” Phase 3.1
 * Shows real-time stats: products, orders, earnings
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user     = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];

// â”€â”€ Product Counts â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$prod_stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'active')  AS active,
        SUM(status = 'pending') AS pending,
        SUM(status = 'sold')    AS sold,
        SUM(status = 'inactive') AS inactive
     FROM products WHERE seller_id = ?"
);
$prod_stmt->bind_param('i', $seller_id);
$prod_stmt->execute();
$prod_counts = $prod_stmt->get_result()->fetch_assoc();

// â”€â”€ Order Counts & Earnings â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$order_stmt = $conn->prepare(
    "SELECT
        SUM(CASE WHEN o.order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        COALESCE(SUM(CASE WHEN o.payment_status = 'released' THEN oi.total ELSE 0 END), 0) AS total_earnings,
        COALESCE(SUM(CASE WHEN o.payment_status = 'held' THEN oi.total ELSE 0 END), 0) AS pending_escrow_payments,
        COALESCE(SUM(CASE WHEN o.payment_status = 'released' THEN oi.total ELSE 0 END), 0) AS released_payments
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     WHERE oi.seller_id = ? AND o.payment_status NOT IN ('pending', 'payment_submitted', 'rejected')"
);
$order_stmt->bind_param('i', $seller_id);
$order_stmt->execute();
$order_stats = $order_stmt->get_result()->fetch_assoc();


// â”€â”€ Recent Orders (last 5) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$recent_stmt = $conn->prepare(
    "SELECT o.id, o.order_number, o.order_status, o.created_at,
            u.first_name, u.last_name,
            SUM(oi.total) AS order_total
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     JOIN users  u ON o.user_id   = u.id
     WHERE oi.seller_id = ? AND o.payment_status NOT IN ('pending', 'payment_submitted', 'rejected')
     GROUP BY o.id
     ORDER BY o.created_at DESC
     LIMIT 5"
);
$recent_stmt->bind_param('i', $seller_id);
$recent_stmt->execute();
$recent_orders = $recent_stmt->get_result();

// â”€â”€ Last 5 Products â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$recent_prod_stmt = $conn->prepare(
    "SELECT p.id, p.title_en, p.price, p.status, p.created_at,
            c.name_en AS cat_name,
            (SELECT pi.image_url FROM product_images pi
             WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS thumb
     FROM products p
     JOIN categories c ON p.category_id = c.id
     WHERE p.seller_id = ?
     ORDER BY p.created_at DESC
     LIMIT 5"
);
$recent_prod_stmt->bind_param('i', $seller_id);
$recent_prod_stmt->execute();
$recent_products = $recent_prod_stmt->get_result();

include '../includes/header.php';

// Status badge helper
function status_badge($status) {
    $map = [
        'active'   => 'success',
        'pending'  => 'warning',
        'sold'     => 'secondary',
        'inactive' => 'danger',
        'accepted' => 'primary',
        'preparing' => 'info',
        'ready_for_shipment' => 'warning',
        'out_for_delivery' => 'primary',
        'delivered' => 'success',
        'completed'  => 'success',
        'cancelled' => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    $label = ($status === 'pending' && strpos($status, 'ord') === false) ? $status : $status;
    return '<span class="badge bg-'.$color.' rounded-pill px-3 py-1">'.ucfirst(htmlspecialchars($status)).'</span>';
}
?>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">

        <!-- â•â•â• Sidebar â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
        <div class="col-lg-2 d-none d-lg-block">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- â•â•â• Main Content â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
        <div class="col-lg-10">
            <?php display_messages(); ?>

            <!-- Page Title -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-speedometer2 text-warning me-2"></i>Seller Dashboard
                    </h3>
                    <p class="text-muted mb-0 small">
                        Welcome back, <strong><?php echo htmlspecialchars($user['first_name']); ?></strong>!
                        Here's what's happening today.
                    </p>
                </div>
                <a href="add_product.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i> Add New Product
                </a>
            </div>

            <!-- â”€â”€ Stat Cards â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
            <div class="row g-3 mb-4">
                <!-- Total Products -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-primary bg-opacity-10 text-primary rounded-circle mx-auto mb-2">
                                <i class="bi bi-box fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0"><?php echo (int)$prod_counts['total']; ?></h3>
                            <p class="text-muted small mb-0">Total Products</p>
                        </div>
                    </div>
                </div>
                <!-- Active -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-success bg-opacity-10 text-success rounded-circle mx-auto mb-2">
                                <i class="bi bi-check-circle fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0 text-success"><?php echo (int)$prod_counts['active']; ?></h3>
                            <p class="text-muted small mb-0">Active</p>
                        </div>
                    </div>
                </div>
                <!-- Pending -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-warning bg-opacity-10 text-warning rounded-circle mx-auto mb-2">
                                <i class="bi bi-hourglass-split fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0 text-warning"><?php echo (int)$prod_counts['pending']; ?></h3>
                            <p class="text-muted small mb-0">Pending</p>
                        </div>
                    </div>
                </div>
                <!-- Sold -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary rounded-circle mx-auto mb-2">
                                <i class="bi bi-bag-check fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0 text-secondary"><?php echo (int)$prod_counts['sold']; ?></h3>
                            <p class="text-muted small mb-0">Sold</p>
                        </div>
                    </div>
                </div>
                <!-- Completed Orders -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-info bg-opacity-10 text-info rounded-circle mx-auto mb-2">
                                <i class="bi bi-receipt fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0 text-info"><?php echo (int)$order_stats['completed_orders']; ?></h3>
                            <p class="text-muted small mb-0">Completed Orders</p>
                        </div>
                    </div>
                </div>
                <!-- Total Earnings -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary rounded-circle mx-auto mb-2">
                                <i class="bi bi-currency-dollar fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0">
                                <?php echo $site_settings['currency'] . number_format($order_stats['total_earnings'], 0); ?>
                            </h3>
                            <p class="text-muted small mb-0">Total Earnings</p>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Escrow Payments -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-warning text-dark stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-white bg-opacity-25 rounded-circle mx-auto mb-2">
                                <i class="bi bi-clock-history fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0">
                                <?php echo $site_settings['currency'] . number_format($order_stats['pending_escrow_payments'], 0); ?>
                            </h3>
                            <p class="text-dark-50 small mb-0">Pending Escrow Payments</p>
                        </div>
                    </div>
                </div>

                <!-- Released Payments -->
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-success text-white stat-card">
                        <div class="card-body p-3 text-center">
                            <div class="stat-icon bg-white bg-opacity-25 rounded-circle mx-auto mb-2">
                                <i class="bi bi-wallet2 fs-4"></i>
                            </div>
                            <h3 class="fw-bold mb-0">
                                <?php echo $site_settings['currency'] . number_format($order_stats['released_payments'], 0); ?>
                            </h3>
                            <p class="text-white-50 small mb-0">Released Payments</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- â”€â”€ Bottom Row: Recent Orders + Recent Products â”€â”€â”€ -->
            <div class="row g-4">

                <!-- Recent Orders -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-4 pb-0 px-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-bag me-2 text-success"></i>Recent Orders</h5>
                            <a href="orders.php" class="btn btn-sm btn-outline-success rounded-pill">View All</a>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($recent_orders->num_rows > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle small mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Order #</th>
                                            <th>Customer</th>
                                            <th>Amount</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $recent_orders->fetch_assoc()): ?>
                                        <tr>
                                            <td class="fw-bold text-success">
                                                <a href="orders.php" class="text-success text-decoration-none">
                                                    #<?php echo htmlspecialchars($row['order_number']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?></td>
                                            <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($row['order_total'], 2); ?></td>
                                            <td><?php echo status_badge($row['order_status']); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-bag-x fs-2 d-block mb-2"></i>
                                No orders yet. <a href="add_product.php" class="text-success">Add products</a> to start selling.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Products -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center pt-4 pb-0 px-4">
                            <h5 class="fw-bold mb-0"><i class="bi bi-box me-2 text-warning"></i>Latest Products</h5>
                            <a href="products.php" class="btn btn-sm btn-outline-warning rounded-pill">View All</a>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($recent_products->num_rows > 0): ?>
                            <ul class="list-group list-group-flush">
                                <?php while ($p = $recent_products->fetch_assoc()): ?>
                                <li class="list-group-item px-0 d-flex align-items-center gap-3">
                                    <?php if ($p['thumb']): ?>
                                        <img src="<?php echo BASE_URL; ?>/assets/uploads/products/<?php echo htmlspecialchars($p['thumb']); ?>"
                                             class="rounded-3 object-fit-cover"
                                             style="width:48px;height:48px;"
                                             alt="thumb">
                                    <?php else: ?>
                                        <div class="bg-light rounded-3 d-flex align-items-center justify-content-center text-muted"
                                             style="width:48px;height:48px;">
                                            <i class="bi bi-image"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <p class="mb-0 fw-medium text-truncate">
                                            <?php echo htmlspecialchars($p['title_en']); ?>
                                        </p>
                                        <small class="text-muted"><?php echo htmlspecialchars($p['cat_name']); ?></small>
                                    </div>
                                    <div class="text-end text-nowrap">
                                        <p class="mb-0 fw-bold text-success small">
                                            <?php echo $site_settings['currency'] . number_format($p['price'], 2); ?>
                                        </p>
                                        <?php echo status_badge($p['status']); ?>
                                    </div>
                                </li>
                                <?php endwhile; ?>
                            </ul>
                            <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-box-seam fs-2 d-block mb-2"></i>
                                No products yet. <a href="add_product.php" class="text-warning">Add your first listing.</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div><!-- /row -->
        </div><!-- /col-lg-10 -->
    </div><!-- /row -->
</div>

<style>
.stat-icon {
    width: 52px; height: 52px;
    display: flex; align-items: center; justify-content: center;
}
.stat-card { transition: transform .2s, box-shadow .2s; }
.stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.08) !important; }
</style>

<?php include '../includes/footer.php'; ?>


