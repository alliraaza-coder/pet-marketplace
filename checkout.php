<?php
/**
 * Checkout Page
 * Phase 3.2 — Checkout, Orders & Payments
 */
require_once 'includes/config.php';
require_once 'includes/auth.php';

// 1. Validate Login
require_login();

// 2. Validate Cart
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    $_SESSION['error'] = "Your cart is empty. Please add items to your cart before checking out.";
    header('Location: cart.php');
    exit;
}

$user = current_user($conn);
$cart_items = $_SESSION['cart'];

// 3. Cart Stock Validation & Totals Calculation
$subtotal = 0;
$cart_valid = true;
$validation_errors = [];

foreach ($cart_items as $id => $item) {
    // Query latest product info from DB to check stock and status
    $stmt = $conn->prepare("SELECT title_en, price, stock_quantity, status FROM products WHERE id = ?");
    $stmt->bind_param("i", $item['id']);
    $stmt->execute();
    $prod_res = $stmt->get_result()->fetch_assoc();

    if (!$prod_res) {
        $validation_errors[] = "Product '{$item['title']}' no longer exists.";
        unset($_SESSION['cart'][$id]);
        $cart_valid = false;
        continue;
    }

    if ($prod_res['status'] !== 'active') {
        $validation_errors[] = "Product '{$prod_res['title_en']}' is no longer available.";
        unset($_SESSION['cart'][$id]);
        $cart_valid = false;
        continue;
    }

    if ($prod_res['stock_quantity'] < $item['quantity']) {
        if ($prod_res['stock_quantity'] == 0) {
            $validation_errors[] = "Product '{$prod_res['title_en']}' is out of stock.";
            unset($_SESSION['cart'][$id]);
        } else {
            $validation_errors[] = "Only {$prod_res['stock_quantity']} units of '{$prod_res['title_en']}' are available. Your cart has been updated.";
            $_SESSION['cart'][$id]['quantity'] = $prod_res['stock_quantity'];
        }
        $cart_valid = false;
        continue;
    }

    // Update session price in case it changed in DB
    $_SESSION['cart'][$id]['price'] = $prod_res['price'];
    $item_total = $prod_res['price'] * $_SESSION['cart'][$id]['quantity'];
    $subtotal += $item_total;
}

if (!$cart_valid) {
    $_SESSION['error'] = implode("<br>", $validation_errors);
    header('Location: cart.php');
    exit;
}

// Order Calculations
$shipping_fee = 15.00; // Flat shipping rate
$tax_rate = 0.05; // 5% tax
$tax = $subtotal * $tax_rate;
$grand_total = $subtotal + $shipping_fee + $tax;

include 'includes/header.php';
?>

<div class="bg-light py-4 border-bottom mb-4">
    <div class="container">
        <h1 class="fw-bold mb-1">Checkout</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php" class="text-success text-decoration-none">Home</a></li>
                <li class="breadcrumb-item"><a href="cart.php" class="text-success text-decoration-none">Cart</a></li>
                <li class="breadcrumb-item active" aria-current="page">Checkout</li>
            </ol>
        </nav>
    </div>
</div>

