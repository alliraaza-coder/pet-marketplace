<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Must be logged in to view notifications
require_login();

$user_id = $_SESSION['user_id'];

// Handle Mark All as Read
if (isset($_GET['mark_all'])) {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    header("Location: notifications.php");
    exit;
}

// Handle Read Single Notification
if (isset($_GET['read']) && is_numeric($_GET['read'])) {
    $notif_id = (int)$_GET['read'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notif_id, $user_id);
    $stmt->execute();
    
    if (isset($_GET['url']) && !empty($_GET['url']) && $_GET['url'] !== '#') {
        header("Location: " . urldecode($_GET['url']));
        exit;
    }
    header("Location: notifications.php");
    exit;
}

// Pagination setup
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Fetch total count
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?");
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$total_notifs = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages = ceil($total_notifs / $limit);

// Fetch notifications
$stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->bind_param("iii", $user_id, $limit, $offset);
$stmt->execute();
$notifications = $stmt->get_result();

include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold mb-0"><i class="bi bi-bell text-primary me-2"></i>My Notifications</h3>
                <?php if ($total_notifs > 0): ?>
                <a href="?mark_all=1" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="bi bi-check2-all me-1"></i>Mark all as read</a>
                <?php endif; ?>
            </div>
            
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush rounded-4">
                        <?php if ($notifications->num_rows > 0): ?>
                            <?php while ($n = $notifications->fetch_assoc()): ?>
                                <a href="?read=<?php echo $n['id']; ?>&url=<?php echo urlencode($n['link'] ?? '#'); ?>" class="list-group-item list-group-item-action p-4 <?php echo $n['is_read'] ? 'bg-white' : 'bg-light border-start border-4 border-primary'; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-1">
                                        <h6 class="mb-0 fw-bold <?php echo $n['is_read'] ? 'text-dark' : 'text-primary'; ?>"><?php echo htmlspecialchars($n['title']); ?></h6>
                                        <small class="text-muted"><i class="bi bi-clock me-1"></i><?php echo date('d M Y, h:i A', strtotime($n['created_at'])); ?></small>
                                    </div>
                                    <p class="mb-0 text-secondary"><?php echo htmlspecialchars($n['message']); ?></p>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="p-5 text-center text-muted">
                                <i class="bi bi-bell-slash fs-1 d-block mb-3"></i>
                                <h5 class="fw-bold">No Notifications</h5>
                                <p>You're all caught up! When you receive updates about your orders or account, they will appear here.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                            <span aria-hidden="true">&laquo;</span>
                        </a>
                    </li>
                    <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                            <a class="page-link <?php echo $page == $i ? 'bg-primary border-primary text-white' : ''; ?>" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
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
