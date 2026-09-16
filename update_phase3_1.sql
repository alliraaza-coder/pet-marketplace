-- ============================================================
-- Phase 3.1 Database Update
-- Pet Marketplace - Seller Product Management
-- Run this ONLY if you haven't already. Safe to run once.
-- ============================================================

-- Add discount_price column to products if it doesn't exist
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `discount_price` decimal(10,2) DEFAULT NULL AFTER `price`,
    ADD COLUMN IF NOT EXISTS `weight` varchar(50) DEFAULT NULL AFTER `health_certificate`,
    ADD COLUMN IF NOT EXISTS `color` varchar(100) DEFAULT NULL AFTER `weight`;

-- ============================================================
-- NOTE: All other tables (users, categories, subcategories,
-- products, product_images, orders, order_items, reviews,
-- wishlists) already exist. Do NOT run the main database.sql
-- again. Only run this file.
-- ============================================================
