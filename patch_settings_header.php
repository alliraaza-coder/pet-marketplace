<?php
$content = file_get_contents('C:\xampp\htdocs\pet_marketplace\admin\settings.php');
// Make sure header.php is only included once, and right before the first HTML tag (<div class="container-fluid)
$content = preg_replace("/include __DIR__ \. '\/partials\/header\.php';\s*\?>/", "", $content); // remove any existing
$content = preg_replace("/<\?php\s*include __DIR__ \. '\/partials\/header\.php';/", "", $content); // remove any existing

// Add it right before <div class="container-fluid
$content = str_replace('<div class="container-fluid', "<?php include __DIR__ . '/partials/header.php'; ?>\n<div class=\"container-fluid", $content);

file_put_contents('C:\xampp\htdocs\pet_marketplace\admin\settings.php', $content);
echo "Done fixing settings HTML include.";
