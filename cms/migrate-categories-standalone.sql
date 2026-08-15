-- Migrate existing DB: categories become standalone; products gain car_model_id.
-- Run once in phpMyAdmin on an already-installed database.
-- Safe to re-run only if you check column existence first (manual).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1) Add car_model_id on products (nullable first for backfill)
ALTER TABLE products
  ADD COLUMN car_model_id INT UNSIGNED NULL AFTER category_id;

-- 2) Backfill from old category → car_model link
UPDATE products p
INNER JOIN categories c ON c.id = p.category_id
SET p.car_model_id = c.car_model_id
WHERE p.car_model_id IS NULL AND c.car_model_id IS NOT NULL;

-- 3) Drop products that cannot be mapped (no model)
DELETE FROM products WHERE car_model_id IS NULL;

-- 4) Make car_model_id required + FK
ALTER TABLE products
  MODIFY COLUMN car_model_id INT UNSIGNED NOT NULL,
  ADD KEY idx_prod_model (car_model_id),
  ADD CONSTRAINT fk_prod_model FOREIGN KEY (car_model_id) REFERENCES car_models (id) ON DELETE CASCADE;

-- 5) Detach categories from car models
ALTER TABLE categories DROP FOREIGN KEY fk_cat_model;
ALTER TABLE categories DROP INDEX uq_cat_model_slug;
ALTER TABLE categories DROP INDEX idx_cat_model;
ALTER TABLE categories DROP COLUMN car_model_id;
ALTER TABLE categories ADD UNIQUE KEY uq_category_slug (slug);

SET FOREIGN_KEY_CHECKS = 1;
