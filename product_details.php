<?php
/**
 * product_details.php
 * Displays full details for a single product, fetched by ?id=
 * Pet Marketplace E-Commerce Platform
 */

require_once 'includes/config.php';
require_once 'includes/auth.php';

// Get product ID from query string
$product_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$slug       = isset($_GET['slug']) ? sanitize_input($_GET['slug']) : '';

if (!$product_id && !$slug) {
    header('Location: shop.php');
    exit;
}

// ── Fetch Product Details ──────────────────────────────────────────────────
// Use LEFT JOINs so we don't lose the product if user/category is deleted/missing.
// Condition matches user requirements: status='active', listing_type='store'
$query = "SELECT p.*, 
            c.name_en  AS category_name,
            c.slug     AS category_slug,
            u.first_name, u.last_name, u.city AS seller_city,
            u.profile_pic, u.phone AS seller_phone,
            u.created_at AS seller_joined
     FROM   products   p
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN users      u ON p.seller_id   = u.id
     WHERE  p.status = 'active' AND p.listing_type = 'store' ";

if ($product_id > 0) {
    $query .= "AND p.id = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $product_id);
} else {
    $query .= "AND p.slug = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $slug);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Product not found – show a friendly error page
    include 'includes/header.php';
    echo '<div class="container py-5 text-center">
            <i class="bi bi-exclamation-circle display-1 text-muted"></i>
            <h2 class="mt-4 fw-bold">Product Not Found</h2>
            <p class="text-muted">The product you are looking for does not exist or has been removed.</p>
            <a href="shop.php" class="btn btn-success rounded-pill px-4 mt-2">Back to Shop</a>
          </div>';
    include 'includes/footer.php';
    exit;
}

$product = $result->fetch_assoc();

// Use the resolved numeric ID from the DB row from now on
$product_id = (int)$product['id'];

// ── Increment View Count ───────────────────────────────────────────────────
$conn->query("UPDATE products SET views = views + 1 WHERE id = $product_id");

// ── Fetch All Product Images ───────────────────────────────────────────────
$img_stmt = $conn->prepare(
    "SELECT image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC"
);
$img_stmt->bind_param("i", $product_id);
$img_stmt->execute();
$images = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build the main image URL (uploaded file → inline SVG fallback)
$main_image = get_product_image_url($conn, $product_id);

// ── Fetch Reviews ──────────────────────────────────────────────────────────
$rev_stmt = $conn->prepare(
    "SELECT r.*, u.first_name, u.last_name, u.profile_pic
     FROM   reviews r
     JOIN   users   u ON r.user_id = u.id
     WHERE  r.product_id = ? AND r.status = 'approved'
     ORDER  BY r.created_at DESC
     LIMIT  10"
);
$rev_stmt->bind_param("i", $product_id);
$rev_stmt->execute();
$reviews     = $rev_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$review_count = count($reviews);
$avg_rating   = $review_count > 0
    ? round(array_sum(array_column($reviews, 'rating')) / $review_count, 1)
    : 0;

// ── Fetch Related Products (same category, exclude current) ───────────────
$rel_stmt = $conn->prepare(
    "SELECT p.id, p.title_en, p.slug, p.price, p.is_featured,
            c.name_en AS category_name
     FROM   products   p
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE  p.category_id = ? AND p.id != ? AND p.status = 'active' AND p.listing_type = 'store' AND p.stock_quantity > 0
     ORDER  BY p.is_featured DESC, p.created_at DESC
     LIMIT  4"
);
$rel_stmt->bind_param("ii", $product['category_id'], $product_id);
$rel_stmt->execute();
$related_products = $rel_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Check if item is in user's wishlist ───────────────────────────────────
$in_wishlist = false;
if (isset($_SESSION['user_id'])) {
    $wl = $conn->prepare("SELECT id FROM wishlists WHERE user_id = ? AND product_id = ?");
    $wl->bind_param("ii", $_SESSION['user_id'], $product_id);
    $wl->execute();
    $in_wishlist = $wl->get_result()->num_rows > 0;
}

