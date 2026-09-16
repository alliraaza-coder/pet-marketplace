<?php
/**
 * Admin Sidebar Component
 */
$current_page = basename($_SERVER['PHP_SELF']);
?>
<div class="col-lg-2 sidebar">
    <div class="p-3 text-center border-bottom border-secondary mb-3">
        <h4 class="text-white fw-bold mb-0"><i class="bi bi-feather text-danger"></i> Admin Panel</h4>
        <small class="text-white-50"><?php echo htmlspecialchars($site_settings['site_name']); ?></small>
    </div>
    
    <div class="px-3 mb-3 d-flex align-items-center">
        <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($user['profile_pic'] ?? 'default-user.png'); ?>" onerror="this.src='https://ui-avatars.com/api/?name=Admin'" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
        <div class="overflow-hidden">
            <h6 class="text-white mb-0 text-truncate"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h6>
            <small class="text-success fw-bold d-block" style="font-size:0.75rem;"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> <?php echo ucfirst(htmlspecialchars($user['admin_role'] ?? 'Admin')); ?></small>
        </div>
    </div>
    
    <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' ? 'active' : ''; ?>"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a>
    <a href="buyers.php" class="<?php echo ($current_page === 'buyers.php' || $current_page === 'buyer_details.php') ? 'active' : ''; ?>"><i class="bi bi-people me-2"></i> Buyers</a>
    <a href="sellers.php" class="<?php echo ($current_page === 'sellers.php' || $current_page === 'seller_details.php') ? 'active' : ''; ?>"><i class="bi bi-shop me-2"></i> Sellers</a>
    <a href="products.php" class="<?php echo ($current_page === 'products.php' || $current_page === 'product_details.php') ? 'active' : ''; ?>"><i class="bi bi-box me-2"></i> Products</a>
    <a href="categories.php" class="<?php echo $current_page === 'categories.php' ? 'active' : ''; ?>"><i class="bi bi-tags me-2"></i> Categories</a>
    <a href="orders.php" class="<?php echo ($current_page === 'orders.php' || $current_page === 'order_details.php') ? 'active' : ''; ?>"><i class="bi bi-cart me-2"></i> Orders</a>
    <a href="escrow.php" class="<?php echo $current_page === 'escrow.php' ? 'active' : ''; ?>"><i class="bi bi-shield-lock me-2"></i> Escrow Payments</a>
    <a href="transactions.php" class="<?php echo $current_page === 'transactions.php' ? 'active' : ''; ?>"><i class="bi bi-receipt me-2"></i> Transactions</a>
    <a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>"><i class="bi bi-bar-chart-line me-2"></i> Reports</a>
    <a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>"><i class="bi bi-gear me-2"></i> Settings</a>
    <a href="profile.php" class="<?php echo $current_page === 'profile.php' ? 'active' : ''; ?>"><i class="bi bi-person-gear me-2"></i> Profile</a>
    <a href="activity_logs.php" class="<?php echo $current_page === 'activity_logs.php' ? 'active' : ''; ?>"><i class="bi bi-journal-text me-2"></i> Activity Logs</a>
    <a href="../logout.php" class="text-danger mt-4 border-top"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
</div>
