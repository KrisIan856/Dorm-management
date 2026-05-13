<?php
// dormdean_dashboard.php
include 'config.php';

// Enhanced security check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || !in_array($_SESSION['user_type'], ['dormdean', 'dormdean_assistant'])) {
    header("Location: login.php");
    exit();
}

// Set session creation time for expiration check
if (!isset($_SESSION['created_at'])) {
    $_SESSION['created_at'] = time();
}

// Generate CSRF token
$csrf_token = generateCSRFToken();

// Handle form submissions with CSRF protection
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Security token validation failed.';
        header("Location: dormdean_dashboard.php");
        exit();
    }
    
    if (isset($_POST['approve_reservation'])) {
        $reservation_id = sanitizeInput($_POST['reservation_id']);
        approveReservation($reservation_id, $_SESSION['user_id']);
    } elseif (isset($_POST['reject_reservation'])) {
        $reservation_id = sanitizeInput($_POST['reservation_id']);
        $reason = sanitizeInput($_POST['reject_reason'] ?? 'Not specified');
        rejectReservation($reservation_id, $_SESSION['user_id'], $reason);
    } elseif (isset($_POST['approve_payment'])) {
        $payment_request_id = sanitizeInput($_POST['payment_request_id']);
        if (approvePaymentRequest($payment_request_id, $_SESSION['user_id'])) {
            $_SESSION['success'] = "Payment request approved successfully!";
        } else {
            $_SESSION['error'] = "Failed to approve payment request.";
        }
        header('Location: dormdean_dashboard.php?section=payments');
        exit();
    } elseif (isset($_POST['reject_payment'])) {
        $payment_request_id = sanitizeInput($_POST['payment_request_id']);
        $rejection_reason = sanitizeInput($_POST['rejection_reason']);
        if (rejectPaymentRequest($payment_request_id, $_SESSION['user_id'], $rejection_reason)) {
            $_SESSION['success'] = "Payment request rejected.";
        } else {
            $_SESSION['error'] = "Failed to reject payment request.";
        }
        header('Location: dormdean_dashboard.php?section=payments');
        exit();
    } elseif (isset($_POST['approve_registration'])) {
        $registration_id = sanitizeInput($_POST['registration_id']);
        $result = approveRegistration($registration_id, $_SESSION['user_id']);
        if ($result['success']) {
            $_SESSION['success'] = "Registration approved successfully!";
        } else {
            $_SESSION['error'] = "Failed to approve registration: " . $result['error'];
        }
        header('Location: dormdean_dashboard.php?section=approvals');
        exit();
    } elseif (isset($_POST['reject_registration'])) {
        $registration_id = sanitizeInput($_POST['registration_id']);
        $rejection_reason = sanitizeInput($_POST['rejection_reason']);
        if (rejectRegistration($registration_id, $_SESSION['user_id'], $rejection_reason)) {
            $_SESSION['success'] = "Registration rejected.";
        } else {
            $_SESSION['error'] = "Failed to reject registration.";
        }
        header('Location: dormdean_dashboard.php?section=approvals');
        exit();
    } elseif (isset($_POST['approve_visitor'])) {
        $visitor_id = sanitizeInput($_POST['visitor_id']);
        if (approveVisitorRequest($visitor_id, $_SESSION['user_id'])) {
            $_SESSION['success'] = "Visitor request approved successfully!";
        } else {
            $_SESSION['error'] = "Failed to approve visitor request.";
        }
        header('Location: dormdean_dashboard.php?section=visitors');
        exit();
    } elseif (isset($_POST['reject_visitor'])) {
        $visitor_id = sanitizeInput($_POST['visitor_id']);
        $rejection_reason = sanitizeInput($_POST['rejection_reason']);
        if (rejectVisitorRequest($visitor_id, $_SESSION['user_id'], $rejection_reason)) {
            $_SESSION['success'] = "Visitor request rejected.";
        } else {
            $_SESSION['error'] = "Failed to reject visitor request.";
        }
        header('Location: dormdean_dashboard.php?section=visitors');
        exit();
    } elseif (isset($_POST['mark_all_notifications_read'])) {
        markAllNotificationsAsRead($_SESSION['user_id']);
        header('Location: dormdean_dashboard.php');
        exit();
    } elseif (isset($_POST['clear_old_notifications'])) {
        clearOldNotifications($_SESSION['user_id']);
        header('Location: dormdean_dashboard.php');
        exit();
    } elseif (isset($_POST['add_room'])) {
        $room_number = sanitizeInput($_POST['room_number']);
        $floor = sanitizeInput($_POST['floor']);
        $building = sanitizeInput($_POST['building']);
        $capacity = sanitizeInput($_POST['capacity']);
        $description = sanitizeInput($_POST['description']);
        addRoom($room_number, $floor, $building, $capacity, $description);
    } elseif (isset($_POST['update_room_status'])) {
        $room_id = sanitizeInput($_POST['room_id']);
        $is_available = sanitizeInput($_POST['is_available']);
        updateRoomStatus($room_id, $is_available);
    } elseif (isset($_POST['update_profile'])) {
        $full_name = sanitizeInput($_POST['full_name']);
        $phone = sanitizeInput($_POST['phone']);
        updateDeanProfile($_SESSION['user_id'], $full_name, $phone);
    } elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        changeDeanPassword($_SESSION['user_id'], $current_password, $new_password, $confirm_password);
    } elseif (isset($_POST['create_announcement'])) {
        $title = sanitizeInput($_POST['title']);
        $content = sanitizeInput($_POST['content']);
        $priority = sanitizeInput($_POST['priority']);
        $target_users = sanitizeInput($_POST['target_users']);
        createAnnouncement($_SESSION['user_id'], $title, $content, $priority, $target_users);
    }
}

