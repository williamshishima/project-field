<?php
// ============================================================
// MedStock Professional Inventory Management System
// All-in-one PHP Application
// ============================================================

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// DATABASE CONFIGURATION - UPDATE THESE
// ============================================================
define('DB_HOST', 'localhost');
define('DB_NAME', 'medstock_db');
define('DB_USER', 'root');
define('DB_PASS', '');

// ============================================================
// DATABASE CLASS
// ============================================================
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage() . 
                "<br><br>Please ensure MySQL is running and the database exists.<br>
                Run the database.sql file to create the database structure.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }
    
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
    
    public function beginTransaction() {
        return $this->conn->beginTransaction();
    }
    
    public function commit() {
        return $this->conn->commit();
    }
    
    public function rollback() {
        return $this->conn->rollback();
    }
}

// ============================================================
// SESSION MANAGER
// ============================================================
class SessionManager {
    public static function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    public static function has($key) {
        return isset($_SESSION[$key]);
    }
    
    public static function remove($key) {
        unset($_SESSION[$key]);
    }
    
    public static function destroy() {
        $_SESSION = [];
        session_destroy();
    }
    
    public static function isLoggedIn() {
        return self::has('user_id');
    }
    
    public static function getUser() {
        return self::get('user');
    }
    
    public static function getUserId() {
        return self::get('user_id');
    }
    
    public static function getUserRole() {
        return self::get('user_role');
    }
    
    public static function isAdmin() {
        return self::getUserRole() === 'admin';
    }
}

// ============================================================
// AUTHENTICATION
// ============================================================
class Auth {
    public static function login($username, $password) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT id, username, email, password_hash, full_name, role, is_active 
                FROM users WHERE username = ? OR email = ?
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
                SessionManager::set('user_id', $user['id']);
                SessionManager::set('user', [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'role' => $user['role']
                ]);
                SessionManager::set('user_role', $user['role']);
                
                // Update last login
                $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $updateStmt->execute([$user['id']]);
                
                // Log activity
                $logStmt = $db->prepare("
                    INSERT INTO user_activity (user_id, action, ip_address) 
                    VALUES (?, 'login', ?)
                ");
                $logStmt->execute([$user['id'], $_SERVER['REMOTE_ADDR']]);
                
                return ['success' => true];
            }
            
            return ['success' => false, 'message' => 'Invalid username or password.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    public static function register($data) {
        $errors = [];
        
        if (empty($data['username']) || strlen($data['username']) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (empty($data['full_name'])) {
            $errors[] = 'Please enter your full name.';
        }
        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($data['password'] !== $data['confirm_password']) {
            $errors[] = 'Passwords do not match.';
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'message' => implode('<br>', $errors)];
        }
        
        try {
            $db = Database::getInstance();
            
            // Check if username or email exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $checkStmt->execute([$data['username'], $data['email']]);
            
            if ($checkStmt->rowCount() > 0) {
                return ['success' => false, 'message' => 'Username or email already exists.'];
            }
            
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $insertStmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role) 
                VALUES (?, ?, ?, ?, 'staff')
            ");
            $insertStmt->execute([$data['username'], $data['email'], $password_hash, $data['full_name']]);
            
            return ['success' => true, 'message' => 'Registration successful! You can now login.'];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Registration failed. Please try again.'];
        }
    }
    
    public static function logout() {
        if (SessionManager::isLoggedIn()) {
            try {
                $db = Database::getInstance();
                $logStmt = $db->prepare("
                    INSERT INTO user_activity (user_id, action, ip_address) 
                    VALUES (?, 'logout', ?)
                ");
                $logStmt->execute([SessionManager::getUserId(), $_SERVER['REMOTE_ADDR']]);
            } catch (Exception $e) {}
        }
        SessionManager::destroy();
    }
}

