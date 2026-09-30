<?php
/**
 * Admin Site Settings
 * Phase 3.3 + Phase 8: Payment Account Details
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');
$page_title = "Global Settings";
$page_heading = "System Settings";

$admin_id = $_SESSION['user_id'];

// Handle Payment Account Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_payment_accounts') {
    verify_csrf();
    $conn->begin_transaction();
    try {
        $pay_fields = [
            'pay_jazzcash_name', 'pay_jazzcash_number',
            'pay_easypaisa_name', 'pay_easypaisa_number',
            'pay_bank_name', 'pay_bank_title', 'pay_bank_account', 'pay_bank_iban'
        ];
        $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($pay_fields as $field) {
            $val = sanitize_input($_POST[$field] ?? '');
            $stmt->bind_param("ss", $field, $val);
            $stmt->execute();
        }
        log_admin_activity($conn, $admin_id, "Updated Payment Accounts", "Updated admin payment account details.");
        $conn->commit();
        $_SESSION['success'] = "Payment account details updated successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Failed: " . $e->getMessage();
    }
    header('Location: settings.php#payment-accounts');
    exit;
}

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_settings') {
    verify_csrf();
    $conn->begin_transaction();
    try {
        $updates = [
            'site_name'             => sanitize_input($_POST['site_name']),
            'contact_email'         => sanitize_input($_POST['contact_email']),
            'support_phone'         => sanitize_input($_POST['support_phone']),
            'currency'              => sanitize_input($_POST['currency']),
            'commission_percentage' => (float)$_POST['commission_percentage'],
            'maintenance_mode'      => isset($_POST['maintenance_mode']) ? '1' : '0'
        ];

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

        $stmt = $conn->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($updates as $key => $val) {
            $v = (string)$val;
            $stmt->bind_param("ss", $key, $v);
            $stmt->execute();
        }

        log_admin_activity($conn, $admin_id, "Updated Settings", "Updated global site settings.");
        $conn->commit();
        $_SESSION['success'] = "Settings have been updated successfully.";
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

include __DIR__ . '/partials/header.php';
?>

<div class="container-fluid py-4 px-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold mb-0 text-dark"><i class="bi bi-gear text-primary me-2"></i>System Settings</h3>
    </div>

    <!-- General Settings -->
    <div class="row g-4 mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-sliders me-2 text-primary"></i>General Settings</h5>
                </div>
                <div class="card-body p-4">
                    <form action="settings.php" method="POST" enctype="multipart/form-data">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="update_settings">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Website Name <span class="text-danger">*</span></label>
                                <input type="text" name="site_name" class="form-control rounded-3 border-0 bg-light" value="<?php echo htmlspecialchars($site_settings['site_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Currency Symbol <span class="text-danger">*</span></label>
                                <input type="text" name="currency" class="form-control rounded-3 border-0 bg-light" value="<?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs'); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Contact Email</label>
                                <input type="email" name="contact_email" class="form-control rounded-3 border-0 bg-light" value="<?php echo htmlspecialchars($site_settings['contact_email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Support Phone</label>
                                <input type="text" name="support_phone" class="form-control rounded-3 border-0 bg-light" value="<?php echo htmlspecialchars($site_settings['support_phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Marketplace Commission (%) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="number" step="0.01" min="0" max="100" name="commission_percentage" class="form-control rounded-start-3 border-0 bg-light" value="<?php echo htmlspecialchars($site_settings['commission_percentage'] ?? '0'); ?>" required>
                                    <span class="input-group-text border-0 bg-light">%</span>
                                </div>
                                <small class="text-muted">Percentage deducted from seller payouts.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Maintenance Mode</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance_mode" value="1" <?php echo !empty($site_settings['maintenance_mode']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="maintenance_mode">Enable Maintenance Mode</label>
                                </div>
                                <small class="text-muted">If enabled, visitors see a maintenance page.</small>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h6 class="fw-bold mb-3 text-dark">Branding Assets</h6>

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Site Logo</label>
                                <div class="mb-2 p-2 bg-dark d-inline-block rounded">
                                    <img src="<?php echo BASE_URL . '/assets/images/' . ($site_settings['site_logo'] ?? 'logo.png'); ?>" alt="Logo" height="40" onerror="this.style.display='none'">
                                </div>
                                <input type="file" name="logo" class="form-control rounded-3 border-0 bg-light" accept="image/*">
                                <small class="text-muted">Leave empty to keep current logo.</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small text-muted text-uppercase">Favicon</label>
                                <div class="mb-2">
                                    <img src="<?php echo BASE_URL . '/assets/images/' . ($site_settings['site_favicon'] ?? 'favicon.ico'); ?>" alt="Favicon" height="32" class="rounded border p-1" onerror="this.style.display='none'">
                                </div>
                                <input type="file" name="favicon" class="form-control rounded-3 border-0 bg-light" accept="image/x-icon,image/png">
                                <small class="text-muted">Recommended: 32×32px.</small>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
                                <i class="bi bi-check-lg me-2"></i>Save Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- System Info -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white h-100">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center mb-4">
                        <i class="bi bi-server fs-2 me-3 opacity-75"></i>
                        <h5 class="fw-bold mb-0">System Info</h5>
                    </div>
                    <ul class="list-unstyled mb-0">
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                            <small class="text-white-50 d-block">PHP Version</small>
                            <strong><?php echo phpversion(); ?></strong>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                            <small class="text-white-50 d-block">Server Software</small>
                            <strong><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'); ?></strong>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                            <small class="text-white-50 d-block">Upload Max Size</small>
                            <strong><?php echo ini_get('upload_max_filesize'); ?></strong>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25">
                            <small class="text-white-50 d-block">Post Max Size</small>
                            <strong><?php echo ini_get('post_max_size'); ?></strong>
                        </li>
                        <li>
                            <small class="text-white-50 d-block">Max Execution Time</small>
                            <strong><?php echo ini_get('max_execution_time'); ?>s</strong>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment Account Details -->
    <div class="row g-4" id="payment-accounts">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-1"><i class="bi bi-credit-card-2-back me-2 text-success"></i>Admin Payment Receiving Accounts</h5>
                    <p class="text-muted small mb-3">These accounts are shown to buyers after they place a buy request. Update them to receive payments.</p>
                </div>
                <div class="card-body p-4">
                    <form action="settings.php" method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="update_payment_accounts">

                        <!-- JazzCash -->
                        <div class="p-3 bg-light rounded-3 mb-4">
                            <h6 class="fw-bold text-success mb-3"><i class="bi bi-phone-fill me-2"></i>JazzCash</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Account Name</label>
                                    <input type="text" name="pay_jazzcash_name" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_jazzcash_name'] ?? ''); ?>" placeholder="e.g. PetMarket Admin">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Mobile Number</label>
                                    <input type="text" name="pay_jazzcash_number" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_jazzcash_number'] ?? ''); ?>" placeholder="e.g. 03001234567">
                                </div>
                            </div>
                        </div>

                        <!-- EasyPaisa -->
                        <div class="p-3 bg-light rounded-3 mb-4">
                            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-phone-fill me-2"></i>EasyPaisa</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Account Name</label>
                                    <input type="text" name="pay_easypaisa_name" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_easypaisa_name'] ?? ''); ?>" placeholder="e.g. PetMarket Admin">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Mobile Number</label>
                                    <input type="text" name="pay_easypaisa_number" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_easypaisa_number'] ?? ''); ?>" placeholder="e.g. 03111234567">
                                </div>
                            </div>
                        </div>

                        <!-- Bank Transfer -->
                        <div class="p-3 bg-light rounded-3 mb-4">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-bank2 me-2"></i>Bank Transfer</h6>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Bank Name</label>
                                    <input type="text" name="pay_bank_name" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_bank_name'] ?? ''); ?>" placeholder="e.g. Meezan Bank">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Account Title</label>
                                    <input type="text" name="pay_bank_title" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_bank_title'] ?? ''); ?>" placeholder="e.g. PetMarket Pvt Ltd">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">Account Number</label>
                                    <input type="text" name="pay_bank_account" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_bank_account'] ?? ''); ?>" placeholder="e.g. 01230123456789">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold small">IBAN <span class="text-muted">(optional)</span></label>
                                    <input type="text" name="pay_bank_iban" class="form-control rounded-3 border-0 bg-white" value="<?php echo htmlspecialchars($site_settings['pay_bank_iban'] ?? ''); ?>" placeholder="e.g. PK36MEZN0001230123456789">
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow-sm">
                                <i class="bi bi-check-lg me-2"></i>Save Payment Accounts
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- How It Works -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-4"><i class="bi bi-question-circle-fill me-2"></i>How It Works</h5>
                    <ol class="list-unstyled mb-0">
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25 d-flex align-items-start">
                            <span class="badge bg-white text-success rounded-circle me-3 fw-bold" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</span>
                            <span>Buyer places a buy request</span>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25 d-flex align-items-start">
                            <span class="badge bg-white text-success rounded-circle me-3 fw-bold" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</span>
                            <span>Buyer sees <strong>these account details</strong></span>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25 d-flex align-items-start">
                            <span class="badge bg-white text-success rounded-circle me-3 fw-bold" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</span>
                            <span>Buyer sends money to Admin directly</span>
                        </li>
                        <li class="mb-3 pb-3 border-bottom border-white border-opacity-25 d-flex align-items-start">
                            <span class="badge bg-white text-success rounded-circle me-3 fw-bold" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">4</span>
                            <span>Buyer uploads payment screenshot</span>
                        </li>
                        <li class="d-flex align-items-start">
                            <span class="badge bg-white text-success rounded-circle me-3 fw-bold" style="width:28px;height:28px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">5</span>
                            <span>Admin verifies &amp; activates the order</span>
                        </li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

</div><!-- /container-fluid -->

<?php include __DIR__ . '/partials/footer.php'; ?>
