<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$page_title = "Manage Reviews";
$page_heading = "Product Reviews";

// Handle Actions (Approve, Reject, Delete)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'], $_POST['review_id'])) {
    verify_csrf();
    $action = sanitize_input($_POST['action']);
    $review_id = (int)$_POST['review_id'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review #$review_id has been approved and published.";
        log_admin_activity($conn, $_SESSION['user_id'], "Approved Review", "Review ID: $review_id");
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review #$review_id has been rejected and hidden.";
        log_admin_activity($conn, $_SESSION['user_id'], "Rejected Review", "Review ID: $review_id");
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review #$review_id deleted permanently.";
        log_admin_activity($conn, $_SESSION['user_id'], "Deleted Review", "Review ID: $review_id");
    }

    $redirect_status = isset($_GET['status']) ? '?status=' . urlencode($_GET['status']) : '';
    header("Location: reviews.php" . $redirect_status);
    exit;
}

$allowed_statuses = ['pending', 'approved', 'rejected', 'all'];
$status_filter = isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses) ? $_GET['status'] : 'pending';

// Counts for filter tabs
$pending_cnt = $conn->query("SELECT COUNT(*) AS c FROM reviews WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
$approved_cnt = $conn->query("SELECT COUNT(*) AS c FROM reviews WHERE status = 'approved'")->fetch_assoc()['c'] ?? 0;
$rejected_cnt = $conn->query("SELECT COUNT(*) AS c FROM reviews WHERE status = 'rejected'")->fetch_assoc()['c'] ?? 0;
$all_cnt = $pending_cnt + $approved_cnt + $rejected_cnt;

// Query reviews
$base_query = "SELECT r.*, p.title_en as product_title, u.first_name, u.last_name, u.email
               FROM reviews r 
               JOIN products p ON r.product_id = p.id 
               JOIN users u ON r.user_id = u.id";

if ($status_filter === 'all') {
    $reviews = $conn->query($base_query . " ORDER BY r.created_at DESC");
} else {
    $stmt = $conn->prepare($base_query . " WHERE r.status = ? ORDER BY r.created_at DESC");
    $stmt->bind_param("s", $status_filter);
    $stmt->execute();
    $reviews = $stmt->get_result();
}

include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-1">Customer Reviews</h4>
        <p class="text-muted small mb-0">Approve or moderate customer reviews submitted on products.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="?status=pending" class="btn btn-sm <?php echo $status_filter === 'pending' ? 'btn-warning text-dark fw-bold' : 'btn-outline-warning text-dark'; ?> rounded-pill px-3 shadow-sm">
            Pending <?php if ($pending_cnt > 0): ?><span class="badge bg-dark text-white rounded-pill ms-1"><?php echo $pending_cnt; ?></span><?php endif; ?>
        </a>
        <a href="?status=approved" class="btn btn-sm <?php echo $status_filter === 'approved' ? 'btn-success fw-bold' : 'btn-outline-success'; ?> rounded-pill px-3 shadow-sm">
            Approved <?php if ($approved_cnt > 0): ?><span class="badge bg-white text-success rounded-pill ms-1"><?php echo $approved_cnt; ?></span><?php endif; ?>
        </a>
        <a href="?status=rejected" class="btn btn-sm <?php echo $status_filter === 'rejected' ? 'btn-danger fw-bold' : 'btn-outline-danger'; ?> rounded-pill px-3 shadow-sm">
            Rejected <?php if ($rejected_cnt > 0): ?><span class="badge bg-white text-danger rounded-pill ms-1"><?php echo $rejected_cnt; ?></span><?php endif; ?>
        </a>
        <a href="?status=all" class="btn btn-sm <?php echo $status_filter === 'all' ? 'btn-secondary fw-bold' : 'btn-outline-secondary'; ?> rounded-pill px-3 shadow-sm">
            All (<?php echo $all_cnt; ?>)
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Product</th>
                        <th>User</th>
                        <th>Rating</th>
                        <th style="max-width: 320px;">Review Comment</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th class="pe-4 text-end" style="min-width: 220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($reviews && $reviews->num_rows > 0): ?>
                        <?php while($r = $reviews->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <a href="../product_details.php?id=<?php echo $r['product_id']; ?>" target="_blank" class="text-decoration-none fw-bold text-dark">
                                    <?php echo htmlspecialchars($r['product_title']); ?> <i class="bi bi-box-arrow-up-right small text-muted ms-1"></i>
                                </a>
                            </td>
                            <td>
                                <div class="small fw-bold text-dark"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($r['email']); ?></div>
                            </td>
                            <td class="text-warning text-nowrap">
                                <?php for($i=1; $i<=5; $i++): ?>
                                    <i class="bi bi-star<?php echo $i <= $r['rating'] ? '-fill' : ''; ?>"></i>
                                <?php endfor; ?>
                                <span class="small text-muted ms-1 fw-bold">(<?php echo $r['rating']; ?>/5)</span>
                            </td>
                            <td>
                                <p class="small text-muted mb-0" style="max-width: 320px;" title="<?php echo htmlspecialchars($r['comment']); ?>">
                                    <?php echo htmlspecialchars($r['comment'] ?: 'No written comment'); ?>
                                </p>
                            </td>
                            <td>
                                <?php 
                                    $badge = 'bg-secondary';
                                    if ($r['status'] === 'approved') $badge = 'bg-success';
                                    elseif ($r['status'] === 'pending') $badge = 'bg-warning text-dark';
                                    elseif ($r['status'] === 'rejected') $badge = 'bg-danger';
                                ?>
                                <span class="badge <?php echo $badge; ?> rounded-pill px-3 py-1"><?php echo ucfirst($r['status']); ?></span>
                            </td>
                            <td class="small text-muted text-nowrap">
                                <?php echo date('d M Y, h:i A', strtotime($r['created_at'])); ?>
                            </td>
                            <td class="pe-4 text-end text-nowrap">
                                <div class="d-inline-flex align-items-center gap-1">
                                    <?php if ($r['status'] !== 'approved'): ?>
                                    <form action="reviews.php<?php echo !empty($_GET['status']) ? '?status='.urlencode($_GET['status']) : ''; ?>" method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success rounded-pill px-3 fw-bold shadow-sm" title="Approve Review">
                                            <i class="bi bi-check-circle me-1"></i> Approve
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($r['status'] !== 'rejected'): ?>
                                    <form action="reviews.php<?php echo !empty($_GET['status']) ? '?status='.urlencode($_GET['status']) : ''; ?>" method="POST" class="d-inline">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2" title="Reject / Hide Review">
                                            <i class="bi bi-x-circle me-1"></i> Reject
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form action="reviews.php<?php echo !empty($_GET['status']) ? '?status='.urlencode($_GET['status']) : ''; ?>" method="POST" class="d-inline" onsubmit="return confirm('Permanently delete review #<?php echo $r['id']; ?>?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary rounded-circle" title="Delete Review" style="width:31px;height:31px;padding:0;">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-chat-square-text fs-2 d-block mb-2 text-muted opacity-50"></i>
                                <?php if ($status_filter === 'pending'): ?>
                                    <strong>No pending reviews awaiting approval.</strong>
                                <?php elseif ($status_filter === 'approved'): ?>
                                    <strong>No approved reviews found.</strong>
                                <?php elseif ($status_filter === 'rejected'): ?>
                                    <strong>No rejected reviews found.</strong>
                                <?php else: ?>
                                    <strong>No reviews found.</strong>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
