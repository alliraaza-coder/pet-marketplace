<?php
/**
 * Seller Sidebar Partial — Phase 3.1
 * Include in all seller pages: include 'partials/sidebar.php';
 */

// Determine active page
$current_page = basename($_SERVER['PHP_SELF']);
$seller_id_check = isset($seller_id) ? $seller_id : (int)$_SESSION['user_id'];
$user_partial = isset($user) ? $user : current_user($conn);
?>
<div class="card border-0 shadow-sm rounded-4 sticky-top" style="top:80px;">

    <!-- Profile -->
    <div class="card-body text-center p-3 border-bottom">
        <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($user_partial['profile_pic'] ?? 'default-user.png'); ?>"
             onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode(($user_partial['first_name'] ?? '') . ' ' . ($user_partial['last_name'] ?? '')); ?>&background=random'"
             alt="Profile"
             class="rounded-circle border border-3 border-warning mb-2"
             style="width:65px;height:65px;object-fit:cover;">
        <p class="fw-bold mb-0 small"><?php echo htmlspecialchars(($user_partial['first_name'] ?? '') . ' ' . ($user_partial['last_name'] ?? '')); ?></p>
        <span class="badge bg-warning text-dark rounded-pill small">Seller</span>
    </div>

    <!-- Nav Links -->
    <div class="list-group list-group-flush rounded-bottom-4 overflow-hidden">
        <?php
        $nav_items = [
            ['href' => 'dashboard.php',    'icon' => 'bi-speedometer2', 'label' => 'Dashboard'],
            ['href' => 'products.php',     'icon' => 'bi-box-seam',     'label' => 'My Products'],
            ['href' => 'add_product.php',  'icon' => 'bi-plus-circle',  'label' => 'Add Product'],
            ['href' => 'orders.php',       'icon' => 'bi-bag-check',    'label' => 'My Orders'],
            ['href' => 'analytics.php',    'icon' => 'bi-graph-up',     'label' => 'Earnings'],
            ['href' => '../user/profile.php','icon'=>'bi-person-gear',  'label' => 'Profile'],
        ];
        foreach ($nav_items as $item):
            $is_active = ($current_page === $item['href']) ? 'active bg-warning text-dark fw-bold border-warning' : '';
        ?>
        <a href="<?php echo $item['href']; ?>"
           class="list-group-item list-group-item-action py-3 <?php echo $is_active; ?>">
            <i class="bi <?php echo $item['icon']; ?> me-2"></i>
            <?php echo $item['label']; ?>
        </a>
        <?php endforeach; ?>
        <a href="../logout.php"
           class="list-group-item list-group-item-action py-3 text-danger">
            <i class="bi bi-box-arrow-right me-2"></i> Logout
        </a>
    </div>
</div>
