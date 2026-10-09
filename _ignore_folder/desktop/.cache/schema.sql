CREATE TABLE `audit_log` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `username` varchar(150)  DEFAULT NULL,
  `role` varchar(60)  DEFAULT NULL,
  `entity_type` varchar(60)  NOT NULL,
  `entity_id` int DEFAULT NULL,
  `entity_label` varchar(200)  DEFAULT NULL,
  `action` varchar(30)  NOT NULL,
  `changes` text ,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `blog_categories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(100)  NOT NULL,
  `slug` varchar(100)  NOT NULL,
  `description` text ,
  `color` varchar(20)  DEFAULT '#667eea',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `blog_faqs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `blog_id` int NOT NULL,
  `question` varchar(300)  NOT NULL,
  `answer` text  NOT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyINTEGER DEFAULT '1',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `blog_sections` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `blog_id` int NOT NULL,
  `section_type` TEXT  DEFAULT 'text_only',
  `title` varchar(255)  DEFAULT NULL,
  `content` text ,
  `media_url` varchar(500)  DEFAULT NULL,
  `media_type` TEXT  DEFAULT 'image',
  `video_id` varchar(100)  DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `blog_tag_relations` (
  `blog_id` int NOT NULL,
  `tag_id` int NOT NULL
);
CREATE TABLE `blog_tags` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(50)  NOT NULL,
  `slug` varchar(50)  NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `blogs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `title` varchar(255)  NOT NULL,
  `slug` varchar(255)  NOT NULL,
  `excerpt` text ,
  `content` longtext ,
  `featured_image` varchar(255)  DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `author_id` int NOT NULL,
  `status` TEXT  DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `is_featured` tinyINTEGER DEFAULT '0',
  `meta_title` varchar(255)  DEFAULT NULL,
  `meta_description` text ,
  `meta_keywords` varchar(255)  DEFAULT NULL,
  `published_at` DATETIME,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `book_attributes` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `type` TEXT  NOT NULL,
  `name` varchar(160)  NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `business_services` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `commission_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `duration_minutes` int NOT NULL DEFAULT '30',
  `merged_service_ids` text,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `categories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(120)  NOT NULL,
  `type` TEXT  NOT NULL DEFAULT 'product',
  `image_path` varchar(255)  DEFAULT NULL,
  `status` TEXT  NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `customers` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `company_name` varchar(160) DEFAULT NULL,
  `is_b2b` tinyINTEGER NOT NULL DEFAULT '0',
  `credit_limit` decimal(12,2) NOT NULL DEFAULT '0.00',
  `credit_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loyalty_points` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loyalty_tier` TEXT NOT NULL DEFAULT 'standard',
  `notes` text,
  `status` TEXT NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `enquiries` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(100)  NOT NULL,
  `email` varchar(100)  NOT NULL,
  `phone` varchar(20)  NOT NULL,
  `service` varchar(100)  DEFAULT NULL,
  `message` text ,
  `status` TEXT  DEFAULT 'new',
  `priority` TEXT  DEFAULT 'medium',
  `notes` text ,
  `contacted_at` DATETIME,
  `closed_at` DATETIME,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `enquiry_replies` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `enquiry_id` int NOT NULL,
  `admin_id` int NOT NULL,
  `reply` text  NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `gallery` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `title` varchar(255)  NOT NULL,
  `description` text ,
  `media_type` TEXT  DEFAULT 'image',
  `file_path` varchar(500)  NOT NULL,
  `thumbnail_path` varchar(500)  DEFAULT NULL,
  `video_url` varchar(500)  DEFAULT NULL,
  `video_embed_code` text ,
  `category` varchar(100)  DEFAULT NULL,
  `tags` varchar(255)  DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `is_featured` tinyINTEGER DEFAULT '0',
  `status` TEXT  DEFAULT 'active',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `gallery_categories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(100)  NOT NULL,
  `slug` varchar(100)  NOT NULL,
  `description` text ,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `held_order_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `held_order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160)  NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `price_type` TEXT  NOT NULL DEFAULT 'retail',
  `quantity` decimal(12,2) NOT NULL
);
CREATE TABLE `held_orders` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `customer_name` varchar(120)  NOT NULL,
  `staff_id` int NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `hero_slides` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `image_path` varchar(255)   NOT NULL,
  `caption` varchar(255)   DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_active` tinyINTEGER NOT NULL DEFAULT '1',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `login_attempts` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `email` varchar(100)  DEFAULT NULL,
  `ip_address` varchar(45)  DEFAULT NULL,
  `attempt_time` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `login_otps` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `user_id` int NOT NULL,
  `tenant_id` int DEFAULT NULL,
  `code_hash` varchar(255)  NOT NULL,
  `purpose` varchar(32)  NOT NULL DEFAULT 'login_2fa',
  `attempts` tinyint NOT NULL DEFAULT '0',
  `max_attempts` tinyint NOT NULL DEFAULT '5',
  `expires_at` DATETIME NOT NULL,
  `consumed_at` DATETIME DEFAULT NULL,
  `ip` varchar(45)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `order_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160)  NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `base_unit_price` decimal(12,2) DEFAULT NULL,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `price_type` TEXT  NOT NULL DEFAULT 'retail',
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  `added_by` int NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `order_payments` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `order_id` int NOT NULL,
  `staff_id` int DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `method` varchar(20) NOT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `provider` varchar(100) DEFAULT NULL,
  `account_name` varchar(160) DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `orders` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `table_name` varchar(120)  NOT NULL,
  `customer_phone` varchar(30)  DEFAULT NULL,
  `customer_email` varchar(255)  DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `loyalty_points_earned` decimal(12,2) NOT NULL DEFAULT '0.00',
  `loyalty_points_redeemed` decimal(12,2) NOT NULL DEFAULT '0.00',
  `channel` TEXT  NOT NULL DEFAULT 'tab',
  `sale_type` TEXT  NOT NULL DEFAULT 'retail',
  `opened_by` int NOT NULL,
  `receipt_number` varchar(32)  NOT NULL,
  `status` TEXT  NOT NULL DEFAULT 'open',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `additional_charges` decimal(12,2) NOT NULL DEFAULT '0.00',
  `additional_charges_note` varchar(255)  DEFAULT NULL,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `vat_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_due` decimal(12,2) NOT NULL DEFAULT '0.00',
  `credit_duration_days` int DEFAULT NULL,
  `credit_due_at` DATETIME DEFAULT NULL,
  `payment_method` varchar(20)  DEFAULT NULL,
  `payment_status` varchar(20)  NOT NULL DEFAULT 'credit',
  `payment_provider` varchar(100)  DEFAULT NULL,
  `payment_account_name` varchar(160)  DEFAULT NULL,
  `payment_reference` varchar(120)  DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `amount_tendered` decimal(12,2) DEFAULT NULL,
  `change_due` decimal(12,2) DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `invoice_sent_at` DATETIME DEFAULT NULL,
  `delivery_note_sent_at` DATETIME DEFAULT NULL,
  `thank_you_sent_at` DATETIME DEFAULT NULL,
  `remembrance_sent_at` DATETIME DEFAULT NULL,
  `invoice_deleted_at` DATETIME DEFAULT NULL,
  `invoice_deleted_by` int DEFAULT NULL
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `page_headers` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `page_key` varchar(60)   NOT NULL,
  `title` varchar(150)   DEFAULT NULL,
  `subtitle` varchar(255)   DEFAULT NULL,
  `image_path` varchar(255)   DEFAULT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `product_price_tiers` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `product_id` int NOT NULL,
  `min_qty` decimal(12,2) NOT NULL DEFAULT '1.00',
  `max_qty` decimal(12,2) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) DEFAULT NULL,
  `label` varchar(80) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `product_returns` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `source_type` TEXT NOT NULL,
  `source_id` int NOT NULL,
  `source_item_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `receipt_number` varchar(32) NOT NULL,
  `returned_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `used_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `restocked_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `reason` varchar(120) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `processed_by` int DEFAULT NULL,
  `migrated_at` DATETIME DEFAULT NULL,
  `migrated_by` int DEFAULT NULL,
  `financial_snapshot` TEXT DEFAULT NULL,
  `undone_at` DATETIME DEFAULT NULL,
  `undone_by` int DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `products` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `product_type` TEXT  NOT NULL DEFAULT 'product',
  `category_id` int DEFAULT NULL,
  `subcategory_id` int DEFAULT NULL,
  `grade_id` int DEFAULT NULL,
  `publisher_id` int DEFAULT NULL,
  `author_id` int DEFAULT NULL,
  `edition_id` int DEFAULT NULL,
  `brand_id` int DEFAULT NULL,
  `barcode` varchar(64)  DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `name` varchar(160)  NOT NULL,
  `description` text ,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `faulty_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(20)  NOT NULL DEFAULT 'piece',
  `units_per_pack` decimal(12,2) NOT NULL DEFAULT '1.00',
  `pack_unit` varchar(20)  DEFAULT NULL,
  `pack_price` decimal(12,2) DEFAULT NULL,
  `retail_pack_price` decimal(12,2) DEFAULT NULL,
  `package_buying_price` decimal(12,2) DEFAULT NULL,
  `size_value` decimal(10,2) DEFAULT NULL,
  `size_unit` TEXT  DEFAULT NULL,
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `wholesale_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `retail_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT NULL,
  `offer_price` decimal(12,2) DEFAULT NULL,
  `offer_starts_at` DATETIME DEFAULT NULL,
  `offer_ends_at` DATETIME DEFAULT NULL,
  `colors` TEXT DEFAULT NULL,
  `sizes` TEXT DEFAULT NULL,
  `image_path` varchar(255)  DEFAULT NULL,
  `low_stock_threshold` int NOT NULL DEFAULT '10',
  `credit_limit` decimal(12,2) DEFAULT NULL,
  `low_stock_notified_at` DATETIME DEFAULT NULL,
  `status` TEXT  NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `project_categories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `category_name` varchar(100)  NOT NULL,
  `category_slug` varchar(100)  NOT NULL,
  `category_description` text ,
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `project_gallery` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `project_id` int NOT NULL,
  `image_path` varchar(255)  NOT NULL,
  `image_title` varchar(100)  DEFAULT NULL,
  `image_description` text ,
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `project_tag_relations` (
  `project_id` int NOT NULL,
  `tag_id` int NOT NULL
);
CREATE TABLE `project_tags` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tag_name` varchar(50)  NOT NULL,
  `tag_slug` varchar(50)  NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `project_videos` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `project_id` int NOT NULL,
  `video_title` varchar(200)  DEFAULT NULL,
  `video_url` varchar(500)  NOT NULL,
  `video_embed_code` text ,
  `video_type` TEXT  DEFAULT 'youtube',
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `projects` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `category_id` int NOT NULL,
  `small_title` varchar(100)  NOT NULL,
  `major_title` varchar(200)  NOT NULL,
  `project_slug` varchar(200)  NOT NULL,
  `description` text ,
  `cover_image` varchar(255)  DEFAULT NULL,
  `status` TEXT  DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `roles` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `role_name` varchar(50)  NOT NULL,
  `scope` TEXT  NOT NULL DEFAULT 'tenant',
  `capabilities` TEXT DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `sale_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `sale_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160)  NOT NULL,
  `unit` varchar(20)  NOT NULL DEFAULT 'piece',
  `unit_price` decimal(12,2) NOT NULL,
  `price_type` TEXT  NOT NULL DEFAULT 'retail',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantity` decimal(12,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `sale_payments` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `sale_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `method` varchar(20)  NOT NULL DEFAULT 'cash',
  `note` varchar(255)  DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `sales` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `staff_id` int NOT NULL,
  `sale_type` TEXT  NOT NULL DEFAULT 'retail',
  `receipt_number` varchar(32)  NOT NULL,
  `payment_method` TEXT  NOT NULL DEFAULT 'cash',
  `mpesa_channel` varchar(10)  DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_due` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_given` decimal(12,2) DEFAULT NULL,
  `change_given` decimal(12,2) DEFAULT NULL,
  `cash_amount` decimal(12,2) DEFAULT NULL,
  `mpesa_amount` decimal(12,2) DEFAULT NULL,
  `customer_name` varchar(120)  DEFAULT NULL,
  `customer_phone` varchar(30)  DEFAULT NULL,
  `customer_email` varchar(255)  DEFAULT NULL,
  `status` TEXT  NOT NULL DEFAULT 'completed',
  `payment_status` TEXT  NOT NULL DEFAULT 'paid',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
