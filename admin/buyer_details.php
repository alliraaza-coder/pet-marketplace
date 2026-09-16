<?php
/**
 * Admin Buyer Details
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];
$buyer_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$buyer_id) {
    $_SESSION['error'] = "Invalid buyer ID.";
    header('Location: buyers.php');
    exit;
}

// Fetch Buyer Details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'user' AND is_deleted = 0");
$stmt->bind_param("i", $buyer_id);
$stmt->execute();
$buyer = $stmt->get_result()->fetch_assoc();

if (!$buyer) {
    $_SESSION['error'] = "Buyer not found or has been deleted.";
    header('Location: buyers.php');
    exit;
}

// Fetch Buyer Metrics
$metrics_stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN payment_status = 'released' THEN grand_total ELSE 0 END), 0) AS total_spent
    FROM orders WHERE user_id = ?
");
$metrics_stmt->bind_param("i", $buyer_id);
$metrics_stmt->execute();
$metrics = $metrics_stmt->get_result()->fetch_assoc();

// Fetch Buyer Orders
$orders_stmt = $conn->prepare("
    SELECT id, order_number, grand_total, payment_method, payment_status, order_status, created_at 
    FROM orders 
    WHERE user_id = ? 
    ORDER BY created_at DESC
");
$orders_stmt->bind_param("i", $buyer_id);
$orders_stmt->execute();
$orders = $orders_stmt->get_result();

$page_title = "Buyer Details - " . htmlspecialchars($buyer['first_name'] . ' ' . $buyer['last_name']);
$page_heading = "Buyer Details";
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4">
    <a href="buyers.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Buyers</a>
</div>

<div class="row g-4">
    <!-- Buyer Profile Card -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center p-4">
                <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($buyer['profile_pic'] ?? 'default-user.png'); ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($buyer['first_name'].' '.$buyer['last_name']); ?>'" class="rounded-circle mb-3 border shadow-sm" style="width: 120px; height: 120px; object-fit: cover;">
                
                <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($buyer['first_name'] . ' ' . $buyer['last_name']); ?></h4>
                <p class="text-muted mb-3">Buyer ID: #<?php echo $buyer['id']; ?></p>
                
                <?php if ($buyer['status'] === 'active'): ?>
                    <span class="badge bg-success rounded-pill px-3 py-2 mb-4">Active Account</span>
                <?php else: ?>
                    <span class="badge bg-danger rounded-pill px-3 py-2 mb-4"><?php echo ucfirst($buyer['status']); ?></span>
                <?php endif; ?>

                <ul class="list-group list-group-flush text-start mb-4">
                    <li class="list-group-item px-0"><i class="bi bi-envelope text-muted me-2"></i> <?php echo htmlspecialchars($buyer['email']); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-telephone text-muted me-2"></i> <?php echo htmlspecialchars($buyer['phone'] ?? 'Not provided'); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-geo-alt text-muted me-2"></i> <?php echo htmlspecialchars($buyer['city'] ?? 'Unknown City'); ?>, <?php echo htmlspecialchars($buyer['country'] ?? 'Unknown Country'); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-calendar-event text-muted me-2"></i> Joined: <?php echo date('M d, Y', strtotime($buyer['created_at'])); ?></li>
                </ul>

                <div class="d-grid gap-2">
                    <?php if ($buyer['status'] === 'active'): ?>
                        <form action="buyers.php" method="POST">
                            <input type="hidden" name="action" value="deactivate">
                            <input type="hidden" name="buyer_id" value="<?php echo $buyer['id']; ?>">
                            <button type="submit" class="btn btn-warning w-100 fw-bold rounded-pill" onclick="return confirm('Deactivate this buyer account?');">Deactivate Account</button>
                        </form>
                    <?php else: ?>
                        <form action="buyers.php" method="POST">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="buyer_id" value="<?php echo $buyer['id']; ?>">
                            <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill">Activate Account</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Metrics & Order History -->
    <div class="col-lg-8">
        <!-- Metrics Row -->
        <div class="row g-4 mb-4">
            <div class="col-sm-6">
                <div class="card border-0 shadow-sm bg-primary text-white p-4 h-100 rounded-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Total Orders Placed</h6>
                            <h2 class="fw-bold mb-0"><?php echo number_format($metrics['total_orders']); ?></h2>
                        </div>
                        <i class="bi bi-cart fs-1 text-white-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div class="card border-0 shadow-sm bg-success text-white p-4 h-100 rounded-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Total Spent</h6>
                            <h2 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($metrics['total_spent'], 2); ?></h2>
                        </div>
                        <i class="bi bi-wallet2 fs-1 text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Orders Table -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Order History</h5>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">Order #</th>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th class="pe-4 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($orders && $orders->num_rows > 0): ?>
                                <?php while ($o = $orders->fetch_assoc()): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold text-primary">#<?php echo htmlspecialchars($o['order_number']); ?></td>
                                        <td><small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($o['created_at'])); ?></small></td>
                                        <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($o['grand_total'], 2); ?></td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <?php echo ucfirst(str_replace('_', ' ', $o['payment_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $badge_class = 'bg-secondary';
                                            if ($o['order_status'] === 'completed' || $o['order_status'] === 'delivered') $badge_class = 'bg-success';
                                            if ($o['order_status'] === 'cancelled') $badge_class = 'bg-danger';
                                            if ($o['order_status'] === 'pending') $badge_class = 'bg-warning text-dark';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?> rounded-pill">
                                                <?php echo ucfirst(str_replace('_', ' ', $o['order_status'])); ?>
                                            </span>
                                        </td>
                                        <td class="pe-4 text-end">
                                            <a href="order_details.php?id=<?php echo $o['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill">View</a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-cart-x fs-1 d-block mb-2"></i>
                                        This buyer has not placed any orders yet.
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

<?php include __DIR__ . '/partials/footer.php'; ?>
