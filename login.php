<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Redirect if already logged in
redirect_if_logged_in();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Both email and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, password, role, status, first_name FROM users WHERE email = ? AND role IN ('user', 'seller')");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if ($user['status'] === 'banned') {
                $error = "Your account has been banned. Please contact support.";
            } elseif ($user['status'] === 'inactive') {
                $error = "Your account is inactive. Please verify your email.";
            } else {
                if (password_verify($password, $user['password'])) {
                    // Regenerate session ID to prevent session fixation
                    secure_login($user['id'], $user['role'], $user['first_name']);
                    
                    $_SESSION['success'] = "Welcome back, " . htmlspecialchars($user['first_name']) . "!";
                    
                    // Redirect based on role
                    if ($user['role'] === 'seller') {
                        header('Location: seller/dashboard.php');
                    } else {
                        header('Location: user/dashboard.php');
                    }
                    exit;
                } else {
                    $error = "Invalid email or password.";
                }
            }
        } else {
            $error = "Invalid email or password.";
        }
    }
}

include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-lg rounded-4 glass-panel">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-circle fs-1 text-success"></i>
                        <h2 class="fw-bold mt-2">Welcome Back</h2>
                        <p class="text-muted">Login to your account to continue</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php display_messages(); ?>

                    <form action="login.php" method="POST">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label for="email" class="form-label fw-medium">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="name@example.com">
                            </div>
                        </div>
                        <div class="mb-4">
                            <div class="d-flex justify-content-between">
                                <label for="password" class="form-label fw-medium">Password</label>
                                <a href="forgot_password.php" class="text-success text-decoration-none small">Forgot Password?</a>
                            </div>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required placeholder="Enter your password">
                            </div>
                        </div>
                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input text-success" id="remember">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold">Login</button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4 pt-3 border-top">
                        <p class="mb-1 text-muted">Don't have an account?</p>
                        <a href="register.php" class="btn btn-outline-success rounded-pill px-4 me-2">Register as User</a>
                        <a href="become_seller.php" class="btn btn-outline-warning rounded-pill px-4">Become a Seller</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
