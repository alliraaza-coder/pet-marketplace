<?php
// Include config if not already included
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Calculate dynamic badge counts
$cart_count = isset($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
$wishlist_count = 0;

if (is_logged_in() && isset($conn)) {
    $stmt_wl = $conn->prepare("SELECT COUNT(id) as wl_count FROM wishlists WHERE user_id = ?");
    $stmt_wl->bind_param("i", $_SESSION['user_id']);
    $stmt_wl->execute();
    $wl_res = $stmt_wl->get_result()->fetch_assoc();
    $wishlist_count = $wl_res['wl_count'] ?? 0;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang; ?>" dir="<?php echo $current_lang === 'ur' ? 'rtl' : 'ltr'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_settings['site_name']; ?> - Premium Pet Marketplace</title>
    <meta name="description" content="Buy and sell Birds, Animals, Pets, Accessories, and Bird Foods.">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/style.css">
    
    <?php if($current_lang === 'ur'): ?>
    <style>
        body { font-family: 'Jameel Noori Nastaleeq', 'Inter', sans-serif; }
    </style>
    <?php endif; ?>
</head>
<body>

<!-- Top Bar -->
<div class="bg-dark text-light py-1" style="font-size: 0.85rem;">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-none d-md-block">
            <i class="bi bi-envelope me-1"></i> <?php echo $site_settings['support_email']; ?> | 
            <i class="bi bi-telephone me-1"></i> <?php echo $site_settings['phone']; ?>
        </div>
        <div class="d-flex align-items-center gap-3">
            <a href="?lang=en" class="text-light text-decoration-none">English</a>
            <span class="text-muted">|</span>
            <a href="?lang=ur" class="text-light text-decoration-none">اردو</a>
        </div>
    </div>
</div>

<!-- Main Header -->
<header class="main-header bg-white">
    <div class="container py-3">
        <div class="row align-items-center">
            <!-- Logo -->
            <div class="col-6 col-md-3 col-lg-2">
                <a href="<?php echo BASE_URL; ?>/index.php" class="d-flex align-items-center text-decoration-none">
                    <i class="bi bi-feather fs-2 text-success me-2"></i>
                    <h4 class="mb-0 fw-bold text-dark"><?php echo $site_settings['site_name']; ?></h4>
                </a>
            </div>
            
            <!-- Search Bar -->
            <div class="col-12 col-md-5 col-lg-6 order-3 order-md-2 mt-3 mt-md-0">
                <form action="<?php echo BASE_URL; ?>/shop.php" method="GET" class="d-flex w-100 position-relative">
                    <input type="text" name="q" class="form-control rounded-pill pe-5 py-2" placeholder="<?php echo __('search'); ?>">
                    <button type="submit" class="btn position-absolute end-0 top-0 bottom-0 text-success rounded-pill px-3">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>
            
            <!-- Icons & Theme Toggle -->
            <div class="col-6 col-md-4 col-lg-4 order-2 order-md-3 d-flex justify-content-end align-items-center gap-3">
                <button id="theme-toggle" class="btn btn-link text-dark p-0 fs-5" data-bs-toggle="tooltip" title="Toggle Dark Mode">
                    <i id="theme-icon" class="bi bi-moon"></i>
                </button>
                
                <a href="<?php echo BASE_URL; ?>/wishlist.php" class="text-dark position-relative fs-5" data-bs-toggle="tooltip" title="Wishlist">
                    <i class="bi bi-heart"></i>
                    <?php if ($wishlist_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;"><?php echo $wishlist_count; ?></span>
                    <?php endif; ?>
                </a>
                
                <a href="<?php echo BASE_URL; ?>/cart.php" class="text-dark position-relative fs-5" data-bs-toggle="tooltip" title="Cart">
                    <i class="bi bi-cart3"></i>
                    <?php if ($cart_count > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-success" style="font-size: 0.6rem;"><?php echo $cart_count; ?></span>
                    <?php endif; ?>
                </a>
                
                <div class="dropdown">
                    <a href="#" class="text-dark fs-5 text-decoration-none dropdown-toggle" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 mt-2" aria-labelledby="userDropdown">
                        <?php if (is_logged_in()): ?>
                            <?php $role = get_user_role(); ?>
                            <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/<?php echo $role === 'admin' ? 'admin' : ($role === 'seller' ? 'seller' : 'user'); ?>/dashboard.php"><i class="bi bi-speedometer2 me-2"></i> Dashboard</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item py-2 text-danger" href="<?php echo BASE_URL; ?>/logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        <?php else: ?>
                            <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/login.php"><i class="bi bi-box-arrow-in-right me-2"></i> <?php echo __('login'); ?></a></li>
                            <li><a class="dropdown-item py-2" href="<?php echo BASE_URL; ?>/register.php"><i class="bi bi-person-plus me-2"></i> <?php echo __('register'); ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Navigation Navbar -->
    <nav class="navbar navbar-expand-lg navbar-light border-top" style="padding: 0;">
        <div class="container">
            <button class="navbar-toggler border-0 shadow-none px-0" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link active fw-medium px-3 py-3" href="<?php echo BASE_URL; ?>/index.php"><?php echo __('home'); ?></a>
                    </li>
                    <!-- Mega Menu for Categories -->
                    <li class="nav-item dropdown position-static">
                        <a class="nav-link dropdown-toggle fw-medium px-3 py-3" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php echo __('categories'); ?>
                        </a>
                        <div class="dropdown-menu w-100 border-0 shadow mt-0 p-4 rounded-bottom-4" aria-labelledby="navbarDropdown">
                            <div class="row w-100">
                                <div class="col-md-3 mb-3">
                                    <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="bi bi-twitter"></i> Birds</h6>
                                    <ul class="list-unstyled">
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Parrots</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Love Birds</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Cockatiel</a></li>
                                        <li><a class="dropdown-item text-muted px-0 text-success fw-medium" href="#">View All Birds &rarr;</a></li>
                                    </ul>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="bi bi-heart"></i> Animals</h6>
                                    <ul class="list-unstyled">
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Cats</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Dogs</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Rabbits</a></li>
                                        <li><a class="dropdown-item text-muted px-0 text-success fw-medium" href="#">View All Animals &rarr;</a></li>
                                    </ul>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <h6 class="text-success fw-bold border-bottom pb-2 mb-3"><i class="bi bi-box"></i> Accessories</h6>
                                    <ul class="list-unstyled">
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Cages</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Toys</a></li>
                                        <li><a class="dropdown-item text-muted mb-1 px-0" href="#">Feeders</a></li>
                                        <li><a class="dropdown-item text-muted px-0 text-success fw-medium" href="#">View All Accessories &rarr;</a></li>
                                    </ul>
                                </div>
                                <div class="col-md-3 mb-3 bg-light rounded p-3 d-flex flex-column justify-content-center text-center">
                                    <h5 class="fw-bold">Special Offer</h5>
                                    <p class="text-muted small">Get 20% off on all Bird Foods!</p>
                                    <a href="#" class="btn btn-outline-success btn-sm rounded-pill mt-auto">Shop Now</a>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-medium px-3 py-3" href="<?php echo BASE_URL; ?>/shop.php"><?php echo __('shop'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-medium px-3 py-3 text-danger" href="<?php echo BASE_URL; ?>/shop.php"><i class="bi bi-shop me-1"></i><?php echo __('market'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-medium px-3 py-3" href="<?php echo BASE_URL; ?>/about.php"><?php echo __('about'); ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link fw-medium px-3 py-3" href="<?php echo BASE_URL; ?>/contact.php"><?php echo __('contact'); ?></a>
                    </li>
                </ul>
                <div class="d-flex">
                    <a href="<?php echo BASE_URL; ?>/become_seller.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">Become a Seller</a>
                </div>
            </div>
        </div>
    </nav>
</header>
