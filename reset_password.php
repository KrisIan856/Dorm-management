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
    <title>Reset Password - Dorm Management</title>
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
        .form-group { margin-bottom: 20px; position: relative; }
        .form-label { display: block; margin-bottom: 8px; font-weight: 500; color: var(--dark); }
        .form-control {
            width: 100%; padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 10px;
            font-size: 1rem; transition: all 0.3s; background: var(--light); padding-right: 45px;
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(67,97,238,0.1); background: white; }
        .password-toggle {
            position: absolute; right: 12px; top: 38px; background: none; border: none;
            color: #6c757d; cursor: pointer; z-index: 2;
        }
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
        .hint { font-size: 0.85rem; color: #6c757d; margin-top: 5px; }
        @media (max-width: 480px) { .header { padding: 25px 20px; } .body { padding: 25px 20px; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
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
                    <input type="email" id="email" class="form-control" value="<?php echo htmlspecialchars($email); ?>" readonly style="background: #f5f5f5; cursor: not-allowed;">
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

                <button type="submit" name="reset_password" class="btn"><i class="fas fa-save"></i> Reset Password</button>
            </form>
            <?php elseif (empty($success)): ?>
                <p style="text-align: center; color: #6c757d; padding: 20px 0;">
                    <i class="fas fa-info-circle"></i> Please use the link sent to your email.
                </p>
            <?php endif; ?>

            <div class="footer">
                <a href="login.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
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
