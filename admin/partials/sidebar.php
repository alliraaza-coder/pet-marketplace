<?php
/**
 * Admin Sidebar Component
 * Includes per-section notification badges showing pending action counts.
 */
$current_page = basename($_SERVER['PHP_SELF']);

// ── Per-section badge counts ───────────────────────────────────────────────

// Orders: new orders that are still 'pending' (payment held but not yet processed)
$orders_badge = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM orders
     WHERE order_status IN ('pending','processing')
       AND payment_status = 'held'"
)->fetch_assoc()['c'] ?? 0);

// Escrow: sum of 3 sub-sections needing admin action
//  A) Buyer payments submitted but not yet approved
$escrow_a = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM orders WHERE payment_status = 'payment_submitted'"
)->fetch_assoc()['c'] ?? 0);

//  B) Seller payments pending (delivery confirmed, not yet paid)
$escrow_b = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM orders
     WHERE payment_status IN ('held','seller_payment_sent')
       AND seller_delivered = 1 AND buyer_received = 1
       AND seller_payment_received = 0"
)->fetch_assoc()['c'] ?? 0);

//  C) Buyer refunds pending (cancelled orders with no refund sent yet)
$escrow_c = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM orders
     WHERE ((payment_status IN ('held','refund_submitted') AND order_status = 'cancelled')
        OR  (order_status = 'cancelled' AND payment_reference IS NOT NULL
             AND payment_status NOT IN ('pending','rejected')))
       AND buyer_refund_sent_at IS NULL"
)->fetch_assoc()['c'] ?? 0);

$escrow_badge = $escrow_a + $escrow_b + $escrow_c;

// Reviews: pending approval
$reviews_badge = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM reviews WHERE status = 'pending'"
)->fetch_assoc()['c'] ?? 0);

// Disputes: active open disputes
$disputes_badge = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM orders WHERE dispute_status = 1 AND payment_status = 'held'"
)->fetch_assoc()['c'] ?? 0);

// User Approvals: users waiting for approval
$users_badge = (int)($conn->query(
    "SELECT COUNT(*) AS c FROM users WHERE status = 'pending'"
)->fetch_assoc()['c'] ?? 0);

// Total all-section badge (for reference if needed)
$total_sidebar_badges = $orders_badge + $escrow_badge + $reviews_badge + $disputes_badge + $users_badge;
?>

<style>
/* Sidebar links use flex so badge floats to the right */
.sidebar a {
    display: flex !important;
    align-items: center;
    justify-content: space-between;
}
.sidebar a .nav-label {
    display: flex;
    align-items: center;
    flex: 1;
    min-width: 0;
}
.sidebar-badge {
    flex-shrink: 0;
    font-size: 0.68rem;
    padding: 2px 7px;
    border-radius: 20px;
    font-weight: 700;
    line-height: 1.4;
    margin-left: 6px;
}
</style>

