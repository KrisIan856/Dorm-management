<?php
// config.php
session_start();

// Security headers
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 0); // Set to 0 in production

// Update these with your actual database credentials
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'dorm_management');

class Database {
    private $connection;
    
    public function __construct() {
        try {
            $this->connection = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            
            if ($this->connection->connect_error) {
                throw new Exception("Connection failed: " . $this->connection->connect_error);
            }
            
            $this->connection->set_charset("utf8mb4");
            
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function __destruct() {
        if ($this->connection) {
            $this->connection->close();
        }
    }
}

function sanitizeInput($data, $type = null) {
    if (!isset($data) || $data === null) {
        return '';
    }
    
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');

    // Optional type-specific sanitization
    if ($type === 'email') {
        $data = filter_var($data, FILTER_SANITIZE_EMAIL);
    } elseif ($type === 'int') {
        $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
    } elseif ($type === 'float') {
        $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    } elseif ($type === 'string') {
        $data = filter_var($data, FILTER_SANITIZE_STRING);
    }

    return $data;
}

// CSRF helpers
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Enhanced session security check
function checkSession() {
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        header("Location: login.php");
        exit();
    }
    
    // Session timeout (1 hour)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        session_unset();
        session_destroy();
        header("Location: login.php?session=expired");
        exit();
    }
    
    // Regenerate session ID every 15 minutes for security
    if (!isset($_SESSION['regenerated']) || (time() - $_SESSION['regenerated'] > 900)) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = time();
    }
    
    $_SESSION['last_activity'] = time();
}

// Create notification
function createNotification($user_id, $title, $message, $type = 'system', $related_id = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("isssi", $user_id, $title, $message, $type, $related_id);
    $stmt->execute();
    $stmt->close();
}

// Get unread notification count
function getUnreadNotificationCount($user_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    
    return $count;
}

// Get notifications
function getNotifications($user_id, $limit = 10) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $notifications;
}

