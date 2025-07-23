<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS study_plan (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        subject VARCHAR(100),
        due_date DATE,
        type ENUM('study', 'homework', 'practice', 'project', 'exam', 'reading', 'revision', 'other') DEFAULT 'study',
        priority ENUM('high', 'medium', 'low') DEFAULT 'medium',
        resource VARCHAR(255),
        completed TINYINT(1) DEFAULT 0,
        completed_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ study_plan table created or already exists.<br>";

    // Add missing columns if needed
    $columns = [
        'subject' => "ALTER TABLE study_plan ADD COLUMN subject VARCHAR(100) AFTER description",
        'priority' => "ALTER TABLE study_plan ADD COLUMN priority ENUM('high','medium','low') DEFAULT 'medium' AFTER type",
        'resource' => "ALTER TABLE study_plan ADD COLUMN resource VARCHAR(255) AFTER priority"
    ];
    foreach ($columns as $col => $sql) {
        $result = $pdo->query("SHOW COLUMNS FROM study_plan LIKE '$col'");
        if ($result->rowCount() === 0) {
            $pdo->exec($sql);
            echo "✅ '$col' column added.<br>";
        }
    }

    // Update ENUM for type if needed
    $typeCol = $pdo->query("SHOW COLUMNS FROM study_plan LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
    if ($typeCol && strpos($typeCol['Type'], "project") === false) {
        $pdo->exec("ALTER TABLE study_plan MODIFY COLUMN type ENUM('study', 'homework', 'practice', 'project', 'exam', 'reading', 'revision', 'other') DEFAULT 'study'");
        echo "✅ 'type' ENUM updated.<br>";
    }
    echo "All done!";
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?> 