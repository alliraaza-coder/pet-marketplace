<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';

// Product Details Logic
$slug = isset($_GET['slug']) ? sanitize_input($_GET['slug']) : '';
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$slug && !$product_id) {
    header('Location: shop.php');
    exit;
}

// Fetch Product Details
$query = "SELECT p.*, c.name_en AS category_name, u.first_name, u.last_name, u.city, u.profile_pic, u.created_at as seller_joined 
          FROM products p 
          JOIN categories c ON p.category_id = c.id 
          JOIN users u ON p.seller_id = u.id 
          WHERE (p.slug = ? OR p.id = ?) AND p.status = 'active' AND p.is_deleted = 0";
$stmt = $conn->prepare($query);
$stmt->bind_param("si", $slug, $product_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Product not found or unavailable.");
}
$product = $result->fetch_assoc();

// Increment views
$conn->query("UPDATE products SET views = views + 1 WHERE id = " . $product['id']);

include 'includes/header.php';
?>

<div class="bg-light py-3 border-bottom">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="index.php" class="text-success text-decoration-none">Home</a></li>
                <li class="breadcrumb-item"><a href="shop.php" class="text-success text-decoration-none">Shop</a></li>
                <li class="breadcrumb-item"><a href="shop.php?category=<?php echo urlencode($product['category_name']); ?>" class="text-success text-decoration-none"><?php echo htmlspecialchars($product['category_name']); ?></a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($product['title_en']); ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-5">
    <?php display_messages(); ?>

    <div class="row mb-5">
        <!-- Gallery -->
        <div class="col-lg-6 mb-4 mb-lg-0">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden sticky-top" style="top: 100px;">
                <img src="https://source.unsplash.com/800x800/?<?php echo urlencode($product['category_name']); ?>" alt="<?php echo htmlspecialchars($product['title_en']); ?>" class="img-fluid w-100" id="mainImage">
            </div>
            <!-- Thumbnails would go here -->
        </div>
        
        <!-- Product Info -->
        <div class="col-lg-6 ps-lg-5">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2"><?php echo htmlspecialchars($product['category_name']); ?></span>
                <span class="text-muted small"><i class="bi bi-eye me-1"></i> <?php echo $product['views']; ?> views</span>
            </div>
            
            <h1 class="fw-bold mb-3"><?php echo htmlspecialchars($product['title_en']); ?></h1>
            
            <div class="d-flex align-items-center mb-4">
                <div class="text-warning me-2">
                    <i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-fill"></i><i class="bi bi-star-half"></i>
                </div>
                <span class="text-muted">(12 Reviews)</span>
            </div>
            
            <div class="mb-4">
                <h2 class="fw-bold text-success mb-0"><?php echo $site_settings['currency']; ?><?php echo number_format($product['price'], 2); ?></h2>
                <?php if($product['is_negotiable']): ?>
                    <span class="badge bg-warning text-dark mt-2">Price Negotiable</span>
                <?php endif; ?>
            </div>
            
            <p class="text-muted mb-4 lh-lg"><?php echo nl2br(htmlspecialchars($product['description_en'])); ?></p>
            
            <!-- Features List -->
            <ul class="list-unstyled row mb-4">
                <?php if($product['age']): ?>
                <li class="col-6 mb-2"><i class="bi bi-check2-circle text-success me-2"></i> <strong>Age:</strong> <?php echo htmlspecialchars($product['age']); ?></li>
                <?php endif; ?>
                <?php if($product['gender'] !== 'Unknown'): ?>
                <li class="col-6 mb-2"><i class="bi bi-check2-circle text-success me-2"></i> <strong>Gender:</strong> <?php echo htmlspecialchars($product['gender']); ?></li>
                <?php endif; ?>
                <?php if($product['breed']): ?>
                <li class="col-6 mb-2"><i class="bi bi-check2-circle text-success me-2"></i> <strong>Breed:</strong> <?php echo htmlspecialchars($product['breed']); ?></li>
                <?php endif; ?>
                <li class="col-6 mb-2"><i class="bi bi-check2-circle text-success me-2"></i> <strong>Vaccinated:</strong> <?php echo $product['vaccination_status'] ? 'Yes' : 'No'; ?></li>
            </ul>
            
            <div class="bg-light p-4 rounded-4 mb-4">
                <form action="cart.php" method="POST" class="row g-3 align-items-center">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                    <div class="col-auto">
                        <label for="quantity" class="col-form-label fw-bold">Quantity:</label>
                    </div>
                    <div class="col-3">
                        <input type="number" id="quantity" name="quantity" class="form-control" value="1" min="1" max="<?php echo $product['stock_quantity']; ?>">
                    </div>
                    <div class="col">
                        <span class="text-muted small"><?php echo $product['stock_quantity']; ?> available in stock</span>
                    </div>
                    
                    <div class="col-12 mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-lg rounded-pill fw-bold flex-grow-1 shadow-sm"><i class="bi bi-cart-plus me-2"></i> Add to Cart</button>
                        <button type="button" class="btn btn-outline-danger btn-lg rounded-pill shadow-sm px-4" onclick="document.getElementById('wishlistForm').submit();" data-bs-toggle="tooltip" title="Add to Wishlist"><i class="bi bi-heart"></i></button>
                    </div>
                </form>
                <!-- Hidden Wishlist Form -->
                <form action="wishlist.php" method="POST" id="wishlistForm" class="d-none">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                </form>
            </div>
            
            <!-- Seller Info -->
            <div class="card border border-light shadow-sm rounded-4 mt-4">
                <div class="card-body p-4 d-flex align-items-center">
                    <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo $product['profile_pic'] ?? 'default-user.png'; ?>" onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($product['first_name'].' '.$product['last_name']); ?>'" class="rounded-circle me-3 border" style="width: 60px; height: 60px;">
                    <div>
                        <h6 class="fw-bold mb-1">Sold by: <?php echo htmlspecialchars($product['first_name'] . ' ' . $product['last_name']); ?></h6>
                        <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i> <?php echo htmlspecialchars($product['city']); ?></div>
                    </div>
                    <div class="ms-auto text-center">
                        <a href="https://wa.me/1234567890" target="_blank" class="btn btn-success btn-sm rounded-circle d-flex align-items-center justify-content-center mx-auto mb-1" style="width: 35px; height: 35px;" data-bs-toggle="tooltip" title="Chat on WhatsApp"><i class="bi bi-whatsapp"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
