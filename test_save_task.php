<?php
session_start();

// Include configuration and functions
require_once 'config/database.php';
require_once 'includes/functions.php';

// Configuration
$api_url = 'http://localhost/api/save_task.php';

// Check if user is logged in
if (!isLoggedIn()) {
    echo "Error: User not logged in. Please log in first.\n";
    exit();
}

// Task data to send - you can modify these values for testing
$data = [
    'title' => 'Test Task from PHP',
    'time_slot' => '09:00:00',
    'task_date' => date('Y-m-d'), // Use current date
    'category' => 'Fitness',
    'description' => 'Test description from PHP script'
];

// Function to send task data
function sendTaskData($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'response' => $response,
        'http_code' => $http_code,
        'error' => $error
    ];
}

// Execute request
echo "Sending task data to API...\n";
echo "Data: " . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

$result = sendTaskData($api_url, $data);

if ($result['error']) {
    echo "cURL Error: " . $result['error'] . "\n";
} else {
    echo "HTTP Status Code: " . $result['http_code'] . "\n";
    echo "Response: " . $result['response'] . "\n";
    
    // Try to decode JSON response
    $decoded_response = json_decode($result['response'], true);
    if ($decoded_response) {
        echo "\nDecoded Response:\n";
        print_r($decoded_response);
    }
}

// Log the test activity
logActivity('test_save_task', [
    'api_url' => $api_url,
    'data_sent' => $data,
    'response' => $result
]);
?>
