<?php
/**
 * Add Product — Phase 4: Dynamic Product Forms
 * Supports all 3 Product Types:
 * 1. Birds & Animals (`birds_animals`)
 * 2. Seeds & Animal Food (`seeds_food`)
 * 3. Cages & Houses (`cages_houses`)
 */
require_once '../includes/config.php';
require_once '../includes/auth.php';

require_role('seller');

$user      = current_user($conn);
$seller_id = (int)$_SESSION['user_id'];
$errors    = [];
$old       = []; // Repopulate form on error
$duplicate_product = null; // Holds duplicate product data if detected

// ── Fetch active categories (exclude admin-only like Market Specials) ──
$cats_result = $conn->query("
    SELECT id, name_en, slug, section, default_product_type 
    FROM categories 
    WHERE status = 'active' AND is_admin_only = 0 AND is_deleted = 0 
    ORDER BY id ASC
");
$categories = [];
while ($row = $cats_result->fetch_assoc()) {
    $categories[] = $row;
}

// ── Handle POST ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $old = $_POST; // Keep for repopulation

    $category_id    = (int)($_POST['category_id'] ?? 0);
    $subcategory_id = !empty($_POST['subcategory_id']) ? (int)$_POST['subcategory_id'] : null;
    $listing_type   = ($_POST['listing_type'] ?? 'store') === 'market' ? 'market' : 'store';
    $is_featured    = isset($_POST['is_featured']) ? 1 : 0;
    $status         = 'pending'; // Always pending on creation for admin review

    // Determine target product type
    $category_row = null;
    if (!$category_id) {
        $errors[] = 'Please select a Category.';
        $product_type = PRODUCT_TYPE_BIRDS_ANIMALS;
    } else {
        $cat_chk = $conn->prepare("SELECT id, name_en, slug, is_admin_only, default_product_type FROM categories WHERE id = ? AND status = 'active' AND is_deleted = 0");
        $cat_chk->bind_param("i", $category_id);
        $cat_chk->execute();
        $category_row = $cat_chk->get_result()->fetch_assoc();
        $cat_chk->close();

        if (!$category_row) {
            $errors[] = 'Selected category does not exist or is inactive.';
            $product_type = PRODUCT_TYPE_BIRDS_ANIMALS;
        } elseif (!empty($category_row['is_admin_only'])) {
            $errors[] = 'Market Specials is an admin-only category. Sellers cannot list products here.';
            $product_type = PRODUCT_TYPE_BIRDS_ANIMALS;
        } else {
            $product_type = !empty($category_row['default_product_type']) 
                ? $category_row['default_product_type'] 
                : get_category_product_type($conn, $category_id);
        }
    }

    // Validate subcategory if supplied
    if ($subcategory_id && $category_id) {
        $sub_chk = $conn->prepare("SELECT id FROM subcategories WHERE id = ? AND category_id = ? AND status = 'active'");
        $sub_chk->bind_param("ii", $subcategory_id, $category_id);
        $sub_chk->execute();
        if ($sub_chk->get_result()->num_rows === 0) {
            $errors[] = 'The selected subcategory is invalid for this category.';
        }
        $sub_chk->close();
    }

    // Initialize core columns
    $title_en       = '';
    $description_en = '';
    $price          = 0.00;
    $discount_price = null;
    $stock_quantity = 0;
    $is_negotiable  = isset($_POST['is_negotiable']) ? 1 : 0;
    $breed          = null;
    $age            = null;
    $gender_db      = 'Unknown';
    $color          = null;
    $weight         = null;
    $city           = trim($_POST['city'] ?? $user['city'] ?? '');
    $vaccination    = 0;
    $health_cert    = 0;
    $dynamic_data   = [];

    // ─────────────────────────────────────────────────────────
    // TYPE SPECIFIC PARSING & VALIDATION
    // ─────────────────────────────────────────────────────────
    if ($product_type === PRODUCT_TYPE_SEEDS_FOOD) {
        // Seeds & Animal Food
        $title_en       = trim($_POST['title_en_seeds'] ?? $_POST['title_en'] ?? '');
        $description_en = trim($_POST['description_en_seeds'] ?? $_POST['description_en'] ?? '');

        $pkg_input      = trim($_POST['price_per_kg'] ?? '');
        $price_per_kg   = is_numeric($pkg_input) ? (float)$pkg_input : 0.00;
        $price          = $price_per_kg; // Core price = price per KG

        $disc25_input   = trim($_POST['discount_price_25kg'] ?? '');
        $disc_25kg      = ($disc25_input !== '' && is_numeric($disc25_input)) ? (float)$disc25_input : null;

        $disc50_input   = trim($_POST['discount_price_50kg'] ?? '');
        $disc_50kg      = ($disc50_input !== '' && is_numeric($disc50_input)) ? (float)$disc50_input : null;

        $quality_raw    = trim($_POST['quality_seeds'] ?? $_POST['quality'] ?? '');
        $quality        = in_array($quality_raw, ['A+', 'A', 'B', 'C']) ? $quality_raw : '';

        $stock_mode     = ($_POST['stock_mode_seeds'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
        $stock_kg_input = trim($_POST['stock_kg'] ?? '');
        $stock_kg       = ($stock_kg_input !== '' && is_numeric($stock_kg_input)) ? (float)$stock_kg_input : 0.00;

        if ($stock_mode === 'as_required') {
            $stock_quantity = 999;
        } else {
            $stock_quantity = max(0, (int)round($stock_kg));
        }

        // Validations
        if ($title_en === '') {
            $errors[] = 'Seed / Food Name is required.';
        } elseif (mb_strlen($title_en) < 3) {
            $errors[] = 'Seed / Food Name must be at least 3 characters.';
        }

        if ($description_en === '') {
            $errors[] = 'Description is required.';
        } elseif (mb_strlen($description_en) < 10) {
            $errors[] = 'Description must be at least 10 characters.';
        }

        if ($price_per_kg <= 0) {
            $errors[] = 'Price per KG (PKR) is required and must be greater than 0.';
        }

        if ($disc_25kg !== null) {
            if ($disc_25kg <= 0) {
                $errors[] = 'Discount Price for 25 KG must be greater than 0.';
            } elseif ($price_per_kg > 0 && $disc_25kg >= ($price_per_kg * 25)) {
                $errors[] = 'Discount Price for 25 KG must be lower than the standard 25 KG price (' . number_format($price_per_kg * 25, 2) . ').';
            }
        }

        if ($disc_50kg !== null) {
            if ($disc_50kg <= 0) {
                $errors[] = 'Discount Price for 50 KG must be greater than 0.';
            } elseif ($price_per_kg > 0 && $disc_50kg >= ($price_per_kg * 50)) {
                $errors[] = 'Discount Price for 50 KG must be lower than the standard 50 KG price (' . number_format($price_per_kg * 50, 2) . ').';
            }
            if ($disc_25kg !== null && $disc_50kg >= ($disc_25kg * 2)) {
                $errors[] = 'Discount Price for 50 KG should offer better or equal bulk value compared to 25 KG.';
            }
        }

        if ($quality === '') {
            $errors[] = 'Quality grade (A+, A, B, C) is required.';
        }

        if ($stock_mode === 'numeric' && ($stock_kg_input === '' || $stock_kg < 0)) {
            $errors[] = 'Numeric KG stock quantity is required and cannot be negative.';
        }

        $dynamic_data = [
            'price_per_kg'        => $price_per_kg,
            'discount_price_25kg' => $disc_25kg,
            'discount_price_50kg' => $disc_50kg,
            'quality'             => $quality,
            'stock_type'          => $stock_mode,
            'stock_kg'            => $stock_kg
        ];

    } elseif ($product_type === PRODUCT_TYPE_CAGES_HOUSES) {
        // Cages & Houses
        $title_en       = trim($_POST['title_en_cages'] ?? $_POST['title_en'] ?? '');
        $animal_purpose = trim($_POST['animal_purpose'] ?? '');
        $description_en = trim($_POST['description_en_cages'] ?? $_POST['description_en'] ?? '');

        // Dimensions
        $length_in      = trim($_POST['length'] ?? '');
        $width_in       = trim($_POST['width'] ?? '');
        $depth_in       = trim($_POST['depth'] ?? '');
        $height_in      = trim($_POST['height'] ?? '');
        $length         = ($length_in !== '' && is_numeric($length_in)) ? (float)$length_in : null;
        $width          = ($width_in !== '' && is_numeric($width_in)) ? (float)$width_in : null;
        $depth          = ($depth_in !== '' && is_numeric($depth_in)) ? (float)$depth_in : null;
        $height         = ($height_in !== '' && is_numeric($height_in)) ? (float)$height_in : null;
        $unit_dimension = 'inches';

        // Pricing & Stock
        $ppc_input      = trim($_POST['price_per_piece'] ?? '');
        $price_per_piece= is_numeric($ppc_input) ? (float)$ppc_input : 0.00;
        $price          = $price_per_piece; // Core price = price per piece

        $disc12_input   = trim($_POST['discount_price_12pcs'] ?? '');
        $disc_12pcs     = ($disc12_input !== '' && is_numeric($disc12_input)) ? (float)$disc12_input : null;

        $disc24_input   = trim($_POST['discount_price_24pcs'] ?? '');
        $disc_24pcs     = ($disc24_input !== '' && is_numeric($disc24_input)) ? (float)$disc24_input : null;

        $quality_raw    = trim($_POST['quality_cages'] ?? $_POST['quality'] ?? '');
        $quality        = in_array($quality_raw, ['A+', 'A', 'B', 'C']) ? $quality_raw : '';

        $stock_mode     = ($_POST['stock_mode_cages'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
        $stock_pcs_input= trim($_POST['stock_pieces'] ?? '');
        $stock_pieces   = ($stock_pcs_input !== '' && is_numeric($stock_pcs_input)) ? (int)$stock_pcs_input : 0;

        if ($stock_mode === 'as_required') {
            $stock_quantity = 999;
        } else {
            $stock_quantity = max(0, $stock_pieces);
        }

        // Validations
        if ($title_en === '') {
            $errors[] = 'Cage / House Name is required.';
        } elseif (mb_strlen($title_en) < 3) {
            $errors[] = 'Cage / House Name must be at least 3 characters.';
        }

        if ($animal_purpose === '') {
            $errors[] = 'Target animal / bird purpose is required (e.g., Parrots, Pigeons, Rabbits).';
        }

        if ($description_en === '') {
            $errors[] = 'Description is required.';
        } elseif (mb_strlen($description_en) < 10) {
            $errors[] = 'Description must be at least 10 characters.';
        }

        if ($length !== null && $length <= 0) $errors[] = 'Length must be greater than 0.';
        if ($width !== null && $width <= 0)   $errors[] = 'Width must be greater than 0.';
        if ($depth !== null && $depth <= 0)   $errors[] = 'Depth must be greater than 0.';
        if ($height !== null && $height <= 0) $errors[] = 'Height must be greater than 0.';

        if ($price_per_piece <= 0) {
            $errors[] = 'Price per Piece (PKR) is required and must be greater than 0.';
        }

        if ($disc_12pcs !== null) {
            if ($disc_12pcs <= 0) {
                $errors[] = 'Discount for 12 Pieces must be greater than 0.';
            } elseif ($price_per_piece > 0 && $disc_12pcs >= ($price_per_piece * 12)) {
                $errors[] = 'Discount for 12 Pieces must be lower than standard price for 12 pieces (' . number_format($price_per_piece * 12, 2) . ').';
            }
        }

        if ($disc_24pcs !== null) {
            if ($disc_24pcs <= 0) {
                $errors[] = 'Discount for 24 Pieces must be greater than 0.';
            } elseif ($price_per_piece > 0 && $disc_24pcs >= ($price_per_piece * 24)) {
                $errors[] = 'Discount for 24 Pieces must be lower than standard price for 24 pieces (' . number_format($price_per_piece * 24, 2) . ').';
            }
        }

        if ($quality === '') {
            $errors[] = 'Quality grade (A+, A, B, C) is required.';
        }

        if ($stock_mode === 'numeric' && ($stock_pcs_input === '' || $stock_pieces < 0)) {
            $errors[] = 'Numeric pieces stock quantity is required and cannot be negative.';
        }

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
        // Birds & Animals (Default)
        $title_en       = trim($_POST['title_en_animals'] ?? $_POST['title_en'] ?? '');
        $description_en = trim($_POST['description_en_animals'] ?? $_POST['description_en'] ?? '');

        $price_input    = trim($_POST['price'] ?? '');
        $price          = is_numeric($price_input) ? (float)$price_input : 0.00;
        $discount_input = trim($_POST['discount_price'] ?? '');
        $discount_price = ($discount_input !== '' && is_numeric($discount_input)) ? (float)$discount_input : null;
        $stock_input    = trim($_POST['stock_quantity'] ?? '');
        $stock_quantity = ($stock_input !== '' && is_numeric($stock_input)) ? (int)$stock_input : null;

        $breed          = trim($_POST['breed'] ?? '');
        $age            = trim($_POST['age'] ?? '');
        $gender_raw     = strtolower(trim($_POST['gender'] ?? 'unknown'));
        $gender         = in_array($gender_raw, ['male', 'female', 'pair', 'unknown']) ? $gender_raw : 'unknown';
        $gender_db      = ucfirst($gender);
        $color          = trim($_POST['color'] ?? '');
        $weight         = trim($_POST['weight'] ?? '');
        $vaccination    = isset($_POST['vaccination_status']) ? 1 : 0;
        $health_cert    = isset($_POST['health_certificate']) ? 1 : 0;

        if ($title_en === '') {
            $errors[] = 'Product / Listing Name is required.';
        } elseif (mb_strlen($title_en) < 3) {
            $errors[] = 'Product Name must be at least 3 characters.';
        }

        if ($description_en === '') {
            $errors[] = 'Description is required.';
        } elseif (mb_strlen($description_en) < 10) {
            $errors[] = 'Description must be at least 10 characters.';
        }

        if ($price <= 0) {
            $errors[] = 'Price (PKR) is required and must be greater than 0.';
        }

        if ($discount_price !== null) {
            if ($discount_price < 0) {
                $errors[] = 'Discount Price cannot be negative.';
            } elseif ($discount_price >= $price) {
                $errors[] = 'Discount Price must be less than the regular price.';
            }
        }

        if ($stock_quantity === null || $stock_quantity < 0) {
            $errors[] = 'Stock Quantity is required and must be 0 or greater.';
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

    // ── Duplicate Product Name Protection (Phase 5) ───────────
    $norm_title = normalize_product_name($title_en);
    if ($title_en !== '') {
        $duplicate_product = find_seller_duplicate_product($conn, $seller_id, $title_en);
        if ($duplicate_product) {
            $errors[] = 'duplicate_listing_error'; // Handled via dedicated alert UI with Edit link
        }
    }

    // ── Validate & Upload Images ──────────────────────────────
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
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
                $errors[] = "Image #" . ($idx + 1) . ": Only JPG, PNG, WEBP, and GIF images are allowed.";
                continue;
            }
            if ($size > $max_size) {
                $errors[] = "Image #" . ($idx + 1) . ": File exceeds maximum size of 3 MB.";
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

    // ── Database Insertion ────────────────────────────────────
    if (empty($errors)) {
        // Unique URL Slug
        $base_slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title_en));
        $base_slug = trim($base_slug, '-');
        if ($base_slug === '') $base_slug = 'product';
        $slug = $base_slug . '-' . uniqid();

        $conn->begin_transaction();
        try {
            // Insert into core products table including normalized_name
            $ins = $conn->prepare("
                INSERT INTO products
                  (seller_id, category_id, subcategory_id, product_type, title_en, normalized_name, slug,
                   description_en, price, discount_price, stock_quantity,
                   breed, age, gender, color, weight, city,
                   listing_type, is_featured, is_negotiable,
                   vaccination_status, health_certificate, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'iiisssssddisssssssiiiis',
                $seller_id, $category_id, $subcategory_id, $product_type,
                $title_en, $norm_title, $slug, $description_en,
                $price, $discount_price, $stock_quantity,
                $breed, $age, $gender_db, $color, $weight, $city,
                $listing_type, $is_featured, $is_negotiable,
                $vaccination, $health_cert, $status
            );
            $ins->execute();
            $product_id = $conn->insert_id;
            $ins->close();

            // Save normalized dynamic attributes into supplemental table
            save_product_dynamic_details($conn, $product_id, $product_type, $dynamic_data);

            // Save uploaded product images
            $img_ins = $conn->prepare("
                INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)
            ");
            foreach ($uploaded_images as $i => $img_name) {
                $is_primary = ($i === 0) ? 1 : 0;
                $img_ins->bind_param('isi', $product_id, $img_name, $is_primary);
                $img_ins->execute();
            }
            $img_ins->close();

            $conn->commit();

            // Notify Admin
            $seller_name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            $admin_msg = "Seller " . htmlspecialchars($seller_name) . " added a new listing: " . htmlspecialchars($title_en);
            $notif_stmt = $conn->prepare("
                INSERT INTO admin_notifications (type, title, message, link) 
                VALUES ('new_product', 'New Product Requires Approval', ?, 'products.php?status=pending')
            ");
            if ($notif_stmt) {
                $notif_stmt->bind_param("s", $admin_msg);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            $_SESSION['success'] = 'Product listed successfully! It has been submitted for Admin Review.';
            header('Location: products.php');
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            // Clean up uploaded files on database failure
            foreach ($uploaded_images as $img_name) {
                @unlink($upload_dir . $img_name);
            }
            $errors[] = 'Database error occurred: ' . $e->getMessage();
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

        <!-- Main Content -->
        <div class="col-lg-10">

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="mb-3">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="dashboard.php" class="text-success text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="products.php" class="text-success text-decoration-none">My Products</a></li>
                    <li class="breadcrumb-item active">Add Product</li>
                </ol>
            </nav>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="fw-bold mb-1">
                        <i class="bi bi-plus-circle text-warning me-2"></i>Add Product
                    </h3>
                    <p class="text-muted small mb-0">List new Birds & Animals, Seeds & Food, or Cages & Houses for sale.</p>
                </div>
                <a href="products.php" class="btn btn-outline-secondary rounded-pill px-3">
                    <i class="bi bi-arrow-left me-1"></i>Back to My Products
                </a>
            </div>

            <!-- Duplicate Listing Warning (Phase 5) -->
            <?php if (!empty($duplicate_product)): ?>
                <div class="alert alert-warning border-0 shadow-sm rounded-4 p-4 mb-4" role="alert">
                    <div class="d-flex align-items-start">
                        <div class="bg-warning bg-opacity-25 text-warning-emphasis rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <h5 class="fw-bold text-dark mb-1">Duplicate Product Detected</h5>
                            <p class="text-dark mb-3">
                                You already have a product listing with this name. Please update the stock of your existing listing instead of creating a duplicate listing.
                            </p>
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <a href="edit_product.php?id=<?php echo (int)$duplicate_product['id']; ?>" class="btn btn-warning fw-bold rounded-pill px-4 shadow-sm">
                                    <i class="bi bi-pencil-square me-2"></i>Update Existing Product
                                </a>
                                <span class="badge bg-light text-dark border px-3 py-2 rounded-pill small">
                                    Existing Listing: #<?php echo (int)$duplicate_product['id']; ?> &mdash; <?php echo htmlspecialchars($duplicate_product['title_en']); ?> (Stock: <?php echo (int)$duplicate_product['stock_quantity']; ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Error Alerts (Filtered) -->
            <?php 
                $other_errors = array_filter($errors, function($e) { return $e !== 'duplicate_listing_error'; });
            ?>
            <?php if (!empty($other_errors)): ?>
                <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-exclamation-triangle-fill fs-5 me-2"></i>
                        <h6 class="fw-bold mb-0">Please fix the following errors:</h6>
                    </div>
                    <ul class="mb-0 ps-4">
                        <?php foreach ($other_errors as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="add_product.php" method="POST" enctype="multipart/form-data" novalidate id="addProductForm">
                <?php csrf_field(); ?>

                <div class="row g-4">

                    <!-- ── Left Column (Dynamic Form Sections) ────────── -->
                    <div class="col-xl-8">

                        <!-- CATEGORY SELECTION -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-diagram-3 text-success me-2"></i>Category Selection
                                </h5>
                                <p class="text-muted small mb-0">Select what you are listing to show relevant fields.</p>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-medium">Main Category <span class="text-danger">*</span></label>
                                        <select name="category_id" id="category_id" class="form-select rounded-3 border-0 bg-light py-2" required onchange="handleCategorySelection(this)">
                                            <option value="">-- Select Category --</option>
                                            <?php foreach ($categories as $c): ?>
                                                <?php 
                                                    $selected_cat = isset($old['category_id']) ? (int)$old['category_id'] : 1;
                                                    $is_sel = ($selected_cat === (int)$c['id']) ? 'selected' : '';
                                                ?>
                                                <option value="<?php echo $c['id']; ?>" 
                                                        data-slug="<?php echo htmlspecialchars($c['slug']); ?>" 
                                                        data-type="<?php echo htmlspecialchars($c['default_product_type'] ?? 'birds_animals'); ?>" 
                                                        <?php echo $is_sel; ?>>
                                                    <?php echo htmlspecialchars($c['name_en']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6" id="subcategoryWrapper">
                                        <label class="form-label fw-medium">Subcategory</label>
                                        <select name="subcategory_id" id="subcategory_id" class="form-select rounded-3 border-0 bg-light py-2">
                                            <option value="">-- Select Subcategory --</option>
                                        </select>
                                        <div class="form-text small" id="subcategoryHelp">Birds, Animals, Parrots, etc.</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ============================================================ -->
                        <!-- FORM SECTION A: BIRDS & ANIMALS (Dynamic Container)         -->
                        <!-- ============================================================ -->
                        <div id="section_birds_animals" class="product-type-section">
                            <!-- Basic Information -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-info-circle text-success me-2"></i>1. Basic Information
                                    </h5>
                                    <p class="text-muted small mb-0">Title and description for your Bird or Animal listing.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Product / Listing Name <span class="text-danger">*</span></label>
                                        <input type="text" name="title_en_animals" id="title_en_animals" class="form-control rounded-3 border-0 bg-light py-2"
                                               placeholder="e.g. African Grey Parrot – Hand Tame, Healthy Pair"
                                               value="<?php echo htmlspecialchars($old['title_en_animals'] ?? $old['title_en'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                                        <textarea name="description_en_animals" id="description_en_animals" class="form-control rounded-3 border-0 bg-light py-2" rows="5"
                                                  placeholder="Provide complete details about the bird/animal, diet, health condition, temperament, history, and what is included..."><?php echo htmlspecialchars($old['description_en_animals'] ?? $old['description_en'] ?? ''); ?></textarea>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-medium">Listing Channel <span class="text-danger">*</span></label>
                                            <select name="listing_type" class="form-select rounded-3 border-0 bg-light py-2">
                                                <option value="store"  <?php echo (($old['listing_type'] ?? 'store') === 'store')  ? 'selected' : ''; ?>>Store Listing</option>
                                                <option value="market" <?php echo (($old['listing_type'] ?? '') === 'market') ? 'selected' : ''; ?>>Bird Market (Escrow Direct)</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-medium">Price Negotiable</label>
                                            <div class="pt-2">
                                                <div class="form-check form-check-inline">
                                                    <input class="form-check-input" type="checkbox" name="is_negotiable" id="is_negotiable" value="1"
                                                           <?php echo isset($old['is_negotiable']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="is_negotiable">Price is open for negotiation</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing & Stock -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-tag text-success me-2"></i>2. Pricing & Stock
                                    </h5>
                                    <p class="text-muted small mb-0">Specify item cost and current inventory.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Price (PKR) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="price" id="price_animals" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="1" placeholder="e.g. 25000"
                                                       value="<?php echo htmlspecialchars($old['price'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Discount Price (PKR)</label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="discount_price" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="Optional discount"
                                                       value="<?php echo htmlspecialchars($old['discount_price'] ?? ''); ?>">
                                            </div>
                                            <div class="form-text small">Leave blank if no discount.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Stock Quantity <span class="text-danger">*</span></label>
                                            <input type="number" name="stock_quantity" id="stock_quantity_animals" class="form-control rounded-3 border-0 bg-light py-2"
                                                   min="0" placeholder="1"
                                                   value="<?php echo htmlspecialchars($old['stock_quantity'] ?? '1'); ?>">
                                            <div class="form-text small">Number of available animals/pairs.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Animal / Bird Details -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-feather text-success me-2"></i>3. Animal / Bird Details
                                    </h5>
                                    <p class="text-muted small mb-0">Biological, health, and physical characteristics.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Breed / Species</label>
                                            <input type="text" name="breed" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. Ringneck, Cockatoo, Persian Cat"
                                                   value="<?php echo htmlspecialchars($old['breed'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Age</label>
                                            <input type="text" name="age" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. 6 Months, 1.5 Years, Adult"
                                                   value="<?php echo htmlspecialchars($old['age'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Gender</label>
                                            <select name="gender" class="form-select rounded-3 border-0 bg-light py-2">
                                                <?php 
                                                    $selected_gender = strtolower($old['gender'] ?? 'unknown');
                                                    $genders = [
                                                        'unknown' => 'Unknown (Default)',
                                                        'male'    => 'Male',
                                                        'female'  => 'Female',
                                                        'pair'    => 'Pair'
                                                    ];
                                                    foreach ($genders as $val => $lbl):
                                                ?>
                                                    <option value="<?php echo $val; ?>" <?php echo ($selected_gender === $val) ? 'selected' : ''; ?>>
                                                        <?php echo $lbl; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Color</label>
                                            <input type="text" name="color" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. Green, Blue, White, Multi-color"
                                                   value="<?php echo htmlspecialchars($old['color'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Weight</label>
                                            <input type="text" name="weight" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. 450 grams, 2.5 kg"
                                                   value="<?php echo htmlspecialchars($old['weight'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">City / Location</label>
                                            <input type="text" name="city" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. Lahore, Karachi, Rawalpindi"
                                                   value="<?php echo htmlspecialchars($old['city'] ?? $user['city'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <hr class="my-4">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-check form-switch p-2 bg-light rounded-3 d-flex align-items-center">
                                                <input class="form-check-input ms-0 me-3 fs-5" type="checkbox" role="switch"
                                                       name="vaccination_status" id="vaccination_status" value="1"
                                                       <?php echo isset($old['vaccination_status']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-medium cursor-pointer" for="vaccination_status">
                                                    <i class="bi bi-shield-check text-success me-1"></i> Vaccinated
                                                    <div class="text-muted small fw-normal">Checked if up-to-date with essential vaccines</div>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-check form-switch p-2 bg-light rounded-3 d-flex align-items-center">
                                                <input class="form-check-input ms-0 me-3 fs-5" type="checkbox" role="switch"
                                                       name="health_certificate" id="health_certificate" value="1"
                                                       <?php echo isset($old['health_certificate']) ? 'checked' : ''; ?>>
                                                <label class="form-check-label fw-medium cursor-pointer" for="health_certificate">
                                                    <i class="bi bi-file-medical text-info me-1"></i> Health Certificate Available
                                                    <div class="text-muted small fw-normal">Certified healthy by a licensed veterinarian</div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ============================================================ -->
                        <!-- FORM SECTION B: SEEDS & ANIMAL FOOD (Dynamic Container)      -->
                        <!-- ============================================================ -->
                        <div id="section_seeds_food" class="product-type-section" style="display:none;">
                            <!-- Basic Information -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-egg text-success me-2"></i>1. Basic Information
                                    </h5>
                                    <p class="text-muted small mb-0">Title and description for Seeds & Animal Food.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Seed / Food Name <span class="text-danger">*</span></label>
                                        <input type="text" name="title_en_seeds" id="title_en_seeds" class="form-control rounded-3 border-0 bg-light py-2"
                                               placeholder="e.g. Premium Millet & Sunflower Seed Mix for Cockatiels"
                                               value="<?php echo htmlspecialchars($old['title_en_seeds'] ?? ''); ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                                        <textarea name="description_en_seeds" id="description_en_seeds" class="form-control rounded-3 border-0 bg-light py-2" rows="5"
                                                  placeholder="Provide details about ingredients, freshness, storage guidelines, nutritional value, and packaging..."><?php echo htmlspecialchars($old['description_en_seeds'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <!-- Pricing & Stock -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-tag text-success me-2"></i>2. Pricing & Stock
                                    </h5>
                                    <p class="text-muted small mb-0">Set price per KG, wholesale discounts, quality grade, and stock mode.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Price per KG (PKR) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="price_per_kg" id="price_per_kg" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="1" placeholder="e.g. 350"
                                                       value="<?php echo htmlspecialchars($old['price_per_kg'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Discount Price for 25 KG (PKR)</label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="discount_price_25kg" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="e.g. 8000"
                                                       value="<?php echo htmlspecialchars($old['discount_price_25kg'] ?? ''); ?>">
                                            </div>
                                            <div class="form-text small">Total price when buying 25 KG.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Discount Price for 50 KG (PKR)</label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="discount_price_50kg" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="e.g. 15500"
                                                       value="<?php echo htmlspecialchars($old['discount_price_50kg'] ?? ''); ?>">
                                            </div>
                                            <div class="form-text small">Total price when buying 50 KG bag.</div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Quality Grade <span class="text-danger">*</span></label>
                                            <select name="quality_seeds" class="form-select rounded-3 border-0 bg-light py-2">
                                                <option value="">-- Select Grade --</option>
                                                <?php 
                                                    $sel_q = $old['quality_seeds'] ?? $old['quality'] ?? 'A';
                                                    foreach (['A+' => 'A+ (Super Premium / Export)', 'A' => 'A (High Quality)', 'B' => 'B (Standard Cleaned)', 'C' => 'C (Commercial / Feed Grade)'] as $k => $v):
                                                ?>
                                                    <option value="<?php echo $k; ?>" <?php echo ($sel_q === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Stock Mode <span class="text-danger">*</span></label>
                                            <?php $stock_mode_s = $old['stock_mode_seeds'] ?? 'numeric'; ?>
                                            <select name="stock_mode_seeds" id="stock_mode_seeds" class="form-select rounded-3 border-0 bg-light py-2" onchange="toggleStockModeSeeds(this.value)">
                                                <option value="numeric" <?php echo ($stock_mode_s === 'numeric') ? 'selected' : ''; ?>>Numeric KG Quantity</option>
                                                <option value="as_required" <?php echo ($stock_mode_s === 'as_required') ? 'selected' : ''; ?>>As Required / As Per Demand</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4" id="stock_kg_wrapper">
                                            <label class="form-label fw-medium">Stock in KG <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" name="stock_kg" id="stock_kg" class="form-control rounded-start-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="e.g. 500"
                                                       value="<?php echo htmlspecialchars($old['stock_kg'] ?? '50'); ?>">
                                                <span class="input-group-text border-0 bg-light text-muted">KG</span>
                                            </div>
                                            <div class="form-text small">Available inventory weight in KG.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ============================================================ -->
                        <!-- FORM SECTION C: CAGES & HOUSES (Dynamic Container)          -->
                        <!-- ============================================================ -->
                        <div id="section_cages_houses" class="product-type-section" style="display:none;">
                            <!-- Basic Information -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-box text-success me-2"></i>1. Basic Information
                                    </h5>
                                    <p class="text-muted small mb-0">Specifications, purpose, and dimensions for Cages & Houses.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-medium">Cage / House Name <span class="text-danger">*</span></label>
                                            <input type="text" name="title_en_cages" id="title_en_cages" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. Heavy Duty Foldable 24x18 Breeding Cage"
                                                   value="<?php echo htmlspecialchars($old['title_en_cages'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-medium">For What Animal / Bird <span class="text-danger">*</span></label>
                                            <input type="text" name="animal_purpose" id="animal_purpose" class="form-control rounded-3 border-0 bg-light py-2"
                                                   placeholder="e.g. Parrots, Budgies, Lovebirds, Pigeons, Cats"
                                                   value="<?php echo htmlspecialchars($old['animal_purpose'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-medium">Description <span class="text-danger">*</span></label>
                                        <textarea name="description_en_cages" id="description_en_cages" class="form-control rounded-3 border-0 bg-light py-2" rows="5"
                                                  placeholder="Provide details about wire gauge, coating, door locks, tray material, folding mechanism, and dividers..."><?php echo htmlspecialchars($old['description_en_cages'] ?? ''); ?></textarea>
                                    </div>

                                    <!-- Dimensions (Inches) -->
                                    <label class="form-label fw-medium mb-1">Dimensions (Unit: Inches)</label>
                                    <div class="row g-2 p-3 bg-light rounded-3 mb-2">
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small text-muted mb-1">Length</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.1" min="0" name="length" class="form-control rounded-start-2 border-0 bg-white" placeholder="24"
                                                       value="<?php echo htmlspecialchars($old['length'] ?? ''); ?>">
                                                <span class="input-group-text border-0 bg-white text-muted">in</span>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small text-muted mb-1">Width</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.1" min="0" name="width" class="form-control rounded-start-2 border-0 bg-white" placeholder="18"
                                                       value="<?php echo htmlspecialchars($old['width'] ?? ''); ?>">
                                                <span class="input-group-text border-0 bg-white text-muted">in</span>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small text-muted mb-1">Depth</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.1" min="0" name="depth" class="form-control rounded-start-2 border-0 bg-white" placeholder="18"
                                                       value="<?php echo htmlspecialchars($old['depth'] ?? ''); ?>">
                                                <span class="input-group-text border-0 bg-white text-muted">in</span>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <label class="form-label small text-muted mb-1">Height</label>
                                            <div class="input-group input-group-sm">
                                                <input type="number" step="0.1" min="0" name="height" class="form-control rounded-start-2 border-0 bg-white" placeholder="20"
                                                       value="<?php echo htmlspecialchars($old['height'] ?? ''); ?>">
                                                <span class="input-group-text border-0 bg-white text-muted">in</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-text small">All dimensions are stored in inches.</div>
                                </div>
                            </div>

                            <!-- Pricing & Stock -->
                            <div class="card border-0 shadow-sm rounded-4 mb-4">
                                <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                    <h5 class="fw-bold mb-0">
                                        <i class="bi bi-tag text-success me-2"></i>2. Pricing & Stock
                                    </h5>
                                    <p class="text-muted small mb-0">Single piece pricing, bulk order discounts, and pieces inventory.</p>
                                </div>
                                <div class="card-body px-4 pb-4">
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Price per Piece (PKR) <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="price_per_piece" id="price_per_piece" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="1" placeholder="e.g. 2800"
                                                       value="<?php echo htmlspecialchars($old['price_per_piece'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Discount for 12 Pieces (PKR)</label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="discount_price_12pcs" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="e.g. 31200"
                                                       value="<?php echo htmlspecialchars($old['discount_price_12pcs'] ?? ''); ?>">
                                            </div>
                                            <div class="form-text small">Total price for pack of 12.</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Discount for 24 Pieces (PKR)</label>
                                            <div class="input-group">
                                                <span class="input-group-text border-0 bg-light text-muted">PKR</span>
                                                <input type="number" name="discount_price_24pcs" class="form-control rounded-end-3 border-0 bg-light py-2"
                                                       step="0.01" min="0" placeholder="e.g. 58000"
                                                       value="<?php echo htmlspecialchars($old['discount_price_24pcs'] ?? ''); ?>">
                                            </div>
                                            <div class="form-text small">Total price for pack of 24.</div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Quality Grade <span class="text-danger">*</span></label>
                                            <select name="quality_cages" class="form-select rounded-3 border-0 bg-light py-2">
                                                <option value="">-- Select Grade --</option>
                                                <?php 
                                                    $sel_qc = $old['quality_cages'] ?? $old['quality'] ?? 'A';
                                                    foreach (['A+' => 'A+ (Galvanized / Powder Coated)', 'A' => 'A (High Quality Heavy Wire)', 'B' => 'B (Standard Wire)', 'C' => 'C (Lightweight Commercial)'] as $k => $v):
                                                ?>
                                                    <option value="<?php echo $k; ?>" <?php echo ($sel_qc === $k) ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-4">
                                            <label class="form-label fw-medium">Stock Mode <span class="text-danger">*</span></label>
                                            <?php $stock_mode_c = $old['stock_mode_cages'] ?? 'numeric'; ?>
                                            <select name="stock_mode_cages" id="stock_mode_cages" class="form-select rounded-3 border-0 bg-light py-2" onchange="toggleStockModeCages(this.value)">
                                                <option value="numeric" <?php echo ($stock_mode_c === 'numeric') ? 'selected' : ''; ?>>Numeric Pieces</option>
                                                <option value="as_required" <?php echo ($stock_mode_c === 'as_required') ? 'selected' : ''; ?>>As Required / As Per Demand</option>
                                            </select>
                                        </div>

                                        <div class="col-md-4" id="stock_pieces_wrapper">
                                            <label class="form-label fw-medium">Stock in Pieces <span class="text-danger">*</span></label>
                                            <div class="input-group">
                                                <input type="number" name="stock_pieces" id="stock_pieces" class="form-control rounded-start-3 border-0 bg-light py-2"
                                                       min="0" placeholder="e.g. 50"
                                                       value="<?php echo htmlspecialchars($old['stock_pieces'] ?? '10'); ?>">
                                                <span class="input-group-text border-0 bg-light text-muted">Pcs</span>
                                            </div>
                                            <div class="form-text small">Quantity of ready pieces in stock.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- ── Right Column (Sidebar Controls) ─────────────── -->
                    <div class="col-xl-4">

                        <!-- Status & Review Card -->
                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-gear text-success me-2"></i>Status & Review
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <div class="mb-3">
                                    <label class="form-label fw-medium">Listing Status</label>
                                    <div>
                                        <span class="badge bg-warning text-dark px-3 py-2 fs-6 rounded-pill">
                                            <i class="bi bi-hourglass-split me-1"></i> Pending Admin Approval
                                        </span>
                                    </div>
                                    <div class="form-text mt-2 small">New listings are verified by administrators before going live.</div>
                                </div>

                                <div class="form-check form-switch mt-3">
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
                            <div class="card-header bg-white border-0 pt-4 px-4 pb-2">
                                <h5 class="fw-bold mb-0">
                                    <i class="bi bi-images text-success me-2"></i>Product Images <span class="text-danger">*</span>
                                </h5>
                            </div>
                            <div class="card-body px-4 pb-4">
                                <label class="form-label fw-medium small text-muted">
                                    High-resolution photos increase buyer interest (Max 3 MB each).
                                </label>

                                <!-- Drop Zone -->
                                <div class="border-2 border-dashed rounded-3 p-4 text-center bg-light mb-3"
                                     id="dropZone"
                                     style="border: 2px dashed #b8d5c4; cursor:pointer;"
                                     onclick="document.getElementById('images').click()">
                                    <i class="bi bi-cloud-arrow-up fs-1 text-success d-block mb-2"></i>
                                    <p class="mb-1 fw-bold text-dark small">Click or drag & drop images here</p>
                                    <p class="mb-0 text-muted extra-small" style="font-size:0.75rem;">
                                        JPG, PNG, WEBP, GIF — The 1st image will be set as primary.
                                    </p>
                                </div>

                                <input type="file" id="images" name="images[]"
                                       multiple accept="image/*"
                                       class="d-none" onchange="previewImages(event)">

                                <!-- Image Previews -->
                                <div id="imagePreview" class="d-flex flex-wrap gap-2 mt-2"></div>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-warning btn-lg fw-bold rounded-pill shadow-sm py-3">
                                <i class="bi bi-cloud-upload me-2"></i> Publish Listing
                            </button>
                            <a href="products.php" class="btn btn-light rounded-pill py-2">
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
// Image Preview Handler
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
                     class="rounded-3 object-fit-cover border shadow-sm"
                     style="width:80px;height:80px;">
                ${idx === 0 ? '<span class="position-absolute bottom-0 start-0 badge bg-success w-100 rounded-0 rounded-bottom" style="font-size:0.65rem;">Primary</span>' : ''}
            `;
            preview.appendChild(wrapper);
        };
        reader.readAsDataURL(file);
    });
}

// Drag & Drop
const dropZone = document.getElementById('dropZone');
if (dropZone) {
    dropZone.addEventListener('dragover', e => { 
        e.preventDefault(); 
        dropZone.style.borderColor = '#198754';
        dropZone.style.backgroundColor = '#e8f5e9';
    });
    dropZone.addEventListener('dragleave', () => {
        dropZone.style.borderColor = '#b8d5c4';
        dropZone.style.backgroundColor = '#f8f9fa';
    });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.style.borderColor = '#b8d5c4';
        dropZone.style.backgroundColor = '#f8f9fa';
        const input = document.getElementById('images');
        input.files = e.dataTransfer.files;
        previewImages({ target: input });
    });
}

// Stock mode toggles
function toggleStockModeSeeds(mode) {
    const w = document.getElementById('stock_kg_wrapper');
    if (!w) return;
    if (mode === 'as_required') {
        w.style.opacity = '0.5';
        document.getElementById('stock_kg').disabled = true;
    } else {
        w.style.opacity = '1';
        document.getElementById('stock_kg').disabled = false;
    }
}

function toggleStockModeCages(mode) {
    const w = document.getElementById('stock_pieces_wrapper');
    if (!w) return;
    if (mode === 'as_required') {
        w.style.opacity = '0.5';
        document.getElementById('stock_pieces').disabled = true;
    } else {
        w.style.opacity = '1';
        document.getElementById('stock_pieces').disabled = false;
    }
}

// Subcategory AJAX Loader
function loadSubcategories(categoryId, selectedSubId) {
    const sel = document.getElementById('subcategory_id');
    const wrapper = document.getElementById('subcategoryWrapper');
    if (!sel) return;
    sel.innerHTML = '<option value="">Loading subcategories...</option>';
    if (!categoryId) { 
        sel.innerHTML = '<option value="">-- Select Subcategory --</option>'; 
        return; 
    }

    fetch('ajax/get_subcategories.php?category_id=' + categoryId)
        .then(r => r.json())
        .then(data => {
            sel.innerHTML = '<option value="">-- Select Subcategory --</option>';
            if (data && data.length > 0) {
                if (wrapper) wrapper.style.display = 'block';
                data.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name_en;
                    if (selectedSubId && s.id == selectedSubId) {
                        opt.selected = true;
                    }
                    sel.appendChild(opt);
                });
            } else {
                // If category has no subcategories (e.g. Cages & Houses or Seeds)
                sel.innerHTML = '<option value="">-- No Subcategory Required --</option>';
            }
        })
        .catch(() => {
            sel.innerHTML = '<option value="">-- Select Subcategory --</option>';
        });
}

// Dynamic Section Switcher
function switchProductTypeUI(productType) {
    const secAnimals = document.getElementById('section_birds_animals');
    const secSeeds   = document.getElementById('section_seeds_food');
    const secCages   = document.getElementById('section_cages_houses');

    if (secAnimals) secAnimals.style.display = 'none';
    if (secSeeds)   secSeeds.style.display   = 'none';
    if (secCages)   secCages.style.display   = 'none';

    if (productType === 'seeds_food') {
        if (secSeeds) secSeeds.style.display = 'block';
    } else if (productType === 'cages_houses') {
        if (secCages) secCages.style.display = 'block';
    } else {
        if (secAnimals) secAnimals.style.display = 'block';
    }
}

function handleCategorySelection(selectElem) {
    const opt = selectElem.options[selectElem.selectedIndex];
    if (!opt) return;
    const catId = opt.value;
    const pType = opt.getAttribute('data-type') || 'birds_animals';

    switchProductTypeUI(pType);
    loadSubcategories(catId, null);
}

// Initial mount setup
document.addEventListener('DOMContentLoaded', () => {
    const catSelect = document.getElementById('category_id');
    const prevSubId = <?php echo json_encode($old['subcategory_id'] ?? null); ?>;
    
    if (catSelect && catSelect.selectedIndex >= 0) {
        const opt = catSelect.options[catSelect.selectedIndex];
        if (opt && opt.value) {
            const pType = opt.getAttribute('data-type') || 'birds_animals';
            switchProductTypeUI(pType);
            loadSubcategories(opt.value, prevSubId);
        }
    }

    const smSeeds = document.getElementById('stock_mode_seeds');
    if (smSeeds) toggleStockModeSeeds(smSeeds.value);

    const smCages = document.getElementById('stock_mode_cages');
    if (smCages) toggleStockModeCages(smCages.value);
});
</script>

<?php include '../includes/footer.php'; ?>
