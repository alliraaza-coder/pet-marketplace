<?php
/**
 * User Order History Page
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require user role
require_role('user');

$user = current_user($conn);
$user_id = $_SESSION['user_id'];

// Get filter status
$filter_status = sanitize_input($_GET['status'] ?? '');

// Live Counts per status for this buyer
$status_counts_stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS all_count,
        COALESCE(SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_count,
        COALESCE(SUM(CASE WHEN order_status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END), 0) AS active_count,
        COALESCE(SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled_count
    FROM orders WHERE user_id = ?
");
$status_counts_stmt->bind_param("i", $user_id);
$status_counts_stmt->execute();
$sc = $status_counts_stmt->get_result()->fetch_assoc();

// Build query
$where = "WHERE user_id = ?";
$params = [$user_id];
$types = "i";

if ($filter_status === 'completed') {
    $where .= " AND order_status = 'completed'";
} elseif ($filter_status === 'active') {
    $where .= " AND order_status NOT IN ('completed', 'cancelled')";
} elseif ($filter_status === 'cancelled') {
    $where .= " AND order_status = 'cancelled'";
}

// Get user orders with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Count query for pagination
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders $where");
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_orders = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_orders / $limit);

// Fetch orders
$stmt = $conn->prepare("SELECT * FROM orders $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
$types_p = $types . "ii";
$params_p = array_merge($params, [$limit, $offset]);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch items for these orders with product images
$order_items_map = [];
if (!empty($orders)) {
    $order_ids = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $types_items = str_repeat('i', count($order_ids));
    $stmt_items = $conn->prepare("
        SELECT oi.order_id, oi.product_id, oi.quantity, p.title_en,
               (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image_url
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id IN ($placeholders)
    ");
    $stmt_items->bind_param($types_items, ...$order_ids);
    $stmt_items->execute();
    $res_items = $stmt_items->get_result();
    while ($row_item = $res_items->fetch_assoc()) {
        $order_items_map[$row_item['order_id']][] = $row_item;
    }
}

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $user['profile_pic']; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" alt="Profile Picture" class="rounded-circle mb-3 border border-3 border-success" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                    <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="account.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-credit-card-2-back me-2"></i> Payment Account</a>
                    <a href="refunds.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-arrow-return-left me-2"></i> My Refunds</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <?php
                        $notif_count_q = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
                        $notif_count_q->bind_param("i", $user_id);
                        $notif_count_q->execute();
                        $orders_page_unread = $notif_count_q->get_result()->fetch_assoc()['c'];
                    ?>
                    <a href="../notifications.php" class="list-group-item list-group-item-action py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bell me-2"></i> Notifications</span>
                        <?php if ($orders_page_unread > 0): ?><span class="badge bg-danger rounded-pill"><?php echo $orders_page_unread; ?></span><?php endif; ?>
                    </a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>
            
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h3 class="fw-bold mb-0 text-success">My Orders</h3>
                    <p class="text-muted mb-0 small">Track your purchase history and order status</p>
                </div>
                <div class="card-body px-4 pb-4">
                    <!-- Status Filter Tabs -->
                    <div class="d-flex gap-2 mb-4 flex-wrap">
                        <a href="orders.php" class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-2 <?php echo empty($filter_status) ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                            <span>All</span>
                            <span class="badge <?php echo empty($filter_status) ? 'bg-light text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?> rounded-pill" style="font-size:0.7rem;"><?php echo (int)($sc['all_count'] ?? 0); ?></span>
                        </a>
                        <a href="orders.php?status=completed" class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-2 <?php echo $filter_status === 'completed' ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                            <span>Completed</span>
                            <span class="badge <?php echo $filter_status === 'completed' ? 'bg-light text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?> rounded-pill" style="font-size:0.7rem;"><?php echo (int)($sc['completed_count'] ?? 0); ?></span>
                        </a>
                        <a href="orders.php?status=active" class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-2 <?php echo $filter_status === 'active' ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                            <span>Active</span>
                            <span class="badge <?php echo $filter_status === 'active' ? 'bg-light text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?> rounded-pill" style="font-size:0.7rem;"><?php echo (int)($sc['active_count'] ?? 0); ?></span>
                        </a>
                        <a href="orders.php?status=cancelled" class="btn btn-sm rounded-pill d-inline-flex align-items-center gap-2 <?php echo $filter_status === 'cancelled' ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                            <span>Cancelled</span>
                            <span class="badge <?php echo $filter_status === 'cancelled' ? 'bg-light text-dark' : 'bg-secondary bg-opacity-25 text-dark'; ?> rounded-pill" style="font-size:0.7rem;"><?php echo (int)($sc['cancelled_count'] ?? 0); ?></span>
                        </a>
                    </div>

                    <?php if (empty($orders)): ?>
                        <div class="text-center py-5">
                            <span class="d-inline-flex align-items-center justify-content-center bg-light text-muted rounded-circle p-4 mb-3" style="width: 80px; height: 80px;">
                                <i class="bi bi-box-seam" style="font-size: 2.5rem;"></i>
                            </span>
                            <h4 class="fw-bold">No Orders Found</h4>
                            <p class="text-muted mb-4">No orders match the selected criteria.</p>
                            <a href="../shop.php" class="btn btn-success rounded-pill px-4 fw-bold">Shop Now</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
                                        <th>Product</th>
                                        <th>Date</th>
                                        <th>Total</th>
                                        <th>Payment Method</th>
                                        <th>Payment</th>
                                        <th>Order Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td class="fw-bold text-dark">#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                            <td>
                                                <?php 
                                                $items = $order_items_map[$order['id']] ?? [];
                                                if (!empty($items)):
                                                    foreach ($items as $it):
                                                        $p_img = !empty($it['image_url']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($it['image_url']) : get_product_image_url($conn, $it['product_id']);
                                                ?>
                                                    <div class="d-flex align-items-center gap-2 mb-1">
                                                        <img src="<?php echo $p_img; ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 42px; height: 42px;" onerror="this.src='https://placehold.co/42x42?text=Pet';">
                                                        <div class="text-truncate" style="max-width: 140px;" title="<?php echo htmlspecialchars($it['title_en']); ?>">
                                                            <span class="small fw-semibold text-dark d-block text-truncate"><?php echo htmlspecialchars($it['title_en']); ?></span>
                                                            <small class="text-muted">Qty: <?php echo $it['quantity']; ?></small>
                                                        </div>
                                                    </div>
                                                <?php 
                                                    endforeach;
                                                else: 
                                                ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                            <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($order['payment_method']); ?></td>
                                            <td>
                                                <?php
                                            $ps = $order['payment_status'];
                                            // Determine badge text, color, icon for payment status
                                            if ($ps === 'pending') {
                                                // Buyer hasn't sent payment yet
                                                $ps_label = 'Pending Payment';
                                                $ps_cls   = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                                $ps_icon  = 'bi-clock';
                                            } elseif ($ps === 'payment_submitted') {
                                                // Buyer sent payment, waiting for admin to approve
                                                $ps_label = 'Payment Submitted';
                                                $ps_cls   = 'bg-info bg-opacity-10 text-info border border-info';
                                                $ps_icon  = 'bi-send';
                                            } elseif ($ps === 'held') {
                                                // Admin approved — payment is delivered & held in escrow
                                                $ps_label = 'Payment Delivered';
                                                $ps_cls   = 'bg-success bg-opacity-10 text-success border border-success';
                                                $ps_icon  = 'bi-shield-check';
                                            } elseif ($ps === 'seller_payment_sent') {
                                                // Admin sent payout to seller, awaiting seller confirmation
                                                $ps_label = 'Payment Delivered';
                                                $ps_cls   = 'bg-success bg-opacity-10 text-success border border-success';
                                                $ps_icon  = 'bi-shield-check';
                                            } elseif ($ps === 'seller_payment_received') {
                                                // Seller confirmed payout — fully completed
                                                $ps_label = 'Payment Delivered';
                                                $ps_cls   = 'bg-success bg-opacity-10 text-success border border-success';
                                                $ps_icon  = 'bi-shield-check';
                                            } elseif ($ps === 'released' || $ps === 'payout_submitted') {
                                                // Legacy released status
                                                $ps_label = 'Payment Delivered';
                                                $ps_cls   = 'bg-success bg-opacity-10 text-success border border-success';
                                                $ps_icon  = 'bi-shield-check';
                                            } elseif ($ps === 'rejected') {
                                                // Admin rejected buyer's payment proof
                                                $ps_label = 'Payment Rejected';
                                                $ps_cls   = 'bg-danger bg-opacity-10 text-danger border border-danger';
                                                $ps_icon  = 'bi-x-circle';
                                            } elseif (in_array($ps, ['refund_submitted', 'refund_sent', 'refund_received', 'refunded'])) {
                                                // Order cancelled after payment delivered → refund issued
                                                $ps_label = 'Payment Refunded';
                                                $ps_cls   = 'bg-secondary bg-opacity-10 text-secondary border border-secondary';
                                                $ps_icon  = 'bi-arrow-return-left';
                                            } else {
                                                // Fallback — any other status
                                                $ps_label = 'Pending Payment';
                                                $ps_cls   = 'bg-warning bg-opacity-10 text-warning border border-warning';
                                                $ps_icon  = 'bi-clock';
                                            }
                                            ?>
                                            <span class="badge <?= $ps_cls ?> px-2 py-1 rounded-pill d-inline-flex align-items-center gap-1">
                                                <i class="bi <?= $ps_icon ?>" style="font-size:.7rem;"></i>
                                                <?= $ps_label ?>
                                            </span>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $order['order_status'];
                                                $badge_class = 'bg-secondary bg-opacity-10 text-secondary';
                                                if ($status === 'pending_payment') $badge_class = 'bg-warning bg-opacity-10 text-warning';
                                                elseif ($status === 'pending') $badge_class = 'bg-warning bg-opacity-10 text-warning';
                                                elseif ($status === 'processing' || $status === 'preparing' || $status === 'ready_for_shipment') $badge_class = 'bg-info bg-opacity-10 text-info';
                                                elseif ($status === 'shipped' || $status === 'out_for_delivery') $badge_class = 'bg-primary bg-opacity-10 text-primary';
                                                elseif ($status === 'delivered' || $status === 'completed') $badge_class = 'bg-success bg-opacity-10 text-success';
                                                elseif ($status === 'cancelled') $badge_class = 'bg-danger bg-opacity-10 text-danger';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?> px-3 py-2 rounded-pill fw-semibold mt-1">
                                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($status))); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-sm rounded-pill px-3 <?php echo in_array($order['payment_status'], ['pending','rejected']) ? 'btn-warning text-dark fw-bold' : 'btn-outline-primary'; ?>">
                                                    <?php echo in_array($order['payment_status'], ['pending','rejected']) ? 'Pay Now' : 'View Details'; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($filter_status) ? '&status='.$filter_status : ''; ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                            <a class="page-link <?php echo $page == $i ? 'bg-success border-success text-white' : 'text-success'; ?>" href="?page=<?php echo $i; ?><?php echo !empty($filter_status) ? '&status='.$filter_status : ''; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link text-success" href="?page=<?php echo $page + 1; ?><?php echo !empty($filter_status) ? '&status='.$filter_status : ''; ?>" aria-label="Next">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
