<?php
require_once 'includes/config.php';

echo "--- Categories ---\n";
$res = $conn->query("SELECT * FROM categories");
while($row = $res->fetch_assoc()) {
    print_r($row);
}

echo "--- Users ---\n";
$res = $conn->query("SELECT * FROM users");
while($row = $res->fetch_assoc()) {
    unset($row['password']); // don't output password
    print_r($row);
}

echo "--- Products ---\n";
$res = $conn->query("SELECT * FROM products");
while($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