// Get pending reservations
function getPendingReservations() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT r.*, u.full_name, u.email, rm.room_number, rm.floor, rm.building 
        FROM reservations r 
        JOIN users u ON r.user_id = u.id 
        JOIN rooms rm ON r.room_id = rm.id 
        WHERE r.status = 'pending' 
        ORDER BY r.reservation_date ASC, r.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $reservations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $reservations;
}

// Approve reservation
function approveReservation($reservation_id, $approved_by) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->begin_transaction();
    
    try {
        // Update reservation status
        $stmt = $conn->prepare("UPDATE reservations SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?");
        $stmt->bind_param("ii", $approved_by, $reservation_id);
        $stmt->execute();
        
        // Get reservation details for notification
        $stmt = $conn->prepare("SELECT user_id, room_id FROM reservations WHERE id = ?");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        $stmt->bind_result($user_id, $room_id);
        $stmt->fetch();
        $stmt->close();
        
        // Update room occupants count
        $stmt = $conn->prepare("UPDATE rooms SET current_occupants = current_occupants + 1 WHERE id = ?");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        
        // Check if room is full
        $stmt = $conn->prepare("SELECT current_occupants, capacity FROM rooms WHERE id = ?");
        $stmt->bind_param("i", $room_id);
        $stmt->execute();
        $stmt->bind_result($current_occupants, $capacity);
        $stmt->fetch();
        $stmt->close();
        
        if ($current_occupants >= $capacity) {
            $stmt = $conn->prepare("UPDATE rooms SET is_available = FALSE WHERE id = ?");
            $stmt->bind_param("i", $room_id);
            $stmt->execute();
        }
        
        // Create notification for occupant
        createNotification($user_id, 'Reservation Approved', 'Your room reservation has been approved by the dorm dean.', 'approval', $reservation_id);
        
        // Log activity
        logActivity($approved_by, 'APPROVE_RESERVATION', "Approved reservation ID: $reservation_id");
        
        $conn->commit();
        $_SESSION['success'] = "Reservation approved successfully!";
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error approving reservation: " . $e->getMessage());
        $_SESSION['error'] = "Failed to approve reservation: " . $e->getMessage();
    }
}

// Reject reservation
function rejectReservation($reservation_id, $rejected_by, $reason = 'Not specified') {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE reservations SET status = 'rejected', approved_at = NOW(), approved_by = ?, rejection_reason = ? WHERE id = ?");
    $stmt->bind_param("isi", $rejected_by, $reason, $reservation_id);
    
    if ($stmt->execute()) {
        // Get user_id for notification
        $stmt = $conn->prepare("SELECT user_id FROM reservations WHERE id = ?");
        $stmt->bind_param("i", $reservation_id);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();
        
        // Create notification for occupant
        createNotification($user_id, 'Reservation Rejected', "Your room reservation has been rejected. Reason: $reason", 'approval', $reservation_id);
        
        // Log activity
        logActivity($rejected_by, 'REJECT_RESERVATION', "Rejected reservation ID: $reservation_id, Reason: $reason");
        
        $_SESSION['success'] = "Reservation rejected.";
    } else {
        $_SESSION['error'] = "Failed to reject reservation.";
    }
}

// Approve payment request
function approvePaymentRequest($payment_request_id, $approved_by) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->begin_transaction();
    
    try {
        // Update payment request status
        $stmt = $conn->prepare("UPDATE payment_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $approved_by, $payment_request_id);
        $stmt->execute();
        
        // Get fine_id and user_id
        $stmt = $conn->prepare("SELECT fine_id, user_id FROM payment_requests WHERE id = ?");
        $stmt->bind_param("i", $payment_request_id);
        $stmt->execute();
        $stmt->bind_result($fine_id, $user_id);
        $stmt->fetch();
        $stmt->close();
        
        // Update fine status to paid
        if ($fine_id) {
            $stmt = $conn->prepare("UPDATE fines SET status = 'paid', paid_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $fine_id);
            $stmt->execute();
        }
        
        // Create notification for user
        if ($user_id) {
            createNotification($user_id, 'Payment Approved', 'Your payment request has been approved.', 'payment', $payment_request_id);
        }
        
        // Log activity
        logActivity($approved_by, 'APPROVE_PAYMENT', "Approved payment request ID: $payment_request_id");
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error approving payment request: " . $e->getMessage());
        return false;
    }
}

// Reject payment request
function rejectPaymentRequest($payment_request_id, $rejected_by, $rejection_reason) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE payment_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->bind_param("isi", $rejected_by, $rejection_reason, $payment_request_id);
    
    if ($stmt->execute()) {
        // Get user_id for notification
        $stmt = $conn->prepare("SELECT user_id FROM payment_requests WHERE id = ?");
        $stmt->bind_param("i", $payment_request_id);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();
        
        // Create notification for user
        if ($user_id) {
            createNotification($user_id, 'Payment Rejected', 'Your payment request has been rejected. Reason: ' . $rejection_reason, 'payment', $payment_request_id);
        }
        
        // Log activity
        logActivity($rejected_by, 'REJECT_PAYMENT', "Rejected payment request ID: $payment_request_id, Reason: $rejection_reason");
        
        return true;
    }
    
    return false;
}

// Get pending payment requests
function getPendingPaymentRequests() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT pr.*, u.full_name, u.email, f.reason, f.amount, f.due_date 
        FROM payment_requests pr 
        JOIN users u ON pr.user_id = u.id 
        JOIN fines f ON pr.fine_id = f.id 
        WHERE pr.status = 'pending' 
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $payment_requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $payment_requests;
}

