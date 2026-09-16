<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Initialize session cart if not exists
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// Handle cart actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

    if ($action === 'add' && $product_id > 0) {
        $quantity = isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1;

        // Verify product exists and active and store listing and in stock
        $stmt = $conn->prepare("SELECT id, title_en, price, stock_quantity, seller_id FROM products WHERE id = ? AND status = 'active' AND listing_type = 'store' AND stock_quantity > 0");
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            $current_qty = isset($_SESSION['cart'][$product_id]) ? $_SESSION['cart'][$product_id]['quantity'] : 0;
            $new_qty = $current_qty + $quantity;

            // Respect stock limit
            if ($new_qty > $product['stock_quantity']) {
                $new_qty = $product['stock_quantity'];
                $_SESSION['error'] = "Only {$product['stock_quantity']} units available. Cart updated to maximum.";
            } else {
                $_SESSION['success'] = "Added to cart successfully!";
            }

            $_SESSION['cart'][$product_id] = [
                'id'        => $product['id'],
                'title'     => $product['title_en'],
                'price'     => $product['price'],
                'quantity'  => $new_qty,
                'seller_id' => $product['seller_id']
            ];
        } else {
            $_SESSION['error'] = "Product is unavailable or out of stock.";
        }

        // Redirect back to referring page or cart
        $redirect = isset($_POST['redirect_back']) && !empty($_SERVER['HTTP_REFERER'])
            ? $_SERVER['HTTP_REFERER']
            : 'cart.php';
        header('Location: ' . $redirect);
        exit;
    }

    if ($action === 'remove' && $product_id > 0) {
        if (isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
            $_SESSION['success'] = "Item removed from cart.";
        }
        header('Location: cart.php');
        exit;
    }

    if ($action === 'update' && $product_id > 0) {
        $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
        if ($quantity > 0 && isset($_SESSION['cart'][$product_id])) {
            $_SESSION['cart'][$product_id]['quantity'] = $quantity;
            $_SESSION['success'] = "Cart updated.";
        } elseif ($quantity <= 0 && isset($_SESSION['cart'][$product_id])) {
            unset($_SESSION['cart'][$product_id]);
            $_SESSION['success'] = "Item removed from cart.";
        }
        header('Location: cart.php');
        exit;
    }
}

$cart_items = $_SESSION['cart'];
$subtotal   = 0;

include 'includes/header.php';
?>

<div class="bg-light py-4 border-bottom">
    <div class="container">
        <h2 class="fw-bold mb-0">Shopping Cart
            <?php if (!empty($cart_items)): ?>
            <span class="badge bg-success rounded-pill fs-6 ms-2"><?php echo count($cart_items); ?></span>
            <?php endif; ?>
        </h2>
    </div>
</div>

<div class="container py-5">
    <?php display_messages(); ?>

    <?php if (empty($cart_items)): ?>
        <div class="text-center py-5">
            <i class="bi bi-cart-x fs-1 text-muted mb-3 d-block"></i>
            <h3 class="fw-bold">Your cart is empty</h3>
            <p class="text-muted mb-4">Looks like you haven't added anything to your cart yet.</p>
            <a href="shop.php" class="btn btn-success btn-lg rounded-pill px-5 shadow-sm">Start Shopping</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <!-- Cart Items -->
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th>Price</th>
                                        <th>Quantity</th>
                                        <th>Total</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cart_items as $id => $item):
                                        $item_total  = $item['price'] * $item['quantity'];
                                        $subtotal   += $item_total;
                                        $prod_img    = get_product_image_url($conn, $item['id']);
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <a href="product_details.php?id=<?php echo $item['id']; ?>">
                                                    <img src="<?php echo $prod_img; ?>"
                                                         alt="<?php echo htmlspecialchars($item['title']); ?>"
                                                         class="rounded-3 border"
                                                         style="width:60px;height:60px;object-fit:cover;">
                                                </a>
                                                <h6 class="fw-bold mb-0">
                                                    <a href="product_details.php?id=<?php echo $item['id']; ?>" class="text-dark text-decoration-none">
                                                        <?php echo htmlspecialchars($item['title']); ?>
                                                    </a>
                                                </h6>
                                            </div>
                                        </td>
                                        <td><?php echo $site_settings['currency'] . number_format($item['price'], 2); ?></td>
                                        <td>
                                            <form action="cart.php" method="POST" class="d-flex align-items-center" style="max-width:130px;">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                                <input type="number" name="quantity" value="<?php echo $item['quantity']; ?>" min="1" class="form-control form-control-sm text-center" style="width:65px;">
                                                <button type="submit" class="btn btn-sm btn-link text-success p-1 ms-1" title="Update"><i class="bi bi-arrow-clockwise"></i></button>
                                            </form>
                                        </td>
                                        <td class="fw-bold"><?php echo $site_settings['currency'] . number_format($item_total, 2); ?></td>
                                        <td class="text-end">
                                            <form action="cart.php" method="POST">
                                                <input type="hidden" name="action" value="remove">
                                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-circle" title="Remove"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <a href="shop.php" class="btn btn-outline-secondary rounded-pill px-4">
                                <i class="bi bi-arrow-left me-1"></i> Continue Shopping
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 bg-light">
                    <div class="card-body p-4">
                        <h4 class="fw-bold border-bottom pb-3 mb-4">Order Summary</h4>

                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Subtotal</span>
                            <span class="fw-bold"><?php echo $site_settings['currency'] . number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-3">
                            <span class="text-muted">Shipping (Flat Rate)</span>
                            <span class="fw-bold"><?php echo $site_settings['currency']; ?>15.00</span>
                        </div>
                        <div class="d-flex justify-content-between mb-4 pb-4 border-bottom">
                            <span class="text-muted">Tax (5%)</span>
                            <span class="fw-bold"><?php echo $site_settings['currency'] . number_format($subtotal * 0.05, 2); ?></span>
                        </div>

                        <div class="d-flex justify-content-between mb-4">
                            <h5 class="fw-bold mb-0">Grand Total</h5>
                            <h4 class="fw-bold text-success mb-0"><?php echo $site_settings['currency'] . number_format($subtotal + 15 + ($subtotal * 0.05), 2); ?></h4>
                        </div>

                        <a href="checkout.php" class="btn btn-success btn-lg w-100 rounded-pill fw-bold shadow-sm">
                            <i class="bi bi-lock me-2"></i>Proceed to Checkout
                        </a>

                        <div class="text-center mt-3">
                            <small class="text-muted"><i class="bi bi-shield-check me-1 text-success"></i> Secure Checkout</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
