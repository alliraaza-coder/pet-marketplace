<?php
/**
 * My Products — Phase 3.1
 * Seller product list with search, filter by status, and pagination.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];

// ── Inputs ────────────────────────────────────────────────
$search     = trim($_GET['q'] ?? '');
$filter_status = in_array($_GET['status'] ?? '', ['active','pending','sold','inactive',''])
                 ? ($_GET['status'] ?? '') : '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$per_page   = 10;
$offset     = ($page - 1) * $per_page;

// ── Build Query ───────────────────────────────────────────
$where  = 'WHERE p.seller_id = ? AND p.is_deleted = 0';
$params = [$seller_id];
$types  = 'i';

if ($search !== '') {
    $where   .= ' AND p.title_en LIKE ?';
    $params[] = '%' . $search . '%';
    $types   .= 's';
}
if ($filter_status !== '') {
    $where   .= ' AND p.status = ?';
    $params[] = $filter_status;
    $types   .= 's';
}

// Total count
$count_sql  = "SELECT COUNT(p.id) AS total FROM products p $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total = (int)$count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total / $per_page));
$page = min($page, $total_pages);

// Products with primary image
$sql = "SELECT p.id, p.title_en, p.price, p.discount_price, p.stock_quantity,
               p.status, p.listing_type, p.is_featured, p.views, p.created_at,
               c.name_en AS cat_name,
               (SELECT pi.image_url FROM product_images pi
                WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS thumb
        FROM products p
        JOIN categories c ON p.category_id = c.id
        $where
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";

$params_paged  = array_merge($params, [$per_page, $offset]);
$types_paged   = $types . 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_paged, ...$params_paged);
$stmt->execute();
$products = $stmt->get_result();

// Product counts for quick filter tabs
$tab_stmt = $conn->prepare(
    "SELECT status, COUNT(*) AS cnt FROM products WHERE seller_id = ? AND is_deleted = 0 GROUP BY status"
);
$tab_stmt->bind_param('i', $seller_id);
$tab_stmt->execute();
$tab_result = $tab_stmt->get_result();
$tab_counts = ['all' => 0, 'active' => 0, 'pending' => 0, 'sold' => 0, 'inactive' => 0];
while ($t = $tab_result->fetch_assoc()) {
    $tab_counts[$t['status']] = (int)$t['cnt'];
    $tab_counts['all'] += (int)$t['cnt'];
}

include '../includes/header.php';
?>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">

        <!-- Sidebar -->
        <div class="col-lg-2 d-none d-lg-block">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- Main -->
        <div class="col-lg-10">
            <?php display_messages(); ?>

            <!-- Page Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-box-seam text-warning me-2"></i>My Products
                    </h3>
                    <p class="text-muted mb-0 small"><?php echo $total; ?> listing<?php echo $total !== 1 ? 's' : ''; ?> found</p>
                </div>
                <a href="add_product.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">
                    <i class="bi bi-plus-lg me-1"></i> Add Product
                </a>
            </div>

            <!-- Filter Tabs -->
            <div class="d-flex gap-2 mb-3 flex-wrap">
                <?php
                $tab_items = ['all'=>'All','active'=>'Active','pending'=>'Pending','sold'=>'Sold','inactive'=>'Inactive'];
                foreach ($tab_items as $val => $label):
                    $qs      = http_build_query(['q' => $search, 'status' => ($val === 'all' ? '' : $val)]);
                    $is_active = ($filter_status === ($val === 'all' ? '' : $val));
                ?>
                <a href="products.php?<?php echo $qs; ?>"
                   class="btn btn-sm rounded-pill <?php echo $is_active ? 'btn-dark' : 'btn-outline-secondary'; ?>">
                    <?php echo $label; ?>
                    <span class="badge <?php echo $is_active ? 'bg-warning text-dark' : 'bg-secondary'; ?> rounded-pill ms-1">
                        <?php echo $tab_counts[$val === 'all' ? 'all' : $val]; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Search Bar -->
            <form action="products.php" method="GET" class="mb-3">
                <?php if ($filter_status): ?>
                    <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status); ?>">
                <?php endif; ?>
                <div class="input-group rounded-pill overflow-hidden shadow-sm" style="max-width:480px;">
                    <input type="text" name="q" class="form-control border-0 bg-light"
                           placeholder="Search products by name..."
                           value="<?php echo htmlspecialchars($search); ?>">
                    <button class="btn btn-success px-4" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                    <?php if ($search): ?>
                    <a href="products.php<?php echo $filter_status ? '?status='.$filter_status : ''; ?>"
                       class="btn btn-outline-secondary px-3">
                        <i class="bi bi-x"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Products Table -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4" style="width:60px;">Image</th>
                                    <th>Product Name</th>
                                    <th>Category</th>
                                    <th>Price</th>
                                    <th>Stock</th>
                                    <th>Status</th>
                                    <th>Views</th>
                                    <th class="pe-4 text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if ($products->num_rows > 0): ?>
                                <?php while ($p = $products->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <?php if ($p['thumb']): ?>
                                            <img src="<?php echo BASE_URL; ?>/assets/uploads/products/<?php echo htmlspecialchars($p['thumb']); ?>"
                                                 class="rounded-3 object-fit-cover border"
                                                 style="width:50px;height:50px;"
                                                 alt="thumb">
                                        <?php else: ?>
                                            <div class="bg-light rounded-3 d-flex align-items-center justify-content-center text-muted border"
                                                 style="width:50px;height:50px;">
                                                <i class="bi bi-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <p class="fw-medium mb-0 text-truncate" style="max-width:220px;">
                                            <?php echo htmlspecialchars($p['title_en']); ?>
                                        </p>
                                        <?php if ($p['is_featured']): ?>
                                            <span class="badge bg-warning text-dark rounded-pill" style="font-size:.68rem;">
                                                <i class="bi bi-star-fill me-1"></i>Featured
                                            </span>
                                        <?php endif; ?>
                                        <small class="text-muted d-block"><?php echo date('d M Y', strtotime($p['created_at'])); ?></small>
                                    </td>
                                    <td><span class="text-muted small"><?php echo htmlspecialchars($p['cat_name']); ?></span></td>
                                    <td>
                                        <?php if ($p['discount_price'] && $p['discount_price'] < $p['price']): ?>
                                            <s class="text-muted small"><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></s><br>
                                            <strong class="text-success"><?php echo $site_settings['currency'] . number_format($p['discount_price'], 2); ?></strong>
                                        <?php else: ?>
                                            <strong><?php echo $site_settings['currency'] . number_format($p['price'], 2); ?></strong>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?php echo $p['stock_quantity'] <= 5 ? 'text-danger fw-bold' : ''; ?>">
                                            <?php echo (int)$p['stock_quantity']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $badge = ['active'=>'success','pending'=>'warning','sold'=>'secondary','inactive'=>'danger'];
                                        $bc    = $badge[$p['status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $bc; ?> rounded-pill px-3 py-1">
                                            <?php echo ucfirst(htmlspecialchars($p['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><i class="bi bi-eye me-1"></i><?php echo (int)$p['views']; ?></small>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <div class="btn-group btn-group-sm">
                                            <a href="../product.php?id=<?php echo $p['id']; ?>"
                                               target="_blank"
                                               class="btn btn-outline-secondary rounded-start"
                                               title="View on site">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit_product.php?id=<?php echo $p['id']; ?>"
                                               class="btn btn-outline-primary"
                                               title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <a href="delete_product.php?id=<?php echo $p['id']; ?>"
                                               class="btn btn-outline-danger rounded-end"
                                               title="Delete"
                                               onclick="return confirmDelete('<?php echo htmlspecialchars(addslashes($p['title_en'])); ?>')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-5">
                                        <i class="bi bi-box-seam fs-1 text-muted d-block mb-3"></i>
                                        <h5 class="text-muted">No products found</h5>
                                        <?php if ($search || $filter_status): ?>
                                            <p class="text-muted small mb-3">Try clearing your filters</p>
                                            <a href="products.php" class="btn btn-outline-secondary rounded-pill me-2">Clear Filters</a>
                                        <?php endif; ?>
                                        <a href="add_product.php" class="btn btn-warning rounded-pill px-4">
                                            <i class="bi bi-plus-lg me-1"></i> Add Your First Product
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
                <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center p-4">
                    <small class="text-muted">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        (<?php echo $total; ?> total)
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <!-- Prev -->
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link rounded-pill me-1" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>">
                                    &laquo;
                                </a>
                            </li>
                            <?php
                            $start = max(1, $page - 2);
                            $end   = min($total_pages, $page + 2);
                            for ($i = $start; $i <= $end; $i++):
                            ?>
                            <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                <a class="page-link <?php echo ($i === $page) ? 'bg-warning border-warning text-dark' : ''; ?>"
                                   href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            <!-- Next -->
                            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                <a class="page-link rounded-pill ms-1" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($filter_status); ?>">
                                    &raquo;
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>

            </div><!-- /card -->
        </div>
    </div>
</div>

<script>
function confirmDelete(name) {
    return confirm('Are you sure you want to delete:\n"' + name + '"?\n\nThis will also delete all product images and cannot be undone.');
}
</script>

<?php include '../includes/footer.php'; ?>
