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
    <title>Forgot Password - Enterprise Dorm Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="shared_styles.css">
    <script src="assets/js/dashboard_enhancements.js" defer></script>
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #31104b 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container-wrapper {
            width: 100%;
            max-width: 440px;
        }
        .container {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        .header {
            background: var(--brand-gradient);
            color: white;
            padding: 32px 28px;
            text-align: center;
            position: relative;
        }
        .header-theme-toggle {
            position: absolute;
            top: 16px;
            right: 16px;
        }
        .header h1 { font-size: 1.5rem; font-weight: 800; margin-bottom: 4px; }
        .header p { opacity: 0.9; font-size: 0.88rem; }
        .body { padding: 30px; }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border-color);
        }
        .footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container-wrapper">
        <div class="container">
            <div class="header">
                <div class="header-theme-toggle">
                    <button class="theme-toggle-btn" title="Toggle Light/Dark Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
                <h1><i class="fas fa-key"></i> Forgot Password</h1>
                <p>Enter your email to receive a secure password reset link</p>
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
                    <button type="submit" class="btn btn-primary" style="width: 100%;"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
                </form>
                <?php endif; ?>

                <div class="footer">
                    <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
