<?php
/**
 * Main Database Configuration File
 * Pet Marketplace E-Commerce Platform
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load environment variables from .env file
$env_file = __DIR__ . '/../.env';
$env = [];
if (file_exists($env_file)) {
    $env = parse_ini_file($env_file);
}

// Environment Config
define('APP_ENV', $env['APP_ENV'] ?? 'production');
define('APP_DEBUG', filter_var($env['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));

// Error Reporting
if (APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL); // Log all errors
    mysqli_report(MYSQLI_REPORT_OFF); // Disable mysqli exceptions revealing credentials
}

// Database Credentials
define('DB_HOST', $env['DB_HOST'] ?? 'localhost');
define('DB_USER', $env['DB_USER'] ?? 'root');
define('DB_PASS', $env['DB_PASS'] ?? '');
define('DB_NAME', $env['DB_NAME'] ?? 'pet_marketplace');

try {
    // Create connection
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Set charset to utf8mb4 for full unicode support
    $conn->set_charset("utf8mb4");
} catch (Exception $e) {
    // Log the error to a file in production, don't display it directly to users
    error_log("Database connection error: " . $e->getMessage());
    if (APP_DEBUG) {
        die("Database connection failed: " . $e->getMessage());
    } else {
        http_response_code(500);
        die("Database connection failed. Please try again later.");
    }
}

// Base URL Configuration
define('BASE_URL', rtrim($env['APP_URL'] ?? 'http://localhost/pet_marketplace', '/'));

// Global Settings Configuration
$site_settings = [
    'site_name' => 'PetMarket',
    'support_email' => 'support@petmarket.com',
    'phone' => '+1 (555) 123-4567',
    'currency' => '$',
    'commission_percentage' => '5.00',
    'maintenance_mode' => '0',
    'logo' => 'logo.png',
    'favicon' => 'favicon.ico'
];

try {
    $db_settings_res = $conn->query("SELECT setting_key, setting_value FROM site_settings");
    if ($db_settings_res) {
        while ($row = $db_settings_res->fetch_assoc()) {
            $site_settings[$row['setting_key']] = $row['setting_value'];
        }
    }
} catch (Exception $e) {
    // Table may not exist yet during setup
}

/**
 * Log admin activities for audit trail
 */
function log_admin_activity($conn, $admin_id, $action, $details = '') {
    if (!$admin_id && isset($_SESSION['user_id'])) {
        $admin_id = $_SESSION['user_id'];
    }
    if (!$admin_id) return false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $stmt = $conn->prepare("INSERT INTO admin_activity_logs (admin_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isss", $admin_id, $action, $details, $ip);
        $res = $stmt->execute();
        $stmt->close();
        return $res;
    }
    return false;
}

/**
 * Helper function to sanitize user input to prevent XSS
 */
function sanitize_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

/**
 * Language selection logic
 */
if(isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'ur'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

$current_lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';

// Define basic translation dictionary for UI elements
$lang = [];
if($current_lang === 'ur') {
    $lang = [
        'home' => 'ہوم',
        'shop' => 'شاپ',
        'market' => 'مارکیٹ',
        'about' => 'ہمارے بارے میں',
        'contact' => 'رابطہ کریں',
        'search' => 'تلاش کریں...',
        'login' => 'لاگ ان',
        'register' => 'رجسٹر',
        'categories' => 'اقسام',
        'featured_birds' => 'نمایاں پرندے'
    ];
} else {
    $lang = [
        'home' => 'Home',
        'shop' => 'Shop',
        'market' => 'Bird Market',
        'about' => 'About Us',
        'contact' => 'Contact',
        'search' => 'Search products, birds, animals...',
        'login' => 'Login',
        'register' => 'Register',
        'categories' => 'Categories',
        'featured_birds' => 'Featured Birds'
    ];
}

/**
 * Helper function for translation
 */
function __($key) {
    global $lang;
    return isset($lang[$key]) ? $lang[$key] : $key;
}

/**
 * Helper function to get the primary image URL of a product, or placeholder SVG
 */
function get_product_image_url($conn, $product_id) {
    $stmt = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ? AND is_primary = 1 LIMIT 1");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    if ($res && !empty($res['image_url'])) {
        $file_path = __DIR__ . '/../assets/uploads/products/' . $res['image_url'];
        if (file_exists($file_path)) {
            return BASE_URL . '/assets/uploads/products/' . $res['image_url'];
        }
    }
    // Fallback: Check if there's any image for the product
    $stmt2 = $conn->prepare("SELECT image_url FROM product_images WHERE product_id = ? LIMIT 1");
    $stmt2->bind_param("i", $product_id);
    $stmt2->execute();
    $res2 = $stmt2->get_result()->fetch_assoc();
    if ($res2 && !empty($res2['image_url'])) {
        $file_path2 = __DIR__ . '/../assets/uploads/products/' . $res2['image_url'];
        if (file_exists($file_path2)) {
            return BASE_URL . '/assets/uploads/products/' . $res2['image_url'];
        }
    }
    // Return a clean inline SVG placeholder
    return 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 100 100"><rect width="100" height="100" fill="%23f3f3f3"/><text x="50" y="50" font-family="sans-serif" font-size="8" fill="%23aaa" text-anchor="middle" dy=".3em">No Image</text></svg>';
}
/**
 * Log order actions for audit trail
 */
function log_order_audit($conn, $order_id, $action, $details = '', $user_id = null) {
    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    if ($user_id === null) return false;
    
    $stmt = $conn->prepare("INSERT INTO order_audits (order_id, action, details, user_id) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("issi", $order_id, $action, $details, $user_id);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    return false;
}
?>
