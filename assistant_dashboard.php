<?php
// assistant_dashboard.php
include 'config.php';

// Enhanced security check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['user_type'] != 'dormdean_assistant') {
    header("Location: login.php");
    exit();
}

// Set session creation time for expiration check
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
}

// Check for session expiration (8 hours)
if (time() - $_SESSION['created_at'] > 28800) {
    session_destroy();
    header("Location: login.php?expired=1");
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Security token validation failed.';
        header("Location: assistant_dashboard.php");
        exit();
    }
    
    if (isset($_POST['record_attendance'])) {
        $user_id = sanitizeInput($_POST['user_id']);
        $date = sanitizeInput($_POST['date']);
        $status = sanitizeInput($_POST['status']);
        $check_in_time = sanitizeInput($_POST['check_in_time']);
        $check_out_time = sanitizeInput($_POST['check_out_time']);
        $notes = sanitizeInput($_POST['notes']);
        recordAttendance($user_id, $date, $status, $check_in_time, $check_out_time, $notes);
    }
    elseif (isset($_POST['bulk_attendance'])) {
        $date = sanitizeInput($_POST['bulk_date']);
        $attendance_data = $_POST['attendance'] ?? [];
        recordBulkAttendance($date, $attendance_data);
    }
    elseif (isset($_POST['add_fine'])) {
        $user_id = sanitizeInput($_POST['user_id']);
        $amount = sanitizeInput($_POST['amount']);
        $reason = sanitizeInput($_POST['reason']);
        $due_date = sanitizeInput($_POST['due_date']);
        addFine($user_id, $amount, $reason, $due_date);
    }
    elseif (isset($_POST['update_fine_status'])) {
        $fine_id = sanitizeInput($_POST['fine_id']);
        $status = sanitizeInput($_POST['status']);
        updateFineStatus($fine_id, $status);
    }
    elseif (isset($_POST['update_service_status'])) {
        $request_id = sanitizeInput($_POST['request_id']);
        $status = sanitizeInput($_POST['status']);
        $assigned_to = sanitizeInput($_POST['assigned_to']);
        updateServiceRequestStatus($request_id, $status, $assigned_to);
    }
    elseif (isset($_POST['approve_visitor'])) {
        $request_id = sanitizeInput($_POST['request_id']);
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE visitor_requests SET status = 'approved', approved_by = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
        if ($stmt->execute()) {
            // notify requester
            $u = $conn->prepare("SELECT user_id FROM visitor_requests WHERE id = ?");
            $u->bind_param("i", $request_id);
            $u->execute();
            $u->bind_result($user_id);
            $u->fetch();
            $u->close();
            if ($user_id) createNotification($user_id, 'Visitor Request Approved', 'Your visitor request has been approved.', 'visitor', $request_id);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'APPROVE_VISITOR', "Approved visitor request ID: $request_id");
            
            $_SESSION['success'] = 'Visitor request approved.';
        } else {
            $_SESSION['error'] = 'Failed to approve visitor request.';
        }
        $stmt->close();
    }
    elseif (isset($_POST['reject_visitor'])) {
        $request_id = sanitizeInput($_POST['request_id']);
        $reason = sanitizeInput($_POST['reject_reason'] ?? 'Not specified');
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE visitor_requests SET status = 'rejected', approved_by = ?, updated_at = NOW(), rejection_reason = ? WHERE id = ?");
        $stmt->bind_param("isi", $_SESSION['user_id'], $reason, $request_id);
        if ($stmt->execute()) {
            $u = $conn->prepare("SELECT user_id FROM visitor_requests WHERE id = ?");
            $u->bind_param("i", $request_id);
            $u->execute();
            $u->bind_result($user_id);
            $u->fetch();
            $u->close();
            if ($user_id) createNotification($user_id, 'Visitor Request Rejected', 'Your visitor request has been rejected. Reason: ' . $reason, 'visitor', $request_id);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'REJECT_VISITOR', "Rejected visitor request ID: $request_id, Reason: $reason");
            
            $_SESSION['success'] = 'Visitor request rejected.';
        } else {
            $_SESSION['error'] = 'Failed to reject visitor request.';
        }
        $stmt->close();
    }
    elseif (isset($_POST['approve_reservation'])) {
        $reservation_id = sanitizeInput($_POST['reservation_id']);
        // simple approve logic similar to dorm dean
        $db = new Database();
        $conn = $db->getConnection();
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE reservations SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
            $stmt->bind_param("ii", $_SESSION['user_id'], $reservation_id);
            $stmt->execute();

            // increment room occupants
            $r = $conn->prepare("SELECT room_id, user_id FROM reservations WHERE id = ?");
            $r->bind_param("i", $reservation_id);
            $r->execute();
            $r->bind_result($room_id, $user_id);
            $r->fetch();
            $r->close();

            if (!empty($room_id)) {
                $u2 = $conn->prepare("UPDATE rooms SET current_occupants = current_occupants + 1 WHERE id = ?");
                $u2->bind_param("i", $room_id);
                $u2->execute();
                $u2->close();
            }

            if (!empty($user_id)) createNotification($user_id, 'Reservation Approved', 'Your reservation has been approved.', 'reservation', $reservation_id);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'APPROVE_RESERVATION', "Approved reservation ID: $reservation_id");

            $conn->commit();
            $_SESSION['success'] = 'Reservation approved.';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = 'Failed to approve reservation.';
        }
    }
    elseif (isset($_POST['reject_reservation'])) {
        $reservation_id = sanitizeInput($_POST['reservation_id']);
        $reason = sanitizeInput($_POST['reject_reason'] ?? 'Not specified');
        $db = new Database();
        $conn = $db->getConnection();
        $stmt = $conn->prepare("UPDATE reservations SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
        $stmt->bind_param("isi", $_SESSION['user_id'], $reason, $reservation_id);
        if ($stmt->execute()) {
            $u = $conn->prepare("SELECT user_id FROM reservations WHERE id = ?");
            $u->bind_param("i", $reservation_id);
            $u->execute();
            $u->bind_result($user_id);
            $u->fetch();
            $u->close();
            if ($user_id) createNotification($user_id, 'Reservation Rejected', 'Your reservation has been rejected. Reason: ' . $reason, 'reservation', $reservation_id);
            
            // Log activity
            logActivity($_SESSION['user_id'], 'REJECT_RESERVATION', "Rejected reservation ID: $reservation_id, Reason: $reason");
            
            $_SESSION['success'] = 'Reservation rejected.';
        } else {
            $_SESSION['error'] = 'Failed to reject reservation.';
        }
        $stmt->close();
    }
    elseif (isset($_POST['export_attendance'])) {
        $date_from = sanitizeInput($_POST['export_date_from']);
        $date_to = sanitizeInput($_POST['export_date_to']);
        exportAttendanceCSV($date_from, $date_to);
        exit();
    }
    elseif (isset($_POST['update_profile'])) {
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        updateAssistantProfile($_SESSION['user_id'], $full_name, $phone);
    }
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        changeAssistantPassword($_SESSION['user_id'], $current_password, $new_password, $confirm_password);
    }
    elseif (isset($_POST['mark_all_notifications_read'])) {
        // This now uses the function from config.php
        markAllNotificationsAsRead($_SESSION['user_id']);
    }
    elseif (isset($_POST['clear_old_notifications'])) {
        clearOldNotifications($_SESSION['user_id']);
    }
}

