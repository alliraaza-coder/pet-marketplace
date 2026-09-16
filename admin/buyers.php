<?php
/**
 * Admin Buyers Management
 * Phase 3.3
 */
$page_title = "Buyers Management";
$page_heading = "Manage Buyers";
include __DIR__ . '/partials/header.php';

$admin_id = $_SESSION['user_id'];

// Handle Actions (Activate, Deactivate, Soft Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['buyer_id'])) {
    $action   = sanitize_input($_POST['action']);
    $buyer_id = (int)$_POST['buyer_id'];

    if ($action === 'activate') {
        $stmt = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'user'");
        $stmt->bind_param("i", $buyer_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Activated Buyer", "Activated Buyer ID: $buyer_id");
            $_SESSION['success'] = "Buyer activated successfully.";
        }
    } elseif ($action === 'deactivate') {
        $stmt = $conn->prepare("UPDATE users SET status = 'banned' WHERE id = ? AND role = 'user'");
        $stmt->bind_param("i", $buyer_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Deactivated Buyer", "Deactivated/Banned Buyer ID: $buyer_id");
            $_SESSION['success'] = "Buyer account deactivated/banned.";
        }
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("UPDATE users SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND role = 'user'");
        $stmt->bind_param("i", $buyer_id);
        if ($stmt->execute()) {
            log_admin_activity($conn, $admin_id, "Soft Deleted Buyer", "Soft deleted Buyer ID: $buyer_id");
            $_SESSION['success'] = "Buyer soft deleted successfully.";
        }
    }
    header('Location: buyers.php');
    exit;
}

// Search and Filters
$search        = trim($_GET['q'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

$where  = "WHERE role = 'user' AND is_deleted = 0";
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
$total_buyers = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages  = max(1, ceil($total_buyers / $per_page));

// Fetch buyers
$sql = "SELECT id, first_name, last_name, email, phone, city, status, created_at,
        (SELECT COUNT(*) FROM orders WHERE user_id = users.id) AS total_orders,
        (SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE user_id = users.id AND payment_status = 'released') AS total_spent
        FROM users $where ORDER BY created_at DESC LIMIT ? OFFSET ?";

$params_p = array_merge($params, [$per_page, $offset]);
$types_p  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$buyers = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Buyers Directory</h4>
        <p class="text-muted small mb-0"><?php echo $total_buyers; ?> buyer account(s) registered</p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="buyers.php" method="GET" class="row g-2 align-items-center">
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
                <option value="banned" <?php echo $status_filter === 'banned' ? 'selected' : ''; ?>>Banned / Deactivated</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Filter</button>
            <?php if ($search || $status_filter): ?>
                <a href="buyers.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Buyers Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Buyer</th>
                        <th>Contact</th>
                        <th>City</th>
                        <th>Orders</th>
                        <th>Total Spent</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th class="pe-4 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($buyers && $buyers->num_rows > 0): ?>
                        <?php while ($b = $buyers->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold text-dark"><?php echo htmlspecialchars($b['first_name'] . ' ' . $b['last_name']); ?></div>
                                    <small class="text-muted">ID: #<?php echo $b['id']; ?></small>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($b['email']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($b['phone'] ?? '—'); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($b['city'] ?? '—'); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo $b['total_orders']; ?> orders</span></td>
                                <td class="fw-bold text-success"><?php echo $site_settings['currency'] . number_format($b['total_spent'], 2); ?></td>
                                <td>
                                    <?php if ($b['status'] === 'active'): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-pill px-3 py-1"><?php echo ucfirst($b['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?php echo date('d M Y', strtotime($b['created_at'])); ?></small></td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border rounded-circle" type="button" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow">
                                            <li><a class="dropdown-item" href="buyer_details.php?id=<?php echo $b['id']; ?>"><i class="bi bi-eye me-2"></i> View Details & Orders</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <?php if ($b['status'] === 'active'): ?>
                                                <li>
                                                    <form action="buyers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="deactivate">
                                                        <input type="hidden" name="buyer_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-warning" onclick="return confirm('Deactivate this buyer account?');"><i class="bi bi-pause-circle me-2"></i> Deactivate Account</button>
                                                    </form>
                                                </li>
                                            <?php else: ?>
                                                <li>
                                                    <form action="buyers.php" method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="activate">
                                                        <input type="hidden" name="buyer_id" value="<?php echo $b['id']; ?>">
                                                        <button type="submit" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i> Activate Account</button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                            <li>
                                                <form action="buyers.php" method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="buyer_id" value="<?php echo $b['id']; ?>">
                                                    <button type="submit" class="dropdown-item text-danger" onclick="return confirm('Soft delete this buyer? They can be restored by DB admin.');"><i class="bi bi-trash me-2"></i> Soft Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted">No buyers found.</td></tr>
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
