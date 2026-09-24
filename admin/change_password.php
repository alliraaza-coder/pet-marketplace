<?php
/**
 * Admin Force/Manual Change Password Page
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$user = current_user($conn);
$user_id = $_SESSION['user_id'];

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $error = "Please fill in all password fields.";
    } elseif (strlen($new_password) < 8) {
        $error = "New password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New passwords do not match.";
    } else {
        // Verify current password unless forced and password hash matches admin123
        $pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $pass_stmt->bind_param("i", $user_id);
        $pass_stmt->execute();
        $db_pass = $pass_stmt->get_result()->fetch_assoc()['password'];

        if (!empty($current_password) && !password_verify($current_password, $db_pass)) {
            $error = "Current password is incorrect.";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET password = ?, force_password_change = 0 WHERE id = ?");
            $upd->bind_param("si", $new_hash, $user_id);

            if ($upd->execute()) {
                log_admin_activity($conn, $user_id, "Password Changed", "Updated admin password.");
                $_SESSION['success'] = "Password updated successfully! Welcome to your Admin Panel.";
                header('Location: dashboard.php');
                exit;
            } else {
                $error = "Failed to update password. Please try again.";
            }
        }
    }
}

$page_title = "Change Password - " . $site_settings['site_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #121212; color: #fff; display: flex; align-items: center; justify-content: center; height: 100vh; font-family: 'Inter', sans-serif; }
        .card-custom { background-color: #1E1E1E; border-radius: 12px; border: 1px solid rgba(255,255,255,0.05); }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card card-custom p-4 shadow-lg">
                <div class="card-body">
                    <div class="text-center mb-4">
                        <i class="bi bi-key-fill fs-1 text-warning"></i>
                        <h4 class="fw-bold mt-2">Change Admin Password</h4>
                        <p class="text-muted small">Update your password to secure your admin account.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php display_messages(); ?>

                    <form action="change_password.php" method="POST">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label text-muted">Current Password</label>
                            <input type="password" name="current_password" class="form-control bg-dark text-white border-secondary" placeholder="Enter current password (if set)" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">New Password (min 8 chars)</label>
                            <input type="password" name="new_password" class="form-control bg-dark text-white border-secondary" required minlength="8">
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-muted">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control bg-dark text-white border-secondary" required minlength="8">
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning fw-bold py-2">Update Password & Continue</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
