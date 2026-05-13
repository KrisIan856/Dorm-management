<?php
// occupant_dashboard.php
require_once 'config.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enhanced security check with session validation
checkSession();

// Verify user is occupant
if ($_SESSION['user_type'] != 'occupant') {
    header("Location: login.php");
    exit();
}

// Set session creation time if not set
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
}

// Regenerate session ID periodically for security
if (!isset($_SESSION['last_regeneration']) || time() - $_SESSION['last_regeneration'] > 300) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Initialize database
$db = new Database();
$conn = $db->getConnection();

// Get user ID from session
$user_id = $_SESSION['user_id'];
$user_email = $_SESSION['email'];
$user_fullname = $_SESSION['full_name'];
$user_phone = $_SESSION['phone'] ?? '';

// Debug logging
error_log("Dashboard loaded for user ID: $user_id");
error_log("Session data: " . print_r($_SESSION, true));

// Generate CSRF token
$csrf_token = generateCSRFToken();

// Handle all POST requests with validation
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: occupant_dashboard.php');
        exit();
    }
    
    // Rate limiting check (max 10 requests per minute)
    if (!isset($_SESSION['request_count'])) {
        $_SESSION['request_count'] = 1;
        $_SESSION['first_request_time'] = time();
    } else {
        $time_elapsed = time() - $_SESSION['first_request_time'];
        if ($time_elapsed > 60) {
            // Reset counter after 60 seconds
            $_SESSION['request_count'] = 1;
            $_SESSION['first_request_time'] = time();
        } elseif ($_SESSION['request_count'] >= 10) {
            $_SESSION['error'] = 'Too many requests. Please wait a moment.';
            header('Location: occupant_dashboard.php');
            exit();
        } else {
            $_SESSION['request_count']++;
        }
    }
    
    // Debug: Log POST data
    error_log("POST data received: " . print_r($_POST, true));
    
    // Process different POST actions
    if (isset($_POST['reserve_room'])) {
        handleRoomReservation();
    } elseif (isset($_POST['submit_service_request'])) {
        handleServiceRequest();
    } elseif (isset($_POST['submit_visitor_request'])) {
        handleVisitorRequest();
    } elseif (isset($_POST['update_profile'])) {
        handleProfileUpdate();
    } elseif (isset($_POST['change_password'])) {
        error_log("Change password form submitted");
        handlePasswordChange();
    } elseif (isset($_POST['mark_notification_read'])) {
        handleNotificationRead();
    } elseif (isset($_POST['mark_all_notifications_read'])) {
        markAllNotificationsAsRead($user_id);
        $_SESSION['success'] = "All notifications marked as read.";
    } elseif (isset($_POST['clear_old_notifications'])) {
        clearOldNotifications($user_id);
    } else {
        error_log("No matching POST action found");
    }
    
    // Redirect to refresh page and show session messages
    $redirect_section = isset($_GET['section']) ? '?section=' . sanitizeInput($_GET['section']) : '';
    header('Location: occupant_dashboard.php' . $redirect_section);
    exit();
}

