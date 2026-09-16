<?php
/**
 * Authentication Helper Functions
 */

// Ensure session is started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

/**
 * Require login to access a page
 */
function require_login() {
    if (!is_logged_in()) {
        $_SESSION['error'] = "Please login to access this page.";
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

/**
 * Get current user role
 */
function get_user_role() {
    return isset($_SESSION['user_role']) ? $_SESSION['user_role'] : null;
}

/**
 * Require specific role to access a page
 */
function require_role($role) {
    require_login();
    if (get_user_role() !== $role) {
        $_SESSION['error'] = "Unauthorized access. You do not have permission to view this page.";
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

/**
 * Redirect logged in users away from auth pages (login/register)
 */
function redirect_if_logged_in() {
    if (is_logged_in()) {
        $role = get_user_role();
        if ($role === 'admin') {
            header('Location: ' . BASE_URL . '/admin/dashboard.php');
        } elseif ($role === 'seller') {
            header('Location: ' . BASE_URL . '/seller/dashboard.php');
        } else {
            header('Location: ' . BASE_URL . '/user/dashboard.php');
        }
        exit;
    }
}

/**
 * Get current user data
 */
function current_user($conn) {
    if (!is_logged_in()) return null;
    $stmt = $conn->prepare("SELECT id, first_name, last_name, email, phone, role, profile_pic, address, city, state, country, zip_code FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

/**
 * Helper to display session messages
 */
function display_messages() {
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>' . $_SESSION['success'] . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>' . $_SESSION['error'] . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>';
        unset($_SESSION['error']);
    }
}
?>
