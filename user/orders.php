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

// Get user orders with pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Count query for pagination
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM orders WHERE user_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total_orders = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_orders / $limit);

// Fetch orders
$stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
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
                    <?php if (empty($orders)): ?>
                        <div class="text-center py-5">
                            <span class="d-inline-flex align-items-center justify-content-center bg-light text-muted rounded-circle p-4 mb-3" style="width: 80px; height: 80px;">
                                <i class="bi bi-box-seam" style="font-size: 2.5rem;"></i>
                            </span>
                            <h4 class="fw-bold">No Orders Found</h4>
                            <p class="text-muted mb-4">You haven't placed any orders yet.</p>
                            <a href="../shop.php" class="btn btn-success rounded-pill px-4 fw-bold">Shop Now</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Order ID</th>
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
                                            <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                            <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($order['payment_method']); ?></td>
                                            <td>
                                                <?php if ($order['payment_status'] === 'released'): ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success px-2 py-1 rounded-pill">Released</span>
                                                <?php elseif ($order['payment_status'] === 'held'): ?>
                                                    <span class="badge bg-info bg-opacity-10 text-info px-2 py-1 rounded-pill">In Escrow</span>
                                                <?php elseif ($order['payment_status'] === 'payment_submitted'): ?>
                                                    <span class="badge bg-info bg-opacity-10 text-info px-2 py-1 rounded-pill">Under Review</span>
                                                <?php elseif ($order['payment_status'] === 'rejected'): ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger px-2 py-1 rounded-pill">Rejected</span>
                                                <?php elseif ($order['payment_status'] === 'refunded'): ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-secondary px-2 py-1 rounded-pill">Refunded</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning bg-opacity-10 text-warning px-2 py-1 rounded-pill">Pending Payment</span>
                                                <?php endif; ?>
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
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                            <a class="page-link <?php echo $page == $i ? 'bg-success border-success text-white' : 'text-success'; ?>" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link text-success" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
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
