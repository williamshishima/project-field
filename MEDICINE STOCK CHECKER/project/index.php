<?php
// ============================================
// DATABASE CONNECTION
// ============================================
$host = 'localhost';
$user = 'root';
$pass = '';
$db = 'file_manager';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ============================================
// SESSION & VARIABLES
// ============================================
session_start();
$message = '';
$message_type = '';
$current_user = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? '';
$is_admin = ($_SESSION['role'] ?? '') === 'admin';

// ============================================
// AUTHENTICATION (Login/Register)
// ============================================
if (isset($_POST['register'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    $check = $conn->query("SELECT id FROM users WHERE username='$username'");
    if ($check->num_rows > 0) {
        $message = "Username already exists!";
        $message_type = 'error';
    } else {
        if ($conn->query("INSERT INTO users (username, password) VALUES ('$username', '$password')")) {
            $message = "Registration successful! Please login.";
            $message_type = 'success';
        } else {
            $message = "Registration failed!";
            $message_type = 'error';
        }
    }
}

if (isset($_POST['login'])) {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    
    $result = $conn->query("SELECT * FROM users WHERE username='$username'");
    if ($row = $result->fetch_assoc()) {
        if (password_verify($password, $row['password'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            header("Location: index.php");
            exit;
        } else {
            $message = "Invalid password!";
            $message_type = 'error';
        }
    } else {
        $message = "User not found!";
        $message_type = 'error';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ============================================
// FILE UPLOAD
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file']) && $current_user) {
    $file = $_FILES['file'];
    $filename = time() . '_' . basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    // Determine folder based on file type
    $folders = [
        'jpg' => 'images', 'jpeg' => 'images', 'png' => 'images', 'gif' => 'images',
        'pdf' => 'pdf',
        'doc' => 'documents', 'docx' => 'documents', 'txt' => 'documents',
        'mp4' => 'videos', 'avi' => 'videos', 'mov' => 'videos', 'mkv' => 'videos',
        'zip' => 'others', 'rar' => 'others', '7z' => 'others'
    ];
    $folder = $folders[$ext] ?? 'others';
    $upload_dir = "uploads/$folder/";
    
    // Create folder if not exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $filepath = $upload_dir . $filename;
    
    // Check file size (max 100MB)
    if ($file['size'] > 100 * 1024 * 1024) {
        $message = "File too large! Maximum 100MB allowed.";
        $message_type = 'error';
    } else {
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            $stmt = $conn->prepare("INSERT INTO files (user_id, filename, filepath, filetype, filesize) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issii", $current_user, $filename, $filepath, $ext, $file['size']);
            if ($stmt->execute()) {
                $message = "File uploaded successfully!";
                $message_type = 'success';
            } else {
                $message = "Database error!";
                $message_type = 'error';
            }
            $stmt->close();
        } else {
            $message = "Upload failed! Check folder permissions.";
            $message_type = 'error';
        }
    }
}

// ============================================
// DELETE FILE
// ============================================
if (isset($_GET['delete']) && ($is_admin || $current_user)) {
    $id = intval($_GET['delete']);
    $result = $conn->query("SELECT * FROM files WHERE id=$id");
    if ($row = $result->fetch_assoc()) {
        if ($is_admin || $row['user_id'] == $current_user) {
            if (file_exists($row['filepath'])) {
                unlink($row['filepath']);
            }
            $conn->query("DELETE FROM files WHERE id=$id");
            $message = "File deleted successfully!";
            $message_type = 'success';
        } else {
            $message = "Permission denied!";
            $message_type = 'error';
        }
    }
}

// ============================================
// DOWNLOAD FILE
// ============================================
if (isset($_GET['download'])) {
    $id = intval($_GET['download']);
    $result = $conn->query("SELECT * FROM files WHERE id=$id");
    if ($row = $result->fetch_assoc()) {
        $conn->query("UPDATE files SET downloads = downloads + 1 WHERE id=$id");
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $row['filename'] . '"');
        header('Content-Length: ' . filesize($row['filepath']));
        readfile($row['filepath']);
        exit;
    }
}

// ============================================
// SEARCH & PAGINATION
// ============================================
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';

// Build WHERE clause
$where = "";
if (!empty($search)) {
    $where = "WHERE filename LIKE '%$search%'";
}

// Admin can see all files, users see only their files
if (!$is_admin && $current_user) {
    $where .= ($where ? " AND" : " WHERE") . " user_id = $current_user";
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM files $where";
$count_result = $conn->query($count_query);
$total = $count_result->fetch_assoc()['total'];
$total_pages = ceil($total / $limit);

// Get files for current page
$query = "SELECT files.*, users.username FROM files 
          LEFT JOIN users ON files.user_id = users.id 
          $where ORDER BY upload_date DESC LIMIT $limit OFFSET $offset";
$files = $conn->query($query);
?>

<!DOCTYPE html>
<html lang="sw">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager Pro</title>
    <style>
        /* ============================================
           CSS - Modern Design
           ============================================ */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
            padding: 30px;
        }
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e7ff;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            color: #4a5568;
            font-size: 28px;
        }
        .header h1 span { color: #667eea; }
        .auth-buttons {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover { background: #5a67d8; transform: translateY(-2px); }
        .btn-success {
            background: #48bb78;
            color: white;
        }
        .btn-success:hover { background: #38a169; transform: translateY(-2px); }
        .btn-danger {
            background: #fc8181;
            color: white;
        }
        .btn-danger:hover { background: #f56565; transform: translateY(-2px); }
        .btn-info {
            background: #63b3ed;
            color: white;
        }
        .btn-info:hover { background: #4299e1; transform: translateY(-2px); }
        .btn-outline {
            background: transparent;
            border: 2px solid #667eea;
            color: #667eea;
        }
        .btn-outline:hover { background: #667eea; color: white; }
        .btn-warning {
            background: #ed8936;
            color: white;
        }
        .btn-warning:hover { background: #dd6b20; transform: translateY(-2px); }
        .btn-sm {
            padding: 5px 12px;
            font-size: 12px;
        }
        
        /* Upload Area */
        .upload-area {
            background: #f7fafc;
            padding: 30px;
            border-radius: 15px;
            border: 2px dashed #cbd5e0;
            margin-bottom: 30px;
            text-align: center;
        }
        .upload-area input[type="file"] {
            display: none;
        }
        .upload-area label {
            display: inline-block;
            padding: 15px 30px;
            background: white;
            border-radius: 10px;
            cursor: pointer;
            border: 2px solid #667eea;
            color: #667eea;
            font-weight: 600;
            transition: all 0.3s;
        }
        .upload-area label:hover {
            background: #667eea;
            color: white;
        }
        .upload-area .btn {
            margin-left: 10px;
        }
        
        /* Search */
        .search-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .search-bar form {
            flex: 1;
            display: flex;
            gap: 10px;
            min-width: 200px;
        }
        .search-bar input {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 16px;
            min-width: 150px;
        }
        .search-bar input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        /* Messages */
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 10px 0 20px 0;
            font-weight: 500;
        }
        .message.success {
            background: #c6f6d5;
            color: #22543d;
            border-left: 4px solid #48bb78;
        }
        .message.error {
            background: #fed7d7;
            color: #742a2a;
            border-left: 4px solid #fc8181;
        }
        
        /* File Grid */
        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .file-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.07);
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
            text-align: center;
        }
        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .file-icon {
            font-size: 48px;
            margin: 10px 0;
        }
        .file-name {
            font-weight: 600;
            color: #2d3748;
            margin: 8px 0;
            word-break: break-all;
            font-size: 14px;
        }
        .file-meta {
            color: #718096;
            font-size: 12px;
            margin: 5px 0;
            line-height: 1.6;
        }
        .file-actions {
            display: flex;
            gap: 5px;
            justify-content: center;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .file-actions .btn {
            padding: 5px 12px;
            font-size: 12px;
        }
        
        /* Preview Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.8);
            overflow: auto;
        }
        .modal-content {
            background: white;
            margin: 5% auto;
            padding: 30px;
            width: 90%;
            max-width: 800px;
            border-radius: 20px;
            position: relative;
        }
        .close {
            position: absolute;
            right: 20px;
            top: 10px;
            font-size: 35px;
            cursor: pointer;
            color: #a0aec0;
        }
        .close:hover { color: black; }
        .modal-preview {
            max-width: 100%;
            max-height: 60vh;
            display: block;
            margin: 20px auto;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        .pagination a, .pagination span {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            color: #4a5568;
            text-decoration: none;
            transition: all 0.3s;
        }
        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        /* Auth Modal */
        .auth-modal {
            display: none;
            position: fixed;
            z-index: 999;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }
        .auth-box {
            background: white;
            width: 400px;
            margin: 10% auto;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            position: relative;
        }
        .auth-box h2 {
            margin-bottom: 20px;
            color: #2d3748;
            text-align: center;
        }
        .auth-box input {
            width: 100%;
            padding: 12px;
            margin: 8px 0;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        .auth-box input:focus { outline: none; border-color: #667eea; }
        .auth-box .btn {
            width: 100%;
            margin-top: 10px;
        }
        .auth-close {
            float: right;
            font-size: 30px;
            cursor: pointer;
            color: #a0aec0;
        }
        .auth-close:hover { color: black; }
        .auth-toggle {
            text-align: center;
            margin-top: 15px;
            color: #718096;
        }
        .auth-toggle a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .auth-toggle a:hover { text-decoration: underline; }
        
        /* Tabs for Admin */
        .tabs {
            display: flex;
            gap: 10px;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        .tab {
            padding: 10px 20px;
            background: #f7fafc;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        .tab:hover { background: #e2e8f0; }
        .tab.active {
            background: #667eea;
            color: white;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        /* Stats cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-card {
            background: #f7fafc;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-card .number {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }
        .stat-card .label {
            color: #718096;
            margin-top: 5px;
        }
        
        /* User list in admin */
        .user-item {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .user-item:last-child { border-bottom: none; }
        .user-item .badge {
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-admin { background: #fc8181; color: white; }
        .badge-user { background: #63b3ed; color: white; }
        
        /* Responsive */
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: stretch; }
            .auth-buttons { justify-content: center; }
            .file-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .auth-box { width: 90%; margin: 5% auto; padding: 25px; }
            .search-bar form { flex-direction: column; }
            .container { padding: 15px; }
        }
        
        @media (max-width: 480px) {
            .file-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
            .btn { padding: 8px 15px; font-size: 12px; }
            .upload-area { padding: 15px; }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- ============================================
    HEADER
    ============================================ -->
    <div class="header">
        <h1>📁 File<span>Manager</span></h1>
        <div class="auth-buttons">
            <?php if ($current_user): ?>
                <span style="color:#4a5568;">👋 <?= htmlspecialchars($username) ?></span>
                <?php if ($is_admin): ?>
                    <span class="badge badge-admin">Admin</span>
                <?php endif; ?>
                <a href="?logout" class="btn btn-danger btn-sm">Logout</a>
            <?php else: ?>
                <button onclick="showAuth('login')" class="btn btn-primary">Login</button>
                <button onclick="showAuth('register')" class="btn btn-outline">Register</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Message -->
    <?php if ($message): ?>
        <div class="message <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- ============================================
    UPLOAD AREA
    ============================================ -->
    <?php if ($current_user): ?>
    <div class="upload-area">
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <input type="file" name="file" id="fileInput" required>
            <label for="fileInput">📤 Choose File to Upload</label>
            <button type="submit" class="btn btn-success">Upload</button>
            <p style="margin-top:10px; color:#718096; font-size:12px;">Max file size: 100MB</p>
        </form>
    </div>
    <?php else: ?>
    <div class="upload-area" style="background:#fefcbf;">
        <p style="color:#744210;">🔒 Please login to upload files</p>
    </div>
    <?php endif; ?>

    <!-- ============================================
    SEARCH
    ============================================ -->
    <div class="search-bar">
        <form method="GET" style="flex:1; display:flex; gap:10px;">
            <input type="text" name="search" placeholder="🔍 Search files..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search): ?>
                <a href="?" class="btn btn-outline">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- ============================================
    ADMIN DASHBOARD (Tabs)
    ============================================ -->
    <?php if ($is_admin): ?>
    <div style="margin:20px 0;">
        <div class="tabs">
            <button class="tab active" onclick="switchTab('all', this)">📊 All Files</button>
            <button class="tab" onclick="switchTab('users', this)">👥 Users</button>
            <button class="tab" onclick="switchTab('stats', this)">📈 Statistics</button>
        </div>
        
        <div id="tab-all" class="tab-content active">
            <!-- Files shown below -->
        </div>
        
        <div id="tab-users" class="tab-content">
            <h3 style="margin-bottom:15px;">👥 Registered Users</h3>
            <?php
            $users = $conn->query("SELECT id, username, role, 
                                  (SELECT COUNT(*) FROM files WHERE user_id = users.id) as file_count 
                                  FROM users ORDER BY id");
            if ($users->num_rows > 0):
                while ($u = $users->fetch_assoc()): ?>
                    <div class="user-item">
                        <div>
                            <strong><?= htmlspecialchars($u['username']) ?></strong>
                            <span class="badge <?= $u['role'] === 'admin' ? 'badge-admin' : 'badge-user' ?>">
                                <?= $u['role'] ?>
                            </span>
                        </div>
                        <span style="color:#718096;">📁 <?= $u['file_count'] ?> files</span>
                    </div>
                <?php endwhile;
            else: ?>
                <p style="color:#718096;">No users registered yet.</p>
            <?php endif; ?>
        </div>
        
        <div id="tab-stats" class="tab-content">
            <h3 style="margin-bottom:15px;">📈 System Statistics</h3>
            <?php
            $total_files = $conn->query("SELECT COUNT(*) as total FROM files")->fetch_assoc()['total'];
            $total_users = $conn->query("SELECT COUNT(*) as total FROM users")->fetch_assoc()['total'];
            $total_size = $conn->query("SELECT SUM(filesize) as size FROM files")->fetch_assoc()['size'] ?? 0;
            $total_downloads = $conn->query("SELECT SUM(downloads) as total FROM files")->fetch_assoc()['total'] ?? 0;
            ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="number"><?= $total_files ?></div>
                    <div class="label">📁 Total Files</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $total_users ?></div>
                    <div class="label">👥 Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= round($total_size / 1024 / 1024, 2) ?> MB</div>
                    <div class="label">💾 Total Storage</div>
                </div>
                <div class="stat-card">
                    <div class="number"><?= $total_downloads ?></div>
                    <div class="label">⬇ Total Downloads</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================
    FILE GRID
    ============================================ -->
    <div style="display:flex; justify-content:space-between; align-items:center; margin:20px 0 10px 0; flex-wrap:wrap;">
        <h3 style="color:#4a5568;">
            <?php if ($search): ?>
                📋 Search Results: <?= $total ?> files
            <?php else: ?>
                📋 All Files (<?= $total ?> total)
            <?php endif; ?>
        </h3>
        <?php if ($total > 0): ?>
            <span style="color:#718096; font-size:14px;">Page <?= $page ?> of <?= $total_pages ?></span>
        <?php endif; ?>
    </div>

    <?php if ($files->num_rows > 0): ?>
    <div class="file-grid">
        <?php while ($file = $files->fetch_assoc()): 
            $ext = $file['filetype'];
            $icon = [
                'jpg' => '🖼️', 'jpeg' => '🖼️', 'png' => '🖼️', 'gif' => '🖼️',
                'pdf' => '📄', 
                'doc' => '📝', 'docx' => '📝', 'txt' => '📃',
                'mp4' => '🎬', 'avi' => '🎬', 'mov' => '🎬', 'mkv' => '🎬',
                'zip' => '📦', 'rar' => '📦', '7z' => '📦'
            ][$ext] ?? '📎';
        ?>
        <div class="file-card">
            <div class="file-icon"><?= $icon ?></div>
            <div class="file-name" title="<?= htmlspecialchars($file['filename']) ?>">
                <?= htmlspecialchars(substr($file['filename'], 15)) ?>
            </div>
            <div class="file-meta">
                <?= round($file['filesize'] / 1024, 1) ?> KB
                <br>⬇ <?= $file['downloads'] ?> downloads
                <?php if ($is_admin): ?>
                    <br>👤 <?= htmlspecialchars($file['username'] ?? 'Unknown') ?>
                <?php endif; ?>
                <br><small><?= date('d/m/Y H:i', strtotime($file['upload_date'])) ?></small>
            </div>
            <div class="file-actions">
                <a href="?download=<?= $file['id'] ?>" class="btn btn-success btn-sm">⬇</a>
                
                <?php if (in_array($ext, ['jpg','jpeg','png','gif','pdf'])): ?>
                    <button onclick="previewFile('<?= $file['filepath'] ?>', '<?= $ext ?>')" class="btn btn-info btn-sm">👁</button>
                <?php endif; ?>
                
                <?php if ($is_admin || ($current_user && $file['user_id'] == $current_user)): ?>
                    <a href="?delete=<?= $file['id'] ?>" onclick="return confirm('Delete this file?')" class="btn btn-danger btn-sm">🗑</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php else: ?>
        <div style="text-align:center; padding:60px 20px; color:#718096;">
            <div style="font-size:64px; margin-bottom:20px;">📭</div>
            <h3 style="color:#4a5568;">No files found</h3>
            <p><?= $current_user ? 'Upload your first file above!' : 'Please login to upload files.' ?></p>
        </div>
    <?php endif; ?>

    <!-- ============================================
    PAGINATION
    ============================================ -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1&search=<?= urlencode($search) ?>">« First</a>
            <a href="?page=<?= $page-1 ?>&search=<?= urlencode($search) ?>">‹ Previous</a>
        <?php endif; ?>
        
        <?php 
        $start = max(1, $page - 2);
        $end = min($total_pages, $page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" 
               class="<?= $i == $page ? 'active' : '' ?>">
                <?= $i ?>
            </a>
        <?php endfor; ?>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page+1 ?>&search=<?= urlencode($search) ?>">Next ›</a>
            <a href="?page=<?= $total_pages ?>&search=<?= urlencode($search) ?>">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ============================================
AUTH MODAL (Login/Register)
============================================ -->
<div id="authModal" class="auth-modal">
    <div class="auth-box">
        <span class="auth-close" onclick="closeAuth()">&times;</span>
        <h2 id="authTitle">Login</h2>
        <form method="POST" id="authForm" onsubmit="return validateAuth()">
            <input type="hidden" name="login" id="authAction">
            <input type="text" name="username" id="authUsername" placeholder="Username" required>
            <input type="password" name="password" id="authPassword" placeholder="Password" required>
            <button type="submit" class="btn btn-primary" id="authSubmit">Login</button>
        </form>
        <div class="auth-toggle">
            <span id="authToggle">Don't have an account? <a href="#" onclick="toggleAuth()">Register</a></span>
        </div>
    </div>
</div>

<!-- ============================================
PREVIEW MODAL
============================================ -->
<div id="previewModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closePreview()">&times;</span>
        <div id="previewContent"></div>
    </div>
</div>

<!-- ============================================
JAVASCRIPT
============================================ -->
<script>
// Auth Modal
function showAuth(type) {
    document.getElementById('authModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    if (type === 'login') {
        document.getElementById('authTitle').textContent = '🔐 Login';
        document.getElementById('authAction').name = 'login';
        document.getElementById('authSubmit').textContent = 'Login';
        document.getElementById('authToggle').innerHTML = "Don't have an account? <a href='#' onclick='toggleAuth()'>Register</a>";
    } else {
        document.getElementById('authTitle').textContent = '📝 Register';
        document.getElementById('authAction').name = 'register';
        document.getElementById('authSubmit').textContent = 'Register';
        document.getElementById('authToggle').innerHTML = "Already have an account? <a href='#' onclick='toggleAuth()'>Login</a>";
    }
}

function closeAuth() {
    document.getElementById('authModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

function toggleAuth() {
    const title = document.getElementById('authTitle').textContent;
    if (title.includes('Login')) {
        showAuth('register');
    } else {
        showAuth('login');
    }
}

function validateAuth() {
    const username = document.getElementById('authUsername').value.trim();
    const password = document.getElementById('authPassword').value.trim();
    if (username.length < 3) {
        alert('Username must be at least 3 characters');
        return false;
    }
    if (password.length < 4) {
        alert('Password must be at least 4 characters');
        return false;
    }
    return true;
}

// Close auth modal on outside click
document.getElementById('authModal').addEventListener('click', function(e) {
    if (e.target === this) closeAuth();
});

// Preview
function previewFile(filepath, ext) {
    const modal = document.getElementById('previewModal');
    const content = document.getElementById('previewContent');
    
    if (['jpg','jpeg','png','gif'].includes(ext)) {
        content.innerHTML = `<img src="${filepath}" class="modal-preview" alt="Preview" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22200%22%3E%3Crect fill=%22%23f7fafc%22 width=%22200%22 height=%22200%22/%3E%3Ctext x=%2250%%22 y=%2250%%22 text-anchor=%22middle%22 dy=%22.3em%22 fill=%22%23a0aec0%22 font-size=%2216%22%3ENo Image%3C/text%3E%3C/svg%3E'">`;
    } else if (ext === 'pdf') {
        content.innerHTML = `<embed src="${filepath}" type="application/pdf" width="100%" height="600px">`;
    } else {
        content.innerHTML = `<p style="text-align:center; padding:40px;">Preview not available for this file type</p>`;
    }
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
    document.getElementById('previewContent').innerHTML = '';
    document.body.style.overflow = 'auto';
}

// Close preview on outside click
document.getElementById('previewModal').addEventListener('click', function(e) {
    if (e.target === this) closePreview();
});

// Admin Tabs
function switchTab(tab, element) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    if (element) {
        element.classList.add('active');
    } else {
        document.querySelector(`.tab[data-tab="${tab}"]`)?.classList.add('active');
    }
    document.getElementById('tab-' + tab).classList.add('active');
}

// Auto-upload on file selection
document.getElementById('fileInput')?.addEventListener('change', function() {
    if (this.files.length > 0) {
        const file = this.files[0];
        if (file.size > 100 * 1024 * 1024) {
            alert('File too large! Maximum 100MB allowed.');
            this.value = '';
            return;
        }
        document.getElementById('uploadForm').submit();
    }
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAuth();
        closePreview();
    }
});

// Show modal if there's a message about login/register
<?php if ($message && strpos($message, 'login') !== false): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showAuth('login');
    });
<?php endif; ?>
</script>

</body>
</html>