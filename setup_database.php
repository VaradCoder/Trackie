<?php
// Database Setup Script for Trackie.in
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Trackie.in - Database Setup</h1>";

// Database configuration
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'trackie';

try {
    // Connect to MySQL without selecting a database
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected to MySQL server<br>";
    
    // Check if database exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '$dbname'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Database '$dbname' already exists<br>";
    } else {
        // Create database
        $pdo->exec("CREATE DATABASE `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "✅ Database '$dbname' created successfully<br>";
    }
    
    // Connect to the specific database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected to database '$dbname'<br>";
    
    // Read and execute SQL file
    if (file_exists('trackie_in.sql')) {
        echo "✅ Found trackie_in.sql file<br>";
        
        $sql = file_get_contents('trackie_in.sql');
        
        // Split SQL into individual statements
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                    echo "✅ Executed SQL statement<br>";
                } catch (PDOException $e) {
                    // Ignore "table already exists" errors
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "⚠️ SQL statement warning: " . $e->getMessage() . "<br>";
                    }
                }
            }
        }
        
        echo "✅ Database schema imported successfully<br>";
        
        // Verify tables were created
        $tables = ['users', 'habits', 'logs', 'routines', 'todos', 'goals'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                echo "✅ Table '$table' exists<br>";
            } else {
                echo "❌ Table '$table' missing<br>";
            }
        }
        
    } else {
        echo "❌ trackie_in.sql file not found<br>";
    }
    
    echo "<h2>Database Setup Complete!</h2>";
    echo "<p><a href='index.php'>Go to Application</a></p>";
    echo "<p><a href='debug.php'>Run Debug Test</a></p>";
    
} catch (PDOException $e) {
    echo "❌ Database Error: " . $e->getMessage() . "<br>";
    echo "<p>Please make sure:</p>";
    echo "<ul>";
    echo "<li>XAMPP is running</li>";
    echo "<li>MySQL service is started</li>";
    echo "<li>Database credentials are correct</li>";
    echo "</ul>";
}
?> 