<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Filter setups
$search = isset($_GET['q']) ? sanitize_input($_GET['q']) : '';
$category = isset($_GET['category']) ? sanitize_input($_GET['category']) : '';
$sort = isset($_GET['sort']) ? sanitize_input($_GET['sort']) : 'newest';
$min_price = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 1000000;

// Base query (LEFT JOIN so missing category or seller won't hide the product, but enforce conditions)
$query = "SELECT p.*, c.name_en AS category_name, u.city AS seller_city 
          FROM products p 
          LEFT JOIN categories c ON p.category_id = c.id 
          LEFT JOIN users u ON p.seller_id = u.id 
          WHERE p.status = 'active' AND p.is_deleted = 0 AND p.listing_type = 'store' AND p.stock_quantity > 0";

$count_query = "SELECT COUNT(p.id) as total FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                LEFT JOIN users u ON p.seller_id = u.id 
                WHERE p.status = 'active' AND p.is_deleted = 0 AND p.listing_type = 'store' AND p.stock_quantity > 0";

$params = [];
$types = "";

// Apply filters
if ($search) {
    $query .= " AND (p.title_en LIKE ? OR p.description_en LIKE ?)";
    $count_query .= " AND (p.title_en LIKE ? OR p.description_en LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($category) {
    $query .= " AND c.slug = ?";
    $count_query .= " AND c.slug = ?";
    $params[] = $category;
    $types .= "s";
}

if ($max_price > 0 && $max_price < 1000000) {
    $query .= " AND p.price BETWEEN ? AND ?";
    $count_query .= " AND p.price BETWEEN ? AND ?";
    $params[] = $min_price;
    $params[] = $max_price;
    $types .= "dd";
} elseif ($min_price > 0) {
    $query .= " AND p.price >= ?";
    $count_query .= " AND p.price >= ?";
    $params[] = $min_price;
    $types .= "d";
}

// Sorting
if ($sort === 'price_low') {
    $query .= " ORDER BY p.price ASC";
} elseif ($sort === 'price_high') {
    $query .= " ORDER BY p.price DESC";
} else {
    $query .= " ORDER BY p.created_at DESC";
}

$query .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

// Execute queries
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result();

// Count for pagination
if (substr($types, -2) === "ii") {
    $count_types = substr($types, 0, -2);
    $count_params = array_slice($params, 0, -2);
} else {
    $count_types = $types;
    $count_params = $params;
}
$c_stmt = $conn->prepare($count_query);
if (!empty($count_params)) {
    $c_stmt->bind_param($count_types, ...$count_params);
}
$c_stmt->execute();
$total_products = $c_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_products / $limit);

// Fetch categories for sidebar
$cat_result = $conn->query("SELECT * FROM categories WHERE status = 'active'");

include 'includes/header.php';
?>

<!-- Page Header -->
<div class="bg-light py-4 border-bottom">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="index.php" class="text-success text-decoration-none">Home</a></li>
                <li class="breadcrumb-item active" aria-current="page">Shop</li>
            </ol>
        </nav>
        <h2 class="fw-bold mb-0">Shop Products</h2>
        <?php if($search): ?>
            <p class="text-muted mt-2 mb-0">Search results for "<?php echo htmlspecialchars($search); ?>" (<?php echo $total_products; ?> found)</p>
        <?php endif; ?>
    </div>
