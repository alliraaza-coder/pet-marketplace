<?php
$content = file_get_contents('C:\xampp\htdocs\pet_marketplace\product_details.php');

$content = str_replace(
    '<?php if ((int)$product[\'stock_quantity\'] > 0): ?>',
    '<?php if ((int)$product[\'stock_quantity\'] > 0 && $product[\'status\'] !== \'sold\'): ?>',
    $content
);

$content = str_replace(
    '<?php else: ?>
        <div class="alert alert-warning',
    '<?php else: ?>
        <button disabled class="btn btn-secondary btn-lg rounded-pill fw-bold w-100 shadow-sm mb-3"><i class="bi bi-x-circle me-2"></i>Sold Out</button>
        <div class="alert alert-warning',
    $content
);

file_put_contents('C:\xampp\htdocs\pet_marketplace\product_details.php', $content);
echo "Done patching product_details.\n";
