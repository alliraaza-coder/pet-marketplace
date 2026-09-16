<?php
/**
 * Admin Site Settings
 * Phase 3.3
 */
$page_title = "Global Settings";
$page_heading = "System Settings";
include __DIR__ . '/partials/header.php';

$admin_id = $_SESSION['user_id'];

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    $conn->begin_transaction();
    try {
        $updates = [
            'site_name' => sanitize_input($_POST['site_name']),
            'contact_email' => sanitize_input($_POST['contact_email']),
            'support_phone' => sanitize_input($_POST['support_phone']),
            'currency' => sanitize_input($_POST['currency']),
            'commission_percentage' => (float)$_POST['commission_percentage'],
            'maintenance_mode' => isset($_POST['maintenance_mode']) ? '1' : '0'
        ];

        // Handle File Uploads (Logo & Favicon)
        $upload_dir = '../assets/images/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $logo_name = 'logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_dir . $logo_name)) {
                $updates['site_logo'] = $logo_name;
            }
        }

        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION);
            $fav_name = 'favicon_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['favicon']['tmp_name'], $upload_dir . $fav_name)) {
                $updates['site_favicon'] = $fav_name;
            }
        }

        $stmt = $conn->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
        foreach ($updates as $key => $val) {
            $stmt->bind_param("ss", $val, $key);
            $stmt->execute();
        }

        log_admin_activity($conn, $admin_id, "Updated Settings", "Updated global site settings.");
        
        $conn->commit();
        $_SESSION['success'] = "Settings have been updated successfully.";
        
        // Refresh settings variable
        $site_settings = [];
        $res = $conn->query("SELECT setting_key, setting_value FROM site_settings");
        while ($row = $res->fetch_assoc()) $site_settings[$row['setting_key']] = $row['setting_value'];

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed to update settings: " . $e->getMessage();
    }
    header('Location: settings.php');
    exit;
}
?>

<div class="row">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                <h5 class="fw-bold mb-0">General Settings</h5>
            </div>
            <div class="card-body p-4">
                <form action="settings.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Website Name <span class="text-danger">*</span></label>
                            <input type="text" name="site_name" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($site_settings['site_name'] ?? ''); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Currency Symbol <span class="text-danger">*</span></label>
                            <input type="text" name="currency" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($site_settings['currency'] ?? '$'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Contact Email</label>
                            <input type="email" name="contact_email" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($site_settings['contact_email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Support Phone</label>
                            <input type="text" name="support_phone" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($site_settings['support_phone'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Marketplace Commission (%) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" step="0.01" name="commission_percentage" class="form-control bg-light border-0" value="<?php echo htmlspecialchars($site_settings['commission_percentage'] ?? '0'); ?>" required>
                                <span class="input-group-text bg-light border-0">%</span>
                            </div>
                            <small class="text-muted">Percentage taken from seller payouts.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Maintenance Mode</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" value="1" <?php echo !empty($site_settings['maintenance_mode']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="maintenance_mode">Enable Maintenance Mode</label>
                            </div>
                            <small class="text-muted">If enabled, users will see a maintenance page.</small>
                        </div>
                    </div>

                    <hr class="mb-4">
                    <h6 class="fw-bold mb-3">Branding Assets</h6>
                    
                    <div class="row g-4 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Site Logo</label>
                            <div class="mb-2">
                                <img src="<?php echo BASE_URL . '/assets/images/' . ($site_settings['site_logo'] ?? 'logo.png'); ?>" alt="Logo" height="50" class="bg-dark p-2 rounded">
                            </div>
                            <input type="file" name="logo" class="form-control bg-light border-0" accept="image/*">
                            <small class="text-muted">Leave empty to keep current logo.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Favicon</label>
                            <div class="mb-2">
                                <img src="<?php echo BASE_URL . '/assets/images/' . ($site_settings['site_favicon'] ?? 'favicon.ico'); ?>" alt="Favicon" height="32" class="rounded border p-1">
                            </div>
                            <input type="file" name="favicon" class="form-control bg-light border-0" accept="image/x-icon,image/png">
                            <small class="text-muted">Recommended size 32x32px. Leave empty to keep current.</small>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold">Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 bg-primary text-white">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-info-circle-fill fs-2 me-3"></i>
                    <h5 class="fw-bold mb-0">System Info</h5>
                </div>
                <ul class="list-unstyled mb-0">
                    <li class="mb-2 border-bottom border-light border-opacity-25 pb-2"><strong>PHP Version:</strong> <?php echo phpversion(); ?></li>
                    <li class="mb-2 border-bottom border-light border-opacity-25 pb-2"><strong>Server Software:</strong> <?php echo $_SERVER['SERVER_SOFTWARE']; ?></li>
                    <li class="mb-2 border-bottom border-light border-opacity-25 pb-2"><strong>Upload Max Size:</strong> <?php echo ini_get('upload_max_filesize'); ?></li>
                    <li class="mb-2 border-bottom border-light border-opacity-25 pb-2"><strong>Post Max Size:</strong> <?php echo ini_get('post_max_size'); ?></li>
                    <li><strong>Max Execution Time:</strong> <?php echo ini_get('max_execution_time'); ?>s</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