</div>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar Filters -->
        <div class="col-lg-3 mb-4 mb-lg-0">
            <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 100px; z-index: 10;">
                <div class="card-body p-4">
                    <form action="shop.php" method="GET" id="filterForm">
                        <?php if($search): ?>
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search); ?>">
                        <?php endif; ?>
                        
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
                            <h5 class="fw-bold mb-0"><i class="bi bi-funnel"></i> Filters</h5>
                            <a href="shop.php" class="text-muted text-decoration-none small">Clear All</a>
                        </div>
                        
                        <!-- Category Filter -->
                        <div class="mb-4">
                            <h6 class="fw-bold mb-3">Categories</h6>
                            <ul class="list-unstyled mb-0">
                                <li class="mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input text-success" type="radio" name="category" id="cat_all" value="" <?php echo empty($category) ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit()">
                                        <label class="form-check-label" for="cat_all">All Categories</label>
                                    </div>
                                </li>
                                <?php if ($cat_result) while($cat = $cat_result->fetch_assoc()): ?>
                                <li class="mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input text-success" type="radio" name="category" id="cat_<?php echo $cat['id']; ?>" value="<?php echo $cat['slug']; ?>" <?php echo $category === $cat['slug'] ? 'checked' : ''; ?> onchange="document.getElementById('filterForm').submit()">
                                        <label class="form-check-label" for="cat_<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name_en']); ?></label>
                                    </div>
                                </li>
                                <?php endwhile; ?>
                            </ul>
                        </div>
                        
                        <!-- Price Filter -->
                        <div class="mb-4">
                            <h6 class="fw-bold mb-3">Price Range ($)</h6>
                            <div class="row g-2">
                                <div class="col-6">
                                    <input type="number" class="form-control form-control-sm" name="min_price" value="<?php echo $min_price > 0 ? $min_price : ''; ?>" placeholder="Min">
                                </div>
                                <div class="col-6">
                                    <input type="number" class="form-control form-control-sm" name="max_price" value="<?php echo $max_price < 1000000 ? $max_price : ''; ?>" placeholder="Max">
                                </div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-success w-100 mt-2">Apply Price</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Main Shop Area -->
        <div class="col-lg-9">
            <!-- Toolbar -->
            <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-white border rounded-3 shadow-sm">
                <div>
                    <span class="text-muted">Showing <?php echo $total_products > 0 ? $offset + 1 : 0; ?> - <?php echo min($offset + $limit, $total_products); ?> of <?php echo $total_products; ?> results</span>
                </div>
                <div class="d-flex align-items-center">
                    <label class="me-2 text-muted text-nowrap">Sort By:</label>
                    <select class="form-select form-select-sm border-0 bg-light" form="filterForm" name="sort" onchange="document.getElementById('filterForm').submit()">
                        <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
                        <option value="price_low" <?php echo $sort === 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                        <option value="price_high" <?php echo $sort === 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                    </select>
                </div>
            </div>
            
            <!-- Products Grid -->
            <div class="row g-4">
                <?php if ($products->num_rows > 0): ?>
                    <?php while($p = $products->fetch_assoc()): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="product-card h-100">
                            <div class="product-img-wrapper">
                                <img src="<?php echo get_product_image_url($conn, $p['id']); ?>" alt="<?php echo htmlspecialchars($p['title_en']); ?>">
                                <?php if($p['is_featured']): ?>
                                <div class="product-badges">
                                    <span class="badge-featured">Featured</span>
                                </div>
                                <?php endif; ?>
                                <div class="product-actions">
                                    <form action="wishlist.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="action-btn border-0" data-bs-toggle="tooltip" title="Add to Wishlist"><i class="bi bi-heart"></i></button>
                                    </form>
                                    <a href="product_details.php?id=<?php echo $p['id']; ?>" class="action-btn" data-bs-toggle="tooltip" title="Quick View"><i class="bi bi-eye"></i></a>
                                </div>
                            </div>
                            <div class="product-info">
                                <span class="product-category"><?php echo htmlspecialchars($p['category_name'] ?? 'Uncategorized'); ?></span>
                                <a href="product_details.php?id=<?php echo $p['id']; ?>" class="text-decoration-none"><h3 class="product-title text-truncate-2"><?php echo htmlspecialchars($p['title_en']); ?></h3></a>
                                <div class="product-price"><?php echo $site_settings['currency']; ?><?php echo number_format($p['price'], 2); ?></div>
                                <div class="product-meta mt-3">
                                    <span class="product-location"><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($p['seller_city'] ?? 'Location N/A'); ?></span>
                                    <form action="cart.php" method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 shadow-sm"><i class="bi bi-cart-plus"></i></button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-12 text-center py-5">
                        <i class="bi bi-search fs-1 text-muted mb-3 d-block"></i>
                        <h4 class="fw-bold">No Products Found</h4>
                        <p class="text-muted">Try adjusting your search or filter criteria.</p>
                        <a href="shop.php" class="btn btn-outline-success mt-2 rounded-pill px-4">Clear All Filters</a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-5">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $search ? '&q='.$search : ''; ?><?php echo $category ? '&category='.$category : ''; ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link <?php echo $page == $i ? 'bg-success border-success text-white' : 'text-success'; ?>" href="?page=<?php echo $i; ?><?php echo $search ? '&q='.$search : ''; ?><?php echo $category ? '&category='.$category : ''; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link text-success" href="?page=<?php echo $page + 1; ?><?php echo $search ? '&q='.$search : ''; ?><?php echo $category ? '&category='.$category : ''; ?>" aria-label="Next">
                            <span aria-hidden="true">&raquo;</span>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

