<?php
// Run this script once to fix the routines table
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    // Check if the time_of_day column exists
    $result = $pdo->query("SHOW COLUMNS FROM routines LIKE 'time_of_day'");
    if ($result->rowCount() === 0) {
        $pdo->exec("ALTER TABLE routines ADD COLUMN time_of_day VARCHAR(32) DEFAULT 'morning'");
        echo "✅ 'time_of_day' column added to routines table.<br>";
    } else {
        echo "'time_of_day' column already exists in routines table.<br>";
    }
    // Check if the routine_name column exists
    $result2 = $pdo->query("SHOW COLUMNS FROM routines LIKE 'routine_name'");
    if ($result2->rowCount() === 0) {
        $pdo->exec("ALTER TABLE routines ADD COLUMN routine_name VARCHAR(255) NOT NULL AFTER id");
        echo "✅ 'routine_name' column added to routines table.<br>";
    } else {
        echo "'routine_name' column already exists in routines table.<br>";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?> 