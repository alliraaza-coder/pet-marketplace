<?php
/**
 * Admin Escrow Management
 * Phase 8 ?" Full Manual Payment Workflow
 */

require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$admin_id = $_SESSION['user_id'];

// "?"?"? POST ACTION HANDLING "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
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
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin approved payment", "Payment received and placed in escrow.", $admin_id);
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
            if (!$chk || $chk['payment_status'] !== 'held') {
                throw new Exception("Order is not in escrow.");
            }
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
            if ($chk['seller_delivered'] != 1) throw new Exception("Seller has not marked as delivered yet.");
            if ($chk['buyer_received'] != 1) throw new Exception("Buyer has not confirmed receipt yet.");
            if ($chk['admin_verified'] != 1) throw new Exception("Admin delivery verification is required before releasing.");
            if (!empty($chk['seller_payment_sent_at'])) throw new Exception("Seller has already been paid for this order.");

            $file = $_FILES['seller_payment_receipt'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) throw new Exception("Invalid file type. Only JPG, JPEG, PNG allowed.");
            if ($file['size'] > 5242880) throw new Exception("File size must be under 5MB.");
            
            $new_name = 'seller_pay_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = __DIR__ . '/../assets/images/payments/' . $new_name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) throw new Exception("Failed to save payout proof image.");

            $method = sanitize_input($_POST['seller_payment_method']);
            $amount = (float)$_POST['seller_payment_amount'];
            $tx_id = sanitize_input($_POST['seller_payment_transaction_id']);
            $date = sanitize_input($_POST['seller_payment_date']) . ' ' . date('H:i:s');
            $now = date('Y-m-d H:i:s');

            $upd = $conn->prepare("UPDATE orders SET payment_status = 'seller_payment_sent', seller_payment_method = ?, seller_payment_amount = ?, seller_payment_transaction_id = ?, seller_payment_sent_at = ?, seller_payment_receipt = ?, seller_payment_sent_by = ? WHERE id = ?");
            $upd->bind_param("sdsssii", $method, $amount, $tx_id, $now, $new_name, $admin_id, $order_id);
            $upd->execute();

            $tx = $conn->prepare("INSERT INTO transactions (order_id, user_id, seller_id, transaction_type, amount, payment_method, status, reference_no, created_at) VALUES (?, ?, ?, 'release', ?, ?, 'seller_payment_sent', ?, ?)");
            $tx->bind_param("iiidsss", $order_id, $chk['user_id'], $chk['seller_id'], $amount, $method, $tx_id, $now);
            $tx->execute();

            log_admin_activity($conn, $admin_id, "Paid Seller", "Sent seller payment for Order #{$chk['order_number']}");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin sent payment to seller", "Waiting for seller to confirm receipt.", $admin_id);

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
            if (!in_array($chk['payment_status'], ['held', 'refund_submitted'])) throw new Exception("Payment must be held to refund.");

            $file = $_FILES['buyer_refund_receipt'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) throw new Exception("Invalid file type.");
            
            $new_name = 'buyer_refund_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $dest = __DIR__ . '/../assets/images/payments/' . $new_name;
            if (!move_uploaded_file($file['tmp_name'], $dest)) throw new Exception("Failed to save refund proof image.");

            $method = sanitize_input($_POST['buyer_refund_method']);
            $amount = (float)$_POST['buyer_refund_amount'];
            $tx_id = sanitize_input($_POST['buyer_refund_transaction_id']);
            $now = date('Y-m-d H:i:s');

            $upd = $conn->prepare("UPDATE orders SET payment_status = 'refund_sent', order_status = 'cancelled', buyer_refund_method = ?, buyer_refund_amount = ?, buyer_refund_transaction_id = ?, buyer_refund_sent_at = ?, buyer_refund_receipt = ?, buyer_refund_sent_by = ? WHERE id = ?");
            $upd->bind_param("sdsssii", $method, $amount, $tx_id, $now, $new_name, $admin_id, $order_id);
            $upd->execute();

            $tx = $conn->prepare("INSERT INTO transactions (order_id, user_id, transaction_type, amount, payment_method, status, reference_no, created_at) VALUES (?, ?, 'refund', ?, ?, 'refund_sent', ?, ?)");
            $tx->bind_param("iidsss", $order_id, $chk['user_id'], $amount, $method, $tx_id, $now);
            $tx->execute();

            $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $order_id");
            $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold','active',status) WHERE id = ?");
            while ($item = $items->fetch_assoc()) {
                $stock_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                $stock_stmt->execute();
            }

            log_admin_activity($conn, $admin_id, "Refunded Buyer", "Sent refund for Order #{$chk['order_number']}");
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Admin sent refund to buyer", "Waiting for buyer to confirm receipt.", $admin_id);
            
            $conn->commit();
            $_SESSION['success'] = "Refund sent to buyer successfully. Waiting for buyer confirmation.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Refund failed: " . $e->getMessage();
        }
    }
}

