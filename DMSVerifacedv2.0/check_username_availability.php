<?php
require_once 'db_connect.php'; // Your database connection file

header('Content-Type: application/json'); // Respond with JSON

$username = trim($_GET['username'] ?? ''); // Get username from GET request

$response = ['available' => false]; // Default to not available

if (empty($username)) {
    echo json_encode($response);
    exit();
}

if (isset($conn) && $conn instanceof mysqli) {
    // Check if username exists in the addedmembers table
    $stmt = $conn->prepare("SELECT COUNT(*) FROM addedmembers WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count == 0) {
            $response['available'] = true; // Username is available
        }
    }
    $conn->close();
}

echo json_encode($response);
?>