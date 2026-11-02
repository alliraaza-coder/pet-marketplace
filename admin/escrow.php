<?php
/**
 * Admin Escrow Management
 * Phase 8 – Full Manual Payment Workflow
 * Modals rendered OUTSIDE tables to prevent Bootstrap modal squishing inside table-responsive.
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$admin_id = $_SESSION['user_id'];

// ─── POST ACTION HANDLING ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    verify_csrf();
    $action   = sanitize_input($_POST['action']);
    $order_id = (int)$_POST['order_id'];

    if ($action === 'approve_payment') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("SELECT payment_status FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk || $chk['payment_status'] !== 'payment_submitted') {
                throw new Exception("This order does not have a submitted payment to verify.");
            }
            $upd = $conn->prepare("UPDATE orders SET payment_status = 'held', order_status = 'pending' WHERE id = ?");
            $upd->bind_param("i", $order_id);
            $upd->execute();
            log_admin_activity($conn, $admin_id, "Approved Payment", "Payment verified for Order ID: $order_id. Escrow activated.");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin approved payment", "Payment received and placed in escrow. Awaiting seller acceptance.", $admin_id);
            if (function_exists('add_user_notification')) {
                $sel_res = $conn->query("SELECT seller_id FROM order_items WHERE order_id = $order_id LIMIT 1");
                if ($sel_res && $sel = $sel_res->fetch_assoc()) {
                    add_user_notification($conn, $sel['seller_id'], 'order', 'New Active Order', "Buyer payment verified. You have a new active order (#$order_id).", 'seller/order_details.php?id='.$order_id);
                }
                $buy_res = $conn->query("SELECT user_id, order_number FROM orders WHERE id = $order_id");
                if ($buy_res && $buy = $buy_res->fetch_assoc()) {
                    add_user_notification($conn, $buy['user_id'], 'order', 'Payment Approved', "Your payment for order #{$buy['order_number']} was approved and is now held in escrow.", 'user/order_details.php?id='.$order_id);
                }
            }
            $conn->commit();
            $_SESSION['success'] = "Payment verified! Funds are now held in Escrow. Seller can now see and process the order.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Approval failed: " . $e->getMessage();
        }

    } elseif ($action === 'reject_payment') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("SELECT payment_status FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk || $chk['payment_status'] !== 'payment_submitted') {
                throw new Exception("This order does not have a submitted payment to reject.");
            }
            $reject_note = sanitize_input($_POST['reject_note'] ?? 'Payment proof was rejected by admin.');
            $upd = $conn->prepare("UPDATE orders SET payment_status = 'rejected', payment_reference = NULL, payment_proof = NULL WHERE id = ?");
            $upd->bind_param("i", $order_id);
            $upd->execute();
            log_admin_activity($conn, $admin_id, "Rejected Payment", "Payment rejected for Order ID: $order_id. Reason: $reject_note");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin rejected payment", "Reason: $reject_note", $admin_id);
            $conn->commit();
            $_SESSION['success'] = "Payment proof rejected. The buyer must resubmit correct proof.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Rejection failed: " . $e->getMessage();
        }

    } elseif ($action === 'verify') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("SELECT payment_status, seller_delivered, buyer_received FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk || $chk['payment_status'] !== 'held') throw new Exception("Order is not in escrow.");
            if ($chk['seller_delivered'] != 1 || $chk['buyer_received'] != 1) {
                throw new Exception("Delivery cannot be verified until both Seller Delivered and Buyer Confirmed are YES.");
            }
            $upd = $conn->prepare("UPDATE orders SET admin_verified = 1 WHERE id = ?");
            $upd->bind_param("i", $order_id);
            $upd->execute();
            log_admin_activity($conn, $admin_id, "Verified Delivery", "Delivery verified for Order ID: $order_id. Ready for payment release.");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin verified delivery", "All delivery conditions met. Admin approved.", $admin_id);
            $conn->commit();
            $_SESSION['success'] = "Delivery successfully verified by Admin. Ready for Seller Payment.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Verification failed: " . $e->getMessage();
        }

    } elseif ($action === 'pay_seller') {
        $conn->begin_transaction();
        try {
            if (!isset($_FILES['seller_payment_receipt']) || $_FILES['seller_payment_receipt']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Seller payment receipt image is required.");
            }
            $chk = $conn->query("SELECT o.*, oi.seller_id FROM orders o JOIN order_items oi ON o.id = oi.order_id WHERE o.id = $order_id LIMIT 1")->fetch_assoc();
            if (!$chk) throw new Exception("Order not found.");
            if ($chk['payment_status'] !== 'held') throw new Exception("Payment is not held in escrow.");
            if ($chk['dispute_status'] == 1) throw new Exception("Cannot pay seller. There is an active dispute on this order.");
            if ($chk['seller_delivered'] != 1) throw new Exception("Seller has not marked as delivered yet.");
            if ($chk['buyer_received'] != 1) throw new Exception("Buyer has not confirmed receipt yet.");
            if (!empty($chk['seller_payment_sent_at'])) throw new Exception("Seller has already been paid for this order.");

            $file = $_FILES['seller_payment_receipt'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) throw new Exception("Invalid file type. Only JPG, JPEG, PNG, WEBP allowed.");
            if ($file['size'] > 5242880) throw new Exception("File size must be under 5MB.");

            $new_name = 'seller_pay_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = __DIR__ . '/../assets/images/payments/' . $new_name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) throw new Exception("Failed to save payout proof image.");

            $method = sanitize_input($_POST['seller_payment_method']);
            $amount = (float)$_POST['seller_payment_amount'];
            $tx_id  = sanitize_input($_POST['seller_payment_transaction_id']);
            $now    = date('Y-m-d H:i:s');

            $upd = $conn->prepare("UPDATE orders SET admin_verified = 1, payment_status = 'seller_payment_sent', seller_payment_method = ?, seller_payment_amount = ?, seller_payment_transaction_id = ?, seller_payment_sent_at = ?, seller_payment_receipt = ?, seller_payment_sent_by = ?, seller_payment_received = 0 WHERE id = ?");
            $upd->bind_param("sdsssii", $method, $amount, $tx_id, $now, $new_name, $admin_id, $order_id);
            $upd->execute();

            $tx = $conn->prepare("INSERT INTO transactions (order_id, user_id, seller_id, transaction_type, amount, payment_method, status, reference_no, receipt, admin_id, created_at) VALUES (?, ?, ?, 'release', ?, ?, 'seller_payment_sent', ?, ?, ?, ?)");
            $tx->bind_param("iiidsssis", $order_id, $chk['user_id'], $chk['seller_id'], $amount, $method, $tx_id, $new_name, $admin_id, $now);
            $tx->execute();

            log_admin_activity($conn, $admin_id, "Paid Seller", "Sent seller payment for Order #{$chk['order_number']}");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin sent payment to seller", "Waiting for seller to confirm receipt.", $admin_id);
            if (function_exists('add_user_notification')) {
                add_user_notification($conn, $chk['seller_id'], 'payout', 'Payout Sent', "Admin has sent your payout of " . ($site_settings['currency'] ?? 'Rs.') . number_format($amount, 2) . " for order #{$chk['order_number']}. Please confirm receipt.", 'seller/payments_received.php');
            }
            $conn->commit();
            $_SESSION['success'] = "Seller payment sent successfully. Waiting for seller to confirm receipt.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Release failed: " . $e->getMessage();
        }

    } elseif ($action === 'refund_buyer') {
        $conn->begin_transaction();
        try {
            if (!isset($_FILES['buyer_refund_receipt']) || $_FILES['buyer_refund_receipt']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Refund receipt image is required.");
            }
            $chk = $conn->query("SELECT payment_status, grand_total, order_number, user_id FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk) throw new Exception("Order not found.");
            if (!in_array($chk['payment_status'], ['held', 'refund_submitted', 'payment_submitted', 'pending'])) throw new Exception("No valid payment found to refund.");

            $file = $_FILES['buyer_refund_receipt'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) throw new Exception("Invalid file type. Only JPG, JPEG, PNG, WEBP allowed.");

            $new_name = 'buyer_refund_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = __DIR__ . '/../assets/images/payments/' . $new_name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) throw new Exception("Failed to save refund proof image.");

            $method = sanitize_input($_POST['buyer_refund_method']);
            $amount = (float)$_POST['buyer_refund_amount'];
            $tx_id  = sanitize_input($_POST['buyer_refund_transaction_id']);
            $now    = date('Y-m-d H:i:s');

            $upd = $conn->prepare("UPDATE orders SET payment_status = 'refund_sent', order_status = 'cancelled', buyer_refund_method = ?, buyer_refund_amount = ?, buyer_refund_transaction_id = ?, buyer_refund_sent_at = ?, buyer_refund_receipt = ?, buyer_refund_sent_by = ?, buyer_refund_received = 0 WHERE id = ?");
            $upd->bind_param("sdsssii", $method, $amount, $tx_id, $now, $new_name, $admin_id, $order_id);
            $upd->execute();

            $tx = $conn->prepare("INSERT INTO transactions (order_id, user_id, transaction_type, amount, payment_method, status, reference_no, receipt, admin_id, created_at) VALUES (?, ?, 'refund', ?, ?, 'refund_sent', ?, ?, ?, ?)");
            $tx->bind_param("iidsssis", $order_id, $chk['user_id'], $amount, $method, $tx_id, $new_name, $admin_id, $now);
            $tx->execute();

            log_admin_activity($conn, $admin_id, "Refunded Buyer", "Sent refund for Order #{$chk['order_number']}");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin sent refund to buyer", "Waiting for buyer to confirm receipt.", $admin_id);
            if (function_exists('add_user_notification')) {
                add_user_notification($conn, $chk['user_id'], 'refund', 'Refund Sent', "Admin has sent your refund of " . ($site_settings['currency'] ?? 'Rs.') . number_format($amount, 2) . " for order #{$chk['order_number']}. Please confirm receipt.", 'user/order_details.php?id='.$order_id);
            }
            $conn->commit();
            $_SESSION['success'] = "Refund sent to buyer successfully. Waiting for buyer confirmation.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Refund failed: " . $e->getMessage();
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ─── DATA QUERIES ─────────────────────────────────────────────────────────────
$stats_query = "SELECT
  COUNT(CASE WHEN payment_status = 'held' THEN 1 END) AS total_held,
  COALESCE(SUM(CASE WHEN payment_status = 'held' THEN grand_total END), 0) AS total_held_amount,
  COALESCE(SUM(CASE WHEN payment_status = 'seller_payment_sent' THEN seller_payment_amount END), 0) AS total_paid_sellers,
  COALESCE(SUM(CASE WHEN payment_status = 'seller_payment_received' OR seller_payment_received = 1 THEN seller_payment_amount END), 0) AS total_confirmed_payouts,
  COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent','refund_received') THEN buyer_refund_amount END), 0) AS total_refunds_sent,
  COUNT(CASE WHEN payment_status = 'payment_submitted' THEN 1 END) AS total_pending_verification,
  COUNT(CASE WHEN (payment_status IN ('held', 'seller_payment_sent')) AND seller_delivered=1 AND buyer_received=1 AND seller_payment_received = 0 THEN 1 END) AS sellers_pending_payment,
  COUNT(CASE WHEN ((payment_status IN ('held','refund_submitted') AND order_status='cancelled') OR (order_status='cancelled' AND payment_reference IS NOT NULL AND payment_status NOT IN ('pending','rejected'))) AND buyer_refund_sent_at IS NULL THEN 1 END) AS refunds_pending
FROM orders";
$stats = $conn->query($stats_query)->fetch_assoc();

$base_query = "SELECT o.*,
                      b.first_name AS buyer_fn, b.last_name AS buyer_ln, b.phone AS buyer_phone,
                      s.id AS seller_id, s.first_name AS seller_fn, s.last_name AS seller_ln, s.phone AS seller_phone,
                      p.id AS product_id,
                      p.title_en AS product_title,
                      (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) AS product_image,
                      COALESCE(SUM(oi.total), o.subtotal) AS product_main_price
               FROM orders o
               JOIN users b ON o.user_id = b.id
               JOIN order_items oi ON o.id = oi.order_id
               JOIN users s ON oi.seller_id = s.id
               LEFT JOIN products p ON oi.product_id = p.id ";

// Fetch all rows into arrays so we can separate table rows from modals
$res_a = $conn->query($base_query . "WHERE o.payment_status = 'payment_submitted' GROUP BY o.id ORDER BY o.created_at DESC");
$sec_a_rows = ($res_a && $res_a->num_rows > 0) ? $res_a->fetch_all(MYSQLI_ASSOC) : [];

$res_b = $conn->query($base_query . "WHERE o.payment_status IN ('held', 'seller_payment_sent') AND o.seller_delivered=1 AND o.buyer_received=1 AND o.seller_payment_received = 0 GROUP BY o.id ORDER BY o.created_at DESC");
$sec_b_rows = ($res_b && $res_b->num_rows > 0) ? $res_b->fetch_all(MYSQLI_ASSOC) : [];

$res_c = $conn->query($base_query . "WHERE (o.payment_status = 'seller_payment_received' OR o.seller_payment_received = 1) GROUP BY o.id ORDER BY o.seller_payment_received_at DESC");
$sec_c_rows = ($res_c && $res_c->num_rows > 0) ? $res_c->fetch_all(MYSQLI_ASSOC) : [];

$res_e = $conn->query($base_query . "WHERE ((o.payment_status IN ('held','refund_submitted') AND o.order_status='cancelled') OR (o.order_status='cancelled' AND o.payment_reference IS NOT NULL AND o.payment_status NOT IN ('pending','rejected'))) AND o.buyer_refund_sent_at IS NULL GROUP BY o.id ORDER BY o.created_at DESC");
$sec_e_rows = ($res_e && $res_e->num_rows > 0) ? $res_e->fetch_all(MYSQLI_ASSOC) : [];

$res_f = $conn->query($base_query . "WHERE o.payment_status IN ('refund_sent','refund_received') GROUP BY o.id ORDER BY o.buyer_refund_sent_at DESC");
$sec_f_rows = ($res_f && $res_f->num_rows > 0) ? $res_f->fetch_all(MYSQLI_ASSOC) : [];

// Pre-fetch seller/buyer account details for B and E rows
$sec_b_accounts = [];
foreach ($sec_b_rows as $row) {
    if (!empty($row['seller_id'])) {
        $sec_b_accounts[$row['id']] = get_user_payment_account($conn, $row['seller_id']);
    }
}
$sec_e_accounts = [];
foreach ($sec_e_rows as $row) {
    if (!empty($row['user_id'])) {
        $sec_e_accounts[$row['id']] = get_user_payment_account($conn, $row['user_id']);
    }
}

// ─── HTML STRUCTURE ───────────────────────────────────────────────────────────
$page_title   = "Payment & Escrow Management";
$page_heading = "Payment Management";
include __DIR__ . '/partials/header.php';

function pm_label($m) {
    $labels = ['jazzcash'=>'JazzCash', 'easypaisa'=>'EasyPaisa', 'bank_transfer'=>'Bank Transfer'];
    return $labels[$m] ?? ucfirst(str_replace('_', ' ', $m));
}
?>

<style>
/* ── Delivered / Confirmed Stamp ─────────────────────── */
.delivered-stamp {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%) rotate(-12deg);
    border: 4px dashed #198754;
    color: #198754;
    background: rgba(255, 255, 255, 0.94);
    padding: 12px 26px;
    border-radius: 12px;
    box-shadow: 0 6px 20px rgba(25, 135, 84, 0.35);
    text-align: center;
    pointer-events: none;
    z-index: 10;
}
.delivered-stamp .stamp-inner {
    font-family: 'Impact', 'Arial Black', sans-serif;
    font-size: 2.2rem;
    letter-spacing: 3px;
    line-height: 1;
    color: #198754;
}
.delivered-stamp .stamp-sub {
    font-size: 0.85rem;
    font-family: sans-serif;
    font-weight: 800;
    letter-spacing: 2px;
    margin-top: 4px;
    color: #146c43;
}
.delivered-stamp .stamp-date {
    font-size: 0.75rem;
    font-family: monospace;
    font-weight: 700;
    color: #0f5132;
    margin-top: 2px;
}
.cursor-pointer { cursor: pointer; }

