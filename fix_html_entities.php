<?php
/**
 * One-time DB cleanup — decodes HTML entities stored by old sanitize_input() bug.
 * Run this ONCE via browser: http://localhost/pet_marketplace/fix_html_entities.php
 * DELETE this file immediately after running.
 */
require_once __DIR__ . '/includes/config.php';

$log = [];

// Helper: decode a table column in-place
function fix_column($conn, $table, $column, &$log) {
    $result = $conn->query("SELECT id, `$column` FROM `$table`");
    if (!$result) { $log[] = "SKIP $table.$column — " . $conn->error; return; }
    $fixed = 0;
    while ($row = $result->fetch_assoc()) {
        $original = $row[$column];
        if ($original === null) continue;
        $decoded  = html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $original) {
            $stmt = $conn->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
            $stmt->bind_param('si', $decoded, $row['id']);
            $stmt->execute();
            $log[] = "Fixed $table.$column id={$row['id']}: \"$original\" → \"$decoded\"";
            $fixed++;
        }
    }
    if ($fixed === 0) $log[] = "OK — $table.$column — no encoding issues found.";
}

// Tables and columns to clean
fix_column($conn, 'categories', 'name_en',  $log);
fix_column($conn, 'categories', 'name_ur',  $log);
fix_column($conn, 'products',   'title_en', $log);
fix_column($conn, 'products',   'description_en', $log);
fix_column($conn, 'subcategories', 'name_en', $log);
fix_column($conn, 'subcategories', 'name_ur', $log);

// Fix site_settings (different PK — use setting_key)
$result = $conn->query("SELECT setting_key, setting_value FROM site_settings");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $original = $row['setting_value'];
        if ($original === null) continue;
        $decoded = html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decoded !== $original) {
            $stmt = $conn->prepare("UPDATE site_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param('ss', $decoded, $row['setting_key']);
            $stmt->execute();
            $log[] = "Fixed site_settings.{$row['setting_key']}: \"$original\" → \"$decoded\"";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DB Fix - HTML Entities Cleanup</title>
    <style>
        body { font-family: monospace; padding: 30px; background: #f4f4f4; }
        .card { background: #fff; border-radius: 8px; padding: 24px; max-width: 860px; margin: auto; box-shadow: 0 2px 10px rgba(0,0,0,.1); }
        h2 { color: #198754; margin-bottom: 20px; }
        .line { padding: 5px 8px; margin: 3px 0; border-radius: 4px; font-size: 0.88rem; }
        .fixed { background: #d1e7dd; color: #0f5132; }
        .ok    { background: #f8f9fa; color: #555; }
        .warning { background: #fff3cd; color: #856404; margin-top: 20px; padding: 14px; border-radius: 6px; font-size: 1rem; }
    </style>
</head>
<body>
<div class="card">
    <h2>DB HTML Entity Cleanup - Complete</h2>
    <?php foreach ($log as $line): ?>
        <div class="line <?= str_starts_with($line, 'Fixed') ? 'fixed' : 'ok' ?>">
            <?= htmlspecialchars($line) ?>
        </div>
    <?php endforeach; ?>
    <div class="warning">
        <strong>Action required:</strong> Delete this file immediately after reviewing the results above.<br>
        Path: <code><?= __FILE__ ?></code>
    </div>
</div>
</body>
</html>
