-- ============================================================
-- MedStock Professional Inventory System - Database Schema
-- ============================================================

-- Create database
CREATE DATABASE IF NOT EXISTS medstock_db;
USE medstock_db;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'manager', 'staff') DEFAULT 'staff',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- ============================================================
-- MEDICINES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    unit VARCHAR(20) DEFAULT 'units',
    price DECIMAL(10,2) DEFAULT 0.00,
    expiry_date DATE NULL,
    supplier VARCHAR(200),
    reorder_level INT DEFAULT 10,
    critical_level INT DEFAULT 5,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_name (name),
    INDEX idx_category (category),
    INDEX idx_stock (stock)
);

-- ============================================================
-- STOCK TRANSACTIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    user_id INT NOT NULL,
    transaction_type ENUM('add', 'remove', 'adjust', 'restock', 'sale') NOT NULL,
    quantity INT NOT NULL,
    previous_stock INT NOT NULL,
    new_stock INT NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_medicine (medicine_id),
    INDEX idx_created (created_at)
);

-- ============================================================
-- USER ACTIVITY LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS user_activity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100),
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
);

-- ============================================================
-- INSERT DEFAULT ADMIN USER
-- ============================================================
INSERT INTO users (username, email, password_hash, full_name, role) 
VALUES ('admin', 'admin@medstock.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- ============================================================
-- INSERT SAMPLE MEDICINES
-- ============================================================
INSERT INTO medicines (name, category, stock, unit, price, expiry_date, supplier, reorder_level, critical_level) VALUES
('Paracetamol 500mg', 'Analgesic', 142, 'tablets', 5.99, '2025-12-31', 'PharmaCo', 20, 10),
('Amoxicillin 250mg', 'Antibiotic', 22, 'capsules', 12.50, '2025-06-30', 'MediSupply', 15, 5),
('Insulin Glargine 100U', 'Endocrine', 4, 'vials', 45.00, '2025-08-15', 'DiabetesCare', 10, 3),
('Atorvastatin 20mg', 'Lipid-lowering', 87, 'tablets', 8.75, '2026-01-20', 'HeartHealth', 25, 10),
('Metformin 850mg', 'Antidiabetic', 8, 'tablets', 6.25, '2025-09-10', 'DiabetesCare', 15, 5),
('Vitamin D3 1000IU', 'Supplement', 0, 'capsules', 4.50, '2026-03-01', 'NutriMax', 20, 10),
('Omeprazole 40mg', 'PPI', 56, 'capsules', 9.99, '2025-07-01', 'GastroHealth', 15, 5);

-- ============================================================
-- INSERT SAMPLE TRANSACTIONS
-- ============================================================
INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
SELECT id, 1, 'add', stock, 0, stock, 'Initial stock' FROM medicines;

-- ============================================================
-- VIEWS FOR REPORTS
-- ============================================================
CREATE OR REPLACE VIEW vw_medicine_status AS
SELECT 
    id,
    name,
    category,
    stock,
    reorder_level,
    critical_level,
    CASE 
        WHEN stock <= 0 THEN 'Out of Stock'
        WHEN stock <= critical_level THEN 'Critical'
        WHEN stock <= reorder_level THEN 'Low'
        ELSE 'In Stock'
    END AS status,
    unit,
    price,
    supplier,
    expiry_date
FROM medicines;

CREATE OR REPLACE VIEW vw_stock_summary AS
SELECT 
    COUNT(*) as total_medicines,
    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
    SUM(CASE WHEN stock > 0 AND stock <= critical_level THEN 1 ELSE 0 END) as critical,
    SUM(CASE WHEN stock > critical_level AND stock <= reorder_level THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN stock > reorder_level THEN 1 ELSE 0 END) as in_stock,
    SUM(stock) as total_units,
    SUM(price * stock) as total_value
FROM medicines;