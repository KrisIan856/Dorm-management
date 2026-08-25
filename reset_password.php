<?php
include 'config.php';

$error = '';
$success = '';
$token_valid = false;
$email = '';

if (isset($_GET['token'])) {
    $token = sanitizeInput($_GET['token']);
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT email, expires_at, used FROM password_resets WHERE token = ?");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $stmt->bind_result($email, $expires_at, $used);
    if ($stmt->fetch()) {
        if ($used) {
            $error = "This reset link has already been used.";
        } elseif (strtotime($expires_at) < time()) {
            $error = "This reset link has expired.";
        } else {
            $token_valid = true;
        }
    } else {
        $error = "Invalid reset link.";
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['reset_password'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed.";
    } else {
        $token = sanitizeInput($_POST['token']);
        $password = $_POST['password'];
        $confirm = $_POST['confirm_password'];

        if (strlen($password) < 8) {
            $error = "Password must be at least 8 characters.";
        } elseif (!preg_match('/[A-Z]/', $password)) {
            $error = "Password must contain an uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $password)) {
            $error = "Password must contain a lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $password)) {
            $error = "Password must contain a number.";
        } elseif ($password !== $confirm) {
            $error = "Passwords do not match.";
        } else {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("SELECT email, expires_at, used FROM password_resets WHERE token = ?");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $stmt->bind_result($email, $expires_at, $used);
            if ($stmt->fetch() && !$used && strtotime($expires_at) > time()) {
                $stmt->close();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?");
                $stmt->bind_param("ss", $hash, $email);
                if ($stmt->execute()) {
                    $stmt->close();
                    $stmt = $conn->prepare("UPDATE password_resets SET used = TRUE WHERE token = ?");
                    $stmt->bind_param("s", $token);
                    $stmt->execute();
                    $stmt->close();

                    $success = "Password reset successfully! You can now login with your new password.";
                } else {
                    $error = "Failed to reset password. Please try again.";
                }
            } else {
                $error = "Invalid or expired reset link.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Enterprise Dorm Management</title>
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
        .form-group { position: relative; margin-bottom: 20px; }
        .password-toggle {
            position: absolute; right: 14px; top: 40px; background: none; border: none;
            color: var(--text-muted); cursor: pointer; font-size: 1rem;
        }
        .footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border-color);
        }
        .footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .hint { font-size: 0.8rem; color: var(--text-secondary); margin-top: 6px; }
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
                <h1><i class="fas fa-lock"></i> Reset Password</h1>
                <p>Choose a new password for your account</p>
            </div>
            <div class="body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <?php if ($token_valid && empty($success)): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($_GET['token']); ?>">

                    <div class="form-group">
                        <label for="email" class="form-label"><i class="fas fa-envelope"></i> Email</label>
                        <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" readonly style="cursor: not-allowed;">
                    </div>

                    <div class="form-group">
                        <label for="password" class="form-label"><i class="fas fa-lock"></i> New Password</label>
                        <input type="password" name="password" id="password" class="form-control" placeholder="Enter new password" required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePassword('password', this)"><i class="fas fa-eye"></i></button>
                        <div class="hint">At least 8 characters with uppercase, lowercase, and number</div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label"><i class="fas fa-lock"></i> Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" placeholder="Confirm new password" required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password', this)"><i class="fas fa-eye"></i></button>
                    </div>

                    <button type="submit" name="reset_password" class="btn btn-primary" style="width: 100%;"><i class="fas fa-save"></i> Reset Password</button>
                </form>
                <?php elseif (empty($success)): ?>
                    <p style="text-align: center; color: var(--text-secondary); padding: 20px 0;">
                        <i class="fas fa-info-circle"></i> Please use the link sent to your email.
                    </p>
                <?php endif; ?>

                <div class="footer">
                    <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
                </div>
            </div>
        </div>
    </div>

    <script>
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
    </script>
</body>
</html>
