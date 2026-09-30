<?php
/**
 * Admin Reports Module
 * Phase 3.3 — fixed query order + CSV export + Phase 8 metrics
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$page_title    = "Reports & Analytics";
$page_heading  = "System Reports";

// Date Range Filter — set BEFORE header include
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date   = isset($_GET['end_date'])   ? $_GET['end_date']   : date('Y-m-t');

$p1 = $start_date . ' 00:00:00';
$p2 = $end_date   . ' 23:59:59';

// Order Stats
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN payment_status NOT IN ('pending','payment_submitted','rejected') THEN grand_total ELSE 0 END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN payment_status IN ('seller_payment_sent','seller_payment_received') THEN seller_payment_amount ELSE 0 END), 0) AS released_payouts,
        COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent','refund_received') THEN buyer_refund_amount ELSE 0 END), 0) AS total_refunds
    FROM orders
    WHERE created_at BETWEEN ? AND ?
");
$stmt->bind_param("ss", $p1, $p2);
$stmt->execute();
$order_stats      = $stmt->get_result()->fetch_assoc();
$admin_held       = max(0, $order_stats['total_revenue'] - $order_stats['released_payouts'] - $order_stats['total_refunds']);

// User Stats
$stmt_u = $conn->prepare("
    SELECT
        COUNT(CASE WHEN role='user'   THEN 1 END) AS new_buyers,
        COUNT(CASE WHEN role='seller' THEN 1 END) AS new_sellers
    FROM users
    WHERE created_at BETWEEN ? AND ?
");
$stmt_u->bind_param("ss", $p1, $p2);
$stmt_u->execute();
$user_stats = $stmt_u->get_result()->fetch_assoc();

// Top Selling Categories
$stmt_cat = $conn->prepare("
    SELECT c.name_en,
           COUNT(oi.id)                        AS items_sold,
           COALESCE(SUM(oi.price * oi.quantity), 0) AS category_revenue
    FROM order_items oi
    JOIN products   p  ON oi.product_id = p.id
    JOIN categories c  ON p.category_id = c.id
    JOIN orders     o  ON oi.order_id   = o.id
    WHERE o.created_at BETWEEN ? AND ?
      AND o.payment_status NOT IN ('pending','payment_submitted','rejected')
    GROUP BY c.id
    ORDER BY category_revenue DESC
    LIMIT 8
");
$stmt_cat->bind_param("ss", $p1, $p2);
$stmt_cat->execute();
$top_categories = $stmt_cat->get_result();

// CSV Export — must happen BEFORE any HTML output
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="petmarket_report_' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, ['PetMarket System Report', $start_date . ' to ' . $end_date]);
    fputcsv($f, []);
    fputcsv($f, ['Metric', 'Value']);
    fputcsv($f, ['Total Orders',        $order_stats['total_orders']]);
    fputcsv($f, ['Gross Revenue',       $order_stats['total_revenue']]);
    fputcsv($f, ['Admin Held Balance',  $admin_held]);
    fputcsv($f, ['Paid to Sellers',     $order_stats['released_payouts']]);
    fputcsv($f, ['Total Refunds Sent',  $order_stats['total_refunds']]);
    fputcsv($f, ['New Buyers',          $user_stats['new_buyers']]);
    fputcsv($f, ['New Sellers',         $user_stats['new_sellers']]);
    fputcsv($f, []);
    fputcsv($f, ['Category', 'Items Sold', 'Revenue']);
    $top_categories->data_seek(0);
    while ($cat = $top_categories->fetch_assoc()) {
        fputcsv($f, [$cat['name_en'], $cat['items_sold'], $cat['category_revenue']]);
    }
    fclose($f);
    exit;
}

// Now include admin layout
include __DIR__ . '/partials/header.php';

// Currency helper
$cur = htmlspecialchars($site_settings['currency'] ?? 'Rs');
function fmt($cur, $v) { return $cur . ' ' . number_format((float)$v, 2); }
?>

<div class="container-fluid py-4 px-4">

    <!-- Page header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-bar-chart-line text-primary me-2"></i>System Reports</h3>
        <a href="reports.php?export=csv&start_date=<?= urlencode($start_date) ?>&end_date=<?= urlencode($end_date) ?>"
           class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
            <i class="bi bi-download me-2"></i>Export CSV
        </a>
    </div>

    <!-- Date filter -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form action="reports.php" method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold small text-muted text-uppercase">Start Date</label>
                    <input type="date" name="start_date" class="form-control rounded-3 border-0 bg-light"
                           value="<?= htmlspecialchars($start_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small text-muted text-uppercase">End Date</label>
                    <input type="date" name="end_date" class="form-control rounded-3 border-0 bg-light"
                           value="<?= htmlspecialchars($end_date) ?>" required>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-dark rounded-pill px-4 w-100 fw-bold">
                        <i class="bi bi-search me-1"></i> Generate Report
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stat cards + platform metrics -->
    <div class="row g-4 mb-4">

        <!-- Financial stat cards -->
        <div class="col-lg-8">
            <div class="row g-4 h-100">
                <!-- Gross Revenue -->
                <div class="col-sm-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100" style="background:linear-gradient(135deg,#1a73e8,#0d47a1);">
                        <div class="card-body p-4 text-white d-flex flex-column justify-content-between">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <p class="text-white-50 fw-semibold small mb-1 text-uppercase">Gross Revenue</p>
                                    <h3 class="fw-bold mb-0 text-break"><?= fmt($cur, $order_stats['total_revenue']) ?></h3>
                                </div>
                                <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width:55px;height:55px;flex-shrink:0;">
                                    <i class="bi bi-wallet2" style="font-size:1.8rem;opacity:0.9;"></i>
                                </div>
                            </div>
                            <small class="text-white-50 d-block border-top border-white border-opacity-10 pt-3 mt-auto">All approved payments collected</small>
                        </div>
                    </div>
                </div>
                <!-- Admin Held -->
                <div class="col-sm-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100" style="background:linear-gradient(135deg,#f9a825,#e65100);">
                        <div class="card-body p-4 text-white d-flex flex-column justify-content-between">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <p class="text-white-50 fw-semibold small mb-1 text-uppercase">Admin Held Balance</p>
                                    <h3 class="fw-bold mb-0 text-break"><?= fmt($cur, $admin_held) ?></h3>
                                </div>
                                <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width:55px;height:55px;flex-shrink:0;">
                                    <i class="bi bi-shield-lock" style="font-size:1.8rem;opacity:0.9;"></i>
                                </div>
                            </div>
                            <small class="text-white-50 d-block border-top border-white border-opacity-10 pt-3 mt-auto">Pending seller payouts / refunds</small>
                        </div>
                    </div>
                </div>
                <!-- Paid to Sellers -->
                <div class="col-sm-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100" style="background:linear-gradient(135deg,#2e7d32,#1b5e20);">
                        <div class="card-body p-4 text-white d-flex flex-column justify-content-between">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <p class="text-white-50 fw-semibold small mb-1 text-uppercase">Paid to Sellers</p>
                                    <h3 class="fw-bold mb-0 text-break"><?= fmt($cur, $order_stats['released_payouts']) ?></h3>
                                </div>
                                <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width:55px;height:55px;flex-shrink:0;">
                                    <i class="bi bi-cash-coin" style="font-size:1.8rem;opacity:0.9;"></i>
                                </div>
                            </div>
                            <small class="text-white-50 d-block border-top border-white border-opacity-10 pt-3 mt-auto">Successful seller payouts</small>
                        </div>
                    </div>
                </div>
                <!-- Refunds -->
                <div class="col-sm-6">
                    <div class="card border-0 shadow-sm rounded-4 h-100" style="background:linear-gradient(135deg,#c62828,#b71c1c);">
                        <div class="card-body p-4 text-white d-flex flex-column justify-content-between">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <p class="text-white-50 fw-semibold small mb-1 text-uppercase">Total Refunds Sent</p>
                                    <h3 class="fw-bold mb-0 text-break"><?= fmt($cur, $order_stats['total_refunds']) ?></h3>
                                </div>
                                <div class="rounded-circle bg-white bg-opacity-10 d-flex align-items-center justify-content-center" style="width:55px;height:55px;flex-shrink:0;">
                                    <i class="bi bi-arrow-counterclockwise" style="font-size:1.8rem;opacity:0.9;"></i>
                                </div>
                            </div>
                            <small class="text-white-50 d-block border-top border-white border-opacity-10 pt-3 mt-auto">Refunds sent to buyers</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Platform Metrics -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 px-4 pt-4 pb-0">
                    <h5 class="fw-bold mb-0"><i class="bi bi-activity text-primary me-2"></i>Platform Metrics</h5>
                    <small class="text-muted">for selected period</small>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;">
                                <i class="bi bi-cart-check text-primary"></i>
                            </div>
                            <span class="fw-semibold">Orders Placed</span>
                        </div>
                        <span class="fw-bold fs-5"><?= number_format($order_stats['total_orders']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-3 border-bottom">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-info bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;">
                                <i class="bi bi-person-plus text-info"></i>
                            </div>
                            <span class="fw-semibold">New Buyers</span>
                        </div>
                        <span class="fw-bold fs-5 text-info"><?= number_format($user_stats['new_buyers']) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-3">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center me-3" style="width:40px;height:40px;">
                                <i class="bi bi-shop text-success"></i>
                            </div>
                            <span class="fw-semibold">New Sellers</span>
                        </div>
                        <span class="fw-bold fs-5 text-success"><?= number_format($user_stats['new_sellers']) ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Performing Categories -->
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 px-4 pt-4 pb-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0"><i class="bi bi-trophy text-warning me-2"></i>Top Performing Categories</h5>
            <small class="text-muted"><?= htmlspecialchars($start_date) ?> → <?= htmlspecialchars($end_date) ?></small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light" style="font-size:.83rem;letter-spacing:.5px;">
                        <tr>
                            <th class="ps-4 py-3 fw-semibold border-0 text-muted">#</th>
                            <th class="py-3 fw-semibold border-0 text-muted">CATEGORY</th>
                            <th class="py-3 fw-semibold border-0 text-center text-muted">ITEMS SOLD</th>
                            <th class="pe-4 py-3 fw-semibold border-0 text-end text-muted">REVENUE</th>
                        </tr>
                    </thead>
                    <tbody class="border-top-0">
                        <?php if ($top_categories && $top_categories->num_rows > 0):
                            $rank = 1;
                            $top_categories->data_seek(0);
                            while ($cat = $top_categories->fetch_assoc()):
                                $medal = $rank === 1 ? '🥇' : ($rank === 2 ? '🥈' : ($rank === 3 ? '🥉' : "#$rank"));
                        ?>
                            <tr>
                                <td class="ps-4 py-3 fw-bold text-muted"><?= $medal ?></td>
                                <td class="py-3 fw-bold text-dark"><?= htmlspecialchars($cat['name_en']) ?></td>
                                <td class="py-3 text-center">
                                    <span class="badge bg-light border text-dark rounded-pill px-3">
                                        <?= number_format($cat['items_sold']) ?>
                                    </span>
                                </td>
                                <td class="pe-4 py-3 text-end fw-bold text-success">
                                    <?= fmt($cur, $cat['category_revenue']) ?>
                                </td>
                            </tr>
                        <?php $rank++; endwhile; else: ?>
                            <tr>
                                <td colspan="4" class="text-center py-5">
                                    <i class="bi bi-bar-chart fs-1 text-muted d-block mb-2"></i>
                                    <span class="text-muted">No sales data for this period.</span>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->

<?php include __DIR__ . '/partials/footer.php'; ?>
