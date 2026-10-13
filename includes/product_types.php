<?php
/**
 * Product Types Service & Architecture
 * Pet Marketplace E-Commerce Platform — Phase 2
 *
 * Provides normalized, dynamic product attributes for:
 * 1. Birds / Animals (`birds_animals`)
 * 2. Seeds / Animal Food (`seeds_food`)
 * 3. Cages / Houses (`cages_houses`)
 * 4. Market Specials (Admin Only — uses any type above + is_admin_product = 1)
 */

if (!defined('PRODUCT_TYPE_BIRDS_ANIMALS')) {
    define('PRODUCT_TYPE_BIRDS_ANIMALS', 'birds_animals');
}
if (!defined('PRODUCT_TYPE_SEEDS_FOOD')) {
    define('PRODUCT_TYPE_SEEDS_FOOD', 'seeds_food');
}
if (!defined('PRODUCT_TYPE_CAGES_HOUSES')) {
    define('PRODUCT_TYPE_CAGES_HOUSES', 'cages_houses');
}

/**
 * Get human-readable labels for product types
 */
function get_product_types() {
    return [
        PRODUCT_TYPE_BIRDS_ANIMALS => [
            'key' => PRODUCT_TYPE_BIRDS_ANIMALS,
            'label' => 'Birds & Animals',
            'table' => 'product_birds_animals',
            'description' => 'Live birds and animals with breed, age, gender, vaccination info'
        ],
        PRODUCT_TYPE_SEEDS_FOOD => [
            'key' => PRODUCT_TYPE_SEEDS_FOOD,
            'label' => 'Seeds & Animal Food',
            'table' => 'product_seeds_food',
            'description' => 'Bulk or demand-based seeds and feeds with per-KG pricing and bulk discounts'
        ],
        PRODUCT_TYPE_CAGES_HOUSES => [
            'key' => PRODUCT_TYPE_CAGES_HOUSES,
            'label' => 'Cages & Houses',
            'table' => 'product_cages_houses',
            'description' => 'Cages, houses and habitats with dimensions, quality grading, and bulk discounts'
        ]
    ];
}

/**
 * Determine the default product type for a given category
 */
function get_category_product_type($conn, $category_id) {
    $category_id = (int)$category_id;
    if ($category_id <= 0) return PRODUCT_TYPE_BIRDS_ANIMALS;

    $stmt = $conn->prepare("SELECT default_product_type, slug FROM categories WHERE id = ? LIMIT 1");
    if (!$stmt) return PRODUCT_TYPE_BIRDS_ANIMALS;
    $stmt->bind_param("i", $category_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && !empty($row['default_product_type'])) {
        return $row['default_product_type'];
    }

    // Fallback based on slug if default_product_type is null
    if ($row) {
        $slug = $row['slug'] ?? '';
        if (strpos($slug, 'seed') !== false || strpos($slug, 'food') !== false) {
            return PRODUCT_TYPE_SEEDS_FOOD;
        }
        if (strpos($slug, 'cage') !== false || strpos($slug, 'house') !== false) {
            return PRODUCT_TYPE_CAGES_HOUSES;
        }
    }

    return PRODUCT_TYPE_BIRDS_ANIMALS;
}

/**
 * Fetch dynamic type-specific details for a given product
 *
 * @param mysqli $conn
 * @param int $product_id
 * @param string|null $product_type
 * @return array
 */
function get_product_dynamic_details($conn, $product_id, $product_type = null) {
    $product_id = (int)$product_id;
    if ($product_id <= 0) return [];

    // If product_type not passed, query it from products table
    if (empty($product_type)) {
        $stmt = $conn->prepare("SELECT product_type FROM products WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res && !empty($res['product_type'])) {
                $product_type = $res['product_type'];
            }
        }
    }

    $product_type = $product_type ?: PRODUCT_TYPE_BIRDS_ANIMALS;
    $details = ['product_type' => $product_type];

    switch ($product_type) {
        case PRODUCT_TYPE_SEEDS_FOOD:
            $stmt = $conn->prepare("SELECT * FROM product_seeds_food WHERE product_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $details = array_merge($details, $row);
                }
            }
            break;

        case PRODUCT_TYPE_CAGES_HOUSES:
            $stmt = $conn->prepare("SELECT * FROM product_cages_houses WHERE product_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $details = array_merge($details, $row);
                }
            }
            break;

        case PRODUCT_TYPE_BIRDS_ANIMALS:
        default:
            $stmt = $conn->prepare("SELECT * FROM product_birds_animals WHERE product_id = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("i", $product_id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $details = array_merge($details, $row);
                }
            }
            break;
    }

    return $details;
}