, sync_status INTEGER DEFAULT 0, global_id TEXT);
CREATE TABLE `service_appointment_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `appointment_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `service_name` varchar(160) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '1.00',
  `base_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `commission_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `service_appointments` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `invoice_number` varchar(32) NOT NULL,
  `booking_type` varchar(20) NOT NULL DEFAULT 'appointment',
  `customer_name` varchar(160) NOT NULL,
  `customer_phone` varchar(40) DEFAULT NULL,
  `customer_email` varchar(190) DEFAULT NULL,
  `scheduled_at` DATETIME NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'booked',
  `payment_status` varchar(20) NOT NULL DEFAULT 'unpaid',
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `commission_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `staff_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `service_benefits` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `service_id` int NOT NULL,
  `benefit_title` varchar(200)  NOT NULL,
  `benefit_description` text ,
  `icon_class` varchar(100)  DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `service_faqs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `service_id` int NOT NULL,
  `question` varchar(300)  NOT NULL,
  `answer` text  NOT NULL,
  `sort_order` int DEFAULT '0',
  `is_active` tinyINTEGER DEFAULT '1',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `service_gallery` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `service_id` int NOT NULL,
  `image_path` varchar(255)  NOT NULL,
  `image_title` varchar(100)  DEFAULT NULL,
  `image_description` text ,
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `service_sections` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `service_id` int NOT NULL,
  `section_type` TEXT  DEFAULT 'text_only',
  `title` varchar(200)  DEFAULT NULL,
  `content` text ,
  `media_url` varchar(500)  DEFAULT NULL,
  `media_type` TEXT  DEFAULT 'image',
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `services` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `title` varchar(200)  NOT NULL,
  `slug` varchar(200)  NOT NULL,
  `short_description` text ,
  `cover_image` varchar(255)  DEFAULT NULL,
  `status` TEXT  DEFAULT 'draft',
  `view_count` int DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `site_settings` (
  `setting_key` varchar(100)   NOT NULL,
  `setting_value` text  ,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `staff_reclock_authorizations` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `authorized_by` int NOT NULL,
  `authorized_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` DATETIME DEFAULT NULL
);
CREATE TABLE `staff_time_logs` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `clock_in_at` DATETIME NOT NULL,
  `clock_out_at` DATETIME DEFAULT NULL,
  `auto_closed` tinyINTEGER NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `stock_intake_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `stock_intake_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160)  NOT NULL,
  `quantity` decimal(12,2) NOT NULL,
  `buying_price` decimal(12,2) NOT NULL,
  `remark` varchar(255)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `stock_intakes` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `staff_id` int NOT NULL,
  `notes` varchar(255)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `store_invoice_items` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `store_product_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(160) NOT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(20) DEFAULT NULL,
  `package_unit` varchar(20) DEFAULT NULL,
  `package_quantity` decimal(12,2) DEFAULT NULL,
  `units_per_package` decimal(12,2) DEFAULT NULL,
  `package_price` decimal(12,2) DEFAULT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `profit_impact` decimal(12,2) NOT NULL DEFAULT '0.00'
);
CREATE TABLE `store_invoices` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `invoice_number` varchar(32) NOT NULL,
  `invoice_type` TEXT NOT NULL DEFAULT 'transfer',
  `source` varchar(64) DEFAULT 'Store',
  `destination` varchar(64) DEFAULT 'Shop Inventory',
  `invoice_to` varchar(160) DEFAULT NULL,
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `profit_impact` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `store_products` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `transferred_invoice_id` int DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `category_id` int DEFAULT NULL,
  `brand_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'piece',
  `package_unit` varchar(20) DEFAULT NULL,
  `package_quantity` decimal(12,2) DEFAULT NULL,
  `units_per_package` decimal(12,2) DEFAULT NULL,
  `package_price` decimal(12,2) DEFAULT NULL,
  `retail_pack_price` decimal(12,2) DEFAULT NULL,
  `colors` varchar(255) DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `faulty_quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `buying_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `package_buying_price` decimal(12,2) DEFAULT NULL,
  `retail_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `wholesale_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `offer_price` decimal(12,2) DEFAULT NULL,
  `offer_starts_at` DATETIME DEFAULT NULL,
  `offer_ends_at` DATETIME DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `notes` text,
  `status` TEXT NOT NULL DEFAULT 'stored',
  `created_by` int DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `transferred_at` DATETIME DEFAULT NULL
);
CREATE TABLE `subcategories` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `category_id` int NOT NULL,
  `name` varchar(120)  NOT NULL,
  `status` TEXT  NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `subscription_plans` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(100)  NOT NULL,
  `description` varchar(255)  DEFAULT NULL,
  `price_weekly` decimal(10,2) DEFAULT NULL,
  `price_biweekly` decimal(10,2) DEFAULT NULL,
  `price_monthly` decimal(10,2) DEFAULT NULL,
  `max_staff` int DEFAULT NULL,
  `max_products` int DEFAULT NULL,
  `features` TEXT DEFAULT NULL,
  `is_active` tinyINTEGER NOT NULL DEFAULT '1',
  `is_public` tinyINTEGER NOT NULL DEFAULT '1',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `subscription_stk` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `subscription_id` int DEFAULT NULL,
  `plan_id` int NOT NULL,
  `billing_interval` TEXT  NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `phone` varchar(15)  NOT NULL,
  `checkout_request_id` varchar(64)  DEFAULT NULL,
  `merchant_request_id` varchar(64)  DEFAULT NULL,
  `status` TEXT  NOT NULL DEFAULT 'pending',
  `result_code` int DEFAULT NULL,
  `result_desc` varchar(191)  DEFAULT NULL,
  `mpesa_receipt` varchar(32)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `subscriptions` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `billing_interval` TEXT  NOT NULL DEFAULT 'monthly',
  `amount` decimal(10,2) NOT NULL,
  `status` TEXT  NOT NULL DEFAULT 'trialing',
  `current_period_start` DATETIME DEFAULT NULL,
  `current_period_end` DATETIME DEFAULT NULL,
  `grace_until` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `suppliers` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `name` varchar(160)  NOT NULL,
  `phone` varchar(30)  DEFAULT NULL,
  `notes` varchar(255)  DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `tenant_notifications` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `audience` TEXT NOT NULL,
  `user_id` int DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `url` varchar(255) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `tenants` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `name` varchar(150)  NOT NULL,
  `slug` varchar(150)  NOT NULL,
  `owner_user_id` int DEFAULT NULL,
  `status` TEXT  NOT NULL DEFAULT 'active',
  `logo_path` varchar(255)  DEFAULT NULL,
  `currency` varchar(8)  NOT NULL DEFAULT 'KES',
  `phone` varchar(30)  DEFAULT NULL,
  `address` varchar(255)  DEFAULT NULL,
  `po_box` varchar(120)  DEFAULT NULL,
  `business_email` varchar(190)  DEFAULT NULL,
  `receipt_footer` text ,
  `kra_pin` varchar(20)  DEFAULT NULL,
  `payment_credentials` text ,
  `enabled_modules` TEXT DEFAULT NULL,
  `payment_methods_TEXT` text ,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `vat_inclusive` tinyINTEGER NOT NULL DEFAULT '1',
  `loyalty_points_per_kes` decimal(8,2) NOT NULL DEFAULT '1.00',
  `loyalty_kes_per_point` decimal(8,4) NOT NULL DEFAULT '0.0100',
  `low_stock_alert_enabled` tinyINTEGER NOT NULL DEFAULT '1',
  `product_commission_enabled` tinyINTEGER NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
, payment_methods_json TEXT);
CREATE TABLE `testimonials` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `customer_name` varchar(100)  NOT NULL,
  `customer_email` varchar(100)  DEFAULT NULL,
  `customer_phone` varchar(20)  DEFAULT NULL,
  `customer_initial` varchar(5)  DEFAULT NULL,
  `rating` int DEFAULT '5',
  `testimonial_text` text  NOT NULL,
  `service_tag` varchar(100)  DEFAULT NULL,
  `role` varchar(100)  DEFAULT NULL,
  `status` TEXT  DEFAULT 'pending',
  `is_featured` tinyINTEGER DEFAULT '0',
  `sort_order` int DEFAULT '0',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `approved_at` DATETIME
);
CREATE TABLE `user_permissions` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int NOT NULL,
  `user_id` int NOT NULL,
  `capability` varchar(64)  NOT NULL,
  `effect` TEXT  NOT NULL DEFAULT 'grant',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `user_profiles` (
  `user_id` int NOT NULL,
  `first_name` varchar(100)  DEFAULT NULL,
  `last_name` varchar(100)  DEFAULT NULL,
  `phone` varchar(20)  DEFAULT NULL,
  `address` text ,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE `users` (
  `id` INTEGER PRIMARY KEY AUTOINCREMENT,
  `tenant_id` int DEFAULT NULL,
  `username` varchar(50)  NOT NULL,
  `email` varchar(100)  NOT NULL,
  `password_hash` varchar(255)  NOT NULL,
  `pin_hash` varchar(255)  DEFAULT NULL,
  `position` varchar(100)  DEFAULT NULL,
  `must_reset_password` tinyINTEGER NOT NULL DEFAULT '0',
  `role_id` int NOT NULL,
  `is_active` tinyINTEGER DEFAULT '1',
  `email_verified` tinyINTEGER DEFAULT '0',
  `activation_token` varchar(64)  DEFAULT NULL,
  `activation_expires` DATETIME DEFAULT NULL,
  `activated_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE finance_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    entry_type TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    amount REAL NOT NULL DEFAULT 0.00,
    payment_method TEXT,
    reference TEXT,
    entry_date TEXT NOT NULL,
    created_by INTEGER,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_finance_tenant_date ON finance_entries(tenant_id, entry_date);
