<?php
/**
 * Seller Analytics / Earnings — Phase 3.1
 * Shows detailed earnings breakdown, top-selling products, and monthly chart data.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];

// ── Summary stats ─────────────────────────────────────────────
$summary_stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(oi.total), 0)         AS total_earnings,
        COALESCE(SUM(oi.quantity), 0)      AS total_units_sold,
        COUNT(DISTINCT o.id)               AS total_orders,
        COALESCE(AVG(oi.total), 0)         AS avg_order_value
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     WHERE oi.seller_id = ?"
);
$summary_stmt->bind_param('i', $seller_id);
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();

// ── This month earnings ───────────────────────────────────────
$month_stmt = $conn->prepare(
    "SELECT COALESCE(SUM(oi.total), 0) AS month_earnings
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     WHERE oi.seller_id = ?
       AND MONTH(o.created_at) = MONTH(NOW())
       AND YEAR(o.created_at)  = YEAR(NOW())"
);
$month_stmt->bind_param('i', $seller_id);
$month_stmt->execute();
$month_earnings = (float)$month_stmt->get_result()->fetch_assoc()['month_earnings'];

// ── Monthly earnings for chart (last 6 months) ────────────────
$chart_stmt = $conn->prepare(
    "SELECT
        DATE_FORMAT(o.created_at, '%b %Y') AS month_label,
        DATE_FORMAT(o.created_at, '%Y-%m') AS month_key,
        COALESCE(SUM(oi.total), 0)         AS earnings
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     WHERE oi.seller_id = ?
       AND o.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY month_key, month_label
     ORDER BY month_key ASC"
);
$chart_stmt->bind_param('i', $seller_id);
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();
$chart_labels   = [];
$chart_earnings = [];
while ($row = $chart_result->fetch_assoc()) {
    $chart_labels[]   = $row['month_label'];
    $chart_earnings[] = (float)$row['earnings'];
}

// ── Top selling products ──────────────────────────────────────
$top_stmt = $conn->prepare(
    "SELECT p.id, p.title_en, p.price,
            SUM(oi.quantity) AS units_sold,
            SUM(oi.total)    AS revenue,
            (SELECT pi.image_url FROM product_images pi
             WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS thumb
     FROM order_items oi
     JOIN products p ON oi.product_id = p.id
     WHERE oi.seller_id = ?
     GROUP BY p.id
     ORDER BY revenue DESC
     LIMIT 8"
);
$top_stmt->bind_param('i', $seller_id);
$top_stmt->execute();
$top_products = $top_stmt->get_result();

// ── Recent transactions ───────────────────────────────────────
$trans_stmt = $conn->prepare(
    "SELECT o.order_number, o.created_at, o.order_status, o.payment_method,
            u.first_name, u.last_name,
            SUM(oi.total) AS seller_total
     FROM order_items oi
     JOIN orders o ON oi.order_id = o.id
     JOIN users  u ON o.user_id   = u.id
     WHERE oi.seller_id = ?
     GROUP BY o.id
     ORDER BY o.created_at DESC
     LIMIT 10"
);
$trans_stmt->bind_param('i', $seller_id);
$trans_stmt->execute();
$transactions = $trans_stmt->get_result();

include '../includes/header.php';
?>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">

        <!-- Sidebar -->
        <div class="col-lg-2 mb-4 mb-lg-0">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- Main -->
        <div class="col-lg-10">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-graph-up text-warning me-2"></i>Earnings & Analytics
                    </h3>
                    <p class="text-muted mb-0 small">Your sales performance overview</p>
                </div>
            </div>

            <!-- ── Summary Cards ──────────────────────────────── -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100 bg-success text-white">
                        <div class="card-body p-4">
                            <i class="bi bi-currency-dollar fs-2 opacity-50 d-block mb-2"></i>
                            <h2 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($summary['total_earnings'], 2); ?></h2>
                            <p class="text-white-50 mb-0 small">Total Earnings</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-4">
                            <i class="bi bi-calendar-month fs-2 text-primary opacity-50 d-block mb-2"></i>
                            <h2 class="fw-bold mb-0 text-primary"><?php echo $site_settings['currency'] . number_format($month_earnings, 2); ?></h2>
                            <p class="text-muted mb-0 small">This Month</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-4">
                            <i class="bi bi-bag-check fs-2 text-warning opacity-50 d-block mb-2"></i>
                            <h2 class="fw-bold mb-0 text-warning"><?php echo (int)$summary['total_orders']; ?></h2>
                            <p class="text-muted mb-0 small">Total Orders</p>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-body p-4">
                            <i class="bi bi-box-seam fs-2 text-info opacity-50 d-block mb-2"></i>
                            <h2 class="fw-bold mb-0 text-info"><?php echo (int)$summary['total_units_sold']; ?></h2>
                            <p class="text-muted mb-0 small">Units Sold</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Chart + Top Products ───────────────────────── -->
            <div class="row g-4 mb-4">

                <!-- Earnings Chart -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0">Monthly Earnings (Last 6 Months)</h5>
                        </div>
                        <div class="card-body p-4">
                            <?php if (!empty($chart_labels)): ?>
                                <canvas id="earningsChart" height="220"></canvas>
                            <?php else: ?>
                                <div class="text-center text-muted py-5">
                                    <i class="bi bi-bar-chart-line fs-1 d-block mb-3 opacity-25"></i>
                                    <p>No data yet. Start selling to see your earnings chart!</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top Products -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm rounded-4 h-100">
                        <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                            <h5 class="fw-bold mb-0">Top Selling Products</h5>
                        </div>
                        <div class="card-body p-3">
                            <?php if ($top_products->num_rows > 0): ?>
                            <ul class="list-group list-group-flush">
                                <?php $rank = 1; while ($tp = $top_products->fetch_assoc()): ?>
                                <li class="list-group-item d-flex align-items-center gap-3 px-0">
                                    <span class="fw-bold text-muted" style="min-width:20px;">#<?php echo $rank++; ?></span>
                                    <?php if ($tp['thumb']): ?>
                                        <img src="<?php echo BASE_URL; ?>/assets/uploads/products/<?php echo htmlspecialchars($tp['thumb']); ?>"
                                             class="rounded-3 border object-fit-cover"
                                             style="width:40px;height:40px;">
                                    <?php else: ?>
                                        <div class="bg-light rounded-3 d-flex align-items-center justify-content-center text-muted border" style="width:40px;height:40px;"><i class="bi bi-image small"></i></div>
                                    <?php endif; ?>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <p class="mb-0 fw-medium text-truncate small"><?php echo htmlspecialchars($tp['title_en']); ?></p>
                                        <small class="text-muted"><?php echo (int)$tp['units_sold']; ?> sold</small>
                                    </div>
                                    <div class="text-end">
                                        <p class="mb-0 fw-bold text-success small"><?php echo $site_settings['currency'] . number_format($tp['revenue'], 2); ?></p>
                                    </div>
                                </li>
                                <?php endwhile; ?>
                            </ul>
                            <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-bar-chart fs-1 d-block mb-2 opacity-25"></i>
                                <p class="small">No sales yet.</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Transaction History ───────────────────────── -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-0">Recent Transactions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Order #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th class="pe-4 text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($transactions->num_rows > 0): ?>
                                <?php while ($t = $transactions->fetch_assoc()):
                                    $sc = ['pending'=>'warning','processing'=>'info','shipped'=>'primary','delivered'=>'success','cancelled'=>'danger'][$t['order_status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <strong class="text-success">#<?php echo htmlspecialchars($t['order_number']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?></td>
                                    <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></small></td>
                                    <td><span class="badge bg-light text-dark border small"><?php echo htmlspecialchars($t['payment_method']); ?></span></td>
                                    <td><span class="badge bg-<?php echo $sc; ?> rounded-pill"><?php echo ucfirst($t['order_status']); ?></span></td>
                                    <td class="pe-4 text-end fw-bold text-success"><?php echo $site_settings['currency'] . number_format($t['seller_total'], 2); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-cash-coin fs-1 d-block mb-3 opacity-25"></i>
                                        No transactions yet.
                                    </td>
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

<!-- Chart.js -->
<?php if (!empty($chart_labels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const ctx = document.getElementById('earningsChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets: [{
            label: 'Earnings (<?php echo $site_settings['currency']; ?>)',
            data: <?php echo json_encode($chart_earnings); ?>,
            backgroundColor: 'rgba(46,125,50,0.15)',
            borderColor:     '#2E7D32',
            borderWidth: 2,
            borderRadius: 8,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: ctx => '<?php echo $site_settings['currency']; ?>' + ctx.parsed.y.toFixed(2)
                }
            }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
