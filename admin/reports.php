<?php
/**
 * Admin Reports Module
 * Phase 3.3
 */
$page_title = "Reports & Analytics";
$page_heading = "System Reports";
include __DIR__ . '/partials/header.php';

// Date Range Filter
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to 1st of current month
$end_date   = $_GET['end_date'] ?? date('Y-m-t'); // Default to last day of current month

$where_orders = "WHERE o.created_at BETWEEN ? AND ?";
$where_users  = "WHERE created_at BETWEEN ? AND ?";
$params       = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];

// Fetch Order Stats (Phase 8 logic)
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN o.payment_status NOT IN ('pending','payment_submitted','rejected') THEN o.grand_total ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN o.payment_status IN ('seller_payment_sent', 'seller_payment_received') THEN o.seller_payment_amount ELSE 0 END), 0) AS released_payouts,
        COALESCE(SUM(CASE WHEN o.payment_status IN ('refund_sent', 'refund_received') THEN o.buyer_refund_amount ELSE 0 END), 0) AS total_refunds
    FROM orders o $where_orders
");
$stmt->bind_param("ss", ...$params);
$stmt->execute();
$order_stats = $stmt->get_result()->fetch_assoc();

$admin_held_balance = $order_stats['total_revenue'] - $order_stats['released_payouts'] - $order_stats['total_refunds'];

// Fetch User Stats
$stmt_users = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN role = 'user' THEN 1 END) AS new_buyers,
        COUNT(CASE WHEN role = 'seller' THEN 1 END) AS new_sellers
    FROM users $where_users
");
$stmt_users->bind_param("ss", ...$params);
$stmt_users->execute();
$user_stats = $stmt_users->get_result()->fetch_assoc();

// Fetch Top Selling Categories
$stmt_cat = $conn->prepare("
    SELECT c.name_en, COUNT(oi.id) AS items_sold, SUM(oi.price * oi.quantity) AS category_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN categories c ON p.category_id = c.id
    JOIN orders o ON oi.order_id = o.id
    $where_orders AND o.payment_status NOT IN ('pending','payment_submitted','rejected')
    GROUP BY c.id
    ORDER BY category_revenue DESC
    LIMIT 5
");
$stmt_cat->bind_param("ss", ...$params);
$stmt_cat->execute();
$top_categories = $stmt_cat->get_result();

// Export Action
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="petmarket_report_' . date('Ymd') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['PetMarket System Report']);
    fputcsv($output, ['Date Range', $start_date, 'to', $end_date]);
    fputcsv($output, []);
    
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Orders', $order_stats['total_orders']]);
    fputcsv($output, ['Total Revenue', $order_stats['total_revenue']]);
    fputcsv($output, ['Admin Held Balance', $admin_held_balance]);
    fputcsv($output, ['Released Payouts', $order_stats['released_payouts']]);
    fputcsv($output, ['Total Refunds', $order_stats['total_refunds']]);
    fputcsv($output, ['New Buyers', $user_stats['new_buyers']]);
    fputcsv($output, ['New Sellers', $user_stats['new_sellers']]);
    
    fputcsv($output, []);
    fputcsv($output, ['Top Categories']);
    fputcsv($output, ['Category', 'Items Sold', 'Revenue']);
    
    $top_categories->data_seek(0);
    while ($cat = $top_categories->fetch_assoc()) {
        fputcsv($output, [$cat['name_en'], $cat['items_sold'], $cat['category_revenue']]);
    }
    
    fclose($output);
    exit;
}
?>

