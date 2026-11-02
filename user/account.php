<?php
/**
 * Buyer Payment / Refund Account Details
 * Buyers can save & update their accounts (JazzCash, EasyPaisa, Bank Account)
 * These details are shown to Admin for issuing refunds or escrow dispute returns.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('user');

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
        $_SESSION['success'] = "Your refund and payment account details have been saved successfully!";
    } else {
        $_SESSION['error'] = "Failed to save account details. Please try again.";
    }
    $stmt->close();

    header("Location: account.php");
    exit;
}

$account = get_user_payment_account($conn, $user_id);

$page_title = "Payment & Refund Accounts - PetMarket";
include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($user['profile_pic'] ?? 'default-user.png'); ?>" 
                         onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" 
                         alt="Profile" 
                         class="rounded-circle mb-3 border border-3 border-success" 
                         style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                    <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="account.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-credit-card-2-back me-2"></i> Payment Account</a>
                    <a href="refunds.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-arrow-return-left me-2"></i> My Refunds</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <?php
                        $buyer_notif_stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0");
                        $buyer_notif_stmt->bind_param("i", $user_id);
                        $buyer_notif_stmt->execute();
                        $buyer_unread = $buyer_notif_stmt->get_result()->fetch_assoc()['c'];
                    ?>
                    <a href="../notifications.php" class="list-group-item list-group-item-action py-3 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-bell me-2"></i> Notifications</span>
                        <?php if ($buyer_unread > 0): ?><span class="badge bg-danger rounded-pill"><?php echo $buyer_unread; ?></span><?php endif; ?>
                    </a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>

            <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-credit-card-2-back text-success me-2"></i>Payment & Refund Accounts
                    </h3>
                    <p class="text-muted mb-0 small">
                        Save your bank and mobile wallet details so Admin can quickly issue refunds or dispute returns directly to your account.
                    </p>
                </div>
            </div>

            <div class="alert alert-success bg-opacity-10 border-success rounded-4 shadow-sm mb-4 d-flex align-items-center gap-3">
                <i class="bi bi-shield-check fs-2 text-success flex-shrink-0"></i>
                <div class="small">
                    <strong>Auto-Sync with Admin:</strong> Whenever you change your account numbers here, your profile in the Admin Panel and all escrow refund screens will automatically show your latest account numbers.
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
                        <p class="text-muted small mb-0">For receiving refunds via JazzCash mobile account.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Title / Receiver Name</label>
                                <input type="text" name="jazzcash_name" class="form-control rounded-3" 
                                       placeholder="e.g. Muhammad Ahmad"
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
                        <p class="text-muted small mb-0">For receiving refunds via EasyPaisa mobile account.</p>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Account Title / Receiver Name</label>
                                <input type="text" name="easypaisa_name" class="form-control rounded-3" 
                                       placeholder="e.g. Muhammad Ahmad"
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
                        <p class="text-muted small mb-0">For receiving refunds directly into your Pakistani bank account.</p>
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
                                       placeholder="e.g. Muhammad Ahmad"
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
                    <button type="submit" class="btn btn-success rounded-pill px-5 py-2 fw-bold shadow-sm">
                        <i class="bi bi-check-circle me-2"></i>Save Account Details
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