// Function to handle room reservation
function handleRoomReservation() {
    global $conn, $user_id;
    
    $required_fields = ['room_id', 'reservation_date'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['error'] = "All fields are required.";
            return;
        }
    }
    
    $room_id = intval($_POST['room_id']);
    $reservation_date = sanitizeInput($_POST['reservation_date']);
    $special_requirements = sanitizeInput($_POST['special_requirements'] ?? '');
    
    // Validate date (must be today or future)
    if (strtotime($reservation_date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error'] = "Reservation date cannot be in the past.";
        return;
    }
    
    // Check if date is within 30 days
    if (strtotime($reservation_date) > strtotime('+30 days')) {
        $_SESSION['error'] = "Reservation can only be made up to 30 days in advance.";
        return;
    }
    
    try {
        // Check for existing reservation on same date
        $stmt = $conn->prepare("SELECT id FROM reservations WHERE user_id = ? AND reservation_date = ? AND status IN ('pending', 'approved')");
        $stmt->bind_param("is", $user_id, $reservation_date);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $_SESSION['error'] = "You already have a reservation for this date.";
            $stmt->close();
            return;
        }
        $stmt->close();
        
        // Check room availability
        $stmt = $conn->prepare("SELECT room_number, capacity, current_occupants FROM rooms WHERE id = ? AND is_available = 1");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        $stmt->bind_result($room_number, $capacity, $current_occupants);
        if (!$stmt->fetch()) {
            $_SESSION['error'] = "Selected room is not available.";
            $stmt->close();
            return;
        }
        $stmt->close();
        
        if ($current_occupants >= $capacity) {
            $_SESSION['error'] = "Selected room is at full capacity.";
            return;
        }
        
        // Create reservation
        $stmt = $conn->prepare("INSERT INTO reservations (user_id, room_id, reservation_date, special_requirements) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $user_id, $room_id, $reservation_date, $special_requirements);
        
        if ($stmt->execute()) {
            $reservation_id = $stmt->insert_id;
            
            // Get dorm dean for notification
            $dean_stmt = $conn->prepare("SELECT id FROM users WHERE user_type IN ('dormdean', 'dormdean_assistant') AND is_active = TRUE ORDER BY id LIMIT 1");
            $dean_stmt->execute();
            $dean_result = $dean_stmt->get_result();
            if ($dean = $dean_result->fetch_assoc()) {
                createNotification(
                    $dean['id'],
                    'New Room Reservation',
                    "A new room reservation request has been submitted by " . $_SESSION['full_name'] . " for Room $room_number on $reservation_date.",
                    'reservation',
                    $reservation_id
                );
            }
            $dean_stmt->close();
            
            $_SESSION['success'] = "Room reservation submitted successfully! You will be notified when it's approved.";
            logActivity($user_id, 'reservation_created', "Reserved Room $room_number for $reservation_date");
        } else {
            $_SESSION['error'] = "Failed to submit reservation. Please try again.";
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Reservation error: " . $e->getMessage());
    }
}

// Function to handle service request
function handleServiceRequest() {
    global $conn, $user_id;
    
    $required_fields = ['service_type', 'description', 'urgency'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['error'] = "All fields are required.";
            return;
        }
    }
    
    $service_type = sanitizeInput($_POST['service_type']);
    $description = sanitizeInput($_POST['description']);
    $urgency = sanitizeInput($_POST['urgency']);
    
    // Validate urgency level
    $allowed_urgencies = ['low', 'medium', 'high', 'emergency'];
    if (!in_array($urgency, $allowed_urgencies)) {
        $_SESSION['error'] = "Invalid urgency level.";
        return;
    }
    
    try {
        $stmt = $conn->prepare("INSERT INTO service_requests (user_id, service_type, description, urgency) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $service_type, $description, $urgency);
        
        if ($stmt->execute()) {
            $request_id = $stmt->insert_id;
            
            // Get dorm dean for notification
            $dean_stmt = $conn->prepare("SELECT id FROM users WHERE user_type IN ('dormdean', 'dormdean_assistant') AND is_active = TRUE ORDER BY id LIMIT 1");
            $dean_stmt->execute();
            $dean_result = $dean_stmt->get_result();
            if ($dean = $dean_result->fetch_assoc()) {
                createNotification(
                    $dean['id'],
                    'New Service Request - ' . ucfirst($urgency),
                    "New $urgency priority service request from " . $_SESSION['full_name'] . ": " . substr($description, 0, 100) . "...",
                    'service',
                    $request_id
                );
            }
            $dean_stmt->close();
            
            $_SESSION['success'] = "Service request submitted successfully! Our team will address it soon.";
            logActivity($user_id, 'service_request_created', "$service_type request submitted with $urgency priority");
        } else {
            $_SESSION['error'] = "Failed to submit service request.";
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Service request error: " . $e->getMessage());
    }
}

// Function to handle visitor request
function handleVisitorRequest() {
    global $conn, $user_id;
    
    $required_fields = ['visitor_name', 'visitor_phone', 'visit_date', 'visit_purpose'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $_SESSION['error'] = "All required fields must be filled.";
            return;
        }
    }
    
    $visitor_name = sanitizeInput($_POST['visitor_name']);
    $visitor_email = sanitizeInput($_POST['visitor_email'] ?? '');
    $visitor_phone = sanitizeInput($_POST['visitor_phone']);
    $visit_date = sanitizeInput($_POST['visit_date']);
    $visit_time = sanitizeInput($_POST['visit_time'] ?? '');
    $visit_purpose = sanitizeInput($_POST['visit_purpose']);
    
    // Validate date
    if (strtotime($visit_date) < strtotime(date('Y-m-d'))) {
        $_SESSION['error'] = "Visit date cannot be in the past.";
        return;
    }
    
    // Validate phone number
    if (!preg_match('/^[0-9+\-\s]{10,20}$/', $visitor_phone)) {
        $_SESSION['error'] = "Please enter a valid phone number.";
        return;
    }
    
    try {
        $stmt = $conn->prepare("INSERT INTO visitor_requests (user_id, visitor_name, visitor_email, visitor_phone, visit_date, visit_time, visit_purpose) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssss", $user_id, $visitor_name, $visitor_email, $visitor_phone, $visit_date, $visit_time, $visit_purpose);
        
        if ($stmt->execute()) {
            $request_id = $stmt->insert_id;
            
            // Get dorm dean for notification
            $dean_stmt = $conn->prepare("SELECT id FROM users WHERE user_type IN ('dormdean', 'dormdean_assistant') AND is_active = TRUE ORDER BY id LIMIT 1");
            $dean_stmt->execute();
            $dean_result = $dean_stmt->get_result();
            if ($dean = $dean_result->fetch_assoc()) {
                createNotification(
                    $dean['id'],
                    'New Visitor Request',
                    "Visitor request from " . $_SESSION['full_name'] . " for $visitor_name on $visit_date",
                    'visitor',
                    $request_id
                );
            }
            $dean_stmt->close();
            
            $_SESSION['success'] = "Visitor request submitted successfully! Approval pending.";
            logActivity($user_id, 'visitor_request_created', "Visitor $visitor_name requested for $visit_date");
        } else {
            $_SESSION['error'] = "Failed to submit visitor request.";
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Visitor request error: " . $e->getMessage());
    }
}

// Function to handle profile update
function handleProfileUpdate() {
    global $conn, $user_id;
    
    $full_name = sanitizeInput($_POST['full_name']);
    $phone = sanitizeInput($_POST['phone']);
    
    if (empty($full_name)) {
        $_SESSION['error'] = "Full name is required.";
        return;
    }
    
    // Validate phone if provided
    if (!empty($phone) && !preg_match('/^[0-9+\-\s]{10,20}$/', $phone)) {
        $_SESSION['error'] = "Please enter a valid phone number.";
        return;
    }
    
    try {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $full_name, $phone, $user_id);
        
        if ($stmt->execute()) {
            $_SESSION['full_name'] = $full_name;
            $_SESSION['phone'] = $phone;
            $_SESSION['success'] = 'Profile updated successfully.';
            logActivity($user_id, 'profile_updated', 'Updated profile information');
        } else {
            $_SESSION['error'] = 'Failed to update profile.';
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Profile update error: " . $e->getMessage());
    }
}

// Function to handle password change
function handlePasswordChange() {
    global $conn, $user_id;
    
    // Debug logging
    error_log("handlePasswordChange() called for user ID: $user_id");
    error_log("POST data in handlePasswordChange: " . print_r($_POST, true));
    
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    error_log("Current password provided: " . (!empty($current) ? "Yes" : "No"));
    error_log("New password provided: " . (!empty($new) ? "Yes" : "No"));
    error_log("Confirm password provided: " . (!empty($confirm) ? "Yes" : "No"));
    
    if (empty($current) || empty($new) || empty($confirm)) {
        $_SESSION['error'] = 'All password fields are required.';
        error_log("Password change error: Missing fields");
        return;
    }
    
    if ($new !== $confirm) {
        $_SESSION['error'] = 'New passwords do not match.';
        error_log("Password change error: Passwords don't match");
        return;
    }
    
    if (strlen($new) < 8) {
        $_SESSION['error'] = 'New password must be at least 8 characters.';
        error_log("Password change error: Password too short");
        return;
    }
    
    try {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        if (!$stmt) {
            $_SESSION['error'] = 'Database error: ' . $conn->error;
            error_log("Prepare failed: " . $conn->error);
            return;
        }
        
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($hash);
        $stmt->fetch();
        $stmt->close();
        
        error_log("Current hash from DB: " . ($hash ? "Exists" : "Not found"));
        
        if (!password_verify($current, $hash)) {
            $_SESSION['error'] = 'Current password is incorrect.';
            error_log("Password change error: Current password incorrect");
            return;
        }
        
        // Check if new password is same as old
        if (password_verify($new, $hash)) {
            $_SESSION['error'] = 'New password cannot be same as current password.';
            error_log("Password change error: New password same as old");
            return;
        }
        
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $u = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
        
        if (!$u) {
            $_SESSION['error'] = 'Database error: ' . $conn->error;
            error_log("Update prepare failed: " . $conn->error);
            return;
        }
        
        $u->bind_param("si", $new_hash, $user_id);
        
        if ($u->execute()) {
            $_SESSION['success'] = 'Password changed successfully.';
            error_log("Password change successful for user $user_id");
            
            logActivity($user_id, 'password_changed', 'Password updated successfully');
        } else {
            $_SESSION['error'] = 'Failed to update password: ' . $conn->error;
            error_log("Password change error: Update failed - " . $conn->error);
        }
        $u->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Password change exception: " . $e->getMessage());
    }
}

// Function to handle notification read
function handleNotificationRead() {
    global $user_id;
    
    if (!isset($_POST['notification_id'])) {
        $_SESSION['error'] = "Notification ID is required.";
        return;
    }
    
    $notification_id = intval($_POST['notification_id']);
    
    try {
        if (markNotificationAsRead($notification_id, $user_id)) {
            $_SESSION['success'] = "Notification marked as read.";
            logActivity($user_id, 'notification_read', "Marked notification #$notification_id as read");
        } else {
            $_SESSION['error'] = "Failed to mark notification as read.";
        }
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Notification read error: " . $e->getMessage());
    }
}

// Helper function to get user's current room
function getUserCurrentRoom($user_id) {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT r.room_number, r.floor, r.building 
            FROM room_assignments ra 
            JOIN rooms r ON ra.room_id = r.id 
            WHERE ra.user_id = ? AND ra.status = 'active'
            LIMIT 1
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $room = $result->fetch_assoc();
        $stmt->close();
        
        return $room;
    } catch (Exception $e) {
        error_log("Get user room error: " . $e->getMessage());
        return null;
    }
}

// Function to get user reservations
function getUserReservations($user_id) {
    global $conn;
    $reservations = [];
    try {
        $stmt = $conn->prepare("
            SELECT r.*, rm.room_number, rm.floor, rm.building 
            FROM reservations r 
            JOIN rooms rm ON r.room_id = rm.id 
            WHERE r.user_id = ? 
            ORDER BY r.reservation_date DESC
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $reservations[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get user reservations error: " . $e->getMessage());
    }
    return $reservations;
}

// Function to get available rooms
function getAvailableRooms() {
    global $conn;
    $rooms = [];
    try {
        $stmt = $conn->prepare("
            SELECT * FROM rooms 
            WHERE is_available = 1 
            AND (capacity - current_occupants) > 0 
            ORDER BY room_number
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rooms[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get available rooms error: " . $e->getMessage());
    }
    return $rooms;
}

// Function to get user service requests
function getUserServiceRequests($user_id) {
    global $conn;
    $requests = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM service_requests WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get service requests error: " . $e->getMessage());
    }
    return $requests;
}

// Function to get user visitor requests
function getUserVisitorRequests($user_id) {
    global $conn;
    $requests = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM visitor_requests WHERE user_id = ? ORDER BY visit_date DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $requests[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get visitor requests error: " . $e->getMessage());
    }
    return $requests;
}

// Function to get user fines
function getUserFines($user_id) {
    global $conn;
    $fines = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM fines WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $fines[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get user fines error: " . $e->getMessage());
    }
    return $fines;
}

// Function to get user attendance
function getUserAttendance($user_id, $limit = 30) {
    global $conn;
    $attendance = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT ?");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $attendance[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get user attendance error: " . $e->getMessage());
    }
    return $attendance;
}

// Function to get attendance stats
function getAttendanceStats($user_id) {
    global $conn;
    $stats = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(CASE WHEN status = 'present' THEN 1 END) as present_count,
                COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_count,
                COUNT(CASE WHEN status = 'late' THEN 1 END) as late_count,
                COUNT(*) as total
            FROM attendance 
            WHERE user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get attendance stats error: " . $e->getMessage());
    }
    return $stats;
}

// Function to get fines stats
function getFinesStats($user_id) {
    global $conn;
    $stats = ['pending_total' => 0, 'paid_total' => 0, 'total_fines' => 0];
    try {
        $stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending_total,
                SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as paid_total,
                COUNT(*) as total_fines
            FROM fines 
            WHERE user_id = ?
        ");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get fines stats error: " . $e->getMessage());
    }
    return $stats;
}

