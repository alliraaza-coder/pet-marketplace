<?php
/**
 * Admin Market Specials Management
 * Phase 7 — Dedicated Market Specials Page
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$page_title   = "Market Specials Management";
$page_heading = "Market Specials (Admin Only)";

$admin_id = (int)$_SESSION['user_id'];

// Fetch Market Specials category ID
$cat_row = $conn->query("SELECT id FROM categories WHERE is_admin_only = 1 OR slug = 'market-specials' LIMIT 1")->fetch_assoc();
$market_category_id = $cat_row ? (int)$cat_row['id'] : 5;

// Handle Actions (Activate, Deactivate, Delete, Quick Stock Update)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf();
    $action     = sanitize_input($_POST['action']);
    $product_id = (int)($_POST['product_id'] ?? 0);

    // Verify product is indeed an admin market special
    $chk = $conn->prepare("SELECT id, title_en, stock_quantity, status FROM products WHERE id = ? AND (is_admin_product = 1 OR category_id = ?) AND is_deleted = 0");
    $chk->bind_param("ii", $product_id, $market_category_id);
    $chk->execute();
    $prod = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$prod) {
        $_SESSION['error'] = "Market Special product not found.";
        header('Location: market_specials.php');
        exit;
    }

    if ($action === 'activate') {
        $new_status = ($prod['stock_quantity'] <= 0) ? 'sold_out' : 'active';
        $upd = $conn->prepare("UPDATE products SET status = ? WHERE id = ?");
        $upd->bind_param("si", $new_status, $product_id);
        if ($upd->execute()) {
            log_admin_activity($conn, $admin_id, "Activated Market Special", "Activated Market Special ID: $product_id");
            $_SESSION['success'] = "Market Special product activated successfully.";
        }
    } elseif ($action === 'deactivate') {
        $upd = $conn->prepare("UPDATE products SET status = 'inactive' WHERE id = ?");
        $upd->bind_param("i", $product_id);
        if ($upd->execute()) {
            log_admin_activity($conn, $admin_id, "Deactivated Market Special", "Deactivated Market Special ID: $product_id");
            $_SESSION['success'] = "Market Special product deactivated successfully.";
        }
    } elseif ($action === 'delete') {
        $upd = $conn->prepare("UPDATE products SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
        $upd->bind_param("i", $product_id);
        if ($upd->execute()) {
            log_admin_activity($conn, $admin_id, "Deleted Market Special", "Deleted Market Special ID: $product_id");
            $_SESSION['success'] = "Market Special product deleted successfully.";
        }
    } elseif ($action === 'update_stock') {
        $new_stock = max(0, (int)($_POST['stock_quantity'] ?? 0));
        // Status rule: stock > 0 => active (unless inactive); stock = 0 => sold_out
        $target_status = $prod['status'];
        if ($new_stock === 0) {
            $target_status = 'sold_out';
        } else {
            if ($target_status === 'sold_out' || $target_status === 'sold' || $target_status === 'active') {
                $target_status = 'active';
            }
        }
        $upd = $conn->prepare("UPDATE products SET stock_quantity = ?, status = ? WHERE id = ?");
        $upd->bind_param("isi", $new_stock, $target_status, $product_id);
        if ($upd->execute()) {
            log_admin_activity($conn, $admin_id, "Updated Market Special Stock", "Stock updated to $new_stock for ID: $product_id");
            $_SESSION['success'] = "Stock updated to {$new_stock}. Status is now " . ucfirst(str_replace('_', ' ', $target_status)) . ".";
        }
    }

    header('Location: market_specials.php');
    exit;
}

// Filters & Pagination
$search        = trim($_GET['q'] ?? '');
$filter_status = sanitize_input($_GET['status'] ?? '');
$filter_type   = sanitize_input($_GET['product_type'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$limit         = 10;
$offset        = ($page - 1) * $limit;

$where_clauses = ["p.is_deleted = 0", "(p.is_admin_product = 1 OR p.category_id = {$market_category_id})"];
$params = [];
$types  = "";

if ($search !== '') {
    $where_clauses[] = "(p.title_en LIKE ? OR p.description_en LIKE ?)";
    $s_term = "%$search%";
    $params[] = $s_term;
    $params[] = $s_term;
    $types .= "ss";
}

if ($filter_status !== '') {
    $where_clauses[] = "p.status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

if ($filter_type !== '') {
    $where_clauses[] = "p.product_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

$where_sql = implode(' AND ', $where_clauses);

// Count
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM products p WHERE $where_sql");
if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_items = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, ceil($total_items / $limit));

// Fetch Records
$sql = "
    SELECT p.*,
           (SELECT image_url FROM product_images WHERE product_id = p.id AND is_primary = 1 LIMIT 1) AS primary_image,
           (SELECT COUNT(*) FROM product_images WHERE product_id = p.id) AS image_count
    FROM products p
    WHERE $where_sql
    ORDER BY p.id DESC
    LIMIT ?, ?
";
$fetch_stmt = $conn->prepare($sql);
$types .= "ii";
$params[] = $offset;
$params[] = $limit;
$fetch_stmt->bind_param($types, ...$params);
$fetch_stmt->execute();
$products = $fetch_stmt->get_result();

// Stats for Header Cards
$stats = $conn->query("
    SELECT 
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN status = 'sold_out' OR stock_quantity <= 0 THEN 1 ELSE 0 END) AS sold_out_count,
        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count
    FROM products
    WHERE is_deleted = 0 AND (is_admin_product = 1 OR category_id = {$market_category_id})
")->fetch_assoc();

include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-stars text-warning me-2"></i>Market Specials</h3>
        <p class="text-muted small mb-0">Exclusive platform-managed products (Admin Only). Not available to sellers.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="add_market_special.php" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">
            <i class="bi bi-plus-lg me-1"></i> Add Market Special
        </a>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3 stat-card">
            <div class="d-flex align-items-center">
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-4 me-3">
                    <i class="bi bi-stars fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted small mb-1">Total Specials</h6>
                    <h4 class="fw-bold mb-0"><?php echo (int)($stats['total_count'] ?? 0); ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3 stat-card">
            <div class="d-flex align-items-center">
                <div class="bg-success bg-opacity-10 text-success p-3 rounded-4 me-3">
                    <i class="bi bi-check-circle fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted small mb-1">Active / Available</h6>
                    <h4 class="fw-bold mb-0 text-success"><?php echo (int)($stats['active_count'] ?? 0); ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3 stat-card">
            <div class="d-flex align-items-center">
                <div class="bg-danger bg-opacity-10 text-danger p-3 rounded-4 me-3">
                    <i class="bi bi-x-octagon fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted small mb-1">Sold Out (Stock 0)</h6>
                    <h4 class="fw-bold mb-0 text-danger"><?php echo (int)($stats['sold_out_count'] ?? 0); ?></h4>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3 stat-card">
            <div class="d-flex align-items-center">
                <div class="bg-secondary bg-opacity-10 text-secondary p-3 rounded-4 me-3">
                    <i class="bi bi-pause-circle fs-3"></i>
                </div>
                <div>
                    <h6 class="text-muted small mb-1">Inactive / Hidden</h6>
                    <h4 class="fw-bold mb-0 text-secondary"><?php echo (int)($stats['inactive_count'] ?? 0); ?></h4>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search & Filter Bar -->
<div class="card border-0 shadow-sm p-3 mb-4 rounded-4">
    <form action="market_specials.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control bg-light border-0" placeholder="Search by title, keyword..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="product_type" class="form-select bg-light border-0">
                <option value="">All Product Types</option>
                <option value="birds_animals" <?php echo $filter_type === 'birds_animals' ? 'selected' : ''; ?>>Birds & Animals</option>
                <option value="seeds_food" <?php echo $filter_type === 'seeds_food' ? 'selected' : ''; ?>>Seeds & Animal Food</option>
                <option value="cages_houses" <?php echo $filter_type === 'cages_houses' ? 'selected' : ''; ?>>Cages & Houses</option>
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select bg-light border-0">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="sold_out" <?php echo $filter_status === 'sold_out' ? 'selected' : ''; ?>>Sold Out</option>
                <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">Filter</button>
            <?php if ($search || $filter_status || $filter_type): ?>
                <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Product</th>
                        <th>Type</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th>Images</th>
                        <th>Date Added</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($products && $products->num_rows > 0): ?>
                        <?php while ($p = $products->fetch_assoc()): ?>
                            <?php
                            $img_src = !empty($p['primary_image']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($p['primary_image']) : 'https://placehold.co/100x100?text=Special';
                            $type_label = [
                                'birds_animals' => 'Birds & Animals',
                                'seeds_food'    => 'Seeds & Food',
                                'cages_houses'  => 'Cages & Houses'
                            ][$p['product_type']] ?? 'Special';

                            $status_badge = 'bg-secondary';
                            if ($p['status'] === 'active') $status_badge = 'bg-success';
                            if ($p['status'] === 'sold_out') $status_badge = 'bg-danger';
                            if ($p['status'] === 'inactive') $status_badge = 'bg-secondary text-white';
                            ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <img src="<?php echo $img_src; ?>" class="rounded-3 me-3 border" style="width: 50px; height: 50px; object-fit: cover;" onerror="this.src='https://placehold.co/100x100?text=Special';">
                                        <div>
                                            <a href="market_special_details.php?id=<?php echo $p['id']; ?>" class="fw-bold text-dark text-decoration-none">
                                                <?php echo htmlspecialchars($p['title_en']); ?>
                                            </a>
                                            <div class="small text-muted">ID: #<?php echo $p['id']; ?> &bull; Slug: <?php echo htmlspecialchars($p['slug']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border px-2 py-1">
                                        <?php echo htmlspecialchars($type_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($p['discount_price']) && $p['discount_price'] > 0): ?>
                                        <s class="text-muted small"><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></s><br>
                                        <strong class="text-success"><?php echo $site_settings['currency'] . number_format($p['discount_price'], 2); ?></strong>
                                    <?php else: ?>
                                        <strong class="text-dark"><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form action="market_specials.php" method="POST" class="d-inline-flex align-items-center gap-1" style="max-width: 140px;">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="update_stock">
                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                        <input type="number" name="stock_quantity" min="0" value="<?php echo (int)$p['stock_quantity']; ?>" class="form-control form-control-sm text-center px-1" style="width: 65px;">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary px-2" title="Update Stock"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                </td>
                                <td>
                                    <span class="badge <?php echo $status_badge; ?> rounded-pill px-3 py-1">
                                        <?php echo ($p['status'] === 'sold_out') ? 'Sold Out' : ucfirst(htmlspecialchars($p['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-muted border">
                                        <i class="bi bi-images me-1"></i><?php echo (int)$p['image_count']; ?>
                                    </span>
                                </td>
                                <td>
                                    <small class="text-muted"><?php echo date('d M Y', strtotime($p['created_at'])); ?></small>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li>
                                                <a class="dropdown-item" href="market_special_details.php?id=<?php echo $p['id']; ?>">
                                                    <i class="bi bi-eye me-2 text-primary"></i> View Details
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="edit_market_special.php?id=<?php echo $p['id']; ?>">
                                                    <i class="bi bi-pencil me-2 text-warning"></i> Edit Product &amp; Images
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item" href="../product_details.php?id=<?php echo $p['id']; ?>" target="_blank">
                                                    <i class="bi bi-box-arrow-up-right me-2 text-info"></i> View in Marketplace
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($p['status'] === 'inactive'): ?>
                                                <li>
                                                    <form action="market_specials.php" method="POST" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="activate">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i> Activate</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form action="market_specials.php" method="POST" class="d-inline">
                                                        <?php csrf_field(); ?>
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-secondary"><i class="bi bi-pause-circle me-2"></i> Deactivate / Hide</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <li>
                                                <form action="market_specials.php" method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this Market Special?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="product_id" value="<?php echo $p['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i> Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-stars fs-1 text-muted d-block mb-3"></i>
                                <h5 class="text-muted fw-bold">No Market Specials Found</h5>
                                <p class="text-muted small mb-3">Click below to create an exclusive Admin Market Special.</p>
                                <a href="add_market_special.php" class="btn btn-primary rounded-pill px-4 btn-sm fw-bold">
                                    <i class="bi bi-plus-lg me-1"></i> Add First Market Special
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center p-3">
            <small class="text-muted">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo $total_items; ?> items)</small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>&q=<?php echo urlencode($search); ?>&product_type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>">&laquo;</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&product_type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>&q=<?php echo urlencode($search); ?>&product_type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>">&raquo;</a>
                    </li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
