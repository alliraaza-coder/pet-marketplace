<?php
/**
 * Order Success Page
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Validate Login
require_login();

$order_num = sanitize_input($_GET['order_num'] ?? '');
if (empty($order_num)) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch the order, ensuring it belongs to the logged-in user
$stmt = $conn->prepare("SELECT * FROM orders WHERE order_number = ? AND user_id = ?");
$stmt->bind_param("si", $order_num, $user_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();

if (!$order) {
    $_SESSION['error'] = "Order not found or unauthorized access.";
    header('Location: index.php');
    exit;
}

// Fetch order items
$item_stmt = $conn->prepare("
    SELECT oi.*, p.title_en, p.breed, 
           (SELECT pi.image_url FROM product_images pi WHERE pi.product_id = p.id AND pi.is_primary = 1 LIMIT 1) as image_url
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.order_id = ?
");
$item_stmt->bind_param("i", $order['id']);
$item_stmt->execute();
$order_items = $item_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <!-- Success Message Box -->
            <div class="card border-0 shadow-sm rounded-4 mb-4 text-center p-4 p-md-5 overflow-hidden position-relative">
                <div class="position-absolute top-0 start-0 w-100 bg-success" style="height: 6px;"></div>
                
                <div class="mb-4">
                    <span class="d-inline-flex align-items-center justify-content-center bg-success bg-opacity-10 text-success rounded-circle p-3" style="width: 80px; height: 80px;">
                        <i class="bi bi-check-circle-fill" style="font-size: 3rem;"></i>
                    </span>
                </div>
                
                <h1 class="fw-bold mb-2">Thank You for Your Order!</h1>
                <p class="text-muted mb-4 fs-5">Your order has been placed successfully and is now being processed.</p>
                
                <div class="d-flex flex-wrap justify-content-center gap-3">
                    <a href="user/orders.php" class="btn btn-success rounded-pill px-4 fw-bold shadow-sm">
                        <i class="bi bi-receipt me-1"></i>Track Order Status
                    </a>
                    <a href="shop.php" class="btn btn-outline-success rounded-pill px-4 fw-bold">
                        Continue Shopping
                    </a>
                </div>
            </div>

            <!-- Order Details Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h4 class="fw-bold mb-0 text-success"><i class="bi bi-info-circle me-2"></i>Order Information</h4>
                </div>
                <div class="card-body px-4 pb-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <span class="text-muted d-block small uppercase fw-semibold">Order Number</span>
                            <span class="fw-bold text-dark fs-5">#<?php echo htmlspecialchars($order['order_number']); ?></span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block small uppercase fw-semibold">Date Placed</span>
                            <span class="fw-bold text-dark fs-5"><?php echo date('F d, Y h:i A', strtotime($order['created_at'])); ?></span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block small uppercase fw-semibold">Payment Method</span>
                            <span class="badge bg-light text-dark border fs-6 py-2 px-3 mt-1"><?php echo htmlspecialchars($order['payment_method']); ?></span>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block small uppercase fw-semibold">Payment Status</span>
                            <span class="badge bg-<?php echo ($order['payment_status'] === 'completed') ? 'success' : 'warning'; ?> text-dark border fs-6 py-2 px-3 mt-1 text-white">
                                <?php echo ucfirst(htmlspecialchars($order['payment_status'])); ?>
                            </span>
                        </div>
                        <div class="col-12">
                            <hr class="my-2">
                        </div>
                        <div class="col-md-12">
                            <span class="text-muted d-block small uppercase fw-semibold mb-1">Shipping Contact</span>
                            <p class="mb-0 fw-bold"><?php echo htmlspecialchars($order['shipping_name']); ?></p>
                            <p class="mb-0 small text-muted"><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($order['shipping_phone']); ?> | <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($order['shipping_email']); ?></p>
                        </div>
                        <div class="col-md-12 mt-3">
                            <span class="text-muted d-block small uppercase fw-semibold mb-1">Shipping Address</span>
                            <p class="mb-0 text-muted"><i class="bi bi-geo-alt me-1 text-success"></i><?php echo htmlspecialchars($order['shipping_address']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Items Purchased Card -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4">
                    <h4 class="fw-bold mb-0 text-success"><i class="bi bi-box-seam me-2"></i>Purchased Items</h4>
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
                                                        <img src="assets/uploads/products/<?php echo htmlspecialchars($item['image_url']); ?>" alt="<?php echo htmlspecialchars($item['title_en']); ?>" class="img-fluid w-100 h-100 object-fit-cover">
                                                    <?php else: ?>
                                                        <div class="w-100 h-100 d-flex align-items-center justify-content-center text-muted"><i class="bi bi-image"></i></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <h6 class="fw-bold mb-0 small"><?php echo htmlspecialchars($item['title_en']); ?></h6>
                                                    <?php if (!empty($item['breed'])): ?>
                                                        <span class="text-muted small">Breed: <?php echo htmlspecialchars($item['breed']); ?></span>
                                                    <?php endif; ?>
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

<?php include 'includes/footer.php'; ?>
