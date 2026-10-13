<?php
/**
 * Admin Market Special Details View
 * Phase 7 — View Details of an Admin Market Special Product
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id   = (int)$_SESSION['user_id'];
$product_id = (int)($_GET['id'] ?? 0);

if (!$product_id) {
    $_SESSION['error'] = 'Invalid Market Special ID.';
    header('Location: market_specials.php');
    exit;
}

// Fetch Market Special category ID
$cat_row = $conn->query("SELECT id FROM categories WHERE is_admin_only = 1 OR slug = 'market-specials' LIMIT 1")->fetch_assoc();
$market_category_id = $cat_row ? (int)$cat_row['id'] : 5;

// Fetch Product
$stmt = $conn->prepare("
    SELECT p.*, c.name_en AS category_name, sc.name_en AS subcategory_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
    WHERE p.id = ? AND (p.is_admin_product = 1 OR p.category_id = ?) AND p.is_deleted = 0
");
$stmt->bind_param("ii", $product_id, $market_category_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    $_SESSION['error'] = 'Market Special product not found or unauthorized.';
    header('Location: market_specials.php');
    exit;
}

$product_type = $product['product_type'] ?: PRODUCT_TYPE_BIRDS_ANIMALS;
$dynamic_details = get_product_dynamic_details($conn, $product_id, $product_type);

// Fetch Images
$imgs_res = $conn->query("SELECT * FROM product_images WHERE product_id = $product_id ORDER BY is_primary DESC, id ASC");
$images = [];
while ($ir = $imgs_res->fetch_assoc()) {
    $images[] = $ir;
}

$page_title   = "Market Special: " . htmlspecialchars($product['title_en']);
$page_heading = "Market Special Details";
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-warning text-dark px-3 py-1 fw-bold"><i class="bi bi-stars me-1"></i>ADMIN MARKET SPECIAL</span>
            <?php
            $st_badge = 'bg-secondary';
            if ($product['status'] === 'active') $st_badge = 'bg-success';
            if ($product['status'] === 'sold_out') $st_badge = 'bg-danger';
            if ($product['status'] === 'inactive') $st_badge = 'bg-secondary text-white';
            ?>
            <span class="badge <?php echo $st_badge; ?> px-3 py-1">
                <?php echo ($product['status'] === 'sold_out') ? 'Sold Out' : ucfirst(htmlspecialchars($product['status'])); ?>
            </span>
        </div>
        <h3 class="fw-bold mb-0"><?php echo htmlspecialchars($product['title_en']); ?></h3>
    </div>
    <div class="d-flex gap-2">
        <a href="edit_market_special.php?id=<?php echo $product['id']; ?>" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
            <i class="bi bi-pencil me-1"></i> Edit Product
        </a>
        <a href="../product_details.php?id=<?php echo $product['id']; ?>" target="_blank" class="btn btn-outline-info rounded-pill px-3">
            <i class="bi bi-box-arrow-up-right me-1"></i> View Live
        </a>
        <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill px-3">
            <i class="bi bi-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Details & Images -->
    <div class="col-lg-8">
        <!-- Images Gallery -->
        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-3"><i class="bi bi-images text-info me-2"></i>Product Gallery</h5>
                <div class="row g-3">
                    <?php if (!empty($images)): ?>
                        <?php foreach ($images as $img): ?>
                            <div class="col-sm-6 col-md-4">
                                <div class="position-relative border rounded-3 overflow-hidden shadow-sm h-100">
                                    <img src="<?php echo BASE_URL . '/assets/uploads/products/' . htmlspecialchars($img['image_url']); ?>" class="img-fluid w-100" style="height: 180px; object-fit: cover;" onerror="this.src='https://placehold.co/300x200?text=Special';">
                                    <?php if ($img['is_primary']): ?>
                                        <span class="badge bg-success position-absolute top-0 start-0 m-2">Primary</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-12 text-muted small fst-italic">No images uploaded.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Description -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1"><i class="bi bi-text-paragraph text-primary me-2"></i>Description</h5>
            </div>
            <div class="card-body p-4">
                <p class="text-secondary mb-0" style="line-height: 1.8; white-space: pre-line;">
                    <?php echo htmlspecialchars($product['description_en']); ?>
                </p>
            </div>
        </div>

        <!-- Dynamic Specs Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-dark">
                    <i class="bi bi-sliders text-success me-2"></i>
                    Specific Attributes (<?php echo ucfirst(str_replace('_', ' ', $product_type)); ?>)
                </h5>
            </div>
            <div class="card-body p-4">
                <?php if ($product_type === PRODUCT_TYPE_SEEDS_FOOD): ?>
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Price per KG</small>
                            <strong><?php echo $site_settings['currency'] . number_format($dynamic_details['price_per_kg'] ?? $product['price'], 2); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Quality Grade</small>
                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($dynamic_details['quality'] ?? 'A'); ?></span>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Stock Mode</small>
                            <span class="badge bg-light text-dark border"><?php echo ($dynamic_details['stock_type'] ?? '') === 'as_required' ? 'On Demand' : 'Numeric Quantity'; ?></span>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Discount (25 KG)</small>
                            <strong><?php echo !empty($dynamic_details['discount_price_25kg']) ? $site_settings['currency'] . number_format($dynamic_details['discount_price_25kg'], 2) : 'N/A'; ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Discount (50 KG)</small>
                            <strong><?php echo !empty($dynamic_details['discount_price_50kg']) ? $site_settings['currency'] . number_format($dynamic_details['discount_price_50kg'], 2) : 'N/A'; ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Stock in KG</small>
                            <strong><?php echo (float)($dynamic_details['stock_kg'] ?? $product['stock_quantity']); ?> KG</strong>
                        </div>
                    </div>

                <?php elseif ($product_type === PRODUCT_TYPE_CAGES_HOUSES): ?>
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Price per Piece</small>
                            <strong><?php echo $site_settings['currency'] . number_format($dynamic_details['price_per_piece'] ?? $product['price'], 2); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">For Animal/Bird</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['animal_purpose'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-4">
                            <small class="text-muted d-block">Quality Grade</small>
                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($dynamic_details['quality'] ?? 'A'); ?></span>
                        </div>
                        <div class="col-sm-6 col-md-6">
                            <small class="text-muted d-block">Dimensions (L × W × D × H in Inches)</small>
                            <strong>
                                <?php
                                $dims = [];
                                if (!empty($dynamic_details['length'])) $dims[] = "L: " . $dynamic_details['length'] . "\"";
                                if (!empty($dynamic_details['width']))  $dims[] = "W: " . $dynamic_details['width'] . "\"";
                                if (!empty($dynamic_details['depth']))  $dims[] = "D: " . $dynamic_details['depth'] . "\"";
                                if (!empty($dynamic_details['height'])) $dims[] = "H: " . $dynamic_details['height'] . "\"";
                                echo !empty($dims) ? implode(' × ', $dims) : 'N/A';
                                ?>
                            </strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Discount 12 Pcs</small>
                            <strong><?php echo !empty($dynamic_details['discount_price_12pcs']) ? $site_settings['currency'] . number_format($dynamic_details['discount_price_12pcs'], 2) : 'N/A'; ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Discount 24 Pcs</small>
                            <strong><?php echo !empty($dynamic_details['discount_price_24pcs']) ? $site_settings['currency'] . number_format($dynamic_details['discount_price_24pcs'], 2) : 'N/A'; ?></strong>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Breed / Species</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['breed'] ?? $product['breed'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Gender</small>
                            <strong><?php echo htmlspecialchars(ucfirst($dynamic_details['gender'] ?? $product['gender'] ?? 'Unknown')); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Age</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['age'] ?? $product['age'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Color</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['color'] ?? $product['color'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Weight</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['weight'] ?? $product['weight'] ?? 'N/A'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">City Location</small>
                            <strong><?php echo htmlspecialchars($dynamic_details['city'] ?? $product['city'] ?? 'Admin Special'); ?></strong>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Vaccinated</small>
                            <span class="badge <?php echo !empty($dynamic_details['vaccinated'] ?? $product['vaccination_status']) ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo !empty($dynamic_details['vaccinated'] ?? $product['vaccination_status']) ? 'Yes' : 'No'; ?>
                            </span>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <small class="text-muted d-block">Health Certificate</small>
                            <span class="badge <?php echo !empty($dynamic_details['health_certificate'] ?? $product['health_certificate']) ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo !empty($dynamic_details['health_certificate'] ?? $product['health_certificate']) ? 'Yes' : 'No'; ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Pricing, Stock & Quick Actions -->
    <div class="col-lg-4">
        <!-- Pricing & Stock Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-tag text-primary me-2"></i>Pricing &amp; Stock</h5>
            </div>
            <div class="card-body p-4">
                <div class="mb-3">
                    <small class="text-muted d-block">Base Price</small>
                    <h3 class="fw-bold text-success mb-0"><?php echo $site_settings['currency'] . number_format($product['price'], 2); ?></h3>
                    <?php if (!empty($product['discount_price']) && $product['discount_price'] > 0): ?>
                        <small class="text-muted">Discounted Price: <strong><?php echo $site_settings['currency'] . number_format($product['discount_price'], 2); ?></strong></small>
                    <?php endif; ?>
                </div>
                <hr>
                <div class="mb-3">
                    <small class="text-muted d-block mb-1">Stock Level</small>
                    <h4 class="fw-bold <?php echo $product['stock_quantity'] <= 0 ? 'text-danger' : 'text-dark'; ?>">
                        <?php echo (int)$product['stock_quantity']; ?> units
                    </h4>
                    <span class="badge <?php echo $product['stock_quantity'] > 0 ? 'bg-success' : 'bg-danger'; ?> rounded-pill px-3 py-1">
                        <?php echo $product['stock_quantity'] > 0 ? 'AVAILABLE' : 'SOLD OUT'; ?>
                    </span>
                </div>
                <hr>
                <!-- Quick Stock Update Form -->
                <form action="market_specials.php" method="POST" class="mt-3">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <label class="form-label small fw-bold">Quick Update Stock Quantity</label>
                    <div class="input-group mb-2">
                        <input type="number" name="stock_quantity" min="0" value="<?php echo (int)$product['stock_quantity']; ?>" class="form-control rounded-start-3" required>
                        <button type="submit" class="btn btn-primary rounded-end-3 px-3">Update</button>
                    </div>
                    <small class="text-muted" style="font-size: 0.75rem;">Setting stock to 0 sets status to Sold Out. Increasing stock activates product.</small>
                </form>
            </div>
        </div>

        <!-- Meta Details Card -->
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-secondary"><i class="bi bi-info-circle me-2"></i>Product Info</h5>
            </div>
            <div class="card-body p-4">
                <ul class="list-unstyled mb-0 small">
                    <li class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span class="text-muted">Category:</span>
                        <strong class="text-dark"><?php echo htmlspecialchars($product['category_name'] ?? 'Market Specials'); ?></strong>
                    </li>
                    <li class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span class="text-muted">Subcategory:</span>
                        <strong class="text-dark"><?php echo htmlspecialchars($product['subcategory_name'] ?? 'None'); ?></strong>
                    </li>
                    <li class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span class="text-muted">Product Type:</span>
                        <strong class="text-dark"><?php echo ucfirst(str_replace('_', ' ', $product['product_type'])); ?></strong>
                    </li>
                    <li class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span class="text-muted">Featured:</span>
                        <strong class="text-dark"><?php echo $product['is_featured'] ? 'Yes' : 'No'; ?></strong>
                    </li>
                    <li class="d-flex justify-content-between mb-2 pb-1 border-bottom">
                        <span class="text-muted">Views:</span>
                        <strong class="text-dark"><?php echo (int)$product['views']; ?></strong>
                    </li>
                    <li class="d-flex justify-content-between">
                        <span class="text-muted">Created:</span>
                        <strong class="text-dark"><?php echo date('d M Y, h:i A', strtotime($product['created_at'])); ?></strong>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
