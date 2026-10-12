<?php
/**
 * Admin Disputes Management Page
 * Phase 9 - Dispute Resolution Center
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');

$admin_id = $_SESSION['user_id'];
$page_title = "Dispute Management";
$page_heading = "Dispute Management";

// Handle POST: Resolve or Close dispute
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['order_id'])) {
    verify_csrf();
    $action = sanitize_input($_POST['action']);
    $order_id = (int)$_POST['order_id'];
    
    $order = $conn->query("SELECT * FROM orders WHERE id = $order_id")->fetch_assoc();
    if (!$order) {
        $_SESSION['error'] = "Order not found.";
        header("Location: disputes.php");
        exit;
    }
    
    if ($action === 'resolve_favor_buyer') {
        // Resolve in favor of buyer: Cancel order, trigger refund
        $conn->begin_transaction();
        try {
            $resolution = sanitize_input($_POST['resolution_notes']);
            $conn->query("UPDATE orders SET dispute_status = 0, order_status = 'cancelled', dispute_reason = CONCAT(dispute_reason, '\n[Admin Resolved - Favor Buyer]: $resolution') WHERE id = $order_id");
            log_order_audit($conn, $order_id, "Dispute resolved in favor of buyer", "Admin decision: $resolution", $admin_id);
            log_admin_activity($conn, $admin_id, "Dispute Resolved (Buyer)", "Order ID: $order_id. $resolution");
            
            // Notify buyer
            if (function_exists('add_user_notification')) {
                add_user_notification($conn, $order['user_id'], 'dispute', 'Dispute Resolved', "Your dispute for order #{$order['order_number']} has been resolved in your favor. Admin will process your refund shortly.", 'user/order_details.php?id='.$order_id);
            }
            // Notify seller
            $seller_res = $conn->query("SELECT seller_id FROM order_items WHERE order_id = $order_id LIMIT 1");
            if ($seller_res && $seller = $seller_res->fetch_assoc()) {
                if (function_exists('add_user_notification')) {
                    add_user_notification($conn, $seller['seller_id'], 'dispute', 'Dispute Resolved', "The dispute for order #{$order['order_number']} was resolved in favor of the buyer. The order has been cancelled.", 'seller/order_details.php?id='.$order_id);
                }
            }
            $conn->commit();
            $_SESSION['success'] = "Dispute resolved in buyer's favor. Please process the refund from the Escrow page.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed: " . $e->getMessage();
        }
    } elseif ($action === 'resolve_favor_seller') {
        // Resolve in favor of seller: Mark buyer as received, allow payment to proceed
        $conn->begin_transaction();
        try {
            $resolution = sanitize_input($_POST['resolution_notes']);
            $conn->query("UPDATE orders SET dispute_status = 0, buyer_received = 1, dispute_reason = CONCAT(dispute_reason, '\n[Admin Resolved - Favor Seller]: $resolution') WHERE id = $order_id");
            log_order_audit($conn, $order_id, "Dispute resolved in favor of seller", "Admin decision: $resolution", $admin_id);
            log_admin_activity($conn, $admin_id, "Dispute Resolved (Seller)", "Order ID: $order_id. $resolution");
            
            // Notify buyer
            if (function_exists('add_user_notification')) {
                add_user_notification($conn, $order['user_id'], 'dispute', 'Dispute Resolved', "Your dispute for order #{$order['order_number']} was reviewed and resolved in favor of the seller. Payment will be released.", 'user/order_details.php?id='.$order_id);
            }
            // Notify seller
            $seller_res = $conn->query("SELECT seller_id FROM order_items WHERE order_id = $order_id LIMIT 1");
            if ($seller_res && $seller = $seller_res->fetch_assoc()) {
                if (function_exists('add_user_notification')) {
                    add_user_notification($conn, $seller['seller_id'], 'dispute', 'Dispute Resolved', "The dispute for order #{$order['order_number']} was resolved in your favor! Admin will process your payment.", 'seller/order_details.php?id='.$order_id);
                }
            }
            $conn->commit();
            $_SESSION['success'] = "Dispute resolved in seller's favor. You can now proceed to pay the seller from the Escrow page.";
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Failed: " . $e->getMessage();
        }
    }
    header("Location: disputes.php");
    exit;
}

// Fetch all orders with active disputes
$active_disputes = $conn->query("
    SELECT o.*, b.first_name AS buyer_fn, b.last_name AS buyer_ln, b.email AS buyer_email,
           s.first_name AS seller_fn, s.last_name AS seller_ln
    FROM orders o
    JOIN users b ON o.user_id = b.id
    JOIN order_items oi ON o.id = oi.order_id
    JOIN users s ON oi.seller_id = s.id
    WHERE o.dispute_status = 1 AND o.payment_status = 'held'
    GROUP BY o.id
    ORDER BY o.updated_at DESC
");

// Fetch resolved disputes (last 20)
$resolved_disputes = $conn->query("
    SELECT o.*, b.first_name AS buyer_fn, b.last_name AS buyer_ln
    FROM orders o
    JOIN users b ON o.user_id = b.id
    WHERE o.dispute_status = 0 AND o.dispute_reason IS NOT NULL AND o.dispute_reason != ''
    ORDER BY o.updated_at DESC
    LIMIT 20
");

include __DIR__ . '/partials/header.php';
?>

<div class="p-4">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1">Dispute Management</h4>
            <p class="text-muted mb-0 small">Review buyer disputes and decide resolutions. Escrow is paused for all active disputes.</p>
        </div>
        <?php
            $activeCount = $active_disputes ? $active_disputes->num_rows : 0;
        ?>
        <?php if ($activeCount > 0): ?>
        <span class="badge bg-danger fs-6 rounded-pill px-4"><?php echo $activeCount; ?> Active</span>
        <?php else: ?>
        <span class="badge bg-success fs-6 rounded-pill px-4">All Clear</span>
        <?php endif; ?>
    </div>

    <!-- Active Disputes -->
    <h5 class="fw-bold text-danger mb-3"><i class="bi bi-exclamation-triangle-fill me-2"></i>Active Disputes (Escrow Paused)</h5>
    
    <?php if ($active_disputes && $active_disputes->num_rows > 0): ?>
        <?php while($d = $active_disputes->fetch_assoc()): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 border-start border-4 border-danger">
            <div class="card-body p-4">
                <div class="row g-3">
                    <!-- Order Info -->
                    <div class="col-md-4">
                        <h6 class="fw-bold text-dark mb-2">Order #<?php echo htmlspecialchars($d['order_number']); ?></h6>
                        <div class="small text-muted mb-1"><i class="bi bi-person me-1"></i> Buyer: <strong class="text-dark"><?php echo htmlspecialchars($d['buyer_fn'] . ' ' . $d['buyer_ln']); ?></strong></div>
                        <div class="small text-muted mb-1"><i class="bi bi-shop me-1"></i> Seller: <strong class="text-dark"><?php echo htmlspecialchars($d['seller_fn'] . ' ' . $d['seller_ln']); ?></strong></div>
                        <div class="small text-muted mb-1"><i class="bi bi-cash me-1"></i> Order Total: <strong class="text-success"><?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs'); ?> <?php echo number_format($d['grand_total'], 2); ?></strong></div>
                        <div class="small text-muted"><i class="bi bi-calendar me-1"></i> Placed: <?php echo date('d M Y', strtotime($d['created_at'])); ?></div>
                        <a href="orders.php?view=<?php echo $d['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill mt-2">View Full Order</a>
                    </div>
                    <!-- Dispute Reason -->
                    <div class="col-md-4">
                        <h6 class="fw-bold text-danger mb-2"><i class="bi bi-exclamation-circle me-1"></i>Dispute Reason:</h6>
                        <div class="bg-danger bg-opacity-10 border border-danger rounded-3 p-3">
                            <p class="small text-dark mb-0"><?php echo nl2br(htmlspecialchars($d['dispute_reason'])); ?></p>
                        </div>
                        <div class="small text-muted mt-2"><i class="bi bi-clock me-1"></i> Dispute since: <?php echo date('d M Y h:i A', strtotime($d['updated_at'])); ?></div>
                    </div>
                    <!-- Resolution Actions -->
                    <div class="col-md-4">
                        <h6 class="fw-bold mb-2 text-dark">Resolve Dispute:</h6>
                        <p class="small text-muted mb-3">Add resolution notes and decide in whose favor to resolve.</p>
                        
                        <!-- Resolve Favor Buyer -->
                        <form action="" method="POST" class="mb-3">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $d['id']; ?>">
                            <textarea name="resolution_notes" class="form-control form-control-sm bg-light border-0 mb-2" rows="2" placeholder="Resolution notes (required)..." required></textarea>
                            <button type="submit" name="action" value="resolve_favor_buyer" class="btn btn-warning btn-sm fw-bold rounded-pill w-100" onclick="return confirm('Resolve in BUYER\'s favor? Order will be cancelled and queued for refund.');">
                                <i class="bi bi-person-check me-1"></i> Resolve: Favor Buyer (Refund)
                            </button>
                        </form>
                        
                        <!-- Resolve Favor Seller -->
                        <form action="" method="POST">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="order_id" value="<?php echo $d['id']; ?>">
                            <textarea name="resolution_notes" class="form-control form-control-sm bg-light border-0 mb-2" rows="2" placeholder="Resolution notes (required)..." required></textarea>
                            <button type="submit" name="action" value="resolve_favor_seller" class="btn btn-success btn-sm fw-bold rounded-pill w-100" onclick="return confirm('Resolve in SELLER\'s favor? Buyer will be marked as received and payment will proceed.');">
                                <i class="bi bi-shop me-1"></i> Resolve: Favor Seller (Release)
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; ?>
    <?php else: ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-5 text-center text-muted">
            <i class="bi bi-emoji-smile fs-1 d-block mb-3 text-success"></i>
            <h5 class="fw-bold">No Active Disputes!</h5>
            <p class="mb-0">All orders are proceeding smoothly. No buyer disputes at this time.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Resolved Disputes History -->
    <?php if ($resolved_disputes && $resolved_disputes->num_rows > 0): ?>
    <h5 class="fw-bold text-muted mb-3 mt-5"><i class="bi bi-check2-all me-2"></i>Recently Resolved Disputes</h5>
    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-4">Order #</th>
                            <th>Buyer</th>
                            <th>Amount</th>
                            <th style="max-width:350px;">Resolution Notes</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($r = $resolved_disputes->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo htmlspecialchars($r['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($r['buyer_fn'] . ' ' . $r['buyer_ln']); ?></td>
                            <td class="fw-bold text-success"><?php echo htmlspecialchars($site_settings['currency'] ?? 'Rs'); ?> <?php echo number_format($r['grand_total'], 2); ?></td>
                            <td>
                                <p class="small text-muted mb-0 text-truncate" style="max-width:350px;" title="<?php echo htmlspecialchars($r['dispute_reason']); ?>">
                                    <?php echo htmlspecialchars($r['dispute_reason']); ?>
                                </p>
                            </td>
                            <td class="small text-muted"><?php echo date('d M Y', strtotime($r['updated_at'])); ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/partials/footer.php'; ?>
