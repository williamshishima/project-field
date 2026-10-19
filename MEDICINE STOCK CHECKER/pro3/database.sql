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
-- MEDICINES TABLE (No unit field)
-- ============================================================
CREATE TABLE IF NOT EXISTS medicines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
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
-- INSERT DEFAULT ADMIN USER (Password: Admin@123)
-- ============================================================
INSERT INTO users (username, email, password_hash, full_name, role) 
VALUES ('admin', 'admin@medstock.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- ============================================================
-- INSERT SAMPLE MEDICINES (in TSh)
-- ============================================================
INSERT INTO medicines (name, category, stock, price, expiry_date, supplier, reorder_level, critical_level) VALUES
('Paracetamol 500mg', 'Analgesic', 142, 6000, '2025-12-31', 'PharmaCo', 20, 10),
('Amoxicillin 250mg', 'Antibiotic', 22, 12500, '2025-06-30', 'MediSupply', 15, 5),
('Insulin Glargine 100U', 'Endocrine', 4, 45000, '2025-08-15', 'DiabetesCare', 10, 3),
('Atorvastatin 20mg', 'Lipid-lowering', 87, 8750, '2026-01-20', 'HeartHealth', 25, 10),
('Metformin 850mg', 'Antidiabetic', 8, 6250, '2025-09-10', 'DiabetesCare', 15, 5),
('Vitamin D3 1000IU', 'Supplement', 0, 4500, '2026-03-01', 'NutriMax', 20, 10),
('Omeprazole 40mg', 'PPI', 56, 9990, '2025-07-01', 'GastroHealth', 15, 5);

-- ============================================================
-- INSERT SAMPLE TRANSACTIONS
-- ============================================================
INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
SELECT id, 1, 'add', stock, 0, stock, 'Initial stock added by admin' FROM medicines;