<?php
$content = file_get_contents('C:\xampp\htdocs\pet_marketplace\admin\settings.php');

// Remove header include from top
$content = str_replace("include __DIR__ . '/partials/header.php';", "", $content);

// We need to inject require_once 'config' and 'auth' at top to make POST logic work before HTML
$top_inject = <<<PHP
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_role('admin');
PHP;

$content = str_replace('$page_title = "Global Settings";', $top_inject . "\n" . '$page_title = "Global Settings";', $content);

// Now find where to put the header include. It should be right before the HTML starts.
// The HTML starts with `<div class="container-fluid`
$content = str_replace('<div class="container-fluid', "include __DIR__ . '/partials/header.php';\n?>\n<div class=\"container-fluid", $content);

file_put_contents('C:\xampp\htdocs\pet_marketplace\admin\settings.php', $content);
