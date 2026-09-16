<?php
/**
 * Admin Profile
 * Phase 3.3
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];

// Fetch Admin Data
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $first_name = sanitize_input($_POST['first_name']);
        $last_name = sanitize_input($_POST['last_name']);
        $email = sanitize_input($_POST['email']);

        // Profile Picture Upload
        $profile_pic = $admin['profile_pic'];
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/users/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
            $pic_name = 'admin_' . $admin_id . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $upload_dir . $pic_name)) {
                $profile_pic = 'users/' . $pic_name;
            }
        }

        $stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, email = ?, profile_pic = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $first_name, $last_name, $email, $profile_pic, $admin_id);
        
        if ($stmt->execute()) {
            $_SESSION['name'] = $first_name . ' ' . $last_name;
            log_admin_activity($conn, $admin_id, "Updated Profile", "Admin updated their own profile.");
            $_SESSION['success'] = "Profile updated successfully.";
            header('Location: profile.php');
            exit;
        } else {
            $_SESSION['error'] = "Profile update failed.";
        }
    } elseif ($_POST['action'] === 'update_password') {
        $current_pass = $_POST['current_password'];
        $new_pass = $_POST['new_password'];
        $confirm_pass = $_POST['confirm_password'];

        if (password_verify($current_pass, $admin['password'])) {
            if ($new_pass === $confirm_pass && strlen($new_pass) >= 6) {
                $hashed_pass = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashed_pass, $admin_id);
                $stmt->execute();
                
                log_admin_activity($conn, $admin_id, "Password Changed", "Admin changed their password.");
                $_SESSION['success'] = "Password changed successfully.";
                header('Location: profile.php');
                exit;
            } else {
                $_SESSION['error'] = "New passwords do not match or are less than 6 characters.";
            }
        } else {
            $_SESSION['error'] = "Incorrect current password.";
        }
    }
}

$page_title = "Admin Profile";
$page_heading = "My Profile";
include __DIR__ . '/partials/header.php';
?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 text-center">
            <div class="card-body p-5">
                <img src="<?php echo BASE_URL . '/assets/images/' . ($admin['profile_pic'] ?: 'default-user.png'); ?>" 
                     onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($admin['first_name'].' '.$admin['last_name']); ?>'"
                     class="rounded-circle mb-3 object-fit-cover shadow-sm" width="120" height="120">
                <h4 class="fw-bold mb-1"><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></h4>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($admin['email']); ?></p>
                <div class="badge bg-primary rounded-pill px-3 py-2 mt-3">Super Admin</div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Update Profile</h5>
            </div>
            <div class="card-body p-4">
                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">First Name</label>
                            <input type="text" name="first_name" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($admin['first_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Last Name</label>
                            <input type="text" name="last_name" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($admin['last_name']); ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($admin['email']); ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">Profile Picture</label>
                        <input type="file" name="profile_pic" class="form-control bg-light border-0" accept="image/*">
                    </div>
                    <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold">Save Changes</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">Change Password</h5>
            </div>
            <div class="card-body p-4">
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="update_password">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Current Password</label>
                        <input type="password" name="current_password" class="form-control bg-light border-0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">New Password</label>
                        <input type="password" name="new_password" class="form-control bg-light border-0" minlength="6" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-bold">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control bg-light border-0" minlength="6" required>
                    </div>
                    <button type="submit" class="btn btn-warning rounded-pill px-4 fw-bold">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