// Get enhanced dashboard statistics
function getEnhancedDeanDashboardStats() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stats = [];
    
    // Total rooms
    $result = $conn->query("SELECT COUNT(*) FROM rooms");
    $stats['total_rooms'] = $result->fetch_array()[0];
    
    // Available rooms
    $result = $conn->query("SELECT COUNT(*) FROM rooms WHERE is_available = TRUE");
    $stats['available_rooms'] = $result->fetch_array()[0];
    
    // Total occupants
    $result = $conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'occupant' AND is_active = TRUE");
    $stats['total_occupants'] = $result->fetch_array()[0];
    
    // Total staff (assistants)
    $result = $conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'dormdean_assistant' AND is_active = TRUE");
    $stats['total_assistants'] = $result->fetch_array()[0];
    
    // Pending reservations
    $result = $conn->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    $stats['pending_reservations'] = $result->fetch_array()[0];
    
    // Pending payment requests
    $result = $conn->query("SELECT COUNT(*) FROM payment_requests WHERE status = 'pending'");
    $stats['pending_payments'] = $result->fetch_array()[0];
    
    // Total pending fines amount
    $result = $conn->query("SELECT SUM(amount) FROM fines WHERE status = 'pending'");
    $stats['total_pending_fines'] = $result->fetch_array()[0] ?: 0;
    
    // Pending service requests
    $result = $conn->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending'");
    $stats['pending_services'] = $result->fetch_array()[0];
    
    // Urgent service requests
    $result = $conn->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending' AND urgency IN ('high', 'emergency')");
    $stats['urgent_services'] = $result->fetch_array()[0];
    
    // Pending visitor requests
    $result = $conn->query("SELECT COUNT(*) FROM visitor_requests WHERE status = 'pending'");
    $stats['pending_visitors'] = $result->fetch_array()[0];
    
    // Pending registrations
    $result = $conn->query("SELECT COUNT(*) FROM registration_approvals WHERE status = 'pending'");
    $stats['pending_registrations'] = $result->fetch_array()[0];
    
    // Today's checkouts
    $today = date('Y-m-d');
    $result = $conn->query("SELECT COUNT(*) FROM attendance WHERE date = '$today' AND check_out_time IS NOT NULL");
    $stats['today_checkouts'] = $result->fetch_array()[0];
    
    // Monthly occupancy rate
    $result = $conn->query("SELECT SUM(current_occupants), SUM(capacity) FROM rooms");
    $row = $result->fetch_array();
    $total_occupants = $row[0];
    $total_capacity = $row[1];
    $stats['occupancy_rate'] = $total_capacity > 0 ? round(($total_occupants / $total_capacity) * 100, 1) : 0;
    
    return $stats;
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
        LIMIT 50
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $fines = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $fines;
}

// Get leave records
function getLeaveRecords($date = null) {
    $db = new Database();
    $conn = $db->getConnection();

    $query = "SELECT a.*, u.full_name, u.email FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.check_out_time IS NOT NULL";
    $types = '';
    $params = [];

    if ($date) {
        $query .= " AND a.date = ?";
        $types = 's';
        $params[] = $date;
    }

    $query .= " ORDER BY a.date DESC, a.check_out_time DESC LIMIT 50";

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

// Get service requests
function getServiceRequests($status = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    if ($status) {
        $stmt = $conn->prepare("
            SELECT sr.*, u.full_name, u.email, u2.full_name as assigned_to_name
            FROM service_requests sr 
            JOIN users u ON sr.user_id = u.id 
            LEFT JOIN users u2 ON sr.assigned_to = u2.id
            WHERE sr.status = ? 
            ORDER BY sr.urgency DESC, sr.created_at DESC
        ");
        $stmt->bind_param("s", $status);
    } else {
        $stmt = $conn->prepare("
            SELECT sr.*, u.full_name, u.email, u2.full_name as assigned_to_name
            FROM service_requests sr 
            JOIN users u ON sr.user_id = u.id 
            LEFT JOIN users u2 ON sr.assigned_to = u2.id
            ORDER BY sr.urgency DESC, sr.created_at DESC
            LIMIT 50
        ");
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $requests = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $requests;
}

// Get pending visitor requests
function getPendingVisitorRequests() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT vr.*, u.full_name as occupant_name, u.email as occupant_email 
        FROM visitor_requests vr 
        JOIN users u ON vr.user_id = u.id 
        WHERE vr.status = 'pending' 
        ORDER BY vr.visit_date ASC, vr.created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $visitors = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $visitors;
}

// Approve visitor request
function approveVisitorRequest($visitor_id, $approved_by) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE visitor_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
    $stmt->bind_param("ii", $approved_by, $visitor_id);
    
    if ($stmt->execute()) {
        // Get user_id for notification
        $stmt = $conn->prepare("SELECT user_id, visitor_name, visit_date FROM visitor_requests WHERE id = ?");
        $stmt->bind_param("i", $visitor_id);
        $stmt->execute();
        $stmt->bind_result($user_id, $visitor_name, $visit_date);
        $stmt->fetch();
        $stmt->close();
        
        // Create notification for occupant
        if ($user_id) {
            $message = "Your visitor request for $visitor_name on " . date('F j, Y', strtotime($visit_date)) . " has been approved.";
            createNotification($user_id, 'Visitor Approved', $message, 'visitor', $visitor_id);
        }
        
        // Log activity
        logActivity($approved_by, 'APPROVE_VISITOR', "Approved visitor request ID: $visitor_id");
        
        return true;
    }
    
    return false;
}

// Reject visitor request
function rejectVisitorRequest($visitor_id, $rejected_by, $rejection_reason) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE visitor_requests SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->bind_param("isi", $rejected_by, $rejection_reason, $visitor_id);
    
    if ($stmt->execute()) {
        // Get user_id for notification
        $stmt = $conn->prepare("SELECT user_id, visitor_name, visit_date FROM visitor_requests WHERE id = ?");
        $stmt->bind_param("i", $visitor_id);
        $stmt->execute();
        $stmt->bind_result($user_id, $visitor_name, $visit_date);
        $stmt->fetch();
        $stmt->close();
        
        // Create notification for occupant
        if ($user_id) {
            $message = "Your visitor request for $visitor_name on " . date('F j, Y', strtotime($visit_date)) . " has been rejected. Reason: $rejection_reason";
            createNotification($user_id, 'Visitor Rejected', $message, 'visitor', $visitor_id);
        }
        
        // Log activity
        logActivity($rejected_by, 'REJECT_VISITOR', "Rejected visitor request ID: $visitor_id, Reason: $rejection_reason");
        
        return true;
    }
    
    return false;
}

// Get all rooms
function getAllRooms() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT r.*, 
               (SELECT COUNT(*) FROM room_assignments ra WHERE ra.room_id = r.id AND ra.status = 'active') as active_assignments
        FROM rooms r 
        ORDER BY r.building, r.floor, r.room_number
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $rooms = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $rooms;
}

