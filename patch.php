<?php
$file = 'C:/xampp/htdocs/pet_marketplace/seller/order_details.php';
$content = file_get_contents($file);

$target_1 = "    if (in_array(\$order['order_status'], ['cancelled', 'completed', 'delivered'])) {";
$replace_1 = "    if (in_array(\$order['order_status'], ['cancelled', 'completed', 'delivered']) && \$_POST['workflow_action'] !== 'confirm_payout') {";

$content = str_replace($target_1, $replace_1, $content);

$target_2 = "    \$action = sanitize_input(\$_POST['workflow_action']);\n    \$new_status = '';\n    \n    switch (\$action) {";
$replace_2 = "    \$action = sanitize_input(\$_POST['workflow_action']);\n    if (\$action === 'confirm_payout') {\n        if (\$order['payment_status'] !== 'payout_submitted') {\n            \$_SESSION['error'] = \"No payout pending confirmation.\";\n        } else {\n            \$now = date('Y-m-d H:i:s');\n            \$conn->begin_transaction();\n            try {\n                \$upd = \$conn->prepare(\"UPDATE orders SET payment_status = 'released', order_status = 'completed', payment_released_at = ?, completed_at = ? WHERE id = ?\");\n                \$upd->bind_param(\"ssi\", \$now, \$now, \$order_id);\n                \$upd->execute();\n                \$tx = \$conn->prepare(\"INSERT INTO transactions (order_id, user_id, seller_id, transaction_type, amount, payment_method, status, reference_no, created_at) VALUES (?, ?, ?, 'release', ?, ?, 'released', ?, ?)\");\n                \$tx->bind_param(\"iiidsss\", \$order_id, \$order['user_id'], \$seller_id, \$seller_subtotal, \$order['payment_method'], \$order['order_number'], \$now);\n                \$tx->execute();\n                if (function_exists('log_order_audit')) log_order_audit(\$conn, \$order_id, \"Seller confirmed payout\", \"Escrow release completed.\", \$seller_id);\n                \$conn->commit();\n                \$_SESSION['success'] = \"Payout confirmed. Order is fully completed.\";\n                \$order['payment_status'] = 'released';\n                \$order['order_status'] = 'completed';\n            } catch (Exception \$e) {\n                \$conn->rollback();\n                \$_SESSION['error'] = \"Failed to confirm payout: \" . \$e->getMessage();\n            }\n        }\n    }\n    \$new_status = '';\n    \n    switch (\$action) {";

$content = str_replace($target_2, $replace_2, $content);

file_put_contents($file, $content);
echo "Done order_details.php\n";
