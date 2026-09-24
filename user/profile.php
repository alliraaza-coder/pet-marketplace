<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require user login and user role
require_role('user');

$user = current_user($conn);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $phone = sanitize_input($_POST['phone']);
    $address = sanitize_input($_POST['address']);
    $city = sanitize_input($_POST['city']);
    $state = sanitize_input($_POST['state']);
    $zip_code = sanitize_input($_POST['zip_code']);

    if (empty($first_name) || empty($last_name)) {
        $error = "First Name and Last Name are required.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, address=?, city=?, state=?, zip_code=? WHERE id=?");
        $stmt->bind_param("sssssssi", $first_name, $last_name, $phone, $address, $city, $state, $zip_code, $user['id']);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Profile updated successfully!";
            $_SESSION['user_name'] = $first_name; // update session name
            // refresh user data
            $user = current_user($conn);
        } else {
            $error = "Failed to update profile. Please try again.";
        }
    }
}

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $user['profile_pic']; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" alt="Profile Picture" class="rounded-circle mb-3 border border-3 border-success" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></h5>
                    <p class="text-muted small mb-3"><?php echo $user['email']; ?></p>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0">
                    <h4 class="fw-bold mb-0">My Profile</h4>
                </div>
                <div class="card-body p-4">
                    <form action="profile.php" method="POST">
                        <?php csrf_field(); ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($user['first_name']); ?>">
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label for="last_name" class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($user['last_name']); ?>">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-medium">Email Address (Cannot be changed)</label>
                                <input type="email" class="form-control bg-light" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label for="phone" class="form-label fw-medium">Phone Number</label>
                                <input type="tel" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <h5 class="fw-bold mt-4 mb-3 border-bottom pb-2">Shipping Address</h5>
                        
                        <div class="mb-3">
                            <label for="address" class="form-label fw-medium">Full Address</label>
                            <input type="text" class="form-control" id="address" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label for="city" class="form-label fw-medium">City</label>
                                <input type="text" class="form-control" id="city" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mt-3 mt-md-0">
                                <label for="state" class="form-label fw-medium">State / Province</label>
                                <input type="text" class="form-control" id="state" name="state" value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mt-3 mt-md-0">
                                <label for="zip_code" class="form-label fw-medium">Zip Code</label>
                                <input type="text" class="form-control" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($user['zip_code'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
