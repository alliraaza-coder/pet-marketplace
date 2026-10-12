<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$page_title = "Manage Reviews";
$page_heading = "Product Reviews";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['review_id'])) {
    verify_csrf();
    $action = sanitize_input($_POST['action']);
    $review_id = (int)$_POST['review_id'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review approved and published.";
        log_admin_activity($conn, $_SESSION['user_id'], "Approved Review", "Review ID: $review_id");
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review rejected and hidden.";
        log_admin_activity($conn, $_SESSION['user_id'], "Rejected Review", "Review ID: $review_id");
    } elseif ($action === 'delete') {
        $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->bind_param("i", $review_id);
        $stmt->execute();
        $_SESSION['success'] = "Review deleted permanently.";
        log_admin_activity($conn, $_SESSION['user_id'], "Deleted Review", "Review ID: $review_id");
    }
    header("Location: reviews.php");
    exit;
}

$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'pending';

$query = "SELECT r.*, p.title_en as product_title, u.first_name, u.last_name, u.email
          FROM reviews r 
          JOIN products p ON r.product_id = p.id 
          JOIN users u ON r.user_id = u.id";

if ($status_filter === 'all') {
    $query .= " ORDER BY r.created_at DESC";
} else {
    $query .= " WHERE r.status = '$status_filter' ORDER BY r.created_at DESC";
}

$reviews = $conn->query($query);

include __DIR__ . '/partials/header.php';
?>

<div class="p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">Manage Reviews</h4>
        <div>
            <a href="?status=pending" class="btn btn-sm <?php echo $status_filter === 'pending' ? 'btn-warning' : 'btn-outline-warning'; ?> rounded-pill px-3">Pending</a>
            <a href="?status=approved" class="btn btn-sm <?php echo $status_filter === 'approved' ? 'btn-success' : 'btn-outline-success'; ?> rounded-pill px-3">Approved</a>
            <a href="?status=rejected" class="btn btn-sm <?php echo $status_filter === 'rejected' ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill px-3">Rejected</a>
            <a href="?status=all" class="btn btn-sm <?php echo $status_filter === 'all' ? 'btn-secondary' : 'btn-outline-secondary'; ?> rounded-pill px-3">All</a>
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
                            <th style="max-width: 300px;">Comment</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th class="pe-4 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($reviews && $reviews->num_rows > 0): ?>
                            <?php while($r = $reviews->fetch_assoc()): ?>
                            <tr>
                                <td class="ps-4">
                                    <a href="../product_details.php?id=<?php echo $r['product_id']; ?>" target="_blank" class="text-decoration-none fw-bold text-dark">
                                        <?php echo htmlspecialchars($r['product_title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <div class="small fw-bold text-dark"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($r['email']); ?></div>
                                </td>
                                <td class="text-warning">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <i class="bi bi-star<?php echo $i<=$r['rating'] ? '-fill' : ''; ?>"></i>
                                    <?php endfor; ?>
                                </td>
                                <td>
                                    <p class="small text-muted mb-0 text-truncate" style="max-width: 300px;" title="<?php echo htmlspecialchars($r['comment']); ?>">
                                        <?php echo htmlspecialchars($r['comment'] ?: 'No comment'); ?>
                                    </p>
                                </td>
                                <td>
                                    <?php 
                                        $badge = 'bg-secondary';
                                        if($r['status'] == 'approved') $badge = 'bg-success';
                                        elseif($r['status'] == 'pending') $badge = 'bg-warning text-dark';
                                        elseif($r['status'] == 'rejected') $badge = 'bg-danger';
                                    ?>
                                    <span class="badge <?php echo $badge; ?>"><?php echo ucfirst($r['status']); ?></span>
                                </td>
                                <td class="small text-muted">
                                    <?php echo date('d M Y', strtotime($r['created_at'])); ?>
                                </td>
                                <td class="pe-4 text-end">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light rounded-circle" type="button" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                            <?php if($r['status'] !== 'approved'): ?>
                                            <li>
                                                <form action="" method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="action" value="approve" class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i>Approve</button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <?php if($r['status'] !== 'rejected'): ?>
                                            <li>
                                                <form action="" method="POST" class="d-inline">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="action" value="reject" class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i>Reject</button>
                                                </form>
                                            </li>
                                            <?php endif; ?>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="" method="POST" class="d-inline" onsubmit="return confirm('Delete permanently?');">
                                                    <?php csrf_field(); ?>
                                                    <input type="hidden" name="review_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" name="action" value="delete" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No reviews found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
