<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Trackie.in - Functionality Test</h1>";

// Test 1: Database Connection
echo "<h2>1. Database Connection Test</h2>";
try {
    require_once 'config/database.php';
    $pdo = getDBConnection();
    echo "✅ Database connection successful<br>";
    
    // Test tables
    $tables = ['users', 'todos', 'habits', 'logs', 'routines', 'goals'];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists<br>";
        } else {
            echo "❌ Table '$table' missing<br>";
        }
    }
} catch (Exception $e) {
    echo "❌ Database error: " . $e->getMessage() . "<br>";
}

// Test 2: Functions
echo "<h2>2. Functions Test</h2>";
try {
    require_once 'includes/functions.php';
    echo "✅ Functions loaded successfully<br>";
    
    // Test sanitize function
    $test_input = "<script>alert('test')</script>";
    $sanitized = sanitizeInput($test_input);
    if ($sanitized !== $test_input) {
        echo "✅ Input sanitization working<br>";
    } else {
        echo "❌ Input sanitization not working<br>";
    }
} catch (Exception $e) {
    echo "❌ Functions error: " . $e->getMessage() . "<br>";
}

// Test 3: Session
echo "<h2>3. Session Test</h2>";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "✅ Session is active<br>";
} else {
    echo "❌ Session not active<br>";
}

// Test 4: File Structure
echo "<h2>4. File Structure Test</h2>";
$required_files = [
    'config/database.php',
    'includes/functions.php',
    'includes/sidebar.php',
    'includes/header.php',
    'includes/footer.php',
    'assets/css/style.css',
    'assets/js/app.js',
    'pages/login.php',
    'pages/register.php',
    'pages/todo_manager.php',
    'pages/habits_simple.php',
    'pages/goals_simple.php',
    'pages/routine_simple.php',
    'pages/analytics_simple.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "✅ $file exists<br>";
    } else {
        echo "❌ $file missing<br>";
    }
}

// Test 5: CSS Classes
echo "<h2>5. CSS Classes Test</h2>";
if (file_exists('assets/css/style.css')) {
    $css_content = file_get_contents('assets/css/style.css');
    $required_classes = ['luxury-card', 'btn-primary', 'glassmorphism', 'text-gold'];
    foreach ($required_classes as $class) {
        if (strpos($css_content, $class) !== false) {
            echo "✅ CSS class '$class' found<br>";
        } else {
            echo "❌ CSS class '$class' missing<br>";
        }
    }
} else {
    echo "❌ CSS file not found<br>";
}

echo "<h2>Test Complete!</h2>";
echo "<p><a href='index.php'>Go to Application</a></p>";
echo "<p><a href='setup_database.php'>Setup Database</a></p>";
?> 