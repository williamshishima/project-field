<?php
require_once 'config/session.php';

SessionManager::start();

// Log activity
if (SessionManager::isLoggedIn()) {
    try {
        require_once 'config/database.php';
        $db = Database::getInstance();
        $user_id = SessionManager::getUserId();
        $logStmt = $db->prepare("INSERT INTO user_activity (user_id, action, ip_address) VALUES (?, ?, ?)");
        $logStmt->execute([$user_id, 'logout', $_SERVER['REMOTE_ADDR']]);
    } catch (Exception $e) {
        // Silently handle error
    }
}

// Destroy session
SessionManager::destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>