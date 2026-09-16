<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

redirect_if_logged_in();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = sanitize_input($_POST['first_name']);
    $last_name = sanitize_input($_POST['last_name']);
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $phone = sanitize_input($_POST['phone']);
    $address = sanitize_input($_POST['address']);
    $city = sanitize_input($_POST['city']);

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password) || empty($phone) || empty($address) || empty($city)) {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = "Email is already registered.";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $role = 'seller';
            $status = 'active'; // In production, might require admin approval

            $stmt = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, phone, address, city, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssss", $first_name, $last_name, $email, $hashed_password, $phone, $address, $city, $role, $status);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Seller Registration successful! Welcome to our marketplace.";
                header('Location: login.php');
                exit;
            } else {
                $error = "Registration failed. Please try again later.";
            }
        }
    }
}

include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-8">
            <div class="card border-0 shadow-lg rounded-4" style="background: linear-gradient(135deg, var(--light-surface) 0%, rgba(212, 175, 55, 0.05) 100%);">
                <div class="card-body p-5">
                    <div class="text-center mb-5">
                        <span class="badge bg-warning text-dark mb-2 px-3 py-2 rounded-pill">Seller Portal</span>
                        <h2 class="fw-bold">Become a Seller</h2>
                        <p class="text-muted">Reach thousands of buyers and grow your pet business today.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form action="seller_register.php" method="POST">
                        <h5 class="fw-bold mb-3 border-bottom pb-2">Personal Information</h5>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo isset($_POST['first_name']) ? $_POST['first_name'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label for="last_name" class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo isset($_POST['last_name']) ? $_POST['last_name'] : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="email" class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo isset($_POST['email']) ? $_POST['email'] : ''; ?>">
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label for="phone" class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" id="phone" name="phone" required value="<?php echo isset($_POST['phone']) ? $_POST['phone'] : ''; ?>">
                            </div>
                        </div>

                        <h5 class="fw-bold mb-3 border-bottom pb-2">Business Address</h5>
                        <div class="mb-3">
                            <label for="address" class="form-label fw-medium">Full Address (Shop/Home) <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="address" name="address" required value="<?php echo isset($_POST['address']) ? $_POST['address'] : ''; ?>">
                        </div>
                        <div class="mb-4">
                            <label for="city" class="form-label fw-medium">City <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="city" name="city" required value="<?php echo isset($_POST['city']) ? $_POST['city'] : ''; ?>">
                        </div>

                        <h5 class="fw-bold mb-3 border-bottom pb-2">Security</h5>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <label for="password" class="form-label fw-medium">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" required minlength="8">
                            </div>
                            <div class="col-md-6 mt-3 mt-md-0">
                                <label for="confirm_password" class="form-label fw-medium">Confirm Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                            </div>
                        </div>
                        
                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input text-warning" id="terms" required>
                            <label class="form-check-label text-muted small" for="terms">
                                I agree to the <a href="terms.php" class="text-warning text-decoration-none fw-bold">Seller Terms & Conditions</a>.
                            </label>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg rounded-pill fw-bold shadow">Register as Seller</button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4 pt-3 border-top">
                        <p class="mb-0 text-muted">Already a seller? <a href="login.php" class="text-warning fw-bold text-decoration-none">Login here</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
