-- ============================================================
-- Pet Marketplace — Phase 3.2 Safe Database Migration
-- Run this in phpMyAdmin → pet_marketplace database
-- Safe: uses IF NOT EXISTS checks — can be run multiple times
-- ============================================================

USE `pet_marketplace`;

-- Add shipping contact columns to orders table (if not already added)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'pet_marketplace'
      AND TABLE_NAME   = 'orders'
      AND COLUMN_NAME  = 'shipping_name'
);

-- shipping_name
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `shipping_name` VARCHAR(150) NOT NULL DEFAULT '' AFTER `user_id`;

-- shipping_phone
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `shipping_phone` VARCHAR(30) NOT NULL DEFAULT '' AFTER `shipping_name`;

-- shipping_email
ALTER TABLE `orders`
    ADD COLUMN IF NOT EXISTS `shipping_email` VARCHAR(150) NOT NULL DEFAULT '' AFTER `shipping_phone`;

-- Verify the final orders table structure
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'pet_marketplace'
  AND TABLE_NAME   = 'orders'
ORDER BY ORDINAL_POSITION;
