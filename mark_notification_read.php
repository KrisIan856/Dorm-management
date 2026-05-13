<?php
session_start();
include 'config.php';

// Security check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['notification_id'])) {
    $notification_id = sanitizeInput($_POST['notification_id']);
    $user_id = $_SESSION['user_id'] ?? 0;
    
    if ($user_id) {
        // FIX: Added user_id parameter
        markNotificationAsRead($notification_id, $user_id);
        
        // Log activity
        logActivity($_SESSION['user_id'], 'READ_NOTIFICATION', "Marked notification ID: $notification_id as read");
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>