// ── Page title for <head> ──────────────────────────────────────────────────
$page_title = htmlspecialchars($product['title_en']) . ' – ' . $site_settings['site_name'];

include 'includes/header.php';
?>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<!--  Inline page styles (scoped to product-details)                       -->
<!-- ══════════════════════════════════════════════════════════════════════ -->
<style>
  /* ── Gallery ── */
  .pd-gallery-main {
    width: 100%;
    aspect-ratio: 1 / 1;
    object-fit: cover;
    border-radius: 1rem;
    border: 1px solid #e5e7eb;
    background: #f9fafb;
    transition: opacity .25s ease;
  }
  .pd-thumb {
    width: 72px;
    height: 72px;
    object-fit: cover;
    border-radius: .5rem;
    border: 2px solid transparent;
    cursor: pointer;
    transition: border-color .2s, transform .2s;
  }
  .pd-thumb:hover, .pd-thumb.active {
    border-color: #198754;
    transform: scale(1.06);
  }

  /* ── Sticky sidebar ── */
  @media (min-width: 992px) {
    .pd-sticky { position: sticky; top: 110px; }
  }

  /* ── Star rating display ── */
  .star-filled { color: #f59e0b; }
  .star-empty  { color: #d1d5db; }

  /* ── Feature pill ── */
  .feature-pill {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    background: #f0fdf4;
    color: #166534;
    border: 1px solid #bbf7d0;
    border-radius: 999px;
    padding: .3rem .9rem;
    font-size: .84rem;
    font-weight: 500;
  }

  /* ── Related card hover ── */
  .related-card {
    border: none;
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    transition: transform .25s, box-shadow .25s;
  }
  .related-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 28px rgba(0,0,0,.13);
  }
  .related-card img {
    width: 100%;
    height: 180px;
    object-fit: cover;
  }

  /* ── Review card ── */
  .review-card {
    border-radius: .75rem;
    border: 1px solid #e5e7eb;
    background: #fff;
    padding: 1.25rem;
    margin-bottom: 1rem;
  }

  /* ── WhatsApp btn ── */
  .btn-whatsapp {
    background: #25D366;
    color: #fff;
    border: none;
  }
  .btn-whatsapp:hover { background: #1ebe5d; color: #fff; }

  /* ── Quantity stepper ── */
  .qty-stepper { display: flex; align-items: center; gap: 0; }
  .qty-stepper button {
    width: 36px; height: 36px;
    border: 1px solid #dee2e6;
    background: #f9fafb;
    font-size: 1.1rem;
    line-height: 1;
    cursor: pointer;
    transition: background .15s;
  }
  .qty-stepper button:hover { background: #e9ecef; }
  .qty-stepper input {
    width: 56px; height: 36px;
    text-align: center;
    border: 1px solid #dee2e6;
    border-left: none; border-right: none;
    font-weight: 600;
    -moz-appearance: textfield;
  }
  .qty-stepper input::-webkit-inner-spin-button,
  .qty-stepper input::-webkit-outer-spin-button { display: none; }
</style>

<!-- ── Breadcrumb ────────────────────────────────────────────────────────── -->
<div class="bg-light py-3 border-bottom">
  <div class="container">
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0 small">
        <li class="breadcrumb-item"><a href="index.php" class="text-success text-decoration-none">Home</a></li>
        <li class="breadcrumb-item"><a href="shop.php"  class="text-success text-decoration-none">Shop</a></li>
        <li class="breadcrumb-item">
          <a href="shop.php?category=<?php echo urlencode($product['category_slug'] ?? ''); ?>"
             class="text-success text-decoration-none">
            <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?>
          </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
          <?php echo htmlspecialchars($product['title_en']); ?>
        </li>
      </ol>
    </nav>
  </div>
</div>

<!-- ── Main Content ─────────────────────────────────────────────────────── -->
<div class="container py-5">

  <?php if (function_exists('display_messages')) display_messages(); ?>

  <!-- ── Product Hero Row ─────────────────────────────────────────────── -->
  <div class="row g-5 mb-5">

    <!-- ── Left: Image Gallery ────────────────────────────────────────── -->
    <div class="col-lg-5">
      <div class="pd-sticky">
        <!-- Main image -->
        <img id="mainImage"
             src="<?php echo $main_image; ?>"
             alt="<?php echo htmlspecialchars($product['title_en']); ?>"
             class="pd-gallery-main mb-3 w-100">

        <!-- Thumbnails (if uploaded images exist) -->
        <?php if (!empty($images)): ?>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($images as $i => $img):
            $thumb_url = BASE_URL . '/assets/uploads/products/' . $img['image_url'];
            $is_active = $i === 0 ? 'active' : '';
          ?>
          <img src="<?php echo $thumb_url; ?>"
               alt="Thumbnail <?php echo $i + 1; ?>"
               class="pd-thumb <?php echo $is_active; ?>"
               onclick="switchImage(this, '<?php echo $thumb_url; ?>')">
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Right: Product Info ────────────────────────────────────────── -->
    <div class="col-lg-7">

      <!-- Category badge + views -->
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3 py-2 fs-sm">
          <?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?>
        </span>
        <span class="text-muted small">
          <i class="bi bi-eye me-1"></i><?php echo number_format($product['views']); ?> views
        </span>
      </div>

      <!-- Title -->
      <h1 class="fw-bold mb-2 fs-2"><?php echo htmlspecialchars($product['title_en']); ?></h1>

      <!-- Star rating summary -->
      <div class="d-flex align-items-center gap-2 mb-3">
        <?php
          $full  = floor($avg_rating);
          $half  = ($avg_rating - $full) >= 0.5 ? 1 : 0;
          $empty = 5 - $full - $half;
          for ($s = 0; $s < $full;  $s++) echo '<i class="bi bi-star-fill star-filled"></i>';
          if ($half)                         echo '<i class="bi bi-star-half star-filled"></i>';
          for ($s = 0; $s < $empty; $s++) echo '<i class="bi bi-star star-empty"></i>';
        ?>
        <span class="text-muted small">
          <?php echo $avg_rating > 0 ? $avg_rating : 'No'; ?> rating
          (<?php echo $review_count; ?> review<?php echo $review_count !== 1 ? 's' : ''; ?>)
        </span>
      </div>

      <!-- Price -->
      <div class="mb-3 d-flex align-items-baseline gap-2">
        <span class="fs-2 fw-bold text-success">
          <?php echo $site_settings['currency'] . number_format($product['price'], 2); ?>
        </span>
        <?php if ($product['is_negotiable']): ?>
          <span class="badge bg-warning text-dark rounded-pill">Price Negotiable</span>
        <?php endif; ?>
      </div>

      <!-- Short description -->
      <p class="text-muted lh-lg mb-4">
        <?php echo nl2br(htmlspecialchars($product['description_en'])); ?>
      </p>

      <!-- Feature pills -->
      <div class="d-flex flex-wrap gap-2 mb-4">
        <?php if (!empty($product['age'])): ?>
          <span class="feature-pill"><i class="bi bi-calendar3"></i> Age: <?php echo htmlspecialchars($product['age']); ?></span>
        <?php endif; ?>
        <?php if ($product['gender'] && $product['gender'] !== 'Unknown'): ?>
          <span class="feature-pill"><i class="bi bi-gender-ambiguous"></i> <?php echo htmlspecialchars($product['gender']); ?></span>
        <?php endif; ?>
        <?php if (!empty($product['breed'])): ?>
          <span class="feature-pill"><i class="bi bi-award"></i> <?php echo htmlspecialchars($product['breed']); ?></span>
        <?php endif; ?>
        <span class="feature-pill">
          <i class="bi bi-shield-check"></i>
          Vaccinated: <?php echo $product['vaccination_status'] ? 'Yes' : 'No'; ?>
        </span>
        <?php if ($product['health_certificate']): ?>
          <span class="feature-pill"><i class="bi bi-file-medical"></i> Health Certificate</span>
        <?php endif; ?>
        <?php if (!empty($product['city'])): ?>
          <span class="feature-pill"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($product['city']); ?></span>
        <?php endif; ?>
      </div>

      <!-- Stock -->
      <p class="text-muted small mb-4">
        <i class="bi bi-box-seam me-1 text-success"></i>
        <strong><?php echo (int)$product['stock_quantity']; ?></strong> available in stock
      </p>

      <!-- ── Add to Cart form ────────────────────────────────────────── -->
      <?php if ((int)$product['stock_quantity'] > 0): ?>
      <form action="cart.php" method="POST" class="mb-3">
        <input type="hidden" name="action"     value="add">
        <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

        <div class="d-flex align-items-center gap-3 flex-wrap">
          <!-- Quantity stepper -->
          <div class="qty-stepper">
            <button type="button" onclick="adjustQty(-1)">−</button>
            <input type="number" id="quantityInput" name="quantity"
                   value="1" min="1"
                   max="<?php echo (int)$product['stock_quantity']; ?>">
            <button type="button" onclick="adjustQty(1)">+</button>
          </div>

          <!-- Add to Cart -->
          <button type="submit"
                  class="btn btn-success btn-lg rounded-pill fw-bold px-5 shadow-sm flex-grow-1">
            <i class="bi bi-cart-plus me-2"></i>Add to Cart
          </button>
        </div>
      </form>
      <?php else: ?>
      <div class="alert alert-danger d-inline-block px-4 rounded-pill mb-4 fw-bold">
        <i class="bi bi-x-circle me-1"></i> Out of Stock
      </div>
      <?php endif; ?>

      <!-- ── Wishlist + WhatsApp ──────────────────────────────────────── -->
      <div class="d-flex gap-2 flex-wrap mb-4">
        <form action="wishlist.php" method="POST" class="d-inline">
          <input type="hidden" name="action"     value="<?php echo $in_wishlist ? 'remove' : 'add'; ?>">
          <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
          <button type="submit"
                  class="btn <?php echo $in_wishlist ? 'btn-danger' : 'btn-outline-danger'; ?> rounded-pill px-4">
            <i class="bi <?php echo $in_wishlist ? 'bi-heart-fill' : 'bi-heart'; ?> me-1"></i>
            <?php echo $in_wishlist ? 'Wishlisted' : 'Wishlist'; ?>
          </button>
        </form>

        <?php if (!empty($product['seller_phone'])): ?>
        <a href="https://wa.me/<?php echo preg_replace('/\D/', '', $product['seller_phone']); ?>"
           target="_blank" rel="noopener"
           class="btn btn-whatsapp rounded-pill px-4">
          <i class="bi bi-whatsapp me-1"></i>Chat on WhatsApp
        </a>
        <?php endif; ?>
      </div>

      <!-- ── Seller Card ──────────────────────────────────────────────── -->
      <div class="card border rounded-4 shadow-sm">
        <div class="card-body p-4 d-flex align-items-center gap-3">
          <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($product['profile_pic'] ?? 'default-user.png'); ?>"
               onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode(($product['first_name']??'') . ' ' . ($product['last_name']??'')); ?>&background=198754&color=fff'"
               class="rounded-circle border flex-shrink-0"
               style="width:60px;height:60px;object-fit:cover;"
               alt="Seller">
          <div class="flex-grow-1">
            <h6 class="fw-bold mb-0">
              <?php echo htmlspecialchars(($product['first_name'] ?? 'Unknown') . ' ' . ($product['last_name'] ?? 'Seller')); ?>
            </h6>
            <small class="text-muted">
              <i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($product['seller_city'] ?? 'N/A'); ?>
            </small><br>
            <small class="text-muted">
              <i class="bi bi-calendar3 me-1"></i>
              Member since <?php echo date('M Y', strtotime($product['seller_joined'] ?? 'now')); ?>
            </small>
          </div>
          <span class="badge bg-success rounded-pill">Verified Seller</span>
        </div>
      </div>

    </div><!-- /col right -->
  </div><!-- /row hero -->

  <!-- ── Reviews Section ──────────────────────────────────────────────── -->
  <div class="row g-4 mb-5">
    <div class="col-12">
      <h3 class="fw-bold mb-4">Customer Reviews
        <span class="badge bg-success rounded-pill fs-6 ms-2"><?php echo $review_count; ?></span>
      </h3>

      <?php if (!empty($reviews)): ?>
        <?php foreach ($reviews as $rev): ?>
        <div class="review-card">
          <div class="d-flex align-items-center gap-3 mb-2">
            <img src="<?php echo BASE_URL; ?>/assets/images/<?php echo htmlspecialchars($rev['profile_pic'] ?? 'default-user.png'); ?>"
                 onerror="this.src='https://ui-avatars.com/api/?name=<?php echo urlencode($rev['first_name'] . ' ' . $rev['last_name']); ?>&size=40&background=e0e7ef&color=333'"
                 class="rounded-circle border flex-shrink-0"
                 style="width:42px;height:42px;object-fit:cover;" alt="Reviewer">
            <div>
              <strong><?php echo htmlspecialchars($rev['first_name'] . ' ' . $rev['last_name']); ?></strong>
              <div>
                <?php for ($s = 1; $s <= 5; $s++): ?>
                  <i class="bi bi-star<?php echo $s <= $rev['rating'] ? '-fill star-filled' : ' star-empty'; ?>"></i>
                <?php endfor; ?>
              </div>
            </div>
            <span class="ms-auto text-muted small">
              <?php echo date('d M Y', strtotime($rev['created_at'])); ?>
            </span>
          </div>
          <?php if (!empty($rev['comment'])): ?>
            <p class="mb-0 text-muted"><?php echo nl2br(htmlspecialchars($rev['comment'])); ?></p>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="text-center py-4 text-muted">
          <i class="bi bi-chat-square-text fs-1 d-block mb-2"></i>
          No reviews yet. Be the first to review this product!
        </div>
      <?php endif; ?>
    </div>
  </div><!-- /reviews -->

  <!-- ── Related Products ─────────────────────────────────────────────── -->
  <?php if (!empty($related_products)): ?>
  <div class="mb-5">
    <h3 class="fw-bold mb-4">Related Products</h3>
    <div class="row g-4">
      <?php foreach ($related_products as $rp):
        $rp_img = get_product_image_url($conn, $rp['id']);
      ?>
      <div class="col-sm-6 col-md-3">
        <a href="product_details.php?id=<?php echo $rp['id']; ?>"
           class="text-decoration-none text-dark">
          <div class="related-card card h-100">
            <img src="<?php echo $rp_img; ?>"
                 alt="<?php echo htmlspecialchars($rp['title_en']); ?>">
            <div class="card-body">
              <small class="text-success fw-medium"><?php echo htmlspecialchars($rp['category_name'] ?? 'Uncategorized'); ?></small>
              <h6 class="fw-bold mt-1 mb-1 lh-sm"><?php echo htmlspecialchars($rp['title_en']); ?></h6>
              <p class="mb-0 fw-bold text-success">
                <?php echo $site_settings['currency'] . number_format($rp['price'], 2); ?>
              </p>
            </div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /container -->

<!-- ── Inline JS ────────────────────────────────────────────────────────── -->
<script>
  /* Gallery thumbnail switcher */
  function switchImage(thumb, url) {
    document.getElementById('mainImage').src = url;
    document.querySelectorAll('.pd-thumb').forEach(t => t.classList.remove('active'));
    thumb.classList.add('active');
  }

  /* Quantity stepper */
  function adjustQty(delta) {
    const input = document.getElementById('quantityInput');
    if(!input) return;
    const max   = parseInt(input.max) || 9999;
    let val = parseInt(input.value) + delta;
    if (val < 1)   val = 1;
    if (val > max) val = max;
    input.value = val;
  }
</script>

<?php include 'includes/footer.php'; ?>

