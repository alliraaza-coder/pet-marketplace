<?php
/**
 * Admin Orders Management
 * Phase 3.3
 */
$page_title = "Orders Management";
$page_heading = "Manage Orders";
include __DIR__ . '/partials/header.php';

// Search and Filters
$search        = trim($_GET['q'] ?? '');
$order_status  = trim($_GET['order_status'] ?? '');
$payment_status= trim($_GET['payment_status'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 15;
$offset        = ($page - 1) * $per_page;

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND o.order_number LIKE ?";
    $params[] = "%$search%";
    $types   .= "s";
}
if ($order_status !== '') {
    $where   .= " AND o.order_status = ?";
    $params[] = $order_status;
    $types   .= "s";
}
if ($payment_status !== '') {
    $where   .= " AND o.payment_status = ?";
    $params[] = $payment_status;
    $types   .= "s";
}

// Count total
$cnt_sql = "SELECT COUNT(DISTINCT o.id) as total FROM orders o JOIN order_items oi ON o.id = oi.order_id $where";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_orders = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages  = max(1, ceil($total_orders / $per_page));

// Fetch orders
$sql = "SELECT o.id, o.order_number, o.grand_total, o.payment_status, o.order_status, o.created_at,
        b.first_name AS buyer_fn, b.last_name AS buyer_ln,
        s.first_name AS seller_fn, s.last_name AS seller_ln
        FROM orders o 
        JOIN users b ON o.user_id = b.id
        JOIN order_items oi ON o.id = oi.order_id
        JOIN users s ON oi.seller_id = s.id
        $where 
        GROUP BY o.id
        ORDER BY o.created_at DESC LIMIT ? OFFSET ?";

$params_p = array_merge($params, [$per_page, $offset]);
$types_p  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$orders = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Orders Management</h4>
        <p class="text-muted small mb-0"><?php echo $total_orders; ?> order(s) found</p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="orders.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control bg-light border-0" placeholder="Search by Order #..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="order_status" class="form-select bg-light border-0">
                <option value="">All Order Statuses</option>
                <option value="pending" <?php echo $order_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="accepted" <?php echo $order_status === 'accepted' ? 'selected' : ''; ?>>Accepted (Preparing)</option>
                <option value="shipped" <?php echo $order_status === 'shipped' ? 'selected' : ''; ?>>Out For Delivery</option>
                <option value="delivered" <?php echo $order_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                <option value="completed" <?php echo $order_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $order_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-3">
            <select name="payment_status" class="form-select bg-light border-0">
                <option value="">All Payment Statuses</option>
                <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="payment_received" <?php echo $payment_status === 'payment_received' ? 'selected' : ''; ?>>Payment Received</option>
                <option value="held" <?php echo $payment_status === 'held' ? 'selected' : ''; ?>>Held in Escrow</option>
                <option value="released" <?php echo $payment_status === 'released' ? 'selected' : ''; ?>>Released</option>
                <option value="refunded" <?php echo $payment_status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">Filter</button>
            <?php if ($search || $order_status || $payment_status): ?>
                <a href="orders.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Orders Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Seller</th>
                        <th>Amount</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                        <th>Date</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($orders && $orders->num_rows > 0): ?>
                        <?php while ($o = $orders->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($o['order_number']); ?></td>
                                <td><?php echo htmlspecialchars($o['buyer_fn'] . ' ' . $o['buyer_ln']); ?></td>
                                <td><?php echo htmlspecialchars($o['seller_fn'] . ' ' . $o['seller_ln']); ?></td>
                                <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($o['grand_total'], 2); ?></td>
                                <td>
                                    <?php
                                    $p_bg = 'bg-secondary';
                                    if ($o['payment_status'] === 'held') $p_bg = 'bg-warning text-dark';
                                    if ($o['payment_status'] === 'released') $p_bg = 'bg-success';
                                    if ($o['payment_status'] === 'refunded') $p_bg = 'bg-danger';
                                    ?>
                                    <span class="badge <?php echo $p_bg; ?> rounded-pill px-3 py-1">
                                        <?php echo ucfirst(str_replace('_', ' ', $o['payment_status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $o_bg = 'bg-secondary';
                                    if ($o['order_status'] === 'completed' || $o['order_status'] === 'delivered') $o_bg = 'bg-success';
                                    if ($o['order_status'] === 'cancelled') $o_bg = 'bg-danger';
                                    if ($o['order_status'] === 'pending') $o_bg = 'bg-warning text-dark';
                                    if ($o['order_status'] === 'shipped') $o_bg = 'bg-info text-dark';
                                    ?>
                                    <span class="badge <?php echo $o_bg; ?> rounded-pill px-3 py-1">
                                        <?php echo ucfirst(str_replace('_', ' ', $o['order_status'])); ?>
                                    </span>
                                </td>
                                <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?></small></td>
                                <td class="pe-4 text-end">
                                    <a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">View Details</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">No orders found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center p-3">
            <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&order_status=<?php echo urlencode($order_status); ?>&payment_status=<?php echo urlencode($payment_status); ?>">&laquo;</a></li>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&order_status=<?php echo urlencode($order_status); ?>&payment_status=<?php echo urlencode($payment_status); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&order_status=<?php echo urlencode($order_status); ?>&payment_status=<?php echo urlencode($payment_status); ?>">&raquo;</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
