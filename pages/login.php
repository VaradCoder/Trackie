<?php
session_start();

// Include configuration and functions
require_once '../config/database.php';
require_once '../includes/functions.php';

// Check if user is already logged in
if (isLoggedIn()) {
    redirect('../dashboard.php');
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Remove CSRF validation
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Validate input
    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            // Get user from database
            $sql = "SELECT id, name, email, password, profile_pic FROM users WHERE email = ?";
            $user = fetchOne($sql, [$email]);

            if ($user && verifyPassword($password, $user['password'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['profile_pic'] = $user['profile_pic'];
                $_SESSION['login_time'] = time();

                // Set remember me cookie if requested
                if ($remember) {
                    $token = generateToken();
                    $expires = time() + (30 * 24 * 60 * 60); // 30 days
                    setcookie('remember_token', $token, $expires, '/', '', true, true);
                    
                    // Store token in database (you might want to add a remember_tokens table)
                    // For now, we'll just log the activity
                    logActivity('login_remember', ['user_id' => $user['id']]);
                }

                // Log successful login
                logActivity('login_success', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);

                // Set flash message
                setFlashMessage('success', 'Welcome back, ' . $user['name'] . '!');

                // Redirect to dashboard
                redirect('../dashboard.php');
            } else {
                $error = 'Invalid email or password.';
                logActivity('login_failed', [
                    'email' => $email,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
            }
        } catch (Exception $e) {
            $error = 'An error occurred. Please try again.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// Get flash message
$flash = getFlashMessage();
if ($flash) {
    if ($flash['type'] === 'success') {
        $success = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Trackie.in</title>
    <link rel="icon" type="image/x-icon" href="../assets/images/Logo.png">
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Bangers&family=Comic+Neue:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="font-comic">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Logo and Header -->
            <div class="text-center">
                <div class="flex justify-center">
                    <div class="bg-gold rounded-full p-4">
                        <i class="fa-solid fa-rocket text-black text-3xl"></i>
                    </div>
                </div>
                <h2 class="mt-6 text-4xl font-bangers text-gold">
                    Trackie.in
                </h2>
                <p class="mt-2 text-sm text-silver">
                    Track your habits, achieve your goals
                </p>
            </div>

            <!-- Login Form -->
            <div class="luxury-card p-8">
                <div class="text-center mb-8">
                    <h3 class="text-2xl font-bold text-white">Welcome back!</h3>
                    <p class="text-silver mt-2">Sign in to your account</p>
                </div>

                <!-- Error/Success Messages -->
                <?php if ($error): ?>
                    <div class="mb-4 bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded-lg flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="mb-4 bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded-lg flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-6">
                    <!-- Email Field -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-silver mb-2">
                            Email Address
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-silver"></i>
                            </div>
                            <input 
                                id="email" 
                                name="email" 
                                type="email" 
                                required 
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                                class="form-input block w-full pl-10 pr-3 py-3"
                                placeholder="Enter your email"
                            >
                        </div>
                    </div>

                    <!-- Password Field -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-silver mb-2">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-silver"></i>
                            </div>
                            <input 
                                id="password" 
                                name="password" 
                                type="password" 
                                required 
                                class="form-input block w-full pl-10 pr-10 py-3"
                                placeholder="Enter your password"
                            >
                            <button 
                                type="button" 
                                id="togglePassword"
                                class="absolute inset-y-0 right-0 pr-3 flex items-center"
                            >
                                <i class="fas fa-eye text-silver hover:text-gold"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Remember Me and Forgot Password -->
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <input 
                                id="remember" 
                                name="remember" 
                                type="checkbox" 
                                class="h-4 w-4 text-gold focus:ring-gold border-silver rounded"
                            >
                            <label for="remember" class="ml-2 block text-sm text-silver">
                                Remember me
                            </label>
                        </div>
                        <div class="text-sm">
                            <a href="forgot-password.php" class="font-medium text-gold hover:text-silver transition-colors">
                                Forgot password?
                            </a>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div>
                        <button 
                            type="submit" 
                            class="btn-primary w-full"
                        >
                            <span class="absolute left-0 inset-y-0 flex items-center pl-3">
                                <i class="fas fa-sign-in-alt text-black"></i>
                            </span>
                            Sign in
                        </button>
                    </div>
                </form>

                <!-- Sign Up Link -->
                <div class="mt-6 text-center">
                    <p class="text-sm text-silver">
                        Don't have an account? 
                        <a href="register.php" class="font-medium text-gold hover:text-silver transition-colors">
                            Sign up here
                        </a>
                    </p>
                </div>
            </div>

            <!-- Footer -->
            <div class="text-center">
                <p class="text-xs text-silver">
                    By signing in, you agree to our 
                    <a href="#" class="text-gold hover:text-silver">Terms of Service</a> 
                    and 
                    <a href="#" class="text-gold hover:text-silver">Privacy Policy</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!email || !password) {
                e.preventDefault();
                alert('Please fill in all fields.');
                return false;
            }
            
            // Basic email validation
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                alert('Please enter a valid email address.');
                return false;
            }
        });

        // Auto-focus on email field
        document.getElementById('email').focus();
    </script>
</body>
</html> 