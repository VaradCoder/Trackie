<?php
// Simple index test
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Trackie.in - Index Test</h1>";
echo "<p>If you can see this, PHP is working correctly.</p>";

// Test basic functionality
echo "<h2>Quick Tests:</h2>";
echo "<ul>";
echo "<li><a href='debug.php'>Debug Test</a></li>";
echo "<li><a href='setup_database.php'>Setup Database</a></li>";
echo "<li><a href='test_connection.php'>Connection Test</a></li>";
echo "<li><a href='pages/login.php'>Login Page</a></li>";
echo "</ul>";

echo "<h2>Next Steps:</h2>";
echo "<ol>";
echo "<li>First, run the <a href='setup_database.php'>Database Setup</a></li>";
echo "<li>Then run the <a href='debug.php'>Debug Test</a></li>";
echo "<li>Finally, try the <a href='pages/login.php'>Login Page</a></li>";
echo "</ol>";
?> 