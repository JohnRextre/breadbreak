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

