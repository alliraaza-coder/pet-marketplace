<?php
/**
 * User Order Details Page
 * Phase 8 - Buyer Payment Timeline and Refund Confirmation
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('user');

$user = current_user($conn);
$user_id = $_SESSION['user_id'];

$order_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($order_id <= 0) {
    $_SESSION['error'] = "Invalid Order ID.";
    header('Location: orders.php');
    exit;
}

// Fetch the order, ensuring it belongs to the logged-in user
$stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $order_id, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    $_SESSION['error'] = "Order not found or unauthorized access.";
    header('Location: orders.php');
    exit;
}

// Fetch order items with product details
$item_stmt = $conn->prepare("
    SELECT oi.*, p.title_en, p.breed, p.listing_type,
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$item_stmt->bind_param("i", $order['id']);
$item_stmt->execute();
$order_items = $item_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Handle Buyer Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_action'])) {
    $action = sanitize_input($_POST['buyer_action']);
    
    if ($action === 'cancel' && in_array($order['order_status'], ['pending', 'pending_payment']) && in_array($order['payment_status'], ['pending', 'rejected'])) {
        $upd_stmt = $conn->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        if ($upd_stmt->execute()) {
            foreach ($order_items as $itm) {
                $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold', 'active', status) WHERE id = ?");
                $stock_stmt->bind_param("ii", $itm['quantity'], $itm['product_id']);
                $stock_stmt->execute();
            }
            $_SESSION['success'] = "Order cancelled successfully.";
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Order cancelled by buyer", "", $user_id);
            $order['order_status'] = 'cancelled';
        }
    } elseif ($action === 'submit_payment' && in_array($order['payment_status'], ['pending', 'rejected'])) {
        $reference = sanitize_input($_POST['payment_reference'] ?? '');

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/payments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $allowed_mime  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $allowed_ext   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $max_size      = 5 * 1024 * 1024;
            
            $tmp_file = $_FILES['payment_proof']['tmp_name'];
            $orig_name = $_FILES['payment_proof']['name'];
            $file_size = $_FILES['payment_proof']['size'];
            
            $real_mime = mime_content_type($tmp_file);
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            
            if (!in_array($real_mime, $allowed_mime) || !in_array($ext, $allowed_ext)) {
                $_SESSION['error'] = "Invalid file type. Only JPG, PNG, WEBP, GIF images are accepted.";
                header("Location: order_details.php?id=$order_id");
                exit;
            }
            if ($file_size > $max_size) {
                $_SESSION['error'] = "File size exceeds 5MB limit.";
                header("Location: order_details.php?id=$order_id");
                exit;
            }
            
            $filename = 'proof_' . $order_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (move_uploaded_file($tmp_file, $upload_dir . $filename)) {
                $upd_stmt = $conn->prepare("UPDATE orders SET payment_reference = ?, payment_proof = ?, payment_status = 'payment_submitted', order_status = 'pending_payment' WHERE id = ?");
                $upd_stmt->bind_param("ssi", $reference, $filename, $order_id);
                if ($upd_stmt->execute()) {
                    $_SESSION['success'] = "Payment proof submitted successfully. Waiting for admin verification.";
                    if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Buyer submitted payment proof", "Reference: $reference", $user_id);
                    $order['payment_status'] = 'payment_submitted';
                    $order['payment_reference'] = $reference;
                    $order['payment_proof'] = $filename;
                } else {
                    $_SESSION['error'] = "Database error while updating payment status.";
                }
            } else {
                $_SESSION['error'] = "Failed to upload payment proof.";
            }
        } else {
            $_SESSION['error'] = "Please provide a valid payment screenshot/receipt.";
        }
    } elseif ($action === 'confirm_receipt' && $order['order_status'] === 'delivered' && $order['buyer_received'] == 0) {
        $upd_stmt = $conn->prepare("UPDATE orders SET buyer_received = 1 WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        if ($upd_stmt->execute()) {
            $_SESSION['success'] = "Thank you for confirming receipt of your bird!";
            if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Buyer confirmed receipt", "Delivery condition met.", $user_id);
            $order['buyer_received'] = 1;
        } else {
            $_SESSION['error'] = "Failed to update receipt status.";
        }
    } elseif ($action === 'confirm_refund') {
        if ($order['buyer_refund_received'] == 1) {
            $_SESSION['error'] = "You have already confirmed receiving this refund.";
        } elseif ($order['payment_status'] !== 'refund_sent') {
            $_SESSION['error'] = "No refund has been sent for this order yet.";
        } else {
            $now = date('Y-m-d H:i:s');
            $conn->begin_transaction();
            try {
                $upd = $conn->prepare("UPDATE orders SET payment_status = 'refund_received', buyer_refund_received = 1, buyer_refund_received_at = ? WHERE id = ? AND user_id = ? AND buyer_refund_received = 0");
                $upd->bind_param("sii", $now, $order_id, $user_id);
                $upd->execute();
                
                if ($upd->affected_rows === 0) throw new Exception("Refund already confirmed or unauthorized.");
                
                if (function_exists('log_order_audit')) log_order_audit($conn, $order_id, "Buyer confirmed refund received", "Refund amount: " . $order['buyer_refund_amount'], $user_id);
                
                $conn->commit();
                $_SESSION['success'] = "Refund receipt confirmed. Thank you.";
                $order['payment_status'] = 'refund_received';
                $order['buyer_refund_received'] = 1;
                $order['buyer_refund_received_at'] = $now;
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = "Confirmation failed: " . $e->getMessage();
            }
        }
    }
}

function pm_label($m) {
    $labels = ['jazzcash'=>'JazzCash','easypaisa'=>'EasyPaisa','bank_transfer'=>'Bank Transfer'];
    return $labels[$m] ?? ucfirst($m);
}

$page_title = "Order Details #" . htmlspecialchars($order['order_number']);
include '../includes/header.php';
?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0">Order <span class="text-success">#<?php echo htmlspecialchars($order['order_number']); ?></span></h2>
        <a href="orders.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left me-2"></i>Back to Orders
        </a>
    </div>

    <?php display_messages(); ?>

    <div class="row g-4">
        <!-- Main Content -->
        <div class="col-lg-8">
            
            <!-- Order Status Banner -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-body p-4 bg-light text-center">
                    <?php
                    $status_badge = 'bg-secondary';
                    $status_icon = 'bi-clock';
                    $status_text = 'Pending';
                    
                    if ($order['order_status'] === 'completed') { $status_badge = 'bg-success'; $status_icon = 'bi-check-circle-fill'; $status_text = 'Completed'; }
                    elseif ($order['order_status'] === 'cancelled') { $status_badge = 'bg-danger'; $status_icon = 'bi-x-circle-fill'; $status_text = 'Cancelled'; }
                    elseif ($order['order_status'] === 'delivered') { $status_badge = 'bg-info text-dark'; $status_icon = 'bi-truck'; $status_text = 'Delivered (Awaiting Confirmation)'; }
                    elseif (in_array($order['order_status'], ['preparing', 'ready_for_shipment', 'out_for_delivery'])) { $status_badge = 'bg-primary'; $status_icon = 'bi-box-seam'; $status_text = 'In Progress'; }
                    ?>
                    <h5 class="mb-3 text-muted">Order Status</h5>
                    <div class="d-inline-flex align-items-center badge <?php echo $status_badge; ?> rounded-pill px-4 py-2 fs-5 mb-3">
                        <i class="bi <?php echo $status_icon; ?> me-2"></i> <?php echo strtoupper($status_text); ?>
                    </div>
                    <div class="text-muted small">Placed on <?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?></div>
                </div>
            </div>

            <!-- PAYMENT TIMELINE -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0"><i class="bi bi-card-checklist me-2 text-primary"></i>Payment Timeline</h5>
                </div>
                <div class="card-body p-4">
                    <!-- Panel 1: Buyer Payment -->
                    <div class="border rounded-3 p-3 mb-3">
                        <h6 class="fw-bold mb-2 text-primary"><i class="bi bi-send me-1"></i>Your Payment to PetMarket</h6>
                        <div class="row g-2 small">
                            <div class="col-6"><span class="text-muted">Method:</span> <strong><?= pm_label($order['payment_method']) ?></strong></div>
                            <div class="col-6"><span class="text-muted">Amount:</span> <strong><?= $site_settings['currency'] . number_format($order['grand_total'],2) ?></strong></div>
                            <?php if (!empty($order['payment_reference'])): ?>
                            <div class="col-12"><span class="text-muted">Reference:</span> <strong><?= htmlspecialchars($order['payment_reference']) ?></strong></div>
                            <?php endif; ?>
                            <div class="col-6"><span class="text-muted">Submitted:</span> <strong><?= date('d M Y h:i A', strtotime($order['updated_at'])) ?></strong></div>
                            <div class="col-6">
                                <span class="text-muted">Status:</span> 
                                <?php if (in_array($order['payment_status'], ['held','seller_payment_sent','seller_payment_received','released','refund_sent','refund_received'])): ?>
                                    <span class="badge bg-success">Approved & Held</span>
                                <?php elseif ($order['payment_status'] === 'payment_submitted'): ?>
                                    <span class="badge bg-warning text-dark">Awaiting Verification</span>
                                <?php elseif ($order['payment_status'] === 'rejected'): ?>
                                    <span class="badge bg-danger">Rejected</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pending</span>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($order['payment_proof'])): ?>
                            <div class="col-12 mt-2">
                                <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($order['payment_proof']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">View Your Receipt</a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Panel 2: Delivery Status -->
                    <?php if (in_array($order['payment_status'], ['held','seller_payment_sent','seller_payment_received','released'])): ?>
                    <div class="border rounded-3 p-3 mb-3">
                        <h6 class="fw-bold mb-2 text-info"><i class="bi bi-truck me-1"></i>Delivery</h6>
                        <div class="row g-2 small">
                            <div class="col-6">Seller Dispatched: <strong><?= $order['seller_delivered'] ? '<span class="text-success">Yes</span>' : 'Pending' ?></strong></div>
                            <div class="col-6">You Confirmed: <strong><?= $order['buyer_received'] ? '<span class="text-success">Yes</span>' : 'Pending' ?></strong></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Panel 3: Seller Payment -->
                    <?php if (!empty($order['seller_payment_sent_at'])): ?>
                    <div class="border rounded-3 p-3 mb-3 bg-light">
                        <h6 class="fw-bold mb-2 text-success"><i class="bi bi-check-circle me-1"></i>Seller Payment by PetMarket</h6>
                        <div class="small text-muted">PetMarket has sent payment to the seller. Order will be marked complete once seller confirms.</div>
                        <?php if ($order['seller_payment_received'] == 1): ?>
                        <div class="mt-2"><span class="badge bg-success">Seller Confirmed Receipt</span></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Panel 4: Refund -->
                    <?php if (!empty($order['buyer_refund_sent_at']) || in_array($order['payment_status'], ['refund_sent','refund_received'])): ?>
                    <div class="border border-warning rounded-3 p-3 mb-3 bg-warning bg-opacity-10">
                        <h6 class="fw-bold mb-2 text-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Your Refund</h6>
                        <div class="row g-2 small">
                            <div class="col-6"><span class="text-muted">Method:</span> <strong><?= pm_label($order['buyer_refund_method']) ?></strong></div>
                            <div class="col-6"><span class="text-muted">Amount:</span> <strong class="text-success"><?= $site_settings['currency'] . number_format($order['buyer_refund_amount'],2) ?></strong></div>
                            <?php if (!empty($order['buyer_refund_transaction_id'])): ?>
                            <div class="col-12"><span class="text-muted">Transaction ID:</span> <strong><?= htmlspecialchars($order['buyer_refund_transaction_id']) ?></strong></div>
                            <?php endif; ?>
                            <?php if (!empty($order['buyer_refund_sent_at'])): ?>
                            <div class="col-6"><span class="text-muted">Sent:</span> <strong><?= date('d M Y h:i A', strtotime($order['buyer_refund_sent_at'])) ?></strong></div>
                            <?php endif; ?>
                            <?php if (!empty($order['buyer_refund_receipt'])): ?>
                            <div class="col-12 mt-2">
                                <a href="<?= BASE_URL ?>/assets/images/payments/<?= htmlspecialchars($order['buyer_refund_receipt']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary rounded-pill">View Refund Receipt</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($order['buyer_refund_received'] == 0 && $order['payment_status'] === 'refund_sent'): ?>
                        <form action="" method="POST" class="mt-3" onsubmit="return confirm('Confirm you have received this refund?');">
                            <?php csrf_field(); ?>
                            <button type="submit" name="buyer_action" value="confirm_refund" class="btn btn-warning fw-bold rounded-pill w-100">
                                <i class="bi bi-check-circle me-1"></i>Refund Received
                            </button>
                        </form>
                        <?php elseif ($order['buyer_refund_received'] == 1): ?>
                        <div class="mt-3 alert alert-success border-0 py-2 mb-0 small">
                            <i class="bi bi-check-all me-1"></i><strong>Refund Received</strong> ? Confirmed by you on <?= date('d M Y h:i A', strtotime($order['buyer_refund_received_at'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Action required prompts (Submit Proof or Confirm Delivery) -->
                    <?php if (in_array($order['payment_status'], ['pending', 'rejected']) && in_array($order['order_status'], ['pending', 'pending_payment'])): ?>
                        <div class="bg-warning bg-opacity-10 border border-warning rounded-3 p-4 text-center mt-3">
                            <h5 class="fw-bold text-dark"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Action Required</h5>
                            <p class="text-muted small mb-3">Please submit your payment proof to proceed with the order.</p>
                            <form action="" method="POST" enctype="multipart/form-data" class="text-start bg-white p-3 rounded shadow-sm">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="buyer_action" value="submit_payment">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Transaction Reference / Sender Number</label>
                                    <input type="text" name="payment_reference" class="form-control form-control-sm" required placeholder="e.g. 03001234567 or TXN123456">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Upload Screenshot/Receipt (Max 5MB)</label>
                                    <input type="file" name="payment_proof" class="form-control form-control-sm" required accept="image/*">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100 fw-bold rounded-pill">Submit Proof for Verification</button>
                            </form>
                            
                            <form action="" method="POST" class="mt-3 text-center">
                                <?php csrf_field(); ?>
                                <button type="submit" name="buyer_action" value="cancel" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" onclick="return confirm('Are you sure you want to cancel this order?');">
                                    <i class="bi bi-x-circle me-1"></i>Cancel Order
                                </button>
                            </form>
                        </div>
                    <?php elseif ($order['order_status'] === 'delivered' && $order['buyer_received'] == 0): ?>
                        <div class="mt-3 bg-light p-3 border rounded-3 text-center">
                            <p class="small text-muted mb-2">The seller has marked this order as delivered. Please confirm you have received it.</p>
                            <form action="" method="POST">
                                <?php csrf_field(); ?>
                                <button type="submit" name="buyer_action" value="confirm_receipt" class="btn btn-success btn-sm w-100 rounded-pill fw-bold" onclick="return confirm('Confirm you have received the bird in good condition?');">
                                    <i class="bi bi-check-circle me-1"></i>I Received Bird
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

            <!-- Items Details Card -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h5 class="fw-bold mb-0 text-success"><i class="bi bi-box-seam me-2"></i>Purchased Items</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th class="pe-4 text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($order_items as $item): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <h6 class="fw-bold mb-0 small"><?php echo htmlspecialchars($item['title_en']); ?></h6>
                                        </td>
                                        <td><?php echo $site_settings['currency'] . number_format($item['price'], 2); ?></td>
                                        <td><?php echo $item['quantity']; ?></td>
                                        <td class="pe-4 text-end fw-bold text-success"><?php echo $site_settings['currency'] . number_format($item['total'], 2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
        </div>
        
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-4">Order Summary</h5>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Subtotal:</span>
                        <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['subtotal'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Shipping Charges:</span>
                        <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['shipping_fee'], 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Tax:</span>
                        <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['tax'], 2); ?></span>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <span class="fw-bold text-dark fs-5">Grand Total:</span>
                        <span class="fw-bold text-success fs-4"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold text-dark mb-3">Shipping Address</h5>
                    <p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($order['shipping_address'])); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>