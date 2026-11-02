<?php
/**
 * Admin Dashboard - Real-time statistics & visual analytics
 * Phase 3.3
 */
$page_title = "Admin Dashboard";
$page_heading = "Dashboard Overview";
include __DIR__ . '/partials/header.php';

// ── 1. Real-time Metrics Queries ────────────────────────────
// Users counts
$u_res = $conn->query("
    SELECT 
        COUNT(*) AS total_users,
        SUM(role = 'user') AS total_buyers,
        SUM(role = 'seller') AS total_sellers
    FROM users WHERE is_deleted = 0
")->fetch_assoc();

// Products counts
$p_res = $conn->query("
    SELECT 
        COUNT(*) AS total_products,
        SUM(status = 'active') AS active_products,
        SUM(status = 'pending') AS pending_products
    FROM products WHERE is_deleted = 0
")->fetch_assoc();

// Orders counts & Products Sold (Real-time Accurate)
$o_res = $conn->query("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(order_status = 'completed'), 0) AS completed_orders,
        COALESCE(SUM(order_status NOT IN ('completed', 'cancelled')), 0) AS active_orders,
        COALESCE(SUM(order_status = 'cancelled'), 0) AS cancelled_orders,
        COALESCE(SUM(payment_status = 'payment_submitted'), 0) AS pending_payment_requests,
        COALESCE(SUM(payment_status NOT IN ('pending','payment_submitted','rejected') AND order_status != 'cancelled'), 0) AS approved_orders,
        (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.order_status = 'completed') AS total_products_sold
    FROM orders
")->fetch_assoc();

// Financial counts Phase 8 Accurate
$f_res = $conn->query("
    SELECT 
        COALESCE(SUM(CASE WHEN payment_status NOT IN ('pending','payment_submitted','rejected') AND order_status != 'cancelled' THEN grand_total ELSE 0 END), 0) AS total_payments_received,
        COALESCE(SUM(CASE WHEN payment_status IN ('seller_payment_sent', 'seller_payment_received') THEN seller_payment_amount ELSE 0 END), 0) AS total_paid_to_sellers,
        COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent', 'refund_received') OR order_status = 'cancelled' THEN buyer_refund_amount ELSE 0 END), 0) AS total_refunds_sent,
        COALESCE(SUM(CASE WHEN payment_status = 'payment_submitted' THEN grand_total ELSE 0 END), 0) AS pending_buyer_payments,
        COALESCE(SUM(CASE WHEN payment_status = 'held' AND seller_delivered=1 AND buyer_received=1 AND admin_verified=1 AND seller_payment_sent_at IS NULL THEN seller_payment_amount ELSE (CASE WHEN payment_status='held' AND order_status != 'cancelled' THEN grand_total ELSE 0 END) END), 0) AS pending_seller_payments,
        COALESCE(SUM(CASE WHEN payment_status IN ('held','refund_submitted') AND order_status='cancelled' AND buyer_refund_sent_at IS NULL THEN buyer_refund_amount ELSE (CASE WHEN order_status='cancelled' AND buyer_refund_sent_at IS NULL THEN grand_total ELSE 0 END) END), 0) AS pending_refunds
    FROM orders
")->fetch_assoc();

$admin_held_balance = max(0, $f_res['total_payments_received'] - $f_res['total_paid_to_sellers'] - $f_res['total_refunds_sent']);

// ── 2. Chart Data (Monthly Revenue & Orders last 6 months) ───
$chart_months = [];
$chart_revenue = [];
$chart_orders = [];
$chart_users = [];

for ($i = 5; $i >= 0; $i--) {
    $m_start = date('Y-m-01 00:00:00', strtotime("-$i months"));
    $m_end   = date('Y-m-t 23:59:59', strtotime("-$i months"));
    $m_label = date('M Y', strtotime("-$i months"));
    $chart_months[] = $m_label;

    // Monthly revenue (completed orders)
    $rev_stmt = $conn->prepare("SELECT COALESCE(SUM(grand_total), 0) AS rev FROM orders WHERE order_status = 'completed' AND created_at BETWEEN ? AND ?");
    $rev_stmt->bind_param("ss", $m_start, $m_end);
    $rev_stmt->execute();
    $chart_revenue[] = (float)$rev_stmt->get_result()->fetch_assoc()['rev'];

    // Monthly orders
    $ord_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM orders WHERE created_at BETWEEN ? AND ?");
    $ord_stmt->bind_param("ss", $m_start, $m_end);
    $ord_stmt->execute();
    $chart_orders[] = (int)$ord_stmt->get_result()->fetch_assoc()['cnt'];

    // Monthly users
    $usr_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE created_at BETWEEN ? AND ? AND is_deleted = 0");
    $usr_stmt->bind_param("ss", $m_start, $m_end);
    $usr_stmt->execute();
    $chart_users[] = (int)$usr_stmt->get_result()->fetch_assoc()['cnt'];
}

// ── 3. Recent Activity Lists ─────────────────────────────────
$recent_orders = $conn->query("
    SELECT o.id, o.order_number, o.grand_total, o.order_status, o.payment_status, o.created_at, u.first_name, u.last_name,
           (SELECT p.title_en FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = o.id LIMIT 1) AS product_title,
           (SELECT pi.image_url FROM order_items oi JOIN products p ON oi.product_id = p.id JOIN product_images pi ON pi.product_id = p.id WHERE oi.order_id = o.id AND pi.is_primary = 1 LIMIT 1) AS product_thumb
    FROM orders o JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC LIMIT 5
");

$recent_users = $conn->query("
    SELECT id, first_name, last_name, email, role, status, created_at
    FROM users WHERE is_deleted = 0
    ORDER BY created_at DESC LIMIT 5
");

$recent_products = $conn->query("
    SELECT p.id, p.title_en, p.price, p.status, p.created_at, u.first_name, u.last_name
    FROM products p JOIN users u ON p.seller_id = u.id WHERE p.is_deleted = 0
    ORDER BY p.created_at DESC LIMIT 5
");

$recent_transactions = $conn->query("
    SELECT t.*, o.order_number, u.first_name, u.last_name
    FROM transactions t
    JOIN orders o ON t.order_id = o.id
    JOIN users u ON t.user_id = u.id
    ORDER BY t.created_at DESC LIMIT 5
");
?>

<!-- Phase 8: Financial & Marketplace Stat Cards -->
<div class="row g-3 mb-4">
    <!-- Admin Held Balance (Prominent) -->
    <div class="col-12 col-md-4">
        <div class="card border-0 shadow-sm stat-card bg-success text-white p-3 h-100">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-white-50 small text-uppercase fw-bold">Admin Held Balance</span>
                    <h3 class="fw-bold mb-0 mt-1"><?php echo $site_settings['currency'] . number_format($admin_held_balance ?? 0, 2); ?></h3>
                    <small class="text-white-50">(Gross Received - Paid - Refunded)</small>
                </div>
                <div class="text-white-50"><i class="bi bi-safe fs-1"></i></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-8">
        <div class="row g-3">
            <div class="col-6 col-md-4">
                <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100">
                    <div class="text-success mb-1"><i class="bi bi-box-arrow-in-down fs-4"></i></div>
                    <h5 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['total_payments_received'] ?? 0, 0); ?></h5>
                    <span class="text-muted small">Total Buyer Payments</span>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100">
                    <div class="text-primary mb-1"><i class="bi bi-box-arrow-up fs-4"></i></div>
                    <h5 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['total_paid_to_sellers'] ?? 0, 0); ?></h5>
                    <span class="text-muted small">Total Paid to Sellers</span>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100">
                    <div class="text-danger mb-1"><i class="bi bi-arrow-counterclockwise fs-4"></i></div>
                    <h5 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['total_refunds_sent'] ?? 0, 0); ?></h5>
                    <span class="text-muted small">Total Refunds Sent</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Real-time Orders Lifecycle Overview Row -->
<div class="row g-3 mb-4">
    <!-- Total Orders Placed -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-primary">
            <div class="stat-icon text-primary mb-1"><i class="bi bi-receipt fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-primary"><?php echo number_format($o_res['total_orders'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Total Orders Placed</span>
        </div>
    </div>
    <!-- Completed Orders -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-success">
            <div class="stat-icon text-success mb-1"><i class="bi bi-check2-circle fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-success"><?php echo number_format($o_res['completed_orders'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Completed Orders</span>
        </div>
    </div>
    <!-- Active Orders -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-info">
            <div class="stat-icon text-info mb-1"><i class="bi bi-hourglass-split fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-info"><?php echo number_format($o_res['active_orders'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Active Orders</span>
        </div>
    </div>
    <!-- Cancelled Orders -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-danger">
            <div class="stat-icon text-danger mb-1"><i class="bi bi-x-circle fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-danger"><?php echo number_format($o_res['cancelled_orders'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Cancelled Orders</span>
        </div>
    </div>
    <!-- Products Sold -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-dark">
            <div class="stat-icon text-dark mb-1"><i class="bi bi-bag-check fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-dark"><?php echo number_format($o_res['total_products_sold'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Products Sold</span>
        </div>
    </div>
    <!-- Pending Approvals -->
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-warning">
            <div class="stat-icon text-warning mb-1"><i class="bi bi-shield-exclamation fs-4"></i></div>
            <h4 class="fw-bold mb-0 text-warning"><?php echo number_format($o_res['pending_payment_requests'] ?? 0); ?></h4>
            <span class="text-muted small fw-semibold">Pending Approvals</span>
        </div>
    </div>
</div>

<!-- Secondary Platform & Payouts Overview -->
<div class="row g-3 mb-4">
    <!-- Pending Payouts -->
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-warning">
            <h5 class="fw-bold mb-0 mt-2 text-warning"><?php echo $site_settings['currency'] . number_format($f_res['pending_seller_payments'] ?? 0, 0); ?></h5>
            <span class="text-muted small">Pending Payouts</span>
        </div>
    </div>
    <!-- Pending Refunds -->
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-danger">
            <h5 class="fw-bold mb-0 mt-2 text-danger"><?php echo $site_settings['currency'] . number_format($f_res['pending_refunds'] ?? 0, 0); ?></h5>
            <span class="text-muted small">Pending Refunds</span>
        </div>
    </div>
    <!-- Users -->
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-primary">
            <h5 class="fw-bold mb-0 mt-2 text-primary"><?php echo number_format($u_res['total_buyers'] ?? 0); ?> / <?php echo number_format($u_res['total_sellers'] ?? 0); ?></h5>
            <span class="text-muted small">Total Buyers / Sellers</span>
        </div>
    </div>
    <!-- Total Products -->
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-secondary">
            <h5 class="fw-bold mb-0 mt-2"><?php echo number_format($p_res['total_products'] ?? 0); ?></h5>
            <span class="text-muted small">Total Products</span>
        </div>
    </div>
    <!-- Active Products -->
    <div class="col-6 col-md-4 col-xl">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center h-100 border-start border-4 border-success">
            <h5 class="fw-bold mb-0 mt-2 text-success"><?php echo number_format($p_res['active_products'] ?? 0); ?></h5>
            <span class="text-muted small">Active Products</span>
        </div>
    </div>
</div>

<!-- ── 4 Interactive Charts ─────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-4 h-100">
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-graph-up text-success me-2"></i>Monthly Revenue (Last 6 Months)</h6>
            <canvas id="revenueChart" style="max-height:260px;"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-4 h-100">
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-cart-dash text-primary me-2"></i>Orders Growth</h6>
            <canvas id="ordersChart" style="max-height:260px;"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-4 h-100">
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-people text-info me-2"></i>User Signups Trend</h6>
            <canvas id="usersChart" style="max-height:260px;"></canvas>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-4 h-100">
            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-pie-chart text-warning me-2"></i>Escrow Balance Breakdown</h6>
            <canvas id="escrowChart" style="max-height:260px;"></canvas>
        </div>
    </div>
</div>

<!-- ── Recent Activity Tables ───────────────────────────────── -->
<div class="row g-4">
    <!-- Recent Orders -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3 px-2 pt-2">
                <h6 class="fw-bold mb-0"><i class="bi bi-bag me-2 text-primary"></i>Recent Orders</h6>
                <a href="orders.php" class="btn btn-sm btn-outline-primary rounded-pill">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Order #</th>
                            <th>Product</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
                            <?php while ($o = $recent_orders->fetch_assoc()): 
                                $p_img = !empty($o['product_thumb']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($o['product_thumb']) : 'https://placehold.co/36x36?text=Pet';
                                
                                // Payment badge logic
                                $ps = $o['payment_status'];
                                $ps_label = ucfirst(str_replace('_', ' ', $ps));
                                $ps_badge = 'bg-secondary bg-opacity-10 text-secondary border border-secondary';
                                if (in_array($ps, ['seller_payment_received', 'released', 'seller_payment_sent', 'held'])) {
                                    $ps_label = 'Payment Delivered';
                                    $ps_badge = 'bg-success bg-opacity-10 text-success border border-success';
                                } elseif (in_array($ps, ['refund_received', 'refund_sent', 'refund_submitted', 'refunded'])) {
                                    $ps_label = 'Payment Refunded';
                                    $ps_badge = 'bg-secondary bg-opacity-10 text-secondary border border-secondary';
                                } elseif ($ps === 'payment_submitted') {
                                    $ps_label = 'Payment Submitted';
                                    $ps_badge = 'bg-info bg-opacity-10 text-info border border-info';
                                } elseif ($ps === 'pending') {
                                    $ps_label = 'Pending Payment';
                                    $ps_badge = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                }
                                
                                // Order status badge logic
                                $os = $o['order_status'];
                                $os_badge = 'bg-secondary';
                                if ($os === 'completed' || $os === 'delivered') $os_badge = 'bg-success';
                                elseif ($os === 'cancelled') $os_badge = 'bg-danger';
                                elseif ($os === 'pending') $os_badge = 'bg-warning text-dark';
                                elseif ($os === 'shipped') $os_badge = 'bg-info text-dark';
                            ?>
                                <tr>
                                    <td class="fw-bold"><a href="order_details.php?id=<?php echo $o['id']; ?>" class="text-decoration-none text-primary">#<?php echo htmlspecialchars($o['order_number']); ?></a></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <img src="<?php echo $p_img; ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 36px; height: 36px;" onerror="this.src='https://placehold.co/36x36?text=Pet';">
                                            <span class="small fw-semibold text-dark text-truncate" style="max-width: 110px;" title="<?php echo htmlspecialchars($o['product_title'] ?? 'Pet'); ?>"><?php echo htmlspecialchars($o['product_title'] ?? 'Pet'); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($o['first_name'] . ' ' . $o['last_name']); ?></td>
                                    <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($o['grand_total'] ?? 0, 2); ?></td>
                                    <td><span class="badge <?php echo $ps_badge; ?> px-2 py-1 rounded-pill small"><?php echo $ps_label; ?></span></td>
                                    <td><span class="badge <?php echo $os_badge; ?> rounded-pill px-2 py-1"><?php echo ucfirst(str_replace('_',' ',$os)); ?></span></td>
                                    <td><a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-2 py-0" style="font-size: 0.75rem;">View</a></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-3 text-muted">No orders found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Users -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3 px-2 pt-2">
                <h6 class="fw-bold mb-0"><i class="bi bi-people me-2 text-info"></i>Recent Users</h6>
                <a href="buyers.php" class="btn btn-sm btn-outline-info rounded-pill">View Buyers</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle small mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_users && $recent_users->num_rows > 0): ?>
                            <?php while ($u = $recent_users->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo ucfirst($u['role']); ?></span></td>
                                    <td><span class="badge bg-<?php echo $u['status'] === 'active' ? 'success' : 'danger'; ?> rounded-pill"><?php echo ucfirst($u['status']); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center py-3 text-muted">No users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Scripts Initialization -->
<script>
const months = <?php echo json_encode($chart_months); ?>;
const revenueData = <?php echo json_encode($chart_revenue); ?>;
const ordersData = <?php echo json_encode($chart_orders); ?>;
const usersData = <?php echo json_encode($chart_users); ?>;

// 1. Revenue Chart
new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: months,
        datasets: [{
            label: 'Revenue (<?php echo $site_settings['currency']; ?>)',
            data: revenueData,
            borderColor: '#198754',
            backgroundColor: 'rgba(25, 135, 84, 0.1)',
            fill: true,
            tension: 0.3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

// 2. Orders Chart
new Chart(document.getElementById('ordersChart'), {
    type: 'bar',
    data: {
        labels: months,
        datasets: [{
            label: 'Orders Count',
            data: ordersData,
            backgroundColor: '#0d6efd',
            borderRadius: 6
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

// 3. Users Chart
new Chart(document.getElementById('usersChart'), {
    type: 'line',
    data: {
        labels: months,
        datasets: [{
            label: 'New Registrations',
            data: usersData,
            borderColor: '#0dcaf0',
            backgroundColor: 'rgba(13, 202, 240, 0.1)',
            fill: true,
            tension: 0.3
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});

// 4. Escrow Chart
new Chart(document.getElementById('escrowChart'), {
    type: 'doughnut',
    data: {
        labels: ['Admin Held Balance', 'Paid to Sellers', 'Refunds Sent'],
        datasets: [{
            data: [
                <?php echo (float)$admin_held_balance; ?>,
                <?php echo (float)$f_res['total_paid_to_sellers']; ?>,
                <?php echo (float)$f_res['total_refunds_sent']; ?>
            ],
            backgroundColor: ['#ffc107', '#198754', '#dc3545']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>