// Record attendance function
function recordAttendance($user_id, $date, $status, $check_in_time = null, $check_out_time = null, $notes = '') {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if attendance already exists for this user and date
    $stmt = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->bind_param("is", $user_id, $date);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows > 0) {
        // Update existing record
        $stmt->close();
        $stmt = $conn->prepare("UPDATE attendance SET status = ?, check_in_time = ?, check_out_time = ?, notes = ? WHERE user_id = ? AND date = ?");
        $stmt->bind_param("ssssis", $status, $check_in_time, $check_out_time, $notes, $user_id, $date);
    } else {
        // Insert new record
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO attendance (user_id, date, status, check_in_time, check_out_time, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isssss", $user_id, $date, $status, $check_in_time, $check_out_time, $notes);
    }
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($_SESSION['user_id'], 'RECORD_ATTENDANCE', "User ID: $user_id, Date: $date, Status: $status");
        
        $_SESSION['success'] = "Attendance recorded successfully!";
        
        // Create notification for occupant
        createNotification($user_id, 'Attendance Recorded', "Your attendance for $date has been marked as $status.", 'system');
    } else {
        $_SESSION['error'] = "Failed to record attendance. Please try again.";
    }
    $stmt->close();
}

// Record bulk attendance
function recordBulkAttendance($date, $attendance_data) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($attendance_data as $user_id => $status) {
        $user_id = sanitizeInput($user_id);
        $status = sanitizeInput($status);
        
        // Check if attendance already exists
        $check = $conn->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
        $check->bind_param("is", $user_id, $date);
        $check->execute();
        $check->store_result();
        
        if ($check->num_rows > 0) {
            // Update existing record
            $check->close();
            $stmt = $conn->prepare("UPDATE attendance SET status = ? WHERE user_id = ? AND date = ?");
            $stmt->bind_param("sis", $status, $user_id, $date);
        } else {
            // Insert new record
            $check->close();
            $stmt = $conn->prepare("INSERT INTO attendance (user_id, date, status) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $date, $status);
        }
        
        if ($stmt->execute()) {
            $success_count++;
            createNotification($user_id, 'Attendance Recorded', "Your attendance for $date has been marked as $status.", 'system');
        } else {
            $error_count++;
        }
        
        // Close statements for this iteration
        $check->close();
        $stmt->close();
    }
    
    // Close database connection
    $conn->close();
    
    // Log activity
    logActivity($_SESSION['user_id'], 'BULK_ATTENDANCE', "Date: $date, Success: $success_count, Errors: $error_count");
    
    $_SESSION['success'] = "Bulk attendance recorded: $success_count successful, $error_count failed.";
}

// Add fine function
function addFine($user_id, $amount, $reason, $due_date) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("INSERT INTO fines (user_id, amount, reason, due_date) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("idss", $user_id, $amount, $reason, $due_date);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($_SESSION['user_id'], 'ADD_FINE', "User ID: $user_id, Amount: $amount, Reason: $reason");
        
        $_SESSION['success'] = "Fine added successfully!";
        
        // Create notification for occupant
        createNotification($user_id, 'Fine Issued', "A fine of ₱$amount has been issued. Reason: $reason", 'system');
    } else {
        $_SESSION['error'] = "Failed to add fine. Please try again.";
    }
    $stmt->close();
    $conn->close();
}

// Update fine status function
function updateFineStatus($fine_id, $status) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE fines SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $status, $fine_id);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($_SESSION['user_id'], 'UPDATE_FINE_STATUS', "Fine ID: $fine_id, New Status: $status");
        
        $_SESSION['success'] = "Fine status updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update fine status. Please try again.";
    }
    $stmt->close();
}

// Update service request status function
function updateServiceRequestStatus($request_id, $status, $assigned_to = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    if ($assigned_to) {
        $stmt = $conn->prepare("UPDATE service_requests SET status = ?, assigned_to = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("sii", $status, $assigned_to, $request_id);
    } else {
        $stmt = $conn->prepare("UPDATE service_requests SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("si", $status, $request_id);
    }
    
    if ($stmt->execute()) {
        // Get user_id for notification
        $user_stmt = $conn->prepare("SELECT user_id FROM service_requests WHERE id = ?");
        $user_stmt->bind_param("i", $request_id);
        $user_stmt->execute();
        $user_stmt->bind_result($user_id);
        $user_stmt->fetch();
        $user_stmt->close();
        
        if ($user_id) {
            createNotification($user_id, 'Service Request Updated', "Your service request status has been updated to: $status", 'system');
        }
        
        // Log activity
        logActivity($_SESSION['user_id'], 'UPDATE_SERVICE_REQUEST', "Request ID: $request_id, Status: $status, Assigned To: $assigned_to");
        
        $_SESSION['success'] = "Service request updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update service request. Please try again.";
    }
    $stmt->close();
}

// Update assistant profile
function updateAssistantProfile($user_id, $full_name, $phone) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE id = ?");
    $stmt->bind_param("ssi", $full_name, $phone, $user_id);
    
    if ($stmt->execute()) {
        // Update session
        $_SESSION['full_name'] = $full_name;
        
        // Log activity
        logActivity($user_id, 'UPDATE_PROFILE', "Updated profile information");
        
        $_SESSION['success'] = "Profile updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update profile.";
    }
    $stmt->close();
}

// Change assistant password
function changeAssistantPassword($user_id, $current_password, $new_password, $confirm_password) {
    if ($new_password !== $confirm_password) {
        $_SESSION['error'] = "New passwords do not match.";
        return;
    }
    
    if (strlen($new_password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters long.";
        return;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($hashed_password);
    $stmt->fetch();
    $stmt->close();
    
    if (!password_verify($current_password, $hashed_password)) {
        $_SESSION['error'] = "Current password is incorrect.";
        return;
    }
    
    // Update password
    $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $new_hashed_password, $user_id);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($user_id, 'CHANGE_PASSWORD', "Password changed successfully");
        
        $_SESSION['success'] = "Password changed successfully!";
    } else {
        $_SESSION['error'] = "Failed to change password.";
    }
    $stmt->close();
}

// Clear old notifications (older than 30 days)
function clearOldNotifications($user_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("DELETE FROM notifications WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->bind_param("i", $user_id);
    
    if ($stmt->execute()) {
        $deleted_count = $stmt->affected_rows;
        
        // Log activity
        logActivity($user_id, 'CLEAR_OLD_NOTIFICATIONS', "Cleared $deleted_count old notifications");
        
        $_SESSION['success'] = "Cleared $deleted_count old notifications!";
    } else {
        $_SESSION['error'] = "Failed to clear notifications.";
    }
    $stmt->close();
}

// Export attendance to CSV and force download
function exportAttendanceCSV($date_from, $date_to) {
    $csv_data = exportAttendanceToCSV($date_from, $date_to);
    
    // Log activity
    logActivity($_SESSION['user_id'], 'EXPORT_ATTENDANCE', "Date range: $date_from to $date_to");
    
    // Set headers for download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="attendance_' . $date_from . '_to_' . $date_to . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $csv_data;
    exit();
}

// Get all occupants
function getAllOccupants() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT id, full_name, email, phone FROM users WHERE user_type = 'occupant' AND is_active = TRUE ORDER BY full_name");
    $stmt->execute();
    $result = $stmt->get_result();
    $occupants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $occupants;
}

// Search occupants
function searchOccupantsWrapper($search_term) {
    if (empty($search_term)) {
        return getAllOccupants();
    }
    return searchOccupants($search_term);
}

// Get all fines
function getAllFines() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT f.*, u.full_name, u.email 
        FROM fines f 
        JOIN users u ON f.user_id = u.id 
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $fines = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $fines;
}

