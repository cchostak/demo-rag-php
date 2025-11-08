CREATE DATABASE IF NOT EXISTS sales_db;

USE sales_db;

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    sold_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL UNIQUE,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    description TEXT NULL,
    category VARCHAR(100) NOT NULL DEFAULT 'general',
    stock INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO sales (item_name, quantity, sold_at) VALUES
('Apple iPhone 14', 120, '2024-10-01 10:33:00'),
('Apple iPhone 14', 403, '2024-10-15 11:15:00'),
('Samsung Galaxy S23', 250, '2024-10-02 09:50:00'),
('Samsung Galaxy S23', 168, '2024-10-12 13:20:00'),
('Sony WH-1000XM5 Headphones', 350, '2024-10-03 14:12:00');

INSERT INTO products (name, sku, price, description, category, stock) VALUES
('Apple iPhone 14', 'IP14-128-BLK', 799.99, 'iPhone 14 128GB Black', 'phones', 50),
('Samsung Galaxy S23', 'SGS23-256-GRN', 749.00, 'Galaxy S23 256GB Green', 'phones', 35),
('Sony WH-1000XM5', 'SONY-XM5-BLK', 349.99, 'Noise-canceling over-ear headphones', 'audio', 80);
