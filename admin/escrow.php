<?php
/**
 * Admin Escrow Management
 * Phase 3.3 — FIXED: All POST logic runs BEFORE any HTML output
 */

// ─── 1. BOOTSTRAP: config + auth (no HTML yet) ──────────────────────────────
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$admin_id = $_SESSION['user_id'];

// ─── 2. POST ACTION HANDLING (must be before ANY output) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    $action   = sanitize_input($_POST['action']);
    $order_id = (int)$_POST['order_id'];

    // ── APPROVE PAYMENT ──────────────────────────────────────────────────────
    if ($action === 'approve_payment') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("SELECT payment_status FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk || $chk['payment_status'] !== 'payment_submitted') {
                throw new Exception("This order does not have a submitted payment to verify.");
            }
            // Move payment to escrow (held) and order to pending so seller can see it
            $upd = $conn->prepare("UPDATE orders SET payment_status = 'held', order_status = 'pending' WHERE id = ?");
            $upd->bind_param("i", $order_id);
            $upd->execute();

            log_admin_activity($conn, $admin_id, "Approved Payment", "Payment verified for Order ID: $order_id. Escrow activated.");
            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Admin approved payment", "Payment received and placed in escrow.", $admin_id);
            }
            $conn->commit();
            $_SESSION['success'] = "Payment verified! Funds are now held in Escrow. Seller can now see and process the order.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Approval failed: " . $e->getMessage();
        }

    // ── REJECT PAYMENT ───────────────────────────────────────────────────────
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
            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Admin rejected payment", "Reason: $reject_note", $admin_id);
            }
            $conn->commit();
            $_SESSION['success'] = "Payment proof rejected. The buyer must resubmit correct proof.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Rejection failed: " . $e->getMessage();
        }

    // ── VERIFY DELIVERY (sets admin_verified = 1) ────────────────────────────
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
            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Admin verified delivery", "All delivery conditions met. Admin approved.", $admin_id);
            }
            $conn->commit();
            $_SESSION['success'] = "Delivery verified! Release Payment is now available.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Verification failed: " . $e->getMessage();
        }

    // ── RELEASE PAYMENT ──────────────────────────────────────────────────────
    } elseif ($action === 'release') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("
                SELECT o.*, 
                       oi.seller_id,
                       u.first_name AS buyer_fn, u.last_name AS buyer_ln,
                       s.first_name AS seller_fn, s.last_name AS seller_ln
                FROM orders o
                JOIN users u ON o.user_id = u.id
                JOIN order_items oi ON o.id = oi.order_id
                JOIN users s ON oi.seller_id = s.id
                WHERE o.id = $order_id
                LIMIT 1
            ")->fetch_assoc();

            // Server-side validation — all 4 conditions must be true
            if (!$chk) throw new Exception("Order not found.");
            if ($chk['payment_status'] !== 'held')   throw new Exception("Payment is not held in escrow.");
            if ($chk['seller_delivered'] != 1)        throw new Exception("Seller has not marked as delivered yet.");
            if ($chk['buyer_received'] != 1)          throw new Exception("Buyer has not confirmed receipt yet.");
            if ($chk['admin_verified'] != 1)          throw new Exception("Admin delivery verification is required before releasing.");

            $prev_payment_status = $chk['payment_status'];
            $prev_order_status   = $chk['order_status'];
            $now = date('Y-m-d H:i:s');

            // Update order to completed + released
            $upd = $conn->prepare("
                UPDATE orders 
                SET payment_status     = 'released',
                    order_status       = 'completed',
                    payment_released_at = ?,
                    completed_at        = ?
                WHERE id = ?
            ");
            $upd->bind_param("ssi", $now, $now, $order_id);
            $upd->execute();

            // Insert full transaction record
            $tx = $conn->prepare("
                INSERT INTO transactions 
                    (order_id, user_id, seller_id, transaction_type, amount, payment_method, status, reference_no, created_at)
                VALUES (?, ?, ?, 'release', ?, ?, 'released', ?, ?)
            ");
            $tx->bind_param("iiidsss", $order_id, $chk['user_id'], $chk['seller_id'], $chk['grand_total'], $chk['payment_method'], $chk['order_number'], $now);
            $tx->execute();

            // Admin activity log
            log_admin_activity(
                $conn, $admin_id,
                "Released Escrow Payment",
                "Order #{$chk['order_number']} — Amount: {$chk['grand_total']} — Buyer: {$chk['buyer_fn']} {$chk['buyer_ln']} — Seller: {$chk['seller_fn']} {$chk['seller_ln']}"
            );

            // Order audit log
            if (function_exists('log_order_audit')) {
                log_order_audit(
                    $conn, $order_id,
                    "Payment Released",
                    "Payment released to seller. Prev payment_status: $prev_payment_status → released. Prev order_status: $prev_order_status → completed. Amount: {$chk['grand_total']}",
                    $admin_id
                );
            }

            $conn->commit();
            $_SESSION['success'] = "Payment of {$site_settings['currency']}{$chk['grand_total']} successfully released to the seller. Order #{$chk['order_number']} is now completed.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Release failed: " . $e->getMessage();
        }

    // ── REFUND ───────────────────────────────────────────────────────────────
    } elseif ($action === 'refund') {
        $conn->begin_transaction();
        try {
            $chk = $conn->query("SELECT payment_status, grand_total, order_number FROM orders WHERE id = $order_id")->fetch_assoc();
            if (!$chk) throw new Exception("Order not found.");
            if ($chk['payment_status'] === 'released') throw new Exception("Cannot refund an order that has already been released.");
            if ($chk['payment_status'] === 'refunded') throw new Exception("Order is already refunded.");

            $upd = $conn->prepare("UPDATE orders SET payment_status = 'refunded', order_status = 'cancelled' WHERE id = ?");
            $upd->bind_param("i", $order_id);
            $upd->execute();

            // Restore stock
            $items = $conn->query("SELECT product_id, quantity FROM order_items WHERE order_id = $order_id");
            $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold','active',status) WHERE id = ?");
            while ($item = $items->fetch_assoc()) {
                $stock_stmt->bind_param("ii", $item['quantity'], $item['product_id']);
                $stock_stmt->execute();
            }

            // Transaction record
            $tx = $conn->prepare("INSERT INTO transactions (order_id, transaction_type, amount, status) VALUES (?, 'refund', ?, 'completed')");
            $tx->bind_param("id", $order_id, $chk['grand_total']);
            $tx->execute();

            log_admin_activity($conn, $admin_id, "Refunded Escrow", "Refunded Order #{$chk['order_number']}. Amount: {$chk['grand_total']}");
            if (function_exists('log_order_audit')) {
                log_order_audit($conn, $order_id, "Admin refunded order", "Escrow closed. Amount: {$chk['grand_total']}", $admin_id);
            }
            $conn->commit();
            $_SESSION['success'] = "Order #{$chk['order_number']} has been refunded and cancelled.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Refund failed: " . $e->getMessage();
        }
    }

    // ── REDIRECT (no HTML has been output yet, so this always works) ─────────
    header('Location: escrow.php');
    exit;
}

