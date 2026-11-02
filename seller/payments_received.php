<?php
/**
 * Seller Payments Received Page
 * Allows seller to review payouts sent by Admin and mark as received.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];

// Handle Mark as Received action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_received') {
    verify_csrf();
    $order_id = (int)($_POST['order_id'] ?? 0);

    // Verify order belongs to this seller and is currently seller_payment_sent
    $chk = $conn->prepare("
        SELECT o.id, o.order_number, o.payment_status, o.seller_payment_amount, o.seller_payment_method, o.seller_payment_received
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        WHERE o.id = ? AND oi.seller_id = ?
        LIMIT 1
    ");
    $chk->bind_param("ii", $order_id, $seller_id);
    $chk->execute();
    $ord = $chk->get_result()->fetch_assoc();

    if (!$ord) {
        $_SESSION['error'] = "Order not found or unauthorized.";
    } elseif ($ord['seller_payment_received'] == 1) {
        $_SESSION['info'] = "You have already confirmed receiving this payment.";
    } elseif ($ord['payment_status'] !== 'seller_payment_sent') {
        $_SESSION['error'] = "No payout has been sent by Admin for this order yet.";
    } else {
        $now = date('Y-m-d H:i:s');
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE orders 
                SET payment_status = 'seller_payment_received', 
                    seller_payment_received = 1, 
                    seller_payment_received_at = ?, 
                    order_status = 'completed' 
                WHERE id = ? AND seller_payment_received = 0
            ");
            $upd->bind_param("si", $now, $order_id);
            $upd->execute();

            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Seller confirmed payment received", "Amount: " . $ord['seller_payment_amount'] . " via " . $ord['seller_payment_method'], $seller_id);
            }

            // Notify Admin
            $notif_title = "Seller Confirmed Payment";
            $notif_msg = "Seller has confirmed receiving payment of " . ($site_settings['currency'] ?? 'Rs.') . number_format($ord['seller_payment_amount'], 2) . " for order #{$ord['order_number']}.";
            $notif_link = "escrow.php";
            $notif_stmt = $conn->prepare("INSERT INTO admin_notifications (type, title, message, link, order_id) VALUES ('order', ?, ?, ?, ?)");
            $notif_stmt->bind_param("sssi", $notif_title, $notif_msg, $notif_link, $order_id);
            $notif_stmt->execute();

            $conn->commit();
            $_SESSION['success'] = "Payment confirmed! Order #{$ord['order_number']} is now completed.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Confirmation failed: " . $e->getMessage();
        }
    }
    header("Location: payments_received.php");
    exit;
}

// 1. Fetch Pending Payments awaiting seller mark
$pending_sql = "
    SELECT DISTINCT o.id, o.order_number, o.created_at, o.subtotal, o.grand_total,
                    o.seller_payment_amount, o.seller_payment_method, o.seller_payment_transaction_id,
                    o.seller_payment_sent_at, o.seller_payment_receipt, o.seller_payment_received,
                    p.title_en AS product_title,
                    (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    WHERE oi.seller_id = ? AND o.payment_status = 'seller_payment_sent' AND o.seller_payment_received = 0
    ORDER BY o.seller_payment_sent_at DESC
";
$stmt_pending = $conn->prepare($pending_sql);
$stmt_pending->bind_param("i", $seller_id);
$stmt_pending->execute();
$pending_payments = $stmt_pending->get_result();

// 2. Fetch History of Completed Payments
$history_sql = "
    SELECT DISTINCT o.id, o.order_number, o.created_at, o.subtotal, o.grand_total,
                    o.seller_payment_amount, o.seller_payment_method, o.seller_payment_transaction_id,
                    o.seller_payment_sent_at, o.seller_payment_received_at, o.seller_payment_receipt, o.seller_payment_received,
                    p.title_en AS product_title,
                    (SELECT image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    WHERE oi.seller_id = ? AND (o.payment_status = 'seller_payment_received' OR o.seller_payment_received = 1)
    ORDER BY o.seller_payment_received_at DESC
";
$stmt_history = $conn->prepare($history_sql);
$stmt_history->bind_param("i", $seller_id);
$stmt_history->execute();
$history_payments = $stmt_history->get_result();

function pm_label($m) {
    $labels = ['jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'bank_transfer' => 'Bank Transfer'];
    return $labels[$m] ?? ucfirst(str_replace('_', ' ', $m));
}

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

            <!-- Header Banner -->
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div>
                    <h2 class="fw-bold mb-1 text-dark">
                        <i class="bi bi-cash-coin text-success me-2"></i>Payments Received
                    </h2>
                    <p class="text-muted mb-0">Check payouts sent by Admin for your delivered products and confirm receipt.</p>
                </div>
                <div>
                    <a href="account.php" class="btn btn-outline-success rounded-pill px-3">
                        <i class="bi bi-credit-card-2-back me-1"></i>My Payment Account Details
                    </a>
                </div>
            </div>

            <!-- SECTION 1: PENDING CONFIRMATION -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="fw-bold mb-0 text-warning">
                            <i class="bi bi-hourglass-split me-2"></i>Awaiting Your Confirmation
                        </h5>
                        <p class="text-muted small mb-0">Admin has sent payment for these orders. Check your account and mark as received.</p>
                    </div>
                    <?php if ($pending_payments && $pending_payments->num_rows > 0): ?>
                        <span class="badge bg-warning text-dark rounded-pill px-3 py-2"><?= $pending_payments->num_rows ?> Pending</span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0 mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Order & Product</th>
                                    <th>Payout Amount</th>
                                    <th>Method & Transaction</th>
                                    <th>Date Sent</th>
                                    <th>Receipt Screenshot</th>
                                    <th class="pe-4 text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($pending_payments && $pending_payments->num_rows > 0): while($p = $pending_payments->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if (!empty($p['product_image'])): ?>
                                                <img src="<?= BASE_URL ?>/assets/uploads/products/<?= htmlspecialchars($p['product_image']) ?>" class="rounded-3 border" style="width: 48px; height: 48px; object-fit: cover;" onerror="this.src='https://placehold.co/48x48?text=Pet';">
                                            <?php else: ?>
                                                <div class="rounded-3 bg-light border d-flex align-items-center justify-content-center text-muted" style="width: 48px; height: 48px;">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <a href="order_details.php?id=<?= $p['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                    #<?= htmlspecialchars($p['order_number']) ?>
                                                </a>
                                                <div class="small text-muted text-truncate" style="max-width: 220px;">
                                                    <?= htmlspecialchars($p['product_title']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="text-success fs-5"><?= $site_settings['currency'] . number_format($p['seller_payment_amount'], 2) ?></strong>
                                        <small class="d-block text-muted">Product Main Price</small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= pm_label($p['seller_payment_method']) ?></span>
                                        <small class="d-block font-monospace text-muted mt-1">TID: <?= htmlspecialchars($p['seller_payment_transaction_id']) ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('d M Y, h:i A', strtotime($p['seller_payment_sent_at'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($p['seller_payment_receipt'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#receiptModal_<?= $p['id'] ?>">
                                                <i class="bi bi-receipt me-1"></i>View Screenshot
                                            </button>

                                            <!-- Screenshot Modal -->
                                            <div class="modal fade" id="receiptModal_<?= $p['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content rounded-4 border-0 shadow">
                                                        <div class="modal-header border-0 bg-light">
                                                            <h6 class="modal-title fw-bold">Payment Proof (#<?= htmlspecialchars($p['order_number']) ?>)</h6>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body text-center p-3">
                                                            <img src="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($p['seller_payment_receipt']) ?>" class="img-fluid rounded-3 shadow-sm" style="max-height: 480px;">
                                                            <div class="mt-3 small text-muted">
                                                                Method: <strong><?= pm_label($p['seller_payment_method']) ?></strong> &bull; TID: <strong><?= htmlspecialchars($p['seller_payment_transaction_id']) ?></strong>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer border-0">
                                                            <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($p['seller_payment_receipt']) ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-3">
                                                                <i class="bi bi-arrows-fullscreen me-1"></i>Open Full Size
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted small">No screenshot</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <form action="" method="POST" class="d-inline" onsubmit="return confirm('Confirm you have verified your account and received Rs. <?= number_format($p['seller_payment_amount'], 2) ?>?');">
                                            <?php csrf_field(); ?>
                                            <input type="hidden" name="action" value="mark_received">
                                            <input type="hidden" name="order_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-success fw-bold rounded-pill px-4 shadow-sm">
                                                <i class="bi bi-check2-circle me-1"></i>Mark as Received
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
                                        <p class="mb-0">No pending payouts awaiting confirmation. All payments are up to date!</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: CONFIRMED PAYMENTS HISTORY -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 pb-2 px-4">
                    <h5 class="fw-bold mb-0 text-success">
                        <i class="bi bi-check-all me-2"></i>Confirmed Payments History
                    </h5>
                    <p class="text-muted small mb-0">All past payouts that you have verified and marked as received.</p>
                </div>
                <div class="card-body p-0 mt-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Order & Product</th>
                                    <th>Amount Received</th>
                                    <th>Method & Transaction</th>
                                    <th>Date Sent</th>
                                    <th>Receipt</th>
                                    <th class="pe-4 text-end">Confirmation Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($history_payments && $history_payments->num_rows > 0): while($h = $history_payments->fetch_assoc()): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if (!empty($h['product_image'])): ?>
                                                <img src="<?= BASE_URL ?>/assets/uploads/products/<?= htmlspecialchars($h['product_image']) ?>" class="rounded-3 border" style="width: 44px; height: 44px; object-fit: cover;" onerror="this.src='https://placehold.co/44x44?text=Pet';">
                                            <?php else: ?>
                                                <div class="rounded-3 bg-light border d-flex align-items-center justify-content-center text-muted" style="width: 44px; height: 44px;">
                                                    <i class="bi bi-image"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <a href="order_details.php?id=<?= $h['id'] ?>" class="fw-bold text-primary text-decoration-none">
                                                    #<?= htmlspecialchars($h['order_number']) ?>
                                                </a>
                                                <div class="small text-muted text-truncate" style="max-width: 220px;">
                                                    <?= htmlspecialchars($h['product_title']) ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?= $site_settings['currency'] . number_format($h['seller_payment_amount'], 2) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?= pm_label($h['seller_payment_method']) ?></span>
                                        <small class="d-block font-monospace text-muted mt-1"><?= htmlspecialchars($h['seller_payment_transaction_id']) ?></small>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= date('d M Y', strtotime($h['seller_payment_sent_at'])) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($h['seller_payment_receipt'])): ?>
                                            <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($h['seller_payment_receipt']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">
                                                <i class="bi bi-eye me-1"></i>View
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <span class="badge bg-success-subtle text-success border border-success px-3 py-2 rounded-pill">
                                            <i class="bi bi-patch-check-fill me-1"></i>Confirmed Received
                                        </span>
                                        <?php if (!empty($h['seller_payment_received_at'])): ?>
                                            <small class="d-block text-muted mt-1"><?= date('d M Y, h:i A', strtotime($h['seller_payment_received_at'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">No confirmed payment history yet.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
