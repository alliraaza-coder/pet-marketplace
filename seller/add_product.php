<?php
/**
 * Add Product — Phase 3.1
 * Seller can create a new listing with multiple image upload.
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];
$errors    = [];
$old       = []; // repopulate form on error

// ── Fetch categories ────────────────────────────────────────
$cats = $conn->query("SELECT id, name_en, section FROM categories WHERE status = 'active' ORDER BY name_en");

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitise text fields
    $old = $_POST; // keep for repopulation

    $title_en      = trim($_POST['title_en'] ?? '');
    $description_en= trim($_POST['description_en'] ?? '');
    $price         = (float)($_POST['price'] ?? 0);
    $discount_price= !empty($_POST['discount_price']) ? (float)$_POST['discount_price'] : null;
    $stock         = max(0, (int)($_POST['stock_quantity'] ?? 1));
    $category_id   = (int)($_POST['category_id'] ?? 0);
    $subcategory_id= !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $breed         = trim($_POST['breed'] ?? '');
    $age           = trim($_POST['age'] ?? '');
    $gender        = in_array($_POST['gender'] ?? '', ['Male','Female','Pair','Unknown'])
                     ? $_POST['gender'] : 'Unknown';
    $color         = trim($_POST['color'] ?? '');
    $weight        = trim($_POST['weight'] ?? '');
    $city          = trim($_POST['city'] ?? $user['city'] ?? '');
    $listing_type  = ($_POST['listing_type'] ?? 'store') === 'market' ? 'market' : 'store';
    $status        = 'pending'; // Always pending on creation
    $is_featured   = isset($_POST['is_featured'])   ? 1 : 0;
    $is_negotiable = isset($_POST['is_negotiable'])  ? 1 : 0;
    $vaccination   = isset($_POST['vaccination_status']) ? 1 : 0;
    $health_cert   = isset($_POST['health_certificate']) ? 1 : 0;

    // Validate
    if (!$title_en)      $errors[] = 'Product name is required.';
    if (!$description_en)$errors[] = 'Description is required.';
    if ($price <= 0)     $errors[] = 'Price must be greater than 0.';
    if (!$category_id)   $errors[] = 'Please select a category.';

    // Validate & upload images
    $allowed_types = ['image/jpeg','image/png','image/webp','image/gif'];
    $max_size      = 3 * 1024 * 1024; // 3 MB
    $upload_dir    = '../assets/uploads/products/';
    $uploaded_images = [];

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    if (!empty($_FILES['images']['name'][0])) {
        foreach ($_FILES['images']['name'] as $idx => $orig_name) {
            if ($_FILES['images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            $tmp  = $_FILES['images']['tmp_name'][$idx];
            $size = $_FILES['images']['size'][$idx];
            $mime = mime_content_type($tmp);

            if (!in_array($mime, $allowed_types)) {
                $errors[] = "Image #".($idx+1).": Only JPG, PNG, WEBP, GIF allowed.";
                continue;
            }
            if ($size > $max_size) {
                $errors[] = "Image #".($idx+1).": File too large (max 3 MB).";
                continue;
            }

            $ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $new_name = 'prod_' . uniqid() . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $uploaded_images[] = $new_name;
            }
        }
    } else {
        $errors[] = 'Please upload at least one product image.';
    }

    if (empty($errors)) {
        // Generate unique slug
        $base_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title_en));
        $slug      = $base_slug . '-' . uniqid();

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare(
                "INSERT INTO products
                 (seller_id, category_id, subcategory_id, title_en, slug,
                  description_en, price, discount_price, stock_quantity,
                  breed, age, gender, color, weight, city,
                  listing_type, is_featured, is_negotiable,
                  vaccination_status, health_certificate, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $ins->bind_param(
                'iiisssddisssssssiiiis',
                $seller_id, $category_id, $subcategory_id,
                $title_en, $slug, $description_en,
                $price, $discount_price, $stock,
                $breed, $age, $gender, $color, $weight, $city,
                $listing_type, $is_featured, $is_negotiable,
                $vaccination, $health_cert, $status
            );
            $ins->execute();
            $product_id = $conn->insert_id;

            // Save images
            $img_ins = $conn->prepare(
                "INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?,?,?)"
            );
            foreach ($uploaded_images as $i => $img_name) {
                $is_primary = ($i === 0) ? 1 : 0;
                $img_ins->bind_param('isi', $product_id, $img_name, $is_primary);
                $img_ins->execute();
            }

            $conn->commit();
            
            // Notify Admin
            $admin_msg = "Seller " . $user['first_name'] . " added a new product: " . $title_en;
            $notif_stmt = $conn->prepare("INSERT INTO admin_notifications (type, title, message, link) VALUES ('new_product', 'New Product Requires Approval', ?, 'products.php?status=pending')");
            $notif_stmt->bind_param("s", $admin_msg);
            $notif_stmt->execute();
            
            $_SESSION['success'] = 'Product listed successfully! It is currently Pending Approval by an admin.';
            header('Location: products.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            // Clean up uploaded files on DB failure
            foreach ($uploaded_images as $img_name) {
                @unlink($upload_dir . $img_name);
            }
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
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
                    <li class="breadcrumb-item active">Add Product</li>
                </ol>
            </nav>

            <!-- Errors -->
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-4">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <strong>Please fix the following:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="add_product.php" method="POST" enctype="multipart/form-data" novalidate>

                <div class="row g-4">

                    <!-- ── Left Column ──────────────────────────── -->
                    <div class="col-xl-8">

                        <!-- Basic Info -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-info-circle text-success me-2"></i>Basic Information
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">

                                <div class="mb-3">
                                    <label class="form-label fw-medium">Product / Listing Name <span class="text-danger">*</span></label>
                                    <input type="text" name="title_en" class="form-control rounded-3"
                                           placeholder="e.g. African Grey Parrot – Hand Tame"
                                           value="<?php echo htmlspecialchars($old['title_en'] ?? ''); ?>"
                                           required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                                    <textarea name="description_en" class="form-control rounded-3" rows="5"
                                              placeholder="Describe the product, health, temperament, what's included..."
                                              required><?php echo htmlspecialchars($old['description_en'] ?? ''); ?></textarea>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Category <span class="text-danger">*</span></label>
                                        <select name="category_id" id="category_id" class="form-select rounded-3" required
                                                onchange="loadSubcategories(this.value)">
                                            <option value="">-- Select Category --</option>
                                            <?php while ($c = $cats->fetch_assoc()): ?>
                                                <option value="<?php echo $c['id']; ?>"
                                                    <?php echo (($old['category_id'] ?? '') == $c['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($c['name_en']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Subcategory</label>
                                        <select name="subcategory_id" id="subcategory_id" class="form-select rounded-3">
                                            <option value="">-- Select Subcategory --</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Listing Type <span class="text-danger">*</span></label>
                                        <select name="listing_type" class="form-select rounded-3">
                                            <option value="store"  <?php echo (($old['listing_type'] ?? 'store') === 'store')  ? 'selected' : ''; ?>>Store</option>
                                            <option value="market" <?php echo (($old['listing_type'] ?? '') === 'market') ? 'selected' : ''; ?>>Bird Market</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing & Stock -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-tag text-success me-2"></i>Pricing & Stock
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Price (<?php echo $site_settings['currency']; ?>) <span class="text-danger">*</span></label>
                                        <input type="number" name="price" class="form-control rounded-3"
                                               step="0.01" min="0.01" placeholder="0.00"
                                               value="<?php echo htmlspecialchars($old['price'] ?? ''); ?>"
                                               required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Discount Price (<?php echo $site_settings['currency']; ?>)</label>
                                        <input type="number" name="discount_price" class="form-control rounded-3"
                                               step="0.01" min="0" placeholder="Leave blank if no discount"
                                               value="<?php echo htmlspecialchars($old['discount_price'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Stock Quantity <span class="text-danger">*</span></label>
                                        <input type="number" name="stock_quantity" class="form-control rounded-3"
                                               min="0" placeholder="1"
                                               value="<?php echo htmlspecialchars($old['stock_quantity'] ?? '1'); ?>"
                                               required>
                                    </div>
                                </div>
                                <div class="mt-3">
                                    <div class="form-check form-check-inline">
                                        <input class="form-check-input" type="checkbox" name="is_negotiable"
                                               id="is_negotiable" value="1"
                                               <?php echo isset($old['is_negotiable']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_negotiable">Price is Negotiable</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Animal / Bird Details -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-feather text-success me-2"></i>Animal / Bird Details
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Breed / Species</label>
                                        <input type="text" name="breed" class="form-control rounded-3"
                                               placeholder="e.g. African Grey, Persian"
                                               value="<?php echo htmlspecialchars($old['breed'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Age</label>
                                        <input type="text" name="age" class="form-control rounded-3"
                                               placeholder="e.g. 6 Months, 2 Years"
                                               value="<?php echo htmlspecialchars($old['age'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Gender</label>
                                        <select name="gender" class="form-select rounded-3">
                                            <?php foreach (['Unknown','Male','Female','Pair'] as $g): ?>
                                                <option value="<?php echo $g; ?>"
                                                    <?php echo (($old['gender'] ?? 'Unknown') === $g) ? 'selected' : ''; ?>>
                                                    <?php echo $g; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Color</label>
                                        <input type="text" name="color" class="form-control rounded-3"
                                               placeholder="e.g. Green, White"
                                               value="<?php echo htmlspecialchars($old['color'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">Weight</label>
                                        <input type="text" name="weight" class="form-control rounded-3"
                                               placeholder="e.g. 500g, 1.5 kg"
                                               value="<?php echo htmlspecialchars($old['weight'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-medium">City / Location</label>
                                        <input type="text" name="city" class="form-control rounded-3"
                                               placeholder="Your city"
                                               value="<?php echo htmlspecialchars($old['city'] ?? $user['city'] ?? ''); ?>">
                                    </div>
                                </div>
                                <hr class="my-3">
                                <div class="d-flex flex-wrap gap-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="vaccination_status" id="vaccination_status" value="1"
                                               <?php echo isset($old['vaccination_status']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="vaccination_status">
                                            <i class="bi bi-shield-check text-success me-1"></i> Vaccinated
                                        </label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="health_certificate" id="health_certificate" value="1"
                                               <?php echo isset($old['health_certificate']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="health_certificate">
                                            <i class="bi bi-file-medical text-info me-1"></i> Health Certificate
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- ── Right Column ─────────────────────────── -->
                    <div class="col-xl-4">

                        <!-- Status & Options -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-gear text-success me-2"></i>Status & Options
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Product Status</label>
                                    <div>
                                        <span class="badge bg-warning text-dark px-3 py-2 fs-6">Pending Review</span>
                                    </div>
                                    <div class="form-text mt-2">New products require admin approval before becoming active.</div>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch"
                                           name="is_featured" id="is_featured" value="1"
                                           <?php echo isset($old['is_featured']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-medium" for="is_featured">
                                        <i class="bi bi-star-fill text-warning me-1"></i> Feature this listing
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Image Upload -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-images text-success me-2"></i>Product Images
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <label class="form-label fw-medium">
                                    Upload Images <span class="text-danger">*</span>
                                    <small class="text-muted">(Max 3 MB each)</small>
                                </label>

                                <!-- Drop zone -->
                                <div class="border-2 border-dashed rounded-3 p-4 text-center bg-light mb-3"
                                     id="dropZone"
                                     style="border: 2px dashed #ccc; cursor:pointer;"
                                     onclick="document.getElementById('images').click()">
                                    <i class="bi bi-cloud-upload fs-2 text-muted d-block mb-2"></i>
                                    <p class="mb-0 text-muted small">
                                        Click or drag & drop images here<br>
                                        <strong>JPG, PNG, WEBP, GIF</strong> — First image will be the primary.
                                    </p>
                                </div>
                                <input type="file" id="images" name="images[]"
                                       multiple accept="image/*"
                                       class="d-none" onchange="previewImages(event)">

                                <!-- Preview -->
                                <div id="imagePreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                            </div>
                        </div>

                        <!-- Submit -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold rounded-pill shadow-sm">
                                <i class="bi bi-cloud-upload me-2"></i> Publish Listing
                            </button>
                            <a href="products.php" class="btn btn-light rounded-pill">
                                <i class="bi bi-x me-1"></i> Cancel
                            </a>
                        </div>

                    </div>
                </div><!-- /row -->
            </form>
        </div><!-- /col-lg-10 -->
    </div><!-- /row -->
</div>

<script>
// Image preview
function previewImages(event) {
    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    const files = event.target.files;
    Array.from(files).forEach((file, idx) => {
        const reader = new FileReader();
        reader.onload = e => {
            const wrapper = document.createElement('div');
            wrapper.className = 'position-relative';
            wrapper.innerHTML = `
                <img src="${e.target.result}"
                     class="rounded-3 object-fit-cover border"
                     style="width:80px;height:80px;">
                ${idx === 0 ? '<span class="position-absolute bottom-0 start-0 badge bg-success w-100 rounded-0 rounded-bottom small">Primary</span>' : ''}
            `;
            preview.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
    });
}

// Drag & Drop
const dropZone = document.getElementById('dropZone');
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('border-success'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('border-success'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('border-success');
    const input = document.getElementById('images');
    input.files = e.dataTransfer.files;
    previewImages({ target: input });
});

// Load subcategories via AJAX
function loadSubcategories(categoryId) {
    const sel = document.getElementById('subcategory_id');
    sel.innerHTML = '<option value="">Loading...</option>';
    if (!categoryId) { sel.innerHTML = '<option value="">-- Select Subcategory --</option>'; return; }
    fetch('ajax/get_subcategories.php?category_id=' + categoryId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Select Subcategory --</option>';
            data.forEach(s => {
                sel.innerHTML += `<option value="${s.id}">${s.name_en}</option>`;
            });
        })
        .catch(() => sel.innerHTML = '<option value="">-- None --</option>');
}
</script>

<?php include '../includes/footer.php'; ?>
