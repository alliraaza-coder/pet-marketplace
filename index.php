<?php
/**
 * index.php — Home Page
 * PetMarket — Phase 1 + Dynamic Featured Products Fix
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

// ── Fetch Featured Products from DB (active, store, in-stock, newest first) ──────
$feat_stmt = $conn->prepare(
    "SELECT p.id, p.title_en, p.price, p.city, p.is_featured, p.stock_quantity,
            c.name_en AS category_name, c.slug AS category_slug,
            u.city AS seller_city
     FROM   products p
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN users      u ON p.seller_id   = u.id
     WHERE  p.status = 'active' AND p.is_deleted = 0 AND p.listing_type = 'store' AND p.stock_quantity > 0
     ORDER  BY p.is_featured DESC, p.created_at DESC
     LIMIT  4"
);
$feat_stmt->execute();
$featured_products = $feat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch Categories ────────────────────────────────────────────────────────
$cat_result = $conn->query("SELECT * FROM categories WHERE status = 'active' ORDER BY id ASC LIMIT 6");
$categories  = $cat_result->fetch_all(MYSQLI_ASSOC);

// ── Category section icon map ────────────────────────────────────────────────
$cat_icons = [
    'birds'       => 'bi-twitter',
    'animals'     => 'bi-heart',
    'accessories' => 'bi-box',
    'foods'       => 'bi-egg',
    'market'      => 'bi-shop',
];

include 'includes/header.php';
?>

<!-- Hero Section -->
<section class="hero-section text-center">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="hero-content animate-fade-in">
                    <span class="badge bg-warning text-dark mb-3 px-3 py-2 rounded-pill fw-bold">Premium Quality Pets &amp; Supplies</span>
                    <h1 class="display-3 fw-bold mb-4">Discover Your Perfect Pet Companion</h1>
                    <p class="lead mb-5 text-muted">The most trusted marketplace for buying and selling beautiful birds, lovely animals, and premium pet accessories.</p>

                    <div class="d-flex justify-content-center gap-3 flex-wrap">
                        <a href="<?php echo BASE_URL; ?>/shop.php" class="btn btn-primary btn-lg rounded-pill px-4 shadow">
                            <i class="bi bi-cart me-2"></i> Shop Now
                        </a>
                        <a href="<?php echo BASE_URL; ?>/shop.php" class="btn btn-outline-success btn-lg rounded-pill px-4 bg-white shadow-sm">
                            <i class="bi bi-shop me-2"></i> Browse All Products
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Popular Categories Section -->
<section class="py-5 bg-white">
    <div class="container py-4">
        <div class="text-center mb-5">
            <h2 class="fw-bold">Popular Categories</h2>
            <p class="text-muted">Browse through our top pet categories</p>
        </div>

        <div class="row g-4 justify-content-center">
            <?php if (!empty($categories)): ?>
                <?php foreach ($categories as $cat):
                    $icon = $cat_icons[$cat['section']] ?? 'bi-grid';
                ?>
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="<?php echo BASE_URL; ?>/shop.php?category=<?php echo urlencode($cat['slug']); ?>" class="text-decoration-none">
                        <div class="category-card text-center">
                            <i class="bi <?php echo htmlspecialchars($cat['icon'] ?? $icon); ?>"></i>
                            <h5><?php echo htmlspecialchars($cat['name_en']); ?></h5>
                            <small class="text-muted"><?php echo ucfirst($cat['section']); ?></small>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <!-- Fallback static categories if DB is empty -->
                <div class="col-6 col-md-4 col-lg-2">
                    <a href="<?php echo BASE_URL; ?>/shop.php" class="text-decoration-none">
                        <div class="category-card"><i class="bi bi-twitter"></i><h5>Birds</h5><small class="text-muted">All Types</small></div>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Featured Products Section (Dynamic from DB) -->
<section class="py-5" style="background-color: var(--light-bg, #f8f9fa);">
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-end mb-4">
            <div>
                <h2 class="fw-bold mb-1">Featured Products</h2>
                <p class="text-muted mb-0">Premium quality pets from trusted sellers</p>
            </div>
            <a href="<?php echo BASE_URL; ?>/shop.php" class="btn btn-outline-success rounded-pill d-none d-md-inline-block">View All Products</a>
        </div>

        <?php if (!empty($featured_products)): ?>
        <div class="row g-4">
            <?php foreach ($featured_products as $fp):
                $fp_img = get_product_image_url($conn, $fp['id']);
            ?>
            <div class="col-12 col-md-6 col-lg-3">
                <div class="product-card h-100">
                    <div class="product-img-wrapper">
                        <img src="<?php echo $fp_img; ?>" alt="<?php echo htmlspecialchars($fp['title_en']); ?>">
                        <?php if ($fp['is_featured']): ?>
                        <div class="product-badges">
                            <span class="badge-featured">Featured</span>
                        </div>
                        <?php endif; ?>
                        <div class="product-actions">
                            <form action="wishlist.php" method="POST" class="d-inline">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?php echo $fp['id']; ?>">
                                <button type="submit" class="action-btn border-0" title="Add to Wishlist"><i class="bi bi-heart"></i></button>
                            </form>
                            <a href="product_details.php?id=<?php echo $fp['id']; ?>" class="action-btn" title="Quick View"><i class="bi bi-eye"></i></a>
                        </div>
                    </div>
                    <div class="product-info">
                        <span class="product-category"><?php echo htmlspecialchars($fp['category_name'] ?? 'Uncategorized'); ?></span>
                        <a href="product_details.php?id=<?php echo $fp['id']; ?>" class="text-decoration-none">
                            <h3 class="product-title text-truncate-2"><?php echo htmlspecialchars($fp['title_en']); ?></h3>
                        </a>
                        <div class="product-price"><?php echo $site_settings['currency'] . number_format($fp['price'], 2); ?></div>
                        <div class="product-meta mt-3">
                            <span class="product-location"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($fp['seller_city'] ?? 'N/A'); ?></span>
                            <form action="cart.php" method="POST" class="d-inline">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?php echo $fp['id']; ?>">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm"><i class="bi bi-cart-plus"></i></button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-5">
            <i class="bi bi-bag-x fs-1 text-muted d-block mb-3"></i>
            <h5 class="fw-bold">No products listed yet.</h5>
            <p class="text-muted">Products added by sellers will appear here.</p>
            <a href="become_seller.php" class="btn btn-outline-success rounded-pill px-4">Become a Seller</a>
        </div>
        <?php endif; ?>

        <div class="text-center mt-5 d-md-none">
            <a href="<?php echo BASE_URL; ?>/shop.php" class="btn btn-outline-success rounded-pill px-4">View All Products</a>
        </div>
    </div>
</section>

<!-- Call to Action (Seller Registration) -->
<section class="py-5 bg-success text-white">
    <div class="container py-4">
        <div class="row align-items-center">
            <div class="col-lg-8 mb-4 mb-lg-0">
                <h2 class="fw-bold mb-3">Do you have pets or accessories to sell?</h2>
                <p class="lead mb-0 opacity-75">Join our platform as a verified seller and reach thousands of potential buyers nationwide. It's quick, easy, and secure!</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="<?php echo BASE_URL; ?>/become_seller.php" class="btn btn-warning btn-lg rounded-pill fw-bold shadow px-5 py-3">Become a Seller</a>
            </div>
        </div>
    </div>
</section>

<?php include 'includes/footer.php'; ?>


