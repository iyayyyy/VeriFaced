<?php
session_start();
require_once 'db_connect.php'; // Ensure your database connection is included

header('Content-Type: application/json'); // Set header for JSON response

// Enable robust error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'User not logged in.']);
    exit();
}

if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("CRITICAL ERROR: Database connection failed in process_tenant_member_action.php");
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please contact support.']);
    exit();
}

$tenant_id = $_SESSION['tenant_id'];
$action = $_POST['action'] ?? '';

// Start transaction to ensure atomicity of deletion and logging
$conn->begin_transaction();

try {
    switch ($action) {
        case 'remove_permanent_member':
            $member_id = filter_var($_POST['member_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$member_id) {
                throw new Exception('Invalid permanent member ID.');
            }

            // Fetch member's name and verify ownership
            $member_name = 'Unknown Member';
            $stmt_fetch = $conn->prepare("SELECT full_name FROM members WHERE member_id = ? AND tenant_id = ?");
            if (!$stmt_fetch) {
                throw new Exception("Failed to prepare statement for fetching permanent member name: " . $conn->error);
            }
            $stmt_fetch->bind_param("ii", $member_id, $tenant_id);
            $stmt_fetch->execute();
            $result_fetch = $stmt_fetch->get_result();
            if ($row = $result_fetch->fetch_assoc()) {
                $member_name = $row['full_name'];
            } else {
                throw new Exception('Unauthorized action or permanent member not found.');
            }
            $stmt_fetch->close();

            // Delete the permanent member
            $sql_delete = "DELETE FROM members WHERE member_id = ?";
            $stmt_delete = $conn->prepare($sql_delete);
            if (!$stmt_delete) {
                throw new Exception("Failed to prepare delete permanent member statement: " . $conn->error);
            }
            $stmt_delete->bind_param("i", $member_id);
            
            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    // Log the activity
                    $log_action_type = 'permanent_member_removed';
                    $log_description = 'Removed permanent member: ' . $member_name;
                    $stmt_log = $conn->prepare("INSERT INTO tenant_activity_log (tenant_id, action_type, description, timestamp) VALUES (?, ?, ?, NOW())");
                    if ($stmt_log) {
                        $stmt_log->bind_param("iss", $tenant_id, $log_action_type, $log_description);
                        if (!$stmt_log->execute()) {
                            error_log("Error logging permanent member removal: " . $stmt_log->error);
                            // Decide if logging failure should rollback. For now, we'll commit if deletion succeeded.
                        }
                        $stmt_log->close();
                    } else {
                        error_log("Failed to prepare activity log statement for permanent member removal: " . $conn->error);
                    }
                    
                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Permanent member removed successfully.']);
                } else {
                    throw new Exception('Permanent member not found or already removed.');
                }
            } else {
                throw new Exception('Error removing permanent member: ' . $stmt_delete->error);
            }
            $stmt_delete->close();
            break;

        case 'remove_registered_member':
            $added_member_id = filter_var($_POST['added_member_id'] ?? '', FILTER_VALIDATE_INT);

            if (!$added_member_id) {
                throw new Exception('Invalid registered member ID.');
            }

            // Fetch member's name and verify ownership
            $member_name = 'Unknown Member';
            $stmt_fetch = $conn->prepare("SELECT full_name FROM addedmembers WHERE added_member_id = ? AND tenant_id = ?");
            if (!$stmt_fetch) {
                throw new Exception("Failed to prepare statement for fetching registered member name: " . $conn->error);
            }
            $stmt_fetch->bind_param("ii", $added_member_id, $tenant_id);
            $stmt_fetch->execute();
            $result_fetch = $stmt_fetch->get_result();
            if ($row = $result_fetch->fetch_assoc()) {
                $member_name = $row['full_name'];
            } else {
                throw new Exception('Unauthorized action or registered member not found.');
            }
            $stmt_fetch->close();

            // Delete the registered member
            $sql_delete = "DELETE FROM addedmembers WHERE added_member_id = ?";
            $stmt_delete = $conn->prepare($sql_delete);
            if (!$stmt_delete) {
                throw new Exception("Failed to prepare delete registered member statement: " . $conn->error);
            }
            $stmt_delete->bind_param("i", $added_member_id);

            if ($stmt_delete->execute()) {
                if ($stmt_delete->affected_rows > 0) {
                    // Log the activity
                    $log_action_type = 'registered_member_removed';
                    $log_description = 'Removed registered member: ' . $member_name;
                    $stmt_log = $conn->prepare("INSERT INTO tenant_activity_log (tenant_id, action_type, description, timestamp) VALUES (?, ?, ?, NOW())");
                    if ($stmt_log) {
                        $stmt_log->bind_param("iss", $tenant_id, $log_action_type, $log_description);
                        if (!$stmt_log->execute()) {
                            error_log("Error logging registered member removal: " . $stmt_log->error);
                            // Decide if logging failure should rollback. For now, we'll commit if deletion succeeded.
                        }
                        $stmt_log->close();
                    } else {
                        error_log("Failed to prepare activity log statement for registered member removal: " . $conn->error);
                    }

                    $conn->commit();
                    echo json_encode(['success' => true, 'message' => 'Registered member removed successfully.']);
                } else {
                    throw new Exception('Registered member not found or already removed.');
                }
            } else {
                throw new Exception('Error removing registered member: ' . $stmt_delete->error);
            }
            $stmt_delete->close();
            break;

        default:
            throw new Exception('Invalid action.');
    }
} catch (Exception $e) {
    $conn->rollback(); // Rollback any changes on error
    error_log("process_tenant_member_action.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>