// Mark notification as read - MAKE IT MORE FLEXIBLE
function markNotificationAsRead($notification_id, $user_id = null) {
    $db = new Database();
    $conn = $db->getConnection();
    
    // If user_id is not provided, use session user_id
    if ($user_id === null && isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
    }
    
    // If still no user_id, try to get it from the notification
    if ($user_id === null) {
        $stmt = $conn->prepare("SELECT user_id FROM notifications WHERE id = ?");
        $stmt->bind_param("i", $notification_id);
        $stmt->execute();
        $stmt->bind_result($user_id);
        $stmt->fetch();
        $stmt->close();
    }
    
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $notification_id, $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Mark all notifications as read
function markAllNotificationsAsRead($user_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE");
    $stmt->bind_param("i", $user_id);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// Get pending fines for dashboards
function getPendingFines() {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare(
        "SELECT f.*, u.full_name, u.email 
         FROM fines f 
         JOIN users u ON f.user_id = u.id 
         WHERE f.status = 'pending' 
         ORDER BY f.created_at DESC"
    );
    $stmt->execute();
    $result = $stmt->get_result();
    $payments = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $payments;
}

// Registration Approval Functions
function notifyDormDeansAboutRegistration($registration_data) {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get all dorm deans
    $stmt = $conn->prepare("SELECT id FROM users WHERE user_type = 'dormdean' AND is_active = TRUE");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($dean = $result->fetch_assoc()) {
        $title = "New Registration Pending Approval";
        $message = "User: " . $registration_data['full_name'] . 
                  " (" . $registration_data['email'] . ") wants to register as " . 
                  ucfirst(str_replace('_', ' ', $registration_data['user_type'])) . 
                  ". Please review in the approval section.";
        createNotification($dean['id'], $title, $message, 'system');
    }
    
    $stmt->close();
}

function getPendingRegistrations() {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT * FROM registration_approvals 
        WHERE status = 'pending' 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $registrations = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $registrations;
}

function approveRegistration($registration_id, $approved_by) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->begin_transaction();
    
    try {
        // Get registration data
        $stmt = $conn->prepare("SELECT * FROM registration_approvals WHERE id = ?");
        $stmt->bind_param("i", $registration_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $registration = $result->fetch_assoc();
        $stmt->close();
        
        if (!$registration) {
            throw new Exception("Registration not found");
        }
        
        // Insert into users table
        $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, full_name, phone, is_active) VALUES (?, ?, ?, ?, ?, TRUE)");
        $stmt->bind_param("sssss", 
            $registration['email'], 
            $registration['password'], 
            $registration['user_type'], 
            $registration['full_name'], 
            $registration['phone']
        );
        $stmt->execute();
        $user_id = $stmt->insert_id;
        $stmt->close();
        
        // Update approval record
        $stmt = $conn->prepare("UPDATE registration_approvals SET status = 'approved', approved_by = ?, approved_at = NOW(), user_id = ? WHERE id = ?");
        $stmt->bind_param("iii", $approved_by, $user_id, $registration_id);
        $stmt->execute();
        $stmt->close();
        
        // Log the approval
        error_log("Registration approved: " . $registration['email'] . " by user ID: " . $approved_by);
        
        $conn->commit();
        return ['success' => true, 'user_id' => $user_id];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error approving registration: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function rejectRegistration($registration_id, $rejected_by, $reason) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("UPDATE registration_approvals SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?");
    $stmt->bind_param("isi", $rejected_by, $reason, $registration_id);
    
    if ($stmt->execute()) {
        error_log("Registration rejected: ID " . $registration_id . " by user ID: " . $rejected_by . " - Reason: " . $reason);
        return true;
    }
    
    return false;
}

// Activity logging function
function logActivity($user_id, $action, $details = '') {
    $db = new Database();
    $conn = $db->getConnection();
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $user_id, $action, $details, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

// Get assistant activity logs
function getAssistantActivityLogs($limit = 50) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT al.*, u.full_name 
        FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        WHERE u.user_type = 'dormdean_assistant'
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

// Emergency contacts
function getEmergencyContacts() {
    return [
        ['name' => 'Campus Security', 'phone' => '(123) 456-7890', 'ext' => '111'],
        ['name' => 'Fire Department', 'phone' => '(123) 456-7891', 'ext' => ''],
        ['name' => 'Medical Emergency', 'phone' => '(123) 456-7892', 'ext' => '222'],
        ['name' => 'Maintenance (24/7)', 'phone' => '(123) 456-7893', 'ext' => '333'],
        ['name' => 'Dean of Students', 'phone' => '(123) 456-7894', 'ext' => '444']
    ];
}

// Export data functions
function exportAttendanceToCSV($date_from, $date_to) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT a.date, u.full_name, a.status, a.check_in_time, a.check_out_time, a.notes
        FROM attendance a
        JOIN users u ON a.user_id = u.id
        WHERE a.date BETWEEN ? AND ?
        ORDER BY a.date DESC, u.full_name
    ");
    $stmt->bind_param("ss", $date_from, $date_to);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $csv = "Date,Occupant Name,Status,Check In,Check Out,Notes\n";
    
    while ($row = $result->fetch_assoc()) {
        $csv .= '"' . $row['date'] . '","' . $row['full_name'] . '","' . $row['status'] . '","' . 
                ($row['check_in_time'] ?? '') . '","' . ($row['check_out_time'] ?? '') . '","' . ($row['notes'] ?? '') . "\"\n";
    }
    
    $stmt->close();
    return $csv;
}

// Search occupants
function searchOccupants($search_term) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $search_term = "%" . $search_term . "%";
    $stmt = $conn->prepare("
        SELECT id, full_name, email, phone, created_at 
        FROM users 
        WHERE user_type = 'occupant' 
        AND is_active = TRUE 
        AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?)
        ORDER BY full_name
    ");
    $stmt->bind_param("sss", $search_term, $search_term, $search_term);
    $stmt->execute();
    $result = $stmt->get_result();
    $occupants = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $occupants;
}

// Get dashboard statistics with more details
function getEnhancedDashboardStats($assistant_id) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stats = [];
    
    // Total occupants
    $result = $conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'occupant' AND is_active = TRUE");
    $stats['total_occupants'] = $result->fetch_array()[0];
    
    // Active occupants today
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE date = ? AND status = 'present'");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $stmt->bind_result($active_today);
    $stmt->fetch();
    $stats['active_today'] = $active_today;
    $stmt->close();
    
    // Pending service requests
    $result = $conn->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending'");
    $stats['pending_service_requests'] = $result->fetch_array()[0];
    
    // Urgent service requests
    $result = $conn->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending' AND urgency IN ('high', 'emergency')");
    $stats['urgent_service_requests'] = $result->fetch_array()[0];
    
    // Total pending fines
    $result = $conn->query("SELECT COUNT(*) FROM fines WHERE status = 'pending'");
    $stats['pending_fines'] = $result->fetch_array()[0];
    
    // Total fines amount
    $result = $conn->query("SELECT SUM(amount) FROM fines WHERE status = 'pending'");
    $stats['total_pending_fines'] = $result->fetch_array()[0] ?: 0;
    
    // Today's attendance
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $stmt->bind_result($today_attendance);
    $stmt->fetch();
    $stats['today_attendance'] = $today_attendance;
    $stmt->close();
    
    // Pending approvals
    $result = $conn->query("SELECT COUNT(*) FROM visitor_requests WHERE status = 'pending'");
    $stats['pending_visitors'] = $result->fetch_array()[0];
    
    $result = $conn->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    $stats['pending_reservations'] = $result->fetch_array()[0];
    
    // Assistant's recent activities
    $stmt = $conn->prepare("SELECT COUNT(*) FROM activity_logs WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->bind_param("i", $assistant_id);
    $stmt->execute();
    $stmt->bind_result($today_activities);
    $stmt->fetch();
    $stats['today_activities'] = $today_activities;
    $stmt->close();
    
    return $stats;
}

// Initialize database with COMPLETE tables
function initializeDatabase() {
    $temp_conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($temp_conn->connect_error) {
        die("Connection failed: " . $temp_conn->connect_error);
    }
    
    $temp_conn->query("CREATE DATABASE IF NOT EXISTS " . DB_NAME);
    $temp_conn->close();
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Disable foreign key checks to avoid issues during table creation
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Complete table definitions
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            user_type ENUM('dormdean', 'dormdean_assistant', 'occupant') NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL,
            is_active BOOLEAN DEFAULT TRUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS registration_approvals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            email VARCHAR(255) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            user_type ENUM('dormdean', 'dormdean_assistant', 'occupant') NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            rejection_reason TEXT NULL,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_user_type (user_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS login_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            session_token VARCHAR(255),
            login_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            logout_time TIMESTAMP NULL,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255) NULL,
            is_active BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_number VARCHAR(10) UNIQUE NOT NULL,
            floor VARCHAR(10) DEFAULT '1',
            building VARCHAR(50) DEFAULT 'Main',
            capacity INT DEFAULT 4,
            current_occupants INT DEFAULT 0,
            is_available BOOLEAN DEFAULT TRUE,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS room_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            room_id INT,
            assigned_date DATE NOT NULL,
            checkout_date DATE NULL,
            status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            UNIQUE KEY unique_active_assignment (user_id, status),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            room_id INT,
            reservation_date DATE NOT NULL,
            special_requirements TEXT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            approved_by INT NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_reservation_date (reservation_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('reservation', 'approval', 'system', 'service', 'visitor', 'payment') DEFAULT 'system',
            is_read BOOLEAN DEFAULT FALSE,
            related_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_read (user_id, is_read),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS service_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            service_type ENUM('electrical', 'plumbing', 'furniture', 'aircon', 'cleaning', 'other') NOT NULL,
            description TEXT NOT NULL,
            urgency ENUM('low', 'medium', 'high', 'emergency') DEFAULT 'medium',
            status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            assigned_to INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_urgency (urgency)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS visitor_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            visitor_name VARCHAR(255) NOT NULL,
            visitor_email VARCHAR(255),
            visitor_phone VARCHAR(20),
            visit_date DATE NOT NULL,
            visit_time TIME NULL,
            visit_purpose TEXT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by INT NULL,
            approved_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_visit_date (visit_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS fines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            amount DECIMAL(10,2) NOT NULL,
            reason TEXT NOT NULL,
            status ENUM('pending', 'paid', 'waived', 'payment_pending') DEFAULT 'pending',
            due_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            paid_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_status (status),
            INDEX idx_due_date (due_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS payment_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fine_id INT,
            user_id INT,
            amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('cash', 'card', 'online') DEFAULT 'cash',
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            approved_by INT NULL,
            FOREIGN KEY (fine_id) REFERENCES fines(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            date DATE NOT NULL,
            status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
            check_in_time TIME NULL,
            check_out_time TIME NULL,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_attendance (user_id, date),
            INDEX idx_date (date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email (email),
            INDEX idx_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            action VARCHAR(255) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_activity (user_id, created_at),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            created_by INT,
            priority ENUM('normal', 'important', 'urgent') DEFAULT 'normal',
            target_users ENUM('all', 'occupants', 'assistants', 'specific') DEFAULT 'all',
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_is_active (is_active),
            INDEX idx_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS inventory (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            category ENUM('furniture', 'electronics', 'utilities', 'maintenance', 'other') DEFAULT 'other',
            quantity INT DEFAULT 1,
            room_id INT NULL,
            status ENUM('available', 'in_use', 'maintenance', 'retired') DEFAULT 'available',
            last_check DATE,
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
            INDEX idx_status (status),
            INDEX idx_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        
        "CREATE TABLE IF NOT EXISTS documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT,
            document_type ENUM('contract', 'id', 'payment', 'other') DEFAULT 'other',
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT,
            uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    foreach ($tables as $table) {
        if (!$conn->query($table)) {
            error_log("Table creation failed: " . $conn->error . " - SQL: " . substr($table, 0, 100));
        }
    }

    // Re-enable foreign key checks
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    
    // Insert sample rooms if none exist
    $room_check = $conn->query("SELECT COUNT(*) FROM rooms");
    if ($room_check->fetch_array()[0] == 0) {
        $rooms = [
            ['101', '1', 'Main', 4],
            ['102', '1', 'Main', 4],
            ['103', '1', 'Main', 4],
            ['104', '1', 'Main', 4],
            ['201', '2', 'Main', 4],
            ['202', '2', 'Main', 4],
            ['301', '3', 'Main', 2],
            ['302', '3', 'Main', 2]
        ];
        foreach ($rooms as $room) {
            $stmt = $conn->prepare("INSERT INTO rooms (room_number, floor, building, capacity) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssi", $room[0], $room[1], $room[2], $room[3]);
            $stmt->execute();
        }
    }
    
    // Insert sample data for testing
    $user_check = $conn->query("SELECT COUNT(*) FROM users WHERE user_type = 'dormdean'");
    if ($user_check->fetch_array()[0] == 0) {
        // Insert initial dorm dean
        $password = password_hash('Dean@1234', PASSWORD_DEFAULT);
        $email1 = 'dean@dorm.com';
        $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, full_name, is_active) VALUES (?, ?, 'dormdean', 'System Admin', TRUE)");
        $stmt->bind_param("ss", $email1, $password);
        $stmt->execute();
        $dean_id = $stmt->insert_id;
        
        // Insert sample assistant
        $assistant_password = password_hash('Assistant@1234', PASSWORD_DEFAULT);
        $email2 = 'assistant@dorm.com';
        $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, full_name, is_active) VALUES (?, ?, 'dormdean_assistant', 'John Assistant', TRUE)");
        $stmt->bind_param("ss", $email2, $assistant_password);
        $stmt->execute();
        $assistant_id = $stmt->insert_id;
        
        // Insert sample occupants
        $occupants = [
            ['email' => 'student1@university.edu', 'name' => 'Alice Johnson', 'password' => password_hash('Student@123', PASSWORD_DEFAULT), 'phone' => '123-456-7890'],
            ['email' => 'student2@university.edu', 'name' => 'Bob Smith', 'password' => password_hash('Student@123', PASSWORD_DEFAULT), 'phone' => '123-456-7891'],
            ['email' => 'student3@university.edu', 'name' => 'Charlie Brown', 'password' => password_hash('Student@123', PASSWORD_DEFAULT), 'phone' => '123-456-7892']
        ];
        
        foreach ($occupants as $occupant) {
            $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, full_name, phone, is_active) VALUES (?, ?, 'occupant', ?, ?, TRUE)");
            $stmt->bind_param("ssss", $occupant['email'], $occupant['password'], $occupant['name'], $occupant['phone']);
            $stmt->execute();
            $user_id = $stmt->insert_id;
            
            // Assign room to some occupants
            if ($occupant['name'] == 'Alice Johnson') {
                $room_stmt = $conn->prepare("INSERT INTO room_assignments (user_id, room_id, assigned_date, status) VALUES (?, 1, CURDATE(), 'active')");
                $room_stmt->bind_param("i", $user_id);
                $room_stmt->execute();
                $room_stmt->close();
                
                // Update room occupant count
                $conn->query("UPDATE rooms SET current_occupants = current_occupants + 1 WHERE id = 1");
            }
        }
        
        // Create sample announcements
        $announcements = [
            ['Welcome to Dorm Management System', 'Welcome to our new dorm management system. Please update your profile information.', $dean_id, 'important', 'all'],
            ['Maintenance Notice', 'Scheduled maintenance on floor 2 this Saturday from 9 AM to 12 PM.', $assistant_id, 'normal', 'occupants'],
            ['Fire Drill Alert', 'Fire drill scheduled for next Tuesday at 10 AM. Participation is mandatory.', $dean_id, 'urgent', 'all']
        ];
        
        foreach ($announcements as $announcement) {
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, created_by, priority, target_users, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))");
            $stmt->bind_param("ssiss", $announcement[0], $announcement[1], $announcement[2], $announcement[3], $announcement[4]);
            $stmt->execute();
        }
        
        error_log("Initial database setup completed successfully.");
    }
    
    return true;
}

// Initialize database
initializeDatabase();
?>