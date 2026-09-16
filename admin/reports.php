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

$where_orders = "WHERE created_at BETWEEN ? AND ?";
$where_users  = "WHERE created_at BETWEEN ? AND ?";
$params       = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];

// Fetch Order Stats
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(grand_total), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN payment_status = 'held' THEN grand_total ELSE 0 END), 0) AS held_escrow,
        COALESCE(SUM(CASE WHEN payment_status = 'released' THEN grand_total ELSE 0 END), 0) AS released_payouts,
        COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN grand_total ELSE 0 END), 0) AS total_refunds
    FROM orders $where_orders
");
$stmt->bind_param("ss", ...$params);
$stmt->execute();
$order_stats = $stmt->get_result()->fetch_assoc();

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
    $where_orders AND o.payment_status IN ('payment_received', 'held', 'released')
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
    header('Content-Disposition: attachment; filename="report_' . $start_date . '_to_' . $end_date . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Report Period:', $start_date, 'to', $end_date]);
    fputcsv($output, []);
    
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Orders', $order_stats['total_orders']]);
    fputcsv($output, ['Total Revenue', $order_stats['total_revenue']]);
    fputcsv($output, ['Escrow Held', $order_stats['held_escrow']]);
    fputcsv($output, ['Released Payouts', $order_stats['released_payouts']]);
    fputcsv($output, ['Refunds', $order_stats['total_refunds']]);
    fputcsv($output, ['New Buyers', $user_stats['new_buyers']]);
    fputcsv($output, ['New Sellers', $user_stats['new_sellers']]);
    
    fclose($output);
    exit;
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Business Reports</h4>
        <p class="text-muted small mb-0">Analytics for the selected period</p>
    </div>
    <a href="?export=csv&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
        <i class="bi bi-download me-2"></i> Export Report
    </a>
</div>

<!-- Date Filter -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="reports.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-light border-0">From</span>
                <input type="date" name="start_date" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($start_date); ?>" required>
            </div>
        </div>
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-light border-0">To</span>
                <input type="date" name="end_date" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($end_date); ?>" required>
            </div>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">Generate</button>
        </div>
    </form>
</div>

<!-- Report Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-primary text-white p-4 h-100 rounded-4">
            <h6 class="text-white-50 mb-1">Gross Merchandise Value (GMV)</h6>
            <h2 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($order_stats['total_revenue'], 2); ?></h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-success text-white p-4 h-100 rounded-4">
            <h6 class="text-white-50 mb-1">Total Released Payouts</h6>
            <h2 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($order_stats['released_payouts'], 2); ?></h2>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm bg-warning text-dark p-4 h-100 rounded-4">
            <h6 class="text-dark-50 mb-1">Currently in Escrow</h6>
            <h2 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($order_stats['held_escrow'], 2); ?></h2>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold mb-0">Platform Engagement</h6>
            </div>
            <div class="card-body p-4">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted"><i class="bi bi-cart me-2"></i> Orders Placed</span>
                        <span class="fw-bold fs-5"><?php echo number_format($order_stats['total_orders']); ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted"><i class="bi bi-person-plus me-2"></i> New Buyers</span>
                        <span class="fw-bold fs-5 text-primary">+<?php echo number_format($user_stats['new_buyers']); ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted"><i class="bi bi-shop me-2"></i> New Sellers</span>
                        <span class="fw-bold fs-5 text-primary">+<?php echo number_format($user_stats['new_sellers']); ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <span class="text-muted"><i class="bi bi-arrow-counterclockwise me-2"></i> Refunds</span>
                        <span class="fw-bold fs-5 text-danger"><?php echo $site_settings['currency'] . number_format($order_stats['total_refunds'], 2); ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm rounded-4 h-100">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold mb-0">Top Categories by Revenue</h6>
            </div>
            <div class="card-body p-4">
                <?php if ($top_categories && $top_categories->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-borderless align-middle mb-0">
                            <tbody>
                                <?php while ($cat = $top_categories->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-0">
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded p-2 me-3"><i class="bi bi-tag text-primary"></i></div>
                                                <span class="fw-bold"><?php echo htmlspecialchars($cat['name_en']); ?></span>
                                            </div>
                                        </td>
                                        <td class="text-muted small"><?php echo $cat['items_sold']; ?> items</td>
                                        <td class="text-end fw-bold text-success"><?php echo $site_settings['currency'] . number_format($cat['category_revenue'], 2); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted text-center my-4">No sales data for the selected period.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
