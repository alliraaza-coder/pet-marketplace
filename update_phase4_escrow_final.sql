-- Phase 4: Final Escrow Model Update

-- 1. Migrate old Payment Methods
UPDATE orders SET payment_method = 'bank_transfer' WHERE payment_method NOT IN ('bank_transfer', 'jazzcash', 'easypaisa');

-- 2. Modify payment_method ENUM
ALTER TABLE orders MODIFY COLUMN payment_method ENUM('bank_transfer', 'jazzcash', 'easypaisa') NOT NULL DEFAULT 'bank_transfer';

-- 3. Migrate old Payment Statuses if needed (e.g., 'completed' to 'released', 'failed' to 'refunded')
UPDATE orders SET payment_status = 'released' WHERE payment_status = 'completed';
UPDATE orders SET payment_status = 'refunded' WHERE payment_status IN ('failed', 'cancelled');

-- 4. Modify payment_status ENUM
ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'received', 'held', 'released', 'refunded') NOT NULL DEFAULT 'pending';

-- 5. Migrate old Order Statuses
UPDATE orders SET order_status = 'preparing' WHERE order_status = 'processing';
UPDATE orders SET order_status = 'out_for_delivery' WHERE order_status = 'shipped';

-- 6. Modify order_status ENUM
ALTER TABLE orders MODIFY COLUMN order_status ENUM('pending', 'accepted', 'preparing', 'ready_for_shipment', 'out_for_delivery', 'delivered', 'completed', 'cancelled') NOT NULL DEFAULT 'pending';
