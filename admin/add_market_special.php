<?php
/**
 * Admin Add Market Special
 * Phase 7 — Dynamic Product Form for Admin-Only Market Specials
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('admin');

$page_title   = "Add Market Special";
$page_heading = "Create Market Special";

$admin_id = (int)$_SESSION['user_id'];
$errors   = [];
$old      = [];

// Fetch Market Specials category
$cat_row = $conn->query("SELECT id, name_en, slug FROM categories WHERE is_admin_only = 1 OR slug = 'market-specials' LIMIT 1")->fetch_assoc();
$market_category_id = $cat_row ? (int)$cat_row['id'] : 5;

// Fetch subcategories under Market Specials if any exist
$subcats_res = $conn->query("SELECT id, name_en FROM subcategories WHERE category_id = {$market_category_id} AND status = 'active' ORDER BY name_en ASC");
$subcategories = [];
while ($sr = $subcats_res->fetch_assoc()) {
    $subcategories[] = $sr;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST;

    $product_type   = sanitize_input($_POST['product_type'] ?? PRODUCT_TYPE_BIRDS_ANIMALS);
    if (!in_array($product_type, [PRODUCT_TYPE_BIRDS_ANIMALS, PRODUCT_TYPE_SEEDS_FOOD, PRODUCT_TYPE_CAGES_HOUSES])) {
        $product_type = PRODUCT_TYPE_BIRDS_ANIMALS;
    }

    $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $is_featured    = isset($_POST['is_featured']) ? 1 : 0;
    $is_negotiable  = isset($_POST['is_negotiable']) ? 1 : 0;
    $listing_type   = 'store'; // Market specials default to store listing
    $status         = 'active'; // Admin products are immediately active upon creation unless stock is 0

    $title_en       = '';
    $description_en = '';
    $price          = 0.00;
    $discount_price = null;
    $stock_quantity = 0;
    $dynamic_data   = [];

    // Shared default core animal columns
    $breed          = null;
    $age            = null;
    $gender_db      = 'Unknown';
    $color          = null;
    $weight         = null;
    $city           = 'Market Special Admin HQ';
    $vaccination    = 0;
    $health_cert    = 0;

    if ($product_type === PRODUCT_TYPE_SEEDS_FOOD) {
        $title_en       = trim($_POST['title_en_seeds'] ?? '');
        $description_en = trim($_POST['description_en_seeds'] ?? '');
        $price_per_kg   = is_numeric($_POST['price_per_kg'] ?? '') ? (float)$_POST['price_per_kg'] : 0.00;
        $price          = $price_per_kg;

        $disc25         = trim($_POST['discount_price_25kg'] ?? '');
        $disc_25kg      = ($disc25 !== '' && is_numeric($disc25)) ? (float)$disc25 : null;

        $disc50         = trim($_POST['discount_price_50kg'] ?? '');
        $disc_50kg      = ($disc50 !== '' && is_numeric($disc50)) ? (float)$disc50 : null;

        $quality_raw    = trim($_POST['quality_seeds'] ?? 'A');
        $quality        = in_array($quality_raw, ['A+', 'A', 'B', 'C']) ? $quality_raw : 'A';

        $stock_mode     = ($_POST['stock_mode_seeds'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
        $stock_kg       = is_numeric($_POST['stock_kg'] ?? '') ? (float)$_POST['stock_kg'] : 0.00;

        if ($stock_mode === 'as_required') {
            $stock_quantity = 999;
        } else {
            $stock_quantity = max(0, (int)round($stock_kg));
        }

        if ($title_en === '') $errors[] = 'Seed / Food Name is required.';
        if ($description_en === '') $errors[] = 'Description is required.';
        if ($price_per_kg <= 0) $errors[] = 'Price per KG must be greater than 0.';
        if ($disc_25kg !== null && $disc_25kg <= 0) $errors[] = '25 KG Discount Price must be greater than 0.';
        if ($disc_50kg !== null && $disc_50kg <= 0) $errors[] = '50 KG Discount Price must be greater than 0.';

        $dynamic_data = [
            'price_per_kg'        => $price_per_kg,
            'discount_price_25kg' => $disc_25kg,
            'discount_price_50kg' => $disc_50kg,
            'quality'             => $quality,
            'stock_type'          => $stock_mode,
            'stock_kg'            => $stock_kg
        ];

    } elseif ($product_type === PRODUCT_TYPE_CAGES_HOUSES) {
        $title_en       = trim($_POST['title_en_cages'] ?? '');
        $animal_purpose = trim($_POST['animal_purpose'] ?? '');
        $description_en = trim($_POST['description_en_cages'] ?? '');

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

        $quality_raw    = trim($_POST['quality_cages'] ?? 'A');
        $quality        = in_array($quality_raw, ['A+', 'A', 'B', 'C']) ? $quality_raw : 'A';

        $stock_mode     = ($_POST['stock_mode_cages'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
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
        $title_en       = trim($_POST['title_en_animals'] ?? '');
        $description_en = trim($_POST['description_en_animals'] ?? '');
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
        if ($discount_price !== null && ($discount_price <= 0 || $discount_price >= $price)) {
            $errors[] = 'Discount price must be greater than 0 and less than regular price.';
        }

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

    // Set status based on stock rule
    if ($stock_quantity <= 0) {
        $status = 'sold_out';
    } else {
        $status = 'active';
    }

    // Duplicate check for admin
    $norm_title = normalize_product_name($title_en);
    if ($title_en !== '') {
        $dup_chk = $conn->prepare("SELECT id FROM products WHERE is_admin_product = 1 AND normalized_name = ? AND is_deleted = 0 LIMIT 1");
        $dup_chk->bind_param("s", $norm_title);
        $dup_chk->execute();
        if ($dup_chk->get_result()->fetch_assoc()) {
            $errors[] = "A Market Special with the name '{$title_en}' already exists. Please choose a unique name.";
        }
        $dup_chk->close();
    }

    // Handle Image Uploads
    $uploaded_images = [];
    $upload_dir = '../assets/uploads/products/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (!empty($_FILES['images']['name'][0])) {
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        foreach ($_FILES['images']['name'] as $idx => $orig_name) {
            if ($_FILES['images']['error'][$idx] !== UPLOAD_ERR_OK) continue;
            $tmp  = $_FILES['images']['tmp_name'][$idx];
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed_mime)) {
                $errors[] = "Image #" . ($idx + 1) . ": Invalid format. JPG, PNG, WEBP, GIF allowed.";
                continue;
            }
            $ext      = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            $new_name = 'spec_' . uniqid() . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                $uploaded_images[] = $new_name;
            }
        }
    } else {
        $errors[] = 'Please upload at least one image for the Market Special.';
    }

    // Save
    if (empty($errors)) {
        $base_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title_en));
        $base_slug = trim($base_slug, '-');
        if ($base_slug === '') $base_slug = 'market-special';
        $slug = $base_slug . '-' . uniqid();

        $conn->begin_transaction();
        try {
            $ins = $conn->prepare("
                INSERT INTO products
                  (seller_id, category_id, subcategory_id, product_type, title_en, normalized_name, slug,
                   description_en, price, discount_price, stock_quantity,
                   breed, age, gender, color, weight, city,
                   listing_type, is_admin_product, is_featured, is_negotiable,
                   vaccination_status, health_certificate, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'iiisssssddisssssssiiiis',
                $admin_id, $market_category_id, $subcategory_id, $product_type,
                $title_en, $norm_title, $slug, $description_en,
                $price, $discount_price, $stock_quantity,
                $breed, $age, $gender_db, $color, $weight, $city,
                $listing_type, $is_featured, $is_negotiable,
                $vaccination, $health_cert, $status
            );
            $ins->execute();
            $product_id = $conn->insert_id;
            $ins->close();

            // Save Dynamic Type Attributes
            save_product_dynamic_details($conn, $product_id, $product_type, $dynamic_data);

            // Save Images
            $img_ins = $conn->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)");
            foreach ($uploaded_images as $i => $img_name) {
                $is_primary = ($i === 0) ? 1 : 0;
                $img_ins->bind_param("isi", $product_id, $img_name, $is_primary);
                $img_ins->execute();
            }
            $img_ins->close();

            log_admin_activity($conn, $admin_id, "Created Market Special", "Added Market Special '{$title_en}' (ID: $product_id)");

            $conn->commit();
            $_SESSION['success'] = "Market Special product '{$title_en}' created successfully!";
            header('Location: market_specials.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            foreach ($uploaded_images as $img_name) {
                @unlink($upload_dir . $img_name);
            }
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

include __DIR__ . '/partials/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-stars text-warning me-2"></i>Add Market Special</h3>
        <p class="text-muted small mb-0">Create a high-priority, platform-exclusive Market Special item.</p>
    </div>
    <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill px-4">
        <i class="bi bi-arrow-left me-1"></i> Back to Market Specials
    </a>
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

<form action="add_market_special.php" method="POST" enctype="multipart/form-data" id="marketSpecialForm">
    <?php csrf_field(); ?>

    <!-- STEP 1: Select Product Type -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold mb-1"><i class="bi bi-diagram-3 text-primary me-2"></i>1. Select Product Type</h5>
            <p class="text-muted small">Choose the product type. Form fields will update dynamically.</p>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer type-selector-card shadow-sm" style="cursor: pointer;">
                        <input type="radio" name="product_type" value="<?php echo PRODUCT_TYPE_BIRDS_ANIMALS; ?>" class="d-none" <?php echo (!isset($old['product_type']) || $old['product_type'] === PRODUCT_TYPE_BIRDS_ANIMALS) ? 'checked' : ''; ?> onchange="switchProductType('<?php echo PRODUCT_TYPE_BIRDS_ANIMALS; ?>')">
                        <div class="my-2">
                            <i class="bi bi-feather fs-1 text-primary"></i>
                        </div>
                        <h6 class="fw-bold mb-1">Birds &amp; Animals</h6>
                        <small class="text-muted">Live birds &amp; animals with breed, age, gender &amp; health certs</small>
                    </label>
                </div>
                <div class="col-md-4">
                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer type-selector-card shadow-sm" style="cursor: pointer;">
                        <input type="radio" name="product_type" value="<?php echo PRODUCT_TYPE_SEEDS_FOOD; ?>" class="d-none" <?php echo (isset($old['product_type']) && $old['product_type'] === PRODUCT_TYPE_SEEDS_FOOD) ? 'checked' : ''; ?> onchange="switchProductType('<?php echo PRODUCT_TYPE_SEEDS_FOOD; ?>')">
                        <div class="my-2">
                            <i class="bi bi-basket2 fs-1 text-success"></i>
                        </div>
                        <h6 class="fw-bold mb-1">Seeds &amp; Animal Food</h6>
                        <small class="text-muted">Feed, seeds, grain with Per-KG rates &amp; bulk discounts</small>
                    </label>
                </div>
                <div class="col-md-4">
                    <label class="card h-100 p-3 border rounded-4 text-center cursor-pointer type-selector-card shadow-sm" style="cursor: pointer;">
                        <input type="radio" name="product_type" value="<?php echo PRODUCT_TYPE_CAGES_HOUSES; ?>" class="d-none" <?php echo (isset($old['product_type']) && $old['product_type'] === PRODUCT_TYPE_CAGES_HOUSES) ? 'checked' : ''; ?> onchange="switchProductType('<?php echo PRODUCT_TYPE_CAGES_HOUSES; ?>')">
                        <div class="my-2">
                            <i class="bi bi-house-door fs-1 text-warning"></i>
                        </div>
                        <h6 class="fw-bold mb-1">Cages &amp; Houses</h6>
                        <small class="text-muted">Habitats &amp; cages with dimensions (Inches) &amp; bulk piece rates</small>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 2: DYNAMIC FORM FIELDS -->

    <!-- Form Section A: Birds & Animals -->
    <div id="section_birds_animals" class="product-type-section">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-primary"><i class="bi bi-feather me-2"></i>Birds &amp; Animals Details</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-medium">Listing Name <span class="text-danger">*</span></label>
                        <input type="text" name="title_en_animals" class="form-control rounded-3" placeholder="e.g. Rare Albino White Peacock" value="<?php echo htmlspecialchars($old['title_en_animals'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Breed / Species</label>
                        <input type="text" name="breed" class="form-control rounded-3" placeholder="e.g. Indian Peafowl" value="<?php echo htmlspecialchars($old['breed'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                        <textarea name="description_en_animals" class="form-control rounded-3" rows="4" placeholder="Provide complete information about health, diet, pedigree, etc."><?php echo htmlspecialchars($old['description_en_animals'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-medium">Price (PKR) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="price" class="form-control rounded-3" placeholder="0.00" value="<?php echo htmlspecialchars($old['price'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Discount Price (PKR)</label>
                        <input type="number" step="0.01" name="discount_price" class="form-control rounded-3" placeholder="Optional" value="<?php echo htmlspecialchars($old['discount_price'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Stock Quantity <span class="text-danger">*</span></label>
                        <input type="number" name="stock_quantity" min="0" class="form-control rounded-3" placeholder="1" value="<?php echo htmlspecialchars($old['stock_quantity'] ?? '1'); ?>">
                        <small class="text-muted">Stock &gt; 0 = Available, 0 = Sold Out</small>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-medium">Gender</label>
                        <select name="gender" class="form-select rounded-3">
                            <option value="unknown" <?php echo (($old['gender'] ?? '') === 'unknown') ? 'selected' : ''; ?>>Unknown (Default)</option>
                            <option value="male" <?php echo (($old['gender'] ?? '') === 'male') ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo (($old['gender'] ?? '') === 'female') ? 'selected' : ''; ?>>Female</option>
                            <option value="pair" <?php echo (($old['gender'] ?? '') === 'pair') ? 'selected' : ''; ?>>Pair</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Age</label>
                        <input type="text" name="age" class="form-control rounded-3" placeholder="e.g. 8 months" value="<?php echo htmlspecialchars($old['age'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Color</label>
                        <input type="text" name="color" class="form-control rounded-3" placeholder="e.g. Pure White" value="<?php echo htmlspecialchars($old['color'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Weight</label>
                        <input type="text" name="weight" class="form-control rounded-3" placeholder="e.g. 4.5 KG" value="<?php echo htmlspecialchars($old['weight'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium">City / Location</label>
                        <input type="text" name="city" class="form-control rounded-3" placeholder="e.g. Lahore / Nationwide Delivery" value="<?php echo htmlspecialchars($old['city'] ?? 'Nationwide Delivery'); ?>">
                    </div>
                    <div class="col-md-3 pt-md-4">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="vaccination_status" id="vaccination_status" value="1" <?php echo !empty($old['vaccination_status']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-medium" for="vaccination_status">Vaccinated</label>
                        </div>
                    </div>
                    <div class="col-md-3 pt-md-4">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="health_certificate" id="health_certificate" value="1" <?php echo !empty($old['health_certificate']) ? 'checked' : ''; ?>>
                            <label class="form-check-label fw-medium" for="health_certificate">Health Certificate</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Section B: Seeds & Food -->
    <div id="section_seeds_food" class="product-type-section d-none">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-success"><i class="bi bi-basket2 me-2"></i>Seeds &amp; Animal Food Details</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-medium">Seed / Food Name <span class="text-danger">*</span></label>
                        <input type="text" name="title_en_seeds" class="form-control rounded-3" placeholder="e.g. Premium Millet &amp; Sunflower Bird Seed Mix" value="<?php echo htmlspecialchars($old['title_en_seeds'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Quality Grade <span class="text-danger">*</span></label>
                        <select name="quality_seeds" class="form-select rounded-3">
                            <option value="A+" <?php echo (($old['quality_seeds'] ?? '') === 'A+') ? 'selected' : ''; ?>>Grade A+ (Export Quality)</option>
                            <option value="A" <?php echo (($old['quality_seeds'] ?? 'A') === 'A') ? 'selected' : ''; ?>>Grade A (Standard Pure)</option>
                            <option value="B" <?php echo (($old['quality_seeds'] ?? '') === 'B') ? 'selected' : ''; ?>>Grade B (Medium Clean)</option>
                            <option value="C" <?php echo (($old['quality_seeds'] ?? '') === 'C') ? 'selected' : ''; ?>>Grade C (Regular)</option>
                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                        <textarea name="description_en_seeds" class="form-control rounded-3" rows="4" placeholder="Detail nutritional value, suitable animals, cleanliness, etc."><?php echo htmlspecialchars($old['description_en_seeds'] ?? ''); ?></textarea>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-medium">Price per KG (PKR) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="price_per_kg" class="form-control rounded-3" placeholder="e.g. 250.00" value="<?php echo htmlspecialchars($old['price_per_kg'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Discount Price for 25 KG (PKR)</label>
                        <input type="number" step="0.01" name="discount_price_25kg" class="form-control rounded-3" placeholder="e.g. 5800.00" value="<?php echo htmlspecialchars($old['discount_price_25kg'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium">Discount Price for 50 KG (PKR)</label>
                        <input type="number" step="0.01" name="discount_price_50kg" class="form-control rounded-3" placeholder="e.g. 11000.00" value="<?php echo htmlspecialchars($old['discount_price_50kg'] ?? ''); ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium">Stock Mode <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stock_mode_seeds" id="stock_mode_seeds_req" value="as_required" <?php echo (($old['stock_mode_seeds'] ?? '') === 'as_required') ? 'checked' : ''; ?> onchange="toggleSeedsStockKg(false)">
                                <label class="form-check-label fw-medium" for="stock_mode_seeds_req">As Required / On Demand</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stock_mode_seeds" id="stock_mode_seeds_num" value="numeric" <?php echo (($old['stock_mode_seeds'] ?? 'numeric') === 'numeric') ? 'checked' : ''; ?> onchange="toggleSeedsStockKg(true)">
                                <label class="form-check-label fw-medium" for="stock_mode_seeds_num">Specific Numeric KG Quantity</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="seeds_stock_kg_wrapper">
                        <label class="form-label fw-medium">Stock Quantity (KG)</label>
                        <input type="number" step="0.5" name="stock_kg" class="form-control rounded-3" placeholder="e.g. 500" value="<?php echo htmlspecialchars($old['stock_kg'] ?? '100'); ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Section C: Cages & Houses -->
    <div id="section_cages_houses" class="product-type-section d-none">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
                <h5 class="fw-bold mb-1 text-warning"><i class="bi bi-house-door me-2"></i>Cages &amp; Houses Details</h5>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-medium">Cage / House Name <span class="text-danger">*</span></label>
                        <input type="text" name="title_en_cages" class="form-control rounded-3" placeholder="e.g. Heavy Duty Galvanized Breeding Cage" value="<?php echo htmlspecialchars($old['title_en_cages'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium">For What Animal / Bird <span class="text-danger">*</span></label>
                        <input type="text" name="animal_purpose" class="form-control rounded-3" placeholder="e.g. Parrots, Canaries, Rabbits" value="<?php echo htmlspecialchars($old['animal_purpose'] ?? ''); ?>">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                        <textarea name="description_en_cages" class="form-control rounded-3" rows="4" placeholder="Detail gauge, wire quality, trays, doors, coating, etc."><?php echo htmlspecialchars($old['description_en_cages'] ?? ''); ?></textarea>
                    </div>

                    <!-- Dimensions (Unit = Inches) -->
                    <div class="col-12">
                        <div class="p-3 bg-light rounded-4">
                            <h6 class="fw-bold mb-2"><i class="bi bi-rulers me-1"></i>Dimensions (Unit: Inches)</h6>
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Length (inches)</label>
                                    <input type="number" step="0.1" name="length" class="form-control rounded-3" placeholder="e.g. 24" value="<?php echo htmlspecialchars($old['length'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Width (inches)</label>
                                    <input type="number" step="0.1" name="width" class="form-control rounded-3" placeholder="e.g. 18" value="<?php echo htmlspecialchars($old['width'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Depth (inches)</label>
                                    <input type="number" step="0.1" name="depth" class="form-control rounded-3" placeholder="e.g. 18" value="<?php echo htmlspecialchars($old['depth'] ?? ''); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">Height (inches)</label>
                                    <input type="number" step="0.1" name="height" class="form-control rounded-3" placeholder="e.g. 36" value="<?php echo htmlspecialchars($old['height'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label fw-medium">Price per Piece (PKR) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="price_per_piece" class="form-control rounded-3" placeholder="e.g. 3500.00" value="<?php echo htmlspecialchars($old['price_per_piece'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Discount for 12 Pieces (PKR)</label>
                        <input type="number" step="0.01" name="discount_price_12pcs" class="form-control rounded-3" placeholder="e.g. 38000.00" value="<?php echo htmlspecialchars($old['discount_price_12pcs'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Discount for 24 Pieces (PKR)</label>
                        <input type="number" step="0.01" name="discount_price_24pcs" class="form-control rounded-3" placeholder="e.g. 72000.00" value="<?php echo htmlspecialchars($old['discount_price_24pcs'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-medium">Quality Grade <span class="text-danger">*</span></label>
                        <select name="quality_cages" class="form-select rounded-3">
                            <option value="A+" <?php echo (($old['quality_cages'] ?? '') === 'A+') ? 'selected' : ''; ?>>Grade A+ (Premium Heavy)</option>
                            <option value="A" <?php echo (($old['quality_cages'] ?? 'A') === 'A') ? 'selected' : ''; ?>>Grade A (Standard Wire)</option>
                            <option value="B" <?php echo (($old['quality_cages'] ?? '') === 'B') ? 'selected' : ''; ?>>Grade B (Medium Wire)</option>
                            <option value="C" <?php echo (($old['quality_cages'] ?? '') === 'C') ? 'selected' : ''; ?>>Grade C (Lightweight)</option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-medium">Stock Mode <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stock_mode_cages" id="stock_mode_cages_req" value="as_required" <?php echo (($old['stock_mode_cages'] ?? '') === 'as_required') ? 'checked' : ''; ?> onchange="toggleCagesStockPieces(false)">
                                <label class="form-check-label fw-medium" for="stock_mode_cages_req">As Required / Built on Demand</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="stock_mode_cages" id="stock_mode_cages_num" value="numeric" <?php echo (($old['stock_mode_cages'] ?? 'numeric') === 'numeric') ? 'checked' : ''; ?> onchange="toggleCagesStockPieces(true)">
                                <label class="form-check-label fw-medium" for="stock_mode_cages_num">Specific Pieces in Stock</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="cages_stock_pieces_wrapper">
                        <label class="form-label fw-medium">Stock Quantity (Pieces)</label>
                        <input type="number" name="stock_pieces" class="form-control rounded-3" placeholder="e.g. 20" value="<?php echo htmlspecialchars($old['stock_pieces'] ?? '10'); ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 3: Images & Options -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4 pb-0">
            <h5 class="fw-bold mb-1"><i class="bi bi-images text-info me-2"></i>3. Product Images &amp; Visibility</h5>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Upload Images <span class="text-danger">*</span></label>
                    <input type="file" name="images[]" class="form-control rounded-3" multiple accept="image/jpeg,image/png,image/webp,image/gif" required>
                    <div class="form-text text-muted small">Select 1 or more images. First image will be the primary catalog display image.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-medium">Market Subcategory</label>
                    <select name="subcategory_id" class="form-select rounded-3">
                        <option value="">General Market Special</option>
                        <?php foreach ($subcategories as $sc): ?>
                            <option value="<?php echo $sc['id']; ?>" <?php echo (($old['subcategory_id'] ?? '') == $sc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($sc['name_en']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 pt-md-4">
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_featured" id="is_featured" value="1" <?php echo !empty($old['is_featured']) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-medium" for="is_featured">Featured on Homepage</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Submit Button -->
    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="market_specials.php" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
        <button type="submit" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm">
            <i class="bi bi-check-lg me-1"></i> Save &amp; Publish Market Special
        </button>
    </div>
</form>

<script>
function switchProductType(type) {
    // Hide all
    document.querySelectorAll('.product-type-section').forEach(el => el.classList.add('d-none'));

    // Highlight card
    document.querySelectorAll('.type-selector-card').forEach(c => {
        c.classList.remove('border-primary', 'bg-light', 'border-2');
    });

    const activeRadio = document.querySelector(`input[name="product_type"][value="${type}"]`);
    if (activeRadio) {
        activeRadio.checked = true;
        const card = activeRadio.closest('.type-selector-card');
        if (card) {
            card.classList.add('border-primary', 'bg-light', 'border-2');
        }
    }

    // Show selected section
    const targetSection = document.getElementById(`section_${type}`);
    if (targetSection) {
        targetSection.classList.remove('d-none');
    }
}

function toggleSeedsStockKg(show) {
    const el = document.getElementById('seeds_stock_kg_wrapper');
    if (el) el.style.display = show ? 'block' : 'none';
}

function toggleCagesStockPieces(show) {
    const el = document.getElementById('cages_stock_pieces_wrapper');
    if (el) el.style.display = show ? 'block' : 'none';
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const checkedType = document.querySelector('input[name="product_type"]:checked');
    if (checkedType) {
        switchProductType(checkedType.value);
    } else {
        switchProductType('<?php echo PRODUCT_TYPE_BIRDS_ANIMALS; ?>');
    }
});
</script>

<?php include __DIR__ . '/partials/footer.php'; ?>
