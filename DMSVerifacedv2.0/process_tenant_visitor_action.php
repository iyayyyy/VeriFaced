<?php
session_start();
require_once 'db_connect.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => 'An unknown error occurred.'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Renamed the action to match the JS call in T_UnitProfile.php
    if ($_POST['action'] === 'soft_delete_visitor') {
        if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
            $response['message'] = "Authentication required.";
            echo json_encode($response);
            exit();
        }

        $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $logged_in_tenant_id = $_SESSION['tenant_id'];

        if (empty($visitor_id)) {
            $response['message'] = "Invalid visitor ID provided.";
            echo json_encode($response);
            exit();
        }

        if (isset($conn) && $conn instanceof mysqli) {
            $stmt = null;
            try {
                // Modified SQL to perform a soft delete: update status and processed_at timestamp
                // This will work even if the status is already 'deleted', effectively re-stamping it.
                $sql = "UPDATE visitors SET status = 'deleted', processed_at = NOW() WHERE visitor_id = ? AND host_tenant_id = ?";
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ii", $visitor_id, $logged_in_tenant_id);
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $response = ['success' => true, 'message' => 'Visitor record marked as deleted successfully.'];
                        } else {
                            // If affected_rows is 0, it means the record wasn't found or already had the same status.
                            // We can provide a more specific message if desired, but "not found" covers both.
                            $response['message'] = "Visitor record not found or already marked as deleted, or you don't have permission.";
                        }
                    } else {
                        $response['message'] = "Database error: " . $stmt->error;
                        error_log("Error soft deleting visitor: " . $stmt->error);
                    }
                } else {
                    $response['message'] = "Database error: Could not prepare statement.";
                    error_log("Failed to prepare statement for visitor soft deletion: " . $conn->error);
                }
            } catch (Throwable $e) {
                $response['message'] = "An unexpected error occurred: " . $e->getMessage();
                error_log("Exception during visitor soft deletion: " . $e->getMessage());
            } finally {
                if ($stmt) {
                    $stmt->close();
                }
            }
        } else {
            $response['message'] = "Database connection error.";
        }
    } else {
        $response['message'] = "Invalid action specified.";
    }
} else {
    $response['message'] = "Invalid request method.";
}

echo json_encode($response);
?>