<div class="container pb-5">
    <?php display_messages(); ?>

    <form action="process_checkout.php" method="POST" id="checkoutForm">
        <div class="row g-4">
            <!-- Checkout Details Column -->
            <div class="col-lg-8">
                <!-- Shipping Address Card -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="fw-bold mb-0 text-success"><i class="bi bi-truck me-2"></i>Shipping Address</h4>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="shipping_first_name" class="form-label fw-medium">First Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_first_name" name="shipping_first_name" required value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="shipping_last_name" class="form-label fw-medium">Last Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_last_name" name="shipping_last_name" required value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="shipping_phone" class="form-label fw-medium">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control rounded-3" id="shipping_phone" name="shipping_phone" required value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="shipping_email" class="form-label fw-medium">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control rounded-3" id="shipping_email" name="shipping_email" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label for="shipping_address" class="form-label fw-medium">Street Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_address" name="shipping_address" placeholder="House number and street name" required value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="shipping_city" class="form-label fw-medium">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_city" name="shipping_city" required value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="shipping_state" class="form-label fw-medium">State / Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_state" name="shipping_state" required value="<?php echo htmlspecialchars($user['state'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label for="shipping_zip_code" class="form-label fw-medium">Zip / Postal Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_zip_code" name="shipping_zip_code" required value="<?php echo htmlspecialchars($user['zip_code'] ?? ''); ?>">
                            </div>
                            <div class="col-md-12">
                                <label for="shipping_country" class="form-label fw-medium">Country <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="shipping_country" name="shipping_country" required value="<?php echo htmlspecialchars($user['country'] ?? 'Pakistan'); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Billing Address Card -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="fw-bold mb-0 text-success"><i class="bi bi-receipt me-2"></i>Billing Address</h4>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="form-check mb-3">
                            <input class="form-check-input text-success" type="checkbox" id="same_as_shipping" name="same_as_shipping" value="1" checked onchange="toggleBillingAddress()">
                            <label class="form-check-label fw-medium" for="same_as_shipping">
                                Billing Address is the same as Shipping Address
                            </label>
                        </div>

                        <div id="billing_address_fields" class="row g-3 d-none">
                            <div class="col-12">
                                <label for="billing_address" class="form-label fw-medium">Street Address <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="billing_address" name="billing_address" placeholder="House number and street name">
                            </div>
                            <div class="col-md-4">
                                <label for="billing_city" class="form-label fw-medium">City <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="billing_city" name="billing_city">
                            </div>
                            <div class="col-md-4">
                                <label for="billing_state" class="form-label fw-medium">State / Province <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="billing_state" name="billing_state">
                            </div>
                            <div class="col-md-4">
                                <label for="billing_zip_code" class="form-label fw-medium">Zip / Postal Code <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="billing_zip_code" name="billing_zip_code">
                            </div>
                            <div class="col-md-12">
                                <label for="billing_country" class="form-label fw-medium">Country <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" id="billing_country" name="billing_country" value="Pakistan">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Payment Methods Card -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="fw-bold mb-0 text-success"><i class="bi bi-credit-card me-2"></i>Payment Method</h4>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <div class="row g-3">
                            <!-- Escrow Message -->
                            <div class="col-12 mb-2">
                                <div class="alert alert-info border-0 shadow-sm d-flex align-items-center">
                                    <i class="bi bi-shield-lock-fill fs-4 me-3"></i>
                                    <div>
                                        Your payment is securely held by PetMarket in Escrow. Payment will only be released to the Seller after successful delivery confirmation by Buyer and Seller followed by Admin approval.
                                    </div>
                                </div>
                            </div>

                            <!-- Bank Transfer Option -->
                            <div class="col-md-6">
                                <div class="payment-method-card p-3 border rounded-3 h-100 position-relative cursor-pointer" onclick="selectPaymentMethod('Bank Transfer')">
                                    <div class="form-check">
                                        <input class="form-check-input text-success" type="radio" name="payment_method" id="pay_bank" value="Bank Transfer">
                                        <label class="form-check-label fw-bold d-block" for="pay_bank">
                                            Bank Transfer
                                            <span class="d-block text-muted fw-normal small">Transfer directly to our corporate account.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- JazzCash Option -->
                            <div class="col-md-6">
                                <div class="payment-method-card p-3 border rounded-3 h-100 position-relative cursor-pointer" onclick="selectPaymentMethod('JazzCash')">
                                    <div class="form-check">
                                        <input class="form-check-input text-success" type="radio" name="payment_method" id="pay_jazzcash" value="JazzCash">
                                        <label class="form-check-label fw-bold d-block" for="pay_jazzcash">
                                            JazzCash (Mobile Account)
                                            <span class="d-block text-muted fw-normal small">Instant payment via JazzCash Wallet.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <!-- EasyPaisa Option -->
                            <div class="col-md-6">
                                <div class="payment-method-card p-3 border rounded-3 h-100 position-relative cursor-pointer" onclick="selectPaymentMethod('EasyPaisa')">
                                    <div class="form-check">
                                        <input class="form-check-input text-success" type="radio" name="payment_method" id="pay_easypaisa" value="EasyPaisa">
                                        <label class="form-check-label fw-bold d-block" for="pay_easypaisa">
                                            EasyPaisa (Mobile Account)
                                            <span class="d-block text-muted fw-normal small">Instant payment via EasyPaisa Wallet.</span>
                                        </label>
                                    </div>
                                </div>
                            </div>


                        </div>

                        <!-- Dynamic Details Areas -->
                        <div class="mt-4 p-3 bg-light rounded-3 d-none" id="payment_details_container">
                            <!-- Bank Transfer Details -->
                            <div id="details_bank" class="payment-detail-block d-none">
                                <h6 class="fw-bold text-success mb-2">Direct Bank Transfer Details</h6>
                                <p class="small text-muted mb-0">
                                    Please transfer the total amount to the following bank account:<br>
                                    <strong>Bank Name:</strong> Allied Bank Limited (ABL)<br>
                                    <strong>Account Title:</strong> Pet Marketplace Ltd.<br>
                                    <strong>Account Number:</strong> 1234-5678-9012-34<br>
                                    <strong>IBAN:</strong> PK12 ABIL 0010 0987 6543 2100<br>
                                    <em>Note: Share screenshot of the payment receipt with support for verification.</em>
                                </p>
                            </div>

                            <!-- JazzCash Details -->
                            <div id="details_jazzcash" class="payment-detail-block d-none">
                                <h6 class="fw-bold text-danger mb-2">JazzCash Mobile Wallet Details</h6>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="jazzcash_phone" class="form-label small mb-1 fw-medium">JazzCash Mobile Number <span class="text-danger">*</span></label>
                                        <input type="tel" class="form-control form-control-sm rounded-3" id="jazzcash_phone" name="jazzcash_phone" placeholder="e.g. 03001234567">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="jazzcash_mpin" class="form-label small mb-1 fw-medium">Simulated MPIN Wallet Pin (Demo)</label>
                                        <input type="password" class="form-control form-control-sm rounded-3" id="jazzcash_mpin" name="jazzcash_mpin" placeholder="Enter 4 digit PIN (demo)" maxlength="4">
                                    </div>
                                </div>
                            </div>

                            <!-- EasyPaisa Details -->
                            <div id="details_easypaisa" class="payment-detail-block d-none">
                                <h6 class="fw-bold text-primary mb-2">EasyPaisa Mobile Wallet Details</h6>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label for="easypaisa_phone" class="form-label small mb-1 fw-medium">EasyPaisa Mobile Number <span class="text-danger">*</span></label>
                                        <input type="tel" class="form-control form-control-sm rounded-3" id="easypaisa_phone" name="easypaisa_phone" placeholder="e.g. 03451234567">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="easypaisa_pin" class="form-label small mb-1 fw-medium">Simulated Account PIN (Demo)</label>
                                        <input type="password" class="form-control form-control-sm rounded-3" id="easypaisa_pin" name="easypaisa_pin" placeholder="Enter 5 digit PIN (demo)" maxlength="5">
                                    </div>
                                </div>
                            </div>


                        </div>
                    </div>
                </div>
            </div>

            <!-- Order Summary Column -->
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm rounded-4 sticky-top" style="top: 100px; z-index: 1;">
                    <div class="card-header bg-white border-0 pt-4 px-4">
                        <h4 class="fw-bold mb-0 text-success">Order Summary</h4>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <!-- Products List -->
                        <div class="order-items-list mb-3" style="max-height: 240px; overflow-y: auto;">
                            <?php foreach ($cart_items as $item): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="pe-3">
                                        <h6 class="fw-bold mb-0 text-dark small"><?php echo htmlspecialchars($item['title']); ?></h6>
                                        <span class="text-muted small">Qty: <?php echo $item['quantity']; ?></span>
                                    </div>
                                    <span class="fw-bold text-success text-nowrap"><?php echo $site_settings['currency'] . number_format($item['price'] * $item['quantity'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <hr class="my-3">

                        <!-- Calculations -->
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Subtotal</span>
                            <span class="fw-bold"><?php echo $site_settings['currency'] . number_format($subtotal, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Shipping Charges</span>
                            <span class="fw-bold"><?php echo $site_settings['currency'] . number_format($shipping_fee, 2); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">Tax (5%)</span>
                            <span class="fw-bold"><?php echo $site_settings['currency'] . number_format($tax, 2); ?></span>
                        </div>
                        <hr class="my-3">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <span class="fw-bold text-dark fs-5">Grand Total</span>
                            <span class="fw-bold text-success fs-4" id="checkout_grand_total"><?php echo $site_settings['currency'] . number_format($grand_total, 2); ?></span>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" class="btn btn-success btn-lg w-100 rounded-pill fw-bold shadow-sm mb-3">
                            <i class="bi bi-lock-fill me-2"></i>Place Order
                        </button>
                        <p class="text-center text-muted small mb-0"><i class="bi bi-shield-lock me-1"></i>Secure checkout & encrypted payment processing.</p>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<style>
.payment-method-card {
    transition: all 0.25s ease;
    border-color: #dee2e6 !important;
}
.payment-method-card:hover {
    border-color: #198754 !important;
    background-color: #f8f9fa;
}
.payment-method-card.selected {
    border-color: #198754 !important;
    background-color: rgba(25, 135, 84, 0.05);
}
.payment-method-card input[type="radio"]:checked + label {
    color: #198754;
}
</style>

<script>
function toggleBillingAddress() {
    const sameAsShipping = document.getElementById('same_as_shipping').checked;
    const billingFields = document.getElementById('billing_address_fields');
    const inputs = billingFields.querySelectorAll('input');
    
    if (sameAsShipping) {
        billingFields.classList.add('d-none');
        inputs.forEach(input => input.removeAttribute('required'));
    } else {
        billingFields.classList.remove('d-none');
        inputs.forEach(input => input.setAttribute('required', 'required'));
    }
}

function selectPaymentMethod(method) {
    // Uncheck other radios & select card wrapper class styling
    const methods = ['Bank Transfer', 'JazzCash', 'EasyPaisa'];
    const ids = {
        'Bank Transfer': 'pay_bank',
        'JazzCash': 'pay_jazzcash',
        'EasyPaisa': 'pay_easypaisa'
    };

    // Update checked attributes
    document.getElementById(ids[method]).checked = true;

    // Apply selection classes
    document.querySelectorAll('.payment-method-card').forEach(card => {
        card.classList.remove('selected');
    });

    const activeCard = document.getElementById(ids[method]).closest('.payment-method-card');
    activeCard.classList.add('selected');

    // Show corresponding detail fields if any
    const detailsContainer = document.getElementById('payment_details_container');
    const blockBank = document.getElementById('details_bank');
    const blockJazz = document.getElementById('details_jazzcash');
    const blockEasy = document.getElementById('details_easypaisa');

    // Clear required flags first
    document.querySelectorAll('.payment-detail-block input').forEach(input => input.removeAttribute('required'));

    // Hide all
    blockBank.classList.add('d-none');
    blockJazz.classList.add('d-none');
    blockEasy.classList.add('d-none');
    detailsContainer.classList.add('d-none');

    if (method === 'Bank Transfer') {
        detailsContainer.classList.remove('d-none');
        blockBank.classList.remove('d-none');
    } else if (method === 'JazzCash') {
        detailsContainer.classList.remove('d-none');
        blockJazz.classList.remove('d-none');
        document.getElementById('jazzcash_phone').setAttribute('required', 'required');
    } else if (method === 'EasyPaisa') {
        detailsContainer.classList.remove('d-none');
        blockEasy.classList.remove('d-none');
        document.getElementById('easypaisa_phone').setAttribute('required', 'required');
    }
}

// Initial Selection logic triggers UI
document.addEventListener("DOMContentLoaded", function() {
    toggleBillingAddress();
    selectPaymentMethod('Bank Transfer');
});
</script>

<?php include 'includes/footer.php'; ?>
