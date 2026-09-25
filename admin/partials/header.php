<?php
/**
 * Admin Header Layout
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$user = current_user($conn);

// Check forced password change
if (!empty($user['force_password_change']) && basename($_SERVER['PHP_SELF']) !== 'change_password.php') {
    $_SESSION['error'] = "For security reasons, you must change your default admin password before continuing.";
    header('Location: change_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Admin Panel - ' . htmlspecialchars($site_settings['site_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f4f6f9; }
        .sidebar { background-color: #343a40; }
        @media (min-width: 992px) { .sidebar { min-height: 100vh; position: sticky; top: 0; } }
        .sidebar a { color: #c2c7d0; text-decoration: none; padding: 12px 20px; display: block; border-bottom: 1px solid #4b545c; font-size: 0.95rem; }
        .sidebar a:hover, .sidebar a.active { background-color: #007bff; color: #fff; }
        .card { border-radius: 12px; }
        .stat-card { transition: transform .2s, box-shadow .2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,.08) !important; }
    </style>
</head>
<body>

<div class="container-fluid p-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="col-lg-10">
            <!-- Top navbar -->
            <nav class="navbar navbar-expand bg-white border-bottom shadow-sm px-4 py-3">
                <div class="d-flex align-items-center me-auto">
                    <h5 class="fw-bold mb-0 text-dark me-3"><?php echo isset($page_heading) ? htmlspecialchars($page_heading) : 'Admin Management'; ?></h5>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="../index.php" target="_blank">
                        <i class="bi bi-box-arrow-up-right me-1"></i> View Website
                    </a>

                    <?php
                    // Fetch unread notifications
                    $notif_q = $conn->query("SELECT * FROM admin_notifications WHERE is_read = 0 ORDER BY created_at DESC LIMIT 5");
                    $unread_count = $conn->query("SELECT COUNT(*) AS cnt FROM admin_notifications WHERE is_read = 0")->fetch_assoc()['cnt'];
                    ?>
                    <div class="dropdown">
                        <button class="btn btn-light btn-sm rounded-circle position-relative p-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="width:36px;height:36px;">
                            <i class="bi bi-bell"></i>
                            <?php if ($unread_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.65rem;">
                                <?php echo $unread_count > 99 ? '99+' : $unread_count; ?>
                            </span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-lg" style="width: 320px;">
                            <li><h6 class="dropdown-header fw-bold text-dark d-flex justify-content-between align-items-center">
                                Notifications
                                <?php if ($unread_count > 0): ?>
                                    <a href="read_notif.php?action=read_all" class="text-decoration-none text-primary" style="font-size: 0.8rem;">Mark all read</a>
                                <?php endif; ?>
                            </h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if ($unread_count == 0): ?>
                                <li><a class="dropdown-item text-center text-muted small py-3" href="#">No new notifications</a></li>
                            <?php else: ?>
                                <?php while($notif = $notif_q->fetch_assoc()): ?>
                                    <li>
                                        <a class="dropdown-item py-2" href="read_notif.php?id=<?php echo $notif['id']; ?>">
                                            <div class="fw-bold" style="font-size: 0.85rem;"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div class="text-muted text-wrap" style="font-size: 0.8rem;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div class="text-secondary mt-1" style="font-size: 0.7rem;"><i class="bi bi-clock me-1"></i><?php echo date('d M H:i', strtotime($notif['created_at'])); ?></div>
                                        </a>
                                    </li>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div class="dropdown">
                        <button class="btn btn-light btn-sm rounded-pill dropdown-toggle px-3 border" type="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i> <?php echo htmlspecialchars($user['first_name']); ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i> Profile Settings</a></li>
                            <li><a class="dropdown-item" href="activity_logs.php"><i class="bi bi-journal-text me-2"></i> Activity Logs</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i> Logout</a></li>
                        </ul>
                    </div>
                </div>
            </nav>
            
            <div class="p-4">
                <?php display_messages(); ?>
