-- Phase 3.3 Escrow Payment Workflow Update

-- 1. Modify Enums for orders table
ALTER TABLE `orders` 
MODIFY COLUMN `payment_status` ENUM('pending','completed','failed','refunded','held','released') NOT NULL DEFAULT 'pending',
MODIFY COLUMN `order_status` ENUM('pending','processing','shipped','delivered','cancelled','accepted','preparing','out_for_delivery','completed') NOT NULL DEFAULT 'pending';

-- 2. Add new columns for Escrow & Dispute management
ALTER TABLE `orders`
ADD COLUMN `seller_delivered` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN `buyer_received` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN `payment_released_at` TIMESTAMP NULL DEFAULT NULL,
ADD COLUMN `released_by` INT(11) DEFAULT NULL,
ADD COLUMN `dispute_status` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN `dispute_reason` TEXT DEFAULT NULL,
ADD COLUMN `admin_verified` TINYINT(1) NOT NULL DEFAULT 0,
ADD COLUMN `completed_at` TIMESTAMP NULL DEFAULT NULL,
ADD CONSTRAINT `fk_order_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- 3. Create Audit Logs table
CREATE TABLE `order_audits` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `user_id` INT(11) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_audit_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
