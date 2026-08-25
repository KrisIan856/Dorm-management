<?php
// login.php - Enhanced Version with Specific Error Messages
include 'config.php';

// Redirect if already logged in
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
$email = '';
$field_error = array('email' => '', 'password' => '');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $db = new Database();
        $conn = $db->getConnection();
        
        $email = sanitizeInput($_POST['email'] ?? '', 'email');
        $password = $_POST['password'] ?? '';
        $remember_me = isset($_POST['remember_me']);
        
        // Input validation
        $valid = true;
        
        if (empty($email)) {
            $field_error['email'] = "Email is required.";
            $valid = false;
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $field_error['email'] = "Please enter a valid email address.";
            $valid = false;
        }
        
        if (empty($password)) {
            $field_error['password'] = "Password is required.";
            $valid = false;
        }
        
        if ($valid) {
            // Check user credentials with prepared statement
            $stmt = $conn->prepare("SELECT id, email, password, user_type, full_name, is_active FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();
            
            if ($stmt->num_rows > 0) {
                $stmt->bind_result($user_id, $db_email, $db_password, $user_type, $full_name, $is_active);
                $stmt->fetch();
                
                if (!$is_active) {
                    $error = "Your account is inactive. Please contact the administrator.";
                } elseif (password_verify($password, $db_password)) {
                    // Enhanced session security
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['email'] = $db_email;
                    $_SESSION['user_type'] = $user_type;
                    $_SESSION['full_name'] = $full_name;
                    $_SESSION['logged_in'] = true;
                    $_SESSION['created_at'] = time();
                    $_SESSION['last_activity'] = time();
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    
                    // Generate secure session token
                    $session_token = bin2hex(random_bytes(32));
                    $_SESSION['session_token'] = $session_token;
                    
                    // Log login session with user agent
                    $ip_address = $_SERVER['REMOTE_ADDR'];
                    $user_agent = $_SERVER['HTTP_USER_AGENT'];
                    $log_stmt = $conn->prepare("INSERT INTO login_sessions (user_id, session_token, ip_address, user_agent) VALUES (?, ?, ?, ?)");
                    $log_stmt->bind_param("isss", $user_id, $session_token, $ip_address, $user_agent);
                    $log_stmt->execute();
                    $log_stmt->close();
                    
                    // Update last login
                    $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                    $update_stmt->bind_param("i", $user_id);
                    $update_stmt->execute();
                    $update_stmt->close();
                    
                    // Redirect based on user type
                    $redirect = match($user_type) {
                        'dormdean' => 'dormdean_dashboard.php',
                        'dormdean_assistant' => 'assistant_dashboard.php',
                        default => 'occupant_dashboard.php'
                    };
                    
                    header("Location: $redirect");
                    exit();
                } else {
                    // Password is incorrect but email exists
                    $error = "Invalid password. Please try again.";
                    // Consider adding a delay here to prevent brute force attacks
                    usleep(500000); // 0.5 second delay
                }
            } else {
                // Email not found (but don't reveal this to prevent user enumeration)
                // Use a generic message but log the attempt
                $error = "The email address and password you entered do not match our records.";
                
                // Simulate password verification to prevent timing attacks
                password_verify($password, '$2y$10$' . str_repeat('0', 53));
                usleep(500000); // 0.5 second delay
            }
            
            $stmt->close();
            
            // Log failed login attempt
            error_log("Failed login attempt for email: $email from IP: " . $_SERVER['REMOTE_ADDR']);
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Enterprise Dorm Management</title>
    <meta name="description" content="Secure login to Dorm Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
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
            position: relative;
        }
        body::before {
            content: '';
            position: absolute;
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(79,70,229,0.35) 0%, rgba(0,0,0,0) 70%);
            top: 10%;
            left: 15%;
            pointer-events: none;
        }
        body::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(6,182,212,0.3) 0%, rgba(0,0,0,0) 70%);
            bottom: 10%;
            right: 15%;
            pointer-events: none;
        }
        .login-card-wrapper {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 10;
        }
        .login-container {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .login-header {
            background: var(--brand-gradient);
            color: white;
            padding: 36px 30px;
            text-align: center;
            position: relative;
        }
        .login-header-theme-toggle {
            position: absolute;
            top: 16px;
            right: 16px;
        }
        .login-header .logo-badge {
            width: 56px;
            height: 56px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            margin-bottom: 12px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        }
        .login-header h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .login-header p {
            opacity: 0.9;
            font-size: 0.88rem;
            font-weight: 500;
        }
        .login-body {
            padding: 32px 30px;
        }
        .password-group {
            position: relative;
        }
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 40px;
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            font-size: 1rem;
        }
        .login-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.88rem;
        }
        .login-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .login-footer a:hover {
            text-decoration: underline;
        }
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: 0.88rem;
            color: var(--text-secondary);
        }
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        .form-group {
            margin-bottom: 20px;
            position: relative;
        }
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.9rem;
        }
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--input-bg);
            color: var(--text-primary);
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
            background: var(--bg-surface);
        }
        /* Style for invalid fields */
        .form-control.is-invalid {
            border-color: var(--danger);
            background-color: var(--danger-light);
        }
        .form-control.is-invalid:focus {
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .invalid-feedback {
            color: var(--danger);
            font-size: 0.85rem;
            margin-top: 5px;
            display: block;
        }
        .btn {
            width: 100%;
            padding: 14px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .btn:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(79, 70, 229, 0.3);
        }
        .btn:active {
            transform: translateY(0);
        }
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }
        .alert-danger {
            background: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        .alert-success {
            background: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        .alert-warning {
            background: var(--warning-light);
            color: var(--warning);
            border-left: 4px solid var(--warning);
        }
    </style>
</head>
<body>
    <div class="login-card-wrapper">
        <div class="login-container">
            <div class="login-header">
                <div class="login-header-theme-toggle">
                    <button class="theme-toggle-btn" title="Toggle Light/Dark Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
                <div class="logo-badge">
                    <i class="fas fa-city"></i>
                </div>
                <h1>Dorm Management</h1>
                <p>Enterprise Campus & Occupant Portal</p>
            </div>

            <div class="login-body">
            <?php if (isset($_GET['success']) && $_GET['success'] == 'registered'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> Registration successful! Please login.
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error']) && $_GET['error'] == 'session_expired'): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Session expired. Please login again.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope"></i> Email Address
                    </label>
                    <input type="email" name="email" id="email" class="form-control <?php echo !empty($field_error['email']) ? 'is-invalid' : ''; ?>" 
                           value="<?php echo htmlspecialchars($email); ?>" 
                           placeholder="Enter your email" required autofocus>
                    <?php if (!empty($field_error['email'])): ?>
                        <span class="invalid-feedback">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($field_error['email']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <input type="password" name="password" id="password" class="form-control <?php echo !empty($field_error['password']) ? 'is-invalid' : ''; ?>" 
                           placeholder="Enter your password" required>
                    <button type="button" class="password-toggle" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                    <?php if (!empty($field_error['password'])): ?>
                        <span class="invalid-feedback">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($field_error['password']); ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="remember-me">
                    <input type="checkbox" id="remember_me" name="remember_me">
                    <label for="remember_me">Remember me</label>
                </div>
                
                <button type="submit" class="btn">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
            </form>
            
            <div class="login-footer">
                Don't have an account? <a href="register.php">Register here</a>
                <br>
                <small><a href="forgot_password.php" style="color: #6c757d;">Forgot password?</a></small>
        </div>
    </div>
</div>

    <script>
        // Password visibility toggle
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        // Clear field errors when user starts typing
        document.getElementById('email').addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                this.classList.remove('is-invalid');
                const feedback = this.nextElementSibling;
                if (feedback && feedback.classList.contains('invalid-feedback')) {
                    feedback.style.display = 'none';
                }
            }
        });

        document.getElementById('password').addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                this.classList.remove('is-invalid');
                const feedback = this.parentNode.querySelector('.invalid-feedback');
                if (feedback) {
                    feedback.style.display = 'none';
                }
            }
        });

        // Form validation
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            let hasError = false;
            
            // Clear previous field errors
            document.querySelectorAll('.is-invalid').forEach(el => {
                el.classList.remove('is-invalid');
            });
            document.querySelectorAll('.invalid-feedback').forEach(el => {
                el.style.display = 'none';
            });
            
            // Validate email
            if (!email) {
                const emailField = document.getElementById('email');
                emailField.classList.add('is-invalid');
                let feedback = emailField.nextElementSibling;
                if (!feedback || !feedback.classList.contains('invalid-feedback')) {
                    feedback = document.createElement('span');
                    feedback.className = 'invalid-feedback';
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Email is required.';
                    emailField.parentNode.appendChild(feedback);
                } else {
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Email is required.';
                    feedback.style.display = 'block';
                }
                hasError = true;
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                const emailField = document.getElementById('email');
                emailField.classList.add('is-invalid');
                let feedback = emailField.nextElementSibling;
                if (!feedback || !feedback.classList.contains('invalid-feedback')) {
                    feedback = document.createElement('span');
                    feedback.className = 'invalid-feedback';
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a valid email address.';
                    emailField.parentNode.appendChild(feedback);
                } else {
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a valid email address.';
                    feedback.style.display = 'block';
                }
                hasError = true;
            }
            
            // Validate password
            if (!password) {
                const passwordField = document.getElementById('password');
                passwordField.classList.add('is-invalid');
                let feedback = passwordField.parentNode.querySelector('.invalid-feedback');
                if (!feedback) {
                    feedback = document.createElement('span');
                    feedback.className = 'invalid-feedback';
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password is required.';
                    passwordField.parentNode.appendChild(feedback);
                } else {
                    feedback.innerHTML = '<i class="fas fa-exclamation-circle"></i> Password is required.';
                    feedback.style.display = 'block';
                }
                hasError = true;
            }
            
            if (hasError) {
                e.preventDefault();
                // Scroll to first error
                const firstError = document.querySelector('.is-invalid');
                if (firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus();
                }
                return false;
            }
        });

        // Clear form on back/forward navigation
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                document.getElementById('loginForm').reset();
            }
        });

        // Prevent form caching
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Auto-focus on email field if empty
        document.addEventListener('DOMContentLoaded', function() {
            const emailField = document.getElementById('email');
            if (!emailField.value) {
                emailField.focus();
            }
        });
    </script>
</body>
</html>