/**
 * Fetch a complete product record including its core columns and its dynamic type-specific details
 *
 * @param mysqli $conn
 * @param int $product_id
 * @return array|null
 */
function get_full_product($conn, $product_id) {
    $product_id = (int)$product_id;
    if ($product_id <= 0) return null;

    $stmt = $conn->prepare("
        SELECT p.*, 
               c.name_en AS category_name, c.slug AS category_slug, c.is_admin_only,
               sc.name_en AS subcategory_name, sc.slug AS subcategory_slug
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN subcategories sc ON p.subcategory_id = sc.id
        WHERE p.id = ? AND p.is_deleted = 0
        LIMIT 1
    ");
    if (!$stmt) return null;
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) return null;

    $type_details = get_product_dynamic_details($conn, $product_id, $product['product_type'] ?? null);
    $product['dynamic_details'] = $type_details;

    return $product;
}

/**
 * Save or update dynamic details for a product
 *
 * @param mysqli $conn
 * @param int $product_id
 * @param string $product_type
 * @param array $data
 * @return bool
 */
function save_product_dynamic_details($conn, $product_id, $product_type, array $data) {
    $product_id = (int)$product_id;
    if ($product_id <= 0) return false;

    // Ensure products table has correct product_type set
    $upd_type = $conn->prepare("UPDATE products SET product_type = ? WHERE id = ?");
    if ($upd_type) {
        $upd_type->bind_param("si", $product_type, $product_id);
        $upd_type->execute();
        $upd_type->close();
    }

    switch ($product_type) {
        case PRODUCT_TYPE_SEEDS_FOOD:
            $price_per_kg = (float)($data['price_per_kg'] ?? 0.00);
            $disc_25kg    = isset($data['discount_price_25kg']) && $data['discount_price_25kg'] !== '' ? (float)$data['discount_price_25kg'] : null;
            $disc_50kg    = isset($data['discount_price_50kg']) && $data['discount_price_50kg'] !== '' ? (float)$data['discount_price_50kg'] : null;
            $quality      = in_array($data['quality'] ?? '', ['A+','A','B','C']) ? $data['quality'] : 'A';
            $stock_type   = ($data['stock_type'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
            $stock_kg     = isset($data['stock_kg']) ? (float)$data['stock_kg'] : 0.00;

            $stmt = $conn->prepare("
                INSERT INTO product_seeds_food 
                  (product_id, price_per_kg, discount_price_25kg, discount_price_50kg, quality, stock_type, stock_kg)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  price_per_kg = VALUES(price_per_kg),
                  discount_price_25kg = VALUES(discount_price_25kg),
                  discount_price_50kg = VALUES(discount_price_50kg),
                  quality = VALUES(quality),
                  stock_type = VALUES(stock_type),
                  stock_kg = VALUES(stock_kg)
            ");
            if (!$stmt) return false;
            $stmt->bind_param("idddssd", $product_id, $price_per_kg, $disc_25kg, $disc_50kg, $quality, $stock_type, $stock_kg);
            $res = $stmt->execute();
            $stmt->close();
            return $res;

        case PRODUCT_TYPE_CAGES_HOUSES:
            $purpose    = !empty($data['animal_purpose']) ? trim($data['animal_purpose']) : null;
            $length     = isset($data['length']) && $data['length'] !== '' ? (float)$data['length'] : null;
            $width      = isset($data['width']) && $data['width'] !== '' ? (float)$data['width'] : null;
            $depth      = isset($data['depth']) && $data['depth'] !== '' ? (float)$data['depth'] : null;
            $height     = isset($data['height']) && $data['height'] !== '' ? (float)$data['height'] : null;
            $unit       = !empty($data['unit_dimension']) ? trim($data['unit_dimension']) : 'inches';
            $price_pc   = (float)($data['price_per_piece'] ?? 0.00);
            $disc_12pcs = isset($data['discount_price_12pcs']) && $data['discount_price_12pcs'] !== '' ? (float)$data['discount_price_12pcs'] : null;
            $disc_24pcs = isset($data['discount_price_24pcs']) && $data['discount_price_24pcs'] !== '' ? (float)$data['discount_price_24pcs'] : null;
            $quality    = in_array($data['quality'] ?? '', ['A+','A','B','C']) ? $data['quality'] : 'A';
            $stock_type = ($data['stock_type'] ?? '') === 'as_required' ? 'as_required' : 'numeric';
            $stock_pcs  = isset($data['stock_pieces']) ? (int)$data['stock_pieces'] : 0;

            $stmt = $conn->prepare("
                INSERT INTO product_cages_houses 
                  (product_id, animal_purpose, length, width, depth, height, unit_dimension, 
                   price_per_piece, discount_price_12pcs, discount_price_24pcs, quality, stock_type, stock_pieces)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  animal_purpose = VALUES(animal_purpose),
                  length = VALUES(length),
                  width = VALUES(width),
                  depth = VALUES(depth),
                  height = VALUES(height),
                  unit_dimension = VALUES(unit_dimension),
                  price_per_piece = VALUES(price_per_piece),
                  discount_price_12pcs = VALUES(discount_price_12pcs),
                  discount_price_24pcs = VALUES(discount_price_24pcs),
                  quality = VALUES(quality),
                  stock_type = VALUES(stock_type),
                  stock_pieces = VALUES(stock_pieces)
            ");
            if (!$stmt) return false;
            $stmt->bind_param("isddddsdddssi", 
                $product_id, $purpose, $length, $width, $depth, $height, $unit,
                $price_pc, $disc_12pcs, $disc_24pcs, $quality, $stock_type, $stock_pcs
            );
            $res = $stmt->execute();
            $stmt->close();
            return $res;

        case PRODUCT_TYPE_BIRDS_ANIMALS:
        default:
            $breed       = !empty($data['breed']) ? trim($data['breed']) : null;
            $age         = !empty($data['age']) ? trim($data['age']) : null;
            $gender_raw  = strtolower(trim($data['gender'] ?? 'unknown'));
            $gender      = in_array($gender_raw, ['male','female','pair','unknown']) ? $gender_raw : 'unknown';
            $color       = !empty($data['color']) ? trim($data['color']) : null;
            $weight      = !empty($data['weight']) ? trim($data['weight']) : null;
            $city        = !empty($data['city']) ? trim($data['city']) : null;
            $vaccinated  = !empty($data['vaccinated']) ? 1 : 0;
            $health_cert = !empty($data['health_certificate']) ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO product_birds_animals
                  (product_id, breed, age, gender, color, weight, city, vaccinated, health_certificate)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  breed = VALUES(breed),
                  age = VALUES(age),
                  gender = VALUES(gender),
                  color = VALUES(color),
                  weight = VALUES(weight),
                  city = VALUES(city),
                  vaccinated = VALUES(vaccinated),
                  health_certificate = VALUES(health_certificate)
            ");
            if (!$stmt) return false;
            $stmt->bind_param("issssssii", $product_id, $breed, $age, $gender, $color, $weight, $city, $vaccinated, $health_cert);
            $res = $stmt->execute();
            $stmt->close();

            // Also keep core products table columns synchronized for birds/animals backwards compatibility
            $core_gender = ucfirst($gender);
            $sync = $conn->prepare("
                UPDATE products 
                SET breed = ?, age = ?, gender = ?, color = ?, weight = ?, city = ?, 
                    vaccination_status = ?, health_certificate = ?
                WHERE id = ?
            ");
            if ($sync) {
                $sync->bind_param("ssssssiii", $breed, $age, $core_gender, $color, $weight, $city, $vaccinated, $health_cert, $product_id);
                $sync->execute();
                $sync->close();
            }

            return $res;
    }
}

/**
 * Normalize product title for duplicate comparison
 * - Trim leading/trailing whitespace
 * - Collapse repeated whitespace/spaces to single space
 * - Convert to lowercase
 *
 * @param string $title
 * @return string
 */
function normalize_product_name($title) {
    $normalized = trim((string)$title);
    $normalized = preg_replace('/\s+/u', ' ', $normalized);
    $normalized = mb_strtolower($normalized, 'UTF-8');
    return $normalized;
}

/**
 * Find an existing product for the same seller with the same normalized name
 *
 * @param mysqli $conn
 * @param int $seller_id
 * @param string $title
 * @param int|null $exclude_product_id When updating, exclude self
 * @return array|null Returns existing product row if duplicate found, or null
 */
function find_seller_duplicate_product($conn, $seller_id, $title, $exclude_product_id = null) {
    $seller_id = (int)$seller_id;
    if ($seller_id <= 0) return null;

    $normalized = normalize_product_name($title);
    if ($normalized === '') return null;

    $sql = "SELECT id, title_en, stock_quantity, status 
            FROM products 
            WHERE seller_id = ? 
              AND is_deleted = 0 
              AND (normalized_name = ? OR LOWER(TRIM(REGEXP_REPLACE(title_en, '[[:space:]]+', ' '))) = ?)";
    
    if ($exclude_product_id !== null && (int)$exclude_product_id > 0) {
        $sql .= " AND id != " . (int)$exclude_product_id;
    }
    
    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;

    $stmt->bind_param("iss", $seller_id, $normalized, $normalized);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ?: null;
}