<div class="col-lg-2 sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="text-white fw-bold mb-0"><i class="bi bi-feather text-danger"></i> Admin Panel</h4>
        <small class="text-white-50"><?php echo htmlspecialchars($site_settings['site_name']); ?></small>
    </div>

    <div class="px-3 mb-3 d-flex align-items-center">
        <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($user['profile_pic'] ?? 'default-user.png'); ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=Admin'"
             class="rounded-circle me-2" style="width:40px;height:40px;object-fit:cover;">
        <div class="overflow-hidden">
            <h6 class="text-white mb-0 text-truncate"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h6>
            <small class="text-success fw-bold d-block" style="font-size:0.75rem;">
                <i class="bi bi-circle-fill" style="font-size:0.5rem;"></i>
                <?php echo ucfirst(htmlspecialchars($user['admin_role'] ?? 'Admin')); ?>
            </small>
        </div>
    </div>

    <!-- Dashboard -->
    <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-speedometer2 me-2"></i> Dashboard</span>
    </a>

    <!-- Buyers -->
    <a href="buyers.php" class="<?php echo in_array($current_page, ['buyers.php','buyer_details.php']) ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-people me-2"></i> Buyers</span>
    </a>

    <!-- Sellers -->
    <a href="sellers.php" class="<?php echo in_array($current_page, ['sellers.php','seller_details.php']) ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-shop me-2"></i> Sellers</span>
    </a>

    <!-- Products -->
    <a href="products.php" class="<?php echo in_array($current_page, ['products.php','product_details.php']) ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-box me-2"></i> Products</span>
    </a>

    <!-- Market Specials -->
    <a href="market_specials.php" class="<?php echo in_array($current_page, ['market_specials.php','add_market_special.php','edit_market_special.php','market_special_details.php']) ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-stars me-2"></i> Market Specials</span>
    </a>

    <!-- Categories -->
    <a href="categories.php" class="<?php echo $current_page === 'categories.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-tags me-2"></i> Categories</span>
    </a>

    <!-- Orders — badge: pending/processing orders -->
    <a href="orders.php" class="<?php echo in_array($current_page, ['orders.php','order_details.php']) ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-cart me-2"></i> Orders</span>
        <?php if ($orders_badge > 0): ?>
            <span class="sidebar-badge bg-warning text-dark" title="<?= $orders_badge ?> orders need attention">
                <?= $orders_badge > 99 ? '99+' : $orders_badge ?>
            </span>
        <?php endif; ?>
    </a>

    <!-- Escrow Payments — badge: buyer verifications + seller payments due + refunds pending -->
    <a href="escrow.php" class="<?php echo $current_page === 'escrow.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-shield-lock me-2"></i> Escrow Payments</span>
        <?php if ($escrow_badge > 0): ?>
            <span class="sidebar-badge bg-danger text-white" title="<?= $escrow_a ?> verifications, <?= $escrow_b ?> payouts, <?= $escrow_c ?> refunds">
                <?= $escrow_badge > 99 ? '99+' : $escrow_badge ?>
            </span>
        <?php endif; ?>
    </a>

    <!-- Transactions -->
    <a href="transactions.php" class="<?php echo $current_page === 'transactions.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-receipt me-2"></i> Transactions</span>
    </a>

    <!-- Reports -->
    <a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-bar-chart-line me-2"></i> Reports</span>
    </a>

    <!-- Reviews — badge: pending approval -->
    <a href="reviews.php" class="<?php echo $current_page === 'reviews.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-star me-2"></i> Reviews</span>
        <?php if ($reviews_badge > 0): ?>
            <span class="sidebar-badge bg-warning text-dark" title="<?= $reviews_badge ?> reviews pending approval">
                <?= $reviews_badge > 99 ? '99+' : $reviews_badge ?>
            </span>
        <?php endif; ?>
    </a>

    <!-- Disputes — badge: active disputes -->
    <a href="disputes.php" class="<?php echo $current_page === 'disputes.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-exclamation-octagon me-2"></i> Disputes</span>
        <?php if ($disputes_badge > 0): ?>
            <span class="sidebar-badge bg-danger text-white" title="<?= $disputes_badge ?> active disputes">
                <?= $disputes_badge > 99 ? '99+' : $disputes_badge ?>
            </span>
        <?php endif; ?>
    </a>

    <!-- User Approvals — badge: pending users -->
    <a href="user_approval.php" class="<?php echo $current_page === 'user_approval.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-person-check me-2"></i> User Approvals</span>
        <?php if ($users_badge > 0): ?>
            <span class="sidebar-badge bg-danger text-white" title="<?= $users_badge ?> users pending approval">
                <?= $users_badge > 99 ? '99+' : $users_badge ?>
            </span>
        <?php endif; ?>
    </a>

    <!-- Settings -->
    <a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-gear me-2"></i> Settings</span>
    </a>

    <!-- Payment Accounts (sub-link under Settings) -->
    <a href="settings.php#payment-accounts" style="font-size:0.85rem; padding-left:36px;">
        <span class="nav-label"><i class="bi bi-credit-card-2-back me-2"></i> Payment Accounts</span>
    </a>

    <!-- Profile -->
    <a href="profile.php" class="<?php echo $current_page === 'profile.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-person-gear me-2"></i> Profile</span>
    </a>

    <!-- Activity Logs -->
    <a href="activity_logs.php" class="<?php echo $current_page === 'activity_logs.php' ? 'active' : ''; ?>">
        <span class="nav-label"><i class="bi bi-journal-text me-2"></i> Activity Logs</span>
    </a>

    <!-- Logout -->
    <a href="../logout.php" class="text-danger mt-4 border-top">
        <span class="nav-label"><i class="bi bi-box-arrow-right me-2"></i> Logout</span>
    </a>
</div>

