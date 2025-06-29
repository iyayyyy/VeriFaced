<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Include your database connection file.
require_once 'db_connect.php';

// Function to safely output HTML to prevent XSS (Cross-Site Scripting)
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Unauthorized access. Please log in as an admin.";
    $_SESSION['flash_message_type'] = "danger";
    header("Location: adminlogin.php");
    exit();
}

// Initialize response variables for redirection
$success_message = '';
$error_message = '';
$redirect_page = 'index.php'; // Default page to redirect back to

// Check if tenant_id is provided via GET request
if (isset($_GET['tenant_id']) && is_numeric($_GET['tenant_id'])) {
    $tenantIdToDelete = (int)$_GET['tenant_id'];

    if (isset($conn) && $conn instanceof mysqli) {
        $stmt_delete = null;
        try {
            // First, check if there are any pending or active visitor requests for this tenant.
            // Your SQL schema uses ON DELETE RESTRICT for visitors where host_tenant_id is a FK.
            // This means you cannot delete a tenant if they have *any* associated visitors.
            // You might need to delete associated visitors first, or reassign them, or change their status.
            $sql_check_visitors = "SELECT COUNT(*) FROM visitors WHERE host_tenant_id = ?";
            $stmt_check_visitors = $conn->prepare($sql_check_visitors);
            if ($stmt_check_visitors) {
                $stmt_check_visitors->bind_param("i", $tenantIdToDelete);
                $stmt_check_visitors->execute();
                $result_check_visitors = $stmt_check_visitors->get_result();
                $row_check_visitors = $result_check_visitors->fetch_row();
                $visitor_count = $row_check_visitors[0];
                $stmt_check_visitors->close();

                if ($visitor_count > 0) {
                    // Option 1: Prevent deletion if visitors exist (based on RESTRICT)
                    // If you have ON DELETE RESTRICT for visitors, this is required.
                    $error_message = "Cannot delete tenant. There are still {$visitor_count} visitor records associated with this tenant. Please delete or reassign them first.";
                    $_SESSION['flash_message'] = $error_message;
                    $_SESSION['flash_message_type'] = "danger";
                    header("Location: " . $redirect_page);
                    exit();

                    // Option 2: Delete associated visitors first (if you change FK to CASCADE for visitors too, or handle explicitly)
                    /*
                    $sql_delete_visitors = "DELETE FROM visitors WHERE host_tenant_id = ?";
                    $stmt_delete_visitors = $conn->prepare($sql_delete_visitors);
                    if ($stmt_delete_visitors) {
                        $stmt_delete_visitors->bind_param("i", $tenantIdToDelete);
                        $stmt_delete_visitors->execute();
                        $stmt_delete_visitors->close();
                    }
                    */
                }
            }


            // SQL to delete the tenant.
            // ON DELETE CASCADE on 'members' table will automatically delete associated members.
            $sql_delete_tenant = "DELETE FROM tenants WHERE tenant_id = ?";
            $stmt_delete = $conn->prepare($sql_delete_tenant);

            if ($stmt_delete) {
                $stmt_delete->bind_param("i", $tenantIdToDelete);
                if ($stmt_delete->execute()) {
                    if ($stmt_delete->affected_rows > 0) {
                        $success_message = "Tenant and associated data deleted successfully!";
                        $_SESSION['flash_message'] = $success_message;
                        $_SESSION['flash_message_type'] = "success";
                    } else {
                        $error_message = "Tenant not found or already deleted.";
                        $_SESSION['flash_message'] = $error_message;
                        $_SESSION['flash_message_type'] = "warning";
                    }
                } else {
                    $error_message = "Database delete failed: " . $stmt_delete->error;
                    $_SESSION['flash_message'] = $error_message;
                    $_SESSION['flash_message_type'] = "danger";
                    error_log("Error deleting tenant: " . $stmt_delete->error);
                }
                $stmt_delete->close();
            } else {
                $error_message = "Database error: Could not prepare delete statement.";
                $_SESSION['flash_message'] = $error_message;
                $_SESSION['flash_message_type'] = "danger";
                error_log("Failed to prepare delete statement in delete_tenant.php: " . $conn->error);
            }
        } catch (Throwable $e) {
            $error_message = "An unexpected error occurred during tenant deletion.";
            $_SESSION['flash_message'] = $error_message;
            $_SESSION['flash_message_type'] = "danger";
            error_log("Unexpected error in delete_tenant.php: " . $e->getMessage());
        }
    } else {
        $error_message = "Database connection not available.";
        $_SESSION['flash_message'] = $error_message;
        $_SESSION['flash_message_type'] = "danger";
        error_log("Database connection not available in delete_tenant.php.");
    }
} else {
    $error_message = "No tenant ID provided for deletion.";
    $_SESSION['flash_message'] = $error_message;
    $_SESSION['flash_message_type'] = "warning";
}

// Redirect back to the unit profiles page
header("Location: " . $redirect_page);
exit();
?>