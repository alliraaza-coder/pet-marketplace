<?php
/**
 * Admin Edit Market Special
 * Phase 7 — Edit Market Special Details, Dynamic Attributes, Stock & Images
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$admin_id   = (int)$_SESSION['user_id'];
$product_id = (int)($_GET['id'] ?? 0);

if (!$product_id) {
    $_SESSION['error'] = 'Invalid Market Special ID.';
    header('Location: market_specials.php');
    exit;
}

// Fetch Market Special category ID
$cat_row = $conn->query("SELECT id FROM categories WHERE is_admin_only = 1 OR slug = 'market-specials' LIMIT 1")->fetch_assoc();
$market_category_id = $cat_row ? (int)$cat_row['id'] : 5;

// Fetch Product and verify it's an admin market special
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND (is_admin_product = 1 OR category_id = ?) AND is_deleted = 0");
$stmt->bind_param("ii", $product_id, $market_category_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    $_SESSION['error'] = 'Market Special product not found or unauthorized.';
    header('Location: market_specials.php');
    exit;
}

$product_type = $product['product_type'] ?: PRODUCT_TYPE_BIRDS_ANIMALS;
$dynamic_details = get_product_dynamic_details($conn, $product_id, $product_type);

// Handle delete single image
if (isset($_GET['delete_image'])) {
    $img_id = (int)$_GET['delete_image'];
    $img_stmt = $conn->prepare("SELECT image_url, is_primary FROM product_images WHERE id = ? AND product_id = ?");
    $img_stmt->bind_param("ii", $img_id, $product_id);
    $img_stmt->execute();
    $img_row = $img_stmt->get_result()->fetch_assoc();
    $img_stmt->close();

    if ($img_row) {
        $upload_dir = '../assets/uploads/products/';
        @unlink($upload_dir . $img_row['image_url']);
        $conn->query("DELETE FROM product_images WHERE id = $img_id");

        // If deleted was primary, assign next primary
        if ($img_row['is_primary']) {
            $conn->query("UPDATE product_images SET is_primary = 1 WHERE product_id = $product_id ORDER BY id ASC LIMIT 1");
        }
        $_SESSION['success'] = "Image removed successfully.";
    }
    header("Location: edit_market_special.php?id=$product_id");
    exit;
}

// Handle set primary image
if (isset($_GET['set_primary'])) {
    $img_id = (int)$_GET['set_primary'];
    $conn->query("UPDATE product_images SET is_primary = 0 WHERE product_id = $product_id");
    $conn->query("UPDATE product_images SET is_primary = 1 WHERE id = $img_id AND product_id = $product_id");
    $_SESSION['success'] = "Primary image updated.";
    header("Location: edit_market_special.php?id=$product_id");
    exit;
}

// Fetch images
$imgs_res = $conn->query("SELECT * FROM product_images WHERE product_id = $product_id ORDER BY is_primary DESC, id ASC");
$existing_images = [];
while ($ir = $imgs_res->fetch_assoc()) {
    $existing_images[] = $ir;
}

// Fetch subcategories
$subcats_res = $conn->query("SELECT id, name_en FROM subcategories WHERE category_id = {$market_category_id} AND status = 'active' ORDER BY name_en ASC");
$subcategories = [];
while ($sr = $subcats_res->fetch_assoc()) {
    $subcategories[] = $sr;
}

$errors = [];

// Handle POST Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title_en       = trim($_POST['title_en'] ?? '');
    $description_en = trim($_POST['description_en'] ?? '');
    $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $is_featured    = isset($_POST['is_featured']) ? 1 : 0;
    $status_input   = sanitize_input($_POST['status'] ?? $product['status']);

    $price          = 0.00;
    $discount_price = null;
    $stock_quantity = 0;
    $dynamic_data   = [];

    $breed          = null;
    $age            = null;
    $gender_db      = 'Unknown';
    $color          = null;
    $weight         = null;
    $city           = 'Market Special Admin HQ';
    $vaccination    = 0;
    $health_cert    = 0;

    if ($product_type === PRODUCT_TYPE_SEEDS_FOOD) {
        $price_per_kg   = is_numeric($_POST['price_per_kg'] ?? '') ? (float)$_POST['price_per_kg'] : 0.00;
        $price          = $price_per_kg;

        $disc25         = trim($_POST['discount_price_25kg'] ?? '');
        $disc_25kg      = ($disc25 !== '' && is_numeric($disc25)) ? (float)$disc25 : null;

        $disc50         = trim($_POST['discount_price_50kg'] ?? '');
        $disc_50kg      = ($disc50 !== '' && is_numeric($disc50)) ? (float)$disc50 : null;

        $quality        = in_array($_POST['quality'] ?? '', ['A+', 'A', 'B', 'C']) ? $_POST['quality'] : 'A';
        $stock_mode     = ($_POST['stock_type'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
        $stock_kg       = is_numeric($_POST['stock_kg'] ?? '') ? (float)$_POST['stock_kg'] : 0.00;

        if ($stock_mode === 'as_required') {
            $stock_quantity = 999;
        } else {
            $stock_quantity = max(0, (int)round($stock_kg));
        }

        if ($title_en === '') $errors[] = 'Seed / Food Name is required.';
        if ($description_en === '') $errors[] = 'Description is required.';
        if ($price_per_kg <= 0) $errors[] = 'Price per KG must be greater than 0.';

        $dynamic_data = [
            'price_per_kg'        => $price_per_kg,
            'discount_price_25kg' => $disc_25kg,
            'discount_price_50kg' => $disc_50kg,
            'quality'             => $quality,
            'stock_type'          => $stock_mode,
            'stock_kg'            => $stock_kg
        ];

    } elseif ($product_type === PRODUCT_TYPE_CAGES_HOUSES) {
        $animal_purpose = trim($_POST['animal_purpose'] ?? '');
        $length         = is_numeric($_POST['length'] ?? '') ? (float)$_POST['length'] : null;
        $width          = is_numeric($_POST['width'] ?? '') ? (float)$_POST['width'] : null;
        $depth          = is_numeric($_POST['depth'] ?? '') ? (float)$_POST['depth'] : null;
        $height         = is_numeric($_POST['height'] ?? '') ? (float)$_POST['height'] : null;
        $unit_dimension = 'inches';

        $price_per_piece= is_numeric($_POST['price_per_piece'] ?? '') ? (float)$_POST['price_per_piece'] : 0.00;
        $price          = $price_per_piece;

        $disc12         = trim($_POST['discount_price_12pcs'] ?? '');
        $disc_12pcs     = ($disc12 !== '' && is_numeric($disc12)) ? (float)$disc12 : null;

        $disc24         = trim($_POST['discount_price_24pcs'] ?? '');
        $disc_24pcs     = ($disc24 !== '' && is_numeric($disc24)) ? (float)$disc24 : null;

        $quality        = in_array($_POST['quality'] ?? '', ['A+', 'A', 'B', 'C']) ? $_POST['quality'] : 'A';
        $stock_mode     = ($_POST['stock_type'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
        $stock_pieces   = is_numeric($_POST['stock_pieces'] ?? '') ? (int)$_POST['stock_pieces'] : 0;

        if ($stock_mode === 'as_required') {
            $stock_quantity = 999;
        } else {
            $stock_quantity = max(0, $stock_pieces);
        }

        if ($title_en === '') $errors[] = 'Cage / House Name is required.';
        if ($animal_purpose === '') $errors[] = 'Animal purpose is required.';
        if ($description_en === '') $errors[] = 'Description is required.';
        if ($price_per_piece <= 0) $errors[] = 'Price per Piece must be greater than 0.';

        $dynamic_data = [
            'animal_purpose'       => $animal_purpose,
            'length'               => $length,
            'width'                => $width,
            'depth'                => $depth,
            'height'               => $height,
            'unit_dimension'       => $unit_dimension,
            'price_per_piece'      => $price_per_piece,
            'discount_price_12pcs' => $disc_12pcs,
            'discount_price_24pcs' => $disc_24pcs,
            'quality'              => $quality,
            'stock_type'           => $stock_mode,
            'stock_pieces'         => $stock_pieces
        ];

    } else {
        // Birds & Animals
        $price          = is_numeric($_POST['price'] ?? '') ? (float)$_POST['price'] : 0.00;
        $disc           = trim($_POST['discount_price'] ?? '');
        $discount_price = ($disc !== '' && is_numeric($disc)) ? (float)$disc : null;
        $stock_quantity = is_numeric($_POST['stock_quantity'] ?? '') ? max(0, (int)$_POST['stock_quantity']) : 0;

        $breed          = trim($_POST['breed'] ?? '');
        $age            = trim($_POST['age'] ?? '');
        $gender_raw     = strtolower(trim($_POST['gender'] ?? 'unknown'));
        $gender         = in_array($gender_raw, ['male', 'female', 'pair', 'unknown']) ? $gender_raw : 'unknown';
        $gender_db      = ucfirst($gender);
        $color          = trim($_POST['color'] ?? '');
        $weight         = trim($_POST['weight'] ?? '');
        $city           = trim($_POST['city'] ?? 'Admin Special');
        $vaccination    = isset($_POST['vaccination_status']) ? 1 : 0;
        $health_cert    = isset($_POST['health_certificate']) ? 1 : 0;

        if ($title_en === '') $errors[] = 'Product Name is required.';
        if ($description_en === '') $errors[] = 'Description is required.';
        if ($price <= 0) $errors[] = 'Price must be greater than 0.';

        $dynamic_data = [
            'breed'              => $breed,
            'age'                => $age,
            'gender'             => $gender,
            'color'              => $color,
            'weight'             => $weight,
            'city'               => $city,
            'vaccinated'         => $vaccination,
            'health_certificate' => $health_cert
        ];
    }

    // Determine Status by Stock rule
    $final_status = $status_input;
    if ($stock_quantity <= 0) {
        $final_status = 'sold_out';
    } else {
        if ($final_status === 'sold_out' || $final_status === 'sold' || $final_status === 'active') {
            $final_status = 'active';
        }
    }

    // Duplicate Check
    $norm_title = normalize_product_name($title_en);
    if ($title_en !== '') {
        $dup_chk = $conn->prepare("SELECT id FROM products WHERE is_admin_product = 1 AND normalized_name = ? AND id != ? AND is_deleted = 0 LIMIT 1");
        $dup_chk->bind_param("si", $norm_title, $product_id);
        $dup_chk->execute();
        if ($dup_chk->get_result()->fetch_assoc()) {
            $errors[] = "Another Market Special with the name '{$title_en}' already exists.";
        }
        $dup_chk->close();
    }

    // Handle optional new images
    $new_images = [];
    $upload_dir = '../assets/uploads/products/';
    if (!empty($_FILES['new_images']['name'][0])) {
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        foreach ($_FILES['new_images']['name'] as $idx => $orig_name) {
            if ($_FILES['new_images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            $tmp  = $_FILES['new_images']['tmp_name'][$idx];
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed_mime)) {
                $errors[] = "New image #" . ($idx + 1) . ": Invalid format.";
                continue;
            }
            $ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $new_name = 'spec_' . uniqid() . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $new_images[] = $new_name;
            }
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $upd = $conn->prepare("
                UPDATE products SET
                    subcategory_id = ?,
                    title_en = ?,
                    normalized_name = ?,
                    description_en = ?,
                    price = ?,
                    discount_price = ?,
                    stock_quantity = ?,
                    breed = ?,
                    age = ?,
                    gender = ?,
                    color = ?,
                    weight = ?,
                    city = ?,
                    is_featured = ?,
                    vaccination_status = ?,
                    health_certificate = ?,
                    status = ?
                WHERE id = ? AND is_admin_product = 1
            ");
            $upd->bind_param(
                'isssddissssssiiiisi',
                $subcategory_id,
                $title_en,
                $norm_title,
                $description_en,
                $price,
                $discount_price,
                $stock_quantity,
                $breed,
                $age,
                $gender_db,
                $color,
                $weight,
                $city,
                $is_featured,
                $vaccination,
                $health_cert,
                $final_status,
                $product_id
            );
            $upd->execute();
            $upd->close();

            // Save Dynamic Attributes
            save_product_dynamic_details($conn, $product_id, $product_type, $dynamic_data);

            // Insert new images if uploaded
            if (!empty($new_images)) {
                $has_primary = count($existing_images) > 0;
                $img_ins = $conn->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)");
                foreach ($new_images as $k => $img_name) {
                    $is_p = (!$has_primary && $k === 0) ? 1 : 0;
                    $img_ins->bind_param("isi", $product_id, $img_name, $is_p);
                    $img_ins->execute();
                }
                $img_ins->close();
            }

            log_admin_activity($conn, $admin_id, "Updated Market Special", "Updated '{$title_en}' (ID: $product_id)");

            $conn->commit();
            $_SESSION['success'] = "Market Special updated successfully!";
            header("Location: edit_market_special.php?id=$product_id");
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($new_images as $img_name) {
                @unlink($upload_dir . $img_name);
            }
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$page_title   = "Edit Market Special: " . htmlspecialchars($product['title_en']);
$page_heading = "Edit Market Special";
include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-pencil-square text-warning me-2"></i>Edit Market Special</h3>
        <p class="text-muted small mb-0">Product ID: #<?php echo $product['id']; ?> &bull; Type: <strong><?php echo ucfirst(str_replace('_', ' ', $product_type)); ?></strong></p>
    </div>
    <div class="d-flex gap-2">
        <a href="market_special_details.php?id=<?php echo $product['id']; ?>" class="btn btn-outline-primary rounded-pill px-3">
            <i class="bi bi-eye me-1"></i> View Details
        </a>
        <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill px-4">
            <i class="bi bi-arrow-left me-1"></i> Back to List
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger alert-dismissible fade show rounded-4 mb-4 shadow-sm" role="alert">
    <div class="d-flex align-items-center mb-1">
        <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
        <strong class="fs-6">Please fix the following issues:</strong>
    </div>
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<form action="edit_market_special.php?id=<?php echo $product['id']; ?>" method="POST" enctype="multipart/form-data">
    <?php csrf_field(); ?>

    <div class="row g-4">
        <!-- Left Column: Details -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-1 text-dark"><i class="bi bi-info-circle text-primary me-2"></i>Basic Information</h5>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-medium">Market Special Name <span class="text-danger">*</span></label>
                            <input type="text" name="title_en" class="form-control rounded-3" value="<?php echo htmlspecialchars($product['title_en']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                            <textarea name="description_en" class="form-control rounded-3" rows="4" required><?php echo htmlspecialchars($product['description_en']); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dynamic Product Type Fields -->
            <?php if ($product_type === PRODUCT_TYPE_SEEDS_FOOD): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="fw-bold mb-1 text-success"><i class="bi bi-basket2 me-2"></i>Seeds &amp; Animal Food Pricing &amp; Stock</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Price per KG (PKR) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="price_per_kg" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['price_per_kg'] ?? $product['price']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Discount Price 25 KG (PKR)</label>
                                <input type="number" step="0.01" name="discount_price_25kg" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['discount_price_25kg'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Discount Price 50 KG (PKR)</label>
                                <input type="number" step="0.01" name="discount_price_50kg" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['discount_price_50kg'] ?? ''); ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-medium">Quality Grade</label>
                                <select name="quality" class="form-select rounded-3">
                                    <?php foreach (['A+', 'A', 'B', 'C'] as $q): ?>
                                        <option value="<?php echo $q; ?>" <?php echo (($dynamic_details['quality'] ?? '') === $q) ? 'selected' : ''; ?>><?php echo $q; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-medium">Stock Mode</label>
                                <select name="stock_type" class="form-select rounded-3">
                                    <option value="numeric" <?php echo (($dynamic_details['stock_type'] ?? '') === 'numeric') ? 'selected' : ''; ?>>Specific Numeric KG</option>
                                    <option value="as_required" <?php echo (($dynamic_details['stock_type'] ?? '') === 'as_required') ? 'selected' : ''; ?>>As Required / On Demand</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-medium">Stock Quantity (KG)</label>
                                <input type="number" step="0.5" name="stock_kg" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['stock_kg'] ?? $product['stock_quantity']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($product_type === PRODUCT_TYPE_CAGES_HOUSES): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="fw-bold mb-1 text-warning"><i class="bi bi-house-door me-2"></i>Cages &amp; Houses Specs &amp; Pricing</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium">For What Animal / Purpose</label>
                                <input type="text" name="animal_purpose" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['animal_purpose'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Quality Grade</label>
                                <select name="quality" class="form-select rounded-3">
                                    <?php foreach (['A+', 'A', 'B', 'C'] as $q): ?>
                                        <option value="<?php echo $q; ?>" <?php echo (($dynamic_details['quality'] ?? '') === $q) ? 'selected' : ''; ?>><?php echo $q; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <div class="p-3 bg-light rounded-4">
                                    <h6 class="fw-bold mb-2">Dimensions (Unit: Inches)</h6>
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted mb-1">Length</label>
                                            <input type="number" step="0.1" name="length" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['length'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted mb-1">Width</label>
                                            <input type="number" step="0.1" name="width" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['width'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted mb-1">Depth</label>
                                            <input type="number" step="0.1" name="depth" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['depth'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small text-muted mb-1">Height</label>
                                            <input type="number" step="0.1" name="height" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['height'] ?? ''); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-medium">Price per Piece (PKR)</label>
                                <input type="number" step="0.01" name="price_per_piece" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['price_per_piece'] ?? $product['price']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Discount 12 Pieces (PKR)</label>
                                <input type="number" step="0.01" name="discount_price_12pcs" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['discount_price_12pcs'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Discount 24 Pieces (PKR)</label>
                                <input type="number" step="0.01" name="discount_price_24pcs" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['discount_price_24pcs'] ?? ''); ?>">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-medium">Stock Mode</label>
                                <select name="stock_type" class="form-select rounded-3">
                                    <option value="numeric" <?php echo (($dynamic_details['stock_type'] ?? '') === 'numeric') ? 'selected' : ''; ?>>Specific Pieces in Stock</option>
                                    <option value="as_required" <?php echo (($dynamic_details['stock_type'] ?? '') === 'as_required') ? 'selected' : ''; ?>>As Required / Built on Demand</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium">Stock (Pieces)</label>
                                <input type="number" name="stock_pieces" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['stock_pieces'] ?? $product['stock_quantity']); ?>">
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <!-- Birds & Animals -->
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                        <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-feather me-2"></i>Birds &amp; Animals Specs &amp; Health</h5>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Price (PKR) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="price" class="form-control rounded-3" value="<?php echo htmlspecialchars($product['price']); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Discount Price (PKR)</label>
                                <input type="number" step="0.01" name="discount_price" class="form-control rounded-3" value="<?php echo htmlspecialchars($product['discount_price'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-medium">Stock Quantity <span class="text-danger">*</span></label>
                                <input type="number" name="stock_quantity" min="0" class="form-control rounded-3" value="<?php echo htmlspecialchars($product['stock_quantity']); ?>" required>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Breed / Species</label>
                                <input type="text" name="breed" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['breed'] ?? $product['breed'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">Gender</label>
                                <select name="gender" class="form-select rounded-3">
                                    <?php $cur_g = strtolower($dynamic_details['gender'] ?? $product['gender'] ?? 'unknown'); ?>
                                    <option value="unknown" <?php echo $cur_g === 'unknown' ? 'selected' : ''; ?>>Unknown</option>
                                    <option value="male" <?php echo $cur_g === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo $cur_g === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="pair" <?php echo $cur_g === 'pair' ? 'selected' : ''; ?>>Pair</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">Age</label>
                                <input type="text" name="age" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['age'] ?? $product['age'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">Color</label>
                                <input type="text" name="color" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['color'] ?? $product['color'] ?? ''); ?>">
                            </div>

                            <div class="col-md-3">
                                <label class="form-label fw-medium">Weight</label>
                                <input type="text" name="weight" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['weight'] ?? $product['weight'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-medium">City</label>
                                <input type="text" name="city" class="form-control rounded-3" value="<?php echo htmlspecialchars($dynamic_details['city'] ?? $product['city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3 pt-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="vaccination_status" id="vaccination_status" value="1" <?php echo !empty($dynamic_details['vaccinated'] ?? $product['vaccination_status']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-medium" for="vaccination_status">Vaccinated</label>
                                </div>
                            </div>
                            <div class="col-md-3 pt-md-4">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="health_certificate" id="health_certificate" value="1" <?php echo !empty($dynamic_details['health_certificate'] ?? $product['health_certificate']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-medium" for="health_certificate">Health Certificate</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Existing Images Gallery -->
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-1"><i class="bi bi-images text-info me-2"></i>Product Images</h5>
                    <p class="text-muted small">Manage current images or upload additional photos.</p>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3 mb-3">
                        <?php if (!empty($existing_images)): ?>
                            <?php foreach ($existing_images as $img): ?>
                                <div class="col-6 col-sm-4 col-md-3">
                                    <div class="card border rounded-3 position-relative overflow-hidden h-100 shadow-sm">
                                        <img src="<?php echo BASE_URL . '/assets/uploads/products/' . htmlspecialchars($img['image_url']); ?>" class="card-img-top" style="height: 120px; object-fit: cover;" onerror="this.src='https://placehold.co/120x120?text=Image';">
                                        <div class="card-body p-2 text-center bg-light">
                                            <?php if ($img['is_primary']): ?>
                                                <span class="badge bg-success mb-1">Primary Image</span>
                                            <?php else: ?>
                                                <a href="edit_market_special.php?id=<?php echo $product_id; ?>&set_primary=<?php echo $img['id']; ?>" class="btn btn-xs btn-outline-primary btn-sm py-0 mb-1" style="font-size: 0.75rem;">Set Primary</a>
                                            <?php endif; ?>
                                            <div>
                                                <a href="edit_market_special.php?id=<?php echo $product_id; ?>&delete_image=<?php echo $img['id']; ?>" class="text-danger small text-decoration-none" onclick="return confirm('Delete this image?');">
                                                    <i class="bi bi-trash"></i> Remove
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-12 text-muted small fst-italic">No images uploaded yet.</div>
                        <?php endif; ?>
                    </div>

                    <div class="border-top pt-3">
                        <label class="form-label fw-medium">Upload Additional Images</label>
                        <input type="file" name="new_images[]" class="form-control rounded-3" multiple accept="image/jpeg,image/png,image/webp,image/gif">
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Settings & Stock -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                    <h5 class="fw-bold mb-1"><i class="bi bi-gear text-secondary me-2"></i>Status &amp; Options</h5>
                </div>
                <div class="card-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Publish Status</label>
                        <select name="status" class="form-select rounded-3">
                            <option value="active" <?php echo $product['status'] === 'active' ? 'selected' : ''; ?>>Active (Visible in Marketplace)</option>
                            <option value="sold_out" <?php echo $product['status'] === 'sold_out' ? 'selected' : ''; ?>>Sold Out</option>
                            <option value="inactive" <?php echo $product['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive (Hidden)</option>
                        </select>
                        <div class="form-text text-muted small mt-2">Setting stock to 0 automatically updates status to Sold Out. Increasing stock makes it Available.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Subcategory</label>
                        <select name="subcategory_id" class="form-select rounded-3">
                            <option value="">General Market Special</option>
                            <?php foreach ($subcategories as $sc): ?>
                                <option value="<?php echo $sc['id']; ?>" <?php echo ($product['subcategory_id'] == $sc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sc['name_en']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" value="1" <?php echo $product['is_featured'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="is_featured">Featured on Homepage</label>
                    </div>

                    <button type="submit" class="btn btn-primary rounded-pill w-100 fw-bold py-2 shadow-sm">
                        <i class="bi bi-check-lg me-1"></i> Update Market Special
                    </button>
                    <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill w-100 mt-2">Cancel</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php include __DIR__ . '/partials/footer.php'; ?>
