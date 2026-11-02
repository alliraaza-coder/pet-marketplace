<?php
/**
 * Seller Payout Account Details
 * Sellers can save & update their payout receiving details (JazzCash, EasyPaisa, Bank Account)
 * These details are shown to Admin for payout disbursement and automatically sync everywhere.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user    = current_user($conn);
$user_id = $user['id'];

// Handle form submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verify_csrf();

    $jazzcash_name       = sanitize_input($_POST['jazzcash_name'] ?? '');
    $jazzcash_number     = sanitize_input($_POST['jazzcash_number'] ?? '');
    $easypaisa_name      = sanitize_input($_POST['easypaisa_name'] ?? '');
    $easypaisa_number    = sanitize_input($_POST['easypaisa_number'] ?? '');
    $bank_name           = sanitize_input($_POST['bank_name'] ?? '');
    $bank_account_title  = sanitize_input($_POST['bank_account_title'] ?? '');
    $bank_account_number = sanitize_input($_POST['bank_account_number'] ?? '');
    $bank_iban           = sanitize_input($_POST['bank_iban'] ?? '');

    $save_sql = "INSERT INTO user_payment_accounts 
                    (user_id, jazzcash_name, jazzcash_number, easypaisa_name, easypaisa_number, bank_name, bank_account_title, bank_account_number, bank_iban) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE 
                    jazzcash_name = VALUES(jazzcash_name),
                    jazzcash_number = VALUES(jazzcash_number),
                    easypaisa_name = VALUES(easypaisa_name),
                    easypaisa_number = VALUES(easypaisa_number),
                    bank_name = VALUES(bank_name),
                    bank_account_title = VALUES(bank_account_title),
                    bank_account_number = VALUES(bank_account_number),
                    bank_iban = VALUES(bank_iban),
                    updated_at = NOW()";

    $stmt = $conn->prepare($save_sql);
    $stmt->bind_param("issssssss", $user_id, $jazzcash_name, $jazzcash_number, $easypaisa_name, $easypaisa_number, $bank_name, $bank_account_title, $bank_account_number, $bank_iban);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Your payout account details have been saved successfully!";
    } else {
        $_SESSION['error'] = "Failed to save account details. Please try again.";
    }
    $stmt->close();

    header("Location: account.php");
    exit;
}

$account = get_user_payment_account($conn, $user_id);

$page_title = "Payout Accounts - Seller Dashboard";
include '../includes/header.php';
?>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">
        <!-- Sidebar -->
        <div class="col-lg-2 mb-4 mb-lg-0">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- Main Content -->
        <div class="col-lg-10">
            <?php display_messages(); ?>

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-credit-card-2-back text-warning me-2"></i>Payout Account Details
                    </h3>
                    <p class="text-muted mb-0 small">
                        Save your bank and mobile wallet details so Admin can disburse your escrow payouts directly.
                    </p>
                </div>
            </div>

            <div class="alert alert-info border-0 rounded-4 shadow-sm mb-4 d-flex align-items-center gap-3">
                <i class="bi bi-shield-check fs-2 text-info flex-shrink-0"></i>
                <div class="small">
                    <strong>Secure & Dynamic Synchronization:</strong> Whenever you change your account numbers here, your profile in the Admin Panel and all escrow payout screens will automatically display your newest account details.
                </div>
            </div>

            <form action="" method="POST">
                <?php csrf_field(); ?>

                <!-- JazzCash Wallet -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                        <h5 class="fw-bold mb-1 text-danger">
                            <i class="bi bi-phone me-2"></i>JazzCash Account
                        </h5>
                        <p class="text-muted small mb-0">For payouts sent via JazzCash mobile account.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Title / Receiver Name</label>
                                <input type="text" name="jazzcash_name" class="form-control rounded-3" 
                                       placeholder="e.g. Ali Raza"
                                       value="<?php echo htmlspecialchars($account['jazzcash_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">JazzCash Mobile Number</label>
                                <input type="text" name="jazzcash_number" class="form-control rounded-3" 
                                       placeholder="e.g. 03001234567"
                                       value="<?php echo htmlspecialchars($account['jazzcash_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EasyPaisa Wallet -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                        <h5 class="fw-bold mb-1 text-success">
                            <i class="bi bi-wallet2 me-2"></i>EasyPaisa Account
                        </h5>
                        <p class="text-muted small mb-0">For payouts sent via EasyPaisa mobile account.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Title / Receiver Name</label>
                                <input type="text" name="easypaisa_name" class="form-control rounded-3" 
                                       placeholder="e.g. Ali Raza"
                                       value="<?php echo htmlspecialchars($account['easypaisa_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">EasyPaisa Mobile Number</label>
                                <input type="text" name="easypaisa_number" class="form-control rounded-3" 
                                       placeholder="e.g. 03451234567"
                                       value="<?php echo htmlspecialchars($account['easypaisa_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Bank Transfer Details -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                        <h5 class="fw-bold mb-1 text-dark">
                            <i class="bi bi-bank2 me-2 text-primary"></i>Bank Transfer Details
                        </h5>
                        <p class="text-muted small mb-0">For payouts transferred directly into your Pakistani bank account.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Bank Name</label>
                                <input type="text" name="bank_name" class="form-control rounded-3" 
                                       placeholder="e.g. Meezan Bank, HBL, Allied Bank"
                                       value="<?php echo htmlspecialchars($account['bank_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Title</label>
                                <input type="text" name="bank_account_title" class="form-control rounded-3" 
                                       placeholder="e.g. Ali Raza"
                                       value="<?php echo htmlspecialchars($account['bank_account_title'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Number</label>
                                <input type="text" name="bank_account_number" class="form-control rounded-3" 
                                       placeholder="e.g. 01230123456789"
                                       value="<?php echo htmlspecialchars($account['bank_account_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">IBAN (Optional)</label>
                                <input type="text" name="bank_iban" class="form-control rounded-3" 
                                       placeholder="e.g. PK36MEZN0001230123456789"
                                       value="<?php echo htmlspecialchars($account['bank_iban'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end mb-5">
                    <button type="submit" class="btn btn-warning rounded-pill px-5 py-2 fw-bold shadow-sm">
                        <i class="bi bi-check-circle me-2"></i>Save Account Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
