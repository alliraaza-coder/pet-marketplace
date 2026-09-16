<?php
/**
 * Admin Activity Logs
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$page_title = "Admin Activity Logs";
$page_heading = "Audit Trails";
include __DIR__ . '/partials/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count total
$cnt_sql = "SELECT COUNT(*) as total FROM admin_activity_logs";
$total_logs = $conn->query($cnt_sql)->fetch_assoc()['total'];
$total_pages = max(1, ceil($total_logs / $per_page));

// Fetch logs
$sql = "SELECT l.*, u.first_name, u.last_name 
        FROM admin_activity_logs l 
        JOIN users u ON l.admin_id = u.id 
        ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $per_page, $offset);
$stmt->execute();
$logs = $stmt->get_result();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-1">System Audit Logs</h4>
        <p class="text-muted small mb-0"><?php echo $total_logs; ?> actions recorded</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Date & Time</th>
                        <th>Admin User</th>
                        <th>Action Performed</th>
                        <th>Details</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($logs && $logs->num_rows > 0): ?>
                        <?php while ($log = $logs->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4 text-nowrap"><small class="text-muted"><?php echo date('d M Y, h:i:s A', strtotime($log['created_at'])); ?></small></td>
                                <td>
                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></span>
                                </td>
                                <td><span class="badge bg-secondary rounded-pill"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                <td class="text-muted small" style="max-width: 300px;"><?php echo htmlspecialchars($log['details']); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? 'Unknown'); ?></small></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No activity logs found.</td></tr>
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
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page-1; ?>">&laquo;</a></li>
                    <?php for ($i=1; $i<=$total_pages; $i++): ?>
                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a></li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $page+1; ?>">&raquo;</a></li>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
