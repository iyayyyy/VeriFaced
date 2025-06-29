<?php
// process_member_activity.php
session_start();
require_once 'db_connect.php'; // Ensure this file creates a $conn mysqli object

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['added_member_logged_in']) || $_SESSION['added_member_logged_in'] !== true) {
    $response['message'] = "Authentication required.";
    echo json_encode($response);
    exit();
}

$logged_in_added_member_id = $_SESSION['added_member_id'];
$logged_in_added_member_tenant_id = $_SESSION['added_member_tenant_id'];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action'])) {
    $action_raw = $_POST['action']; // Renamed from $action to $action_raw to avoid conflict

    // Fetch member's full name for the log entry
    $member_full_name = 'Unknown Member';
    $stmt_member_name = null;
    try {
        $stmt_member_name = $conn->prepare("SELECT full_name FROM addedmembers WHERE added_member_id = ?");
        if ($stmt_member_name) {
            $stmt_member_name->bind_param("i", $logged_in_added_member_id);
            $stmt_member_name->execute();
            $result_member_name = $stmt_member_name->get_result();
            if ($result_member_name->num_rows > 0) {
                $member_data = $result_member_name->fetch_assoc();
                $member_full_name = $member_data['full_name'];
            }
        }
    } catch (Throwable $e) {
        error_log("Error fetching member name in process_member_activity.php: " . $e->getMessage());
    } finally {
        if ($stmt_member_name) {
            $stmt_member_name->close();
        }
    }

    // Fetch tenant's full name for the log entry (Optional, but good for context)
    $tenant_full_name = 'Unknown Tenant';
    $stmt_tenant_name = null;
    try {
        $stmt_tenant_name = $conn->prepare("SELECT first_name, last_name FROM tenants WHERE tenant_id = ?");
        if ($stmt_tenant_name) {
            $stmt_tenant_name->bind_param("i", $logged_in_added_member_tenant_id);
            $stmt_tenant_name->execute();
            $result_tenant_name = $stmt_tenant_name->get_result();
            if ($result_tenant_name->num_rows > 0) {
                $tenant_data = $result_tenant_name->fetch_assoc();
                $tenant_full_name = $tenant_data['first_name'] . ' ' . $tenant_data['last_name'];
            }
        }
    } catch (Throwable $e) {
        error_log("Error fetching tenant name in process_member_activity.php: " . $e->getMessage());
    } finally {
        if ($stmt_tenant_name) {
            $stmt_tenant_name->close();
        }
    }


    $standard_action_type = ''; // For the action_type column (e.g., 'member_checked_in')
    $log_description = '';      // For the description column (e.g., 'Member John Doe checked in')
    $status_to_update = '';

    if ($action_raw === 'check_in') {
        $status_to_update = 'In';
        $standard_action_type = 'member_checked_in';
        $log_description = "Member checked in: " . $member_full_name;
    } elseif ($action_raw === 'check_out') {
        $status_to_update = 'Out';
        $standard_action_type = 'member_checked_out';
        $log_description = "Member checked out: " . $member_full_name;
    } elseif ($action_raw === 'remove_visitor_from_member_view') {
        $visitor_id = filter_var($_POST['visitor_id'] ?? '', FILTER_VALIDATE_INT);
        if (!$visitor_id) {
            $response['message'] = "Invalid visitor ID for removal.";
            echo json_encode($response);
            exit();
        }

        // Verify the visitor actually belongs to this member (and the tenant)
        $stmt_verify_visitor = $conn->prepare("SELECT v.full_name FROM visitors v WHERE v.visitor_id = ? AND v.specific_host_id = ? AND v.specific_host_type = 'member' AND v.host_tenant_id = ?");
        if (!$stmt_verify_visitor) {
            $response['message'] = "Database error verifying visitor ownership: " . $conn->error;
            echo json_encode($response);
            exit();
        }
        $stmt_verify_visitor->bind_param("iii", $visitor_id, $logged_in_added_member_id, $logged_in_added_member_tenant_id);
        $stmt_verify_visitor->execute();
        $result_verify_visitor = $stmt_verify_visitor->get_result();
        $visitor_data = $result_verify_visitor->fetch_assoc();
        $stmt_verify_visitor->close();

        if (!$visitor_data) {
            $response['message'] = "Visitor not found or unauthorized for removal by this member.";
            echo json_encode($response);
            exit();
        }
        $visitor_name_for_log = $visitor_data['full_name'];

        $conn->begin_transaction();
        try {
            // Update visitor record: Mark them as not being specifically hosted by this member anymore.
            // They are still a visitor of the main tenant.
            $stmt_update_visitor = $conn->prepare("UPDATE visitors SET specific_host_id = NULL, specific_host_type = 'tenant' WHERE visitor_id = ?");
            if (!$stmt_update_visitor) {
                throw new Exception("Failed to prepare visitor update statement: " . $conn->error);
            }
            $stmt_update_visitor->bind_param("i", $visitor_id);
            if (!$stmt_update_visitor->execute()) {
                throw new Exception("Failed to update visitor specific host: " . $stmt_update_visitor->error);
            }
            $stmt_update_visitor->close();

            // Log this action to the TENANT'S activity log
            $standard_action_type = "member_removed_visitor";
            $log_description = "Member '{$member_full_name}' removed visitor '{$visitor_name_for_log}' from their personal accepted list.";

            $stmt_log = $conn->prepare("INSERT INTO tenant_activity_log (tenant_id, action_type, description) VALUES (?, ?, ?)");
            if (!$stmt_log) {
                throw new Exception("Failed to prepare activity log statement: " . $conn->error);
            }
            $stmt_log->bind_param("iss", $logged_in_added_member_tenant_id, $standard_action_type, $log_description);
            if (!$stmt_log->execute()) {
                error_log("Error logging member visitor removal: " . $stmt_log->error);
            }
            $stmt_log->close();

            $conn->commit();
            $response['success'] = true;
            $response['message'] = "Visitor '{$visitor_name_for_log}' removed from your list.";
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Failed to remove visitor: " . $e->getMessage();
            error_log("Error during member visitor removal: " . $e->getMessage());
        }
        echo json_encode($response);
        exit();
    } else {
        $response['message'] = "Invalid action type.";
        echo json_encode($response);
        exit();
    }

    // This block now specifically handles check_in/check_out actions
    if ($action_raw === 'check_in' || $action_raw === 'check_out') {
        // Start a transaction for atomicity
        $conn->begin_transaction();

        try {
            // 1. Update the member's current_status
            $update_status_sql = "UPDATE addedmembers SET current_status = ?, last_status_update = NOW() WHERE added_member_id = ?";
            $stmt_status = $conn->prepare($update_status_sql);
            if ($stmt_status) {
                $stmt_status->bind_param("si", $status_to_update, $logged_in_added_member_id);
                if (!$stmt_status->execute()) {
                    throw new Exception("Error updating member status: " . $stmt_status->error);
                }
                $stmt_status->close();
            } else {
                throw new Exception("Database error: Could not prepare status update statement.");
            }

            // 2. Log the activity for the tenant
            // MODIFIED: Insert into 'action_type' and 'description'
            $insert_log_sql = "INSERT INTO tenant_activity_log (tenant_id, action_type, description, timestamp) VALUES (?, ?, ?, NOW())";
            $stmt_log = $conn->prepare($insert_log_sql);
            if ($stmt_log) {
                $stmt_log->bind_param("iss", $logged_in_added_member_tenant_id, $standard_action_type, $log_description); // Use standard_action_type and log_description
                if (!$stmt_log->execute()) {
                    throw new Exception("Error logging activity: " . $stmt_log->error);
                }
                $stmt_log->close();
            } else {
                throw new Exception("Database error: Could not prepare activity log statement.");
            }

            $conn->commit();
            $response['success'] = true;
            $response['message'] = "Successfully checked " . $status_to_update . ". Activity logged.";
        } catch (Exception $e) {
            $conn->rollback();
            $response['message'] = "Operation failed: " . $e->getMessage();
            error_log("Error in process_member_activity.php: " . $e->getMessage());
        }
    }
} else {
    $response['message'] = "Invalid request method or missing action.";
}

echo json_encode($response);
?>