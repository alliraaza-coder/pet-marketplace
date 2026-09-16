<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require user login and user role
require_role('user');

$user = current_user($conn);
$user_id = $user['id'];

// 1. Fetch Total Orders
$stmt_to = $conn->prepare("SELECT COUNT(id) as total_orders FROM orders WHERE user_id = ?");
$stmt_to->bind_param("i", $user_id);
$stmt_to->execute();
$total_orders = $stmt_to->get_result()->fetch_assoc()['total_orders'];

// 2. Fetch Wishlist Items
$stmt_wl = $conn->prepare("SELECT COUNT(id) as total_wishlist FROM wishlists WHERE user_id = ?");
$stmt_wl->bind_param("i", $user_id);
$stmt_wl->execute();
$total_wishlist = $stmt_wl->get_result()->fetch_assoc()['total_wishlist'];

// 3. Fetch Pending Reviews
$stmt_pr = $conn->prepare("
    SELECT COUNT(oi.id) as pending_reviews 
    FROM order_items oi 
    JOIN orders o ON oi.order_id = o.id 
    WHERE o.user_id = ? 
      AND o.order_status = 'delivered' 
      AND NOT EXISTS (
          SELECT 1 FROM reviews r WHERE r.product_id = oi.product_id AND r.user_id = o.user_id
      )
");
$stmt_pr->bind_param("i", $user_id);
$stmt_pr->execute();
$pending_reviews = $stmt_pr->get_result()->fetch_assoc()['pending_reviews'];

// 4. Fetch Recent Orders
$stmt_ro = $conn->prepare("
    SELECT id, order_number, created_at, order_status, grand_total, payment_method, payment_status 
    FROM orders 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt_ro->bind_param("i", $user_id);
$stmt_ro->execute();
$recent_orders = $stmt_ro->get_result()->fetch_all(MYSQLI_ASSOC);

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $user['profile_pic']; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" alt="Profile Picture" class="rounded-circle mb-3 border border-3 border-success" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></h5>
                    <p class="text-muted small mb-3"><?php echo $user['email']; ?></p>
                    <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>
            
            <h2 class="fw-bold mb-4">Dashboard</h2>
            
            <!-- Stats -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 bg-primary text-white h-100">
                        <div class="card-body p-4 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-white-50">Total Orders</h6>
                                <h2 class="fw-bold mb-0"><?php echo $total_orders; ?></h2>
                            </div>
                            <div class="fs-1 opacity-50"><i class="bi bi-cart-check"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 bg-success text-white h-100">
                        <div class="card-body p-4 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-white-50">Wishlist Items</h6>
                                <h2 class="fw-bold mb-0"><?php echo $total_wishlist; ?></h2>
                            </div>
                            <div class="fs-1 opacity-50"><i class="bi bi-heart"></i></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm rounded-4 bg-warning text-dark h-100">
                        <div class="card-body p-4 d-flex align-items-center justify-content-between">
                            <div>
                                <h6 class="text-dark-50">Pending Reviews</h6>
                                <h2 class="fw-bold mb-0"><?php echo $pending_reviews; ?></h2>
                            </div>
                            <div class="fs-1 opacity-50"><i class="bi bi-star"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Orders -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0">Recent Orders</h5>
                    <a href="orders.php" class="btn btn-sm btn-link text-success text-decoration-none">View All</a>
                </div>
                <div class="card-body p-4">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Order ID</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Payment</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recent_orders)): ?>
                                    <?php foreach ($recent_orders as $order): 
                                        $badge_class = 'bg-secondary';
                                        switch ($order['order_status']) {
                                            case 'pending': $badge_class = 'bg-warning text-dark'; break;
                                            case 'accepted': $badge_class = 'bg-primary text-white'; break;
                                            case 'preparing': 
                                            case 'ready_for_shipment': $badge_class = 'bg-info text-dark'; break;
                                            case 'out_for_delivery': $badge_class = 'bg-primary text-white'; break;
                                            case 'delivered': 
                                            case 'completed': $badge_class = 'bg-success text-white'; break;
                                            case 'cancelled': $badge_class = 'bg-danger text-white'; break;
                                        }
                                        $pay_badge = ($order['payment_status'] === 'held') ? 'bg-info text-dark' : (($order['payment_status'] === 'released') ? 'bg-success' : 'bg-secondary');
                                    ?>
                                    <tr>
                                        <td>#<?php echo htmlspecialchars($order['order_number']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($order['created_at'])); ?></td>
                                        <td><span class="badge <?php echo $badge_class; ?> px-2 py-1 rounded-pill"><?php echo ucfirst(str_replace('_', ' ', $order['order_status'])); ?></span></td>
                                        <td>
                                            <div class="small fw-bold"><?php echo htmlspecialchars($order['payment_method']); ?></div>
                                            <span class="badge <?php echo $pay_badge; ?> px-2 py-1 rounded-pill" style="font-size: 0.65rem;"><?php echo ucfirst($order['payment_status']); ?></span>
                                        </td>
                                        <td><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></td>
                                        <td><a href="order_details.php?id=<?php echo $order['id']; ?>" class="btn btn-sm btn-outline-secondary">View</a></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-4 text-muted">You have not placed any orders yet.</td>
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

<?php include '../includes/footer.php'; ?>