// Add new room
function addRoom($room_number, $floor, $building, $capacity, $description) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("INSERT INTO rooms (room_number, floor, building, capacity, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssis", $room_number, $floor, $building, $capacity, $description);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($_SESSION['user_id'], 'ADD_ROOM', "Added room: $room_number, Building: $building, Floor: $floor");
        
        $_SESSION['success'] = "Room added successfully!";
    } else {
        $_SESSION['error'] = "Failed to add room. Room number might already exist.";
    }
    $stmt->close();
}

// Update room status
function updateRoomStatus($room_id, $is_available) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE rooms SET is_available = ? WHERE id = ?");
    $stmt->bind_param("ii", $is_available, $room_id);
    
    if ($stmt->execute()) {
        // Log activity
        $status = $is_available ? 'available' : 'unavailable';
        logActivity($_SESSION['user_id'], 'UPDATE_ROOM_STATUS', "Updated room ID: $room_id to $status");
        
        $_SESSION['success'] = "Room status updated successfully!";
    } else {
        $_SESSION['error'] = "Failed to update room status.";
    }
    $stmt->close();
}

// Get dean profile
function getDeanProfile($user_id) {
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

// Update dean profile
function updateDeanProfile($user_id, $full_name, $phone) {
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

// Change dean password
function changeDeanPassword($user_id, $current_password, $new_password, $confirm_password) {
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

// Create announcement
function createAnnouncement($user_id, $title, $content, $priority = 'normal', $target_users = 'all') {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by, priority, target_users) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $title, $content, $user_id, $priority, $target_users);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($user_id, 'CREATE_ANNOUNCEMENT', "Created announcement: $title");
        
        $_SESSION['success'] = "Announcement created successfully!";
        
        // Create notifications for target users
        if ($target_users == 'all') {
            $user_stmt = $conn->prepare("SELECT id FROM users WHERE is_active = TRUE");
            $user_stmt->execute();
            $result = $user_stmt->get_result();
            while ($user = $result->fetch_assoc()) {
                createNotification($user['id'], 'New Announcement', $title, 'system');
            }
            $user_stmt->close();
        } elseif ($target_users == 'occupants') {
            $user_stmt = $conn->prepare("SELECT id FROM users WHERE user_type = 'occupant' AND is_active = TRUE");
            $user_stmt->execute();
            $result = $user_stmt->get_result();
            while ($user = $result->fetch_assoc()) {
                createNotification($user['id'], 'New Announcement', $title, 'system');
            }
            $user_stmt->close();
        }
    } else {
        $_SESSION['error'] = "Failed to create announcement.";
    }
    $stmt->close();
}

// Get recent announcements
function getRecentAnnouncements($limit = 10) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT a.*, u.full_name as author_name 
        FROM announcements a 
        JOIN users u ON a.created_by = u.id 
        WHERE a.is_active = TRUE 
        AND (a.expires_at IS NULL OR a.expires_at > NOW())
        ORDER BY 
            CASE priority 
                WHEN 'urgent' THEN 1
                WHEN 'important' THEN 2
                WHEN 'normal' THEN 3
            END,
            a.created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $announcements = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $announcements;
}

// Get activity logs for dean
function getDeanActivity($limit = 20) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT al.*, u.full_name 
        FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        ORDER BY al.created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $logs = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $logs;
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

// Get room occupancy data for chart
function getRoomOccupancyData() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $rooms_result = $conn->query("SELECT * FROM rooms ORDER BY room_number");
    $rooms = $rooms_result->fetch_all(MYSQLI_ASSOC);
    
    $room_numbers = [];
    $current_occupants = [];
    $capacities = [];
    
    foreach ($rooms as $room) {
        $room_numbers[] = $room['room_number'];
        $current_occupants[] = $room['current_occupants'];
        $capacities[] = $room['capacity'];
    }
    
    return [
        'room_numbers' => $room_numbers,
        'current_occupants' => $current_occupants,
        'capacities' => $capacities
    ];
}

// Get data
$pending_reservations = getPendingReservations();
$pending_payments = getPendingPaymentRequests();
$pending_registrations = getPendingRegistrations();
$pending_visitors = getPendingVisitorRequests();
$unread_count = getUnreadNotificationCount($_SESSION['user_id']);
$notifications = getNotifications($_SESSION['user_id'], 10);
$stats = getEnhancedDeanDashboardStats();
$leave_records = getLeaveRecords();
$service_requests = getServiceRequests();
$fines = getAllFines();
$rooms = getAllRooms();
$dean_profile = getDeanProfile($_SESSION['user_id']);
$activity_logs = getDeanActivity(15);
$announcements = getRecentAnnouncements(5);
$room_data = getRoomOccupancyData();

