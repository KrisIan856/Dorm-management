<?php
include 'config.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $redirect = match($_SESSION['user_type']) {
        'dormdean' => 'dormdean_dashboard.php',
        'dormdean_assistant' => 'assistant_dashboard.php',
        default => 'occupant_dashboard.php'
    };
    header("Location: $redirect");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $email = sanitizeInput($_POST['email'] ?? '', 'email');

        if (empty($email)) {
            $error = "Please enter your email address.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $email, $token, $expires);
                $stmt->execute();
                $stmt->close();

                $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/softeng/reset_password.php?token=$token";
                error_log("Password reset link for $email: $reset_link");
            }
            $stmt->close();

            $success = "If that email is registered, a password reset link has been sent. Please check your email.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - Dorm Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --primary: #4361ee; --secondary: #3f37c9; --dark: #1d3557; --light: #f8f9fa; --danger: #e63946; --success: #4cc9f0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex; justify-content: center; align-items: center; padding: 20px;
        }
        .container {
            background: white; border-radius: 20px; box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden; width: 100%; max-width: 420px;
        }
        .header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white; padding: 30px; text-align: center;
        }
        .header h1 { font-size: 1.8rem; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 0.95rem; }
        .body { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--dark); }
        .form-control {
            width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px;
            font-size: 1rem; transition: all 0.3s; background: var(--light);
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); background: white; }
        .btn {
            width: 100%; padding: 14px; background: var(--primary); color: white; border: none;
            border-radius: 10px; font-size: 1rem; font-weight: 600; cursor: pointer;
            transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .btn:hover { background: var(--secondary); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(67,97,238,0.3); }
        .alert { padding: 12px 15px; border-radius: 10px; margin-bottom: 20px; font-size: 0.9rem; display: flex; align-items: flex-start; gap: 10px; }
        .alert-danger { background: #ffe6e6; color: var(--danger); border-left: 4px solid var(--danger); }
        .alert-success { background: #e6f7e6; color: #2e7d32; border-left: 4px solid #2e7d32; }
        .footer { text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid #e9ecef; font-size: 0.9rem; }
        .footer a { color: var(--primary); text-decoration: none; font-weight: 500; }
        .footer a:hover { text-decoration: underline; }
        @media (max-width: 480px) { .header { padding: 25px 20px; } .body { padding: 25px 20px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-key"></i> Forgot Password</h1>
            <p>Enter your email to reset your password</p>
        </div>
        <div class="body">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <?php if (empty($success)): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <div class="form-group">
                    <label for="email" class="form-label"><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" id="email" class="form-control" placeholder="Enter your email" required autofocus>
                </div>
                <button type="submit" class="btn"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
            </form>
            <?php endif; ?>

            <div class="footer">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        </div>
    </div>
</body>
</html>
