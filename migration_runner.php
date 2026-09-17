<?php
require_once 'includes/config.php';

$sql = file_get_contents('update_phase3_3_admin.sql');
if ($conn->multi_query($sql)) {
    do {
        if ($res = $conn->store_result()) {
            $res->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "SQL Migration executed successfully.\n";
} else {
    echo "SQL Migration Error: " . $conn->error . "\n";
}

// Seed default admin account
$admin_email = 'admin@petmarket.com';
$admin_pass  = password_hash('admin123', PASSWORD_DEFAULT);

$chk = $conn->prepare("SELECT id FROM users WHERE email = ?");
$chk->bind_param("s", $admin_email);
$chk->execute();
$res = $chk->get_result();

if ($res->num_rows === 0) {
    $ins = $conn->prepare("INSERT INTO users (first_name, last_name, email, password, role, admin_role, force_password_change, status) VALUES ('Super', 'Admin', ?, ?, 'admin', 'super_admin', 1, 'active')");
    $ins->bind_param("ss", $admin_email, $admin_pass);
    if ($ins->execute()) {
        echo "Default admin user (admin@petmarket.com) created with force_password_change = 1.\n";
    } else {
        echo "Error creating admin: " . $conn->error . "\n";
    }
} else {
    $upd = $conn->prepare("UPDATE users SET role = 'admin', admin_role = 'super_admin' WHERE email = ?");
    $upd->bind_param("s", $admin_email);
    $upd->execute();
    echo "Admin user role verified.\n";
}
