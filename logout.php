<?php
// logout.php - Enhanced Version
include 'config.php';

// Verify user is logged in before attempting logout operations
if (isset($_SESSION['user_id'])) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Log the logout activity
        logActivity($_SESSION['user_id'], 'LOGOUT', "User logged out from IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        
        // Update logout time and invalidate session in login_sessions table if session_token exists
        if (isset($_SESSION['session_token'])) {
            $stmt = $conn->prepare("UPDATE login_sessions SET logout_time = NOW(), is_active = FALSE WHERE user_id = ? AND session_token = ?");
            $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['session_token']);
            $stmt->execute();
            $stmt->close();
        }
        
        // Additional cleanup: mark user as inactive in current session
        if (isset($_SESSION['login_session_id'])) {
            $stmt = $conn->prepare("UPDATE login_sessions SET logout_time = NOW(), is_active = FALSE WHERE id = ?");
            $stmt->bind_param("i", $_SESSION['login_session_id']);
            $stmt->execute();
            $stmt->close();
        }
        
        // Clear any CSRF token from session
        if (isset($_SESSION['csrf_token'])) {
            unset($_SESSION['csrf_token']);
        }
        
    } catch (Exception $e) {
        error_log("Logout error for user ID {$_SESSION['user_id']}: " . $e->getMessage());
    }
}

// Store some session data for potential debugging before clearing
$user_id = $_SESSION['user_id'] ?? null;
$user_type = $_SESSION['user_type'] ?? null;
$email = $_SESSION['email'] ?? null;

// Completely destroy session data
$_SESSION = array();

// If session is cookie-based, delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 86400,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear all browser cache
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// Create a simple audit log entry
error_log("Logout successful - User: $email (ID: $user_id, Type: $user_type) at " . date('Y-m-d H:i:s'));

// Redirect to login with success message
header("Location: login.php?logout=success");
exit();
?>