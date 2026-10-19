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
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($username) || strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters.';
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    
    if (empty($full_name)) {
        $errors[] = 'Please enter your full name.';
    }
    
    if (empty($password) || strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (empty($errors)) {
        try {
            $db = Database::getInstance();
            
            // Check if username or email already exists
            $checkStmt = $db->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $checkStmt->execute([$username, $email]);
            
            if ($checkStmt->rowCount() > 0) {
                $errors[] = 'Username or email already exists.';
            } else {
                // Hash password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert new user
                $insertStmt = $db->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, role) 
                    VALUES (?, ?, ?, ?, 'staff')
                ");
                $insertStmt->execute([$username, $email, $password_hash, $full_name]);
                
                $success = 'Registration successful! You can now login.';
                
                // Clear form fields
                $username = $email = $full_name = $password = $confirm_password = '';
            }
        } catch (PDOException $e) {
            $errors[] = 'Registration failed. Please try again.';
        }
    }
    
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedStock - Register</title>
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
        
        .register-container {
            background: white;
            border-radius: 30px;
            padding: 3rem 2.5rem;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 30, 20, 0.15);
            border: 1px solid #d4e8dd;
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }
        
        .register-header .logo {
            font-size: 2rem;
            font-weight: 700;
            color: #1f4f3b;
        }
        
        .register-header .logo i {
            color: #f5c542;
            margin-right: 10px;
        }
        
        .register-header h2 {
            color: #1f4f3b;
            margin-top: 0.5rem;
            font-weight: 600;
        }
        
        .register-header p {
            color: #4d7a62;
            margin-top: 0.3rem;
        }
        
        .form-group {
            margin-bottom: 1.2rem;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #1f4f3b;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
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
            padding: 0.7rem 1rem 0.7rem 3rem;
            border: 2px solid #c8ddd0;
            border-radius: 40px;
            font-size: 0.95rem;
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
        
        .btn-register {
            width: 100%;
            padding: 0.9rem;
            background: #f5c542;
            color: #1f4f3b;
            border: none;
            border-radius: 40px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }
        
        .btn-register:hover {
            background: #e6b53a;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(245, 197, 66, 0.3);
        }
        
        .btn-register i {
            margin-right: 10px;
        }
        
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            color: #4d7a62;
        }
        
        .login-link a {
            color: #1f4f3b;
            font-weight: 600;
            text-decoration: none;
        }
        
        .login-link a:hover {
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
        
        .success-message {
            background: #cfe8d6;
            color: #1a6b3a;
            padding: 0.8rem 1.2rem;
            border-radius: 40px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #1a6b3a;
            display: <?php echo $success ? 'block' : 'none'; ?>;
        }
        
        .error-message i, .success-message i {
            margin-right: 10px;
        }
        
        .password-requirements {
            font-size: 0.8rem;
            color: #4d7a62;
            margin-top: 0.3rem;
            padding-left: 0.5rem;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 2rem;
            color: #4d7a62;
            font-size: 0.85rem;
        }
        
        @media (max-width: 480px) {
            .register-container {
                padding: 2rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <div class="logo">
                <i class="fas fa-pills"></i> MedStock
            </div>
            <h2>Create Account</h2>
            <p>Join MedStock to manage your inventory</p>
        </div>
        
        <?php if ($error): ?>
        <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo $error; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="success-message">
            <i class="fas fa-check-circle"></i>
            <?php echo $success; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" 
                           value="<?php echo htmlspecialchars($full_name ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="username">Username</label>
                <div class="input-wrapper">
                    <i class="fas fa-user-tag"></i>
                    <input type="text" id="username" name="username" placeholder="Choose a username (min 3 chars)" 
                           value="<?php echo htmlspecialchars($username ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="email">Email Address</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" 
                           value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="password" name="password" placeholder="Min 8 characters" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('password', this)"></i>
                </div>
                <div class="password-requirements">
                    <i class="fas fa-info-circle"></i> Must be at least 8 characters
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePassword('confirm_password', this)"></i>
                </div>
            </div>
            
            <button type="submit" class="btn-register">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
        
        <div class="login-link">
            Already have an account? <a href="login.php">Sign in here</a>
        </div>
        
        <div class="footer-text">
            <i class="fas fa-shield-alt"></i> Your data is secure and encrypted
        </div>
    </div>
    
    <script>
        function togglePassword(inputId, icon) {
            const input = document.getElementById(inputId);
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
    </script>
</body>
</html>