// Determine active section from query (defaults to home)
$active_section = isset($_GET['section']) ? sanitizeInput($_GET['section']) : 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard - Dorm Management</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Using the same CSS as assistant_dashboard.php */
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
        
        .fine-item, .request-item, .attendance-item, .visitor-item, .reservation-item, .room-item { 
            border: 1px solid #e9ecef; 
            padding: 20px; 
            margin: 15px 0; 
            border-radius: 12px; 
            transition: background 0.3s;
            background: white;
        }
        
        .fine-item:hover, .request-item:hover, .attendance-item:hover, .visitor-item:hover, .reservation-item:hover, .room-item:hover {
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
        
        .chart-container {
            position: relative;
            height: 300px;
            margin: 20px 0;
        }
        
        .room-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .room-card {
            border: 1px solid #e9ecef;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
            background: white;
            border-left: 4px solid var(--success);
        }
        
        .room-card.full {
            border-left: 4px solid var(--danger);
        }
        
        .room-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .occupancy-meter {
            height: 10px;
            background: #e9ecef;
            border-radius: 5px;
            margin: 10px 0;
            overflow: hidden;
        }
        
        .occupancy-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            border-radius: 5px;
        }
        
        .activity-log {
            font-family: 'Segoe UI', sans-serif;
            font-size: 0.9rem;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 10px;
            border-left: 3px solid var(--info);
        }
        
        .activity-log .time {
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 5px;
        }
        
        .announcement-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            border-left: 4px solid var(--info);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .announcement-card.urgent {
            border-left: 4px solid var(--danger);
            background: #fff5f5;
        }
        
        .announcement-card.important {
            border-left: 4px solid var(--warning);
            background: #fff9e6;
        }
        
        .announcement-card .meta {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
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
        
        .mobile-menu-btn {
            display: none;
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
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
            
            .room-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h3>Dorm Management</h3>
            <small>Dean Portal</small>
        </div>
        <ul class="sidebar-menu">
            <li><a href="?section=home" class="<?php echo $active_section == 'home' ? 'active' : ''; ?>"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="?section=reservations" class="<?php echo $active_section == 'reservations' ? 'active' : ''; ?>"><i class="fas fa-bed"></i> Reservations</a></li>
            <li><a href="?section=approvals" class="<?php echo $active_section == 'approvals' ? 'active' : ''; ?>">
                <i class="fas fa-user-check"></i> Registration Approvals
                <?php if (count($pending_registrations) > 0): ?>
                    <span class="notification-badge"><?php echo count($pending_registrations); ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="?section=rooms" class="<?php echo $active_section == 'rooms' ? 'active' : ''; ?>"><i class="fas fa-building"></i> Room Management</a></li>
            <li><a href="?section=fines" class="<?php echo $active_section == 'fines' ? 'active' : ''; ?>"><i class="fas fa-money-bill-wave"></i> Fines</a></li>
            <!-- Payment Requests menu item has been removed -->
            <li><a href="?section=leaves" class="<?php echo $active_section == 'leaves' ? 'active' : ''; ?>"><i class="fas fa-sign-out-alt"></i> Leave Logs</a></li>
            <li><a href="?section=services" class="<?php echo $active_section == 'services' ? 'active' : ''; ?>">
                <i class="fas fa-tools"></i> Service Requests
                <?php if ($stats['pending_services'] > 0): ?>
                    <span class="notification-badge"><?php echo $stats['pending_services']; ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="?section=visitors" class="<?php echo $active_section == 'visitors' ? 'active' : ''; ?>">
                <i class="fas fa-user-friends"></i> Visitor Approvals
                <?php if (count($pending_visitors) > 0): ?>
                    <span class="notification-badge"><?php echo count($pending_visitors); ?></span>
                <?php endif; ?>
            </a></li>
            <li><a href="?section=announcements" class="<?php echo $active_section == 'announcements' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i> Announcements</a></li>
            <li><a href="?section=reports" class="<?php echo $active_section == 'reports' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Reports</a></li>
            <li><a href="?section=profile" class="<?php echo $active_section == 'profile' ? 'active' : ''; ?>"><i class="fas fa-user-cog"></i> Profile</a></li>
        </ul>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="header">
            <button class="mobile-menu-btn" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </button>
            <h1>Dean Dashboard</h1>
            <div class="header-actions">
                <span class="user-display">
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($_SESSION['full_name'], 0, 1)); ?>
                    </div>
                    <?php echo $_SESSION['full_name']; ?> <span class="role-badge"><?php echo ucfirst(str_replace('_', ' ', $_SESSION['user_type'])); ?></span>
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
                        Here's an overview of your dorm management portal.
                    </p>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-building" style="color: #4361ee;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_rooms']; ?></div>
                        <div class="stat-label">Total Rooms</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-bed" style="color: #4cc9f0;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['available_rooms']; ?></div>
                        <div class="stat-label">Available Rooms</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users" style="color: #fca311;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_occupants']; ?></div>
                        <div class="stat-label">Total Occupants</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-user-tie" style="color: #4895ef;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['total_assistants']; ?></div>
                        <div class="stat-label">Staff Members</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clock" style="color: #e63946;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_reservations']; ?></div>
                        <div class="stat-label">Pending Reservations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-money-bill-wave" style="color: #e63946;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_payments']; ?></div>
                        <div class="stat-label">Pending Payments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-tools" style="color: #fca311;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['pending_services']; ?></div>
                        <div class="stat-label">Pending Services</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line" style="color: #4cc9f0;"></i>
                        </div>
                        <div class="stat-number"><?php echo $stats['occupancy_rate']; ?>%</div>
                        <div class="stat-label">Occupancy Rate</div>
                    </div>
                </div>
                
                <div class="quick-actions">
                    <div class="quick-action-btn" onclick="window.location.href='?section=reservations'">
                        <i class="fas fa-bed"></i>
                        <span>Approve Reservations</span>
                    </div>
                    <!-- Removed Process Payments quick action button -->
                    <div class="quick-action-btn" onclick="window.location.href='?section=rooms'">
                        <i class="fas fa-building"></i>
                        <span>Manage Rooms</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=approvals'">
                        <i class="fas fa-user-check"></i>
                        <span>Approve Registrations</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=announcements'">
                        <i class="fas fa-bullhorn"></i>
                        <span>Create Announcement</span>
                    </div>
                    <div class="quick-action-btn" onclick="window.location.href='?section=reports'">
                        <i class="fas fa-chart-bar"></i>
                        <span>View Reports</span>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> Recent Announcements</h3>
                        <a href="?section=announcements" class="btn btn-small">View All</a>
                    </div>
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No recent announcements.</p>
                            <a href="?section=announcements" class="btn">Create Announcement</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card <?php echo $announcement['priority']; ?>">
                                <h4><?php echo $announcement['title']; ?></h4>
                                <p><?php echo $announcement['content']; ?></p>
                                <div class="meta">
                                    <span>By: <?php echo $announcement['author_name']; ?></span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
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
                                <div><strong><?php echo $log['full_name']; ?>:</strong> <?php echo $log['action']; ?></div>
                                <div><?php echo $log['details']; ?></div>
                                <div class="time"><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Reservations Section -->
            <div id="reservations-section" class="section <?php echo $active_section == 'reservations' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bed"></i> Pending Room Reservations</h3>
                        <span class="badge"><?php echo count($pending_reservations); ?> pending</span>
                    </div>
                    <?php if (empty($pending_reservations)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending reservations at the moment.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_reservations as $reservation): ?>
                            <div class="reservation-item">
                                <h4>Reservation #<?php echo $reservation['id']; ?></h4>
                                <p><strong>Occupant:</strong> <?php echo $reservation['full_name']; ?> (<?php echo $reservation['email']; ?>)</p>
                                <p><strong>Room:</strong> <?php echo $reservation['room_number']; ?> (<?php echo $reservation['building']; ?>, Floor <?php echo $reservation['floor']; ?>)</p>
                                <p><strong>Requested Date:</strong> <?php echo date('F j, Y', strtotime($reservation['reservation_date'])); ?></p>
                                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($reservation['created_at'])); ?></p>
                                <?php if ($reservation['special_requirements']): ?>
                                    <p><strong>Special Requirements:</strong> <?php echo $reservation['special_requirements']; ?></p>
                                <?php endif; ?>
                                
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
            
            <!-- Registration Approvals Section -->
            <div id="approvals-section" class="section <?php echo $active_section == 'approvals' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-user-check"></i> Pending Registration Approvals</h3>
                        <span class="badge"><?php echo count($pending_registrations); ?> pending</span>
                    </div>
                    
                    <?php if (empty($pending_registrations)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending registration approvals.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_registrations as $reg): ?>
                            <div class="request-item">
                                <h4>Registration Request #<?php echo $reg['id']; ?></h4>
                                <p><strong>Name:</strong> <?php echo $reg['full_name']; ?></p>
                                <p><strong>Email:</strong> <?php echo $reg['email']; ?></p>
                                <p><strong>Phone:</strong> <?php echo $reg['phone'] ?: 'Not provided'; ?></p>
                                <p><strong>Requested Role:</strong> 
                                    <span class="role-badge"><?php echo ucfirst(str_replace('_', ' ', $reg['user_type'])); ?></span>
                                </p>
                                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($reg['created_at'])); ?></p>
                                
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                        <button type="submit" name="approve_registration" class="btn btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                        <div style="display: inline-flex; gap: 10px; align-items: center;">
                                            <input type="text" name="rejection_reason" placeholder="Reason for rejection" style="padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                            <button type="submit" name="reject_registration" class="btn btn-danger">
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
            
            <!-- Room Management Section -->
            <div id="rooms-section" class="section <?php echo $active_section == 'rooms' ? 'active' : ''; ?>">
                <div class="tab-nav">
                    <button class="tab-btn active" onclick="showTab('room-overview')">Room Overview</button>
                    <button class="tab-btn" onclick="showTab('add-room')">Add New Room</button>
                </div>
                
                <!-- Room Overview Tab -->
                <div id="room-overview" class="tab-content active">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-building"></i> Room Status Overview</h3>
                        </div>
                        
                        <div class="room-grid">
                            <?php foreach ($rooms as $room): ?>
                                <div class="room-card <?php echo $room['is_available'] ? '' : 'full'; ?>">
                                    <h4>Room <?php echo $room['room_number']; ?></h4>
                                    <p><i class="fas fa-map-marker-alt"></i> <?php echo $room['building']; ?>, Floor <?php echo $room['floor']; ?></p>
                                    <p><i class="fas fa-users"></i> <?php echo $room['current_occupants']; ?>/<?php echo $room['capacity']; ?> occupants</p>
                                    <p><i class="fas fa-user-check"></i> <?php echo $room['active_assignments']; ?> active assignments</p>
                                    
                                    <div class="occupancy-meter">
                                        <div class="occupancy-fill" style="width: <?php echo ($room['capacity'] > 0) ? ($room['current_occupants'] / $room['capacity'] * 100) : 0; ?>%"></div>
                                    </div>
                                    
                                    <p style="margin-top: 10px;">
                                        <span style="color: <?php echo $room['is_available'] ? '#27ae60' : '#e74c3c'; ?>; font-weight: bold;">
                                            <?php echo $room['is_available'] ? '<i class="fas fa-check"></i> Available' : '<i class="fas fa-times"></i> Full'; ?>
                                        </span>
                                    </p>
                                    
                                    <div class="action-buttons" style="margin-top: 15px;">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                            <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                            <input type="hidden" name="is_available" value="<?php echo $room['is_available'] ? '0' : '1'; ?>">
                                            <button type="submit" name="update_room_status" class="btn btn-small <?php echo $room['is_available'] ? 'btn-danger' : 'btn-success'; ?>">
                                                <?php echo $room['is_available'] ? 'Mark as Full' : 'Mark as Available'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Add Room Tab -->
                <div id="add-room" class="tab-content">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-plus-circle"></i> Add New Room</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="room_number">Room Number</label>
                                    <input type="text" name="room_number" id="room_number" required placeholder="e.g., 101">
                                </div>
                                
                                <div class="form-group">
                                    <label for="floor">Floor</label>
                                    <input type="text" name="floor" id="floor" required placeholder="e.g., 1">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="building">Building</label>
                                    <input type="text" name="building" id="building" required placeholder="e.g., Main Building">
                                </div>
                                
                                <div class="form-group">
                                    <label for="capacity">Capacity</label>
                                    <input type="number" name="capacity" id="capacity" required min="1" max="10" value="4">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="description">Description (Optional)</label>
                                <textarea name="description" id="description" placeholder="Room features, amenities, etc."></textarea>
                            </div>
                            
                            <button type="submit" name="add_room" class="btn btn-success">
                                <i class="fas fa-plus-circle"></i> Add Room
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Fines Section -->
            <div id="fines-section" class="section <?php echo $active_section == 'fines' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-money-bill-wave"></i> Fines Management</h3>
                    </div>

                    <?php if (empty($fines)): ?>
                        <div class="empty-state">
                            <i class="fas fa-money-bill-wave"></i>
                            <p>No fines recorded.</p>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Occupant</th>
                                    <th>Amount</th>
                                    <th>Reason</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Issued</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fines as $fine): ?>
                                    <tr>
                                        <td>#<?php echo $fine['id']; ?></td>
                                        <td><?php echo $fine['full_name']; ?></td>
                                        <td>₱<?php echo number_format($fine['amount'], 2); ?></td>
                                        <td><?php echo $fine['reason']; ?></td>
                                        <td><?php echo date('M j, Y', strtotime($fine['due_date'])); ?></td>
                                        <td><span class="status-<?php echo $fine['status']; ?>"><?php echo ucfirst(str_replace('_', ' ', $fine['status'])); ?></span></td>
                                        <td><?php echo date('M j, Y', strtotime($fine['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Requests Section (Removed from menu but still exists in code) -->
            <div id="payments-section" class="section <?php echo $active_section == 'payments' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-credit-card"></i> Pending Payment Requests</h3>
                        <span class="badge"><?php echo count($pending_payments); ?> pending</span>
                    </div>

                    <?php if (empty($pending_payments)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>No pending payment requests.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pending_payments as $payment): ?>
                            <div class="request-item">
                                <h4>Payment Request #<?php echo $payment['id']; ?> - ₱<?php echo number_format($payment['amount'], 2); ?></h4>
                                <p><strong>Occupant:</strong> <?php echo $payment['full_name']; ?> (<?php echo $payment['email']; ?>)</p>
                                <p><strong>Fine ID:</strong> #<?php echo $payment['fine_id']; ?></p>
                                <p><strong>Reason:</strong> <?php echo $payment['reason']; ?></p>
                                <p><strong>Fine Amount:</strong> ₱<?php echo number_format($payment['amount'], 2); ?></p>
                                <p><strong>Fine Due Date:</strong> <?php echo date('F j, Y', strtotime($payment['due_date'])); ?></p>
                                <p><strong>Requested:</strong> <?php echo date('F j, Y g:i A', strtotime($payment['created_at'])); ?></p>
                                <p><strong>Status:</strong> <span class="status-payment_pending"><?php echo ucfirst(str_replace('_', ' ', $payment['status'])); ?></span></p>
                                
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="payment_request_id" value="<?php echo $payment['id']; ?>">
                                        <button type="submit" name="approve_payment" class="btn btn-success">
                                            <i class="fas fa-check"></i> Approve Payment
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="payment_request_id" value="<?php echo $payment['id']; ?>">
                                        <div style="display: inline-flex; gap: 10px; align-items: center;">
                                            <input type="text" name="rejection_reason" placeholder="Reason for rejection" style="padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
                                            <button type="submit" name="reject_payment" class="btn btn-danger">
                                                <i class="fas fa-times"></i> Reject Payment
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Leave Logs Section -->
            <div id="leaves-section" class="section <?php echo $active_section == 'leaves' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-sign-out-alt"></i> Occupant Leave Logs (Check-Out Times)</h3>
                    </div>

                    <?php if (empty($leave_records)): ?>
                        <div class="empty-state">
                            <i class="fas fa-sign-out-alt"></i>
                            <p>No leave records found.</p>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Occupant</th>
                                    <th>Check In</th>
                                    <th>Check Out</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($leave_records as $rec): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($rec['date'])); ?></td>
                                        <td><?php echo $rec['full_name']; ?></td>
                                        <td><?php echo $rec['check_in_time'] ?: 'N/A'; ?></td>
                                        <td><?php echo $rec['check_out_time'] ?: 'N/A'; ?></td>
                                        <td><?php echo $rec['notes'] ?: '-'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Service Requests Section -->
            <div id="services-section" class="section <?php echo $active_section == 'services' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-tools"></i> Service Requests Management</h3>
                        <span class="badge"><?php echo $stats['pending_services']; ?> pending</span>
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
                                <?php if ($request['assigned_to_name']): ?>
                                    <p><strong>Assigned To:</strong> <?php echo $request['assigned_to_name']; ?></p>
                                <?php endif; ?>
                                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Visitor Approvals Section -->
            <div id="visitors-section" class="section <?php echo $active_section == 'visitors' ? 'active' : ''; ?>">
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
                            <div class="request-item">
                                <h4>Visitor Request #<?php echo $visitor['id']; ?></h4>
                                <p><strong>Requested By:</strong> <?php echo $visitor['occupant_name']; ?> (<?php echo $visitor['occupant_email']; ?>)</p>
                                <p><strong>Visitor Name:</strong> <?php echo $visitor['visitor_name']; ?></p>
                                <p><strong>Visitor Email:</strong> <?php echo $visitor['visitor_email'] ?: 'Not provided'; ?></p>
                                <p><strong>Visitor Phone:</strong> <?php echo $visitor['visitor_phone'] ?: 'Not provided'; ?></p>
                                <p><strong>Visit Date:</strong> <?php echo date('F j, Y', strtotime($visitor['visit_date'])); ?></p>
                                <p><strong>Purpose:</strong> <?php echo $visitor['visit_purpose']; ?></p>
                                <p><strong>Submitted:</strong> <?php echo date('F j, Y g:i A', strtotime($visitor['created_at'])); ?></p>
                                
                                <div class="action-buttons">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="visitor_id" value="<?php echo $visitor['id']; ?>">
                                        <button type="submit" name="approve_visitor" class="btn btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="visitor_id" value="<?php echo $visitor['id']; ?>">
                                        <div style="display: inline-flex; gap: 10px; align-items: center;">
                                            <input type="text" name="rejection_reason" placeholder="Reason for rejection" style="padding: 8px; border-radius: 6px; border: 1px solid #e9ecef;">
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
            
            <!-- Announcements Section -->
            <div id="announcements-section" class="section <?php echo $active_section == 'announcements' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-bullhorn"></i> Create New Announcement</h3>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <div class="form-group">
                            <label for="title">Announcement Title</label>
                            <input type="text" name="title" id="title" required placeholder="Enter announcement title">
                        </div>
                        
                        <div class="form-group">
                            <label for="content">Announcement Content</label>
                            <textarea name="content" id="content" required placeholder="Enter announcement details..." rows="4"></textarea>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="priority">Priority</label>
                                <select name="priority" id="priority" required>
                                    <option value="normal">Normal</option>
                                    <option value="important">Important</option>
                                    <option value="urgent">Urgent</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="target_users">Target Audience</label>
                                <select name="target_users" id="target_users" required>
                                    <option value="all">All Users</option>
                                    <option value="occupants">Occupants Only</option>
                                    <option value="assistants">Assistants Only</option>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" name="create_announcement" class="btn btn-success">
                            <i class="fas fa-bullhorn"></i> Publish Announcement
                        </button>
                    </form>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-history"></i> Recent Announcements</h3>
                    </div>
                    <?php if (empty($announcements)): ?>
                        <div class="empty-state">
                            <i class="fas fa-bullhorn"></i>
                            <p>No announcements found.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="announcement-card <?php echo $announcement['priority']; ?>">
                                <h4><?php echo $announcement['title']; ?></h4>
                                <p><?php echo $announcement['content']; ?></p>
                                <div class="meta">
                                    <span>By: <?php echo $announcement['author_name']; ?> | Target: <?php echo ucfirst($announcement['target_users']); ?></span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Reports Section -->
            <div id="reports-section" class="section <?php echo $active_section == 'reports' ? 'active' : ''; ?>">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-bar"></i> Room Occupancy Chart</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="occupancyChart"></canvas>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-chart-pie"></i> Dorm Statistics</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="dormStatsChart"></canvas>
                    </div>
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
                        <div class="card-header">
                            <h3><i class="fas fa-user-cog"></i> Profile Information</h3>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <div class="form-group">
                                <label for="full_name">Full Name</label>
                                <input type="text" name="full_name" id="full_name" required value="<?php echo $dean_profile['full_name']; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email Address</label>
                                <input type="email" name="email" id="email" readonly value="<?php echo $dean_profile['email']; ?>">
                                <small style="color: #6c757d;">Email cannot be changed</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">Phone Number</label>
                                <input type="text" name="phone" id="phone" value="<?php echo $dean_profile['phone'] ?? ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label>Member Since</label>
                                <input type="text" readonly value="<?php echo date('F j, Y', strtotime($dean_profile['created_at'])); ?>">
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

        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Room Occupancy Chart
            const occupancyCtx = document.getElementById('occupancyChart');
            if (occupancyCtx) {
                const roomData = <?php echo json_encode($room_data); ?>;
                
                const occupancyChart = new Chart(occupancyCtx, {
                    type: 'bar',
                    data: {
                        labels: roomData.room_numbers,
                        datasets: [
                            {
                                label: 'Current Occupants',
                                data: roomData.current_occupants,
                                backgroundColor: 'rgba(67, 97, 238, 0.7)',
                                borderColor: 'rgba(67, 97, 238, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Capacity',
                                data: roomData.capacities,
                                backgroundColor: 'rgba(231, 76, 60, 0.3)',
                                borderColor: 'rgba(231, 76, 60, 1)',
                                borderWidth: 1,
                                type: 'line',
                                fill: false
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: Math.max(...roomData.capacities) + 2,
                                title: {
                                    display: true,
                                    text: 'Number of Occupants'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Room Numbers'
                                }
                            }
                        },
                        plugins: {
                            title: {
                                display: true,
                                text: 'Room Occupancy vs Capacity'
                            }
                        }
                    }
                });
            }
            
            // Dorm Statistics Chart
            const statsCtx = document.getElementById('dormStatsChart');
            if (statsCtx) {
                const statsChart = new Chart(statsCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Occupied Rooms', 'Available Rooms'],
                        datasets: [{
                            data: [
                                <?php echo $stats['total_rooms'] - $stats['available_rooms']; ?>,
                                <?php echo $stats['available_rooms']; ?>
                            ],
                            backgroundColor: [
                                '#4361ee',
                                '#4cc9f0'
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
                                text: 'Room Utilization'
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