// "?"?"? DATA QUERIES "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$stats_query = "SELECT
  COUNT(CASE WHEN payment_status = 'held' THEN 1 END) AS total_held,
  COALESCE(SUM(CASE WHEN payment_status = 'held' THEN grand_total END), 0) AS total_held_amount,
  COALESCE(SUM(CASE WHEN payment_status = 'seller_payment_sent' THEN seller_payment_amount END), 0) AS total_paid_sellers,
  COALESCE(SUM(CASE WHEN payment_status = 'seller_payment_received' THEN seller_payment_amount END), 0) AS total_confirmed_payouts,
  COALESCE(SUM(CASE WHEN payment_status IN ('refund_sent','refund_received') THEN buyer_refund_amount END), 0) AS total_refunds_sent,
  COUNT(CASE WHEN payment_status = 'payment_submitted' THEN 1 END) AS total_pending_verification,
  COUNT(CASE WHEN payment_status = 'held' AND seller_delivered=1 AND buyer_received=1 AND admin_verified=1 AND seller_payment_sent_at IS NULL THEN 1 END) AS sellers_pending_payment,
  COUNT(CASE WHEN payment_status = 'held' AND (dispute_status=1 OR (order_status='cancelled' AND buyer_refund_sent_at IS NULL)) THEN 1 END) AS refunds_pending
FROM orders";
$stats = $conn->query($stats_query)->fetch_assoc();

$base_query = "SELECT o.*, b.first_name AS buyer_fn, b.last_name AS buyer_ln, s.first_name AS seller_fn, s.last_name AS seller_ln 
               FROM orders o 
               JOIN users b ON o.user_id = b.id 
               JOIN order_items oi ON o.id = oi.order_id 
               JOIN users s ON oi.seller_id = s.id ";

$sec_a = $conn->query($base_query . "WHERE o.payment_status = 'payment_submitted' GROUP BY o.id ORDER BY o.created_at DESC");
$sec_b = $conn->query($base_query . "WHERE o.payment_status = 'held' AND o.seller_delivered=1 AND o.buyer_received=1 AND o.admin_verified=1 AND o.seller_payment_sent_at IS NULL GROUP BY o.id ORDER BY o.created_at DESC");
$sec_c = $conn->query($base_query . "WHERE o.payment_status = 'seller_payment_sent' GROUP BY o.id ORDER BY o.seller_payment_sent_at DESC");
$sec_d = $conn->query($base_query . "WHERE o.payment_status = 'seller_payment_received' GROUP BY o.id ORDER BY o.seller_payment_received_at DESC");
$sec_e = $conn->query($base_query . "WHERE o.payment_status IN ('held','refund_submitted') AND o.order_status='cancelled' AND o.buyer_refund_sent_at IS NULL GROUP BY o.id ORDER BY o.created_at DESC");
$sec_f = $conn->query($base_query . "WHERE o.payment_status IN ('refund_sent','refund_received') GROUP BY o.id ORDER BY o.buyer_refund_sent_at DESC");
$sec_g = $conn->query($base_query . "WHERE o.payment_status = 'held' GROUP BY o.id ORDER BY o.created_at DESC");

