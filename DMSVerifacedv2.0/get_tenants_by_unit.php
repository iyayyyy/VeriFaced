<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file.
require_once 'db_connect.php'; // Ensure this path is correct and db_connect.php works.

// Set header to indicate that the response is JSON.
header('Content-Type: application/json');

$tenants = []; // Initialize an empty array to hold tenant data.

// Check if 'unit_id' is provided in the GET request and is a valid number.
if (isset($_GET['unit_id']) && is_numeric($_GET['unit_id'])) {
    $unit_id = (int)$_GET['unit_id']; // Cast to integer for security and type consistency.

    // Ensure database connection is established.
    if (isset($conn) && $conn instanceof mysqli) {
        $stmt = null; // Initialize statement variable.
        try {
            // Prepare a SQL query to select tenant_id, first_name, and last_name from the 'tenants' table
            // where the unit_id matches the provided unit_id.
            // Replace 'tenants', 'tenant_id', 'first_name', 'last_name', 'unit_id' with your actual column/table names if different.
            $sql = "SELECT tenant_id, first_name, last_name FROM tenants WHERE unit_id = ? ORDER BY last_name, first_name";
            
            $stmt = $conn->prepare($sql); // Prepare the SQL statement to prevent SQL injection.
            
            if ($stmt) {
                $stmt->bind_param("i", $unit_id); // Bind the integer unit_id parameter to the prepared statement.
                $stmt->execute(); // Execute the prepared statement.
                $result = $stmt->get_result(); // Get the result set.

                // Loop through the results and add each tenant to the $tenants array.
                while ($row = $result->fetch_assoc()) {
                    $tenants[] = [
                        'tenant_id' => $row['tenant_id'], // The ID to be used as option value.
                        'full_name' => $row['first_name'] . ' ' . $row['last_name'] // The full name to display.
                    ];
                }
                $stmt->close(); // Close the prepared statement.
            } else {
                // Log an error if statement preparation fails.
                error_log("Failed to prepare statement in get_tenants_by_unit.php: " . $conn->error);
            }
        } catch (Throwable $e) {
            // Catch any exceptions during database operation and log them.
            error_log("Error fetching tenants by unit in get_tenants_by_unit.php: " . $e->getMessage());
        }
        // $conn->close(); // Uncomment if your db_connect.php doesn't manage global connection closing.
    } else {
        // Log an error if the database connection is not available.
        error_log("Database connection not available in get_tenants_by_unit.php.");
    }
}

// Output the tenants array as a JSON encoded string.
echo json_encode($tenants);
?>