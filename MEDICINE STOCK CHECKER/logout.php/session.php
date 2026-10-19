<?php
// config/session.php
session_start();

class SessionManager {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
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
        session_destroy();
        $_SESSION = [];
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
}
?>