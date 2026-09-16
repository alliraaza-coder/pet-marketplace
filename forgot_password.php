<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

redirect_if_logged_in();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize_input($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows > 0) {
            // In a real application, you would generate a token and send an email here.
            // For this demo, we will just show a success message.
            $success = "If your email is registered, you will receive a password reset link shortly.";
        } else {
            // Don't reveal if email exists or not for security
            $success = "If your email is registered, you will receive a password reset link shortly.";
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
                        <i class="bi bi-key fs-1 text-success"></i>
                        <h2 class="fw-bold mt-2">Forgot Password</h2>
                        <p class="text-muted">Enter your email to receive a reset link</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?></div>
                    <?php else: ?>
                    
                    <form action="forgot_password.php" method="POST">
                        <div class="mb-4">
                            <label for="email" class="form-label fw-medium">Email Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="bi bi-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required placeholder="name@example.com">
                            </div>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold">Send Reset Link</button>
                        </div>
                    </form>
                    <?php endif; ?>
                    
                    <div class="text-center mt-4 pt-3 border-top">
                        <a href="login.php" class="text-success text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Back to Login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
