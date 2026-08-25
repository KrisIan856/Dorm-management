<?php
// register.php - Enhanced Version
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
$success = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Collect and sanitize form data
        $form_data = [
            'email' => sanitizeInput($_POST['email'], 'email'),
            'password' => $_POST['password'],
            'confirm_password' => $_POST['confirm_password'],
            'user_type' => sanitizeInput($_POST['user_type']),
            'full_name' => sanitizeInput($_POST['full_name']),
            'phone' => sanitizeInput($_POST['phone'])
        ];
        
        // Validate inputs
        if (empty($form_data['email']) || empty($form_data['password']) || 
            empty($form_data['full_name']) || empty($form_data['user_type'])) {
            $error = "All required fields must be filled.";
        } elseif (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (strlen($form_data['password']) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $form_data['password'])) {
            $error = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $form_data['password'])) {
            $error = "Password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $form_data['password'])) {
            $error = "Password must contain at least one number.";
        } elseif ($form_data['password'] !== $form_data['confirm_password']) {
            $error = "Passwords do not match.";
        } elseif (!in_array($form_data['user_type'], ['dormdean', 'dormdean_assistant', 'occupant'])) {
            $error = "Please select a valid user type.";
        } else {
            // Check if email already exists (in both users and pending approvals)
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE email = ? 
                                          UNION 
                                          SELECT id FROM registration_approvals WHERE email = ? AND status = 'pending'");
            $check_stmt->bind_param("ss", $form_data['email'], $form_data['email']);
            $check_stmt->execute();
            $check_stmt->store_result();

            if ($check_stmt->num_rows > 0) {
                $error = "Email already registered or pending approval!";
            } else {
                // Hash password
                $password_hash = password_hash($form_data['password'], PASSWORD_DEFAULT);
                
                // Check if approval is required
                $requires_approval = in_array($form_data['user_type'], ['dormdean', 'dormdean_assistant']);
                
                if (!$requires_approval) {
                    // For occupants, insert directly
                    $stmt = $conn->prepare("INSERT INTO users (email, password, user_type, full_name, phone, is_active) VALUES (?, ?, ?, ?, ?, TRUE)");
                    $stmt->bind_param("sssss", $form_data['email'], $password_hash, $form_data['user_type'], $form_data['full_name'], $form_data['phone']);
                    
                    if ($stmt->execute()) {
                        error_log("New occupant registered: " . $form_data['email']);
                        
                        // Store success message in session for login page
                        $_SESSION['registration_success'] = "Registration successful! You can now login.";
                        
                        // Redirect to login page
                        header("Location: login.php");
                        exit();
                    } else {
                        $error = "Registration failed. Please try again.";
                        error_log("Registration failed: " . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    // For dormdean and assistant, insert into pending approvals
                    $stmt = $conn->prepare("INSERT INTO registration_approvals (email, password, user_type, full_name, phone, status) VALUES (?, ?, ?, ?, ?, 'pending')");
                    $stmt->bind_param("sssss", $form_data['email'], $password_hash, $form_data['user_type'], $form_data['full_name'], $form_data['phone']);
                    
                    if ($stmt->execute()) {
                        // Notify existing dorm deans
                        notifyDormDeansAboutRegistration($form_data);
                        
                        error_log("Pending registration: " . $form_data['email'] . " (" . $form_data['user_type'] . ")");
                        
                        // Store pending message in session for login page
                        $_SESSION['registration_pending'] = "Registration submitted for approval! A dorm dean will review your application. You'll receive an email once approved.";
                        
                        // Redirect to login page
                        header("Location: login.php");
                        exit();
                    } else {
                        $error = "Registration submission failed. Please try again.";
                        error_log("Pending registration failed: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
            $check_stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Enterprise Dorm Management</title>
    <meta name="description" content="Create a new account for Dorm Management System">
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
            padding: 24px 20px;
            position: relative;
        }
        .register-card-wrapper {
            width: 100%;
            max-width: 520px;
            position: relative;
            z-index: 10;
        }
        .register-container {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
        }
        .register-header {
            background: var(--brand-gradient);
            color: white;
            padding: 32px 28px;
            text-align: center;
            position: relative;
        }
        .register-header-theme-toggle {
            position: absolute;
            top: 16px;
            right: 16px;
        }
        .register-header .logo-badge {
            width: 52px;
            height: 52px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        .register-header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .register-body {
            padding: 30px;
        }
        .user-type-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        .user-type-option {
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 14px;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: center;
            background: var(--input-bg);
            color: var(--text-primary);
        }
        .user-type-option:hover, .user-type-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary);
        }
        .register-footer {
            text-align: center;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid var(--border-color);
            color: var(--text-secondary);
            font-size: 0.88rem;
        }
        .register-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="register-card-wrapper">
        <div class="register-container">
            <div class="register-header">
                <div class="register-header-theme-toggle">
                    <button class="theme-toggle-btn" title="Toggle Light/Dark Theme">
                        <i class="fas fa-moon"></i>
                    </button>
                </div>
                <div class="logo-badge">
                    <i class="fas fa-user-plus"></i>
                </div>
                <h1>Create Account</h1>
                <p>Join Enterprise Dorm Management System</p>
            </div>
            
            <div class="register-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="form-group">
                    <label for="full_name" class="form-label">
                        <i class="fas fa-user"></i> Full Name *
                    </label>
                    <input type="text" name="full_name" id="full_name" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['full_name'] ?? ''); ?>" 
                           placeholder="Enter your full name" required>
                </div>
                
                <div class="form-group">
                    <label for="email" class="form-label">
                        <i class="fas fa-envelope"></i> Email Address *
                    </label>
                    <input type="email" name="email" id="email" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" 
                           placeholder="Enter your email" required>
                </div>
                
                <div class="form-group">
                    <label for="phone" class="form-label">
                        <i class="fas fa-phone"></i> Phone Number
                    </label>
                    <input type="tel" name="phone" id="phone" class="form-control" 
                           value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>" 
                           placeholder="Enter your phone number">
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-users"></i> User Type *
                    </label>
                    <div class="user-type-options">
                        <div class="user-type-option" data-value="occupant">
                            <i class="fas fa-user"></i>
                            <div><strong>Occupant</strong></div>
                            <small>Dorm resident</small>
                        </div>
                        <div class="user-type-option" data-value="dormdean_assistant">
                            <i class="fas fa-user-shield"></i>
                            <div><strong>Assistant</strong></div>
                            <small>Dorm assistant</small>
                        </div>
                        <div class="user-type-option" data-value="dormdean">
                            <i class="fas fa-user-tie"></i>
                            <div><strong>Dorm Dean</strong></div>
                            <small>Administrator</small>
                        </div>
                    </div>
                    <input type="hidden" name="user_type" id="user_type" required>
                </div>
                
                <div class="form-group" style="position: relative;">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock"></i> Password *
                    </label>
                    <input type="password" name="password" id="password" class="form-control" 
                           placeholder="Create a password" required minlength="8">
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('password', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                    <div class="password-requirements">
                        Must be at least 8 characters with uppercase, lowercase, and number
                    </div>
                    <div class="password-strength">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                </div>
                
                <div class="form-group" style="position: relative;">
                    <label for="confirm_password" class="form-label">
                        <i class="fas fa-lock"></i> Confirm Password *
                    </label>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                           placeholder="Confirm your password" required>
                    <button type="button" class="password-toggle" onclick="togglePasswordVisibility('confirm_password', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <button type="submit" class="btn" id="submitBtn">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>
            
            <div class="register-footer">
                Already have an account? <a href="login.php">Login here</a>
            </div>
        </div>
    </div>
</div>

    <script>
        // User type selection
        document.querySelectorAll('.user-type-option').forEach(option => {
            option.addEventListener('click', function() {
                document.querySelectorAll('.user-type-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                this.classList.add('selected');
                document.getElementById('user_type').value = this.dataset.value;
            });
        });

        // Password toggle visibility
        function togglePasswordVisibility(inputId, btn) {
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

        // Password strength indicator
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const strengthBar = document.getElementById('passwordStrengthBar');
            let strength = 0;
            
            if (password.length >= 8) strength += 25;
            if (/[A-Z]/.test(password)) strength += 25;
            if (/[a-z]/.test(password)) strength += 25;
            if (/[0-9]/.test(password)) strength += 25;
            
            strengthBar.style.width = strength + '%';
            
            if (strength < 50) {
                strengthBar.style.background = '#e63946';
            } else if (strength < 75) {
                strengthBar.style.background = '#fca311';
            } else {
                strengthBar.style.background = '#4cc9f0';
            }
        });

        // Form validation
        document.getElementById('registerForm').addEventListener('submit', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const userType = document.getElementById('user_type').value;
            
            if (!userType) {
                e.preventDefault();
                alert('Please select a user type.');
                return false;
            }
            
            if (password !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match.');
                return false;
            }
            
            // Password strength validation
            if (password.length < 8 || !/[A-Z]/.test(password) || 
                !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
                e.preventDefault();
                alert('Password does not meet the requirements.');
                return false;
            }
        });

        // Real-time password match indicator
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (confirmPassword && password !== confirmPassword) {
                this.style.borderColor = '#e63946';
            } else if (confirmPassword) {
                this.style.borderColor = '#4cc9f0';
            } else {
                this.style.borderColor = '#e9ecef';
            }
        });
    </script>
</body>
</html>