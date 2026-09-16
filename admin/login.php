<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

redirect_if_logged_in();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize_input($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error = "Both email and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, password, role, first_name, last_name, force_password_change, is_deleted FROM users WHERE email = ? AND role = 'admin'");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (!empty($user['is_deleted'])) {
                $error = "Your account has been deactivated.";
            } elseif (password_verify($password, $user['password'])) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['user_name'] = $user['first_name'];
                
                log_admin_activity($conn, $user['id'], 'Admin Login', 'Logged in successfully.');

                if (!empty($user['force_password_change'])) {
                    $_SESSION['warning'] = "Please change your default admin password before accessing the panel.";
                    header('Location: change_password.php');
                    exit;
                }

                header('Location: dashboard.php');
                exit;
            } else {
                $error = "Invalid admin credentials.";
            }
        } else {
            $error = "Invalid admin credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - <?php echo htmlspecialchars($site_settings['site_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #121212;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            font-family: 'Inter', sans-serif;
        }
        .admin-card {
            background-color: #1E1E1E;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.05);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card admin-card border-0">
                <div class="card-body p-5">
                    <div class="text-center mb-4">
                        <i class="bi bi-shield-lock-fill fs-1 text-danger"></i>
                        <h3 class="fw-bold mt-2">Admin Portal</h3>
                        <p class="text-muted small">Restricted Access Only</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger bg-danger text-white border-0"><i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?></div>
                    <?php endif; ?>
                    <?php display_messages(); ?>

                    <form action="login.php" method="POST">
                        <div class="mb-3">
                            <label for="email" class="form-label text-muted">Admin Email</label>
                            <input type="email" class="form-control bg-dark text-white border-secondary" id="email" name="email" required value="admin@petmarket.com">
                        </div>
                        <div class="mb-4">
                            <label for="password" class="form-label text-muted">Password</label>
                            <input type="password" class="form-control bg-dark text-white border-secondary" id="password" name="password" required>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-danger fw-bold py-2">Secure Login</button>
                        </div>
                    </form>
                    
                    <div class="text-center mt-4">
                        <a href="../index.php" class="text-muted text-decoration-none small"><i class="bi bi-arrow-left me-1"></i> Back to Website</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
