CREATE DATABASE IF NOT EXISTS breadbreak_db;
USE breadbreak_db;

CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    profile_data MEDIUMBLOB NULL,
    profile_mime VARCHAR(50) NULL,
    role ENUM('admin', 'staff', 'customer') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    status_reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS menu_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    category_id INT NOT NULL,
    description TEXT NOT NULL,
    photo VARCHAR(255) NULL,
    photo_data MEDIUMBLOB NULL,
    photo_mime VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inventory_category FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS inventory_item_variants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    inventory_item_id INT NOT NULL,
    service_size VARCHAR(100) NOT NULL,
    sku VARCHAR(80) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    availability ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_variant_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY unique_item_service_size (inventory_item_id, service_size)
);

CREATE TABLE IF NOT EXISTS category_service_sizes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    size_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_category_size FOREIGN KEY (category_id) REFERENCES menu_categories(id) ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY unique_category_size (category_id, size_name)
);

INSERT IGNORE INTO menu_categories (id, name) VALUES
    (1, 'Bite-sized Breads'),
    (2, 'Big Breads'),
    (3, 'Palm-sized Cookies'),
    (4, 'Cookies'),
    (5, 'Pastries'),
    (6, 'Crinkles'),
    (7, 'Decadent Cakes'),
    (8, 'Round Cakes');

INSERT IGNORE INTO category_service_sizes (category_id, size_name)
SELECT id, 'Solo' FROM menu_categories WHERE name IN ('Bite-sized Breads', 'Big Breads')
UNION ALL
SELECT id, 'Partner' FROM menu_categories WHERE name IN ('Bite-sized Breads', 'Big Breads')
UNION ALL
SELECT id, 'Family' FROM menu_categories WHERE name IN ('Bite-sized Breads', 'Big Breads')
UNION ALL
SELECT id, '1pc' FROM menu_categories WHERE name IN ('Palm-sized Cookies', 'Cookies', 'Pastries', 'Crinkles', 'Decadent Cakes')
UNION ALL
SELECT id, '10pcs tub' FROM menu_categories WHERE name IN ('Palm-sized Cookies', 'Cookies', 'Pastries', 'Crinkles', 'Decadent Cakes')
UNION ALL
SELECT id, '6-inch round' FROM menu_categories WHERE name = 'Round Cakes'
UNION ALL
SELECT id, '8-inch round' FROM menu_categories WHERE name = 'Round Cakes';

CREATE TABLE IF NOT EXISTS customer_cart (
    user_id INT NOT NULL,
    variant_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, variant_id),
    CONSTRAINT fk_customer_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_cart_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON DELETE CASCADE
);

-- =============================================================================
-- Orders
-- =============================================================================
CREATE TABLE IF NOT EXISTS orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    customer_id INT NOT NULL,
    reference_id VARCHAR(32) NOT NULL UNIQUE,
    status ENUM('pending', 'processing', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vatable_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    vat_exempt_sales DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_type ENUM('none', 'senior', 'pwd') NOT NULL DEFAULT 'none',
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_id_number VARCHAR(100) NULL,
    discount_name VARCHAR(150) NULL,
    delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    delivery_address TEXT NULL,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_customer FOREIGN KEY (customer_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

-- =============================================================================
-- Order Items  (snapshot of product/variant at time of purchase)
-- =============================================================================
CREATE TABLE IF NOT EXISTS order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    variant_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    service_size VARCHAR(100) NOT NULL,
    sku VARCHAR(80) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    line_total DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_order_item_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_order_item_variant FOREIGN KEY (variant_id) REFERENCES inventory_item_variants(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

-- =============================================================================
-- Payments  (one Xendit payment_request per order)
-- =============================================================================
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    xendit_payment_request_id VARCHAR(255) NOT NULL UNIQUE,
    reference_id VARCHAR(64) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'PHP',
    payment_method VARCHAR(60) NULL,
    payment_channel VARCHAR(60) NULL,
    status ENUM('pending', 'paid', 'failed', 'expired', 'voided') NOT NULL DEFAULT 'pending',
    xendit_raw_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_order FOREIGN KEY (order_id) REFERENCES orders(id) ON UPDATE CASCADE ON DELETE RESTRICT
);

-- =============================================================================
-- Delivery Settings  (admin-configurable key-value store)
-- =============================================================================
CREATE TABLE IF NOT EXISTS delivery_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Default values (seeded by database/migrate_delivery.php)
-- delivery_mode        = 'simple' | 'full'
-- delivery_zones       = JSON array of {name, label, max_km, fee, min_order}
-- in_range_areas       = JSON array of lowercase barangay/city names (simple mode)
-- branch_lat/lng       = branch GPS coordinates (full mode)
-- branch_name          = display name of branch
-- max_drive_time_min   = drive time threshold for traffic warning
-- geoapify_api_key     = optional Geoapify key (full mode)

-- =============================================================================
-- Delivery Geocode Cache  (24-hour TTL, respects Nominatim rate limit)
-- =============================================================================
CREATE TABLE IF NOT EXISTS delivery_geocode_cache (
    id INT PRIMARY KEY AUTO_INCREMENT,
    address_hash CHAR(64) NOT NULL UNIQUE,
    address_raw TEXT NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    geocode_success TINYINT(1) NOT NULL DEFAULT 0,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geocache_hash (address_hash)
);

-- =============================================================================
-- Delivery Zone Logs  (analytics per order)
-- =============================================================================
CREATE TABLE IF NOT EXISTS delivery_zone_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    customer_address TEXT NOT NULL,
    zone VARCHAR(20) NULL,
    distance_km DECIMAL(8,3) NULL,
    drive_time_min DECIMAL(8,1) NULL,
    delivery_fee_charged DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    mode_used ENUM('simple','full') NOT NULL DEFAULT 'simple',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_zone_logs_order (order_id)
);