<div class="container-fluid py-4 px-4">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-line text-primary me-2"></i>System Reports</h3>
        <a href="reports.php?export=csv&start_date=<?= htmlspecialchars($start_date) ?>&end_date=<?= htmlspecialchars($end_date) ?>" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
            <i class="bi bi-download me-2"></i>Export CSV
        </a>
    </div>

    <!-- Filter Form -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form action="reports.php" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-medium text-muted">Start Date</label>
                    <input type="date" name="start_date" class="form-control bg-light border-0" value="<?= htmlspecialchars($start_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-medium text-muted">End Date</label>
                    <input type="date" name="end_date" class="form-control bg-light border-0" value="<?= htmlspecialchars($end_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark rounded-pill px-4 w-100 fw-bold">Generate Report</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Financial Stats -->
        <div class="col-lg-8">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-primary text-white overflow-hidden stat-card">
                        <div class="card-body p-4 position-relative">
                            <i class="bi bi-wallet2 position-absolute text-white opacity-25" style="font-size: 5rem; top: -10px; right: -10px;"></i>
                            <h6 class="fw-bold text-white-50 mb-2">Total Revenue (Gross)</h6>
                            <h2 class="fw-bold mb-0"><?= htmlspecialchars($site_settings['currency'] ?? 'Rs') . ' ' . number_format($order_stats['total_revenue'], 2) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-warning text-dark overflow-hidden stat-card">
                        <div class="card-body p-4 position-relative">
                            <i class="bi bi-shield-lock position-absolute text-dark opacity-10" style="font-size: 5rem; top: -10px; right: -10px;"></i>
                            <h6 class="fw-bold text-muted mb-2">Admin Held Balance</h6>
                            <h2 class="fw-bold mb-0"><?= htmlspecialchars($site_settings['currency'] ?? 'Rs') . ' ' . number_format($admin_held_balance, 2) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-success text-white overflow-hidden stat-card">
                        <div class="card-body p-4 position-relative">
                            <i class="bi bi-cash-coin position-absolute text-white opacity-25" style="font-size: 5rem; top: -10px; right: -10px;"></i>
                            <h6 class="fw-bold text-white-50 mb-2">Total Paid to Sellers</h6>
                            <h2 class="fw-bold mb-0"><?= htmlspecialchars($site_settings['currency'] ?? 'Rs') . ' ' . number_format($order_stats['released_payouts'], 2) ?></h2>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-danger text-white overflow-hidden stat-card">
                        <div class="card-body p-4 position-relative">
                            <i class="bi bi-arrow-counterclockwise position-absolute text-white opacity-25" style="font-size: 5rem; top: -10px; right: -10px;"></i>
                            <h6 class="fw-bold text-white-50 mb-2">Total Refunds Sent</h6>
                            <h2 class="fw-bold mb-0"><?= htmlspecialchars($site_settings['currency'] ?? 'Rs') . ' ' . number_format($order_stats['total_refunds'], 2) ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Stats -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0">Platform Metrics</h5>
                </div>
                <div class="card-body p-4">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="bi bi-cart me-2"></i> Orders Placed</span>
                            <span class="fw-bold fs-5"><?php echo number_format($order_stats['total_orders']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                            <span class="text-muted"><i class="bi bi-person-plus me-2"></i> New Buyers</span>
                            <span class="fw-bold fs-5 text-primary"><?php echo number_format($user_stats['new_buyers']); ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-center px-0 border-0">
                            <span class="text-muted"><i class="bi bi-shop me-2"></i> New Sellers</span>
                            <span class="fw-bold fs-5 text-success"><?php echo number_format($user_stats['new_sellers']); ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Categories -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 pt-4 pb-3 px-4">
            <h5 class="fw-bold mb-0">Top Performing Categories</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                        <tr>
                            <th class="ps-4 py-3 fw-semibold border-0">CATEGORY</th>
                            <th class="py-3 fw-semibold border-0 text-center">ITEMS SOLD</th>
                            <th class="pe-4 py-3 fw-semibold border-0 text-end">REVENUE</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if ($top_categories->num_rows > 0): ?>
                            <?php while ($cat = $top_categories->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4 py-3 fw-bold text-dark"><?= htmlspecialchars($cat['name_en']) ?></td>
                                    <td class="py-3 text-center"><span class="badge bg-light border text-dark rounded-pill px-3"><?= number_format($cat['items_sold']) ?></span></td>
                                    <td class="pe-4 py-3 text-end fw-bold text-success"><?= htmlspecialchars($site_settings['currency'] ?? 'Rs') . ' ' . number_format($cat['category_revenue'], 2) ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center py-5 text-muted">No sales data for this period.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>

