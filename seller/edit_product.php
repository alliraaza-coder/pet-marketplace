<?php
/**
 * Edit Product — Phase 3.1
 * Pre-fills the form with existing product data and handles the update.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];
$errors    = [];

$product_id = (int)($_GET['id'] ?? 0);
if (!$product_id) {
    $_SESSION['error'] = 'Invalid product ID.';
    header('Location: products.php');
    exit;
}

// ── Verify ownership ─────────────────────────────────────────
$own_stmt = $conn->prepare(
    "SELECT p.*, c.id AS cat_id FROM products p
     JOIN categories c ON p.category_id = c.id
     WHERE p.id = ? AND p.seller_id = ?"
);
$own_stmt->bind_param('ii', $product_id, $seller_id);
$own_stmt->execute();
$product = $own_stmt->get_result()->fetch_assoc();

if (!$product) {
    $_SESSION['error'] = 'Product not found or you do not have permission to edit it.';
    header('Location: products.php');
    exit;
}

// ── Fetch existing images ─────────────────────────────────────
$img_stmt = $conn->prepare(
    "SELECT id, image_url, is_primary FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, id ASC"
);
$img_stmt->bind_param('i', $product_id);
$img_stmt->execute();
$existing_images = $img_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Fetch categories ──────────────────────────────────────────
$cats = $conn->query("SELECT id, name_en FROM categories WHERE status = 'active' ORDER BY name_en");

// ── Fetch subcategories for current category ──────────────────
$subs_stmt = $conn->prepare(
    "SELECT id, name_en FROM subcategories WHERE category_id = ? AND status = 'active'"
);
$subs_stmt->bind_param('i', $product['category_id']);
$subs_stmt->execute();
$subcats = $subs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Handle DELETE single image (AJAX-style GET) ───────────────
if (isset($_GET['delete_image'])) {
    $img_id = (int)$_GET['delete_image'];
    // Verify image belongs to this seller's product
    $del_check = $conn->prepare(
        "SELECT pi.image_url FROM product_images pi
         JOIN products p ON pi.product_id = p.id
         WHERE pi.id = ? AND p.seller_id = ?"
    );
    $del_check->bind_param('ii', $img_id, $seller_id);
    $del_check->execute();
    $img_row = $del_check->get_result()->fetch_assoc();
    if ($img_row) {
        @unlink('../assets/uploads/products/' . $img_row['image_url']);
        $conn->query("DELETE FROM product_images WHERE id = $img_id");
        // If deleted image was primary, promote the next one
        $conn->query(
            "UPDATE product_images SET is_primary = 1
             WHERE product_id = $product_id
             ORDER BY id ASC LIMIT 1"
        );
    }
    header("Location: edit_product.php?id=$product_id&deleted=1");
    exit;
}

// ── Handle POST update ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title_en       = trim($_POST['title_en'] ?? '');
    $description_en = trim($_POST['description_en'] ?? '');
    $price          = (float)($_POST['price'] ?? 0);
    $discount_price = !empty($_POST['discount_price']) ? (float)$_POST['discount_price'] : null;
    $stock          = max(0, (int)($_POST['stock_quantity'] ?? 0));
    $category_id    = (int)($_POST['category_id'] ?? 0);
    $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $breed          = trim($_POST['breed'] ?? '');
    $age            = trim($_POST['age'] ?? '');
    $gender         = in_array($_POST['gender'] ?? '', ['Male','Female','Pair','Unknown'])
                      ? $_POST['gender'] : 'Unknown';
    $color          = trim($_POST['color'] ?? '');
    $weight         = trim($_POST['weight'] ?? '');
    $city           = trim($_POST['city'] ?? '');
    $listing_type   = ($_POST['listing_type'] ?? 'store') === 'market' ? 'market' : 'store';
    // Seller can only mark as 'sold', cannot change back, cannot activate/deactivate
    $status = $product['status'];
    if (isset($_POST['status']) && $_POST['status'] === 'sold') {
        $status = 'sold';
    }
    $is_featured    = isset($_POST['is_featured'])   ? 1 : 0;
    $is_negotiable  = isset($_POST['is_negotiable'])  ? 1 : 0;
    $vaccination    = isset($_POST['vaccination_status']) ? 1 : 0;
    $health_cert    = isset($_POST['health_certificate']) ? 1 : 0;

    if (!$title_en)       $errors[] = 'Product name is required.';
    if (!$description_en) $errors[] = 'Description is required.';
    if ($price <= 0)      $errors[] = 'Price must be greater than 0.';
    if (!$category_id)    $errors[] = 'Please select a category.';

    // Handle new image uploads
    $allowed_types   = ['image/jpeg','image/png','image/webp','image/gif'];
    $max_size        = 3 * 1024 * 1024;
    $upload_dir      = '../assets/uploads/products/';
    $new_images      = [];

    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['name'] as $idx => $orig_name) {
            if ($_FILES['images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            $tmp  = $_FILES['images']['tmp_name'][$idx];
            $mime = mime_content_type($tmp);
            $size = $_FILES['images']['size'][$idx];

            if (!in_array($mime, $allowed_types)) {
                $errors[] = "New image #".($idx+1).": Only JPG, PNG, WEBP, GIF allowed.";
                continue;
            }
            if ($size > $max_size) {
                $errors[] = "New image #".($idx+1).": Max 3 MB allowed.";
                continue;
            }
            $ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $new_name = 'prod_' . uniqid() . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $new_images[] = $new_name;
            }
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare(
                "UPDATE products SET
                     category_id = ?, subcategory_id = ?,
                     title_en = ?, description_en = ?,
                     price = ?, discount_price = ?, stock_quantity = ?,
                     breed = ?, age = ?, gender = ?, color = ?, weight = ?, city = ?,
                     listing_type = ?, is_featured = ?, is_negotiable = ?,
                     vaccination_status = ?, health_certificate = ?, status = ?
                 WHERE id = ? AND seller_id = ?"
            );
            $upd->bind_param(
                'iissddisssssssiiiisii',
                $category_id, $subcategory_id,
                $title_en, $description_en,
                $price, $discount_price, $stock,
                $breed, $age, $gender, $color, $weight, $city,
                $listing_type, $is_featured, $is_negotiable,
                $vaccination, $health_cert, $status,
                $product_id, $seller_id
            );
            $upd->execute();

            // Check if product has any primary image
            $primary_check = $conn->query(
                "SELECT COUNT(*) AS cnt FROM product_images WHERE product_id = $product_id AND is_primary = 1"
            )->fetch_assoc()['cnt'];

            // Save new images
            $img_ins = $conn->prepare(
                "INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?,?,?)"
            );
            foreach ($new_images as $i => $img_name) {
                // If no existing primary, first new image becomes primary
                $make_primary = ($primary_check == 0 && $i === 0) ? 1 : 0;
                $img_ins->bind_param('isi', $product_id, $img_name, $make_primary);
                $img_ins->execute();
            }

            $conn->commit();
            $_SESSION['success'] = 'Product updated successfully!';
            header('Location: products.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($new_images as $n) @unlink($upload_dir . $n);
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // Repopulate $product with POST data for re-rendering
    $product = array_merge($product, [
        'title_en'            => $title_en,
        'description_en'      => $description_en,
        'price'               => $price,
        'discount_price'      => $discount_price,
        'stock_quantity'      => $stock,
        'category_id'         => $category_id,
        'subcategory_id'      => $subcategory_id,
        'breed'               => $breed,
        'age'                 => $age,
        'gender'              => $gender,
        'color'               => $color,
        'weight'              => $weight,
        'city'                => $city,
        'listing_type'        => $listing_type,
        'is_featured'         => $is_featured,
        'is_negotiable'       => $is_negotiable,
        'vaccination_status'  => $vaccination,
        'health_certificate'  => $health_cert,
        'status'              => $status,
    ]);
}

include '../includes/header.php';
?>

<div class="container-fluid py-4 px-4">
    <div class="row g-4">

        <!-- Sidebar -->
        <div class="col-lg-2 mb-4 mb-lg-0">
            <?php include 'partials/sidebar.php'; ?>
        </div>

        <!-- Main -->
        <div class="col-lg-10">

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-success">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="products.php" class="text-success">My Products</a></li>
                    <li class="breadcrumb-item active">Edit Product</li>
                </ol>
            </nav>

            <?php if (isset($_GET['deleted'])): ?>
                <div class="alert alert-info alert-dismissible fade show rounded-4">
                    <i class="bi bi-image me-2"></i> Image deleted successfully.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><strong>Fix the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="edit_product.php?id=<?php echo $product_id; ?>" method="POST" enctype="multipart/form-data" novalidate>

                <div class="row g-4">

                    <!-- ── Left Column ──────────────────────── -->
                    <div class="col-xl-8">

                        <!-- Basic Info -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0"><i class="bi bi-info-circle text-success me-2"></i>Basic Information</h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Product Name <span class="text-danger">*</span></label>
                                    <input type="text" name="title_en" class="form-control rounded-3"
                                           value="<?php echo htmlspecialchars($product['title_en']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                                    <textarea name="description_en" class="form-control rounded-3" rows="5" required><?php echo htmlspecialchars($product['description_en']); ?></textarea>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
                                        <select name="category_id" id="category_id" class="form-select rounded-3" required
                                                onchange="loadSubcategories(this.value, <?php echo (int)($product['subcategory_id'] ?? 0); ?>)">
                                            <option value="">-- Select --</option>
                                            <?php while ($c = $cats->fetch_assoc()): ?>
                                                <option value="<?php echo $c['id']; ?>" <?php echo ($product['category_id'] == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['name_en']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Subcategory</label>
                                        <select name="subcategory_id" id="subcategory_id" class="form-select rounded-3">
                                            <option value="">-- Select --</option>
                                            <?php foreach ($subcats as $s): ?>
                                                <option value="<?php echo $s['id']; ?>" <?php echo ($product['subcategory_id'] == $s['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($s['name_en']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Listing Type</label>
                                        <select name="listing_type" class="form-select rounded-3">
                                            <option value="store"  <?php echo $product['listing_type'] === 'store'  ? 'selected' : ''; ?>>Store</option>
                                            <option value="market" <?php echo $product['listing_type'] === 'market' ? 'selected' : ''; ?>>Bird Market</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing & Stock -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0"><i class="bi bi-tag text-success me-2"></i>Pricing & Stock</h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Price (<?php echo $site_settings['currency']; ?>) <span class="text-danger">*</span></label>
                                        <input type="number" name="price" class="form-control rounded-3"
                                               step="0.01" min="0.01"
                                               value="<?php echo htmlspecialchars($product['price']); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Discount Price (<?php echo $site_settings['currency']; ?>)</label>
                                        <input type="number" name="discount_price" class="form-control rounded-3"
                                               step="0.01" min="0"
                                               value="<?php echo htmlspecialchars($product['discount_price'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Stock Qty <span class="text-danger">*</span></label>
                                        <input type="number" name="stock_quantity" class="form-control rounded-3"
                                               min="0"
                                               value="<?php echo htmlspecialchars($product['stock_quantity']); ?>" required>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="is_negotiable"
                                               id="is_negotiable" value="1"
                                               <?php echo $product['is_negotiable'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_negotiable">Price is Negotiable</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Animal Details -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0"><i class="bi bi-feather text-success me-2"></i>Animal / Bird Details</h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Breed / Species</label>
                                        <input type="text" name="breed" class="form-control rounded-3"
                                               value="<?php echo htmlspecialchars($product['breed'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Age</label>
                                        <input type="text" name="age" class="form-control rounded-3"
                                               value="<?php echo htmlspecialchars($product['age'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Gender</label>
                                        <select name="gender" class="form-select rounded-3">
                                            <?php foreach (['Unknown','Male','Female','Pair'] as $g): ?>
                                                <option value="<?php echo $g; ?>" <?php echo ($product['gender'] === $g) ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Color</label>
                                        <input type="text" name="color" class="form-control rounded-3"
                                               value="<?php echo htmlspecialchars($product['color'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Weight</label>
                                        <input type="text" name="weight" class="form-control rounded-3"
                                               value="<?php echo htmlspecialchars($product['weight'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">City / Location</label>
                                        <input type="text" name="city" class="form-control rounded-3"
                                               value="<?php echo htmlspecialchars($product['city'] ?? ''); ?>">
                                    </div>
                                </div>
                                <hr class="my-3">
                                <div class="d-flex flex-wrap gap-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="vaccination_status" id="vaccination_status" value="1"
                                               <?php echo $product['vaccination_status'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="vaccination_status">
                                            <i class="bi bi-shield-check text-success me-1"></i> Vaccinated
                                        </label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="health_certificate" id="health_certificate" value="1"
                                               <?php echo $product['health_certificate'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="health_certificate">
                                            <i class="bi bi-file-medical text-info me-1"></i> Health Certificate
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- ── Right Column ─────────────────────── -->
                    <div class="col-xl-4">

                        <!-- Status -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0"><i class="bi bi-gear text-success me-2"></i>Status & Options</h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Product Status</label>
                                    
                                    <?php if ($product['status'] === 'sold'): ?>
                                        <div class="alert alert-secondary py-2 mb-0">
                                            <i class="bi bi-info-circle me-1"></i> Sold Out (Cannot be changed)
                                        </div>
                                        <input type="hidden" name="status" value="sold">
                                    <?php else: ?>
                                        <select name="status" class="form-select rounded-3">
                                            <option value="<?php echo $product['status']; ?>">Keep Current Status (<?php echo ucfirst($product['status']); ?>)</option>
                                            <option value="sold">Mark as Sold Out</option>
                                        </select>
                                        <div class="form-text text-muted small mt-2">You can only mark the product as Sold Out. Admin approval is required for activation.</div>
                                    <?php endif; ?>
                                    
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="is_featured" id="is_featured" value="1"
                                           <?php echo $product['is_featured'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-medium" for="is_featured">
                                        <i class="bi bi-star-fill text-warning me-1"></i> Feature this listing
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Existing Images -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0"><i class="bi bi-images text-success me-2"></i>Current Images</h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <?php if (!empty($existing_images)): ?>
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <?php foreach ($existing_images as $img): ?>
                                    <div class="position-relative">
                                        <img src="<?php echo BASE_URL; ?>/assets/uploads/products/<?php echo htmlspecialchars($img['image_url']); ?>"
                                             class="rounded-3 border object-fit-cover"
                                             style="width:75px;height:75px;"
                                             alt="Product image">
                                        <?php if ($img['is_primary']): ?>
                                            <span class="position-absolute top-0 start-0 badge bg-success rounded-1 m-1" style="font-size:.6rem;">Primary</span>
                                        <?php endif; ?>
                                        <a href="edit_product.php?id=<?php echo $product_id; ?>&delete_image=<?php echo $img['id']; ?>"
                                           class="position-absolute top-0 end-0 btn btn-danger btn-sm rounded-circle m-1 p-0 d-flex align-items-center justify-content-center"
                                           style="width:20px;height:20px;font-size:.65rem;"
                                           onclick="return confirm('Delete this image?')"
                                           title="Delete image">
                                            <i class="bi bi-x"></i>
                                        </a>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <p class="text-muted small mb-2">No images yet.</p>
                                <?php endif; ?>

                                <!-- Add more images -->
                                <label class="form-label fw-medium small">Add More Images</label>
                                <input type="file" id="images" name="images[]"
                                       multiple accept="image/*"
                                       class="form-control form-control-sm rounded-3"
                                       onchange="previewNew(event)">
                                <div id="newPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold rounded-pill shadow-sm">
                                <i class="bi bi-save me-2"></i> Save Changes
                            </button>
                            <a href="products.php" class="btn btn-light rounded-pill">
                                <i class="bi bi-x me-1"></i> Cancel
                            </a>
                        </div>

                    </div>
                </div><!-- /row -->
            </form>
        </div><!-- /main -->
    </div>
</div>

<script>
function previewNew(event) {
    const preview = document.getElementById('newPreview');
    preview.innerHTML = '';
    Array.from(event.target.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src = e.target.result;
            img.className = 'rounded-3 border object-fit-cover';
            img.style.cssText = 'width:70px;height:70px;';
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}

function loadSubcategories(categoryId, selectedId) {
    const sel = document.getElementById('subcategory_id');
    sel.innerHTML = '<option value="">Loading...</option>';
    if (!categoryId) { sel.innerHTML = '<option value="">-- None --</option>'; return; }
    fetch('ajax/get_subcategories.php?category_id=' + categoryId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Select Subcategory --</option>';
            data.forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = s.name_en;
                if (s.id == selectedId) opt.selected = true;
                sel.appendChild(opt);
            });
        })
        .catch(() => sel.innerHTML = '<option value="">-- None --</option>');
}
</script>

<?php include '../includes/footer.php'; ?>
