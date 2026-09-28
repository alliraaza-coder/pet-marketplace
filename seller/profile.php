<?php
/**
 * Seller Profile
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require seller role
require_role('seller');

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

<div class="container-fluid py-4 px-4">
    <div class="row g-4">
        <!-- ─── Sidebar ────────────────────────────────────────── -->
        <div class="col-lg-2 mb-4 mb-lg-0">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- ─── Main Content ────────────────────────────────────── -->
        <div class="col-lg-10">
            <?php display_messages(); ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-person-gear text-primary me-2"></i>My Profile
                    </h3>
                    <p class="text-muted mb-0 small">Update your personal and contact information.</p>
                </div>
            </div>
            
            <div class="card border-0 shadow-sm rounded-4">
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
                        
                        <h5 class="fw-bold mt-4 mb-3 border-bottom pb-2">Business / Contact Address</h5>
                        
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
                            <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm"><i class="bi bi-save me-2"></i>Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
