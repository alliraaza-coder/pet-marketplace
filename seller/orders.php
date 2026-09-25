<?php
/**
 * Seller Orders — Phase 3.1
 * Shows all orders that contain the seller's products.
 * Seller can update order status.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];

// Status updating moved to order_details.php for escrow workflow
// ── Filters ───────────────────────────────────────────────────
$filter_status = in_array($_GET['status'] ?? '', ['pending','accepted','preparing','ready_for_shipment','out_for_delivery','delivered','completed','cancelled',''])
                 ? ($_GET['status'] ?? '') : '';
$search        = trim($_GET['q'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

// ── Build query ───────────────────────────────────────────────
$where  = "WHERE oi.seller_id = ? AND o.payment_status NOT IN ('pending', 'payment_submitted', 'rejected')";
$params = [$seller_id];
$types  = 'i';

if ($filter_status !== '') {
    $where   .= ' AND o.order_status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}
if ($search !== '') {
    $where   .= ' AND (o.order_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)';
    $s        = '%' . $search . '%';
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types   .= 'sss';
}

// Count
$cnt_sql = "SELECT COUNT(DISTINCT o.id) AS total
            FROM orders o
            JOIN order_items oi ON oi.order_id = o.id
            JOIN users u ON o.user_id = u.id
            $where";
$cnt_stmt = $conn->prepare($cnt_sql);
$cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total       = (int)$cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $per_page));

// Orders
$sql = "SELECT DISTINCT o.id, o.order_number, o.order_status, o.payment_method,
               o.grand_total, o.created_at,
               u.first_name, u.last_name, u.phone, u.email,
               o.shipping_address
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN users u ON o.user_id = u.id
        $where
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?";

$params_p  = array_merge($params, [$per_page, $offset]);
$types_p   = $types . 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$orders = $stmt->get_result();

// Status badge map
$status_map = [
    'pending'    => 'warning',
    'accepted'   => 'primary',
    'preparing'  => 'info',
    'ready_for_shipment' => 'warning',
    'out_for_delivery' => 'primary',
    'delivered'  => 'success',
    'completed'  => 'success',
    'cancelled'  => 'danger',
];

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
            <?php display_messages(); ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-bag-check text-warning me-2"></i>My Orders
                    </h3>
                    <p class="text-muted mb-0 small"><?php echo $total; ?> order<?php echo $total !== 1 ? 's' : ''; ?> found</p>
                </div>
            </div>

            <!-- Status Filter Tabs -->
            <div class="d-flex gap-2 mb-3 flex-wrap">
                <?php
                $tab_opts = ['' => 'All', 'pending' => 'Pending', 'accepted' => 'Accepted',
                             'preparing' => 'Preparing', 'ready_for_shipment' => 'Ready', 'out_for_delivery' => 'Shipping', 'delivered' => 'Delivered', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
                foreach ($tab_opts as $val => $lbl):
                    $qs       = http_build_query(['q' => $search, 'status' => $val]);
                    $is_act   = ($filter_status === $val);
                ?>
                <a href="orders.php?<?php echo $qs; ?>"
                   class="btn btn-sm rounded-pill <?php echo $is_act ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                    <?php echo $lbl; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Search -->
            <form action="orders.php" method="GET" class="mb-3">
                <?php if ($filter_status): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>"><?php endif; ?>
                <div class="input-group rounded-pill overflow-hidden shadow-sm" style="max-width:420px;">
                    <input type="text" name="q" class="form-control border-0 bg-light"
                           placeholder="Search by order # or customer..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-success px-4" type="submit"><i class="bi bi-search"></i></button>
                    <?php if ($search): ?>
                    <a href="orders.php<?php echo $filter_status ? '?status='.$filter_status : ''; ?>"
                       class="btn btn-outline-secondary px-3"><i class="bi bi-x"></i></a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Orders Table -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Order #</th>
                                    <th>Customer</th>
                                    <th>Items Ordered</th>
                                    <th>Total</th>
                                    <th>Payment</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th class="pe-4 text-end">Update Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($orders->num_rows > 0): ?>
                                <?php while ($o = $orders->fetch_assoc()): ?>
                                <?php
                                    // Fetch items belonging to this seller for this order
                                    $items_stmt = $conn->prepare(
                                        "SELECT oi.quantity, p.title_en
                                         FROM order_items oi
                                         JOIN products p ON oi.product_id = p.id
                                         WHERE oi.order_id = ? AND oi.seller_id = ?"
                                    );
                                    $items_stmt->bind_param('ii', $o['id'], $seller_id);
                                    $items_stmt->execute();
                                    $items = $items_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                                    $badge_color = $status_map[$o['order_status']] ?? 'secondary';
                                ?>
                                <tr>
                                    <td class="ps-4">
                                        <strong class="text-success">#<?php echo htmlspecialchars($o['order_number']); ?></strong>
                                    </td>
                                    <td>
                                        <p class="mb-0 fw-medium"><?php echo htmlspecialchars($o['first_name'] . ' ' . $o['last_name']); ?></p>
                                        <small class="text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($o['phone'] ?? '—'); ?></small>
                                    </td>
                                    <td>
                                        <?php foreach ($items as $it): ?>
                                            <div class="small"><i class="bi bi-dot text-success"></i> <?php echo $it['quantity']; ?>× <?php echo htmlspecialchars($it['title_en']); ?></div>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo $site_settings['currency'] . number_format($o['grand_total'], 2); ?></strong>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($o['payment_method']); ?></span></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo date('d M Y', strtotime($o['created_at'])); ?><br>
                                            <?php echo date('h:i A', strtotime($o['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $badge_color; ?> rounded-pill px-3 py-1">
                                            <?php echo ucfirst(htmlspecialchars($o['order_status'])); ?>
                                        </span>
                                    </td>
                                     <td class="pe-4 text-end">
                                         <a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-success rounded-pill px-3">
                                             <i class="bi bi-eye me-1"></i>Details
                                         </a>
                                     </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="bi bi-bag-x fs-1 text-muted d-block mb-3"></i>
                                        <h5 class="text-muted">No orders found</h5>
                                        <p class="text-muted small">Orders will appear here when customers purchase your products.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center p-4">
                    <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>">&laquo;</a>
                            </li>
                            <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link <?php echo $i === $page ? 'bg-warning border-warning text-dark' : ''; ?>"
                                   href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>"><?php echo $i; ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>">&raquo;</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