// Function to get recent activity
function getRecentActivity($user_id, $limit = 10) {
    global $conn;
    $activities = [];
    try {
        $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("ii", $user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $activities[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get recent activity error: " . $e->getMessage());
    }
    return $activities;
}

// Function to get announcements
function getAnnouncements($target = 'all', $limit = 5) {
    global $conn;
    $announcements = [];
    try {
        $stmt = $conn->prepare("
            SELECT a.*, u.full_name as author 
            FROM announcements a 
            JOIN users u ON a.created_by = u.id 
            WHERE a.is_active = TRUE 
            AND (a.target_users = ? OR a.target_users = 'all') 
            AND (a.expires_at IS NULL OR a.expires_at > NOW()) 
            ORDER BY 
                CASE a.priority 
                    WHEN 'urgent' THEN 1 
                    WHEN 'important' THEN 2 
                    ELSE 3 
                END, 
                a.created_at DESC 
            LIMIT ?
        ");
        $stmt->bind_param("si", $target, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $announcements[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Get announcements error: " . $e->getMessage());
    }
    return $announcements;
}

// Clear old notifications (older than 30 days)
function clearOldNotifications($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $deleted_count = $stmt->affected_rows;
        logActivity($user_id, 'CLEAR_OLD_NOTIFICATIONS', "Cleared $deleted_count old notifications");
        $_SESSION['success'] = "Cleared $deleted_count old notifications!";
    } else {
        $_SESSION['error'] = "Failed to clear notifications.";
    }
    $stmt->close();
}

// Function to format time elapsed string
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) {
        $string = array_slice($string, 0, 1);
    }
    
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}

// Get occupant profile
function getOccupantProfile($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT full_name, email, phone, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
    
    return $profile;
}

// Get dashboard data
function getDashboardData($user_id) {
    $data = [
        'reservations' => getUserReservations($user_id),
        'available_rooms' => getAvailableRooms(),
        'service_requests' => getUserServiceRequests($user_id),
        'visitor_requests' => getUserVisitorRequests($user_id),
        'fines' => getUserFines($user_id),
        'attendance' => getUserAttendance($user_id, 30),
        'attendance_stats' => getAttendanceStats($user_id),
        'fines_stats' => getFinesStats($user_id),
        'current_room' => getUserCurrentRoom($user_id),
        'recent_activity' => getRecentActivity($user_id, 10),
        'announcements' => getAnnouncements('occupants', 5)
    ];
    
    return $data;
}

// Fetch dashboard data
$dashboard_data = getDashboardData($user_id);

// Initialize variables
$reservations = $dashboard_data['reservations'] ?? [];
$available_rooms = $dashboard_data['available_rooms'] ?? [];
$service_requests = $dashboard_data['service_requests'] ?? [];
$visitor_requests = $dashboard_data['visitor_requests'] ?? [];
$fines = $dashboard_data['fines'] ?? [];
$attendance = $dashboard_data['attendance'] ?? [];
$attendance_stats = $dashboard_data['attendance_stats'] ?? [];
$fines_stats = $dashboard_data['fines_stats'] ?? [];
$current_room = $dashboard_data['current_room'] ?? null;
$recent_activity = $dashboard_data['recent_activity'] ?? [];
$announcements = $dashboard_data['announcements'] ?? [];

// Get notifications
$unread_count = getUnreadNotificationCount($user_id);
$notifications = getNotifications($user_id, 5);

// Get occupant profile
$occupant_profile = getOccupantProfile($user_id);

// Determine active section
$active_section = isset($_GET['section']) ? sanitizeInput($_GET['section']) : 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Occupant Dashboard - Dorm Management System</title>
    <meta name="description" content="Dormitory management system occupant portal">
    
    <!-- Security headers -->
    <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; style-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; img-src 'self' data: https:;">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="X-Frame-Options" content="DENY">
    <meta http-equiv="Referrer-Policy" content="strict-origin-when-cross-origin">
    
    <!-- External resources -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    
    <style>
        /* Tab navigation styles from dormdean dashboard */
        .tab-nav {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 500;
            color: #6c757d;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.3s;
        }
        
        .tab-btn:hover {
            color: var(--primary);
        }
        
        .tab-btn.active {
            color: var(--primary);
            border-bottom: 2px solid var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #fca311;
            --danger: #e63946;
            --dark: #1d3557;
            --light: #f8f9fa;
            --sidebar-width: 280px;
            --border-radius: 12px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fb 0%, #e3e9f7 100%);
            color: #333;
            display: flex;
            min-height: 100vh;
            line-height: 1.6;
        }
        
        /* Responsive sidebar */
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dark), #0d1b2a);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: transform 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 100%;
                max-width: 300px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0 !important;
            }
            
            .mobile-menu-btn {
                display: block !important;
            }
            
            .tab-nav {
                flex-direction: column;
            }
            
            .tab-btn {
                text-align: left;
                padding: 10px 15px;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            font-size: 1.5rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 20px;
            width: calc(100% - var(--sidebar-width));
            transition: margin-left 0.3s ease;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            background: white;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }
        
        .sidebar-menu li a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            border-left: 4px solid transparent;
        }
        
        .sidebar-menu li a:hover,
        .sidebar-menu li a.active {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left-color: var(--primary);
        }
        
        .card {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            border-left: 4px solid var(--primary);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            text-decoration: none;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(67, 97, 238, 0.4);
        }
        
        .btn-success { background: linear-gradient(135deg, #28a745, #218838); }
        .btn-info { background: linear-gradient(135deg, var(--info), #2589cf); }
        .btn-warning { background: linear-gradient(135deg, var(--warning), #e69500); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #c82333); }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover { transform: translateY(-5px); }
        .stat-icon { font-size: 2rem; color: var(--primary); margin-bottom: 10px; }
        .stat-number { font-size: 2rem; font-weight: bold; margin-bottom: 5px; }
        .stat-label { color: #666; font-size: 0.9rem; }
        
        .section { display: none; }
        .section.active { display: block; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px 15px; border: 1px solid #ddd; border-radius: 8px; font-size: 1rem; transition: border-color 0.3s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary); outline: none; }
        
        .required { color: var(--danger); }
        
        .success {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #dc3545;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .notification-dropdown { position: relative; }
        .notification-content { display: none; position: absolute; right: 0; top: 100%; width: 350px; background: white; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); z-index: 1000; max-height: 400px; overflow-y: auto; }
        .notification-dropdown:hover .notification-content { display: block; }
        .notification-badge { position: absolute; top: -8px; right: -8px; background: var(--danger); color: white; border-radius: 50%; width: 20px; height: 20px; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; }
        .notification-item { display: block; padding: 15px; border-bottom: 1px solid #eee; text-decoration: none; color: #333; transition: background 0.3s; }
        .notification-item:hover { background: #f8f9fa; }
        .notification-unread { background: #f0f7ff; font-weight: bold; }
        
        .logout-btn { background: var(--danger); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.3s; }
        .logout-btn:hover { background: #c82333; }
        
        .role-badge { background: var(--primary); color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; display: inline-block; }
        
        .loading-spinner { display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; background: rgba(255,255,255,0.9); padding: 30px; border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .spinner { width: 50px; height: 50px; border: 5px solid #f3f3f3; border-top: 5px solid var(--primary); border-radius: 50%; animation: spin 1s linear infinite; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .table th { background: var(--light); padding: 12px; text-align: left; border-bottom: 2px solid #ddd; }
        .table td { padding: 12px; border-bottom: 1px solid #eee; }
        .table tr:hover { background: #f8f9fa; }
        
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: bold; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-completed { background: #d1ecf1; color: #0c5460; }
        .status-payment-pending { background: #d6d8d9; color: #383d41; }
        
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
        
        .btn-sm { padding: 8px 16px !important; font-size: 0.875rem !important; }
        .status-paid { background: #d4edda; color: #155724; }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 2rem;
            margin-right: 20px;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <!-- Mobile menu button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Loading spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
        <p style="margin-top: 15px; text-align: center;">Processing...</p>
    </div>
    
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>Dorm Management</h3>
            <small>Occupant Portal</small>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=home" class="<?php echo $active_section == 'home' ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a></li>
            <li><a href="?section=reservations" class="<?php echo $active_section == 'reservations' ? 'active' : ''; ?>">
                <i class="fas fa-bed"></i> Room Reservations
            </a></li>
            <li><a href="?section=attendance" class="<?php echo $active_section == 'attendance' ? 'active' : ''; ?>">
                <i class="fas fa-chart-bar"></i> Attendance & Fines
            </a></li>
            <li><a href="?section=visitors" class="<?php echo $active_section == 'visitors' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> Visitor Management
            </a></li>
            <li><a href="?section=services" class="<?php echo $active_section == 'services' ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i> Service Requests
            </a></li>
            <li><a href="?section=announcements" class="<?php echo $active_section == 'announcements' ? 'active' : ''; ?>">
                <i class="fas fa-bullhorn"></i> Announcements
            </a></li>
            <li><a href="?section=profile" class="<?php echo $active_section == 'profile' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> My Profile
            </a></li>
            <li><a href="?section=documents" class="<?php echo $active_section == 'documents' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Documents
            </a></li>
        </ul>
        
        <!-- Current room info -->
        <?php if (!empty($current_room)): ?>
        <div class="current-room-info" style="padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); margin-top: 20px;">
            <h4 style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 8px;">Current Room</h4>
            <p style="font-weight: bold; font-size: 1.1rem;">
                Room <?php echo $current_room['room_number']; ?>
                <?php if ($current_room['floor']): ?>
                    <br><small>Floor <?php echo $current_room['floor']; ?></small>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
        
        <!-- Emergency contacts -->
        <div style="padding: 20px; border-top: 1px solid rgba(255,255,255,0.1); margin-top: auto;">
            <h4 style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 8px;">Emergency Contacts</h4>
            <div style="font-size: 0.85rem;">
                <div style="margin-bottom: 5px;">
                    <i class="fas fa-shield-alt" style="color: #4cc9f0;"></i>
                    Campus Security: 0975-543-6523
                </div>
                <div style="margin-bottom: 5px;">
                    <i class="fas fa-ambulance" style="color: #e63946;"></i>
                    Medical: 0975-656-2356
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <div>
                <h1>Occupant Dashboard</h1>
                <p style="margin-top: 5px; color: #666; font-size: 0.9rem;">
                    Welcome back, <?php echo htmlspecialchars($user_fullname); ?>!
                    <?php if (!empty($current_room)): ?>
                        • Room <?php echo $current_room['room_number']; ?>
                    <?php endif; ?>
                    • <span class="role-badge">Occupant</span>
                </p>
            </div>
<div style="display: flex; align-items: center; gap: 15px;">
    <!-- Notification Bell -->
    <div class="notification-dropdown">
        <button class="btn btn-warning" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <?php if ($unread_count > 0): ?>
                <span class="notification-badge"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </button>
        <div class="notification-content">
            <?php if (empty($notifications)): ?>
                <a href="#" style="pointer-events: none; text-align: center;">No notifications</a>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <a href="javascript:void(0)" 
                       class="notification-item <?php echo $notification['is_read'] ? '' : 'notification-unread'; ?>"
                       onclick="markNotificationAsRead(<?php echo $notification['id']; ?>)">
                        <strong><?php echo htmlspecialchars($notification['title']); ?></strong><br>
                        <small><?php echo htmlspecialchars($notification['message']); ?></small><br>
                        <small style="color: #7f8c8d;"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></small>
                    </a>
                <?php endforeach; ?>
                <div style="padding: 15px; background: #f8f9fa;">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <button type="submit" name="mark_all_notifications_read" class="btn btn-small">Mark All Read</button>
                    </form>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <button type="submit" name="clear_old_notifications" class="btn btn-small btn-danger">Clear Old</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <form action="logout.php" method="POST" class="logout-form">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <button type="submit" class="logout-btn" onclick="return confirm('Are you sure you want to logout?')">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </form>
</div>
        </div>
        
        <div class="content">
            <!-- Session warning -->
            <div id="sessionWarning" style="display: none; background: #fff3cd; border-color: #ffc107;" class="card">
                <strong><i class="fas fa-clock"></i> Session Expiring Soon</strong>
                    <p>Your session will expire in <span id="sessionTimer">5:00</span>. Please save your work.</p>
                    <button onclick="extendSession()" class="btn btn-warning" style="margin-top: 10px;">
                    <i class="fas fa-redo"></i> Extend Session
                </button>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="success" role="alert" aria-live="polite">
                    <div>
                        <i class="fas fa-check-circle"></i> 
                        <?php echo htmlspecialchars($_SESSION['success']); ?>
                    </div>
                    <button onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer; color: inherit;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['success']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="error" role="alert" aria-live="assertive">
                    <div>
                        <i class="fas fa-exclamation-circle"></i> 
                        <?php echo htmlspecialchars($_SESSION['error']); ?>
                    </div>
                    <button onclick="this.parentElement.style.display='none'" style="background:none; border:none; cursor:pointer; color: inherit;">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <!-- Home Section -->
            <div id="home-section" class="section <?php echo $active_section == 'home' ? 'active' : ''; ?>">
                <!-- Quick Stats -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-bed"></i>
                        </div>
                        <?php 
                        $pending_reservations = count(array_filter($reservations, function($r) { 
                            return isset($r['status']) && $r['status'] == 'pending'; 
                        }));
                        ?>
                        <div class="stat-number"><?php echo count($reservations); ?></div>
                        <div class="stat-label">Reservations</div>
                        <?php if ($pending_reservations > 0): ?>
                            <div style="margin-top: 10px; font-size: 0.8rem; color: var(--warning);">
                                <?php echo $pending_reservations; ?> pending
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <?php 
                        $present_count = $attendance_stats['present_count'] ?? 0;
                        $total_attendance = $attendance_stats['total'] ?? 0;
                        $attendance_rate = $total_attendance > 0 ? ($present_count / $total_attendance * 100) : 0;
                        ?>
                        <div class="stat-number"><?php echo number_format($attendance_rate, 1); ?>%</div>
                        <div class="stat-label">Attendance Rate</div>
                        <div style="margin-top: 10px; font-size: 0.8rem; color: #666;">
                            <?php echo $present_count; ?>/<?php echo $total_attendance; ?> days
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave"></i>
                        </div>
                        <?php 
                        $pending_fines = array_filter($fines, function($f) { 
                            return isset($f['status']) && $f['status'] == 'pending'; 
                        });
                        $total_pending = $fines_stats['pending_total'] ?? 0;
                        ?>
                        <div class="stat-number">₱<?php echo number_format($total_pending, 0); ?></div>
                        <div class="stat-label">Pending Fines</div>
                        <?php if (count($pending_fines) > 0): ?>
                            <div style="margin-top: 10px; font-size: 0.8rem; color: var(--danger);">
                                <?php echo count($pending_fines); ?> outstanding
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tools"></i>
                        </div>
                        <?php 
                        $active_requests = count(array_filter($service_requests, function($s) { 
                            return isset($s['status']) && in_array($s['status'], ['pending', 'in_progress']); 
                        }));
                        ?>
                        <div class="stat-number"><?php echo $active_requests; ?></div>
                        <div class="stat-label">Active Requests</div>
                        <?php if ($active_requests > 0): ?>
                            <div style="margin-top: 10px; font-size: 0.8rem; color: var(--warning);">
                                Needs attention
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Announcements -->
                <div class="card">
                    <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                    <?php if (empty($announcements)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No announcements</p>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($announcements as $announcement): ?>
                                <div style="padding: 15px; border-bottom: 1px solid #eee;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                                        <strong><?php echo htmlspecialchars($announcement['title']); ?></strong>
                                        <span style="font-size: 0.8rem; padding: 2px 8px; border-radius: 10px; background: 
                                            <?php echo $announcement['priority'] == 'urgent' ? '#f8d7da' : 
                                                   ($announcement['priority'] == 'important' ? '#fff3cd' : '#d1ecf1'); ?>">
                                            <?php echo ucfirst($announcement['priority']); ?>
                                        </span>
                                    </div>
                                    <p style="margin-bottom: 8px;"><?php echo htmlspecialchars($announcement['content']); ?></p>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: #666;">
                                        <span>By: <?php echo htmlspecialchars($announcement['author']); ?></span>
                                        <span><?php echo date('M j, Y', strtotime($announcement['created_at'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <h3><i class="fas fa-history"></i> Recent Activity</h3>
                    <?php if (empty($recent_activity)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No recent activity</p>
                    <?php else: ?>
                        <div style="max-height: 300px; overflow-y: auto;">
                            <?php foreach ($recent_activity as $activity): ?>
                                <div style="padding: 10px 0; border-bottom: 1px solid #eee;">
                                    <div style="display: flex; align-items: start; gap: 10px;">
                                        <i class="fas fa-<?php 
                                            echo strpos($activity['action'], 'reservation') !== false ? 'bed' : 
                                                 (strpos($activity['action'], 'service') !== false ? 'tools' : 
                                                 (strpos($activity['action'], 'visitor') !== false ? 'users' : 
                                                 (strpos($activity['action'], 'profile') !== false ? 'user' : 
                                                 (strpos($activity['action'], 'password') !== false ? 'key' : 'history')))); 
                                        ?>" style="color: #666; margin-top: 3px;"></i>
                                        <div style="flex: 1;">
                                            <div><?php echo htmlspecialchars($activity['details']); ?></div>
                                            <small style="color: #888;">
                                                <?php echo time_elapsed_string($activity['created_at']); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Room Reservations Section -->
            <div id="reservations-section" class="section <?php echo $active_section == 'reservations' ? 'active' : ''; ?>">
                <!-- Reserve Room Form -->
                <div class="card">
                    <h3><i class="fas fa-bed"></i> Reserve a Room</h3>
                    <form method="POST" id="reservationForm" onsubmit="return validateReservation()">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="form-group">
                            <label for="room_id">
                                <i class="fas fa-door-open"></i> Select Room
                                <span class="required">*</span>
                            </label>
                            <select name="room_id" id="room_id" required onchange="updateRoomDetails(this.value)">
                                <option value="">Choose a room...</option>
                                <?php foreach ($available_rooms as $room): 
                                    $available_spots = $room['capacity'] - $room['current_occupants'];
                                ?>
                                    <option value="<?php echo $room['id']; ?>" 
                                            data-capacity="<?php echo $room['capacity']; ?>"
                                            data-occupants="<?php echo $room['current_occupants']; ?>"
                                            data-floor="<?php echo $room['floor']; ?>"
                                            data-building="<?php echo $room['building']; ?>"
                                            data-available="<?php echo $available_spots; ?>">
                                        Room <?php echo $room['room_number']; ?> 
                                        (<?php echo $available_spots; ?> spots available)
                                        - Floor <?php echo $room['floor']; ?> - <?php echo $room['building']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="roomDetails" style="margin-top: 10px; padding: 10px; background: var(--light); border-radius: 5px; display: none;">
                                <small>Select a room to see details</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="reservation_date">
                                    <i class="fas fa-calendar"></i> Reservation Date
                                    <span class="required">*</span>
                                </label>
                                <input type="date" name="reservation_date" id="reservation_date" required 
                                       min="<?php echo date('Y-m-d'); ?>"
                                       max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                <small class="form-text">Available for next 30 days only</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="visit_time">
                                    <i class="fas fa-clock"></i> Preferred Time (Optional)
                                </label>
                                <input type="time" name="visit_time" id="visit_time">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="special_requirements">
                                <i class="fas fa-clipboard-list"></i> Special Requirements (Optional)
                            </label>
                            <textarea name="special_requirements" id="special_requirements" 
                                      placeholder="Any special requirements or notes..." 
                                      rows="3"></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="reserve_room" class="btn btn-success" id="reserveBtn">
                                <i class="fas fa-paper-plane"></i> Submit Reservation
                            </button>
                            <button type="button" class="btn btn-info" onclick="clearReservationForm()">
                                <i class="fas fa-redo"></i> Clear Form
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- My Reservations -->
                <div class="card">
                    <h3><i class="fas fa-list"></i> My Reservations</h3>
                    <?php if (empty($reservations)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No reservations found</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Date</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reservations as $reservation): ?>
                                        <tr>
                                            <td>Room <?php echo $reservation['room_number']; ?> (<?php echo $reservation['floor']; ?>)</td>
                                            <td><?php echo date('M j, Y', strtotime($reservation['reservation_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $reservation['status']; ?>">
                                                    <?php echo ucfirst($reservation['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo time_elapsed_string($reservation['created_at']); ?></td>
                                            <td>
                                                <button class="btn btn-info btn-sm" onclick="viewReservation(<?php echo $reservation['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($reservation['status'] == 'pending'): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelReservation(<?php echo $reservation['id']; ?>)">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attendance & Fines Section -->
            <div id="attendance-section" class="section <?php echo $active_section == 'attendance' ? 'active' : ''; ?>">
                <!-- Attendance Stats -->
                <div class="card">
                    <h3><i class="fas fa-chart-bar"></i> Attendance Overview</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-check-circle"></i>
                            </div>
                            <div class="stat-number"><?php echo $attendance_stats['present_count'] ?? 0; ?></div>
                            <div class="stat-label">Days Present</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-times-circle"></i>
                            </div>
                            <div class="stat-number"><?php echo $attendance_stats['absent_count'] ?? 0; ?></div>
                            <div class="stat-label">Days Absent</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-number"><?php echo $attendance_stats['late_count'] ?? 0; ?></div>
                            <div class="stat-label">Days Late</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-percentage"></i>
                            </div>
                            <?php 
                            $present_count = $attendance_stats['present_count'] ?? 0;
                            $total_count = $attendance_stats['total'] ?? 0;
                            $attendance_rate = $total_count > 0 ? ($present_count / $total_count * 100) : 0;
                            ?>
                            <div class="stat-number"><?php echo number_format($attendance_rate, 1); ?>%</div>
                            <div class="stat-label">Attendance Rate</div>
                        </div>
                    </div>
                    
                    <!-- Attendance Chart -->
                    <div style="margin-top: 20px; height: 300px;">
                        <canvas id="attendanceChart"></canvas>
                    </div>
                </div>
                
                <!-- Fines -->
                <div class="card">
                    <h3><i class="fas fa-money-bill-wave"></i> My Fines</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="stat-number">₱<?php echo number_format($fines_stats['pending_total'] ?? 0, 2); ?></div>
                            <div class="stat-label">Pending Fines</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-icon">
                                <i class="fas fa-check"></i>
                            </div>
                            <div class="stat-number">₱<?php echo number_format($fines_stats['paid_total'] ?? 0, 2); ?></div>
                            <div class="stat-label">Paid Fines</div>
                        </div>
                    </div>
                    
                    <?php if (empty($fines)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No fines found</p>
                    <?php else: ?>
                        <div style="overflow-x: auto; margin-top: 20px;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Amount</th>
                                        <th>Reason</th>
                                        <th>Due Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fines as $fine): ?>
                                        <tr>
                                            <td>₱<?php echo number_format($fine['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($fine['reason']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($fine['due_date'])); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo str_replace('_', '-', $fine['status']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $fine['status'])); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Visitor Management Section -->
            <div id="visitors-section" class="section <?php echo $active_section == 'visitors' ? 'active' : ''; ?>">
                <!-- Visitor Request Form -->
                <div class="card">
                    <h3><i class="fas fa-user-plus"></i> Request Visitor Access</h3>
                    <form method="POST" id="visitorForm" onsubmit="return validateVisitorForm()">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="visitor_name">
                                    <i class="fas fa-user"></i> Visitor Name
                                    <span class="required">*</span>
                                </label>
                                <input type="text" name="visitor_name" id="visitor_name" required 
                                       pattern="[A-Za-z\s]{2,100}" 
                                       title="Name must be 2-100 letters">
                            </div>
                            
                            <div class="form-group">
                                <label for="visitor_phone">
                                    <i class="fas fa-phone"></i> Visitor Phone
                                    <span class="required">*</span>
                                </label>
                                <input type="tel" name="visitor_phone" id="visitor_phone" required
                                       pattern="[0-9+\-\s]{10,20}"
                                       title="Valid phone number required">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="visitor_email">
                                <i class="fas fa-envelope"></i> Visitor Email (Optional)
                            </label>
                            <input type="email" name="visitor_email" id="visitor_email">
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="visit_date">
                                    <i class="fas fa-calendar"></i> Visit Date
                                    <span class="required">*</span>
                                </label>
                                <input type="date" name="visit_date" id="visit_date" required 
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="visit_time">
                                    <i class="fas fa-clock"></i> Visit Time (Optional)
                                </label>
                                <input type="time" name="visit_time" id="visit_time">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="visit_purpose">
                                <i class="fas fa-comment"></i> Purpose of Visit
                                <span class="required">*</span>
                            </label>
                            <textarea name="visit_purpose" id="visit_purpose" required rows="3" 
                                      placeholder="Please describe the purpose of the visit..."></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="submit_visitor_request" class="btn btn-success">
                                <i class="fas fa-paper-plane"></i> Submit Request
                            </button>
                            <button type="button" class="btn btn-info" onclick="clearVisitorForm()">
                                <i class="fas fa-redo"></i> Clear Form
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Visitor Requests List -->
                <div class="card">
                    <h3><i class="fas fa-list"></i> My Visitor Requests</h3>
                    <?php if (empty($visitor_requests)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No visitor requests found</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Visitor Name</th>
                                        <th>Visit Date</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visitor_requests as $request): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($request['visitor_name']); ?></td>
                                            <td><?php echo date('M j, Y', strtotime($request['visit_date'])); ?></td>
                                            <td><?php echo htmlspecialchars(substr($request['visit_purpose'], 0, 50)) . '...'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo $request['status']; ?>">
                                                    <?php echo ucfirst($request['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo time_elapsed_string($request['created_at']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Service Requests Section -->
            <div id="services-section" class="section <?php echo $active_section == 'services' ? 'active' : ''; ?>">
                <!-- Service Request Form -->
                <div class="card">
                    <h3><i class="fas fa-tools"></i> Submit Service Request</h3>
                    <form method="POST" id="serviceForm" onsubmit="return validateServiceForm()">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="service_type">
                                    <i class="fas fa-cog"></i> Service Type
                                    <span class="required">*</span>
                                </label>
                                <select name="service_type" id="service_type" required>
                                    <option value="">Select type...</option>
                                    <option value="electrical">Electrical</option>
                                    <option value="plumbing">Plumbing</option>
                                    <option value="furniture">Furniture</option>
                                    <option value="aircon">Air Conditioning</option>
                                    <option value="cleaning">Cleaning</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="urgency">
                                    <i class="fas fa-exclamation-triangle"></i> Urgency Level
                                    <span class="required">*</span>
                                </label>
                                <select name="urgency" id="urgency" required>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                    <option value="high">High</option>
                                    <option value="emergency">Emergency</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">
                                <i class="fas fa-file-alt"></i> Description
                                <span class="required">*</span>
                            </label>
                            <textarea name="description" id="description" required rows="4" 
                                      placeholder="Please describe the issue in detail..."></textarea>
                        </div>
                        
                        <div style="display: flex; gap: 10px; margin-top: 20px;">
                            <button type="submit" name="submit_service_request" class="btn btn-success">
                                <i class="fas fa-paper-plane"></i> Submit Request
                            </button>
                            <button type="button" class="btn btn-info" onclick="clearServiceForm()">
                                <i class="fas fa-redo"></i> Clear Form
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Service Requests List -->
                <div class="card">
                    <h3><i class="fas fa-list"></i> My Service Requests</h3>
                    <?php if (empty($service_requests)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No service requests found</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Urgency</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($service_requests as $request): ?>
                                        <tr>
                                            <td><?php echo ucfirst($request['service_type']); ?></td>
                                            <td><?php echo htmlspecialchars(substr($request['description'], 0, 50)) . '...'; ?></td>
                                            <td>
                                                <span style="padding: 2px 8px; border-radius: 10px; font-size: 0.8rem; background: 
                                                    <?php echo $request['urgency'] == 'emergency' ? '#f8d7da' : 
                                                           ($request['urgency'] == 'high' ? '#fff3cd' : 
                                                           ($request['urgency'] == 'medium' ? '#d1ecf1' : '#d4edda')); ?>">
                                                    <?php echo ucfirst($request['urgency']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo str_replace('_', '-', $request['status']); ?>">
                                                    <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo time_elapsed_string($request['created_at']); ?></td>
                                            <td>
                                                <button class="btn btn-info btn-sm" onclick="viewServiceRequest(<?php echo $request['id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($request['status'] == 'pending'): ?>
                                                    <button class="btn btn-danger btn-sm" onclick="cancelServiceRequest(<?php echo $request['id']; ?>)">
                                                        <i class="fas fa-times"></i> Cancel
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Announcements Section -->
            <div id="announcements-section" class="section <?php echo $active_section == 'announcements' ? 'active' : ''; ?>">
                <div class="card">
                    <h3><i class="fas fa-bullhorn"></i> All Announcements</h3>
                    <?php if (empty($announcements)): ?>
                        <p style="text-align: center; padding: 20px; color: #666;">No announcements available</p>
                    <?php else: ?>
                        <div>
                            <?php foreach ($announcements as $announcement): ?>
                                <div style="padding: 20px; border: 1px solid #eee; border-radius: 8px; margin-bottom: 20px; 
                                     background: <?php echo $announcement['priority'] == 'urgent' ? '#fff5f5' : 
                                                        ($announcement['priority'] == 'important' ? '#fffdf5' : '#f8f9fa'); ?>">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 15px;">
                                        <h4 style="margin: 0; color: var(--dark);"><?php echo htmlspecialchars($announcement['title']); ?></h4>
                                        <span style="font-size: 0.8rem; padding: 4px 12px; border-radius: 20px; font-weight: bold;
                                              background: <?php echo $announcement['priority'] == 'urgent' ? '#f8d7da' : 
                                                     ($announcement['priority'] == 'important' ? '#fff3cd' : '#d1ecf1'); ?>;">
                                            <?php echo strtoupper($announcement['priority']); ?>
                                        </span>
                                    </div>
                                    
                                    <div style="margin-bottom: 15px; line-height: 1.6;">
                                        <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                    </div>
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: center; 
                                         padding-top: 15px; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                                        <div>
                                            <i class="fas fa-user"></i>
                                            <span>Posted by: <?php echo htmlspecialchars($announcement['author']); ?></span>
                                        </div>
                                        <div>
                                            <i class="fas fa-calendar"></i>
                                            <span><?php echo date('F j, Y \a\t g:i A', strtotime($announcement['created_at'])); ?></span>
                                            <?php if ($announcement['expires_at']): ?>
                                                <br>
                                                <i class="fas fa-clock"></i>
                                                <span>Expires: <?php echo date('M j, Y', strtotime($announcement['expires_at'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="section <?php echo $active_section == 'profile' ? 'active' : ''; ?>">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('profile-info')">Profile Information</button>
                    <button class="tab-btn" onclick="showTab('change-password')">Change Password</button>
                </div>
                
                <!-- Profile Information Tab -->
                <div id="profile-info" class="tab-content active">
                    <div class="card">
                        <div class="profile-header">
                            <div class="profile-avatar">
                                <?php echo strtoupper(substr($occupant_profile['full_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <h3><?php echo htmlspecialchars($occupant_profile['full_name']); ?></h3>
                                <p style="color: #666; margin-bottom: 5px;"><?php echo $occupant_profile['email']; ?></p>
                                <p><span class="role-badge">Occupant</span></p>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="form-group">
                                <label for="full_name">
                                    <i class="fas fa-user"></i> Full Name
                                    <span class="required">*</span>
                                </label>
                                <input type="text" name="full_name" id="full_name" required 
                                       value="<?php echo htmlspecialchars($occupant_profile['full_name']); ?>" 
                                       pattern="[A-Za-z\s]{2,100}"
                                       title="Name must be 2-100 letters">
                            </div>
                            
                            <div class="form-group">
                                <label for="email">
                                    <i class="fas fa-envelope"></i> Email Address
                                </label>
                                <input type="email" value="<?php echo $occupant_profile['email']; ?>" readonly
                                       style="background: #f5f5f5; cursor: not-allowed;">
                                <small style="color: #6c757d;">Contact admin to change email</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">
                                    <i class="fas fa-phone"></i> Phone Number
                                </label>
                                <input type="text" name="phone" id="phone" 
                                       value="<?php echo htmlspecialchars($occupant_profile['phone'] ?? ''); ?>"
                                       pattern="[0-9+\-\s]{10,20}"
                                       title="Valid phone number required">
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-calendar-plus"></i> Member Since
                                </label>
                                <input type="text" readonly 
                                       value="<?php echo date('F j, Y', strtotime($occupant_profile['created_at'])); ?>">
                            </div>
                            
                            <div style="margin-top: 30px;">
                                <button type="submit" name="update_profile" class="btn btn-success">
                                    <i class="fas fa-save"></i> Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Change Password Tab -->
                <div id="change-password" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-key"></i> Change Password</h3>
                        </div>
                        <form method="POST" id="passwordChangeForm" onsubmit="return validatePassword(event)">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="form-group" style="position: relative;">
                                <label for="current_password">
                                    <i class="fas fa-lock"></i> Current Password
                                    <span class="required">*</span>
                                </label>
                                <input type="password" name="current_password" id="current_password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('current_password', this)" style="position: absolute; right: 10px; top: 38px; background: none; border: none; color: #6c757d; cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                            <div class="form-group" style="position: relative;">
                                <label for="new_password">
                                    <i class="fas fa-lock"></i> New Password
                                    <span class="required">*</span>
                                </label>
                                <input type="password" name="new_password" id="new_password" required
                                       pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$"
                                       title="Must be at least 8 characters with uppercase, lowercase, and number">
                                <button type="button" class="password-toggle" onclick="togglePassword('new_password', this)" style="position: absolute; right: 10px; top: 38px; background: none; border: none; color: #6c757d; cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <small style="color: #6c757d;">
                                    <i class="fas fa-info-circle"></i> Password must be at least 8 characters long with uppercase, lowercase, and number
                                </small>
                            </div>
                            
                            <div class="form-group" style="position: relative;">
                                <label for="confirm_password">
                                    <i class="fas fa-lock"></i> Confirm New Password
                                    <span class="required">*</span>
                                </label>
                                <input type="password" name="confirm_password" id="confirm_password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)" style="position: absolute; right: 10px; top: 38px; background: none; border: none; color: #6c757d; cursor: pointer;">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            
                            <div style="margin-top: 30px;">
                                <button type="submit" name="change_password" class="btn btn-success">
                                    <i class="fas fa-key"></i> Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Documents Section -->
            <div id="documents-section" class="section <?php echo $active_section == 'documents' ? 'active' : ''; ?>">
                <div class="card">
                    <h3><i class="fas fa-file-alt"></i> My Documents</h3>
                    <p style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-info-circle"></i> Document management feature coming soon.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Toastr for notifications -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    
    <script>
        // Initialize toastr
        toastr.options = {
            "closeButton": true,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "timeOut": "5000",
            "newestOnTop": true
        };
        
        // Tab navigation function
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Activate selected tab button
            event.target.classList.add('active');
        }
        
        // Mobile menu toggle
        document.getElementById('mobileMenuBtn').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('active');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const mobileBtn = document.getElementById('mobileMenuBtn');
            if (window.innerWidth <= 768 && sidebar.classList.contains('active') && 
                !sidebar.contains(event.target) && !mobileBtn.contains(event.target)) {
                sidebar.classList.remove('active');
            }
        });
        
        // Session timer
        let sessionTimer;
        function startSessionTimer() {
            let timeLeft = 300; // 5 minutes
            sessionTimer = setInterval(function() {
                timeLeft--;
                let minutes = Math.floor(timeLeft / 60);
                let seconds = timeLeft % 60;
                document.getElementById('sessionTimer').textContent = 
                    minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
                
                if (timeLeft <= 60) {
                    document.getElementById('sessionWarning').style.display = 'block';
                }
                
                if (timeLeft <= 0) {
                    clearInterval(sessionTimer);
                    window.location.href = 'logout.php?session=expired';
                }
            }, 1000);
        }
        
        function extendSession() {
            clearInterval(sessionTimer);
            fetch('api/extend_session.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        toastr.success('Session extended successfully');
                        document.getElementById('sessionWarning').style.display = 'none';
                        startSessionTimer();
                    }
                })
                .catch(error => console.error('Error extending session:', error));
        }
        
        // Start session timer
        startSessionTimer();
        
        // Form validation functions
        function validateReservation() {
            const roomId = document.getElementById('room_id').value;
            const date = document.getElementById('reservation_date').value;
            
            if (!roomId || !date) {
                toastr.error('Please fill in all required fields');
                return false;
            }
            
            if (new Date(date) < new Date().setHours(0,0,0,0)) {
                toastr.error('Reservation date cannot be in the past');
                return false;
            }
            
            showLoading();
            return true;
        }
        
        function validateVisitorForm() {
            const visitorName = document.getElementById('visitor_name').value;
            const visitorPhone = document.getElementById('visitor_phone').value;
            const visitDate = document.getElementById('visit_date').value;
            const visitPurpose = document.getElementById('visit_purpose').value;
            
            if (!visitorName || !visitorPhone || !visitDate || !visitPurpose) {
                toastr.error('Please fill in all required fields');
                return false;
            }
            
            if (new Date(visitDate) < new Date().setHours(0,0,0,0)) {
                toastr.error('Visit date cannot be in the past');
                return false;
            }
            
            if (!visitorPhone.match(/^[0-9+\-\s]{10,20}$/)) {
                toastr.error('Please enter a valid phone number');
                return false;
            }
            
            showLoading();
            return true;
        }
        
        function validateServiceForm() {
            const serviceType = document.getElementById('service_type').value;
            const urgency = document.getElementById('urgency').value;
            const description = document.getElementById('description').value;
            
            if (!serviceType || !urgency || !description) {
                toastr.error('Please fill in all required fields');
                return false;
            }
            
            if (description.length < 10) {
                toastr.error('Please provide a more detailed description');
                return false;
            }
            
            showLoading();
            return true;
        }
        
        function validateProfile() {
            const fullName = document.getElementById('full_name').value;
            const phone = document.getElementById('phone').value;
            
            if (!fullName.match(/^[A-Za-z\s]{2,100}$/)) {
                toastr.error('Please enter a valid name (2-100 letters)');
                return false;
            }
            
            if (phone && !phone.match(/^[0-9+\-\s]{10,20}$/)) {
                toastr.error('Please enter a valid phone number');
                return false;
            }
            
            showLoading();
            return true;
        }
        
        // Password toggle visibility
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Password validation with better debugging
        function validatePassword(event) {
            console.log('validatePassword called');
            
            // Get form elements
            const currentPassword = document.getElementById('current_password');
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (!currentPassword || !newPassword || !confirmPassword) {
                console.error('Password form elements not found');
                toastr.error('Form elements not found');
                return false;
            }
            
            const current = currentPassword.value.trim();
            const newPass = newPassword.value;
            const confirm = confirmPassword.value;
            
            console.log('Current password entered:', current.length > 0);
            console.log('New password entered:', newPass.length > 0);
            console.log('Confirm password entered:', confirm.length > 0);
            
            // Validate all fields are filled
            if (!current || !newPass || !confirm) {
                toastr.error('All password fields are required');
                console.log('Missing fields validation failed');
                return false;
            }
            
            // Check minimum length
            if (newPass.length < 8) {
                toastr.error('Password must be at least 8 characters long');
                console.log('Length validation failed');
                return false;
            }
            
            // Check password strength
            const hasUpper = /[A-Z]/.test(newPass);
            const hasLower = /[a-z]/.test(newPass);
            const hasNumber = /[0-9]/.test(newPass);
            
            if (!hasUpper || !hasLower || !hasNumber) {
                toastr.error('Password must contain at least one uppercase letter, one lowercase letter, and one number');
                console.log('Strength validation failed');
                return false;
            }
            
            // Check if passwords match
            if (newPass !== confirm) {
                toastr.error('New passwords do not match');
                console.log('Password match validation failed');
                return false;
            }
            
            console.log('All validations passed, submitting form...');
            showLoading();
            
            // Return true to submit form
            return true;
        }
        
        // Loading spinner
        function showLoading() {
            document.getElementById('loadingSpinner').style.display = 'block';
        }
        
        function hideLoading() {
            document.getElementById('loadingSpinner').style.display = 'none';
        }
        
        // Room details update
        function updateRoomDetails(roomId) {
            const select = document.getElementById('room_id');
            const option = select.options[select.selectedIndex];
            const details = document.getElementById('roomDetails');
            
            if (roomId && option) {
                const availableSpots = option.dataset.available;
                details.innerHTML = `
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div>
                            <strong>Building:</strong><br>
                            ${option.dataset.building}
                        </div>
                        <div>
                            <strong>Floor:</strong><br>
                            ${option.dataset.floor}
                        </div>
                        <div>
                            <strong>Capacity:</strong><br>
                            ${option.dataset.capacity} total spots
                        </div>
                        <div>
                            <strong>Available:</strong><br>
                            <span style="color: ${availableSpots > 0 ? 'var(--success)' : 'var(--danger)'}">
                                ${availableSpots} spots available
                            </span>
                        </div>
                    </div>
                    ${availableSpots <= 0 ? 
                        '<div style="margin-top: 10px; padding: 5px; background: #fff3cd; border-radius: 3px;">' +
                        '<i class="fas fa-exclamation-triangle"></i> Room is at full capacity' +
                        '</div>' : ''}
                `;
                details.style.display = 'block';
            } else {
                details.style.display = 'none';
            }
        }
        
        // Clear form functions
        function clearReservationForm() {
            document.getElementById('reservationForm').reset();
            document.getElementById('roomDetails').style.display = 'none';
            document.getElementById('reservation_date').value = '<?php echo date('Y-m-d'); ?>';
        }
        
        function clearVisitorForm() {
            document.getElementById('visitorForm').reset();
            document.getElementById('visit_date').value = '<?php echo date('Y-m-d'); ?>';
        }
        
        function clearServiceForm() {
            document.getElementById('serviceForm').reset();
        }
        
        
        
        // Notification functions
        function markNotificationAsRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId + '&csrf_token=<?php echo $csrf_token; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    toastr.error('Failed to mark notification as read');
                }
            })
            .catch(error => {
                toastr.error('Network error');
                console.error('Notification error:', error);
            });
        }
        
        function markAllNotificationsRead() {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'csrf_token=<?php echo $csrf_token; ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    toastr.error('Failed to mark all notifications as read');
                }
            })
            .catch(error => {
                toastr.error('Network error');
                console.error('Notification error:', error);
            });
        }
        
        // Action functions
        function viewReservation(reservationId) {
            alert('Viewing reservation #' + reservationId + '\nFeature coming soon.');
        }
        
        function cancelReservation(reservationId) {
            if (confirm('Are you sure you want to cancel this reservation?')) {
                showLoading();
                fetch('cancel_reservation.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'reservation_id=' + reservationId + '&csrf_token=<?php echo $csrf_token; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        hideLoading();
                        toastr.error(data.error || 'Failed to cancel reservation');
                    }
                })
                .catch(error => {
                    hideLoading();
                    toastr.error('Network error');
                    console.error('Cancel error:', error);
                });
            }
        }
        
        function viewServiceRequest(requestId) {
            alert('Viewing service request #' + requestId + '\nFeature coming soon.');
        }
        
        function cancelServiceRequest(requestId) {
            if (confirm('Are you sure you want to cancel this service request?')) {
                showLoading();
                fetch('cancel_service_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'request_id=' + requestId + '&csrf_token=<?php echo $csrf_token; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        hideLoading();
                        toastr.error(data.error || 'Failed to cancel service request');
                    }
                })
                .catch(error => {
                    hideLoading();
                    toastr.error('Network error');
                    console.error('Cancel error:', error);
                });
            }
        }
        
        // Initialize when page loads
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Dashboard initialized');
            
            // Add today's date to date inputs
            const today = new Date().toISOString().split('T')[0];
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (!input.value && !input.hasAttribute('readonly')) {
                    input.value = today;
                }
            });
            
            // Set max date for reservation and visitor forms
            const maxDate = new Date();
            maxDate.setDate(maxDate.getDate() + 30);
            const maxDateStr = maxDate.toISOString().split('T')[0];
            
            document.getElementById('reservation_date')?.setAttribute('max', maxDateStr);
            document.getElementById('visit_date')?.setAttribute('max', maxDateStr);
            
            // Show toastr for success/error messages
            const successElements = document.querySelectorAll('.success');
            successElements.forEach(el => {
                const text = el.querySelector('div').textContent.trim().replace(/×$/, '');
                if (text) {
                    toastr.success(text.replace(/^✓\s*/, ''));
                    console.log('Showing success message:', text);
                }
            });
            
            const errorElements = document.querySelectorAll('.error');
            errorElements.forEach(el => {
                const text = el.querySelector('div').textContent.trim().replace(/×$/, '');
                if (text) {
                    toastr.error(text.replace(/^⚠\s*/, ''));
                    console.log('Showing error message:', text);
                }
            });
            
            // Initialize attendance chart if section is active
            if (document.getElementById('attendance-section').classList.contains('active')) {
                initializeAttendanceChart();
            }
            
            // Auto-hide messages after 5 seconds
            setTimeout(function() {
                const messages = document.querySelectorAll('.success, .error');
                messages.forEach(msg => {
                    if (msg.style.display !== 'none') {
                        msg.style.opacity = '0';
                        setTimeout(() => msg.style.display = 'none', 500);
                    }
                });
            }, 5000);
        });
        
        // Initialize attendance chart
        function initializeAttendanceChart() {
            const ctx = document.getElementById('attendanceChart');
            if (!ctx) return;
            
            // Get attendance data from PHP
            const attendanceData = <?php echo json_encode($attendance); ?>;
            
            // Process last 7 days
            const labels = [];
            const presentData = [];
            const absentData = [];
            
            for (let i = 6; i >= 0; i--) {
                const date = new Date();
                date.setDate(date.getDate() - i);
                const dateStr = date.toISOString().split('T')[0];
                labels.push(date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
                
                const dayAttendance = attendanceData.find(a => a.date === dateStr);
                if (dayAttendance) {
                    presentData.push(dayAttendance.status === 'present' ? 1 : 0);
                    absentData.push(dayAttendance.status !== 'present' ? 1 : 0);
                } else {
                    presentData.push(0);
                    absentData.push(0);
                }
            }
            
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Present',
                            data: presentData,
                            backgroundColor: '#4cc9f0',
                            borderColor: '#4cc9f0',
                            borderWidth: 1
                        },
                        {
                            label: 'Absent/Late',
                            data: absentData,
                            backgroundColor: '#e63946',
                            borderColor: '#e63946',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 1,
                            ticks: {
                                callback: function(value) {
                                    return value === 1 ? 'Yes' : 'No';
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Last 7 Days Attendance'
                        }
                    }
                }
            });
        }
        
        // Offline detection
        window.addEventListener('offline', function() {
            toastr.warning('You are offline. Some features may not work.');
        });
        
        window.addEventListener('online', function() {
            toastr.success('You are back online!');
        });
        
        // Before unload warning for unsaved changes
        let formChanged = false;
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', () => formChanged = true);
                input.addEventListener('change', () => formChanged = true);
            });
        });
        
        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
        
        // Form submit handlers
        forms.forEach(form => {
            form.addEventListener('submit', () => formChanged = false);
        });
        
        // Accessibility improvements
        document.addEventListener('keydown', function(e) {
            // Close sidebar on Escape
            if (e.key === 'Escape' && window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('active');
            }
            
            // Submit form on Ctrl+Enter
            if (e.ctrlKey && e.key === 'Enter') {
                const activeForm = document.querySelector('.section.active form');
                if (activeForm) {
                    activeForm.submit();
                }
            }
        });
        
        // Focus management for accessibility
        document.querySelectorAll('a, button, input, select, textarea').forEach(element => {
            element.addEventListener('focus', function() {
                this.style.outline = '2px solid var(--primary)';
                this.style.outlineOffset = '2px';
            });
            
            element.addEventListener('blur', function() {
                this.style.outline = '';
            });
        });
    </script>
</body>
</html>