// "?"?"? HTML STRUCTURE "?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?"?
$page_title = "Payment & Escrow Management";
$page_heading = "Payment Management";
include __DIR__ . '/partials/header.php';

function pm_label($m) {
    $labels = ['jazzcash'=>'JazzCash', 'easypaisa'=>'EasyPaisa', 'bank_transfer'=>'Bank Transfer'];
    return $labels[$m] ?? ucfirst($m);
}
?>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card bg-warning text-dark border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6>Pending Buyer Verification</h6>
                <h3 class="fw-bold mb-0"><?= number_format($stats['total_pending_verification']) ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-info text-white border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6>Active Escrow Funds</h6>
                <h3 class="fw-bold mb-0"><?= $site_settings['currency'] . number_format($stats['total_held_amount']) ?> (<?= number_format($stats['total_held']) ?> orders)</h3>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-success text-white border-0 shadow-sm rounded-4 h-100">
            <div class="card-body">
                <h6>Paid to Sellers</h6>
                <h3 class="fw-bold mb-0"><?= $site_settings['currency'] . number_format($stats['total_paid_sellers']) ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- SECTION A: Buyer Payments Pending Verification -->
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
                    <?php if ($sec_a->num_rows > 0): while($o = $sec_a->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 fw-bold">#<?= $o['order_number'] ?></td>
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
                            <button type="button" class="btn btn-sm btn-danger rounded-pill px-3" data-bs-toggle="modal" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $o['id'] ?>">Reject</button>
                        </td>
                    </tr>
                    <!-- Reject Modal -->
                    <div class="modal fade" id="rejectModal<?= $o['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <form action="" method="POST" class="modal-content">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="reject_payment">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <div class="modal-header"><h5 class="modal-title">Reject Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <textarea name="reject_note" class="form-control" placeholder="Reason for rejection" required></textarea>
                                </div>
                                <div class="modal-footer"><button type="submit" class="btn btn-danger">Confirm Reject</button></div>
                            </form>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No pending buyer payments.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION B: Seller Payments Pending -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-primary"><i class="bi bi-wallet2 me-2"></i>Seller Payments Pending</h5>
        <p class="text-muted small">Orders successfully delivered and verified. Ready to pay sellers.</p>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Seller</th>
                        <th>Total Amount</th>
                        <th>Delivery Confirmed</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sec_b->num_rows > 0): while($o = $sec_b->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 fw-bold">#<?= $o['order_number'] ?></td>
                        <td><?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?></td>
                        <td class="fw-bold text-success"><?= $site_settings['currency'].number_format($o['grand_total'],2) ?></td>
                        <td><span class="badge bg-success">Verified</span></td>
                        <td class="pe-4 text-end">
                            <button type="button" class="btn btn-sm btn-primary rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#payModal<?= $o['id'] ?>">Pay Seller</button>
                        </td>
                    </tr>
                    <!-- Pay Modal -->
                    <div class="modal fade" id="payModal<?= $o['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <form action="" method="POST" enctype="multipart/form-data" class="modal-content">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="pay_seller">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <div class="modal-header"><h5 class="modal-title">Pay Seller</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body text-start">
                                    <div class="mb-3">
                                        <label>Method</label>
                                        <select name="seller_payment_method" class="form-select" required>
                                            <option value="jazzcash">JazzCash</option>
                                            <option value="easypaisa">EasyPaisa</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label>Amount</label>
                                        <input type="number" name="seller_payment_amount" step="0.01" class="form-control" value="<?= $o['grand_total'] ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Transaction ID / Reference</label>
                                        <input type="text" name="seller_payment_transaction_id" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Payment Date</label>
                                        <input type="date" name="seller_payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Upload Receipt/Screenshot</label>
                                        <input type="file" name="seller_payment_receipt" class="form-control" accept="image/*" required>
                                    </div>
                                </div>
                                <div class="modal-footer"><button type="submit" class="btn btn-success" onclick="return confirm('Confirm payment sent to seller?')">Confirm Payment Sent</button></div>
                            </form>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No pending seller payments.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION C/D: Seller Payments Sent & Confirmed -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-success"><i class="bi bi-check-all me-2"></i>Seller Payments Processed</h5>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Seller</th>
                        <th>Amount Sent</th>
                        <th>Method</th>
                        <th>Ref / Date</th>
                        <th>Receipt</th>
                        <th class="pe-4 text-end">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $processed = [];
                    while($o = $sec_c->fetch_assoc()) $processed[] = $o;
                    while($o = $sec_d->fetch_assoc()) $processed[] = $o;
                    if (count($processed) > 0): foreach($processed as $o): 
                    ?>
                    <tr>
                        <td class="ps-4 fw-bold">#<?= $o['order_number'] ?></td>
                        <td><?= htmlspecialchars($o['seller_fn'].' '.$o['seller_ln']) ?></td>
                        <td class="fw-bold text-success"><?= $site_settings['currency'].number_format($o['seller_payment_amount'],2) ?></td>
                        <td><?= pm_label($o['seller_payment_method']) ?></td>
                        <td><small><?= htmlspecialchars($o['seller_payment_transaction_id']) ?><br><?= date('d M Y', strtotime($o['seller_payment_sent_at'])) ?></small></td>
                        <td>
                            <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($o['seller_payment_receipt']) ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill">View</a>
                        </td>
                        <td class="pe-4 text-end">
                            <?php if($o['payment_status'] === 'seller_payment_received'): ?>
                            <span class="badge bg-success">Confirmed</span><br><small><?= date('d M y', strtotime($o['seller_payment_received_at'])) ?></small>
                            <?php else: ?>
                            <span class="badge bg-warning text-dark">Awaiting Confirmation</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">No processed payments.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SECTION E: Refunds Pending -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-header bg-white border-0 pt-4 pb-0 px-4">
        <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-arrow-return-left me-2"></i>Buyer Refunds Pending</h5>
    </div>
    <div class="card-body p-0 mt-3">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Total Amount</th>
                        <th>Reason</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sec_e->num_rows > 0): while($o = $sec_e->fetch_assoc()): ?>
                    <tr>
                        <td class="ps-4 fw-bold">#<?= $o['order_number'] ?></td>
                        <td><?= htmlspecialchars($o['buyer_fn'].' '.$o['buyer_ln']) ?></td>
                        <td class="fw-bold text-success"><?= $site_settings['currency'].number_format($o['grand_total'],2) ?></td>
                        <td><span class="badge bg-danger">Cancelled</span></td>
                        <td class="pe-4 text-end">
                            <button type="button" class="btn btn-sm btn-danger rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#refundModal<?= $o['id'] ?>">Refund Buyer</button>
                        </td>
                    </tr>
                    <!-- Refund Modal -->
                    <div class="modal fade" id="refundModal<?= $o['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <form action="" method="POST" enctype="multipart/form-data" class="modal-content">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="refund_buyer">
                                <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                <div class="modal-header"><h5 class="modal-title">Refund Buyer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body text-start">
                                    <div class="mb-3">
                                        <label>Method</label>
                                        <select name="buyer_refund_method" class="form-select" required>
                                            <option value="jazzcash">JazzCash</option>
                                            <option value="easypaisa">EasyPaisa</option>
                                            <option value="bank_transfer">Bank Transfer</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label>Amount</label>
                                        <input type="number" name="buyer_refund_amount" step="0.01" class="form-control" value="<?= $o['grand_total'] ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Transaction ID / Reference</label>
                                        <input type="text" name="buyer_refund_transaction_id" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Refund Date</label>
                                        <input type="date" name="buyer_refund_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label>Upload Receipt/Screenshot</label>
                                        <input type="file" name="buyer_refund_receipt" class="form-control" accept="image/*" required>
                                    </div>
                                </div>
                                <div class="modal-footer"><button type="submit" class="btn btn-danger" onclick="return confirm('Confirm refund sent to buyer?')">Confirm Refund Sent</button></div>
                            </form>
                        </div>
                    </div>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No pending refunds.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>