<?php
/**
 * Admin User Registration Approval
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id = $_SESSION['user_id'];
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$user_id) {
    $_SESSION['error'] = "Invalid user ID.";
    header('Location: dashboard.php');
    exit;
}

// Fetch user details
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$req_user = $stmt->get_result()->fetch_assoc();

if (!$req_user) {
    $_SESSION['error'] = "User not found.";
    header('Location: dashboard.php');
    exit;
}

// Mark admin notification as read if it exists
$read_stmt = $conn->prepare("UPDATE admin_notifications SET is_read = 1 WHERE link = ?");
$link = "user_approval.php?id=" . $user_id;
$read_stmt->bind_param("s", $link);
$read_stmt->execute();


// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $upd = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ?");
        $upd->bind_param("i", $user_id);
        if ($upd->execute()) {
            $_SESSION['success'] = "User registration approved successfully.";
            log_admin_activity($conn, $admin_id, "Approved User Registration", "User ID: $user_id");
            header("Location: user_approval.php?id=$user_id");
            exit;
        } else {
            $_SESSION['error'] = "Failed to approve user.";
        }
    } elseif ($action === 'reject') {
        $reason = sanitize_input($_POST['rejection_reason'] ?? 'No reason provided.');
        $upd = $conn->prepare("UPDATE users SET status = 'rejected', rejection_reason = ? WHERE id = ?");
        $upd->bind_param("si", $reason, $user_id);
        if ($upd->execute()) {
            $_SESSION['success'] = "User registration rejected.";
            log_admin_activity($conn, $admin_id, "Rejected User Registration", "User ID: $user_id. Reason: $reason");
            header("Location: user_approval.php?id=$user_id");
            exit;
        } else {
            $_SESSION['error'] = "Failed to reject user.";
        }
    }
}

$page_title = "User Registration Approval";
$page_heading = "Registration Details";
include __DIR__ . '/partials/header.php';
?>

<div class="container-fluid py-4 px-4">
    <div class="mb-4">
        <a href="dashboard.php" class="text-decoration-none text-muted"><i class="bi bi-arrow-left me-1"></i> Back to Dashboard</a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
                    <h5 class="fw-bold mb-0">Registration Request: #<?= $req_user['id'] ?></h5>
                </div>
                <div class="card-body p-4">
                    
                    <div class="row mb-4">
                        <div class="col-sm-4 text-muted fw-semibold">Role Requested:</div>
                        <div class="col-sm-8">
                            <span class="badge bg-primary px-3 py-2 rounded-pill fs-6">
                                <i class="bi <?= $req_user['role'] === 'seller' ? 'bi-shop' : 'bi-cart' ?> me-1"></i> 
                                <?= ucfirst($req_user['role']) ?>
                            </span>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-sm-4 text-muted fw-semibold">Status:</div>
                        <div class="col-sm-8">
                            <?php if ($req_user['status'] === 'pending'): ?>
                                <span class="badge bg-warning text-dark px-3 rounded-pill">Pending Approval</span>
                            <?php elseif ($req_user['status'] === 'active'): ?>
                                <span class="badge bg-success px-3 rounded-pill">Approved / Active</span>
                            <?php elseif ($req_user['status'] === 'rejected'): ?>
                                <span class="badge bg-danger px-3 rounded-pill">Rejected</span>
                            <?php else: ?>
                                <span class="badge bg-secondary px-3 rounded-pill"><?= ucfirst($req_user['status']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <hr class="text-muted opacity-25">

                    <h6 class="fw-bold text-dark mb-3">User Details</h6>
                    <div class="row mb-2">
                        <div class="col-sm-4 text-muted fw-semibold">Full Name:</div>
                        <div class="col-sm-8 fw-bold"><?= htmlspecialchars($req_user['first_name'] . ' ' . $req_user['last_name']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-4 text-muted fw-semibold">Email:</div>
                        <div class="col-sm-8"><?= htmlspecialchars($req_user['email']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-sm-4 text-muted fw-semibold">Phone:</div>
                        <div class="col-sm-8"><?= htmlspecialchars($req_user['phone'] ?? 'N/A') ?></div>
                    </div>
                    <div class="row mb-4">
                        <div class="col-sm-4 text-muted fw-semibold">Registered On:</div>
                        <div class="col-sm-8"><?= date('d M Y, h:i A', strtotime($req_user['created_at'])) ?></div>
                    </div>

                    <hr class="text-muted opacity-25">
                    <h6 class="fw-bold text-dark mb-3"><i class="bi bi-camera-fill me-2 text-primary"></i>KYC Identity Documents</h6>
                    <div class="row g-3 mb-4">
                        <!-- ID Front -->
                        <div class="col-md-4">
                            <p class="text-muted small fw-semibold mb-1 text-center">CNIC / Passport Front</p>
                            <?php if (!empty($req_user['id_card_front'])): ?>
                                <a href="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['id_card_front']) ?>" target="_blank">
                                    <img src="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['id_card_front']) ?>"
                                         class="img-fluid rounded-3 border shadow-sm w-100" style="height:160px;object-fit:cover;"
                                         alt="ID Front">
                                </a>
                                <div class="text-center mt-1"><small class="text-muted">Click to view full size</small></div>
                            <?php else: ?>
                                <div class="border rounded-3 bg-light d-flex align-items-center justify-content-center" style="height:160px;">
                                    <span class="text-muted small">Not uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- ID Back -->
                        <div class="col-md-4">
                            <p class="text-muted small fw-semibold mb-1 text-center">CNIC / Passport Back</p>
                            <?php if (!empty($req_user['id_card_back'])): ?>
                                <a href="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['id_card_back']) ?>" target="_blank">
                                    <img src="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['id_card_back']) ?>"
                                         class="img-fluid rounded-3 border shadow-sm w-100" style="height:160px;object-fit:cover;"
                                         alt="ID Back">
                                </a>
                                <div class="text-center mt-1"><small class="text-muted">Click to view full size</small></div>
                            <?php else: ?>
                                <div class="border rounded-3 bg-light d-flex align-items-center justify-content-center" style="height:160px;">
                                    <span class="text-muted small">Not uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Selfie -->
                        <div class="col-md-4">
                            <p class="text-muted small fw-semibold mb-1 text-center">Live Selfie</p>
                            <?php if (!empty($req_user['selfie_photo'])): ?>
                                <a href="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['selfie_photo']) ?>" target="_blank">
                                    <img src="<?= BASE_URL ?>/assets/images/kyc/<?= htmlspecialchars($req_user['selfie_photo']) ?>"
                                         class="img-fluid rounded-3 border shadow-sm w-100" style="height:160px;object-fit:cover;"
                                         alt="Selfie">
                                </a>
                                <div class="text-center mt-1"><small class="text-muted">Click to view full size</small></div>
                            <?php else: ?>
                                <div class="border rounded-3 bg-light d-flex align-items-center justify-content-center" style="height:160px;">
                                    <span class="text-muted small">Not uploaded</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($req_user['status'] === 'rejected'): ?>
                        <div class="alert alert-danger border-0 rounded-3">
                            <h6 class="fw-bold"><i class="bi bi-x-circle me-1"></i> Rejection Reason:</h6>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($req_user['rejection_reason'])) ?></p>
                        </div>
                    <?php endif; ?>

                    <?php if ($req_user['status'] === 'pending'): ?>
                        <div class="d-flex gap-3 mt-4 pt-3 border-top">
                            <form action="" method="POST" class="flex-grow-1" onsubmit="return confirm('Approve this registration?');">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="approve">
                                <button type="submit" class="btn btn-success w-100 fw-bold rounded-pill py-2">
                                    <i class="bi bi-check-circle me-1"></i> Approve Request
                                </button>
                            </form>
                            <button type="button" class="btn btn-danger flex-grow-1 fw-bold rounded-pill py-2" data-bs-toggle="modal" data-bs-target="#rejectModal">
                                <i class="bi bi-x-circle me-1"></i> Reject Request
                            </button>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form action="" method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" value="reject">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title fw-bold text-danger"><i class="bi bi-x-circle me-2"></i>Reject Registration</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3">Please provide a reason for rejecting this user. The user will see this reason when they try to login.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                        <textarea name="rejection_reason" class="form-control rounded-3 bg-light border-0" rows="4" required placeholder="e.g. Invalid phone number or inappropriate details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger rounded-pill px-4 fw-bold">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
