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
    <title>Register - Dorm Management System</title>
    <meta name="description" content="Create a new account for Dorm Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --dark: #1d3557;
            --light: #f8f9fa;
            --danger: #e63946;
            --warning: #fca311;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            width: 100%;
            max-width: 500px;
        }
        
        .register-header {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .register-header h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
            font-weight: 600;
        }
        
        .register-header p {
            opacity: 0.9;
            font-size: 0.95rem;
        }
        
        .register-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--dark);
            font-size: 0.9rem;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: var(--light);
        }
        
        .form-control:focus {
            outline: none;
                border-color: var(--primary);
                box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
                background: white;
            }
            
            .password-toggle {
                position: absolute;
                right: 15px;
                top: 38px;
                background: none;
                border: none;
                color: #6c757d;
                cursor: pointer;
                z-index: 2;
            }
            
            .password-requirements {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
        }
        
        .password-strength {
            height: 4px;
            background: #e9ecef;
            border-radius: 2px;
            margin-top: 5px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: all 0.3s ease;
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
            background: var(--secondary);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(67, 97, 238, 0.3);
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        
        .alert-danger {
            background: #ffe6e6;
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background: #e6f7e6;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        
        .register-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
            color: #6c757d;
            font-size: 0.9rem;
        }
        
        .register-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .register-footer a:hover {
            text-decoration: underline;
        }
        
        .user-type-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-top: 10px;
        }
        
        .user-type-option {
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
        
        .user-type-option:hover {
            border-color: var(--primary);
            background: #f8f9ff;
        }
        
        .user-type-option.selected {
            border-color: var(--primary);
            background: #f0f4ff;
        }
        
        .user-type-option i {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: var(--primary);
        }
        
        @media (max-width: 480px) {
            .register-container {
                border-radius: 15px;
            }
            
            .register-header {
                padding: 25px 20px;
            }
            
            .register-body {
                padding: 25px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1><i class="fas fa-user-plus"></i> Create Account</h1>
            <p>Join Dorm Management System</p>
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