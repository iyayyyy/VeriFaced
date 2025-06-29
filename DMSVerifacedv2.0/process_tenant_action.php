<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE. NO SPACES OR NEWLINES ABOVE.
ob_start(); // Start output buffering at the very beginning

session_start();
header('Content-Type: application/json'); // Ensure the response is JSON

// Enable robust error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL); // This will send all errors to the Apache error log

// Include your database connection file
require_once 'db_connect.php';

// Function to recursively remove a directory and its contents (UNCHANGED)
function rrmdir($dir) {
    if (!file_exists($dir)) {
        error_log("rrmdir: Directory does not exist: " . $dir);
        return true;
    }
    if (!is_dir($dir)) {
        error_log("rrmdir: Path is not a directory: " . $dir);
        return unlink($dir);
    }
    if (!is_writable($dir)) {
        error_log("rrmdir: Directory is not writable: " . $dir);
        return false;
    }
    $objects = scandir($dir);
    if ($objects === false) {
        error_log("rrmdir: Failed to scan directory: " . $dir);
        return false;
    }
    foreach ($objects as $object) {
        if ($object != "." && $object != "..") {
            $path = $dir . "/" . $object;
            if (is_dir($path)) {
                error_log("rrmdir: Recursively deleting subdirectory: " . $path);
                if (!rrmdir($path)) {
                    error_log("rrmdir: Failed recursive delete for subdirectory: " . $path);
                    return false;
                }
            } else {
                if (!is_writable($path)) {
                    error_log("rrmdir: File is not writable: " . $path . " (Permissions issue likely)");
                    return false;
                }
                error_log("rrmdir: Deleting file: " . $path);
                if (!unlink($path)) {
                    error_log("rrmdir: FAILED to delete file: " . $path . " (Error: " . error_get_last()['message'] . ")");
                    return false;
                }
            }
        }
    }
    error_log("rrmdir: Attempting to delete empty directory: " . $dir);
    if (!rmdir($dir)) {
        error_log("rrmdir: FAILED to delete empty directory: " . $dir . " (Error: " . error_get_last()['message'] . ")");
        return false;
    }
    error_log("rrmdir: Successfully processed directory: " . $dir);
    return true;
}


// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    ob_clean(); // Clean buffer before sending unauthorized response
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in as admin.']);
    exit();
}

