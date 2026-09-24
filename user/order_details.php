<?php
/**
 * User Order Details Page
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

// Require user role
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

// Handle Buyer Actions (Cancel or Confirm Receipt)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buyer_action'])) {
    $action = sanitize_input($_POST['buyer_action']);
    
    if ($action === 'cancel' && in_array($order['order_status'], ['pending', 'pending_payment']) && in_array($order['payment_status'], ['pending', 'rejected'])) {
        $upd_stmt = $conn->prepare("UPDATE orders SET order_status = 'cancelled' WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        if ($upd_stmt->execute()) {
            // Restore Stock
            foreach ($order_items as $itm) {
                $stock_stmt = $conn->prepare("UPDATE products SET stock_quantity = stock_quantity + ?, status = IF(status='sold', 'active', status) WHERE id = ?");
                $stock_stmt->bind_param("ii", $itm['quantity'], $itm['product_id']);
                $stock_stmt->execute();
            }
            $_SESSION['success'] = "Order cancelled successfully.";
            log_order_audit($conn, $order_id, "Order cancelled by buyer", "", $user_id);
            $order['order_status'] = 'cancelled';
        }
    } elseif ($action === 'submit_payment' && in_array($order['payment_status'], ['pending', 'rejected'])) {
        $reference = sanitize_input($_POST['payment_reference'] ?? '');
        $proof_path = '';

        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../assets/images/payments/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            
            $allowed_mime  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $allowed_ext   = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
            $max_size      = 5 * 1024 * 1024; // 5 MB
            
            $tmp_file = $_FILES['payment_proof']['tmp_name'];
            $orig_name = $_FILES['payment_proof']['name'];
            $file_size = $_FILES['payment_proof']['size'];
            
            $real_mime = mime_content_type($tmp_file);
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            
            if (!in_array($real_mime, $allowed_mime)) {
                $_SESSION['error'] = "Invalid file type. Only JPG, PNG, WEBP, GIF images are accepted.";
                header("Location: order_details.php?id=$order_id");
                exit;
            }
            if (!in_array($ext, $allowed_ext)) {
                $_SESSION['error'] = "Invalid file extension.";
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
                $proof_path = 'payments/' . $filename;
            }
        }

        $upd_stmt = $conn->prepare("UPDATE orders SET payment_status = 'payment_submitted', payment_reference = ?, payment_proof = ? WHERE id = ?");
        $upd_stmt->bind_param("ssi", $reference, $proof_path, $order_id);
        if ($upd_stmt->execute()) {
            $_SESSION['success'] = "Payment details submitted successfully! Awaiting Admin verification.";
            log_order_audit($conn, $order_id, "Payment Submitted", "Reference: $reference", $user_id);
            $order['payment_status'] = 'payment_submitted';
        }
    } elseif ($action === 'confirm_receipt' && $order['order_status'] === 'delivered' && $order['buyer_received'] == 0) {
        $upd_stmt = $conn->prepare("UPDATE orders SET buyer_received = 1 WHERE id = ?");
        $upd_stmt->bind_param("i", $order_id);
        if ($upd_stmt->execute()) {
            $_SESSION['success'] = "Thank you for confirming receipt!";
            log_order_audit($conn, $order_id, "Buyer confirmed receipt", "", $user_id);
            $order['buyer_received'] = 1;
        }
    }
}

include '../includes/header.php';
?>

<div class="container py-5">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-lg-3 mb-4">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body text-center p-4">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $user['profile_pic']; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($user['first_name'].' '.$user['last_name']); ?>&background=random'" alt="Profile Picture" class="rounded-circle mb-3 border border-3 border-success" style="width: 100px; height: 100px; object-fit: cover;">
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></h5>
                    <p class="text-muted small mb-3"><?php echo htmlspecialchars($user['email']); ?></p>
                    <a href="profile.php" class="btn btn-sm btn-outline-success rounded-pill px-3">Edit Profile</a>
                </div>
                <div class="list-group list-group-flush border-top">
                    <a href="dashboard.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a href="profile.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-person me-2"></i> My Profile</a>
                    <a href="orders.php" class="list-group-item list-group-item-action active bg-success border-success py-3"><i class="bi bi-box-seam me-2"></i> My Orders</a>
                    <a href="../wishlist.php" class="list-group-item list-group-item-action py-3"><i class="bi bi-heart me-2"></i> Wishlist</a>
                    <a href="../logout.php" class="list-group-item list-group-item-action text-danger py-3"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="col-lg-9">
            <?php display_messages(); ?>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <div>
                        <h4 class="fw-bold mb-0 text-success">Order #<?php echo htmlspecialchars($order['order_number']); ?></h4>
                        <span class="text-muted small">Placed on <?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                    <a href="orders.php" class="btn btn-outline-success rounded-pill btn-sm px-3">
                        <i class="bi bi-arrow-left me-1"></i>Back to List
                    </a>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="row g-4">
                        <!-- Shipping/Contact Info -->
                        <div class="col-md-6 border-end">
                            <h5 class="fw-bold text-success mb-3"><i class="bi bi-geo-alt me-2"></i>Shipping Details</h5>
                            <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($order['shipping_name']); ?></p>
                            <p class="mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($order['shipping_phone']); ?></p>
                            <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($order['shipping_email']); ?></p>
                            <p class="mb-0"><strong>Address:</strong> <?php echo htmlspecialchars($order['shipping_address']); ?></p>
                        </div>
                        
                        <!-- Order/Payment Status -->
                        <div class="col-md-6">
                            <h5 class="fw-bold text-success mb-3"><i class="bi bi-info-circle me-2"></i>Status Information</h5>
                            
                            <div class="mb-3">
                                <span class="text-muted d-block small fw-medium">Order Status:</span>
                                <?php
                                $status = $order['order_status'];
                                $badge_class = 'bg-secondary bg-opacity-10 text-secondary';
                                if ($status === 'pending') $badge_class = 'bg-warning bg-opacity-10 text-warning';
                                elseif ($status === 'processing' || $status === 'preparing' || $status === 'ready_for_shipment') $badge_class = 'bg-info bg-opacity-10 text-info';
                                elseif ($status === 'shipped' || $status === 'out_for_delivery') $badge_class = 'bg-primary bg-opacity-10 text-primary';
                                elseif ($status === 'delivered' || $status === 'completed') $badge_class = 'bg-success bg-opacity-10 text-success';
                                elseif ($status === 'cancelled') $badge_class = 'bg-danger bg-opacity-10 text-danger';
                                ?>
                                <span class="badge <?php echo $badge_class; ?> px-3 py-2 rounded-pill fw-semibold mt-1">
                                    <?php echo ucfirst(str_replace('_', ' ', htmlspecialchars($status))); ?>
                                </span>
                            </div>

                            <div class="mb-3">
                                <span class="text-muted d-block small fw-medium">Payment Method:</span>
                                <span class="badge bg-light text-dark border px-3 py-2 mt-1"><?php echo htmlspecialchars($order['payment_method']); ?></span>
                            </div>

                            <div class="mb-3">
                                <span class="text-muted d-block small fw-medium">Payment Status:</span>
                                <span class="badge bg-<?php echo ($order['payment_status'] === 'completed' || $order['payment_status'] === 'released') ? 'success' : (($order['payment_status'] === 'held') ? 'info' : 'warning'); ?> text-dark border px-3 py-2 mt-1 text-white">
                                    <?php echo ucfirst(htmlspecialchars($order['payment_status'])); ?>
                                    <?php if ($order['payment_status'] === 'held') echo ' (Held securely until delivery)'; ?>
                                </span>
                            </div>
                            
                            <!-- Buyer Actions -->
                              <?php if (in_array($order['payment_status'], ['pending', 'rejected'])): ?>
                                  <?php if ($order['payment_status'] === 'rejected'): ?>
                                  <div class="mt-3 p-3 bg-danger bg-opacity-10 border border-danger rounded-3">
                                      <p class="small text-danger fw-bold mb-0"><i class="bi bi-x-circle-fill me-1"></i>Your payment was rejected by Admin. Please resubmit with correct details.</p>
                                  </div>
                                  <?php endif; ?>
                                  <div class="mt-3 p-3 bg-light border border-warning rounded-3">
                                      <h6 class="fw-bold text-dark"><i class="bi bi-wallet2 me-2"></i>Proceed for Payment</h6>
                                      <p class="small text-muted mb-3">Please pay via <strong><?php echo strtoupper($order['payment_method']); ?></strong> to complete this order.</p>
                                      
                                      <form action="" method="POST" enctype="multipart/form-data">
                                          <input type="hidden" name="buyer_action" value="submit_payment">
                                          <div class="mb-2">
                                              <label class="small text-muted mb-1">Transaction ID / Reference No.</label>
                                              <input type="text" name="payment_reference" class="form-control form-control-sm" required placeholder="e.g. 1234567890">
                                          </div>
                                          <div class="mb-3">
                                              <label class="small text-muted mb-1">Upload Receipt (Optional)</label>
                                              <input type="file" name="payment_proof" class="form-control form-control-sm" accept="image/*">
                                          </div>
                                          <button type="submit" class="btn btn-warning w-100 fw-bold rounded-pill text-dark">Submit Payment Details</button>
                                      </form>
                                  </div>
                                  <form action="" method="POST" class="mt-3 text-center">
                                      <button type="submit" name="buyer_action" value="cancel" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" onclick="return confirm('Are you sure you want to cancel this order?');">
                                          <i class="bi bi-x-circle me-1"></i>Cancel Order
                                      </button>
                                  </form>
                              <?php elseif (in_array($order['order_status'], ['pending', 'pending_payment']) && in_array($order['payment_status'], ['pending', 'rejected'])): ?>
                                  <form action="" method="POST" class="mt-3 text-center">
                                      <button type="submit" name="buyer_action" value="cancel" class="btn btn-outline-danger btn-sm rounded-pill px-4 fw-bold" onclick="return confirm('Are you sure you want to cancel this order?');">
                                          <i class="bi bi-x-circle me-1"></i>Cancel Order
                                      </button>
                                  </form>
                              <?php elseif ($order['order_status'] === 'delivered' && $order['buyer_received'] == 0): ?>
                                <form action="" method="POST" class="mt-3 bg-light p-3 border rounded-3 text-center">
                                    <p class="small text-muted mb-2">The seller has marked this order as delivered. Please confirm you have received it.</p>
                                    <button type="submit" name="buyer_action" value="confirm_receipt" class="btn btn-success btn-sm w-100 rounded-pill fw-bold" onclick="return confirm('Confirm you have received the bird in good condition?');">
                                        <i class="bi bi-check-circle me-1"></i>I Received Bird
                                    </button>
                                </form>
                            <?php elseif ($order['buyer_received'] == 1): ?>
                                <div class="mt-3 bg-success bg-opacity-10 text-success p-2 rounded-3 border border-success border-opacity-25 text-center small fw-bold">
                                    <i class="bi bi-check-all me-1"></i> Delivery Confirmed by You
                                </div>
                            <?php elseif ($order['payment_status'] === 'payment_submitted'): ?>
                                <div class="mt-3 bg-info bg-opacity-10 text-info p-3 rounded-3 border border-info border-opacity-25 text-center small fw-bold">
                                    <i class="bi bi-hourglass-split me-1"></i> Payment submitted — awaiting Admin verification.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
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
                                            <div class="d-flex align-items-center">
                                                <div class="bg-light rounded-3 overflow-hidden me-3" style="width: 50px; height: 50px;">
                                                    <?php if (!empty($item['image_url'])): ?>
                                                        <img src="../assets/uploads/products/<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title_en']); ?>" class="img-fluid w-100 h-100 object-fit-cover">
                                                    <?php else: ?>
                                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="bi bi-image"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <h6 class="fw-bold mb-0 small"><?php echo htmlspecialchars($item['title_en']); ?></h6>
                                                    <?php if (!empty($item['breed'])): ?>
                                                        <span class="text-muted small">Breed: <?php echo htmlspecialchars($item['breed']); ?></span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-light text-muted border py-0 px-2 mt-1 small text-uppercase" style="font-size: 0.65rem;"><?php echo htmlspecialchars($item['listing_type']); ?></span>
                                                </div>
                                            </div>
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
                
                <div class="card-footer bg-light border-0 p-4">
                    <div class="row justify-content-end">
                        <div class="col-md-5">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Subtotal:</span>
                                <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['subtotal'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Shipping Charges:</span>
                                <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['shipping_fee'], 2); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span class="text-muted">Tax (5%):</span>
                                <span class="fw-bold text-dark"><?php echo $site_settings['currency'] . number_format($order['tax'], 2); ?></span>
                            </div>
                            <hr class="my-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold text-dark">Grand Total:</span>
                                <span class="fw-bold text-success fs-5"><?php echo $site_settings['currency'] . number_format($order['grand_total'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
