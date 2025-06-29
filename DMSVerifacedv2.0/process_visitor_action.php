<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php'; // Your database connection file

header('Content-Type: application/json'); // Ensure the response is JSON

// Enable robust error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Authentication required.']);
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && isset($_POST['visitor_id'])) {
    $action = $_POST['action']; // 'accept', 'reject', 'delete_visitor', 'add_as_member'
    $visitor_id = filter_var($_POST['visitor_id'], FILTER_VALIDATE_INT);

    if (!$visitor_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid visitor ID.']);
        exit();
    }

    // First, verify that this visitor belongs to the logged-in tenant (and fetch necessary data)
    $stmt_verify = null;
    $visitor_data = null;
    try {
        $sql_verify = "SELECT v.host_tenant_id, v.profile_pic_path, v.full_name, t.unit_id, u.unit_number, v.phone_number, v.relationship_to_tenant, v.purpose_of_visit
                       FROM visitors v
                       JOIN tenants t ON v.host_tenant_id = t.tenant_id
                       JOIN units u ON t.unit_id = u.unit_id
                       WHERE v.visitor_id = ? AND v.host_tenant_id = ?";
        $stmt_verify = $conn->prepare($sql_verify);
        if ($stmt_verify) {
            $stmt_verify->bind_param("ii", $visitor_id, $logged_in_tenant_id);
            $stmt_verify->execute();
            $result_verify = $stmt_verify->get_result();
            if ($result_verify->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Visitor not found or unauthorized.']);
                exit();
            }
            $visitor_data = $result_verify->fetch_assoc();
        } else {
            error_log("PROCESS_VISITOR_ACTION: Failed to prepare verification statement: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error during verification.']);
            exit();
        }
    } catch (Throwable $e) {
        error_log("PROCESS_VISITOR_ACTION: Verification error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred during verification.']);
        exit();
    } finally {
        if ($stmt_verify) $stmt_verify->close();
    }

    $conn->begin_transaction(); // Start transaction for atomicity

    $stmt_update = null;
    try {
        $sql_update = "";
        $bind_params = [];
        $bind_types = "";
        $log_action_type = ''; // Initialize for activity log
        $log_description = '';  // Initialize for activity log

        switch ($action) {
            case 'accept':
                $sql_update = "UPDATE visitors SET status = 'accepted', processed_at = NOW() WHERE visitor_id = ?";
                $bind_params = [$visitor_id];
                $bind_types = "i";
                $log_action_type = 'visitor_accepted';
                $log_description = 'Accepted visitor: ' . ($visitor_data['full_name'] ?? 'Unknown');
                break;

            case 'reject':
                $sql_update = "UPDATE visitors SET status = 'rejected', processed_at = NOW() WHERE visitor_id = ?";
                $bind_params = [$visitor_id];
                $bind_types = "i";
                $log_action_type = 'visitor_rejected';
                $log_description = 'Rejected visitor: ' . ($visitor_data['full_name'] ?? 'Unknown');
                break;

            case 'delete_visitor':
                $sql_update = "DELETE FROM visitors WHERE visitor_id = ? AND host_tenant_id = ?";
                $bind_params = [$visitor_id, $logged_in_tenant_id];
                $bind_types = "ii";
                $log_action_type = 'visitor_deleted';
                $log_description = 'Deleted visitor record: ' . ($visitor_data['full_name'] ?? 'Unknown');
                break;

            case 'add_as_member':
                error_log("PROCESS_VISITOR_ACTION: Attempting to add member. Visitor Data: " . print_r($visitor_data, true));

                $trimmed_full_name = trim($visitor_data['full_name'] ?? '');
                $trimmed_phone_number = trim($visitor_data['phone_number'] ?? '');

                if (empty($trimmed_full_name)) {
                    throw new Exception("Cannot add member: Visitor full name is empty.");
                }
                if (empty($trimmed_phone_number)) {
                    throw new Exception("Cannot add member: Visitor phone number is empty.");
                }

                $stmt_check_member = null;
                try {
                    $sql_check_member = "SELECT member_id FROM members WHERE tenant_id = ? AND full_name = ?";
                    $stmt_check_member = $conn->prepare($sql_check_member);
                    if ($stmt_check_member) {
                        $stmt_check_member->bind_param("is", $logged_in_tenant_id, $trimmed_full_name);
                        $stmt_check_member->execute();
                        $result_check_member = $stmt_check_member->get_result();
                        if ($result_check_member->num_rows > 0) {
                            throw new Exception("Visitor is already registered as a member for this unit. No duplicates allowed.");
                        }
                    } else {
                        throw new Exception("Database error checking for existing member: " . $conn->error);
                    }
                } finally {
                    if ($stmt_check_member) $stmt_check_member->close();
                }
                
                $sql_update = "INSERT INTO members (tenant_id, full_name, phone_number, relationship_to_tenant, profile_pic_path) VALUES (?, ?, ?, ?, ?)";
                $bind_params = [
                    $logged_in_tenant_id,
                    $trimmed_full_name,
                    $trimmed_phone_number,
                    $visitor_data['relationship_to_tenant'],
                    $visitor_data['profile_pic_path']
                ];
                $bind_types = "issss";
                
                $sql_update_visitor_status = "UPDATE visitors SET status = 'member_added', processed_at = NOW() WHERE visitor_id = ?";
                $stmt_update_visitor_status = $conn->prepare($sql_update_visitor_status);
                if ($stmt_update_visitor_status) {
                    $stmt_update_visitor_status->bind_param("i", $visitor_id);
                    if (!$stmt_update_visitor_status->execute()) {
                        throw new Exception("Failed to update visitor status to 'member_added': " . $stmt_update_visitor_status->error);
                    }
                    $stmt_update_visitor_status->close();
                } else {
                    throw new Exception("Failed to prepare update visitor status statement: " . $conn->error);
                }

                $log_action_type = 'member_added_from_visitor';
                $log_description = 'Added visitor as permanent member: ' . ($visitor_data['full_name'] ?? 'Unknown');
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action.']);
                exit();
        }

        $stmt = $conn->prepare($sql_update);
        if ($stmt) {
            if (!empty($bind_params)) {
                $stmt->bind_param($bind_types, ...$bind_params);
            }
            
            if ($stmt->execute()) {
                // Log activity if an action type was set
                if (!empty($log_action_type)) {
                    $stmt_log = $conn->prepare("INSERT INTO tenant_activity_log (tenant_id, action_type, description, timestamp) VALUES (?, ?, ?, NOW())");
                    if ($stmt_log) {
                        $stmt_log->bind_param("iss", $logged_in_tenant_id, $log_action_type, $log_description);
                        if (!$stmt_log->execute()) {
                            error_log("PROCESS_VISITOR_ACTION: Failed to log activity for {$action}: " . $stmt_log->error);
                            // Decide if logging failure should roll back the main action.
                            // For activity logs, it's often acceptable to proceed if the main action succeeded.
                        }
                        $stmt_log->close();
                    } else {
                        error_log("PROCESS_VISITOR_ACTION: Failed to prepare activity log statement for {$action}: " . $conn->error);
                    }
                }

                $conn->commit(); // Commit transaction

                $new_pending_count = 0;
                $stmt_count = $conn->prepare("SELECT COUNT(*) AS count FROM visitors WHERE host_tenant_id = ? AND status = 'pending'");
                if ($stmt_count) {
                    $stmt_count->bind_param("i", $logged_in_tenant_id);
                    $stmt_count->execute();
                    $result_count = $stmt_count->get_result();
                    $row_count = $result_count->fetch_assoc();
                    $new_pending_count = $row_count['count'];
                    $stmt_count->close();
                }

                $message_text = 'Visitor request ' . $action . 'ed successfully!';
                if ($action === 'add_as_member') {
                    $message_text = 'Visitor successfully added as a permanent member!';
                } else if ($action === 'delete_visitor') {
                    $message_text = 'Visitor deleted successfully!';
                }

                echo json_encode([
                    'success' => true,
                    'message' => $message_text,
                    'new_pending_count' => $new_pending_count
                ]);
            } else {
                $conn->rollback(); // Rollback on update/insert failure
                error_log("PROCESS_VISITOR_ACTION: Failed to execute action ({$action}): " . $stmt->error);
                echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' visitor: ' . $stmt->error]);
            }
        } else {
            $conn->rollback(); // Rollback on prepare failure
            error_log("PROCESS_VISITOR_ACTION: Failed to prepare statement for action ({$action}): " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Database error preparing ' . $action . ' statement.']);
        }
    } catch (Throwable $e) {
        $conn->rollback(); // Rollback on any exception
        error_log("PROCESS_VISITOR_ACTION: General error during action ({$action}): " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'An unexpected error occurred: ' . $e->getMessage()]);
    } finally {
        if ($stmt) $stmt->close();
        if ($conn) $conn->close(); // Close connection
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method or missing parameters.']);
}
?>