// Check if the request is a POST request and has the required action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'delete_tenant') {
        $tenantId = filter_var($_POST['tenant_id'] ?? '', FILTER_SANITIZE_NUMBER_INT);
        $unitNumber = filter_var($_POST['unit_number'] ?? '', FILTER_SANITIZE_STRING);

        if (empty($tenantId) || !is_numeric($tenantId)) {
            ob_clean(); // Clean buffer before sending invalid ID response
            echo json_encode(['success' => false, 'message' => 'Invalid tenant ID.']);
            exit();
        }

        $stmt = null;
        $response = ['success' => false, 'message' => 'An unknown error occurred.']; // Default to error

        try {
            if (isset($conn) && $conn instanceof mysqli) {
                // --- Step 1: Retrieve tenant info including unit_number and image paths for deletion ---
                $tenant_data_for_deletion = null;
                $stmt_fetch_info = $conn->prepare("SELECT u.unit_number, t.known_face_path_1, t.known_face_path_2, t.known_face_path_3 FROM tenants t JOIN units u ON t.unit_id = u.unit_id WHERE t.tenant_id = ?");
                if ($stmt_fetch_info) {
                    $stmt_fetch_info->bind_param("i", $tenantId);
                    $stmt_fetch_info->execute();
                    $result_info = $stmt_fetch_info->get_result();
                    if ($row_info = $result_info->fetch_assoc()) {
                        $tenant_data_for_deletion = $row_info;
                    }
                    $stmt_fetch_info->close();
                } else {
                    error_log("Failed to prepare statement for fetching tenant deletion info: " . $conn->error);
                    $response = ['success' => false, 'message' => 'Database error preparing tenant info retrieval.'];
                    ob_clean(); // Clean buffer before sending database error response
                    echo json_encode($response);
                    exit();
                }

                if ($tenant_data_for_deletion === null) {
                    error_log("Tenant with ID {$tenantId} not found for deletion, or unit_number missing.");
                    ob_clean(); // Clean buffer before sending tenant not found response
                    echo json_encode(['success' => false, 'message' => 'Tenant not found or unit info unavailable.']);
                    exit();
                }

                error_log("Fetched tenant data for deletion: " . print_r($tenant_data_for_deletion, true));

                // Determine the exact unit folder name based on the database value
                $sanitized_unit_folder_name = preg_replace('/[^a-zA-Z0-9_]/', '', str_replace(' ', '_', $tenant_data_for_deletion['unit_number']));
                $unit_folder_path_to_delete = __DIR__ . '/models/known_faces/' . $sanitized_unit_folder_name;


                // --- Step 2: Delete the tenant record from the database ---
                $conn->begin_transaction(); // Start transaction for atomicity

                $stmt = $conn->prepare("DELETE FROM tenants WHERE tenant_id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $tenantId);
                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            // --- Step 3: Delete associated image files only ---
                            $base_dir = __DIR__;

                            $all_files_deleted_successfully = true;
                            foreach (['known_face_path_1', 'known_face_path_2', 'known_face_path_3'] as $col_name) {
                                $relative_path = $tenant_data_for_deletion[$col_name];
                                if (!empty($relative_path)) {
                                    $full_path = $base_dir . '/' . $relative_path;
                                    error_log("Attempting to delete individual file: " . $full_path);
                                    
                                    if (file_exists($full_path)) {
                                        if (is_writable($full_path)) {
                                            if (unlink($full_path)) {
                                                error_log("Successfully deleted file: " . $full_path);
                                            } else {
                                                error_log("FAILED to delete file: " . $full_path . " (Error: " . error_get_last()['message'] . ")");
                                                $all_files_deleted_successfully = false;
                                            }
                                        } else {
                                            error_log("File is not writable, cannot delete: " . $full_path . " (Permissions issue likely)");
                                            $all_files_deleted_successfully = false;
                                        }
                                    } else {
                                        error_log("File not found for deletion (already deleted or wrong path): " . $full_path);
                                    }
                                }
                            }

                            // Do NOT attempt to delete the unit folder itself.

                            $conn->commit();
                            
                            if ($all_files_deleted_successfully) {
                                $response = ['success' => true, 'message' => 'Tenant and associated image files deleted successfully.'];
                            } else {
                                $response = ['success' => true, 'message' => 'Tenant deleted, but some image files could not be removed. Check server logs for details.'];
                            }
                            
                        } else {
                            $conn->rollback();
                            $response = ['success' => false, 'message' => 'Tenant not found.'];
                        }
                    } else {
                        $conn->rollback();
                        $response = ['success' => false, 'message' => 'Error deleting tenant from database: ' . $stmt->error];
                        error_log("Error deleting tenant: " . $stmt->error);
                    }
                } else {
                    $conn->rollback();
                    $response = ['success' => false, 'message' => 'Database error preparing deletion statement.'];
                    error_log("Failed to prepare delete tenant statement: " . $conn->error);
                }
            } else {
                $response = ['success' => false, 'message' => 'Database connection error.'];
                error_log("Database connection variable \$conn is not set or not a mysqli object in process_tenant_action.php.");
            }
        } catch (Throwable $e) {
            if ($conn && $conn->in_transaction) {
                $conn->rollback();
            }
            error_log("Critical error during tenant deletion process: " . $e->getMessage() . " on line " . $e->getLine() . " in " . $e->getFile());
            $response = ['success' => false, 'message' => 'An unexpected server error occurred during deletion. Please try again later.'];
        } finally {
            if ($stmt) {
                $stmt->close();
            }
        }
    } else {
        $response = ['success' => false, 'message' => 'Invalid action specified or missing data.'];
    }
} else {
    $response = ['success' => false, 'message' => 'Invalid request method.'];
}

// Clean any accidental output before sending the final JSON
ob_clean();
echo json_encode($response);
exit();
// NO CLOSING PHP TAG ?> IS RECOMMENDED FOR FILES THAT ONLY CONTAIN PHP CODE.