<?php
/**
 * Test Connection Script
 * Verifies database connection and basic functionality
 */

// Include configuration
require_once 'config/database.php';
require_once 'includes/functions.php';

echo "<h1>Trackie.in - Connection Test</h1>";

// Test 1: Database Connection
echo "<h2>1. Database Connection Test</h2>";
try {
    $pdo = getDBConnection();
    echo "✅ Database connection successful<br>";
    echo "Database: " . DB_NAME . "<br>";
    echo "Host: " . DB_HOST . "<br>";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

// Test 2: Check Tables
echo "<h2>2. Database Tables Test</h2>";
$tables = ['users', 'habits', 'logs', 'routines', 'todos', 'goals'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Table '$table' exists<br>";
        } else {
            echo "❌ Table '$table' missing<br>";
        }
    } catch (Exception $e) {
        echo "❌ Error checking table '$table': " . $e->getMessage() . "<br>";
    }
}

// Test 3: Functions Test
echo "<h2>3. Functions Test</h2>";
$test_email = "test@example.com";
$test_password = "test123";

echo "Email validation: " . (validateEmail($test_email) ? "✅" : "❌") . " Valid email<br>";
echo "Password hashing: " . (hashPassword($test_password) ? "✅" : "❌") . " Password hashed<br>";
echo "Token generation: " . (generateToken() ? "✅" : "❌") . " Token generated<br>";

// Test 4: File System Test
echo "<h2>4. File System Test</h2>";
$required_dirs = ['assets', 'assets/css', 'assets/js', 'assets/images', 'config', 'includes', 'pages', 'logs'];
foreach ($required_dirs as $dir) {
    if (is_dir($dir)) {
        echo "✅ Directory '$dir' exists<br>";
    } else {
        echo "❌ Directory '$dir' missing<br>";
    }
}

$required_files = [
    'assets/css/style.css',
    'assets/js/app.js',
    'assets/images/Logo.png',
    'assets/images/default-user.png',
    'config/database.php',
    'includes/functions.php',
    'includes/header.php',
    'includes/footer.php',
    'includes/sidebar.php',
    'includes/navbar.php'
];

foreach ($required_files as $file) {
    if (file_exists($file)) {
        echo "✅ File '$file' exists<br>";
    } else {
        echo "❌ File '$file' missing<br>";
    }
}

// Test 5: PHP Extensions
echo "<h2>5. PHP Extensions Test</h2>";
$required_extensions = ['pdo', 'pdo_mysql', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    if (extension_loaded($ext)) {
        echo "✅ Extension '$ext' loaded<br>";
    } else {
        echo "❌ Extension '$ext' not loaded<br>";
    }
}

// Test 6: Server Information
echo "<h2>6. Server Information</h2>";
echo "PHP Version: " . phpversion() . "<br>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] ?? 'Unknown' . "<br>";

// Test 7: Sample Data Insertion
echo "<h2>7. Sample Data Test</h2>";
try {
    // Check if users table is empty
    $user_count = fetchOne("SELECT COUNT(*) as count FROM users");
    if ($user_count['count'] == 0) {
        echo "📝 Users table is empty. You can register a new user.<br>";
    } else {
        echo "✅ Users table has " . $user_count['count'] . " user(s)<br>";
    }
} catch (Exception $e) {
    echo "❌ Error checking users table: " . $e->getMessage() . "<br>";
}

echo "<h2>Test Complete!</h2>";
echo "<p>If all tests pass, your Trackie.in application is ready to use.</p>";
echo "<p><a href='index.php'>Go to Application</a></p>";
?> 