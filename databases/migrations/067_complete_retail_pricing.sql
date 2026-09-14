-- Complete retail and retail-package pricing for upgraded installations.

ALTER TABLE products ADD COLUMN units_per_pack DECIMAL(12,2) NOT NULL DEFAULT 1 AFTER unit;
ALTER TABLE products ADD COLUMN pack_unit VARCHAR(20) NULL AFTER units_per_pack;
ALTER TABLE products ADD COLUMN pack_price DECIMAL(12,2) NULL AFTER pack_unit;
ALTER TABLE products ADD COLUMN retail_pack_price DECIMAL(12,2) NULL AFTER pack_price;
ALTER TABLE products ADD COLUMN package_buying_price DECIMAL(12,2) NULL AFTER retail_pack_price;

UPDATE products
   SET retail_price = selling_price,
       wholesale_price = selling_price
 WHERE retail_price = 0 AND selling_price > 0;

ALTER TABLE sale_items MODIFY COLUMN price_type ENUM('retail','retail_pack','wholesale') NOT NULL DEFAULT 'retail';
ALTER TABLE order_items ADD COLUMN price_type ENUM('retail','retail_pack','wholesale') NOT NULL DEFAULT 'retail' AFTER unit_price;
ALTER TABLE order_items MODIFY COLUMN price_type ENUM('retail','retail_pack','wholesale') NOT NULL DEFAULT 'retail';
ALTER TABLE held_order_items ADD COLUMN price_type ENUM('retail','retail_pack','wholesale') NOT NULL DEFAULT 'retail' AFTER unit_price;
ALTER TABLE held_order_items MODIFY COLUMN price_type ENUM('retail','retail_pack','wholesale') NOT NULL DEFAULT 'retail';
