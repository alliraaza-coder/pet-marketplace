<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// If not logged in, redirect to seller registration or login
if (!is_logged_in()) {
    $_SESSION['error'] = "You must be logged in to become a seller. Please login or register.";
    header('Location: login.php?redirect=become_seller.php');
    exit;
}

$user = current_user($conn);

// If already a seller (or admin), redirect to seller dashboard
if ($user['role'] === 'seller' || $user['role'] === 'admin') {
    $_SESSION['info'] = "You are already a seller.";
    header('Location: seller/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process upgrade
    $phone   = isset($_POST['phone']) ? sanitize_input($_POST['phone']) : '';
    $address = isset($_POST['address']) ? sanitize_input($_POST['address']) : '';
    $city    = isset($_POST['city']) ? sanitize_input($_POST['city']) : '';

    if (empty($phone) || empty($address) || empty($city)) {
        $error = "Please provide your phone number, business address, and city.";
    } else {
        $stmt = $conn->prepare("UPDATE users SET role = 'seller', phone = ?, address = ?, city = ? WHERE id = ?");
        $stmt->bind_param("sssi", $phone, $address, $city, $user['id']);
        
        if ($stmt->execute()) {
            $_SESSION['user_role'] = 'seller';
            $_SESSION['success'] = "Congratulations! Your account has been upgraded to Seller.";
            header('Location: seller/dashboard.php');
            exit;
        } else {
            $error = "An error occurred while upgrading your account. Please try again.";
        }
    }
}

$page_title = "Become a Seller - " . $site_settings['site_name'];
include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card border-0 shadow-lg rounded-4" style="background: linear-gradient(135deg, var(--light-surface) 0%, rgba(212, 175, 55, 0.05) 100%);">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <span class="badge bg-warning text-dark mb-2 px-3 py-2 rounded-pill">Seller Upgrade</span>
                        <h2 class="fw-bold">Become a Seller</h2>
                        <p class="text-muted">You're just one step away from selling on our marketplace.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form action="become_seller.php" method="POST">
                        <div class="mb-3">
                            <label for="phone" class="form-label fw-medium">Business Phone Number <span class="text-danger">*</span></label>
                            <input type="tel" class="form-control" id="phone" name="phone" required value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label fw-medium">Business Address <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="address" name="address" required value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                        </div>

                        <div class="mb-4">
                            <label for="city" class="form-label fw-medium">City <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="city" name="city" required value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input text-warning" id="terms" required>
                            <label class="form-check-label text-muted small" for="terms">
                                I agree to the <a href="terms.php" class="text-warning text-decoration-none fw-bold">Seller Terms & Conditions</a>.
                            </label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg rounded-pill fw-bold shadow">Upgrade My Account</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