CREATE INDEX idx_finance_tenant_type ON finance_entries(tenant_id, entry_type);
CREATE TABLE purchases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    supplier_id INTEGER NULL,
    shop_name TEXT NULL,
    receipt_number TEXT NULL,
    receipt_image_path TEXT NULL,
    purchase_date TEXT NULL,
    notes TEXT NULL,
    staff_id INTEGER NULL,
    transfer_destination TEXT NOT NULL DEFAULT 'shop',
    status TEXT NOT NULL DEFAULT 'recorded',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    transferred_at DATETIME NULL
);
CREATE INDEX idx_purchases_tenant ON purchases(tenant_id, created_at);
CREATE INDEX idx_purchases_status ON purchases(tenant_id, status);
CREATE INDEX idx_purchases_supplier ON purchases(tenant_id, supplier_id);
CREATE INDEX idx_purchases_receipt ON purchases(tenant_id, receipt_number);
CREATE TABLE purchase_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    purchase_id INTEGER NOT NULL,
    name TEXT NULL,
    category_id INTEGER NULL,
    brand_id INTEGER NULL,
    barcode TEXT NULL,
    unit TEXT NULL DEFAULT 'piece',
    package_unit TEXT NULL,
    package_quantity REAL NULL,
    units_per_package REAL NULL,
    variant_label TEXT NULL,
    colors TEXT NULL,
    quantity REAL NOT NULL DEFAULT 0.00,
    faulty_quantity REAL NOT NULL DEFAULT 0.00,
    buying_price REAL NOT NULL DEFAULT 0.00,
    package_buying_price REAL NULL,
    wholesale_price REAL NULL,
    package_price REAL NULL,
    retail_price REAL NULL,
    retail_pack_price REAL NULL,
    image_path TEXT NULL,
    notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    store_product_id INTEGER NULL,
    product_id INTEGER NULL,
    transferred_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_purchase_items_purchase ON purchase_items(tenant_id, purchase_id);
CREATE INDEX idx_purchase_items_status ON purchase_items(tenant_id, status);
CREATE INDEX idx_purchase_items_name ON purchase_items(tenant_id, name);
CREATE TABLE employees (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    name TEXT NOT NULL,
    job_title TEXT NOT NULL DEFAULT 'Employee',
    phone TEXT NULL,
    salary_amount REAL NOT NULL DEFAULT 0,
    pay_day INTEGER NOT NULL DEFAULT 1,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_employee_tenant ON employees(tenant_id, is_active);
CREATE INDEX idx_employee_user ON employees(tenant_id, user_id);
CREATE TABLE payroll_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    employee_id INTEGER NOT NULL,
    pay_period TEXT NOT NULL,
    salary_amount REAL NOT NULL DEFAULT 0,
    commission_amount REAL NOT NULL DEFAULT 0,
    total_amount REAL NOT NULL DEFAULT 0,
    paid_on TEXT NOT NULL,
    payment_method TEXT NOT NULL DEFAULT 'cash',
    created_by INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_payroll_tenant ON payroll_payments(tenant_id, paid_on);
CREATE INDEX idx_payroll_employee ON payroll_payments(tenant_id, employee_id);
