-- Phase 3.3 Professional Admin Panel Database Migration

-- 1. Alter users table if columns do not exist
SET @dbname = DATABASE();

SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'users' AND COLUMN_NAME = 'admin_role');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `users` ADD COLUMN `admin_role` ENUM("super_admin", "admin", "moderator", "support_staff") DEFAULT NULL, ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `is_verified` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL;', 'SELECT 1;');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Alter products table for soft delete
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'products' AND COLUMN_NAME = 'is_deleted');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `products` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL;', 'SELECT 1;');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. Alter categories table for soft delete
SET @exist := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'is_deleted');
SET @sqlstmt := IF(@exist = 0, 'ALTER TABLE `categories` ADD COLUMN `is_deleted` TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL;', 'SELECT 1;');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Create site_settings table
CREATE TABLE IF NOT EXISTS `site_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings
INSERT INTO `site_settings` (`setting_key`, `setting_value`) VALUES
('site_name', 'PetMarket'),
('support_email', 'support@petmarket.com'),
('phone', '+1 (555) 123-4567'),
('currency', '$'),
('commission_percentage', '5.00'),
('maintenance_mode', '0'),
('logo', 'logo.png'),
('favicon', 'favicon.ico')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- 5. Create transactions table
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `order_id` INT(11) NOT NULL,
  `user_id` INT(11) NOT NULL,
  `seller_id` INT(11) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `transaction_type` ENUM('payment', 'release', 'refund') NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'completed',
  `reference_no` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `user_id` (`user_id`),
  KEY `seller_id` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Create admin_activity_logs table
CREATE TABLE IF NOT EXISTS `admin_activity_logs` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `admin_id` INT(11) NOT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
