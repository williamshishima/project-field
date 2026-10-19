<?php
require_once 'config/database.php';
require_once 'config/session.php';

SessionManager::start();

// Redirect if already logged in
if (SessionManager::isLoggedIn()) {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT id, username, email, password_hash, full_name, role, is_active FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
                // Login successful
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
                $logStmt = $db->prepare("INSERT INTO user_activity (user_id, action, ip_address) VALUES (?, ?, ?)");
                $logStmt->execute([$user['id'], 'login', $_SERVER['REMOTE_ADDR']]);
                
                header('Location: index.php');
                exit();
            } else {
                $error = 'Invalid username or password.';
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedStock - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, system-ui, sans-serif;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f4f9fc 0%, #d4e8dd 100%);
            padding: 1rem;
        }
        
        .login-container {
            background: white;
            border-radius: 30px;
            padding: 3rem 2.5rem;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 30, 20, 0.15);
            border: 1px solid #d4e8dd;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .login-header .logo {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1f4f3b;
        }
        
        .login-header .logo i {
            color: #f5c542;
            margin-right: 10px;
        }
        
        .login-header h2 {
            color: #1f4f3b;
            margin-top: 0.5rem;
            font-weight: 600;
        }
        
        .login-header p {
            color: #4d7a62;
            margin-top: 0.3rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #1f4f3b;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        
        .form-group .input-wrapper {
            position: relative;
        }
        
        .form-group .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #4d7a62;
        }
        
        .form-group input {
            width: 100%;
            padding: 0.8rem 1rem 0.8rem 3rem;
            border: 2px solid #c8ddd0;
            border-radius: 40px;
            font-size: 1rem;
            transition: 0.2s;
            background: #fafffd;
        }
        
        .form-group input:focus {
            border-color: #1f4f3b;
            box-shadow: 0 0 0 3px rgba(31, 79, 59, 0.15);
            outline: none;
        }
        
        .form-group .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #4d7a62;
        }
        
        .btn-login {
            width: 100%;
            padding: 0.9rem;
            background: #1f4f3b;
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .btn-login:hover {
            background: #2e7a5a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(31, 79, 59, 0.3);
        }
        
        .btn-login i {
            margin-right: 10px;
        }
        
        .register-link {
            text-align: center;
            margin-top: 1.5rem;
            color: #4d7a62;
        }
        
        .register-link a {
            color: #1f4f3b;
            font-weight: 600;
            text-decoration: none;
        }
        
        .register-link a:hover {
            text-decoration: underline;
        }
        
        .error-message {
            background: #fad1c4;
            color: #a84a1a;
            padding: 0.8rem 1.2rem;
            border-radius: 40px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #c0392b;
            display: <?php echo $error ? 'block' : 'none'; ?>;
        }
        
        .error-message i {
            margin-right: 10px;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 2rem;
            color: #4d7a62;
            font-size: 0.85rem;
        }
        
        .demo-credentials {
            background: #f5f0d7;
            padding: 0.8rem 1.2rem;
            border-radius: 12px;
            margin-top: 1.5rem;
            border-left: 4px solid #f5c542;
        }
        
        .demo-credentials p {
            font-size: 0.85rem;
            color: #5a4a1f;
            margin: 0.2rem 0;
        }
        
        .demo-credentials strong {
            color: #1f4f3b;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="fas fa-pills"></i> MedStock
            </div>
            <h2>Welcome Back</h2>
            <p>Sign in to manage your inventory</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username or Email</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="username" name="username" placeholder="Enter your username or email" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword()"></i>
                </div>
            </div>
            
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
        </form>
        
        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>
        
        <div class="demo-credentials">
            <p><strong>Demo Credentials:</strong></p>
            <p><i class="fas fa-user"></i> Username: <strong>admin</strong></p>
            <p><i class="fas fa-lock"></i> Password: <strong>Admin@123</strong></p>
        </div>
        
        <div class="footer-text">
            <i class="fas fa-shield-alt"></i> Secured with password hashing
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const icon = document.querySelector('.toggle-password');
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>