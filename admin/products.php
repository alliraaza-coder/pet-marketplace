<?php
/**
 * Admin Products Management
 * Phase 3.3
 */
$page_title = "Products Management";
$page_heading = "Manage Products";
include __DIR__ . '/partials/header.php';

$admin_id = $_SESSION['user_id'];

// Handle Actions (Approve, Reject, Feature, Unfeature, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['product_id'])) {
    verify_csrf();
    $action     = sanitize_input($_POST['action']);
    $product_id = (int)$_POST['product_id'];

    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE products SET status = 'active' WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Approved Product", "Approved Product ID: $product_id");
            $_SESSION['success'] = "Product approved and activated.";
        }
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Rejected Product", "Rejected/Hid Product ID: $product_id");
            $_SESSION['success'] = "Product rejected/hidden successfully.";
        }
    } elseif ($action === 'feature') {
        $stmt = $conn->prepare("UPDATE products SET is_featured = 1 WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Featured Product", "Featured Product ID: $product_id");
            $_SESSION['success'] = "Product marked as featured.";
        }
    } elseif ($action === 'unfeature') {
        $stmt = $conn->prepare("UPDATE products SET is_featured = 0 WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Unfeatured Product", "Removed featured status from Product ID: $product_id");
            $_SESSION['success'] = "Product removed from featured list.";
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("UPDATE products SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $product_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Soft Deleted Product", "Soft deleted Product ID: $product_id");
            $_SESSION['success'] = "Product soft deleted successfully.";
        }
    }
    header('Location: products.php');
    exit;
}

// Fetch categories for filter dropdown
$cat_stmt = $conn->query("SELECT id, name_en FROM categories WHERE is_deleted = 0 ORDER BY name_en ASC");
$categories = [];
while ($c = $cat_stmt->fetch_assoc()) $categories[] = $c;

// Search and Filters
$search        = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$cat_filter    = trim($_GET['category'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where  = "WHERE p.is_deleted = 0";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (p.title_en LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $s        = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s;
    $types   .= "sss";
}
if ($status_filter !== '') {
    $where   .= " AND p.status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}
if ($cat_filter !== '') {
    $where   .= " AND p.category_id = ?";
    $params[] = $cat_filter;
    $types   .= "i";
}

// Count total
$cnt_sql = "SELECT COUNT(*) as total FROM products p JOIN users u ON p.seller_id = u.id $where";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_products = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages    = max(1, ceil($total_products / $per_page));

// Fetch products
$sql = "SELECT p.id, p.title_en, p.price, p.stock_quantity, p.status, p.is_featured, p.created_at, 
        u.first_name, u.last_name, c.name_en AS category_name
        FROM products p 
        JOIN users u ON p.seller_id = u.id 
        JOIN categories c ON p.category_id = c.id 
        $where ORDER BY p.created_at DESC LIMIT ? OFFSET ?";

$params_p = array_merge($params, [$per_page, $offset]);
$types_p  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$products = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Products Inventory</h4>
        <p class="text-muted small mb-0"><?php echo $total_products; ?> product(s) listed</p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="products.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control bg-light border-0" placeholder="Search product or seller..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="category" class="form-select bg-light border-0">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $cat_filter == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name_en']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select bg-light border-0">
                <option value="">All Statuses</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive / Rejected</option>
                <option value="sold" <?php echo $status_filter === 'sold' ? 'selected' : ''; ?>>Sold</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">Filter</button>
            <?php if ($search || $status_filter || $cat_filter): ?>
                <a href="products.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Products Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Product</th>
                        <th>Seller</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products && $products->num_rows > 0): ?>
                        <?php while ($p = $products->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <img src="<?php echo get_product_image_url($conn, $p['id']); ?>" class="rounded me-3 object-fit-cover" width="40" height="40" onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2740%27 height=%2740%27%3E%3Crect width=%2740%27 height=%2740%27 fill=%27%23f3f3f3%27/%3E%3Ctext x=%2750%25%27 y=%2750%25%27 font-family=%27sans-serif%27 font-size=%276%27 fill=%27%23aaa%27 text-anchor=%27middle%27 dy=%27.3em%27%3ENo Image%3C/text%3E%3C/svg%3E'">
                                        <div>
                                            <div class="fw-bold text-dark text-truncate" style="max-width: 200px;">
                                                <?php echo htmlspecialchars($p['title_en']); ?>
                                                <?php if ($p['is_featured']): ?>
                                                    <i class="bi bi-star-fill text-warning ms-1" title="Featured Product"></i>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($p['category_name']); ?></span></td>
                                <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></td>
                                <td><?php echo $p['stock_quantity']; ?></td>
                                <td>
                                    <?php
                                    $badge_class = 'bg-secondary';
                                    if ($p['status'] === 'active') $badge_class = 'bg-success';
                                    if ($p['status'] === 'pending') $badge_class = 'bg-warning text-dark';
                                    if ($p['status'] === 'sold') $badge_class = 'bg-info text-dark';
                                    if ($p['status'] === 'inactive') $badge_class = 'bg-danger';
                                    ?>
                                    <span class="badge <?php echo $badge_class; ?> rounded-pill px-3 py-1"><?php echo ucfirst($p['status']); ?></span>
                                </td>
                                <td><small class="text-muted"><?php echo date('d M Y', strtotime($p['created_at'])); ?></small></td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li><a class="dropdown-item" href="product_details.php?id=<?php echo $p['id']; ?>"><i class="bi bi-eye me-2"></i> View Details</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            
                                            <?php if ($p['status'] === 'pending' || $p['status'] === 'inactive' || $p['status'] === 'sold'): ?>
                                                <li>
                                                    <form action="products.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="approve">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i> Approve / Activate</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($p['status'] === 'active'): ?>
                                                <li>
                                                    <form action="products.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="reject">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-warning"><i class="bi bi-x-circle me-2"></i> Reject / Hide</button>
                                                    </form>
                                                </li>
                                                
                                                <?php if ($p['is_featured']): ?>
                                                    <li>
                                                        <form action="products.php" method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="unfeature">
                                                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-secondary"><i class="bi bi-star me-2"></i> Remove Feature</button>
                                                        </form>
                                                    </li>
                                                <?php else: ?>
                                                    <li>
                                                        <form action="products.php" method="POST" class="d-inline">
                                                            <input type="hidden" name="action" value="feature">
                                                            <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-primary"><i class="bi bi-star-fill me-2"></i> Feature Product</button>
                                                        </form>
                                                    </li>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <form action="products.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Soft delete this product?');"><i class="bi bi-trash me-2"></i> Soft Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center p-3">
            <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?></small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&category=<?php echo urlencode($cat_filter); ?>">&laquo;</a></li>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&category=<?php echo urlencode($cat_filter); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&category=<?php echo urlencode($cat_filter); ?>">&raquo;</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>



