<?php
/**
 * Admin Sellers Management
 * Phase 3.3
 */
$page_title = "Sellers Management";
$page_heading = "Manage Sellers";
include __DIR__ . '/partials/header.php';

$admin_id = $_SESSION['user_id'];

// Handle Actions (Verify, Unverify, Activate, Suspend, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['seller_id'])) {
    $action    = sanitize_input($_POST['action']);
    $seller_id = (int)$_POST['seller_id'];

    if ($action === 'activate') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'seller'");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Activated Seller", "Activated Seller ID: $seller_id");
            $_SESSION['success'] = "Seller activated successfully.";
        }
    } elseif ($action === 'suspend') {
        $stmt = $conn->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role = 'seller'");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Suspended Seller", "Suspended/Banned Seller ID: $seller_id");
            $_SESSION['success'] = "Seller account suspended.";
        }
    } elseif ($action === 'verify') {
        $stmt = $conn->prepare("UPDATE users SET is_verified = 1 WHERE id = ? AND role = 'seller'");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Verified Seller", "Verified Seller ID: $seller_id");
            $_SESSION['success'] = "Seller verified successfully.";
        }
    } elseif ($action === 'unverify') {
        $stmt = $conn->prepare("UPDATE users SET is_verified = 0 WHERE id = ? AND role = 'seller'");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Unverified Seller", "Removed verification from Seller ID: $seller_id");
            $_SESSION['success'] = "Seller verification removed.";
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("UPDATE users SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND role = 'seller'");
        $stmt->bind_param("i", $seller_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Soft Deleted Seller", "Soft deleted Seller ID: $seller_id");
            $_SESSION['success'] = "Seller soft deleted successfully.";
        }
    }
    header('Location: sellers.php');
    exit;
}

// Search and Filters
$search        = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where  = "WHERE role = 'seller' AND is_deleted = 0";
$params = [];
$types  = "";

if ($search !== '') {
    $where   .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ?)";
    $s        = "%$search%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
    $types   .= "ssss";
}
if ($status_filter !== '') {
    $where   .= " AND status = ?";
    $params[] = $status_filter;
    $types   .= "s";
}

// Count total
$cnt_sql = "SELECT COUNT(*) as total FROM users $where";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_sellers = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages   = max(1, ceil($total_sellers / $per_page));

// Fetch sellers with earnings
$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.city, u.status, u.is_verified, u.created_at,
        (SELECT COUNT(*) FROM products WHERE seller_id = u.id AND is_deleted = 0) AS total_products,
        (SELECT COALESCE(SUM(grand_total), 0) FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.seller_id = u.id AND o.payment_status = 'released') AS released_earnings,
        (SELECT COALESCE(SUM(grand_total), 0) FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE oi.seller_id = u.id AND o.payment_status = 'held') AS pending_earnings
        FROM users u $where ORDER BY u.created_at DESC LIMIT ? OFFSET ?";

$params_p = array_merge($params, [$per_page, $offset]);
$types_p  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$sellers = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Sellers Directory</h4>
        <p class="text-muted small mb-0"><?php echo $total_sellers; ?> seller(s) registered on the marketplace</p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="sellers.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-light border-0"><i class="bi bi-search"></i></span>
                <input type="text" name="q" class="form-control bg-light border-0" placeholder="Search by name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <select name="status" class="form-select bg-light border-0">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="banned" <?php echo $status_filter === 'banned' ? 'selected' : ''; ?>>Suspended / Banned</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Filter</button>
            <?php if ($search || $status_filter): ?>
                <a href="sellers.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Sellers Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Seller</th>
                        <th>Products</th>
                        <th>Released Earnings</th>
                        <th>Pending Escrow</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sellers && $sellers->num_rows > 0): ?>
                        <?php while ($s = $sellers->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="d-flex align-items-center">
                                        <div>
                                            <div class="fw-bold text-dark">
                                                <?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>
                                                <?php if ($s['is_verified']): ?>
                                                    <i class="bi bi-patch-check-fill text-primary ms-1" title="Verified Seller"></i>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted"><?php echo htmlspecialchars($s['email']); ?> | <?php echo htmlspecialchars($s['phone'] ?? '—'); ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?php echo $s['total_products']; ?></span></td>
                                <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($s['released_earnings'], 2); ?></td>
                                <td class="fw-bold text-warning"><?php echo $site_settings['currency'] . number_format($s['pending_earnings'], 2); ?></td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-1"><?php echo ucfirst($s['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?php echo date('d M Y', strtotime($s['created_at'])); ?></small></td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li><a class="dropdown-item" href="seller_details.php?id=<?php echo $s['id']; ?>"><i class="bi bi-shop me-2"></i> View Profile & Store</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            
                                            <?php if ($s['is_verified']): ?>
                                                <li>
                                                    <form action="sellers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="unverify">
                                                        <input type="hidden" name="seller_id" value="<?php echo $s['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-secondary"><i class="bi bi-patch-minus me-2"></i> Remove Verification</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form action="sellers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="verify">
                                                        <input type="hidden" name="seller_id" value="<?php echo $s['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-primary"><i class="bi bi-patch-check-fill me-2"></i> Verify Seller</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>

                                            <?php if ($s['status'] === 'active'): ?>
                                                <li>
                                                    <form action="sellers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="suspend">
                                                        <input type="hidden" name="seller_id" value="<?php echo $s['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-warning" onclick="return confirm('Suspend this seller account?');"><i class="bi bi-pause-circle me-2"></i> Suspend Account</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form action="sellers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="activate">
                                                        <input type="hidden" name="seller_id" value="<?php echo $s['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i> Activate Account</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <li>
                                                <form action="sellers.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="seller_id" value="<?php echo $s['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Soft delete this seller?');"><i class="bi bi-trash me-2"></i> Soft Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">No sellers found.</td></tr>
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
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">&laquo;</a></li>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&q=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">&raquo;</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