/* ── Pay Seller Modal ─────────────────────────────────── */
.pay-modal-body   { background-color: #f8fafc; }
.pay-modal-header { background: linear-gradient(135deg, #198754 0%, #146c43 100%); }
.pay-modal-footer { background-color: #ffffff; }
.account-box-jc   { background: rgba(220,53,69,.07); border-color: #dc3545 !important; }
.account-box-ep   { background: rgba(25,135,84,.07);  border-color: #198754 !important; }
.account-box-bank { background: rgba(13,110,253,.07);  border-color: #0d6efd !important; }
.account-box-empty{ background: #f8f9fa; border-color: #dee2e6 !important; }

/* ── SS Upload Drop-zone ──────────────────────────────── */
.ss-dropzone {
    border: 2px dashed #198754;
    border-radius: 12px;
    background: #f0faf5;
    cursor: pointer;
    transition: background .2s;
}
.ss-dropzone:hover { background: #e2f5ea; }
</style>

<!-- ══════════════════════════════════════════════════════
     STAT CARDS
══════════════════════════════════════════════════════ -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card bg-warning text-dark border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-dark-50 small fw-bold text-uppercase">Buyer Verification</div>
                <h3 class="fw-bold mb-0 mt-1"><?= number_format($stats['total_pending_verification']) ?></h3>
                <small class="text-dark-50">Payments awaiting approval</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-white-50 small fw-bold text-uppercase">Active Escrow Funds</div>
                <h3 class="fw-bold mb-0 mt-1"><?= $site_settings['currency'] . number_format($stats['total_held_amount']) ?></h3>
                <small class="text-white-50"><?= number_format($stats['total_held']) ?> orders held securely</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-primary text-white border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-white-50 small fw-bold text-uppercase">Seller Payouts Pending</div>
                <h3 class="fw-bold mb-0 mt-1"><?= number_format($stats['sellers_pending_payment']) ?></h3>
                <small class="text-white-50">Delivery confirmed by both sides</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <div class="text-white-50 small fw-bold text-uppercase">Buyer Refunds Pending</div>
                <h3 class="fw-bold mb-0 mt-1"><?= number_format($stats['refunds_pending']) ?></h3>
                <small class="text-white-50">Cancelled orders awaiting refund</small>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION A: Buyer Payments Pending Verification
══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-warning"><i class="bi bi-clock-history me-2"></i>Buyer Payments Pending Verification</h5>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Ref / Date</th>
                        <th>Screenshot</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sec_a_rows)): foreach ($sec_a_rows as $o): ?>
                    <tr>
                        <td class="ps-4 fw-bold">#<?= htmlspecialchars($o['order_number']) ?></td>
                        <td><?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></td>
                        <td class="fw-bold text-success"><?= $site_settings['currency'].number_format($o['grand_total'],2) ?></td>
                        <td><?= pm_label($o['payment_method']) ?></td>
                        <td><small><?= htmlspecialchars($o['payment_reference']) ?><br><?= date('d M Y', strtotime($o['updated_at'])) ?></small></td>
                        <td>
                            <?php if($o['payment_proof']): ?>
                            <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['payment_proof']) ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill">View Receipt</a>
                            <?php else: ?>
                            <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 text-end">
                            <form action="" method="POST" class="d-inline">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="approve_payment">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success rounded-pill px-3" onclick="return confirm('Approve this payment and place in Escrow?');">Approve</button>
                            </form>
                            <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 ms-1"
                                data-bs-toggle="modal" data-bs-target="#rejectModal<?= $o['id'] ?>">Reject</button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No pending buyer payments.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION B: Seller Payments Pending
══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-wallet2 me-2"></i>Seller Payments Pending</h5>
        <p class="text-muted small">Both seller marked delivered and buyer marked received. Admin sends product main price with TID &amp; screenshot, then seller marks received on their dashboard.</p>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order &amp; Product</th>
                        <th>Seller</th>
                        <th>Seller Account Details</th>
                        <th>Product Price (To Pay)</th>
                        <th>Delivery Status</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sec_b_rows)): foreach ($sec_b_rows as $o):
                        $s_acc = $sec_b_accounts[$o['id']] ?? null;
                        $product_price = (!empty($o['subtotal']) && $o['subtotal'] > 0) ? (float)$o['subtotal'] : (float)$o['grand_total'];
                    ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-2">
                                <?php 
                                $esc_img = !empty($o['product_image']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($o['product_image']) : (!empty($o['product_id']) ? get_product_image_url($conn, $o['product_id']) : '');
                                if (!empty($esc_img)):
                                ?>
                                    <img src="<?= $esc_img ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 38px; height: 38px;" onerror="this.src='https://placehold.co/38x38?text=Pet';">
                                <?php endif; ?>
                                <div>
                                    <span class="fw-bold text-primary">#<?= htmlspecialchars($o['order_number']) ?></span>
                                    <?php if (!empty($o['product_title'])): ?>
                                        <small class="d-block text-muted text-truncate" style="max-width:180px;" title="<?= htmlspecialchars($o['product_title']) ?>"><?= htmlspecialchars($o['product_title']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?></span>
                            <?php if (!empty($o['seller_phone'])): ?>
                                <small class="d-block text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($o['seller_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($s_acc && (!empty($s_acc['jazzcash_number']) || !empty($s_acc['easypaisa_number']) || !empty($s_acc['bank_account_number']) || !empty($s_acc['bank_iban']))): ?>
                                <div class="small">
                                    <?php if (!empty($s_acc['jazzcash_number'])): ?>
                                        <div class="mb-1"><span class="badge bg-danger py-1 me-1">JC</span><strong><?= htmlspecialchars($s_acc['jazzcash_number']) ?></strong> (<?= htmlspecialchars($s_acc['jazzcash_name'] ?: 'N/A') ?>)</div>
                                    <?php endif; ?>
                                    <?php if (!empty($s_acc['easypaisa_number'])): ?>
                                        <div class="mb-1"><span class="badge bg-success py-1 me-1">EP</span><strong><?= htmlspecialchars($s_acc['easypaisa_number']) ?></strong> (<?= htmlspecialchars($s_acc['easypaisa_name'] ?: 'N/A') ?>)</div>
                                    <?php endif; ?>
                                    <?php if (!empty($s_acc['bank_account_number']) || !empty($s_acc['bank_iban'])): ?>
                                        <div><span class="badge bg-primary py-1 me-1">Bank</span><strong><?= htmlspecialchars($s_acc['bank_account_number'] ?: $s_acc['bank_iban']) ?></strong> (<?= htmlspecialchars($s_acc['bank_name'] ?: 'Bank') ?>)</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">No details saved</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong class="text-success fs-6"><?= $site_settings['currency'] . number_format($product_price, 2) ?></strong>
                            <small class="d-block text-muted">Main Product Price</small>
                        </td>
                        <td>
                            <span class="badge bg-success-subtle text-success border border-success d-inline-flex align-items-center mb-1">
                                <i class="bi bi-truck me-1"></i> Seller Delivered
                            </span><br>
                            <span class="badge bg-success-subtle text-success border border-success d-inline-flex align-items-center">
                                <i class="bi bi-check-circle me-1"></i> Buyer Confirmed
                            </span>
                        </td>
                        <td class="pe-4 text-end">
                            <?php if ($o['payment_status'] === 'seller_payment_sent'): ?>
                                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill mb-1 d-inline-block">
                                    <i class="bi bi-clock-history me-1"></i>Proof Sent &bull; Awaiting Seller Mark
                                </span><br>
                                <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 mt-1"
                                    data-bs-toggle="modal" data-bs-target="#viewSentModal<?= $o['id'] ?>">
                                    <i class="bi bi-eye me-1"></i>View Sent Proof
                                </button>
                                <button type="button" class="btn btn-sm btn-light border rounded-pill px-2 mt-1"
                                    data-bs-toggle="modal" data-bs-target="#payModal<?= $o['id'] ?>" title="Resubmit Proof">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-success rounded-pill px-4 shadow-sm fw-bold"
                                    data-bs-toggle="modal" data-bs-target="#payModal<?= $o['id'] ?>">
                                    <i class="bi bi-send me-1"></i>Pay Seller
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
                        No pending seller payments. Both sides must confirm delivery first.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION C: Seller Payments Processed
══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-success"><i class="bi bi-check-all me-2"></i>Seller Payments Processed</h5>
        <p class="text-muted small">Confirmed payouts received by sellers. View receipt with official DELIVERED &amp; CONFIRMED stamp.</p>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order &amp; Product</th>
                        <th>Seller</th>
                        <th>Amount Sent</th>
                        <th>Method &amp; Transaction</th>
                        <th>Date Sent</th>
                        <th>Receipt Screenshot</th>
                        <th class="pe-4 text-end">Confirmation Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sec_c_rows)): foreach ($sec_c_rows as $o): ?>
                    <tr>
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-2">
                                <?php 
                                $esc_img_c = !empty($o['product_image']) ? BASE_URL . '/assets/uploads/products/' . htmlspecialchars($o['product_image']) : (!empty($o['product_id']) ? get_product_image_url($conn, $o['product_id']) : '');
                                if (!empty($esc_img_c)):
                                ?>
                                    <img src="<?= $esc_img_c ?>" class="rounded border object-fit-cover flex-shrink-0" style="width: 38px; height: 38px;" onerror="this.src='https://placehold.co/38x38?text=Pet';">
                                <?php endif; ?>
                                <div>
                                    <span class="fw-bold text-primary">#<?= htmlspecialchars($o['order_number']) ?></span>
                                    <?php if (!empty($o['product_title'])): ?>
                                        <small class="d-block text-muted text-truncate" style="max-width:180px;"><?= htmlspecialchars($o['product_title']) ?></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="fw-semibold text-dark"><?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?></span></td>
                        <td><strong class="text-success"><?= $site_settings['currency'] . number_format($o['seller_payment_amount'], 2) ?></strong></td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= pm_label($o['seller_payment_method']) ?></span>
                            <small class="d-block text-muted font-monospace mt-1"><?= htmlspecialchars($o['seller_payment_transaction_id']) ?></small>
                        </td>
                        <td><small class="text-muted"><?= !empty($o['seller_payment_sent_at']) ? date('d M Y, h:i A', strtotime($o['seller_payment_sent_at'])) : '—' ?></small></td>
                        <td>
                            <?php if (!empty($o['seller_payment_receipt'])): ?>
                                <button type="button" class="btn btn-sm btn-outline-success rounded-pill px-3 fw-semibold"
                                    data-bs-toggle="modal" data-bs-target="#viewDeliveredModal_<?= $o['id'] ?>">
                                    <i class="bi bi-eye me-1"></i>View Receipt
                                </button>
                            <?php else: ?>
                                <span class="text-muted small">No receipt</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 text-end">
                            <span class="badge bg-success-subtle text-success border border-success px-3 py-2 rounded-pill">
                                <i class="bi bi-check-circle-fill me-1"></i>Received Confirmed
                            </span>
                            <?php if (!empty($o['seller_payment_received_at'])): ?>
                                <small class="d-block text-muted mt-1"><?= date('d M Y, h:i A', strtotime($o['seller_payment_received_at'])) ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-2 text-muted d-block mb-2"></i>
                        No processed seller payments yet.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION E: Buyer Refunds Pending
══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-arrow-return-left me-2"></i>Buyer Refunds Pending</h5>
        <p class="text-muted small">Orders cancelled after buyer payment. Admin can disburse refund directly to buyer's saved account.</p>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Buyer Refund Account Details</th>
                        <th>Refund Amount</th>
                        <th>Status</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sec_e_rows)): foreach ($sec_e_rows as $o):
                        $b_acc = $sec_e_accounts[$o['id']] ?? null;
                    ?>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">#<?= htmlspecialchars($o['order_number']) ?></td>
                        <td>
                            <span class="fw-semibold text-dark"><?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></span>
                            <?php if (!empty($o['buyer_phone'])): ?>
                                <small class="d-block text-muted"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($o['buyer_phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b_acc && (!empty($b_acc['jazzcash_number']) || !empty($b_acc['easypaisa_number']) || !empty($b_acc['bank_account_number']) || !empty($b_acc['bank_iban']))): ?>
                                <div class="small">
                                    <?php if (!empty($b_acc['jazzcash_number'])): ?>
                                        <div class="mb-1"><span class="badge bg-danger py-1 me-1">JC</span><strong><?= htmlspecialchars($b_acc['jazzcash_number']) ?></strong> (<?= htmlspecialchars($b_acc['jazzcash_name'] ?: 'N/A') ?>)</div>
                                    <?php endif; ?>
                                    <?php if (!empty($b_acc['easypaisa_number'])): ?>
                                        <div class="mb-1"><span class="badge bg-success py-1 me-1">EP</span><strong><?= htmlspecialchars($b_acc['easypaisa_number']) ?></strong> (<?= htmlspecialchars($b_acc['easypaisa_name'] ?: 'N/A') ?>)</div>
                                    <?php endif; ?>
                                    <?php if (!empty($b_acc['bank_account_number']) || !empty($b_acc['bank_iban'])): ?>
                                        <div><span class="badge bg-primary py-1 me-1">Bank</span><strong><?= htmlspecialchars($b_acc['bank_account_number'] ?: $b_acc['bank_iban']) ?></strong> (<?= htmlspecialchars($b_acc['bank_name'] ?: 'Bank') ?>)</div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border">No details saved</span>
                            <?php endif; ?>
                        </td>
                        <td><strong class="text-danger fs-6"><?= $site_settings['currency'] . number_format($o['grand_total'], 2) ?></strong></td>
                        <td><span class="badge bg-danger rounded-pill"><i class="bi bi-x-circle me-1"></i>Cancelled</span></td>
                        <td class="pe-4 text-end">
                            <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm fw-bold"
                                data-bs-toggle="modal" data-bs-target="#refundModal<?= $o['id'] ?>">
                                <i class="bi bi-arrow-return-left me-1"></i>Refund Buyer
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No pending refunds.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     SECTION F: Buyer Refunds Processed
══════════════════════════════════════════════════════ -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-success"><i class="bi bi-check2-all me-2"></i>Buyer Refunds Processed</h5>
        <p class="text-muted small">Refunds sent to buyers. Receipt saved and marked "Received Checked" when confirmed by buyer.</p>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Refund Amount</th>
                        <th>Method &amp; Transaction</th>
                        <th>Date Sent</th>
                        <th>Receipt Screenshot</th>
                        <th class="pe-4 text-end">Confirmation Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sec_f_rows)): foreach ($sec_f_rows as $o): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-primary">#<?= htmlspecialchars($o['order_number']) ?></td>
                        <td><span class="fw-semibold text-dark"><?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></span></td>
                        <td><strong class="text-danger"><?= $site_settings['currency'] . number_format($o['buyer_refund_amount'], 2) ?></strong></td>
                        <td>
                            <span class="badge bg-light text-dark border"><?= pm_label($o['buyer_refund_method']) ?></span>
                            <small class="d-block text-muted font-monospace mt-1"><?= htmlspecialchars($o['buyer_refund_transaction_id']) ?></small>
                        </td>
                        <td><small class="text-muted"><?= !empty($o['buyer_refund_sent_at']) ? date('d M Y, h:i A', strtotime($o['buyer_refund_sent_at'])) : '—' ?></small></td>
                        <td>
                            <?php if (!empty($o['buyer_refund_receipt'])): ?>
                                <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['buyer_refund_receipt']) ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill">
                                    <i class="bi bi-receipt me-1"></i>View Receipt
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">No receipt</span>
                            <?php endif; ?>
                        </td>
                        <td class="pe-4 text-end">
                            <?php if ($o['payment_status'] === 'refund_received' || $o['buyer_refund_received'] == 1): ?>
                                <span class="badge bg-success px-3 py-2 rounded-pill"><i class="bi bi-check-circle-fill me-1"></i>Received Checked</span>
                                <?php if (!empty($o['buyer_refund_received_at'])): ?>
                                    <small class="d-block text-muted mt-1"><?= date('d M Y, h:i A', strtotime($o['buyer_refund_received_at'])) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark px-3 py-2 rounded-pill"><i class="bi bi-clock me-1"></i>Awaiting Buyer Confirmation</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No processed buyer refunds yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════
     ALL MODALS — rendered here OUTSIDE any table/card
     so Bootstrap modal positioning works correctly.
══════════════════════════════════════════════════════ -->

<!-- ── Section A Modals: Reject Payment ─────────────── -->
<?php foreach ($sec_a_rows as $o): ?>
<div class="modal fade" id="rejectModal<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form action="" method="POST" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="reject_payment">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold"><i class="bi bi-x-circle me-2"></i>Reject Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-2">Order: <strong>#<?= htmlspecialchars($o['order_number']) ?></strong> — Buyer: <strong><?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></strong></p>
                <label class="form-label fw-bold">Reason for Rejection <span class="text-danger">*</span></label>
                <textarea name="reject_note" class="form-control rounded-3" rows="3" placeholder="e.g. Screenshot unclear, wrong reference number..." required></textarea>
            </div>
            <div class="modal-footer border-0 bg-light">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger fw-bold rounded-pill px-4">
                    <i class="bi bi-x-circle me-1"></i>Confirm Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Section B Modals: Pay Seller + View Sent Proof ── -->
<?php foreach ($sec_b_rows as $o):
    $s_acc = $sec_b_accounts[$o['id']] ?? null;
    $product_price = (!empty($o['subtotal']) && $o['subtotal'] > 0) ? (float)$o['subtotal'] : (float)$o['grand_total'];
?>

<!-- Pay Seller Modal -->
<div class="modal fade" id="payModal<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form action="" method="POST" enctype="multipart/form-data"
              class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="pay_seller">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">

            <!-- Header -->
            <div class="modal-header pay-modal-header border-0 px-4 py-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center text-white"
                         style="width:46px;height:46px;">
                        <i class="bi bi-cash-stack fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Disburse Seller Payout</h5>
                        <small class="text-white-50">Order #<?= htmlspecialchars($o['order_number']) ?><?= !empty($o['product_title']) ? ' &bull; '.htmlspecialchars($o['product_title']) : '' ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <!-- Body -->
            <div class="modal-body p-4 pay-modal-body">

                <!-- Seller Info + Account Cards -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-header bg-white border-bottom py-2 px-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-dark">
                            <i class="bi bi-person-check-fill text-success me-2"></i>
                            Seller: <?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?>
                        </span>
                        <?php if (!empty($o['seller_phone'])): ?>
                            <span class="badge bg-light text-muted border"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($o['seller_phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-3">
                        <div class="fw-semibold text-muted small mb-2 text-uppercase" style="letter-spacing:.5px">Recipient Account Details</div>
                        <div class="row g-2">
                            <!-- JazzCash -->
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= !empty($s_acc['jazzcash_number']) ? 'account-box-jc' : 'account-box-empty' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="badge bg-danger">JazzCash</span>
                                        <small class="text-muted">Mobile</small>
                                    </div>
                                    <?php if (!empty($s_acc['jazzcash_number'])): ?>
                                        <div class="fw-bold text-dark font-monospace small"><?= htmlspecialchars($s_acc['jazzcash_number']) ?></div>
                                        <div class="text-muted small text-truncate"><?= htmlspecialchars($s_acc['jazzcash_name'] ?: 'N/A') ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted fst-italic">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- EasyPaisa -->
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= !empty($s_acc['easypaisa_number']) ? 'account-box-ep' : 'account-box-empty' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="badge bg-success">EasyPaisa</span>
                                        <small class="text-muted">Mobile</small>
                                    </div>
                                    <?php if (!empty($s_acc['easypaisa_number'])): ?>
                                        <div class="fw-bold text-dark font-monospace small"><?= htmlspecialchars($s_acc['easypaisa_number']) ?></div>
                                        <div class="text-muted small text-truncate"><?= htmlspecialchars($s_acc['easypaisa_name'] ?: 'N/A') ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted fst-italic">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Bank -->
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= (!empty($s_acc['bank_account_number']) || !empty($s_acc['bank_iban'])) ? 'account-box-bank' : 'account-box-empty' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="badge bg-primary"><?= htmlspecialchars($s_acc['bank_name'] ?? 'Bank') ?></span>
                                        <small class="text-muted">Account</small>
                                    </div>
                                    <?php if (!empty($s_acc['bank_account_number']) || !empty($s_acc['bank_iban'])): ?>
                                        <div class="fw-bold text-dark font-monospace small text-truncate"><?= htmlspecialchars($s_acc['bank_account_number'] ?? $s_acc['bank_iban']) ?></div>
                                        <div class="text-muted small text-truncate"><?= htmlspecialchars($s_acc['bank_account_title'] ?? 'N/A') ?></div>
                                    <?php else: ?>
                                        <div class="small text-muted fst-italic">Not provided</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Inputs -->
                <div class="card border-0 shadow-sm rounded-3 bg-white p-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Payment Method Used <span class="text-danger">*</span></label>
                            <select name="seller_payment_method" class="form-select rounded-3 shadow-none" required>
                                <option value="jazzcash">JazzCash Mobile Wallet</option>
                                <option value="easypaisa">EasyPaisa Mobile Wallet</option>
                                <option value="bank_transfer">Direct Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Amount Sent (Product Main Price) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-success border-end-0"><?= $site_settings['currency'] ?? 'Rs.' ?></span>
                                <input type="number" name="seller_payment_amount" step="0.01"
                                       class="form-control fw-bold text-success bg-white"
                                       value="<?= htmlspecialchars($product_price) ?>" required>
                            </div>
                            <small class="text-muted">Main price of the purchased item.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Transaction ID / Reference (TDID) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-hash"></i></span>
                                <input type="text" name="seller_payment_transaction_id"
                                       class="form-control rounded-end-3"
                                       placeholder="Enter bank/wallet TID number" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Payment Transfer Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-calendar3"></i></span>
                                <input type="date" name="seller_payment_date"
                                       class="form-control rounded-end-3"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-dark">Payment Receipt / Screenshot (SS) <span class="text-danger">*</span></label>
                            <div class="ss-dropzone p-3 text-center" onclick="document.getElementById('receipt_input_<?= $o['id'] ?>').click();">
                                <i class="bi bi-cloud-arrow-up fs-2 text-success d-block mb-1"></i>
                                <span class="small fw-semibold text-dark d-block">Click here to upload payment receipt screenshot</span>
                                <span class="small text-muted" id="file_label_<?= $o['id'] ?>">Supported: JPG, PNG, WEBP (Max 5MB)</span>
                                <div id="preview_wrap_<?= $o['id'] ?>" class="mt-2 d-none">
                                    <img id="preview_img_<?= $o['id'] ?>" src="" class="img-thumbnail rounded-3" style="max-height:140px;">
                                </div>
                            </div>
                            <input type="file" name="seller_payment_receipt" id="receipt_input_<?= $o['id'] ?>"
                                   class="d-none" accept="image/*" required
                                   onchange="previewScreenshot(this, '<?= $o['id'] ?>')">
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <!-- Footer -->
            <div class="modal-footer pay-modal-footer border-0 px-4 py-3 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success fw-bold rounded-pill px-5 shadow-sm"
                        onclick="return confirm('Confirm payment sent to seller?');">
                    <i class="bi bi-send-check me-1"></i>Submit Payment Proof
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Sent Proof Modal -->
<?php if (!empty($o['seller_payment_receipt'])): ?>
<div class="modal fade" id="viewSentModal<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header border-0 bg-warning text-dark py-3 px-4">
                <h6 class="modal-title fw-bold mb-0">Sent Payment Proof (#<?= htmlspecialchars($o['order_number']) ?>)</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center bg-light">
                <img src="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['seller_payment_receipt']) ?>"
                     class="img-fluid rounded-3 shadow-sm" style="max-height:420px;" alt="Receipt">
                <div class="card border-0 rounded-3 mt-3 text-start p-3 bg-white shadow-sm small">
                    <div>Method: <strong><?= pm_label($o['seller_payment_method']) ?></strong></div>
                    <div>TID: <strong class="font-monospace text-primary"><?= htmlspecialchars($o['seller_payment_transaction_id']) ?></strong></div>
                    <div>Amount: <strong class="text-success"><?= $site_settings['currency'] . number_format($o['seller_payment_amount'], 2) ?></strong></div>
                    <div>Sent At: <strong><?= date('d M Y, h:i A', strtotime($o['seller_payment_sent_at'])) ?></strong></div>
                    <div class="text-warning mt-2 fw-bold"><i class="bi bi-clock me-1"></i>Waiting for seller to click "Mark as Received"</div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
                <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['seller_payment_receipt']) ?>"
                   target="_blank" class="btn btn-primary rounded-pill px-3">
                    <i class="bi bi-arrows-fullscreen me-1"></i>Full Screen
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endforeach; ?>

<!-- ── Section C Modals: View Delivered Receipt with Stamp ── -->
<?php foreach ($sec_c_rows as $o): ?>
<?php if (!empty($o['seller_payment_receipt'])): ?>
<div class="modal fade" id="viewDeliveredModal_<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden bg-white">
            <div class="modal-header border-0 bg-success text-white py-3 px-4">
                <h5 class="modal-title fw-bold text-white mb-0">
                    <i class="bi bi-patch-check me-2"></i>Confirmed Payment Receipt
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center bg-light">
                <div class="position-relative d-inline-block border rounded-3 overflow-hidden shadow-sm p-2 bg-white">
                    <img src="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['seller_payment_receipt']) ?>"
                         class="img-fluid rounded" style="max-height:440px;" alt="Receipt">
                    <!-- DELIVERED & CONFIRMED STAMP OVERLAY -->
                    <div class="delivered-stamp">
                        <div class="stamp-inner"><i class="bi bi-patch-check-fill me-1"></i>DELIVERED</div>
                        <div class="stamp-sub">PAID &amp; RECEIVED</div>
                        <div class="stamp-date">
                            <?= !empty($o['seller_payment_received_at'])
                                ? date('d M Y, h:i A', strtotime($o['seller_payment_received_at']))
                                : date('d M Y') ?>
                        </div>
                    </div>
                </div>
                <div class="card border-0 rounded-3 mt-3 text-start p-3 bg-white shadow-sm">
                    <div class="row g-2 small">
                        <div class="col-6"><span class="text-muted">Order:</span> <strong class="text-primary">#<?= htmlspecialchars($o['order_number']) ?></strong></div>
                        <div class="col-6"><span class="text-muted">Seller:</span> <strong><?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?></strong></div>
                        <div class="col-6"><span class="text-muted">Amount Sent:</span> <strong class="text-success"><?= $site_settings['currency'] . number_format($o['seller_payment_amount'], 2) ?></strong></div>
                        <div class="col-6"><span class="text-muted">Method:</span> <strong><?= pm_label($o['seller_payment_method']) ?></strong></div>
                        <div class="col-12"><span class="text-muted">Transaction ID:</span> <strong class="font-monospace text-dark"><?= htmlspecialchars($o['seller_payment_transaction_id']) ?></strong></div>
                        <div class="col-6"><span class="text-muted">Date Sent:</span> <strong><?= date('d M Y, h:i A', strtotime($o['seller_payment_sent_at'])) ?></strong></div>
                        <?php if (!empty($o['seller_payment_received_at'])): ?>
                        <div class="col-6"><span class="text-muted">Confirmed:</span> <strong class="text-success"><?= date('d M Y, h:i A', strtotime($o['seller_payment_received_at'])) ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 bg-white py-2 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-bs-dismiss="modal">Close</button>
                <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['seller_payment_receipt']) ?>"
                   target="_blank" class="btn btn-primary rounded-pill px-4">
                    <i class="bi bi-arrows-fullscreen me-1"></i>Full Screen
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<!-- ── Section E Modals: Refund Buyer ────────────────── -->
<?php foreach ($sec_e_rows as $o):
    $b_acc = $sec_e_accounts[$o['id']] ?? null;
?>
<div class="modal fade" id="refundModal<?= $o['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form action="" method="POST" enctype="multipart/form-data"
              class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="refund_buyer">
            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">

            <div class="modal-header border-0 px-4 py-3" style="background:linear-gradient(135deg,#dc3545 0%,#b02a37 100%);">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center text-white"
                         style="width:44px;height:44px;">
                        <i class="bi bi-arrow-return-left fs-4"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">Refund Buyer</h5>
                        <small class="text-white-50">Order #<?= htmlspecialchars($o['order_number']) ?> &bull; <?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body p-4" style="background:#f8fafc;">

                <!-- Buyer account details -->
                <div class="card border-0 shadow-sm rounded-3 mb-3 bg-white">
                    <div class="card-header bg-white border-bottom py-2 px-3">
                        <span class="fw-bold small text-dark"><i class="bi bi-wallet2 text-danger me-2"></i>Buyer Refund Account Details</span>
                    </div>
                    <div class="card-body p-3">
                        <?php if ($b_acc && (!empty($b_acc['jazzcash_number']) || !empty($b_acc['easypaisa_number']) || !empty($b_acc['bank_account_number']) || !empty($b_acc['bank_iban']))): ?>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= !empty($b_acc['jazzcash_number']) ? 'account-box-jc' : 'account-box-empty' ?>">
                                    <span class="badge bg-danger mb-1">JazzCash</span>
                                    <?php if (!empty($b_acc['jazzcash_number'])): ?>
                                        <div class="fw-bold font-monospace small"><?= htmlspecialchars($b_acc['jazzcash_number']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($b_acc['jazzcash_name'] ?: 'N/A') ?></div>
                                    <?php else: ?><div class="small text-muted fst-italic">Not provided</div><?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= !empty($b_acc['easypaisa_number']) ? 'account-box-ep' : 'account-box-empty' ?>">
                                    <span class="badge bg-success mb-1">EasyPaisa</span>
                                    <?php if (!empty($b_acc['easypaisa_number'])): ?>
                                        <div class="fw-bold font-monospace small"><?= htmlspecialchars($b_acc['easypaisa_number']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($b_acc['easypaisa_name'] ?: 'N/A') ?></div>
                                    <?php else: ?><div class="small text-muted fst-italic">Not provided</div><?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-2 rounded-3 border h-100 <?= (!empty($b_acc['bank_account_number']) || !empty($b_acc['bank_iban'])) ? 'account-box-bank' : 'account-box-empty' ?>">
                                    <span class="badge bg-primary mb-1"><?= htmlspecialchars($b_acc['bank_name'] ?? 'Bank') ?></span>
                                    <?php if (!empty($b_acc['bank_account_number']) || !empty($b_acc['bank_iban'])): ?>
                                        <div class="fw-bold font-monospace small text-truncate"><?= htmlspecialchars($b_acc['bank_account_number'] ?? $b_acc['bank_iban']) ?></div>
                                        <div class="text-muted small"><?= htmlspecialchars($b_acc['bank_account_title'] ?? 'N/A') ?></div>
                                    <?php else: ?><div class="small text-muted fst-italic">Not provided</div><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                            <div class="small text-muted fst-italic">Buyer has not saved account details yet.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Refund form inputs -->
                <div class="card border-0 shadow-sm rounded-3 bg-white p-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Refund Method <span class="text-danger">*</span></label>
                            <select name="buyer_refund_method" class="form-select rounded-3 shadow-none" required>
                                <option value="jazzcash">JazzCash Mobile Wallet</option>
                                <option value="easypaisa">EasyPaisa Mobile Wallet</option>
                                <option value="bank_transfer">Direct Bank Transfer</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Refund Amount <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold text-danger border-end-0"><?= $site_settings['currency'] ?? 'Rs.' ?></span>
                                <input type="number" name="buyer_refund_amount" step="0.01"
                                       class="form-control fw-bold text-danger bg-white"
                                       value="<?= htmlspecialchars($o['grand_total']) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Transaction ID / Reference Number <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-hash"></i></span>
                                <input type="text" name="buyer_refund_transaction_id"
                                       class="form-control rounded-end-3"
                                       placeholder="e.g. REF-1238912" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-dark">Refund Date <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted"><i class="bi bi-calendar3"></i></span>
                                <input type="date" name="buyer_refund_date"
                                       class="form-control rounded-end-3"
                                       value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-dark">Upload Refund Receipt Screenshot <span class="text-danger">*</span></label>
                            <div class="ss-dropzone p-3 text-center" onclick="document.getElementById('refund_receipt_<?= $o['id'] ?>').click();">
                                <i class="bi bi-cloud-arrow-up fs-2 text-danger d-block mb-1"></i>
                                <span class="small fw-semibold text-dark d-block">Click here to upload refund receipt screenshot</span>
                                <span class="small text-muted" id="refund_label_<?= $o['id'] ?>">Supported: JPG, PNG, WEBP (Max 5MB)</span>
                                <div id="refund_preview_wrap_<?= $o['id'] ?>" class="mt-2 d-none">
                                    <img id="refund_preview_img_<?= $o['id'] ?>" src="" class="img-thumbnail rounded-3" style="max-height:140px;">
                                </div>
                            </div>
                            <input type="file" name="buyer_refund_receipt" id="refund_receipt_<?= $o['id'] ?>"
                                   class="d-none" accept="image/*" required
                                   onchange="previewRefund(this, '<?= $o['id'] ?>')">
                        </div>
                    </div>
                </div>

            </div><!-- /modal-body -->

            <div class="modal-footer border-0 px-4 py-3 bg-white d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger fw-bold rounded-pill px-5 shadow-sm"
                        onclick="return confirm('Confirm refund sent to buyer?');">
                    <i class="bi bi-check-circle me-1"></i>Confirm Refund Sent
                </button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- ══════════════════════════════════════════════════════
     JAVASCRIPT
══════════════════════════════════════════════════════ -->
<script>
function previewScreenshot(input, id) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const label = document.getElementById('file_label_' + id);
        if (label) label.innerText = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img  = document.getElementById('preview_img_' + id);
            const wrap = document.getElementById('preview_wrap_' + id);
            if (img && wrap) { img.src = e.target.result; wrap.classList.remove('d-none'); }
        };
        reader.readAsDataURL(file);
    }
}

function previewRefund(input, id) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const label = document.getElementById('refund_label_' + id);
        if (label) label.innerText = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        const reader = new FileReader();
        reader.onload = function(e) {
            const img  = document.getElementById('refund_preview_img_' + id);
            const wrap = document.getElementById('refund_preview_wrap_' + id);
            if (img && wrap) { img.src = e.target.result; wrap.classList.remove('d-none'); }
        };
        reader.readAsDataURL(file);
    }
}
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
