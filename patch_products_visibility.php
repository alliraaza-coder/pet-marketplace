<?php
function patch_file($path, $is_index = false) {
    if (!file_exists($path)) return;
    $content = file_get_contents($path);

    // Patch query
    $content = str_replace(
        "p.status = 'active' AND p.is_deleted = 0 AND p.listing_type = 'store' AND p.stock_quantity > 0",
        "p.status IN ('active', 'sold') AND p.is_deleted = 0 AND p.listing_type = 'store'",
        $content
    );
    $content = str_replace(
        "p.status = 'active' AND p.is_deleted = 0 AND p.listing_type = 'store'",
        "p.status IN ('active', 'sold') AND p.is_deleted = 0 AND p.listing_type = 'store'",
        $content
    );
    // For product_details.php
    $content = str_replace(
        "p.status = 'active' AND p.listing_type = 'store'",
        "p.status IN ('active', 'sold') AND p.is_deleted = 0 AND p.listing_type = 'store'",
        $content
    );

    // Patch badges for index.php and shop.php
    if (strpos($content, '<!-- Stock Badge -->') === false && strpos($path, 'product_details') === false) {
        $badge_html = '
        <?php if ($p[\'stock_quantity\'] <= 0 || $p[\'status\'] === \'sold\'): ?>
            <div class="position-absolute top-0 start-0 m-2 z-3">
                <span class="badge bg-danger rounded-pill px-3 py-1 fw-bold text-uppercase shadow-sm">Sold Out</span>
            </div>
        <?php endif; ?>
        ';
        // Insert after <div class="position-relative">
        $content = preg_replace('/(<div class="position-relative[^>]*>)/i', "$1\n$badge_html", $content);
    }
    
    file_put_contents($path, $content);
}

patch_file('C:\xampp\htdocs\pet_marketplace\index.php', true);
patch_file('C:\xampp\htdocs\pet_marketplace\shop.php', false);
patch_file('C:\xampp\htdocs\pet_marketplace\product_details.php', false);

echo "Patched queries and badges.\n";
