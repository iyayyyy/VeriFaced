<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php';

// Function to safely output JSON
function json_response($success, $message, $data = []) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message] + $data);
    exit();
}

// Check if added member is logged in
if (!isset($_SESSION['added_member_logged_in']) || $_SESSION['added_member_logged_in'] !== true) {
    json_response(false, "Unauthorized: Please log in as a member.", ['redirect' => 'memberlogin.php']);
}

$logged_in_added_member_id = $_SESSION['added_member_id'];

// Check for database connection
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("CRITICAL ERROR: Database connection failed in process_member_visitor_action.php");
    json_response(false, "Critical: Database connection failed. Please contact support.");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';
    $visitorId = $_POST['visitor_id'] ?? '';

    // Validate inputs
    if (empty($action) || empty($visitorId)) {
        json_response(false, "Invalid request: Missing action or visitor ID.");
    }

    if (!is_numeric($visitorId)) {
        json_response(false, "Invalid visitor ID format.");
    }

    $visitorId = (int)$visitorId; // Cast to integer for security

    switch ($action) {
        case 'remove_visitor_from_member_view': // This action now means permanent deletion
            $stmt = null;
            try {
                // IMPORTANT: This will PERMANENTLY DELETE the visitor record from the 'visitors' table.
                // It will only delete if the visitor was specifically assigned to this logged-in member.
                $sql = "DELETE FROM visitors 
                        WHERE visitor_id = ? 
                          AND specific_host_id = ? 
                          AND specific_host_type = 'member'";

                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $visitorId, $logged_in_added_member_id);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        json_response(true, "Visitor permanently deleted from the system.");
                    } else {
                        // This could happen if visitorId is invalid, or if the visitor wasn't assigned to this member.
                        json_response(false, "Failed to delete visitor. Visitor not found or not assigned to your account.");
                    }
                } else {
                    error_log("Failed to prepare statement for deleting visitor: " . $conn->error);
                    json_response(false, "Database error: Could not prepare statement.");
                }
            } catch (Throwable $e) {
                error_log("Error deleting visitor from system: " . $e->getMessage());
                json_response(false, "An unexpected error occurred while deleting the visitor.");
            } finally {
                if ($stmt) {
                    $stmt->close();
                }
            }
            break;

        default:
            json_response(false, "Invalid action specified.");
            break;
    }
} else {
    json_response(false, "Invalid request method.");
}
?>
