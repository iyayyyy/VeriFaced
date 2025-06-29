<?php
// Database credentials
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root'); // Default XAMPP MySQL username
define('DB_PASSWORD', '');     // Default XAMPP MySQL root password (empty string)
define('DB_NAME', 'dmsverifaced_db'); // Your actual database name

// Attempt to connect to MySQL database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_NAME);

// Check connection
if ($conn->connect_error) {
    // For development: display error (remove or refine for production)
    // ini_set('display_errors', 1);
    // ini_set('display_startup_errors', 1);
    // error_reporting(E_ALL);

    // Log the error for debugging purposes (check your server's error logs)
    error_log("Failed to connect to MySQL: " . $conn->connect_error);

    // Terminate script execution and display a user-friendly message
    die("ERROR: Could not connect to the database. Please try again later. (Error Code: " . $conn->connect_errno . ")");
}

// Optional: Set charset to UTF-8 for proper character handling
$conn->set_charset("utf8mb4");

// Note: It's generally a good practice to close the connection when it's no longer needed,
// but for simple scripts that exit soon after, PHP typically closes it automatically.
// For long-running applications or complex logic, explicit closing ($conn->close();)
// or using persistent connections might be considered. For now, we'll let PHP handle it.

?>