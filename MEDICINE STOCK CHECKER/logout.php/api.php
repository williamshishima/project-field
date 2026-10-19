<?php
require_once 'config/database.php';
require_once 'config/session.php';

SessionManager::start();

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = Database::getInstance();
    $user_id = SessionManager::getUserId();
    
    switch ($action) {
        case 'get':
            $id = $_GET['id'] ?? 0;
            $stmt = $db->prepare("SELECT * FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            $medicine = $stmt->fetch();
            
            if ($medicine) {
                echo json_encode(['success' => true, 'data' => $medicine]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Medicine not found']);
            }
            break;
            
        case 'add':
            $data = json_decode(file_get_contents('php://input'), true);
            $name = $data['name'] ?? '';
            $category = $data['category'] ?? '';
            $stock = $data['stock'] ?? 0;
            
            if (empty($name) || empty($category)) {
                echo json_encode(['success' => false, 'message' => 'Name and category are required']);
                break;
            }
            
            $stmt = $db->prepare("INSERT INTO medicines (name, category, stock, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $category, $stock, $user_id]);
            $id = $db->lastInsertId();
            
            // Log transaction
            $logStmt = $db->prepare("
                INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
                VALUES (?, ?, 'add', ?, 0, ?, 'Initial stock')
            ");
            $logStmt->execute([$id, $user_id, $stock, $stock]);
            
            echo json_encode(['success' => true, 'message' => 'Medicine added successfully', 'id' => $id]);
            break;
            
        case 'update':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $name = $data['name'] ?? '';
            $category = $data['category'] ?? '';
            $stock = $data['stock'] ?? 0;
            
            // Get current stock
            $currentStmt = $db->prepare("SELECT stock FROM medicines WHERE id = ?");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            $oldStock = $current['stock'] ?? 0;
            
            $stmt = $db->prepare("UPDATE medicines SET name = ?, category = ?, stock = ? WHERE id = ?");
            $stmt->execute([$name, $category, $stock, $id]);
            
            // Log transaction if stock changed
            if ($oldStock != $stock) {
                $logStmt = $db->prepare("
                    INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
                    VALUES (?, ?, 'adjust', ?, ?, ?, 'Stock updated')
                ");
                $logStmt->execute([$id, $user_id, abs($stock - $oldStock), $oldStock, $stock]);
            }
            
            echo json_encode(['success' => true, 'message' => 'Medicine updated successfully']);
            break;
            
        case 'restock':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            $stock = $data['stock'] ?? 0;
            
            // Get current stock
            $currentStmt = $db->prepare("SELECT stock FROM medicines WHERE id = ?");
            $currentStmt->execute([$id]);
            $current = $currentStmt->fetch();
            $oldStock = $current['stock'] ?? 0;
            
            $stmt = $db->prepare("UPDATE medicines SET stock = ? WHERE id = ?");
            $stmt->execute([$stock, $id]);
            
            // Log transaction
            $logStmt = $db->prepare("
                INSERT INTO stock_transactions (medicine_id, user_id, transaction_type, quantity, previous_stock, new_stock, notes) 
                VALUES (?, ?, 'restock', ?, ?, ?, 'Restocked')
            ");
            $logStmt->execute([$id, $user_id, abs($stock - $oldStock), $oldStock, $stock]);
            
            echo json_encode(['success' => true, 'message' => 'Medicine restocked successfully']);
            break;
            
        case 'delete':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $data['id'] ?? 0;
            
            $stmt = $db->prepare("DELETE FROM medicines WHERE id = ?");
            $stmt->execute([$id]);
            
            echo json_encode(['success' => true, 'message' => 'Medicine deleted successfully']);
            break;
            
        case 'search':
            $query = $_GET['q'] ?? '';
            $stmt = $db->prepare("SELECT * FROM medicines WHERE name LIKE ? OR category LIKE ? LIMIT 50");
            $searchTerm = "%$query%";
            $stmt->execute([$searchTerm, $searchTerm]);
            $results = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'data' => $results]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>