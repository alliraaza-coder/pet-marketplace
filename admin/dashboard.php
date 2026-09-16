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

// Orders counts
$o_res = $conn->query("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(order_status = 'pending') AS pending_orders,
        SUM(order_status = 'completed') AS completed_orders,
        SUM(order_status = 'cancelled') AS cancelled_orders
    FROM orders
")->fetch_assoc();

// Financial counts
$f_res = $conn->query("
    SELECT 
        COALESCE(SUM(CASE WHEN payment_status = 'released' THEN grand_total ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN payment_status = 'held' THEN grand_total ELSE 0 END), 0) AS escrow_balance,
        COALESCE(SUM(CASE WHEN payment_status = 'released' THEN grand_total ELSE 0 END), 0) AS released_payments,
        COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN grand_total ELSE 0 END), 0) AS refunded_payments
    FROM orders
")->fetch_assoc();

// ── 2. Chart Data (Monthly Revenue & Orders last 6 months) ──
$chart_months = [];
$chart_revenue = [];
$chart_orders = [];
$chart_users = [];

for ($i = 5; $i >= 0; $i--) {
    $m_start = date('Y-m-01 00:00:00', strtotime("-$i months"));
    $m_end   = date('Y-m-t 23:59:59', strtotime("-$i months"));
    $m_label = date('M Y', strtotime("-$i months"));
    $chart_months[] = $m_label;

    // Monthly revenue (released orders)
    $rev_stmt = $conn->prepare("SELECT COALESCE(SUM(grand_total), 0) AS rev FROM orders WHERE payment_status = 'released' AND created_at BETWEEN ? AND ?");
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
    SELECT o.id, o.order_number, o.grand_total, o.order_status, o.payment_status, o.created_at, u.first_name, u.last_name
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

<!-- ── 14 Stat Cards ────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <!-- Total Users -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-primary mb-1"><i class="bi bi-people fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo number_format($u_res['total_users']); ?></h4>
            <span class="text-muted small">Total Users</span>
        </div>
    </div>
    <!-- Total Buyers -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-info mb-1"><i class="bi bi-person-check fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo number_format($u_res['total_buyers']); ?></h4>
            <span class="text-muted small">Total Buyers</span>
        </div>
    </div>
    <!-- Total Sellers -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-warning mb-1"><i class="bi bi-shop fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo number_format($u_res['total_sellers']); ?></h4>
            <span class="text-muted small">Total Sellers</span>
        </div>
    </div>
    <!-- Total Products -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-secondary mb-1"><i class="bi bi-box-seam fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo number_format($p_res['total_products']); ?></h4>
            <span class="text-muted small">Total Products</span>
        </div>
    </div>
    <!-- Active Products -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-success mb-1"><i class="bi bi-check-circle fs-3"></i></div>
            <h4 class="fw-bold mb-0 text-success"><?php echo number_format($p_res['active_products']); ?></h4>
            <span class="text-muted small">Active Products</span>
        </div>
    </div>
    <!-- Pending Products -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-danger mb-1"><i class="bi bi-hourglass-split fs-3"></i></div>
            <h4 class="fw-bold mb-0 text-danger"><?php echo number_format($p_res['pending_products']); ?></h4>
            <span class="text-muted small">Pending Products</span>
        </div>
    </div>

    <!-- Total Orders -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-primary mb-1"><i class="bi bi-cart-check fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo number_format($o_res['total_orders']); ?></h4>
            <span class="text-muted small">Total Orders</span>
        </div>
    </div>
    <!-- Pending Orders -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-warning mb-1"><i class="bi bi-clock-history fs-3"></i></div>
            <h4 class="fw-bold mb-0 text-warning"><?php echo number_format($o_res['pending_orders']); ?></h4>
            <span class="text-muted small">Pending Orders</span>
        </div>
    </div>
    <!-- Completed Orders -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-success mb-1"><i class="bi bi-bag-check fs-3"></i></div>
            <h4 class="fw-bold mb-0 text-success"><?php echo number_format($o_res['completed_orders']); ?></h4>
            <span class="text-muted small">Completed Orders</span>
        </div>
    </div>
    <!-- Cancelled Orders -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-white p-3 text-center">
            <div class="text-danger mb-1"><i class="bi bi-x-circle fs-3"></i></div>
            <h4 class="fw-bold mb-0 text-danger"><?php echo number_format($o_res['cancelled_orders']); ?></h4>
            <span class="text-muted small">Cancelled Orders</span>
        </div>
    </div>

    <!-- Total Revenue -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-success text-white p-3 text-center">
            <div class="text-white-50 mb-1"><i class="bi bi-currency-dollar fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['total_revenue'], 0); ?></h4>
            <span class="text-white-50 small">Total Revenue</span>
        </div>
    </div>
    <!-- Escrow Balance -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-warning text-dark p-3 text-center">
            <div class="text-dark-50 mb-1"><i class="bi bi-shield-lock fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['escrow_balance'], 0); ?></h4>
            <span class="text-dark-50 small">Escrow Balance</span>
        </div>
    </div>
    <!-- Released Payments -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-info text-white p-3 text-center">
            <div class="text-white-50 mb-1"><i class="bi bi-wallet2 fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['released_payments'], 0); ?></h4>
            <span class="text-white-50 small">Released Payments</span>
        </div>
    </div>
    <!-- Refunded Payments -->
    <div class="col-6 col-md-3 col-xl-2">
        <div class="card border-0 shadow-sm stat-card bg-danger text-white p-3 text-center">
            <div class="text-white-50 mb-1"><i class="bi bi-arrow-counterclockwise fs-3"></i></div>
            <h4 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($f_res['refunded_payments'], 0); ?></h4>
            <span class="text-white-50 small">Refunded Payments</span>
        </div>
    </div>
</div>

<!-- ── 4 Interactive Charts ─────────────────────────────── -->
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

<!-- ── Recent Activity Tables ────────────────────────────── -->
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
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Payment</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recent_orders && $recent_orders->num_rows > 0): ?>
                            <?php while ($o = $recent_orders->fetch_assoc()): ?>
                                <tr>
                                    <td class="fw-bold text-primary">#<?php echo htmlspecialchars($o['order_number']); ?></td>
                                    <td><?php echo htmlspecialchars($o['first_name'] . ' ' . $o['last_name']); ?></td>
                                    <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($o['grand_total'], 2); ?></td>
                                    <td><span class="badge bg-light text-dark border"><?php echo ucfirst(htmlspecialchars($o['payment_status'])); ?></span></td>
                                    <td><span class="badge bg-secondary rounded-pill"><?php echo ucfirst(str_replace('_',' ',$o['order_status'])); ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center py-3 text-muted">No orders found.</td></tr>
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
        labels: ['Held in Escrow', 'Released Payments', 'Refunded Payments'],
        datasets: [{
            data: [
                <?php echo (float)$f_res['escrow_balance']; ?>,
                <?php echo (float)$f_res['released_payments']; ?>,
                <?php echo (float)$f_res['refunded_payments']; ?>
            ],
            backgroundColor: ['#ffc107', '#198754', '#dc3545']
        }]
    },
    options: { responsive: true, maintainAspectRatio: false }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
