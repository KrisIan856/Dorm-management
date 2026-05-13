<?php
// process_payment.php - CORRECTED VERSION
session_start();
require_once 'config.php';

// Security check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: login.php");
    exit();
}

// Verify user is occupant
if ($_SESSION['user_type'] != 'occupant') {
    header("Location: login.php");
    exit();
}

// Get user ID from session
$user_id = $_SESSION['user_id'] ?? 0;
$full_name = $_SESSION['full_name'] ?? '';

if (!$user_id) {
    header("Location: login.php");
    exit();
}

// Handle POST request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['error'] = 'Invalid security token. Please try again.';
        header('Location: occupant_dashboard.php?section=attendance');
        exit();
    }
    
    if (!isset($_POST['fine_id']) || !isset($_POST['payment_method'])) {
        $_SESSION['error'] = "Missing required fields.";
        header('Location: occupant_dashboard.php?section=attendance');
        exit();
    }
    
    $fine_id = intval($_POST['fine_id']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Get fine details
        $stmt = $conn->prepare("SELECT amount, reason FROM fines WHERE id = ? AND user_id = ? AND status = 'pending'");
        $stmt->bind_param("ii", $fine_id, $user_id);
        $stmt->execute();
        $stmt->bind_result($amount, $reason);
        
        if (!$stmt->fetch()) {
            $_SESSION['error'] = "Fine not found or already processed.";
            $stmt->close();
            header('Location: occupant_dashboard.php?section=attendance');
            exit();
        }
        $stmt->close();
        
        // Check for existing payment request
        $stmt = $conn->prepare("SELECT id FROM payment_requests WHERE fine_id = ? AND status = 'pending'");
        $stmt->bind_param("i", $fine_id);
        $stmt->execute();
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $_SESSION['error'] = "A payment request for this fine is already pending.";
            $stmt->close();
            header('Location: occupant_dashboard.php?section=attendance');
            exit();
        }
        $stmt->close();
        
        // Create payment request
        $stmt = $conn->prepare("INSERT INTO payment_requests (fine_id, user_id, amount, payment_method) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iids", $fine_id, $user_id, $amount, $payment_method);
        
        if ($stmt->execute()) {
            $payment_request_id = $stmt->insert_id;
            
            // Update fine status
            $update_stmt = $conn->prepare("UPDATE fines SET status = 'payment_pending' WHERE id = ?");
            $update_stmt->bind_param("i", $fine_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Get dorm dean for notification
            $dean_stmt = $conn->prepare("SELECT id FROM users WHERE user_type IN ('dormdean', 'dormdean_assistant') AND is_active = TRUE");
            $dean_stmt->execute();
            $dean_result = $dean_stmt->get_result();
            while ($dean = $dean_result->fetch_assoc()) {
                createNotification(
                    $dean['id'],
                    'New Payment Request',
                    "Payment request for fine #$fine_id (₱" . number_format($amount, 2) . ") from " . $full_name,
                    'payment',
                    $payment_request_id
                );
            }
            $dean_stmt->close();
            
            // Send notification to user
            createNotification(
                $user_id,
                'Payment Request Submitted',
                "Your payment request for fine #$fine_id (₱" . number_format($amount, 2) . ") has been submitted and is pending approval.",
                'payment',
                $payment_request_id
            );
            
            $_SESSION['success'] = "Payment request submitted successfully! The dorm dean will review and approve your payment.";
            logActivity($user_id, 'PAYMENT_REQUESTED', "Requested payment for fine #$fine_id (₱" . number_format($amount, 2) . ")");
        } else {
            $_SESSION['error'] = "Failed to submit payment request. Please try again.";
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $_SESSION['error'] = "An error occurred. Please try again later.";
        error_log("Payment processing error: " . $e->getMessage());
    }
    
    header('Location: occupant_dashboard.php?section=attendance');
    exit();
} else {
    header("Location: occupant_dashboard.php");
    exit();
}
?>