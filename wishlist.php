<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Must be logged in to use wishlist
require_login();
$user_id = current_user($conn)['id'];

// Handle Add/Remove Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action     = $_POST['action'];
    $product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

    if ($action === 'add' && $product_id > 0) {
        $stmt = $conn->prepare("SELECT id FROM wishlists WHERE user_id = ? AND product_id = ?");
        $stmt->bind_param("ii", $user_id, $product_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows == 0) {
            $ins = $conn->prepare("INSERT INTO wishlists (user_id, product_id) VALUES (?, ?)");
            $ins->bind_param("ii", $user_id, $product_id);
            $ins->execute();
            $_SESSION['success'] = "Added to your Wishlist!";
        } else {
            $_SESSION['error'] = "Product is already in your wishlist.";
        }
        header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'wishlist.php'));
        exit;
    }

    if ($action === 'remove' && $product_id > 0) {
        $del = $conn->prepare("DELETE FROM wishlists WHERE user_id = ? AND product_id = ?");
        $del->bind_param("ii", $user_id, $product_id);
        $del->execute();
        $_SESSION['success'] = "Removed from wishlist.";
        header('Location: wishlist.php');
        exit;
    }
}

// Fetch Wishlist Items with images
$query = "SELECT p.*, c.name_en as category_name
          FROM wishlists w
          JOIN products p ON w.product_id = p.id
          JOIN categories c ON p.category_id = c.id
          WHERE w.user_id = ? AND p.status = 'active'
          ORDER BY w.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$wishlist_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

include 'includes/header.php';
?>

<div class="bg-light py-4 border-bottom">
    <div class="container">
        <h2 class="fw-bold mb-0"><i class="bi bi-heart text-danger me-2"></i> My Wishlist</h2>
    </div>
</div>

<div class="container py-5">
    <?php display_messages(); ?>

    <?php if (empty($wishlist_items)): ?>
        <div class="text-center py-5">
            <i class="bi bi-heart-break fs-1 text-muted mb-3 d-block"></i>
            <h4 class="fw-bold">Your wishlist is empty</h4>
            <p class="text-muted mb-4">Save items you love here and buy them later.</p>
            <a href="shop.php" class="btn btn-outline-success rounded-pill px-4">Browse Products</a>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($wishlist_items as $item):
                $item_img = get_product_image_url($conn, $item['id']);
            ?>
            <div class="col-md-6 col-lg-3">
                <div class="product-card h-100 position-relative">
                    <!-- Remove Button -->
                    <form action="wishlist.php" method="POST" class="position-absolute" style="top: 10px; right: 10px; z-index: 5;">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-danger rounded-circle shadow-sm" title="Remove"><i class="bi bi-x-lg"></i></button>
                    </form>

                    <div class="product-img-wrapper">
                        <img src="<?php echo $item_img; ?>" alt="<?php echo htmlspecialchars($item['title_en']); ?>">
                    </div>
                    <div class="product-info p-3">
                        <span class="product-category small text-muted"><?php echo htmlspecialchars($item['category_name']); ?></span>
                        <a href="product_details.php?id=<?php echo $item['id']; ?>" class="text-decoration-none">
                            <h5 class="fw-bold mb-2 text-dark text-truncate"><?php echo htmlspecialchars($item['title_en']); ?></h5>
                        </a>
                        <div class="product-price fs-5 fw-bold text-success mb-3"><?php echo $site_settings['currency'] . number_format($item['price'], 2); ?></div>

                        <div class="d-flex gap-2">
                            <form action="cart.php" method="POST" class="flex-grow-1">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <input type="hidden" name="quantity" value="1">
                                <button type="submit" class="btn btn-success btn-sm w-100 rounded-pill fw-bold">
                                    <i class="bi bi-cart-plus me-1"></i> Add to Cart
                                </button>
                            </form>
                            <a href="product_details.php?id=<?php echo $item['id']; ?>" class="btn btn-outline-success btn-sm rounded-pill px-3">
                                <i class="bi bi-eye"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
