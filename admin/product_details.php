<?php
/**
 * Admin Product Details
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$product_id) {
    $_SESSION['error'] = "Invalid product ID.";
    header('Location: products.php');
    exit;
}

// Fetch Product Details
$stmt = $conn->prepare("
    SELECT p.*, c.name_en AS category_name, u.first_name, u.last_name, u.email, u.phone,
           (SELECT COUNT(*) FROM order_items WHERE product_id = p.id) AS times_ordered,
           (SELECT COALESCE(SUM(quantity), 0) FROM order_items WHERE product_id = p.id) AS total_units_sold
    FROM products p 
    JOIN categories c ON p.category_id = c.id 
    JOIN users u ON p.seller_id = u.id 
    WHERE p.id = ? AND p.is_deleted = 0
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();

if (!$product) {
    $_SESSION['error'] = "Product not found or has been deleted.";
    header('Location: products.php');
    exit;
}

$page_title = "Product Details - " . htmlspecialchars($product['title_en']);
$page_heading = "Product Details";
include __DIR__ . '/partials/header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center">
    <a href="products.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Products</a>
    <div>
        <?php if ($product['status'] === 'pending' || $product['status'] === 'inactive'): ?>
            <form action="products.php" method="POST" class="d-inline">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <button type="submit" class="btn btn-success fw-bold rounded-pill px-4"><i class="bi bi-check-circle me-1"></i> Approve / Activate</button>
            </form>
        <?php endif; ?>
        <?php if ($product['status'] === 'active'): ?>
            <form action="products.php" method="POST" class="d-inline">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                <button type="submit" class="btn btn-warning fw-bold rounded-pill px-4"><i class="bi bi-x-circle me-1"></i> Reject / Hide</button>
            </form>
        <?php endif; ?>
        <form action="products.php" method="POST" class="d-inline ms-2">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
            <button type="submit" class="btn btn-outline-danger fw-bold rounded-pill px-4" onclick="return confirm('Soft delete this product?');"><i class="bi bi-trash me-1"></i> Delete</button>
        </form>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Image and Seller Info -->
    <div class="col-lg-4">
        <!-- Product Image -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
            <img src="<?php echo get_product_image_url($conn, $product['id']); ?>" class="card-img-top object-fit-cover" style="height: 300px;" alt="Product Image">
        </div>

        <!-- Seller Info Card -->
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h6 class="fw-bold text-muted mb-0">Seller Information</h6>
            </div>
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($product['first_name'] . ' ' . $product['last_name']); ?></h5>
                <p class="text-muted mb-3">Seller ID: #<?php echo $product['seller_id']; ?></p>
                <ul class="list-group list-group-flush text-start mb-4">
                    <li class="list-group-item px-0"><i class="bi bi-envelope text-muted me-2"></i> <?php echo htmlspecialchars($product['email']); ?></li>
                    <li class="list-group-item px-0"><i class="bi bi-telephone text-muted me-2"></i> <?php echo htmlspecialchars($product['phone'] ?? 'Not provided'); ?></li>
                </ul>
                <a href="seller_details.php?id=<?php echo $product['seller_id']; ?>" class="btn btn-outline-primary w-100 rounded-pill">View Seller Profile</a>
            </div>
        </div>
    </div>

    <!-- Right Column: Product Details and Stats -->
    <div class="col-lg-8">
        <!-- Product Main Details -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <span class="badge bg-light text-dark border mb-2"><?php echo htmlspecialchars($product['category_name']); ?></span>
                        <span class="badge bg-light text-dark border mb-2 ms-1"><?php echo ucfirst($product['listing_type']); ?> Listing</span>
                        <?php if ($product['is_featured']): ?>
                            <span class="badge bg-warning text-dark mb-2 ms-1"><i class="bi bi-star-fill me-1"></i> Featured</span>
                        <?php endif; ?>
                        
                        <h2 class="fw-bold mb-1"><?php echo htmlspecialchars($product['title_en']); ?></h2>
                        <?php if ($product['title_ar']): ?>
                            <h4 class="text-muted mb-3" dir="rtl"><?php echo htmlspecialchars($product['title_ar']); ?></h4>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <h2 class="fw-bold text-primary mb-1"><?php echo $site_settings['currency'] . number_format($product['price'], 2); ?></h2>
                        <?php
                        $badge_class = 'bg-secondary';
                        if ($product['status'] === 'active') $badge_class = 'bg-success';
                        if ($product['status'] === 'pending') $badge_class = 'bg-warning text-dark';
                        if ($product['status'] === 'sold') $badge_class = 'bg-info text-dark';
                        if ($product['status'] === 'inactive') $badge_class = 'bg-danger';
                        ?>
                        <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-2 fs-6"><?php echo ucfirst($product['status']); ?></span>
                    </div>
                </div>

                <hr class="my-4 text-muted">

                <h6 class="fw-bold mb-3">Description (English)</h6>
                <p class="text-muted mb-4" style="white-space: pre-line;"><?php echo htmlspecialchars($product['description_en']); ?></p>

                <?php if ($product['description_ar']): ?>
                    <h6 class="fw-bold mb-3">Description (Arabic)</h6>
                    <p class="text-muted mb-4" dir="rtl" style="white-space: pre-line;"><?php echo htmlspecialchars($product['description_ar']); ?></p>
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <div class="bg-light rounded p-3">
                            <span class="text-muted d-block small mb-1">Stock Quantity</span>
                            <span class="fw-bold fs-5"><?php echo $product['stock_quantity']; ?></span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="bg-light rounded p-3">
                            <span class="text-muted d-block small mb-1">Listed On</span>
                            <span class="fw-bold fs-5"><?php echo date('d M Y, h:i A', strtotime($product['created_at'])); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sales Metrics -->
        <div class="row g-4">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm bg-primary text-white p-4 h-100 rounded-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Times Ordered</h6>
                            <h2 class="fw-bold mb-0"><?php echo number_format($product['times_ordered']); ?></h2>
                        </div>
                        <i class="bi bi-cart-check fs-1 text-white-50"></i>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm bg-success text-white p-4 h-100 rounded-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="text-white-50 mb-1">Total Units Sold</h6>
                            <h2 class="fw-bold mb-0"><?php echo number_format($product['total_units_sold']); ?></h2>
                        </div>
                        <i class="bi bi-box-seam fs-1 text-white-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
