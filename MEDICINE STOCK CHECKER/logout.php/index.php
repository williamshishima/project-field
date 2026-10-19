<?php
require_once 'config/database.php';
require_once 'config/session.php';

SessionManager::start();

// Check if user is logged in
if (!SessionManager::isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user = SessionManager::getUser();
$user_id = SessionManager::getUserId();
$user_role = SessionManager::getUserRole();

// Get statistics
$db = Database::getInstance();

// Get total medicines
$totalStmt = $db->prepare("SELECT COUNT(*) as total FROM medicines");
$totalStmt->execute();
$totalMedicines = $totalStmt->fetch()['total'];

// Get low stock count
$lowStmt = $db->prepare("SELECT COUNT(*) as count FROM medicines WHERE stock < reorder_level AND stock > 0");
$lowStmt->execute();
$lowStock = $lowStmt->fetch()['count'];

// Get critical stock count
$criticalStmt = $db->prepare("SELECT COUNT(*) as count FROM medicines WHERE stock <= critical_level AND stock > 0");
$criticalStmt->execute();
$criticalStock = $criticalStmt->fetch()['count'];

// Get out of stock count
$outStmt = $db->prepare("SELECT COUNT(*) as count FROM medicines WHERE stock = 0");
$outStmt->execute();
$outOfStock = $outStmt->fetch()['count'];

// Get in stock count
$inStock = $totalMedicines - ($lowStock + $criticalStock + $outOfStock);

// Get recent transactions
$transStmt = $db->prepare("
    SELECT t.*, m.name as medicine_name, u.username 
    FROM stock_transactions t 
    JOIN medicines m ON t.medicine_id = m.id 
    JOIN users u ON t.user_id = u.id 
    ORDER BY t.created_at DESC LIMIT 10
");
$transStmt->execute();
$recentTransactions = $transStmt->fetchAll();

// Get medicines for dashboard
$medStmt = $db->prepare("SELECT * FROM medicines ORDER BY created_at DESC LIMIT 50");
$medStmt->execute();
$medicines = $medStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedStock · Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Include all styles from the original file */
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Roboto, system-ui, sans-serif; }
        body { background: #f4f9fc; min-height: 100vh; display: flex; flex-direction: column; }
        
        .navbar {
            background: #1f4f3b;
            color: white;
            padding: 1rem 2rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 12px rgba(0, 30, 20, 0.25);
            border-bottom: 4px solid #f5c542;
        }
        
        .logo {
            font-size: 1.6rem;
            font-weight: 600;
            letter-spacing: 1px;
        }
        
        .logo i { margin-right: 10px; color: #f5c542; }
        
        .nav-links {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .nav-links a {
            color: #e3f0e8;
            text-decoration: none;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            transition: 0.2s;
            background: rgba(255, 255, 255, 0.05);
            font-size: 0.95rem;
            cursor: pointer;
        }
        
        .nav-links a i { margin-right: 6px; }
        .nav-links a:hover { background: #2e7a5a; color: white; }
        .nav-links a.active { background: #f5c542; color: #1f4f3b; box-shadow: 0 0 0 2px #f5d97a; font-weight: 600; }
        
        .user-info {
            color: #e3f0e8;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .user-info .user-name {
            font-weight: 500;
        }
        
        .user-info .user-role {
            background: rgba(245, 197, 66, 0.2);
            padding: 0.2rem 0.8rem;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            border: none;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .logout-btn:hover {
            background: #c0392b;
        }
        
        .container {
            max-width: 1300px;
            margin: 2rem auto;
            padding: 0 1.5rem;
            flex: 1;
            width: 100%;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-header h1 {
            font-size: 2.2rem;
            font-weight: 600;
            color: #1f4f3b;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i { color: #f5c542; font-size: 2rem; }
        .page-header p { color: #2a5b4a; margin-top: 6px; font-size: 1.1rem; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem 1.2rem;
            border-radius: 24px;
            box-shadow: 0 6px 18px rgba(0, 40, 30, 0.06);
            transition: 0.2s;
            border-left: 8px solid #1f4f3b;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }
        
        .stat-card:nth-child(2) { border-left-color: #f5c542; }
        .stat-card:nth-child(3) { border-left-color: #d48c2c; }
        .stat-card:nth-child(4) { border-left-color: #2f8f5a; }
        .stat-card:nth-child(5) { border-left-color: #c0392b; }
        
        .stat-card i {
            font-size: 2.4rem;
            color: #1f4f3b;
            width: 3rem;
            text-align: center;
        }
        
        .stat-card:nth-child(2) i { color: #c99a2b; }
        .stat-card:nth-child(3) i { color: #b87a1f; }
        .stat-card:nth-child(4) i { color: #268f4e; }
        .stat-card:nth-child(5) i { color: #c0392b; }
        
        .stat-info h3 {
            font-size: 0.9rem;
            font-weight: 500;
            color: #2d5e48;
            letter-spacing: 0.3px;
        }
        
        .stat-info .number {
            font-size: 1.9rem;
            font-weight: 700;
            color: #1f4f3b;
            line-height: 1.2;
        }
        
        .table-wrapper {
            background: white;
            border-radius: 28px;
            padding: 1.5rem 1rem 1rem 1rem;
            box-shadow: 0 8px 28px rgba(0, 30, 20, 0.05);
            border: 1px solid #d4e8dd;
            overflow-x: auto;
            margin-bottom: 2rem;
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
            font-size: 1.4rem;
            font-weight: 600;
            color: #1f4f3b;
        }
        
        .table-header h2 i { color: #f5c542; margin-right: 8px; }
        
        .filter-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .filter-group input {
            padding: 0.5rem 1rem;
            border: 2px solid #c8ddd0;
            border-radius: 40px;
            background: #fafffd;
            font-size: 0.9rem;
            outline: none;
            transition: 0.2s;
            width: 180px;
        }
        
        .filter-group input:focus {
            border-color: #1f4f3b;
            box-shadow: 0 0 0 3px rgba(31, 79, 59, 0.15);
        }
        
        .filter-group button {
            background: #f5c542;
            border: none;
            color: #1f4f3b;
            padding: 0.5rem 1.2rem;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 2px solid #f5c542;
        }
        
        .filter-group button:hover {
            background: #e6b53a;
            border-color: #d9a531;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        
        th {
            text-align: left;
            padding: 1rem 0.5rem 0.8rem 0.5rem;
            color: #1f4f3b;
            font-weight: 600;
            border-bottom: 2px solid #c8ddd0;
            letter-spacing: 0.3px;
        }
        
        td {
            padding: 0.9rem 0.5rem;
            border-bottom: 1px solid #d4e8dd;
            color: #1d3f30;
        }
        
        tr:last-child td { border-bottom: none; }
        
        .status-badge {
            display: inline-block;
            padding: 0.2rem 0.9rem;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 600;
            background: #d4e8dd;
            color: #1f4f3b;
        }
        
        .status-badge.low { background: #fcf1d6; color: #8f6d1f; }
        .status-badge.critical { background: #fad1c4; color: #a84a1a; }
        .status-badge.in-stock { background: #cfe8d6; color: #1a6b3a; }
        .status-badge.out-stock { background: #fad1c4; color: #a84a1a; }
        
        .action-icons i {
            margin: 0 6px;
            color: #3d7b62;
            cursor: pointer;
            opacity: 0.7;
            transition: 0.15s;
            font-size: 1.1rem;
        }
        
        .action-icons i:hover { opacity: 1; color: #1f4f3b; }
        
        tr.low-stock td { background-color: #fffae6; }
        tr.critical-stock td { background-color: #ffefeb; }
        
        .footer-note {
            margin-top: 2.5rem;
            text-align: center;
            color: #2d5e48;
            padding: 0.5rem;
            font-size: 0.9rem;
            border-top: 1px solid #c8ddd0;
            padding-top: 1.8rem;
        }
        
        @media (max-width: 700px) {
            .navbar { flex-direction: column; align-items: stretch; gap: 0.8rem; }
            .nav-links { justify-content: center; }
            .page-header h1 { font-size: 1.8rem; }
            .filter-group input { width: 140px; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="logo">
            <i class="fas fa-pills"></i> MedStock
        </div>
        <div class="nav-links">
            <a class="nav-link active" data-page="dashboard"><i class="fas fa-home"></i> Dashboard</a>
            <a class="nav-link" data-page="inventory"><i class="fas fa-boxes"></i> Inventory</a>
            <a class="nav-link" data-page="add"><i class="fas fa-plus-circle"></i> Add</a>
            <a class="nav-link" data-page="reports"><i class="fas fa-chart-line"></i> Reports</a>
            <a class="nav-link" data-page="settings"><i class="fas fa-cog"></i> Settings</a>
            <div class="user-info">
                <span class="user-name"><i class="fas fa-user"></i> <?php echo htmlspecialchars($user['full_name'] ?? $user['username']); ?></span>
                <span class="user-role"><?php echo htmlspecialchars($user['role']); ?></span>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </nav>
    
    <div class="container">
        <div id="page-dashboard" class="page-panel active-page">
            <div class="page-header">
                <h1><i class="fas fa-capsules"></i> Medicine Stock Dashboard</h1>
                <p><i class="fas fa-arrow-right" style="color:#1f4f3b;"></i> Real‑time inventory management</p>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <i class="fas fa-prescription-bottle-alt"></i>
                    <div class="stat-info">
                        <h3>Total Medicines</h3>
                        <div class="number"><?php echo $totalMedicines; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div class="stat-info">
                        <h3>Low Stock</h3>
                        <div class="number" style="color:#a87d1f;"><?php echo $lowStock; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-circle-exclamation"></i>
                    <div class="stat-info">
                        <h3>Critical</h3>
                        <div class="number" style="color:#a84a1a;"><?php echo $criticalStock; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-check-circle"></i>
                    <div class="stat-info">
                        <h3>In Stock</h3>
                        <div class="number" style="color:#1a7a42;"><?php echo $inStock; ?></div>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-times-circle"></i>
                    <div class="stat-info">
                        <h3>Out of Stock</h3>
                        <div class="number" style="color:#c0392b;"><?php echo $outOfStock; ?></div>
                    </div>
                </div>
            </div>
            
            <div class="table-wrapper">
                <div class="table-header">
                    <h2><i class="fas fa-list-ul"></i> Current Stock</h2>
                    <div class="filter-group">
                        <input type="text" id="searchInput" placeholder="🔍 Search medicine...">
                        <button id="filterBtn"><i class="fas fa-filter"></i> Filter</button>
                    </div>
                </div>
                <table>
                    <thead>
                        <tr><th>Medicine</th><th>Category</th><th>Stock</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody id="medicineTableBody">
                        <?php foreach ($medicines as $item): 
                            $status = getStatus($item['stock']);
                            $rowClass = ($status['className'] === 'low') ? 'low-stock' : ($status['className'] === 'critical' ? 'critical-stock' : '');
                        ?>
                        <tr class="<?php echo $rowClass; ?>" data-id="<?php echo $item['id']; ?>">
                            <td><i class="fas fa-tablets" style="color:#1f4f3b; margin-right:10px;"></i> <?php echo htmlspecialchars($item['name']); ?></td>
                            <td><?php echo htmlspecialchars($item['category']); ?></td>
                            <td><?php echo $item['stock']; ?></td>
                            <td><span class="status-badge <?php echo $status['className']; ?>"><i class="fas <?php echo $status['icon']; ?>"></i> <?php echo $status['label']; ?></span></td>
                            <td class="action-icons">
                                <i class="fas fa-eye" onclick="viewMedicine(<?php echo $item['id']; ?>)"></i>
                                <i class="fas fa-edit" onclick="editMedicine(<?php echo $item['id']; ?>)"></i>
                                <i class="fas fa-arrow-up" onclick="restockMedicine(<?php echo $item['id']; ?>)"></i>
                                <i class="fas fa-trash" style="color:#c0392b;" onclick="deleteMedicine(<?php echo $item['id']; ?>)"></i>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="padding: 0.8rem 0.5rem 0.2rem; color:#3d6e57; font-size:0.8rem; border-top: 1px solid #d4e8dd; margin-top:0.5rem;">
                    <i class="fas fa-circle-info"></i> <span id="rowCountDisplay"><?php echo count($medicines); ?></span> medicines
                </div>
            </div>
        </div>
    </div>
    
    <div class="footer-note">
        <i class="fas fa-copyright"></i> 2026 MedStock · <i class="fas fa-pulse fa-circle" style="color:#f5c542;"></i> Professional Edition
    </div>
    
    <script>
        function getStatus(stock) {
            if (stock <= 0) return { label: 'Out of Stock', className: 'critical', icon: 'fa-times-circle' };
            if (stock < 5) return { label: 'Critical', className: 'critical', icon: 'fa-triangle-exclamation' };
            if (stock < 10) return { label: 'Low', className: 'low', icon: 'fa-exclamation-circle' };
            return { label: 'In Stock', className: 'in-stock', icon: 'fa-check-circle' };
        }
        
        // AJAX functions for CRUD operations
        async function viewMedicine(id) {
            try {
                const response = await fetch(`api.php?action=get&id=${id}`);
                const data = await response.json();
                if (data.success) {
                    alert(`📋 ${data.data.name}\nCategory: ${data.data.category}\nStock: ${data.data.stock} units`);
                }
            } catch (error) {
                alert('Error fetching medicine details');
            }
        }
        
        async function editMedicine(id) {
            try {
                const response = await fetch(`api.php?action=get&id=${id}`);
                const data = await response.json();
                if (!data.success) {
                    alert('Error fetching medicine data');
                    return;
                }
                
                const item = data.data;
                const newName = prompt('Medicine name:', item.name);
                if (newName === null) return;
                const newCategory = prompt('Category:', item.category);
                if (newCategory === null) return;
                const newStock = prompt('Stock quantity:', item.stock);
                if (newStock === null) return;
                
                const stockNum = parseInt(newStock);
                if (isNaN(stockNum) || stockNum < 0) {
                    alert('Invalid stock quantity');
                    return;
                }
                
                const updateResponse = await fetch('api.php?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: item.id,
                        name: newName.trim() || item.name,
                        category: newCategory.trim() || item.category,
                        stock: stockNum
                    })
                });
                const result = await updateResponse.json();
                if (result.success) {
                    alert('✅ Medicine updated successfully!');
                    location.reload();
                } else {
                    alert('Error updating medicine');
                }
            } catch (error) {
                alert('Error updating medicine');
            }
        }
        
        async function restockMedicine(id) {
            try {
                const response = await fetch(`api.php?action=get&id=${id}`);
                const data = await response.json();
                if (!data.success) {
                    alert('Error fetching medicine data');
                    return;
                }
                
                const item = data.data;
                const newStock = prompt(`Restock ${item.name} (current: ${item.stock}):`, item.stock + 10);
                if (newStock === null) return;
                
                const stockNum = parseInt(newStock);
                if (isNaN(stockNum) || stockNum < 0) {
                    alert('Invalid stock quantity');
                    return;
                }
                
                const updateResponse = await fetch('api.php?action=restock', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        id: item.id,
                        stock: stockNum
                    })
                });
                const result = await updateResponse.json();
                if (result.success) {
                    alert(`✅ Restocked ${item.name} to ${stockNum} units`);
                    location.reload();
                } else {
                    alert('Error restocking medicine');
                }
            } catch (error) {
                alert('Error restocking medicine');
            }
        }
        
        async function deleteMedicine(id) {
            if (!confirm('⚠️ Are you sure you want to delete this medicine?')) return;
            
            try {
                const response = await fetch('api.php?action=delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id })
                });
                const result = await response.json();
                if (result.success) {
                    alert('🗑️ Medicine deleted successfully');
                    location.reload();
                } else {
                    alert('Error deleting medicine');
                }
            } catch (error) {
                alert('Error deleting medicine');
            }
        }
        
        // Search functionality
        document.getElementById('filterBtn').addEventListener('click', function() {
            const search = document.getElementById('searchInput').value;
            if (search.trim()) {
                window.location.href = `index.php?search=${encodeURIComponent(search)}`;
            } else {
                window.location.href = 'index.php';
            }
        });
        
        document.getElementById('searchInput').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('filterBtn').click();
            }
        });
    </script>
</body>
</html>

<?php
function getStatus($stock) {
    if ($stock <= 0) return ['label' => 'Out of Stock', 'className' => 'critical', 'icon' => 'fa-times-circle'];
    if ($stock < 5) return ['label' => 'Critical', 'className' => 'critical', 'icon' => 'fa-triangle-exclamation'];
    if ($stock < 10) return ['label' => 'Low', 'className' => 'low', 'icon' => 'fa-exclamation-circle'];
    return ['label' => 'In Stock', 'className' => 'in-stock', 'icon' => 'fa-check-circle'];
}
?>