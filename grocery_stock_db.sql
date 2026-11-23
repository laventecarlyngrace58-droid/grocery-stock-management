CREATE DATABASE IF NOT EXISTS grocery_stock_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE grocery_stock_db;

CREATE TABLE IF NOT EXISTS categories (
    category_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_name VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (category_id),
    UNIQUE KEY uq_categories_category_name (category_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
    product_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category_id INT UNSIGNED NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    description TEXT NULL,
    unit VARCHAR(30) NOT NULL DEFAULT 'each',
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 5,
    cost_price DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    selling_price DECIMAL(10, 2) UNSIGNED NOT NULL DEFAULT 0.00,
    expiration_date DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (product_id),
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category_id (category_id),
    KEY idx_products_expiration_date (expiration_date),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (category_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_products_cost_price_nonnegative CHECK (cost_price >= 0),
    CONSTRAINT chk_products_selling_price_nonnegative CHECK (selling_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT UNSIGNED NOT NULL,
    transaction_type ENUM('stock_in', 'stock_out') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    reason VARCHAR(100) NOT NULL,
    notes VARCHAR(500) NULL,
    transaction_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    KEY idx_stock_transactions_product_date (product_id, transaction_date),
    CONSTRAINT fk_stock_transactions_product
        FOREIGN KEY (product_id) REFERENCES products (product_id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT chk_stock_transactions_quantity_positive CHECK (quantity > 0)
) ENGINE=InnoDB;