// ============================================================
// INVENTORY MANAGER
// ============================================================
class Inventory {
    public static function getAll($search = '', $category = '') {
        try {
            $db = Database::getInstance();
            $sql = "SELECT * FROM medicines WHERE 1=1";
            $params = [];
            
            if (!empty($search)) {
                $sql .= " AND (name LIKE ? OR category LIKE ?)";
                $searchTerm = "%$search%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            if (!empty($category)) {
                $sql .= " AND category = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public static function getCategories() {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT DISTINCT category FROM medicines ORDER BY category");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public static function getStats() {
        try {
            $db = Database::getInstance();
            
            // Get total medicines
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM medicines");
            $stmt->execute();
            $total = $stmt->fetch()['total'];
            
            // Get stock counts
            $stmt = $db->prepare("
                SELECT 
                    SUM(CASE WHEN stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
                    SUM(CASE WHEN stock > 0 AND stock <= critical_level THEN 1 ELSE 0 END) as critical,
                    SUM(CASE WHEN stock > critical_level AND stock <= reorder_level THEN 1 ELSE 0 END) as low_stock,
                    SUM(CASE WHEN stock > reorder_level THEN 1 ELSE 0 END) as in_stock,
                    SUM(stock) as total_units,
                    SUM(price * stock) as total_value
                FROM medicines
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            return array_merge(['total_medicines' => $total], $result);
        } catch (PDOException $e) {
            return [
                'total_medicines' => 0,
                'out_of_stock' => 0,
                'critical' => 0,
                'low_stock' => 0,
                'in_stock' => 0,
                'total_units' => 0,
                'total_value' => 0
            ];
        }
    }
    
    public static function getStatus($stock) {
        if ($stock <= 0) return ['label' => 'Out of Stock', 'class' => 'danger', 'icon' => 'fa-times-circle'];
        if ($stock <= 5) return ['label' => 'Critical', 'class' => 'danger', 'icon' => 'fa-exclamation-triangle'];
        if ($stock <= 10) return ['label' => 'Low', 'class' => 'warning', 'icon' => 'fa-exclamation-circle'];
        return ['label' => 'In Stock', 'class' => 'success', 'icon' => 'fa-check-circle'];
    }
    
    // Only ADMIN can add stock
    public static function add($data) {
        // Check if user is admin
        if (!SessionManager::isAdmin()) {
            return ['success' => false, 'message' => 'Only administrators can add medicines.'];
        }
        
        try {
            $db = Database::getInstance();
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                INSERT INTO medicines (name, category, stock, price, expiry_date, supplier, reorder_level, critical_level, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['name'],
                $data['category'],
                $data['stock'],
                $data['price'] ?? 0,
                $data['expiry_date'] ?? null,
                $data['supplier'] ?? null,
                $data['reorder_level'] ?? 10,
                $data['critical_level'] ?? 5,
                SessionManager::getUserId()
            ]);
            
            $id = $db->lastInsertId();
            
            // Log transaction
            $logStmt = $db->prepare("
                INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
                VALUES (?, ?, 'add', ?, 0, ?, 'Initial stock added by admin')
            ");
            $logStmt->execute([$id, SessionManager::getUserId(), $data['stock'], $data['stock']]);
            
            $db->commit();
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            $db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // Only ADMIN can update stock
    public static function update($id, $data) {
        // Check if user is admin
        if (!SessionManager::isAdmin()) {
            return ['success' => false, 'message' => 'Only administrators can update medicines.'];
        }
        
        try {
            $db = Database::getInstance();
            $db->beginTransaction();
            
            // Get current stock
            $currentStmt = $db->prepare("SELECT stock FROM medicines WHERE id = ?");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            $oldStock = $current['stock'] ?? 0;
            
            $stmt = $db->prepare("
                UPDATE medicines 
                SET name = ?, category = ?, stock = ?, price = ?, 
                    expiry_date = ?, supplier = ?, reorder_level = ?, critical_level = ? 
                WHERE id = ?
            ");
            $stmt->execute([
                $data['name'],
                $data['category'],
                $data['stock'],
                $data['price'] ?? 0,
                $data['expiry_date'] ?? null,
                $data['supplier'] ?? null,
                $data['reorder_level'] ?? 10,
                $data['critical_level'] ?? 5,
                $id
            ]);
            
            // Log transaction if stock changed
            if ($oldStock != $data['stock']) {
                $logStmt = $db->prepare("
                    INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
                    VALUES (?, ?, 'adjust', ?, ?, ?, 'Stock updated by admin')
                ");
                $logStmt->execute([
                    $id, 
                    SessionManager::getUserId(), 
                    abs($data['stock'] - $oldStock), 
                    $oldStock, 
                    $data['stock']
                ]);
            }
            
            $db->commit();
            return ['success' => true];
        } catch (PDOException $e) {
            $db->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // Only ADMIN can delete
    public static function delete($id) {
        if (!SessionManager::isAdmin()) {
            return ['success' => false, 'message' => 'Only administrators can delete medicines.'];
        }
        
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            return ['success' => true];
        } catch (PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    public static function getById($id) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            return null;
        }
    }
    
    public static function getRecentTransactions($limit = 20) {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT t.*, m.name as medicine_name, u.username, u.role 
                FROM stock_transactions t 
                JOIN medicines m ON t.medicine_id = m.id 
                JOIN users u ON t.user_id = u.id 
                ORDER BY t.created_at DESC LIMIT ?
            ");
            $stmt->execute([$limit]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    
    public static function getLowStockItems() {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                SELECT * FROM medicines 
                WHERE stock <= reorder_level 
                ORDER BY stock ASC 
                LIMIT 10
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    
    // Live search function
    public static function liveSearch($query) {
        try {
            $db = Database::getInstance();
            $sql = "SELECT * FROM medicines WHERE name LIKE ? OR category LIKE ? ORDER BY name ASC LIMIT 20";
            $searchTerm = "%$query%";
            $stmt = $db->prepare($sql);
            $stmt->execute([$searchTerm, $searchTerm]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

// ============================================================
// REQUEST HANDLER
// ============================================================
$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';

// Handle AJAX requests
if ($action) {
    header('Content-Type: application/json');
    
    if (!SessionManager::isLoggedIn()) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $response = ['success' => false, 'message' => 'Invalid action'];
    
    switch ($action) {
        case 'get_medicine':
            $id = $_GET['id'] ?? 0;
            $data = Inventory::getById($id);
            $response = ['success' => true, 'data' => $data];
            break;
            
        case 'add_medicine':
            $data = $_POST;
            $response = Inventory::add($data);
            break;
            
        case 'update_medicine':
            $id = $_POST['id'] ?? 0;
            $data = $_POST;
            $response = Inventory::update($id, $data);
            break;
            
        case 'delete_medicine':
            $id = $_POST['id'] ?? 0;
            $response = Inventory::delete($id);
            break;
            
        case 'live_search':
            $query = $_GET['q'] ?? '';
            $data = Inventory::liveSearch($query);
            $response = ['success' => true, 'data' => $data];
            break;
            
        case 'get_stats':
            $response = ['success' => true, 'data' => Inventory::getStats()];
            break;
    }
    
    echo json_encode($response);
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['login'])) {
        $result = Auth::login($_POST['username'], $_POST['password']);
        if ($result['success']) {
            header('Location: ?page=dashboard');
            exit();
        }
        $login_error = $result['message'];
    } elseif (isset($_POST['register'])) {
        $result = Auth::register($_POST);
        if ($result['success']) {
            $register_success = $result['message'];
        } else {
            $register_error = $result['message'];
        }
    } elseif (isset($_POST['logout'])) {
        Auth::logout();
        header('Location: ?page=login');
        exit();
    } elseif (isset($_POST['add_medicine'])) {
        $result = Inventory::add($_POST);
        if ($result['success']) {
            $add_success = 'Medicine added successfully!';
        } else {
            $add_error = $result['message'];
        }
    }
}

// Check authentication for protected pages
$protected_pages = ['dashboard', 'inventory', 'add', 'reports', 'settings'];
if (in_array($page, $protected_pages) && !SessionManager::isLoggedIn()) {
    $page = 'login';
}

// Get data for dashboard
$medicines = [];
$stats = [];
$recent_transactions = [];
$low_stock_items = [];
$categories = [];
$user = SessionManager::getUser();
$is_admin = SessionManager::isAdmin();

if (SessionManager::isLoggedIn()) {
    $search = $_GET['search'] ?? '';
    $category_filter = $_GET['category'] ?? '';
    $medicines = Inventory::getAll($search, $category_filter);
    $stats = Inventory::getStats();
    $recent_transactions = Inventory::getRecentTransactions();
    $low_stock_items = Inventory::getLowStockItems();
    $categories = Inventory::getCategories();
}

// Check if admin user exists in database
$admin_exists = false;
try {
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT id FROM users WHERE username = 'Admin' OR email = 'williamshishima@gmail.com'");
    $stmt->execute();
    $admin_exists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $admin_exists = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedStock · Professional Inventory System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        
        body {
            background: #f0f5f2;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* ============================================================
           NAVBAR
           ============================================================ */
        .navbar {
            background: #1a3d2f;
            color: white;
            padding: 0.8rem 2rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-bottom: 3px solid #f5c542;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .logo {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        .logo i { color: #f5c542; margin-right: 10px; }
        
        .nav-links {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .nav-links a {
            color: #d4e8dd;
            text-decoration: none;
            font-weight: 500;
            padding: 0.4rem 1rem;
            border-radius: 25px;
            transition: 0.2s;
            font-size: 0.9rem;
            background: rgba(255,255,255,0.05);
        }
        
        .nav-links a i { margin-right: 6px; }
        .nav-links a:hover { background: #2d5e48; color: white; }
        .nav-links a.active {
            background: #f5c542;
            color: #1a3d2f;
            font-weight: 600;
        }
        
        .user-section {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #d4e8dd;
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        
        .user-section .user-name { font-weight: 500; color: white; }
        .user-section .user-role {
            background: rgba(245,197,66,0.2);
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .user-section .user-role.admin { background: #f5c542; color: #1a3d2f; }
        
        .logout-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            border: none;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.85rem;
        }
        .logout-btn:hover { background: #c0392b; }
        
        .admin-badge {
            background: #f5c542;
            color: #1a3d2f;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 4px;
        }
        
        /* ============================================================
           CONTAINER
           ============================================================ */
        .container {
            max-width: 1400px;
            margin: 1.5rem auto;
            padding: 0 1.5rem;
            flex: 1;
            width: 100%;
        }
        
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: #1a3d2f;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .page-header h1 i { color: #f5c542; }
        .page-header p { color: #4d7a62; margin-top: 4px; }
        
        /* ============================================================
           STATS CARDS
           ============================================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.2rem 1rem;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #1a3d2f;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .stat-card:nth-child(2) { border-left-color: #f5c542; }
        .stat-card:nth-child(3) { border-left-color: #e67e22; }
        .stat-card:nth-child(4) { border-left-color: #27ae60; }
        .stat-card:nth-child(5) { border-left-color: #c0392b; }
        .stat-card:nth-child(6) { border-left-color: #3498db; }
        
        .stat-card i {
            font-size: 2rem;
            color: #1a3d2f;
            width: 2.5rem;
            text-align: center;
        }
        .stat-card:nth-child(2) i { color: #f5c542; }
        .stat-card:nth-child(3) i { color: #e67e22; }
        .stat-card:nth-child(4) i { color: #27ae60; }
        .stat-card:nth-child(5) i { color: #c0392b; }
        .stat-card:nth-child(6) i { color: #3498db; }
        
        .stat-info h3 {
            font-size: 0.7rem;
            font-weight: 500;
            color: #4d7a62;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-info .number {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1a3d2f;
            line-height: 1.2;
        }
        
        /* ============================================================
           TABLES & WRAPPERS
           ============================================================ */
        .table-wrapper {
            background: white;
            border-radius: 20px;
            padding: 1.2rem 1rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid #e8f0ec;
            overflow-x: auto;
            margin-bottom: 1.5rem;
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0.5rem 1rem 0.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .table-header h2 {
            font-size: 1.2rem;
            color: #1a3d2f;
        }
        .table-header h2 i { color: #f5c542; margin-right: 8px; }
        
        .filter-group {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .filter-group input, .filter-group select {
            padding: 0.4rem 1rem;
            border: 2px solid #dce8e0;
            border-radius: 25px;
            background: #fafffd;
            font-size: 0.9rem;
            outline: none;
            transition: 0.2s;
        }
        .filter-group input:focus, .filter-group select:focus {
            border-color: #1a3d2f;
            box-shadow: 0 0 0 3px rgba(26,61,47,0.1);
        }
        .filter-group button {
            background: #f5c542;
            border: none;
            color: #1a3d2f;
            padding: 0.4rem 1.2rem;
            border-radius: 25px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .filter-group button:hover { background: #e6b53a; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th {
            text-align: left;
            padding: 0.8rem 0.5rem;
            color: #1a3d2f;
            font-weight: 600;
            border-bottom: 2px solid #e8f0ec;
        }
        td {
            padding: 0.7rem 0.5rem;
            border-bottom: 1px solid #f0f5f2;
            color: #1a3d2f;
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: #f8fbfa; }
        
        .status-badge {
            display: inline-block;
            padding: 0.15rem 0.8rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .status-badge.success { background: #d5f0e0; color: #1a7a42; }
        .status-badge.warning { background: #fcf1d6; color: #8f6d1f; }
        .status-badge.danger { background: #fad1c4; color: #a84a1a; }
        
        .action-icons i {
            margin: 0 4px;
            color: #4d7a62;
            cursor: pointer;
            transition: 0.15s;
            font-size: 1rem;
        }
        .action-icons i:hover { color: #1a3d2f; }
        .action-icons i.danger:hover { color: #c0392b; }
        .action-icons i.disabled {
            opacity: 0.3;
            cursor: not-allowed;
        }
        .action-icons i.disabled:hover { color: #4d7a62; }
        
        .row-low { background-color: #fffae6; }
        .row-critical { background-color: #ffefeb; }
        .row-out { background-color: #ffebeb; }
        
        .page-panel { display: none; }
        .page-panel.active-page { display: block; }
        
        /* ============================================================
           FORMS
           ============================================================ */
        .form-container {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            border: 1px solid #e8f0ec;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.2rem;
        }
        .form-group {
            margin-bottom: 1.2rem;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            color: #1a3d2f;
            margin-bottom: 0.3rem;
            font-size: 0.9rem;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 0.6rem 1rem;
            border: 2px solid #dce8e0;
            border-radius: 25px;
            font-size: 0.9rem;
            transition: 0.2s;
            background: #fafffd;
        }
        .form-group input:focus, .form-group select:focus {
            border-color: #1a3d2f;
            outline: none;
            box-shadow: 0 0 0 3px rgba(26,61,47,0.1);
        }
        .form-group input:disabled {
            background: #e8f0ec;
            cursor: not-allowed;
        }
        
        .btn {
            padding: 0.6rem 1.8rem;
            border-radius: 25px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-primary { background: #1a3d2f; color: white; }
        .btn-primary:hover { background: #2d5e48; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(26,61,47,0.3); }
        .btn-secondary { background: #f5c542; color: #1a3d2f; }
        .btn-secondary:hover { background: #e6b53a; transform: translateY(-2px); }
        .btn-danger { background: #c0392b; color: white; }
        .btn-danger:hover { background: #e74c3c; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #2ecc71; }
        
        /* ============================================================
           LOGIN / REGISTER PAGES
           ============================================================ */
        .auth-container {
            max-width: 420px;
            margin: 3rem auto;
            background: white;
            border-radius: 24px;
            padding: 2.5rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.08);
            border: 1px solid #e8f0ec;
        }
        .auth-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .auth-header .logo {
            font-size: 2rem;
            font-weight: 700;
            color: #1a3d2f;
        }
        .auth-header .logo i { color: #f5c542; }
        .auth-header h2 { color: #1a3d2f; margin-top: 0.5rem; }
        .auth-header p { color: #4d7a62; }
        
        .auth-container .form-group input {
            padding-left: 3rem;
        }
        .auth-container .input-wrapper {
            position: relative;
        }
        .auth-container .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #4d7a62;
        }
        .auth-container .input-wrapper .toggle-password {
            left: auto;
            right: 1rem;
            cursor: pointer;
        }
        
        .alert {
            padding: 0.8rem 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-danger { background: #fad1c4; color: #a84a1a; border-left: 4px solid #c0392b; }
        .alert-success { background: #d5f0e0; color: #1a7a42; border-left: 4px solid #27ae60; }
        .alert-info { background: #d4e8dd; color: #1a3d2f; border-left: 4px solid #1a3d2f; }
        .alert-warning { background: #fcf1d6; color: #8f6d1f; border-left: 4px solid #f5c542; }
        
        .demo-box {
            background: #f5f0d7;
            padding: 0.8rem 1.2rem;
            border-radius: 12px;
            margin-top: 1.5rem;
            border-left: 4px solid #f5c542;
        }
        .demo-box p { font-size: 0.85rem; color: #5a4a1f; margin: 0.2rem 0; }
        
        .footer-text {
            text-align: center;
            margin-top: 2rem;
            color: #4d7a62;
            font-size: 0.85rem;
        }
        
        /* ============================================================
           TRANSACTION CARDS
           ============================================================ */
        .transactions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
        }
        .transaction-card {
            background: #f8fbfa;
            padding: 1rem;
            border-radius: 12px;
            border-left: 3px solid #1a3d2f;
        }
        .transaction-card .med-name { font-weight: 600; color: #1a3d2f; }
        .transaction-card .details { font-size: 0.85rem; color: #4d7a62; margin-top: 4px; }
        .transaction-card .time { font-size: 0.75rem; color: #8aa89a; }
        .transaction-card .admin-tag {
            background: #f5c542;
            color: #1a3d2f;
            padding: 0.1rem 0.5rem;
            border-radius: 10px;
            font-size: 0.6rem;
            font-weight: 700;
        }
        
        /* ============================================================
           LIVE SEARCH
           ============================================================ */
        .live-search-container {
            position: relative;
            width: 100%;
            max-width: 500px;
        }
        
        .live-search-container input {
            width: 100%;
            padding: 0.6rem 1rem 0.6rem 2.5rem;
            border: 2px solid #dce8e0;
            border-radius: 25px;
            font-size: 0.9rem;
            transition: 0.2s;
            background: #fafffd;
        }
        .live-search-container input:focus {
            border-color: #1a3d2f;
            box-shadow: 0 0 0 3px rgba(26,61,47,0.1);
            outline: none;
        }
        .live-search-container .search-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #4d7a62;
        }
        .live-search-container .search-clear {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #4d7a62;
            cursor: pointer;
            display: none;
        }
        .live-search-container .search-clear.show {
            display: block;
        }
        
        .live-search-results {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            right: 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            border: 1px solid #e8f0ec;
            max-height: 400px;
            overflow-y: auto;
            display: none;
            z-index: 999;
        }
        .live-search-results.show {
            display: block;
        }
        .live-search-results .search-item {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid #f0f5f2;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .live-search-results .search-item:hover {
            background: #f8fbfa;
        }
        .live-search-results .search-item:last-child {
            border-bottom: none;
        }
        .live-search-results .search-item .item-name {
            font-weight: 600;
            color: #1a3d2f;
        }
        .live-search-results .search-item .item-details {
            font-size: 0.8rem;
            color: #4d7a62;
        }
        .live-search-results .search-item .item-stock {
            font-weight: 600;
            font-size: 0.85rem;
        }
        .live-search-results .search-item .item-stock.low { color: #e67e22; }
        .live-search-results .search-item .item-stock.critical { color: #c0392b; }
        .live-search-results .search-item .item-stock.in-stock { color: #27ae60; }
        .live-search-results .search-empty {
            padding: 1.5rem;
            text-align: center;
            color: #4d7a62;
        }
        .live-search-results .search-loading {
            padding: 1.5rem;
            text-align: center;
            color: #4d7a62;
        }
        
        .live-search-results::-webkit-scrollbar {
            width: 6px;
        }
        .live-search-results::-webkit-scrollbar-track {
            background: #f0f5f2;
            border-radius: 10px;
        }
        .live-search-results::-webkit-scrollbar-thumb {
            background: #c8ddd0;
            border-radius: 10px;
        }
        .live-search-results::-webkit-scrollbar-thumb:hover {
            background: #a8c0b0;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .navbar { flex-direction: column; gap: 0.5rem; padding: 0.8rem 1rem; }
            .nav-links { justify-content: center; width: 100%; }
            .user-section { width: 100%; justify-content: center; flex-wrap: wrap; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .container { padding: 0 1rem; }
            .live-search-container { max-width: 100%; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filter-group input { width: 120px; }
        }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            color: white;
            font-weight: 500;
            z-index: 9999;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s ease;
            max-width: 400px;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .toast-success { background: #27ae60; }
        .toast-error { background: #c0392b; }
        .toast-info { background: #3498db; }
        .toast-warning { background: #e67e22; }
        
        .access-denied {
            background: #fad1c4;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            border-left: 4px solid #c0392b;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #a84a1a;
        }
        .access-denied i { font-size: 1.5rem; }
    </style>
</head>
<body>

<!-- Toast Notification -->
<div id="toast" class="toast"></div>

<!-- ============================================================
NAVBAR
============================================================ -->
<nav class="navbar">
    <div class="logo">
        <i class="fas fa-pills"></i> MedStock
    </div>
    
    <?php if (SessionManager::isLoggedIn()): ?>
    <div class="nav-links">
        <a href="?page=dashboard" class="<?php echo $page == 'dashboard' ? 'active' : ''; ?>">
            <i class="fas fa-home"></i> Dashboard
        </a>
        <a href="?page=inventory" class="<?php echo $page == 'inventory' ? 'active' : ''; ?>">
            <i class="fas fa-boxes"></i> Inventory
        </a>
        <?php if ($is_admin): ?>
        <a href="?page=add" class="<?php echo $page == 'add' ? 'active' : ''; ?>">
            <i class="fas fa-plus-circle"></i> Add
        </a>
        <?php endif; ?>
        <a href="?page=reports" class="<?php echo $page == 'reports' ? 'active' : ''; ?>">
            <i class="fas fa-chart-line"></i> Reports
        </a>
        <a href="?page=settings" class="<?php echo $page == 'settings' ? 'active' : ''; ?>">
            <i class="fas fa-cog"></i> Settings
        </a>
    </div>
    <div class="user-section">
        <span class="user-name">
            <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? ''); ?>
            <?php if ($is_admin): ?>
            <span class="admin-badge"><i class="fas fa-crown"></i> Admin</span>
            <?php endif; ?>
        </span>
        <span class="user-role <?php echo $is_admin ? 'admin' : ''; ?>">
            <?php echo strtoupper(htmlspecialchars($user['role'] ?? 'staff')); ?>
        </span>
        <form method="POST" style="display:inline;">
            <button type="submit" name="logout" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </form>
    </div>
    <?php endif; ?>
</nav>

<!-- ============================================================
MAIN CONTENT
============================================================ -->
<div class="container">

    <!-- ============================================================
    LOGIN PAGE
    ============================================================ -->
    <?php if ($page == 'login'): ?>
    <div class="auth-container">
        <div class="auth-header">
            <div class="logo"><i class="fas fa-pills"></i> MedStock</div>
            <h2>Welcome Back</h2>
            <p>Sign in to manage your inventory</p>
        </div>
        
        <?php if (isset($login_error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $login_error; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Username or Email</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" placeholder="Enter your username or email" required>
                </div>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('loginPassword')"></i>
                </div>
            </div>
            <button type="submit" name="login" class="btn btn-primary" style="width:100%; justify-content:center; padding:0.8rem;">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div style="text-align:center; margin-top:1.2rem;">
            Don't have an account? <a href="?page=register" style="color:#1a3d2f; font-weight:600;">Register here</a>
        </div>
        
        <div class="demo-box">
            <p><strong>Admin Credentials:</strong></p>
            <p><i class="fas fa-user"></i> Username: <strong>Admin</strong></p>
            <p><i class="fas fa-envelope"></i> Email: <strong>williamshishima@gmail.com</strong></p>
            <p><i class="fas fa-lock"></i> Password: <strong>admin1234</strong></p>
            <p style="color:#c0392b; font-size:0.75rem; margin-top:4px;">
                <i class="fas fa-info-circle"></i> Only ADMIN can add/update/delete stock
            </p>
        </div>
        
        <div class="footer-text">
            <i class="fas fa-shield-alt"></i> Secured with password hashing
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
    REGISTER PAGE
    ============================================================ -->
    <?php if ($page == 'register'): ?>
    <div class="auth-container">
        <div class="auth-header">
            <div class="logo"><i class="fas fa-pills"></i> MedStock</div>
            <h2>Create Account</h2>
            <p>Join MedStock to manage your inventory</p>
        </div>
        
        <?php if (isset($register_error)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo $register_error; ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($register_success)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo $register_success; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" name="full_name" placeholder="Enter your full name" required>
                </div>
            </div>
            <div class="form-group">
                <label>Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user-tag"></i>
                    <input type="text" name="username" placeholder="Choose a username (min 3 chars)" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your email address" required>
                </div>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="regPassword" name="password" placeholder="Min 8 characters" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regPassword')"></i>
                </div>
                <div style="font-size:0.75rem; color:#4d7a62; margin-top:4px;">
                    <i class="fas fa-info-circle"></i> Must be at least 8 characters
                </div>
            </div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="regConfirmPassword" name="confirm_password" placeholder="Confirm your password" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('regConfirmPassword')"></i>
                </div>
            </div>
            <button type="submit" name="register" class="btn btn-secondary" style="width:100%; justify-content:center; padding:0.8rem;">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
        
        <div style="text-align:center; margin-top:1.2rem;">
            Already have an account? <a href="?page=login" style="color:#1a3d2f; font-weight:600;">Sign in here</a>
        </div>
        
        <div class="footer-text">
            <i class="fas fa-shield-alt"></i> Your data is secure and encrypted
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
    DASHBOARD PAGE
    ============================================================ -->
    <?php if ($page == 'dashboard' && SessionManager::isLoggedIn()): ?>
    <div class="page-header">
        <h1><i class="fas fa-chart-pie"></i> Dashboard</h1>
        <p>Real-time inventory overview and statistics</p>
    </div>
    
    <!-- Live Search Bar -->
    <div style="margin-bottom: 2rem;">
        <div class="live-search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="liveSearchInput" placeholder="Live search medicines by name or category..." autocomplete="off">
            <span class="search-clear" id="searchClear" onclick="clearLiveSearch()">
                <i class="fas fa-times-circle"></i>
            </span>
            <div class="live-search-results" id="liveSearchResults"></div>
        </div>
    </div>
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <i class="fas fa-prescription-bottle-alt"></i>
            <div class="stat-info">
                <h3>Total Medicines</h3>
                <div class="number"><?php echo $stats['total_medicines'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-check-circle"></i>
            <div class="stat-info">
                <h3>In Stock</h3>
                <div class="number" style="color:#27ae60;"><?php echo $stats['in_stock'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-exclamation-triangle"></i>
            <div class="stat-info">
                <h3>Low Stock</h3>
                <div class="number" style="color:#e67e22;"><?php echo $stats['low_stock'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-exclamation-circle"></i>
            <div class="stat-info">
                <h3>Critical</h3>
                <div class="number" style="color:#c0392b;"><?php echo $stats['critical'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-times-circle"></i>
            <div class="stat-info">
                <h3>Out of Stock</h3>
                <div class="number" style="color:#c0392b;"><?php echo $stats['out_of_stock'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-money-bill-wave"></i>
            <div class="stat-info">
                <h3>Total Value</h3>
                <div class="number" style="color:#3498db;">TSh <?php echo number_format($stats['total_value'] ?? 0, 0); ?></div>
            </div>
        </div>
    </div>
    
    <!-- Low Stock Alert -->
    <?php if (!empty($low_stock_items)): ?>
    <div class="alert alert-danger" style="margin-bottom:1.5rem;">
        <i class="fas fa-bell"></i>
        <strong>Alert!</strong> The following items need restocking:
        <?php 
        $alert_items = [];
        foreach ($low_stock_items as $item) {
            $alert_items[] = $item['name'] . ' (' . $item['stock'] . ' units)';
        }
        echo implode(', ', $alert_items);
        ?>
    </div>
    <?php endif; ?>
    
    <!-- Recent Transactions -->
    <div class="table-wrapper">
        <div class="table-header">
            <h2><i class="fas fa-clock"></i> Recent Transactions</h2>
        </div>
        <?php if (!empty($recent_transactions)): ?>
        <div class="transactions-grid">
            <?php foreach ($recent_transactions as $trans): ?>
            <div class="transaction-card">
                <div class="med-name">
                    <?php echo htmlspecialchars($trans['medicine_name']); ?>
                    <?php if ($trans['role'] === 'admin'): ?>
                    <span class="admin-tag"><i class="fas fa-crown"></i> Admin</span>
                    <?php endif; ?>
                </div>
                <div class="details">
                    <strong><?php echo ucfirst($trans['transaction_type']); ?></strong> 
                    <?php echo $trans['quantity']; ?> units 
                    (<?php echo $trans['previous_stock']; ?> → <?php echo $trans['new_stock']; ?>)
                </div>
                <div class="time">
                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($trans['username']); ?> 
                    · <?php echo date('M d, H:i', strtotime($trans['created_at'])); ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="color:#4d7a62; padding:1rem; text-align:center;">No transactions yet.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
    INVENTORY PAGE
    ============================================================ -->
    <?php if ($page == 'inventory' && SessionManager::isLoggedIn()): ?>
    <div class="page-header">
        <h1><i class="fas fa-boxes"></i> Inventory Management</h1>
        <p>View, search, and manage all medicines</p>
    </div>
    
    <?php if (!$is_admin): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> 
        You are viewing medicines. Only <strong>Administrators</strong> can add, edit, or delete medicines.
    </div>
    <?php endif; ?>
    
    <div class="table-wrapper">
        <div class="table-header">
            <h2><i class="fas fa-list-ul"></i> All Medicines</h2>
            <div class="filter-group">
                <input type="text" id="inventorySearch" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                <select id="categoryFilter">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat['category']); ?>" <?php echo $category_filter == $cat['category'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['category']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button onclick="applyFilters()"><i class="fas fa-search"></i> Filter</button>
                <button onclick="clearFilters()" class="btn btn-secondary" style="background:#e8f0ec; border:2px solid #dce8e0;">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Category</th>
                    <th>Stock</th>
                    <th>Price (TSh)</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($medicines)): ?>
                <?php foreach ($medicines as $item): 
                    $status = Inventory::getStatus($item['stock']);
                    $rowClass = $status['class'] == 'danger' ? 'row-critical' : ($status['class'] == 'warning' ? 'row-low' : '');
                ?>
                <tr class="<?php echo $rowClass; ?>" data-id="<?php echo $item['id']; ?>">
                    <td><i class="fas fa-capsules" style="color:#1a3d2f; margin-right:8px;"></i> <?php echo htmlspecialchars($item['name']); ?></td>
                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                    <td><strong><?php echo $item['stock']; ?></strong></td>
                    <td>TSh <?php echo number_format($item['price'] ?? 0, 0); ?></td>
                    <td><?php echo htmlspecialchars($item['supplier'] ?? '-'); ?></td>
                    <td><span class="status-badge <?php echo $status['class']; ?>"><i class="fas <?php echo $status['icon']; ?>"></i> <?php echo $status['label']; ?></span></td>
                    <td class="action-icons">
                        <i class="fas fa-eye" onclick="viewMedicine(<?php echo $item['id']; ?>)" title="View"></i>
                        <?php if ($is_admin): ?>
                        <i class="fas fa-edit" onclick="editMedicine(<?php echo $item['id']; ?>)" title="Edit"></i>
                        <i class="fas fa-arrow-up" onclick="restockMedicine(<?php echo $item['id']; ?>)" title="Restock"></i>
                        <i class="fas fa-trash danger" onclick="deleteMedicine(<?php echo $item['id']; ?>)" title="Delete"></i>
                        <?php else: ?>
                        <i class="fas fa-edit disabled" title="Only Admin can edit"></i>
                        <i class="fas fa-arrow-up disabled" title="Only Admin can restock"></i>
                        <i class="fas fa-trash danger disabled" title="Only Admin can delete"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" style="text-align:center; padding:2rem; color:#4d7a62;">
                        <i class="fas fa-inbox" style="font-size:2rem; display:block; margin-bottom:0.5rem;"></i>
                        No medicines found. 
                        <?php if ($is_admin): ?>
                        Click <a href="?page=add" style="color:#1a3d2f; font-weight:600;">Add</a> to add your first medicine.
                        <?php else: ?>
                        Contact an administrator to add medicines.
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <div style="padding: 0.8rem 0.5rem 0.2rem; color:#4d7a62; font-size:0.8rem; border-top: 1px solid #e8f0ec; margin-top:0.5rem;">
            <i class="fas fa-circle-info"></i> Showing <?php echo count($medicines); ?> medicines
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================
    ADD MEDICINE PAGE (ADMIN ONLY)
    ============================================================ -->
    <?php if ($page == 'add' && SessionManager::isLoggedIn()): ?>
    <?php if ($is_admin): ?>
    <div class="page-header">
        <h1><i class="fas fa-plus-circle"></i> Add New Medicine</h1>
        <p>Enter the details of the new medicine (Admin Only)</p>
    </div>
    
    <?php if (isset($add_success)): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $add_success; ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($add_error)): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-circle"></i> <?php echo $add_error; ?>
    </div>
    <?php endif; ?>
    
    <div class="form-container">
        <form method="POST" id="addMedicineForm">
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Medicine Name <span style="color:#c0392b;">*</span></label>
                    <input type="text" id="name" name="name" placeholder="e.g. Ibuprofen 400mg" required>
                </div>
                <div class="form-group">
                    <label for="category">Category <span style="color:#c0392b;">*</span></label>
                    <input type="text" id="category" name="category" placeholder="e.g. Anti-inflammatory" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="stock">Stock Quantity <span style="color:#c0392b;">*</span></label>
                    <input type="number" id="stock" name="stock" placeholder="0" min="0" required>
                </div>
                <div class="form-group">
                    <label for="price">Price (TSh)</label>
                    <input type="number" id="price" name="price" placeholder="0" min="0" step="1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="supplier">Supplier</label>
                    <input type="text" id="supplier" name="supplier" placeholder="Supplier name">
                </div>
                <div class="form-group">
                    <label for="expiry_date">Expiry Date</label>
                    <input type="date" id="expiry_date" name="expiry_date">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="reorder_level">Reorder Level</label>
                    <input type="number" id="reorder_level" name="reorder_level" placeholder="10" value="10" min="1">
                </div>
                <div class="form-group">
                    <label for="critical_level">Critical Level</label>
                    <input type="number" id="critical_level" name="critical_level" placeholder="5" value="5" min="1">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <button type="submit" name="add_medicine" class="btn btn-primary" style="width:100%; justify-content:center;">
                        <i class="fas fa-save"></i> Add Medicine
                    </button>
                </div>
                <div class="form-group" style="display:flex; align-items:flex-end;">
                    <a href="?page=inventory" class="btn btn-secondary" style="width:100%; justify-content:center; text-decoration:none;">
                        <i class="fas fa-arrow-left"></i> Back to Inventory
                    </a>
                </div>
            </div>
        </form>
        
        <div style="margin-top:1.5rem; background:#f5f0d7; padding:0.8rem 1.2rem; border-radius:12px; border-left:4px solid #f5c542;">
            <i class="fas fa-info-circle" style="color:#1a3d2f;"></i> 
            Fields marked with <span style="color:#c0392b;">*</span> are required.
        </div>
    </div>
    <?php else: ?>
    <div class="access-denied">
        <i class="fas fa-lock"></i>
        <div>
            <strong>Access Denied!</strong><br>
            Only administrators can add medicines. Please contact your system administrator.
        </div>
    </div>
    <div style="text-align:center; margin-top:1rem;">
        <a href="?page=inventory" class="btn btn-primary">
            <i class="fas fa-arrow-left"></i> Back to Inventory
        </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- ============================================================
    REPORTS PAGE
    ============================================================ -->
    <?php if ($page == 'reports' && SessionManager::isLoggedIn()): ?>
    <div class="page-header">
        <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
        <p>View stock insights and generate reports</p>
    </div>
    
    <div class="stats-grid" style="margin-bottom:1.5rem;">
        <div class="stat-card">
            <i class="fas fa-boxes"></i>
            <div class="stat-info">
                <h3>Total Items</h3>
                <div class="number"><?php echo $stats['total_medicines'] ?? 0; ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-box"></i>
            <div class="stat-info">
                <h3>Total Units</h3>
                <div class="number"><?php echo number_format($stats['total_units'] ?? 0); ?></div>
            </div>
        </div>
        <div class="stat-card">
            <i class="fas fa-money-bill-wave"></i>
            <div class="stat-info">
                <h3>Inventory Value</h3>
                <div class="number" style="color:#3498db;">TSh <?php echo number_format($stats['total_value'] ?? 0, 0); ?></div>
            </div>
        </div>
    </div>
    
    <div class="table-wrapper">
        <div class="table-header">
            <h2><i class="fas fa-file-alt"></i> Stock Status Summary</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Count</th>
                    <th>Percentage</th>
                    <th>Action Needed</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total = $stats['total_medicines'] ?? 1;
                $statuses = [
                    ['label' => 'In Stock', 'count' => $stats['in_stock'] ?? 0, 'color' => '#27ae60', 'action' => 'Ready to dispense'],
                    ['label' => 'Low Stock', 'count' => $stats['low_stock'] ?? 0, 'color' => '#e67e22', 'action' => 'Order soon'],
                    ['label' => 'Critical', 'count' => $stats['critical'] ?? 0, 'color' => '#c0392b', 'action' => 'Immediate reorder'],
                    ['label' => 'Out of Stock', 'count' => $stats['out_of_stock'] ?? 0, 'color' => '#c0392b', 'action' => 'Emergency restock']
                ];
                foreach ($statuses as $status):
                    $percentage = $total > 0 ? round(($status['count'] / $total) * 100, 1) : 0;
                ?>
                <tr>
                    <td><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?php echo $status['color']; ?>; margin-right:8px;"></span> <?php echo $status['label']; ?></td>
                    <td><strong><?php echo $status['count']; ?></strong></td>
                    <td>
                        <div style="background:#e8f0ec; border-radius:10px; height:20px; width:150px; overflow:hidden;">
                            <div style="background:<?php echo $status['color']; ?>; height:100%; width:<?php echo $percentage; ?>%; transition:width 0.5s;"></div>
                        </div>
                        <span style="font-size:0.8rem; color:#4d7a62; margin-left:8px;"><?php echo $percentage; ?>%</span>
                    </td>
                    <td><?php echo $status['action']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <div style="display:flex; gap:1rem; flex-wrap:wrap; margin-top:1rem;">
        <button onclick="exportCSV()" class="btn btn-secondary">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <button onclick="printReport()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>
    <?php endif; ?>

    <!-- ============================================================
    SETTINGS PAGE
    ============================================================ -->
    <?php if ($page == 'settings' && SessionManager::isLoggedIn()): ?>
    <div class="page-header">
        <h1><i class="fas fa-cog"></i> Settings</h1>
        <p>Configure system settings and preferences</p>
    </div>
    
    <div class="form-container">
        <div style="margin-bottom:2rem;">
            <h3><i class="fas fa-user-shield"></i> User Information</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Username</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;"><?php echo htmlspecialchars($user['username'] ?? ''); ?></div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Full Name</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;"><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Email</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Role</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;">
                        <span class="status-badge" style="background:<?php echo $is_admin ? '#f5c542' : '#d5f0e0'; ?>; color:<?php echo $is_admin ? '#1a3d2f' : '#1a7a42'; ?>;">
                            <?php echo strtoupper(htmlspecialchars($user['role'] ?? 'staff')); ?>
                            <?php if ($is_admin): ?>
                            <i class="fas fa-crown"></i>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <div style="border-top:1px solid #e8f0ec; padding-top:2rem;">
            <h3><i class="fas fa-bell"></i> Notification Settings</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                <div class="alert alert-warning" style="margin:0;">
                    <i class="fas fa-info-circle"></i> Low Stock Alert: <strong>10 units</strong>
                </div>
                <div class="alert alert-danger" style="margin:0;">
                    <i class="fas fa-exclamation-circle"></i> Critical Alert: <strong>5 units</strong>
                </div>
            </div>
        </div>
        
        <div style="border-top:1px solid #e8f0ec; padding-top:2rem; margin-top:1rem;">
            <h3><i class="fas fa-database"></i> System Information</h3>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:1rem;">
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Database</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;">
                        <i class="fas fa-check-circle" style="color:#27ae60;"></i> Connected
                    </div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Total Users</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;">
                        <?php
                        try {
                            $db = Database::getInstance();
                            $stmt = $db->prepare("SELECT COUNT(*) as count FROM users");
                            $stmt->execute();
                            echo $stmt->fetch()['count'];
                        } catch (Exception $e) {
                            echo '0';
                        }
                        ?>
                    </div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Currency</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;">
                        <i class="fas fa-money-bill-wave" style="color:#f5c542;"></i> TSh (Tanzanian Shillings)
                    </div>
                </div>
                <div>
                    <label style="font-weight:600; color:#4d7a62;">Admin Access</label>
                    <div style="padding:0.5rem 1rem; background:#f8fbfa; border-radius:12px;">
                        <?php if ($is_admin): ?>
                        <i class="fas fa-check-circle" style="color:#27ae60;"></i> Full Access
                        <?php else: ?>
                        <i class="fas fa-info-circle" style="color:#e67e22;"></i> Read Only
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ============================================================
FOOTER
============================================================ -->
<div style="text-align:center; padding:1.5rem; color:#4d7a62; border-top:1px solid #e8f0ec; margin-top:2rem;">
    <i class="fas fa-copyright"></i> 2026 MedStock · Professional Inventory System
    <?php if (SessionManager::isLoggedIn()): ?>
    · <i class="fas fa-user"></i> <?php echo htmlspecialchars($user['full_name'] ?? $user['username'] ?? ''); ?>
    <?php endif; ?>
    · <i class="fas fa-money-bill-wave" style="color:#f5c542;"></i> TSh
</div>

<!-- ============================================================
JAVASCRIPT
============================================================ -->
<script>
// ============================================================
// TOGGLE PASSWORD
// ============================================================
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.parentElement.querySelector('.toggle-password');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// ============================================================
// TOAST NOTIFICATION
// ============================================================
function showToast(message, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = message;
    toast.className = 'toast toast-' + type + ' show';
    setTimeout(() => {
        toast.classList.remove('show');
    }, 4000);
}

// ============================================================
// INVENTORY FILTERS
// ============================================================
function applyFilters() {
    const search = document.getElementById('inventorySearch').value;
    const category = document.getElementById('categoryFilter').value;
    let url = '?page=inventory';
    if (search) url += '&search=' + encodeURIComponent(search);
    if (category) url += '&category=' + encodeURIComponent(category);
    window.location.href = url;
}

function clearFilters() {
    window.location.href = '?page=inventory';
}

// Enter key for search
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('inventorySearch');
    if (searchInput) {
        searchInput.addEventListener('keyup', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    }
});

// ============================================================
// LIVE SEARCH
// ============================================================
let searchTimeout;

function clearLiveSearch() {
    document.getElementById('liveSearchInput').value = '';
    document.getElementById('liveSearchResults').classList.remove('show');
    document.getElementById('searchClear').classList.remove('show');
}

document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('liveSearchInput');
    const resultsDiv = document.getElementById('liveSearchResults');
    const clearBtn = document.getElementById('searchClear');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            if (query.length > 0) {
                clearBtn.classList.add('show');
            } else {
                clearBtn.classList.remove('show');
                resultsDiv.classList.remove('show');
                return;
            }
            
            // Show loading
            resultsDiv.innerHTML = '<div class="search-loading"><i class="fas fa-spinner fa-spin"></i> Searching...</div>';
            resultsDiv.classList.add('show');
            
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                fetch('?action=live_search&q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.data.length > 0) {
                            let html = '';
                            data.data.forEach(item => {
                                const status = getStockStatus(item.stock);
                                html += `
                                    <div class="search-item" onclick="selectMedicine(${item.id})">
                                        <div>
                                            <div class="item-name">${escapeHtml(item.name)}</div>
                                            <div class="item-details">
                                                ${escapeHtml(item.category)} · 
                                                ${escapeHtml(item.supplier || 'No supplier')} · 
                                                TSh ${parseInt(item.price || 0).toLocaleString()}
                                            </div>
                                        </div>
                                        <div class="item-stock ${status.class}">
                                            ${item.stock} units
                                        </div>
                                    </div>
                                `;
                            });
                            resultsDiv.innerHTML = html;
                        } else {
                            resultsDiv.innerHTML = `
                                <div class="search-empty">
                                    <i class="fas fa-search" style="font-size:1.5rem; display:block; margin-bottom:0.5rem;"></i>
                                    No medicines found for "${escapeHtml(query)}"
                                </div>
                            `;
                        }
                    })
                    .catch(() => {
                        resultsDiv.innerHTML = '<div class="search-empty">Error searching. Please try again.</div>';
                    });
            }, 300);
        });
        
        // Close search results on outside click
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
                resultsDiv.classList.remove('show');
            }
        });
    }
});

function getStockStatus(stock) {
    if (stock <= 0) return { class: 'critical' };
    if (stock <= 5) return { class: 'critical' };
    if (stock <= 10) return { class: 'low' };
    return { class: 'in-stock' };
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function selectMedicine(id) {
    document.getElementById('liveSearchResults').classList.remove('show');
    document.getElementById('searchClear').classList.remove('show');
    viewMedicine(id);
}

// ============================================================
// MEDICINE OPERATIONS (AJAX)
// ============================================================
function viewMedicine(id) {
    fetch('?action=get_medicine&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const m = data.data;
                alert(
                    '📋 Medicine Details\n\n' +
                    'Name: ' + m.name + '\n' +
                    'Category: ' + m.category + '\n' +
                    'Stock: ' + m.stock + ' units\n' +
                    'Price: TSh ' + parseInt(m.price || 0).toLocaleString() + '\n' +
                    'Supplier: ' + (m.supplier || 'N/A') + '\n' +
                    'Expiry: ' + (m.expiry_date || 'N/A') + '\n' +
                    'Reorder Level: ' + m.reorder_level + '\n' +
                    'Critical Level: ' + m.critical_level
                );
            } else {
                showToast('Medicine not found', 'error');
            }
        })
        .catch(error => showToast('Error loading medicine', 'error'));
}

function editMedicine(id) {
    <?php if (!$is_admin): ?>
    showToast('Only administrators can edit medicines', 'error');
    return;
    <?php endif; ?>
    
    fetch('?action=get_medicine&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const m = data.data;
                const name = prompt('Medicine name:', m.name);
                if (name === null) return;
                const category = prompt('Category:', m.category);
                if (category === null) return;
                const stock = prompt('Stock quantity:', m.stock);
                if (stock === null) return;
                const price = prompt('Price (TSh):', m.price || '0');
                if (price === null) return;
                
                const formData = new FormData();
                formData.append('id', m.id);
                formData.append('name', name.trim() || m.name);
                formData.append('category', category.trim() || m.category);
                formData.append('stock', parseInt(stock) || 0);
                formData.append('price', parseInt(price) || 0);
                formData.append('supplier', m.supplier || '');
                formData.append('expiry_date', m.expiry_date || '');
                formData.append('reorder_level', m.reorder_level || 10);
                formData.append('critical_level', m.critical_level || 5);
                
                fetch('?action=update_medicine', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showToast('✅ Medicine updated successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Error: ' + (result.message || 'Update failed'), 'error');
                    }
                });
            } else {
                showToast('Medicine not found', 'error');
            }
        })
        .catch(error => showToast('Error loading medicine', 'error'));
}

function restockMedicine(id) {
    <?php if (!$is_admin): ?>
    showToast('Only administrators can restock medicines', 'error');
    return;
    <?php endif; ?>
    
    fetch('?action=get_medicine&id=' + id)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                const m = data.data;
                const newStock = prompt(
                    'Restock ' + m.name + '\nCurrent stock: ' + m.stock + '\n\nEnter new stock quantity:',
                    parseInt(m.stock) + 10
                );
                if (newStock === null) return;
                
                const stockNum = parseInt(newStock);
                if (isNaN(stockNum) || stockNum < 0) {
                    showToast('Invalid stock quantity', 'error');
                    return;
                }
                
                const formData = new FormData();
                formData.append('id', m.id);
                formData.append('name', m.name);
                formData.append('category', m.category);
                formData.append('stock', stockNum);
                formData.append('price', m.price || 0);
                formData.append('supplier', m.supplier || '');
                formData.append('expiry_date', m.expiry_date || '');
                formData.append('reorder_level', m.reorder_level || 10);
                formData.append('critical_level', m.critical_level || 5);
                
                fetch('?action=update_medicine', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showToast('✅ Restocked ' + m.name + ' to ' + stockNum + ' units', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showToast('Error: ' + (result.message || 'Restock failed'), 'error');
                    }
                });
            } else {
                showToast('Medicine not found', 'error');
            }
        })
        .catch(error => showToast('Error loading medicine', 'error'));
}

function deleteMedicine(id) {
    <?php if (!$is_admin): ?>
    showToast('Only administrators can delete medicines', 'error');
    return;
    <?php endif; ?>
    
    if (!confirm('⚠️ Are you sure you want to delete this medicine? This action cannot be undone!')) return;
    
    const formData = new FormData();
    formData.append('id', id);
    
    fetch('?action=delete_medicine', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            showToast('🗑️ Medicine deleted successfully', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast('Error: ' + (result.message || 'Delete failed'), 'error');
        }
    })
    .catch(error => showToast('Error deleting medicine', 'error'));
}

// ============================================================
// EXPORT CSV
// ============================================================
function exportCSV() {
    window.location.href = '?action=export_csv';
}

// ============================================================
// PRINT REPORT
// ============================================================
function printReport() {
    window.print();
}

// ============================================================
// AUTO-REFRESH STATS (every 30 seconds)
// ============================================================
if (document.querySelector('.stat-card')) {
    setInterval(() => {
        fetch('?action=get_stats')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data) {
                    const stats = data.data;
                    const cards = document.querySelectorAll('.stat-card');
                    if (cards.length >= 6) {
                        cards[0].querySelector('.number').textContent = stats.total_medicines || 0;
                        cards[1].querySelector('.number').textContent = stats.in_stock || 0;
                        cards[2].querySelector('.number').textContent = stats.low_stock || 0;
                        cards[3].querySelector('.number').textContent = stats.critical || 0;
                        cards[4].querySelector('.number').textContent = stats.out_of_stock || 0;
                        cards[5].querySelector('.number').textContent = 'TSh ' + (stats.total_value || 0).toLocaleString();
                    }
                }
            })
            .catch(() => {});
    }, 30000);
}
</script>

</body>
</html>