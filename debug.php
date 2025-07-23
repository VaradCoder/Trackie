<?php
// Simple debug script to identify 500 error
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Debug Test</h1>";

// Test 1: Basic PHP
echo "<h2>1. Basic PHP Test</h2>";
echo "✅ PHP is working<br>";
echo "PHP Version: " . phpversion() . "<br>";

// Test 2: File includes
echo "<h2>2. File Include Test</h2>";
try {
    if (file_exists('config/database.php')) {
        echo "✅ config/database.php exists<br>";
        require_once 'config/database.php';
        echo "✅ config/database.php loaded successfully<br>";
    } else {
        echo "❌ config/database.php not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error loading config/database.php: " . $e->getMessage() . "<br>";
}

// Test 3: Database connection
echo "<h2>3. Database Connection Test</h2>";
try {
    if (function_exists('getDBConnection')) {
        $pdo = getDBConnection();
        echo "✅ Database connection successful<br>";
    } else {
        echo "❌ getDBConnection function not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
}

// Test 4: Functions file
echo "<h2>4. Functions File Test</h2>";
try {
    if (file_exists('includes/functions.php')) {
        echo "✅ includes/functions.php exists<br>";
        require_once 'includes/functions.php';
        echo "✅ includes/functions.php loaded successfully<br>";
    } else {
        echo "❌ includes/functions.php not found<br>";
    }
} catch (Exception $e) {
    echo "❌ Error loading includes/functions.php: " . $e->getMessage() . "<br>";
}

// Test 5: Session
echo "<h2>5. Session Test</h2>";
try {
    session_start();
    echo "✅ Session started successfully<br>";
} catch (Exception $e) {
    echo "❌ Session error: " . $e->getMessage() . "<br>";
}

echo "<h2>Debug Complete!</h2>";
?> 