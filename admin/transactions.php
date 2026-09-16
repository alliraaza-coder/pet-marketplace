<?php
/**
 * Admin Transactions Management
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];

// Handle Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Order Number', 'Type', 'Amount', 'Status', 'Date']);
    
    $sql = "SELECT t.id, o.order_number, t.transaction_type, t.amount, t.status, t.created_at
            FROM transactions t
            LEFT JOIN orders o ON t.order_id = o.id
            ORDER BY t.created_at DESC";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['id'],
            $row['order_number'] ?? 'N/A',
            ucfirst($row['transaction_type']),
            $site_settings['currency'] . number_format($row['amount'], 2),
            ucfirst($row['status']),
            $row['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// Search and Filters
$type_filter = trim($_GET['type'] ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$per_page    = 20;
$offset      = ($page - 1) * $per_page;

$where = "WHERE 1=1";
$params = [];
$types = "";

if ($type_filter !== '') {
    $where .= " AND t.transaction_type = ?";
    $params[] = $type_filter;
    $types .= "s";
}

// Count total
$cnt_sql = "SELECT COUNT(*) as total FROM transactions t $where";
$cnt_stmt = $conn->prepare($cnt_sql);
if ($types) $cnt_stmt->bind_param($types, ...$params);
$cnt_stmt->execute();
$total_tx = $cnt_stmt->get_result()->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_tx / $per_page));

// Fetch transactions
$sql = "SELECT t.*, o.order_number 
        FROM transactions t
        LEFT JOIN orders o ON t.order_id = o.id
        $where 
        ORDER BY t.created_at DESC LIMIT ? OFFSET ?";

$params_p = array_merge($params, [$per_page, $offset]);
$types_p  = $types . "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types_p, ...$params_p);
$stmt->execute();
$transactions = $stmt->get_result();

$page_title = "Transactions Log";
$page_heading = "Transactions";
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">Transactions Ledger</h4>
        <p class="text-muted small mb-0"><?php echo $total_tx; ?> transaction(s) recorded</p>
    </div>
    <a href="?export=csv" class="btn btn-outline-primary rounded-pill px-4 fw-bold">
        <i class="bi bi-file-earmark-excel me-2"></i> Export to CSV
    </a>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm p-3 mb-4">
    <form action="transactions.php" method="GET" class="row g-2 align-items-center">
        <div class="col-md-4">
            <select name="type" class="form-select bg-light border-0">
                <option value="">All Transaction Types</option>
                <option value="payment" <?php echo $type_filter === 'payment' ? 'selected' : ''; ?>>Buyer Payment</option>
                <option value="release" <?php echo $type_filter === 'release' ? 'selected' : ''; ?>>Seller Release (Payout)</option>
                <option value="refund" <?php echo $type_filter === 'refund' ? 'selected' : ''; ?>>Buyer Refund</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold w-100">Filter</button>
            <?php if ($type_filter): ?>
                <a href="transactions.php" class="btn btn-outline-secondary rounded-pill px-3">Reset</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Transactions Table -->
<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Txn ID</th>
                        <th>Date</th>
                        <th>Order #</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions && $transactions->num_rows > 0): ?>
                        <?php while ($tx = $transactions->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4 text-muted">#<?php echo $tx['id']; ?></td>
                                <td><?php echo date('d M Y, h:i A', strtotime($tx['created_at'])); ?></td>
                                <td>
                                    <?php if ($tx['order_number']): ?>
                                        <a href="order_details.php?id=<?php echo $tx['order_id']; ?>" class="fw-bold text-primary">#<?php echo htmlspecialchars($tx['order_number']); ?></a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $t_icon = '';
                                    if ($tx['transaction_type'] === 'payment') $t_icon = '<i class="bi bi-box-arrow-in-right text-success me-1"></i>';
                                    if ($tx['transaction_type'] === 'release') $t_icon = '<i class="bi bi-box-arrow-up-right text-primary me-1"></i>';
                                    if ($tx['transaction_type'] === 'refund') $t_icon = '<i class="bi bi-arrow-counterclockwise text-danger me-1"></i>';
                                    ?>
                                    <?php echo $t_icon . ucfirst($tx['transaction_type']); ?>
                                </td>
                                <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($tx['amount'], 2); ?></td>
                                <td>
                                    <?php if ($tx['status'] === 'completed'): ?>
                                        <span class="badge bg-success rounded-pill px-3 py-1">Completed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3 py-1"><?php echo ucfirst($tx['status']); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted">No transactions found.</td></tr>
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
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>&type=<?php echo urlencode($type_filter); ?>">&laquo;</a></li>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&type=<?php echo urlencode($type_filter); ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>&type=<?php echo urlencode($type_filter); ?>">&raquo;</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