// ─── 3. DATA QUERIES (read-only, before HTML) ────────────────────────────────
$stats = $conn->query("
    SELECT 
        COUNT(CASE WHEN payment_status = 'held' THEN 1 END) AS total_held,
        COALESCE(SUM(CASE WHEN payment_status = 'held' THEN grand_total END), 0) AS total_held_amount,
        COALESCE(SUM(CASE WHEN payment_status = 'released' THEN grand_total END), 0) AS total_released_amount,
        COALESCE(SUM(CASE WHEN payment_status = 'refunded' THEN grand_total END), 0) AS total_refunded_amount,
        COUNT(CASE WHEN payment_status = 'payment_submitted' THEN 1 END) AS total_pending_verification
    FROM orders
")->fetch_assoc();

// Orders awaiting payment proof verification
$pending_sql = "
    SELECT o.id, o.order_number, o.grand_total, o.payment_method,
           o.payment_reference, o.payment_proof, o.created_at,
           b.first_name AS buyer_fn, b.last_name AS buyer_ln,
           s.first_name AS seller_fn, s.last_name AS seller_ln
    FROM orders o
    JOIN users b ON o.user_id = b.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users s ON oi.seller_id = s.id
    WHERE o.payment_status = 'payment_submitted'
    GROUP BY o.id
    ORDER BY o.created_at ASC";
$pending_payments = $conn->query($pending_sql);

// Active escrow orders (held)
$escrow_sql = "
    SELECT o.id, o.order_number, o.grand_total, o.order_status, o.payment_status,
           o.payment_method, o.seller_delivered, o.buyer_received, o.admin_verified,
           o.created_at,
           b.first_name AS buyer_fn, b.last_name AS buyer_ln,
           s.first_name AS seller_fn, s.last_name AS seller_ln
    FROM orders o
    JOIN users b ON o.user_id = b.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users s ON oi.seller_id = s.id
    WHERE o.payment_status = 'held'
    GROUP BY o.id
    ORDER BY o.created_at ASC";
$escrow_orders = $conn->query($escrow_sql);

// ─── 4. HTML OUTPUT STARTS HERE ──────────────────────────────────────────────
$page_title   = "Escrow Management";
$page_heading = "Manage Escrow Payments";
include __DIR__ . '/partials/header.php';
?>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100" style="background: linear-gradient(135deg,#dc3545,#a71d2a); color:#fff;">
            <h6 class="mb-1" style="opacity:.8;">Pending Verification</h6>
            <h3 class="fw-bold mb-0"><?php echo (int)($stats['total_pending_verification'] ?? 0); ?></h3>
            <small style="opacity:.7;">Payment proofs awaiting review</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100" style="background: linear-gradient(135deg,#ffc107,#e0a800); color:#1a1a1a;">
            <h6 class="mb-1" style="opacity:.8;">Escrow Balance</h6>
            <h3 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($stats['total_held_amount'], 2); ?></h3>
            <small style="opacity:.7;"><?php echo $stats['total_held']; ?> active order(s)</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100" style="background: linear-gradient(135deg,#198754,#146c43); color:#fff;">
            <h6 class="mb-1" style="opacity:.8;">Total Released</h6>
            <h3 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($stats['total_released_amount'], 2); ?></h3>
            <small style="opacity:.7;">All time</small>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100" style="background: linear-gradient(135deg,#0dcaf0,#0aa2c0); color:#1a1a1a;">
            <h6 class="mb-1" style="opacity:.8;">Total Refunded</h6>
            <h3 class="fw-bold mb-0"><?php echo $site_settings['currency'] . number_format($stats['total_refunded_amount'], 2); ?></h3>
            <small style="opacity:.7;">All time</small>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 1: Payment Verification Queue
═══════════════════════════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1 text-danger"><i class="bi bi-shield-exclamation me-2"></i>Payment Verification Queue</h4>
        <p class="text-muted small mb-0">Review buyer payment proofs. Seller will NOT see the order until you approve.</p>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-5">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Seller</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Reference / Proof</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($pending_payments && $pending_payments->num_rows > 0): ?>
                        <?php while ($p = $pending_payments->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 fw-bold">
                                <a href="order_details.php?id=<?php echo $p['id']; ?>" class="text-primary text-decoration-none">
                                    #<?php echo htmlspecialchars($p['order_number']); ?>
                                </a>
                                <div class="small text-muted"><?php echo date('d M Y', strtotime($p['created_at'])); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($p['buyer_fn'] . ' ' . $p['buyer_ln']); ?></td>
                            <td><?php echo htmlspecialchars($p['seller_fn'] . ' ' . $p['seller_ln']); ?></td>
                            <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($p['grand_total'], 2); ?></td>
                            <td><span class="badge bg-info text-dark rounded-pill"><?php echo strtoupper(str_replace('_', ' ', $p['payment_method'])); ?></span></td>
                            <td>
                                <div class="small"><strong>Ref:</strong> <?php echo htmlspecialchars($p['payment_reference'] ?: 'N/A'); ?></div>
                                <?php if ($p['payment_proof']): ?>
                                    <a href="../assets/images/<?php echo htmlspecialchars($p['payment_proof']); ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill mt-1 px-2">
                                        <i class="bi bi-image me-1"></i>View Proof
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">No image uploaded</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-4 text-end">
                                <div class="d-flex justify-content-end gap-2">
                                    <form action="escrow.php" method="POST" onsubmit="return confirm('Approve payment and activate escrow? Seller will now see this order.');">
                                        <input type="hidden" name="action" value="approve_payment">
                                        <input type="hidden" name="order_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold rounded-pill">
                                            <i class="bi bi-check-circle me-1"></i>Approve
                                        </button>
                                    </form>
                                    <form action="escrow.php" method="POST" onsubmit="return confirm('Reject this payment? Buyer will need to resubmit.');">
                                        <input type="hidden" name="action" value="reject_payment">
                                        <input type="hidden" name="order_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger fw-bold rounded-pill">
                                            <i class="bi bi-x-circle me-1"></i>Reject
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>No pending payment proofs to review.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SECTION 2: Active Escrow Orders (payment_status = held)
═══════════════════════════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-shield-lock me-2 text-warning"></i>Active Escrow Orders</h4>
        <p class="text-muted small mb-0">Manage escrow stages — Verify delivery and release payments to sellers.</p>
    </div>
    <a href="orders.php" class="btn btn-outline-secondary rounded-pill fw-bold">View All Orders</a>
</div>

<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Order #</th>
                        <th>Buyer</th>
                        <th>Seller</th>
                        <th>Amount</th>
                        <th>Escrow Stage</th>
                        <th>Conditions</th>
                        <th class="pe-4 text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($escrow_orders && $escrow_orders->num_rows > 0): ?>
                        <?php while ($o = $escrow_orders->fetch_assoc()):
                            $sd = (bool)$o['seller_delivered'];
                            $br = (bool)$o['buyer_received'];
                            $av = (bool)$o['admin_verified'];
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold">
                                <a href="order_details.php?id=<?php echo $o['id']; ?>" class="text-primary text-decoration-none">
                                    #<?php echo htmlspecialchars($o['order_number']); ?>
                                </a>
                                <div class="small text-muted"><?php echo date('d M Y', strtotime($o['created_at'])); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($o['buyer_fn'] . ' ' . $o['buyer_ln']); ?></td>
                            <td><?php echo htmlspecialchars($o['seller_fn'] . ' ' . $o['seller_ln']); ?></td>
                            <td class="fw-bold text-warning"><?php echo $site_settings['currency'] . number_format($o['grand_total'], 2); ?></td>

                            <!-- Escrow Stage Badge -->
                            <td>
                                <?php if (!$sd): ?>
                                    <span class="badge bg-secondary rounded-pill px-2 py-1"><i class="bi bi-clock me-1"></i>Waiting for Delivery</span>
                                <?php elseif ($sd && !$br): ?>
                                    <span class="badge bg-info text-dark rounded-pill px-2 py-1"><i class="bi bi-hourglass me-1"></i>Awaiting Buyer Confirmation</span>
                                <?php elseif ($sd && $br && !$av): ?>
                                    <span class="badge bg-warning text-dark rounded-pill px-2 py-1"><i class="bi bi-check2-all me-1"></i>Ready to Verify</span>
                                <?php else: ?>
                                    <span class="badge bg-success rounded-pill px-2 py-1"><i class="bi bi-unlock me-1"></i>Ready for Release</span>
                                <?php endif; ?>
                            </td>

                            <!-- Conditions Checklist -->
                            <td>
                                <div class="small lh-lg">
                                    <div><?php echo $sd ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>'; ?> Seller Delivered</div>
                                    <div><?php echo $br ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>'; ?> Buyer Confirmed</div>
                                    <div><?php echo $av ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>'; ?> Admin Verified</div>
                                </div>
                            </td>

                            <!-- Dynamic Actions -->
                            <td class="pe-4 text-end">
                                <div class="d-flex flex-column align-items-end gap-2">

                                    <?php if ($sd && $br && !$av): ?>
                                    <!-- VERIFY DELIVERY button -->
                                    <form action="escrow.php" method="POST" onsubmit="return confirm('Verify delivery for this order? This will allow you to release payment.');">
                                        <input type="hidden" name="action" value="verify">
                                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-primary fw-bold rounded-pill">
                                            <i class="bi bi-check2-square me-1"></i>Verify Delivery
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if ($sd && $br && $av): ?>
                                    <!-- RELEASE PAYMENT button (all conditions met) -->
                                    <form action="escrow.php" method="POST" onsubmit="return confirm('CONFIRM: Release payment to seller? This action cannot be undone.');">
                                        <input type="hidden" name="action" value="release">
                                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success fw-bold rounded-pill">
                                            <i class="bi bi-unlock-fill me-1"></i>Release Payment
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <!-- REFUND button (always available while in escrow) -->
                                    <form action="escrow.php" method="POST" onsubmit="return confirm('Refund buyer and cancel order? Stock will be restored.');">
                                        <input type="hidden" name="action" value="refund">
                                        <input type="hidden" name="order_id" value="<?php echo $o['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger fw-bold rounded-pill">
                                            <i class="bi bi-arrow-counterclockwise me-1"></i>Refund
                                        </button>
                                    </form>

                                    <?php if (!$sd && !$br && !$av): ?>
                                        <span class="text-muted small">Waiting for seller</span>
                                    <?php elseif ($sd && !$br): ?>
                                        <span class="text-muted small">Awaiting buyer confirmation</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-shield-check fs-2 d-block mb-2"></i>No active escrow orders.
                        </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