// Get service requests
function getServiceRequests($status = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    if ($status) {
        $stmt = $conn->prepare("
            SELECT sr.*, u.full_name, u.email 
            FROM service_requests sr 
            JOIN users u ON sr.user_id = u.id 
            WHERE sr.status = ?
            ORDER BY sr.urgency DESC, sr.created_at DESC
        ");
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare("
            SELECT sr.*, u.full_name, u.email 
            FROM service_requests sr 
            JOIN users u ON sr.user_id = u.id 
            ORDER BY sr.urgency DESC, sr.created_at DESC
        ");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $requests;
}

// Get pending visitor requests (for approvals)
function getPendingVisitorRequests() {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT vr.*, u.full_name, u.email FROM visitor_requests vr JOIN users u ON vr.user_id = u.id WHERE vr.status = 'pending' ORDER BY vr.visit_date ASC, vr.created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $requests;
}

// Get pending reservations (for approvals)
function getPendingReservationsForApproval() {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT r.*, u.full_name, u.email, rm.room_number FROM reservations r JOIN users u ON r.user_id = u.id JOIN rooms rm ON r.room_id = rm.id WHERE r.status = 'pending' ORDER BY r.created_at DESC");
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $reservations;
}

// Get attendance records
function getAttendanceRecords($date = null, $user_id = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $query = "
        SELECT a.*, u.full_name, u.email 
        FROM attendance a 
        JOIN users u ON a.user_id = u.id 
        WHERE 1=1
    ";
    
    $params = [];
    $types = '';
    
    if ($date) {
        $query .= " AND a.date = ?";
        $params[] = $date;
        $types .= 's';
    }
    
    if ($user_id) {
        $query .= " AND a.user_id = ?";
        $params[] = $user_id;
        $types .= 'i';
    }
    
    $query .= " ORDER BY a.date DESC, u.full_name";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $records = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $records;
}

// Get inventory items
function getInventoryItems() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT i.*, r.room_number 
        FROM inventory i 
        LEFT JOIN rooms r ON i.room_id = r.id 
        ORDER BY i.category, i.item_name
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $items;
}

// Get assistant profile
function getAssistantProfile($user_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT full_name, email, phone, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $profile = $result->fetch_assoc();
    $stmt->close();
    
    return $profile;
}

// Get activity logs for assistant
function getAssistantActivity($limit = 20) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT * FROM activity_logs 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("ii", $_SESSION['user_id'], $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $logs;
}

// Get dashboard statistics
$stats = getEnhancedDashboardStats($_SESSION['user_id']);

// Get data for sections
$occupants = getAllOccupants();
$fines = getAllFines();
$service_requests = getServiceRequests();
$pending_visitors = getPendingVisitorRequests();
$pending_reservations = getPendingReservationsForApproval();
$today_attendance = getAttendanceRecords(date('Y-m-d'));
$inventory_items = getInventoryItems();
$assistant_profile = getAssistantProfile($_SESSION['user_id']);
$activity_logs = getAssistantActivity(15);
$emergency_contacts = getEmergencyContacts();
$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
$notifications = getNotifications($_SESSION['user_id'], 10);

// Generate CSRF token
$csrf_token = generateCSRFToken();

// Determine active section (defaults to home)
$active_section = isset($_GET['section']) ? sanitizeInput($_GET['section']) : 'home';

// Handle search if present
$search_term = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
if ($search_term && $active_section == 'occupants') {
    $occupants = searchOccupantsWrapper($search_term);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Dashboard - Dorm Management</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
            --header-height: 80px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f7fb; 
            color: #333;
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dark), #0d1b2a);
            color: white;
            height: 100vh;
            position: fixed;
            overflow-y: auto;
            box-shadow: 5px 0 15px rgba(0,0,0,0.1);
            z-index: 1000;
            transition: all 0.3s ease;
        }
        
        .sidebar-header {
            padding: 30px 25px;
            background: rgba(255,255,255,0.05);
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header h3 {
            font-size: 1.5rem;
            margin-bottom: 5px;
            font-weight: 700;
        }
        
        .sidebar-header small {
            opacity: 0.7;
            font-size: 0.85rem;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .sidebar-menu li {
            margin: 5px 15px;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            font-weight: 500;
            border-radius: 12px;
        }
        
        .sidebar-menu a i {
            margin-right: 12px;
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
        }
        
        .sidebar-menu a:hover, .sidebar-menu a.active {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.3);
        }
        
        .main-content {
            margin-left: var(--sidebar-width);
            flex: 1;
            padding: 0;
            transition: all 0.3s ease;
        }
        
        .header { 
            background: white;
            color: var(--dark); 
            padding: 0 30px; 
            height: var(--header-height);
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .content { 
            padding: 30px; 
        }
        
        .card { 
            background: white; 
            padding: 25px; 
            border-radius: 16px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.05); 
            margin-bottom: 25px; 
            border: 1px solid rgba(0,0,0,0.03);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .card-header h3 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .btn { 
            padding: 12px 24px; 
            background: var(--primary); 
            color: white; 
            text-decoration: none; 
            border-radius: 10px; 
            border: none; 
            cursor: pointer; 
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            font-weight: 500;
            font-size: 0.9rem;
        }
        
        .btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .btn-success { background: var(--success); }
        .btn-success:hover { background: #3ab8e0; }
        
        .btn-danger { background: var(--danger); }
        .btn-danger:hover { background: #d32f2f; }
        
        .btn-warning { background: var(--warning); color: white; }
        .btn-warning:hover { background: #e59400; }
        
        .btn-info { background: var(--info); }
        .btn-info:hover { background: #3a86e9; }
        
        .btn-small {
            padding: 8px 16px;
            font-size: 0.8rem;
        }
        
        .user-info { 
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; 
            padding: 20px; 
            border-radius: 12px; 
            margin-bottom: 25px; 
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.2);
        }
        
        .logout-form { display: inline; }
        .logout-btn { 
            background: var(--primary); 
            color: white; 
            border: none; 
            padding: 10px 18px; 
            border-radius: 8px; 
            cursor: pointer; 
            margin-left: 15px; 
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(67,97,238,0.15);
            font-weight: 600;
        }

        .logout-btn:hover {
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(67,97,238,0.18);
        }
        
        .notification-badge { 
            background: var(--danger); 
            color: white; 
            border-radius: 50%; 
            padding: 2px 6px; 
            font-size: 0.7rem; 
            margin-left: 5px; 
        }
        
        .notification-dropdown { 
            position: relative; 
            display: inline-block; 
        }
        
        .notification-btn {
            background: transparent;
            border: none;
            color: var(--dark);
            font-size: 1.2rem;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s;
            position: relative;
        }
        
        .notification-btn:hover {
            background: rgba(0,0,0,0.05);
        }
        
        .notification-content { 
            display: none; 
            position: absolute; 
            right: 0; 
            top: 50px;
            background: white; 
            width: 350px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.15); 
            z-index: 1000; 
            border-radius: 12px; 
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        .notification-content a { 
            color: black; 
            padding: 15px; 
            text-decoration: none; 
            display: block; 
            border-bottom: 1px solid #eee; 
            transition: background 0.2s;
        }
        
        .notification-content a:hover { background: #f8f9fa; }
        .notification-dropdown:hover .notification-content { display: block; }
        
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
        
        .form-group { 
            margin-bottom: 20px; 
        }
        
        label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 600; 
            color: var(--dark);
        }
        
        input, select, textarea { 
            width: 100%; 
            padding: 12px 15px; 
            border: 2px solid #e9ecef; 
            border-radius: 10px; 
            box-sizing: border-box;
            font-family: inherit;
            transition: border 0.3s;
            background: #f8f9fa;
        }
        
        input:focus, select:focus, textarea:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
            background: white;
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .success { 
            background: #e6f7e6; 
            color: #2e7d32; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            border-left: 4px solid var(--success);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .error { 
            background: #ffe6e6; 
            color: #721c24; 
            padding: 15px; 
            border-radius: 10px; 
            margin-bottom: 20px; 
            border-left: 4px solid var(--danger);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section {
            display: none;
        }
        
        .section.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            text-align: center;
            border-top: 4px solid var(--primary);
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
            opacity: 0.8;
        }
        
        .stat-number {
            font-size: 2.2rem;
            font-weight: bold;
            color: var(--dark);
            margin: 10px 0;
        }
        
        .stat-label {
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.85rem;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.03);
        }
        
        .table th, .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--dark);
        }
        
        .status-pending { 
            background: #fff3cd; 
            color: #856404; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-approved { 
            background: #d4edda; 
            color: #155724; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-rejected { 
            background: #f8d7da; 
            color: #721c24; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-completed { 
            background: #d4edda; 
            color: #155724; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-in_progress { 
            background: #cce7ff; 
            color: #004085; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-paid { 
            background: #d4edda; 
            color: #155724; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-payment_pending { 
            background: #fff3cd; 
            color: #856404; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-present { 
            background: #d4edda; 
            color: #155724; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-absent { 
            background: #f8d7da; 
            color: #721c24; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-late { 
            background: #fff3cd; 
            color: #856404; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        .status-excused { 
            background: #cce7ff; 
            color: #004085; 
            padding: 5px 10px; 
            border-radius: 20px; 
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .fine-item, .request-item, .attendance-item, .visitor-item, .reservation-item { 
            border: 1px solid #e9ecef; 
            padding: 20px; 
            margin: 15px 0; 
            border-radius: 12px; 
            transition: background 0.3s;
            background: white;
        }
        
        .fine-item:hover, .request-item:hover, .attendance-item:hover, .visitor-item:hover, .reservation-item:hover {
            background: #f8f9fa;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .role-badge {
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-left: 10px;
        }
        
        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        
        .urgent { 
            background: #fff3cd; 
            border-left: 4px solid var(--warning); 
        }
        .high { 
            background: #f8d7da; 
            border-left: 4px solid var(--danger); 
        }
        .emergency { 
            background: #f8d7da; 
            border-left: 4px solid var(--danger); 
            font-weight: bold; 
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            margin-right: 10px;
        }
        
        .user-display {
            display: flex;
            align-items: center;
        }
        
        .search-box {
            margin-bottom: 20px;
        }
        
        .search-box input {
            max-width: 400px;
        }
        
        .bulk-attendance-table {
            margin: 20px 0;
        }
        
        .bulk-attendance-table th, .bulk-attendance-table td {
            padding: 10px;
            text-align: left;
        }
        
        .contact-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .contact-card h4 {
            margin-bottom: 10px;
            color: var(--dark);
        }
        
        .contact-card p {
            margin: 5px 0;
        }
        
        .activity-log {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 3px solid var(--info);
        }
        
        .activity-log .time {
            color: #6c757d;
            font-size: 0.8rem;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .inventory-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .inventory-item:last-child {
            border-bottom: none;
        }
        
        .inventory-item .quantity {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--primary);
        }
        
        .export-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .quick-action-btn {
            background: white;
            border: 2px solid #e9ecef;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        
        .quick-action-btn:hover {
            border-color: var(--primary);
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .quick-action-btn i {
            font-size: 2rem;
            color: var(--primary);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                width: 280px;
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .header {
                padding: 0 15px;
            }
            
            .content {
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-row {
                flex-direction: column;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        
        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
        }
        
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
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h3>Dorm Management</h3>
            <small>Assistant Portal</small>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=home" class="<?php echo $active_section == 'home' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="?section=attendance" class="<?php echo $active_section == 'attendance' ? 'active' : ''; ?>"><i class="fas fa-clipboard-check"></i> Attendance</a></li>
            <li><a href="?section=fines" class="<?php echo $active_section == 'fines' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Fines Management</a></li>
            <li><a href="?section=services" class="<?php echo $active_section == 'services' ? 'active' : ''; ?>"><i class="fas fa-tools"></i> Service Requests</a></li>
            <li><a href="?section=approvals" class="<?php echo $active_section == 'approvals' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i> Approvals</a></li>
            <li><a href="?section=occupants" class="<?php echo $active_section == 'occupants' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Occupants</a></li>
            <li><a href="?section=reports" class="<?php echo $active_section == 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i> Reports</a></li>
            <li><a href="?section=profile" class="<?php echo $active_section == 'profile' ? 'active' : ''; ?>"><i class="fas fa-user-cog"></i> Profile</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <button class="mobile-menu-btn" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Assistant Dashboard</h1>
            <div class="header-actions">
                <span class="user-display">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <?php echo $_SESSION['full_name']; ?> <span class="role-badge">Assistant</span>
                </span>
                
                <!-- Notification Bell -->
                <div class="notification-dropdown">
                    <button class="notification-btn">
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
                                   class="<?php echo $notification['is_read'] ? 'notification-read' : 'notification-unread'; ?>"
                                   onclick="markNotificationAsRead(<?php echo $notification['id']; ?>)">
                                    <strong><?php echo $notification['title']; ?></strong><br>
                                    <small><?php echo $notification['message']; ?></small><br>
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
            <div class="user-info">
                <div>
                    <strong><i class="fas fa-envelope"></i> Email:</strong> <?php echo $_SESSION['email']; ?> | 
                    <strong><i class="fas fa-user-tag"></i> Role:</strong> <?php echo ucfirst(str_replace('_', ' ', $_SESSION['user_type'])); ?>
                </div>
                <div>
                    <strong><i class="fas fa-clock"></i> Last Login:</strong> <?php echo date('F j, Y g:i A'); ?>
                </div>
            </div>
            
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success'])): ?>
                <div class="success">
                    <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <!-- Home/Dashboard Section -->
            <div id="home-section" class="section <?php echo $active_section == 'home' ? 'active' : ''; ?>">
                <div class="card">
                    <h2><i class="fas fa-home"></i> Welcome, <?php echo $_SESSION['full_name']; ?>!</h2>
                    <p style="margin-top: 15px; color: #666; font-size: 1.05rem;">
                        You are logged in as <strong><?php echo ucfirst(str_replace('_', ' ', $_SESSION['user_type'])); ?></strong>.
                        Here's an overview of your dorm management assistant portal.
                    </p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users" style="color: #4361ee;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_occupants']; ?></div>
                        <div class="stat-label">Total Occupants</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-check" style="color: #4cc9f0;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['active_today']; ?></div>
                        <div class="stat-label">Active Today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tools" style="color: #fca311;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_service_requests']; ?></div>
                        <div class="stat-label">Pending Requests</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle" style="color: #e63946;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['urgent_service_requests']; ?></div>
                        <div class="stat-label">Urgent Requests</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave" style="color: #e63946;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_fines']; ?></div>
                        <div class="stat-label">Pending Fines</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line" style="color: #4cc9f0;"></i>
                        </div>
                        <div class="stat-number">₱<?php echo number_format($stats['total_pending_fines'], 2); ?></div>
                        <div class="stat-label">Total Pending Fines</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-friends" style="color: #4895ef;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_visitors']; ?></div>
                        <div class="stat-label">Pending Visitors</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-bed" style="color: #3f37c9;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_reservations']; ?></div>
                        <div class="stat-label">Pending Reservations</div>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <div class="quick-action-btn" onclick="window.location.href='?section=attendance'">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Record Attendance</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=fines'">
                        <i class="fas fa-money-bill-wave"></i>
                        <span>Add Fine</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=services'">
                        <i class="fas fa-tools"></i>
                        <span>Service Requests</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=approvals'">
                        <i class="fas fa-check-circle"></i>
                        <span>Pending Approvals</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=reports'">
                        <i class="fas fa-chart-bar"></i>
                        <span>Generate Reports</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=emergency'">
                        <i class="fas fa-phone-alt"></i>
                        <span>Emergency Contacts</span>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clipboard-list"></i> Today's Attendance Summary</h3>
                        <span class="badge"><?php echo $stats['today_attendance']; ?> records</span>
                    </div>
                    <?php if (empty($today_attendance)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard-check"></i>
                            <p>No attendance records for today.</p>
                            <a href="?section=attendance" class="btn">Record Attendance Now</a>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Occupant</th>
                                    <th>Status</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $present_count = 0;
                                $absent_count = 0;
                                foreach ($today_attendance as $record): 
                                    if ($record['status'] == 'present') $present_count++;
                                    if ($record['status'] == 'absent') $absent_count++;
                                ?>
                                    <tr>
                                        <td><?php echo $record['full_name']; ?></td>
                                        <td><span class="status-<?php echo $record['status']; ?>"><?php echo ucfirst($record['status']); ?></span></td>
                                        <td><?php echo $record['check_in_time'] ?: 'N/A'; ?></td>
                                        <td><?php echo $record['check_out_time'] ?: 'N/A'; ?></td>
                                        <td><?php echo $record['notes'] ?: 'None'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 8px;">
                            <strong>Summary:</strong> Present: <?php echo $present_count; ?>, Absent: <?php echo $absent_count; ?>, Total: <?php echo count($today_attendance); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Activities</h3>
                    </div>
                    <?php if (empty($activity_logs)): ?>
                        <div class="empty-state">
                            <i class="fas fa-history"></i>
                            <p>No recent activities.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activity_logs as $log): ?>
                            <div class="activity-log">
                                <div><strong><?php echo $log['action']; ?></strong></div>
                                <div><?php echo $log['details']; ?></div>
                                <div class="time"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Attendance Section -->
            <div id="attendance-section" class="section <?php echo $active_section == 'attendance' ? 'active' : ''; ?>">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('record-attendance')">Record Attendance</button>
                    <button class="tab-btn" onclick="showTab('bulk-attendance')">Bulk Attendance</button>
                    <button class="tab-btn" onclick="showTab('attendance-history')">History</button>
                </div>
                
                <!-- Record Attendance Tab -->
                <div id="record-attendance" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-clipboard-check"></i> Record Individual Attendance</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="user_id">Occupant</label>
                                    <select name="user_id" id="user_id" required>
                                        <option value="">Select Occupant</option>
                                        <?php foreach ($occupants as $occupant): ?>
                                            <option value="<?php echo $occupant['id']; ?>">
                                                <?php echo $occupant['full_name']; ?> (<?php echo $occupant['email']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="date">Date</label>
                                    <input type="date" name="date" id="date" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="status">Status</label>
                                    <select name="status" id="status" required>
                                        <option value="present">Present</option>
                                        <option value="absent">Absent</option>
                                        <option value="late">Late</option>
                                        <option value="excused">Excused</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="check_in_time">Check In Time</label>
                                    <input type="time" name="check_in_time" id="check_in_time">
                                </div>
                                
                                <div class="form-group">
                                    <label for="check_out_time">Check Out Time</label>
                                    <input type="time" name="check_out_time" id="check_out_time">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="notes">Notes</label>
                                <textarea name="notes" id="notes" placeholder="Optional notes..."></textarea>
                            </div>
                            
                            <button type="submit" name="record_attendance" class="btn btn-success">
                                <i class="fas fa-save"></i> Record Attendance
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Bulk Attendance Tab -->
                <div id="bulk-attendance" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-users"></i> Bulk Attendance Recording</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="bulk_date">Date</label>
                                <input type="date" name="bulk_date" id="bulk_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            
                            <div class="bulk-attendance-table">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Occupant</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($occupants as $occupant): ?>
                                            <tr>
                                                <td><?php echo $occupant['full_name']; ?></td>
                                                <td>
                                                    <select name="attendance[<?php echo $occupant['id']; ?>]" class="status-select">
                                                        <option value="present">Present</option>
                                                        <option value="absent">Absent</option>
                                                        <option value="late">Late</option>
                                                        <option value="excused">Excused</option>
                                                    </select>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-small" onclick="markAllAsPresent()">Mark All Present</button>
                                                    <button type="button" class="btn btn-small" onclick="markAllAsAbsent()">Mark All Absent</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <button type="submit" name="bulk_attendance" class="btn btn-success">
                                <i class="fas fa-save"></i> Save Bulk Attendance
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Attendance History Tab -->
                <div id="attendance-history" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-history"></i> Attendance History</h3>
                        </div>
                        <form method="GET" style="margin-bottom: 20px;">
                            <input type="hidden" name="section" value="attendance">
                            <input type="hidden" name="tab" value="history">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="filter_date">Filter by Date</label>
                                    <input type="date" name="filter_date" id="filter_date" value="<?php echo isset($_GET['filter_date']) ? $_GET['filter_date'] : ''; ?>">
                                </div>
                                <div class="form-group">
                                    <label for="filter_user">Filter by Occupant</label>
                                    <select name="filter_user" id="filter_user">
                                        <option value="">All Occupants</option>
                                        <?php foreach ($occupants as $occupant): ?>
                                            <option value="<?php echo $occupant['id']; ?>" <?php echo (isset($_GET['filter_user']) && $_GET['filter_user'] == $occupant['id']) ? 'selected' : ''; ?>>
                                                <?php echo $occupant['full_name']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-info">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="?section=attendance" class="btn btn-warning">
                                        <i class="fas fa-redo"></i> Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                        
                        <?php
                        $filter_date = isset($_GET['filter_date']) ? sanitizeInput($_GET['filter_date']) : null;
                        $filter_user = isset($_GET['filter_user']) ? sanitizeInput($_GET['filter_user']) : null;
                        $attendance_history = getAttendanceRecords($filter_date, $filter_user);
                        ?>
                        
                        <?php if (empty($attendance_history)): ?>
                            <div class="empty-state">
                                <i class="fas fa-clipboard-list"></i>
                                <p>No attendance records found.</p>
                            </div>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Occupant</th>
                                        <th>Status</th>
                                        <th>Check In</th>
                                        <th>Check Out</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($attendance_history as $record): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                            <td><?php echo $record['full_name']; ?></td>
                                            <td><span class="status-<?php echo $record['status']; ?>"><?php echo ucfirst($record['status']); ?></span></td>
                                            <td><?php echo $record['check_in_time'] ?: 'N/A'; ?></td>
                                            <td><?php echo $record['check_out_time'] ?: 'N/A'; ?></td>
                                            <td><?php echo $record['notes'] ?: 'None'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Fines Management Section -->
            <div id="fines-section" class="section <?php echo $active_section == 'fines' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Add Fine</h3>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="fine_user_id">Occupant</label>
                                <select name="user_id" id="fine_user_id" required>
                                    <option value="">Select Occupant</option>
                                    <?php foreach ($occupants as $occupant): ?>
                                        <option value="<?php echo $occupant['id']; ?>">
                                            <?php echo $occupant['full_name']; ?> (<?php echo $occupant['email']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="amount">Amount (₱)</label>
                                <input type="number" name="amount" id="amount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="due_date">Due Date</label>
                                <input type="date" name="due_date" id="due_date" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="reason">Reason</label>
                            <textarea name="reason" id="reason" required placeholder="Enter reason for fine..."></textarea>
                        </div>
                        
                        <button type="submit" name="add_fine" class="btn btn-success">
                            <i class="fas fa-plus-circle"></i> Add Fine
                        </button>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> Fines Management</h3>
                    </div>
                    <?php if (empty($fines)): ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <p>No fines recorded.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($fines as $fine): ?>
                            <div class="fine-item">
                                <h4>Fine #<?php echo $fine['id']; ?> - ₱<?php echo number_format($fine['amount'], 2); ?></h4>
                                <p><strong>Occupant:</strong> <?php echo $fine['full_name']; ?> (<?php echo $fine['email']; ?>)</p>
                                <p><strong>Reason:</strong> <?php echo $fine['reason']; ?></p>
                                <p><strong>Due Date:</strong> <?php echo date('F j, Y', strtotime($fine['due_date'])); ?></p>
                                <p><strong>Status:</strong> 
                                    <span class="status-<?php echo $fine['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $fine['status'])); ?>
                                    </span>
                                </p>
                                <p><strong>Issued:</strong> <?php echo date('F j, Y g:i A', strtotime($fine['created_at'])); ?></p>
                                
                                <?php if ($fine['status'] == 'pending'): ?>
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="fine_id" value="<?php echo $fine['id']; ?>">
                                            <input type="hidden" name="status" value="paid">
                                            <button type="submit" name="update_fine_status" class="btn btn-success">
                                                <i class="fas fa-check"></i> Mark as Paid
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="fine_id" value="<?php echo $fine['id']; ?>">
                                            <input type="hidden" name="status" value="waived">
                                            <button type="submit" name="update_fine_status" class="btn btn-warning">
                                                <i class="fas fa-hand-holding-usd"></i> Waive Fine
                                            </button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Service Requests Section -->
            <div id="services-section" class="section <?php echo $active_section == 'services' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-tools"></i> Service Requests Management</h3>
                    </div>
                    <?php if (empty($service_requests)): ?>
                        <div class="empty-state">
                            <i class="fas fa-tools"></i>
                            <p>No service requests.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($service_requests as $request): ?>
                            <div class="request-item <?php echo $request['urgency']; ?>">
                                <h4>Request #<?php echo $request['id']; ?> - <?php echo ucfirst($request['service_type']); ?></h4>
                                <p><strong>From:</strong> <?php echo $request['full_name']; ?> (<?php echo $request['email']; ?>)</p>
                                <p><strong>Description:</strong> <?php echo $request['description']; ?></p>
                                <p><strong>Urgency:</strong> 
                                    <span class="status-<?php echo $request['urgency']; ?>">
                                        <?php echo ucfirst($request['urgency']); ?>
                                    </span>
                                </p>
                                <p><strong>Status:</strong> 
                                    <span class="status-<?php echo $request['status']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['status'])); ?>
                                    </span>
                                </p>
                                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></p>
                                
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                        <select name="status" style="margin-right: 10px; padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                            <option value="pending" <?php echo $request['status'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo $request['status'] == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="completed" <?php echo $request['status'] == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $request['status'] == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <select name="assigned_to" style="margin-right: 10px; padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                            <option value="">Not Assigned</option>
                                            <?php 
                                            $staff = getAllOccupants(); // In a real system, you'd have a staff table
                                            foreach ($staff as $person): ?>
                                                <option value="<?php echo $person['id']; ?>" <?php echo $request['assigned_to'] == $person['id'] ? 'selected' : ''; ?>>
                                                    <?php echo $person['full_name']; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="update_service_status" class="btn btn-info">
                                            <i class="fas fa-sync-alt"></i> Update Status
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Approvals Section -->
            <div id="approvals-section" class="section <?php echo $active_section == 'approvals' ? 'active' : ''; ?>">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('visitor-approvals')">Visitor Requests</button>
                    <button class="tab-btn" onclick="showTab('reservation-approvals')">Reservations</button>
                </div>
                
                <!-- Visitor Approvals Tab -->
                <div id="visitor-approvals" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-friends"></i> Pending Visitor Approvals</h3>
                            <span class="badge"><?php echo count($pending_visitors); ?> pending</span>
                        </div>
                        <?php if (empty($pending_visitors)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>No pending visitor requests.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pending_visitors as $visitor): ?>
                                <div class="visitor-item">
                                    <h4>Visitor Request #<?php echo $visitor['id']; ?></h4>
                                    <p><strong>Requested by:</strong> <?php echo $visitor['full_name']; ?> (<?php echo $visitor['email']; ?>)</p>
                                    <p><strong>Visitor Name:</strong> <?php echo $visitor['visitor_name']; ?></p>
                                    <p><strong>Visitor Contact:</strong> <?php echo $visitor['visitor_email']; ?> / <?php echo $visitor['visitor_phone']; ?></p>
                                    <p><strong>Visit Date:</strong> <?php echo date('F j, Y', strtotime($visitor['visit_date'])); ?></p>
                                    <p><strong>Purpose:</strong> <?php echo $visitor['visit_purpose']; ?></p>
                                    <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($visitor['created_at'])); ?></p>
                                    
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $visitor['id']; ?>">
                                            <button type="submit" name="approve_visitor" class="btn btn-success">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="request_id" value="<?php echo $visitor['id']; ?>">
                                            <div style="display: inline-flex; gap: 10px; align-items: center;">
                                                <input type="text" name="reject_reason" placeholder="Reason for rejection" style="padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                                <button type="submit" name="reject_visitor" class="btn btn-danger">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Reservation Approvals Tab -->
                <div id="reservation-approvals" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-bed"></i> Pending Reservation Approvals</h3>
                            <span class="badge"><?php echo count($pending_reservations); ?> pending</span>
                        </div>
                        <?php if (empty($pending_reservations)): ?>
                            <div class="empty-state">
                                <i class="fas fa-check-circle"></i>
                                <p>No pending reservations.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pending_reservations as $reservation): ?>
                                <div class="reservation-item">
                                    <h4>Reservation #<?php echo $reservation['id']; ?></h4>
                                    <p><strong>Requested by:</strong> <?php echo $reservation['full_name']; ?> (<?php echo $reservation['email']; ?>)</p>
                                    <p><strong>Room:</strong> <?php echo $reservation['room_number']; ?></p>
                                    <p><strong>Reservation Date:</strong> <?php echo date('F j, Y', strtotime($reservation['reservation_date'])); ?></p>
                                    <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($reservation['created_at'])); ?></p>
                                    
                                    <div class="action-buttons">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                            <button type="submit" name="approve_reservation" class="btn btn-success">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                        </form>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="reservation_id" value="<?php echo $reservation['id']; ?>">
                                            <div style="display: inline-flex; gap: 10px; align-items: center;">
                                                <input type="text" name="reject_reason" placeholder="Reason for rejection" style="padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                                <button type="submit" name="reject_reservation" class="btn btn-danger">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Occupants Section -->
            <div id="occupants-section" class="section <?php echo $active_section == 'occupants' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-users"></i> Occupant Management</h3>
                    </div>
                    
                    <div class="search-box">
                        <form method="GET">
                            <input type="hidden" name="section" value="occupants">
                            <div class="form-row">
                                <div class="form-group">
                                    <input type="text" name="search" placeholder="Search by name, email, or phone..." value="<?php echo $search_term; ?>">
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="btn btn-info">
                                        <i class="fas fa-search"></i> Search
                                    </button>
                                    <a href="?section=occupants" class="btn btn-warning">
                                        <i class="fas fa-redo"></i> Clear
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                    
                    <?php if (empty($occupants)): ?>
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No occupants found.</p>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($occupants as $occupant): ?>
                                    <tr>
                                        <td><?php echo $occupant['full_name']; ?></td>
                                        <td><?php echo $occupant['email']; ?></td>
                                        <td><?php echo $occupant['phone'] ?: 'N/A'; ?></td>
                                        <td><?php echo isset($occupant['created_at']) ? date('M j, Y', strtotime($occupant['created_at'])) : 'N/A'; ?></td>
                                        <td>
                                            <a href="?section=attendance&filter_user=<?php echo $occupant['id']; ?>" class="btn btn-small">
                                                <i class="fas fa-clipboard-check"></i> Attendance
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reports Section -->
            <div id="reports-section" class="section <?php echo $active_section == 'reports' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Reports & Export</h3>
                    </div>
                    
                    <div class="export-form">
                        <h4><i class="fas fa-file-export"></i> Export Attendance Data</h4>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="export_date_from">From Date</label>
                                    <input type="date" name="export_date_from" id="export_date_from" required value="<?php echo date('Y-m-01'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="export_date_to">To Date</label>
                                    <input type="date" name="export_date_to" id="export_date_to" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <button type="submit" name="export_attendance" class="btn btn-success">
                                <i class="fas fa-download"></i> Export to CSV
                            </button>
                        </form>
                    </div>
                    
                    <div class="card" style="margin-top: 20px;">
                        <div class="card-header">
                            <h4><i class="fas fa-chart-pie"></i> Attendance Statistics</h4>
                        </div>
                        <div class="chart-container">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Section -->
            <div id="profile-section" class="section <?php echo $active_section == 'profile' ? 'active' : ''; ?>">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('profile-info')">Profile Information</button>
                    <button class="tab-btn" onclick="showTab('change-password')">Change Password</button>
                    <button class="tab-btn" onclick="showTab('emergency-contacts')">Emergency Contacts</button>
                </div>
                
                <!-- Profile Information Tab -->
                <div id="profile-info" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-user-cog"></i> Profile Information</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" name="full_name" id="full_name" required value="<?php echo $assistant_profile['full_name']; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" name="email" id="email" readonly value="<?php echo $assistant_profile['email']; ?>">
                                <small style="color: #6c757d;">Email cannot be changed</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="text" name="phone" id="phone" value="<?php echo $assistant_profile['phone'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Member Since</label>
                                <input type="text" readonly value="<?php echo date('F j, Y', strtotime($assistant_profile['created_at'])); ?>">
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-success">
                                <i class="fas fa-save"></i> Update Profile
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Change Password Tab -->
                <div id="change-password" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-key"></i> Change Password</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="current_password">Current Password</label>
                                <input type="password" name="current_password" id="current_password" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="new_password">New Password</label>
                                <input type="password" name="new_password" id="new_password" required>
                                <small style="color: #6c757d;">Password must be at least 8 characters long</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm New Password</label>
                                <input type="password" name="confirm_password" id="confirm_password" required>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-success">
                                <i class="fas fa-key"></i> Change Password
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Emergency Contacts Tab -->
                <div id="emergency-contacts" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-phone-alt"></i> Emergency Contacts</h3>
                        </div>
                        <?php foreach ($emergency_contacts as $contact): ?>
                            <div class="contact-card">
                                <h4><?php echo $contact['name']; ?></h4>
                                <p><i class="fas fa-phone"></i> <?php echo $contact['phone']; ?></p>
                                <?php if ($contact['ext']): ?>
                                    <p><strong>Extension:</strong> <?php echo $contact['ext']; ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        
                        <div style="margin-top: 20px; padding: 20px; background: #fff3cd; border-radius: 10px;">
                            <h4><i class="fas fa-exclamation-triangle"></i> Emergency Procedures</h4>
                            <ul style="margin-top: 10px;">
                                <li>In case of fire, activate the nearest fire alarm</li>
                                <li>For medical emergencies, call campus security first</li>
                                <li>Report any suspicious activity immediately</li>
                                <li>Keep emergency exits clear at all times</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Prevent back button after logout
        history.pushState(null, null, location.href);
        window.onpopstate = function () {
            history.go(1);
        };

        function markNotificationAsRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'notification_id=' + notificationId
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        }

        // Set minimum date to today for date inputs
        document.addEventListener('DOMContentLoaded', function() {
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (!input.value) {
                    input.min = new Date().toISOString().split('T')[0];
                }
            });
        });

        // Handle sidebar navigation
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarLinks = document.querySelectorAll('.sidebar-menu a');
            const sections = document.querySelectorAll('.section');
            
            sidebarLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    const targetSection = this.getAttribute('href').split('=')[1];
                    
                    // Update active states
                    sidebarLinks.forEach(l => l.classList.remove('active'));
                    this.classList.add('active');
                    
                    sections.forEach(section => {
                        section.classList.remove('active');
                        if (section.id === targetSection + '-section') {
                            section.classList.add('active');
                        }
                    });
                    
                    // Update URL without reload
                    history.pushState(null, null, `?section=${targetSection}`);
                });
            });
            
            // Mobile menu toggle
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const sidebar = document.querySelector('.sidebar');
            
            if (mobileMenuToggle) {
                mobileMenuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                });
            }
        });

        // Tab navigation
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

        // Bulk attendance helpers
        function markAllAsPresent() {
            document.querySelectorAll('.status-select').forEach(select => {
                select.value = 'present';
            });
        }
        
        function markAllAsAbsent() {
            document.querySelectorAll('.status-select').forEach(select => {
                select.value = 'absent';
            });
        }

        // Initialize attendance chart
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('attendanceChart');
            if (ctx) {
                // Sample data - in real application, fetch from server
                const attendanceChart = new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Present', 'Absent', 'Late', 'Excused'],
                        datasets: [{
                            data: [65, 10, 15, 5],
                            backgroundColor: [
                                '#4cc9f0',
                                '#e63946',
                                '#fca311',
                                '#4895ef'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                            },
                            title: {
                                display: true,
                                text: 'Attendance Distribution (Last 30 Days)'
                            }
                        }
                    }
                });
            }
        });

        // Auto-refresh notifications every 30 seconds
        setInterval(() => {
            const badge = document.querySelector('.notification-badge');
            if (badge) {
                // In a real app, fetch new notification count via AJAX
                // For now, just update the timestamp
                console.log('Notifications auto-refresh at ' + new Date().toLocaleTimeString());
            }
        }, 30000);

        // Form validation
        function validateForm(form) {
            const required = form.querySelectorAll('[required]');
            let valid = true;
            
            required.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = '#e63946';
                    valid = false;
                } else {
                    field.style.borderColor = '#e9ecef';
                }
            });
            
            return valid;
        }

        // Add form validation to all forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                }
            });
        });
    </script>
</body>
</html>