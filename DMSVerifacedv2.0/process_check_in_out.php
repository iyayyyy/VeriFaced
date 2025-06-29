<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // Ensure your database connection file is included

header('Content-Type: application/json'); // Set header for JSON response

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    $response['message'] = "Authentication required. Please log in.";
    echo json_encode($response);
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Check if it's a POST request and 'action' is set
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action_type_raw = trim($_POST['action']);

    // Fetch tenant's full name for the log entry
    $tenant_full_name = 'Unknown Tenant';
    $stmt_tenant_name = null; // Initialize to null
    try {
        $stmt_tenant_name = $conn->prepare("SELECT first_name, last_name FROM tenants WHERE tenant_id = ?");
        if ($stmt_tenant_name) {
            $stmt_tenant_name->bind_param("i", $logged_in_tenant_id);
            $stmt_tenant_name->execute();
            $result_tenant_name = $stmt_tenant_name->get_result();
            if ($result_tenant_name->num_rows > 0) {
                $tenant_data = $result_tenant_name->fetch_assoc();
                $tenant_full_name = $tenant_data['first_name'] . ' ' . $tenant_data['last_name'];
            }
        }
    } catch (Throwable $e) {
        error_log("Error fetching tenant name in process_check_in_out.php: " . $e->getMessage());
        // Continue, as this is just for logging description
    } finally {
        if ($stmt_tenant_name) {
            $stmt_tenant_name->close();
        }
    }


    $standard_action_type = ''; // This will be the standardized string for 'action_type' column
    $log_description = '';      // This will be the full descriptive string for 'description' column

    if ($action_type_raw === 'check_in') {
        $standard_action_type = "tenant_checked_in";
        $log_description = "Tenant checked in: " . $tenant_full_name;
    } elseif ($action_type_raw === 'check_out') {
        $standard_action_type = "tenant_checked_out";
        $log_description = "Tenant checked out: " . $tenant_full_name;
    } else {
        $response['message'] = "Invalid action type.";
        echo json_encode($response);
        exit();
    }

    if (isset($conn) && $conn instanceof mysqli) {
        // Start a transaction for atomicity
        $conn->begin_transaction();

        try {
            // 1. Log the activity
            // MODIFIED: Insert into both 'action_type' and 'description' columns
            $stmt_log = $conn->prepare("INSERT INTO tenant_activity_log (tenant_id, action_type, description) VALUES (?, ?, ?)");
            if ($stmt_log) {
                $stmt_log->bind_param("iss", $logged_in_tenant_id, $standard_action_type, $log_description); // Use the correct variables
                if (!$stmt_log->execute()) {
                    throw new Exception("Failed to record action in log: " . $stmt_log->error);
                }
                $stmt_log->close();
            } else {
                throw new Exception("Database error: Could not prepare statement for activity log.");
            }

            // 2. Update the tenant's current status in the 'tenants' table
            $update_status_sql = "UPDATE tenants SET current_status = ?, last_status_update = NOW() WHERE tenant_id = ?";
            $stmt_status = $conn->prepare($update_status_sql);
            if ($stmt_status) {
                $status_value = ($action_type_raw === 'check_in') ? 'In' : 'Out';
                $stmt_status->bind_param("si", $status_value, $logged_in_tenant_id);
                if (!$stmt_status->execute()) {
                    throw new Exception("Failed to update tenant status: " . $stmt_status->error);
                }
                $stmt_status->close();
            } else {
                throw new Exception("Database error: Could not prepare status update statement for tenants table.");
            }

            $conn->commit();
            $response['success'] = true;
            $response['message'] = "Action '" . htmlspecialchars($action_type_raw) . "' recorded successfully!";

        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Operation failed: " . $e->getMessage();
            error_log("Error in process_check_in_out.php: " . $e->getMessage());
        }
    } else {
        $response['message'] = "Database connection not available.";
        error_log("Database connection not available in process_check_in_out.php.");
    }
} else {
    $response['message'] = "Invalid request.";
}

echo json_encode($response);
exit();
?>