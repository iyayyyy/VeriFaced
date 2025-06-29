<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

require_once 'db_connect.php';

// Add a check for database connection after require_once
if (!isset($conn) || !$conn instanceof mysqli) {
    error_log("CRITICAL ERROR: Database connection failed in T_UnitProfile.php");
    $_SESSION['flash_message'] = "Critical: Database connection failed. Please contact support.";
    $_SESSION['flash_message_type'] = "danger";
    header("Location: login.php"); // Or show an error page
    exit();
}


function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Check if tenant is logged in
if (!isset($_SESSION['tenant_logged_in']) || $_SESSION['tenant_logged_in'] !== true) {
    $_SESSION['flash_message'] = "Please log in to view your unit profile.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: login.php"); // Redirect to tenant login page
    exit();
}

$logged_in_tenant_id = $_SESSION['tenant_id'];

// Initialize tenant and unit data
$tenant_first_name = '';
$tenant_last_name = '';
$tenant_phone_number = '';
$tenant_email = '';
$tenant_profile_pic_path = 'https://placehold.co/150x150/A0A0A0/FFFFFF?text=Owner+Image'; // Default placeholder
$unit_number = '';
// NEW: Initialize for current status and last status update
$tenant_current_status = 'N/A';
$tenant_last_status_update = 'N/A';


// Initialize HTML for various sections
$permanent_members_html = '';
$registered_members_html = '';
$visitors_list_html = ''; // Still initialize, but it won't be displayed
$activity_log_html = '';

$pending_badge_count = 0;

// Fetch Pending count for badge (from actual database)
    $stmt_pending_count = null;
    try {
        $sql_pending_count = "SELECT COUNT(*) AS count FROM visitors WHERE host_tenant_id = ? AND status = 'pending'";
        $stmt_pending_count = $conn->prepare($sql_pending_count);
        if ($stmt_pending_count) {
            $stmt_pending_count->bind_param("i", $logged_in_tenant_id);
            $stmt_pending_count->execute();
            $result_pending_count = $stmt_pending_count->get_result();
            $row_pending_count = $result_pending_count->fetch_assoc();
            $pending_badge_count = $row_pending_count['count'];
        }
    } catch (Throwable $e) {
        error_log("Error fetching pending count for T_UnitProfile: " . $e->getMessage());
    } finally {
        if ($stmt_pending_count) {
            $stmt_pending_count->close();
        }
    }

// Handle form submission for updating tenant profile (no changes needed here)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $new_first_name = trim($_POST['first_name'] ?? '');
    $new_last_name = trim($_POST['last_name'] ?? '');
    $new_phone_number = trim($_POST['phone_number'] ?? '');
    $new_email_address = filter_var(trim($_POST['email_address'] ?? ''), FILTER_SANITIZE_EMAIL);

    $update_errors = [];

    if (empty($new_first_name)) $update_errors[] = "First Name cannot be empty.";
    if (empty($new_last_name)) $update_errors[] = "Last Name cannot be empty.";
    if (empty($new_phone_number)) $update_errors[] = "Phone Number cannot be empty.";
    if (empty($new_email_address) || !filter_var($new_email_address, FILTER_VALIDATE_EMAIL)) $update_errors[] = "A valid Email Address is required.";

    $new_known_face_path_1 = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['profile_image']['tmp_name'];
        $fileName = $_FILES['profile_image']['name'];
        $fileSize = $_FILES['profile_image']['size'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $uploadDir = './uploads/tenant_profile_pics/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $newFileName = uniqid('tenant_') . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $new_known_face_path_1 = $destPath;
            } else {
                $update_errors[] = "Error uploading profile image.";
            }
        } else {
            $update_errors[] = "Invalid image format. Only JPG, JPEG, PNG, GIF allowed.";
        }
    }


    if (empty($update_errors)) {
        if (isset($conn) && $conn instanceof mysqli) {
            $update_sql = "UPDATE tenants SET first_name = ?, last_name = ?, phone_number = ?, email = ?";
            if ($new_known_face_path_1) {
                $update_sql .= ", known_face_path_1 = ?";
            }
            $update_sql .= " WHERE tenant_id = ?";

            $stmt_update = $conn->prepare($update_sql);
            if ($stmt_update) {
                if ($new_known_face_path_1) {
                    $stmt_update->bind_param("sssssi", $new_first_name, $new_last_name, $new_phone_number, $new_email_address, $new_known_face_path_1, $logged_in_tenant_id);
                } else {
                    $stmt_update->bind_param("ssssi", $new_first_name, $new_last_name, $new_phone_number, $new_email_address, $logged_in_tenant_id);
                }

                if ($stmt_update->execute()) {
                    $_SESSION['flash_message'] = "Profile updated successfully!";
                    $_SESSION['flash_message_type'] = "success";
                } else {
                    $_SESSION['flash_message'] = "Error updating profile: " . $stmt_update->error;
                    $_SESSION['flash_message_type'] = "danger";
                    error_log("Error updating tenant profile: " . $stmt_update->error);
                }
                $stmt_update->close();
            } else {
                $_SESSION['flash_message'] = "Database error: Could not prepare update statement.";
                $_SESSION['flash_message_type'] = "danger";
                error_log("Failed to prepare update statement for T_UnitProfile: " . $conn->error);
            }
        } else {
            $_SESSION['flash_message'] = "Database connection error. Unable to update profile.";
            $_SESSION['flash_message_type'] = "danger";
        }
    } else {
        $_SESSION['flash_message'] = implode("<br>", $update_errors);
        $_SESSION['flash_message_type'] = "danger";
    }
    header("Location: T_UnitProfile.php"); // PRG pattern
    exit();
}

// Fetch Tenant and Unit Data
// This logic is mostly fine, assuming $conn is correctly established
if (isset($conn) && $conn instanceof mysqli) {
    $stmt_tenant_data = null;
    try {
        // MODIFIED: Added current_status and last_status_update
        $sql_tenant_data = "SELECT t.tenant_id, t.first_name, t.last_name, t.phone_number, t.email, t.known_face_path_1, u.unit_number, t.current_status, t.last_status_update
                            FROM tenants t
                            JOIN units u ON t.unit_id = u.unit_id
                            WHERE t.tenant_id = ? LIMIT 1";
        $stmt_tenant_data = $conn->prepare($sql_tenant_data);
        if ($stmt_tenant_data) {
            $stmt_tenant_data->bind_param("i", $logged_in_tenant_id);
            $stmt_tenant_data->execute();
            $result_tenant_data = $stmt_tenant_data->get_result();

            if ($result_tenant_data->num_rows === 1) {
                $tenant_data = $result_tenant_data->fetch_assoc();
                $logged_in_tenant_id = e($tenant_data['tenant_id']);
                $tenant_first_name = e($tenant_data['first_name']);
                $tenant_last_name = e($tenant_data['last_name']);
                $tenant_phone_number = e($tenant_data['phone_number']);
                $tenant_email = e($tenant_data['email']);
                // Use realpath for file_exists check to be more robust
                $tenant_profile_pic_path_fs = !empty($tenant_data['known_face_path_1']) ? realpath($tenant_data['known_face_path_1']) : false;
                $tenant_profile_pic_path = ($tenant_profile_pic_path_fs && file_exists($tenant_profile_pic_path_fs)) ? e($tenant_data['known_face_path_1']) : 'https://placehold.co/150x150/A0A0A0/FFFFFF?text=Owner+Image';
                $unit_number = e($tenant_data['unit_number']);
                // NEW: Fetch and format status data
                $tenant_current_status = e($tenant_data['current_status'] ?? 'N/A');
                if (!empty($tenant_data['last_status_update'])) {
                    $dt = new DateTime($tenant_data['last_status_update']);
                    $tenant_last_status_update = e($dt->format('M d, Y h:i A'));
                } else {
                    $tenant_last_status_update = 'Never updated';
                }

            } else {
                $_SESSION['flash_message'] = "Tenant profile not found.";
                $_SESSION['flash_message_type'] = "danger";
                header("Location: login.php");
                exit();
            }
        } else {
            error_log("Failed to prepare tenant data statement for T_UnitProfile: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error fetching tenant data for T_UnitProfile: " . $e->getMessage());
    } finally {
        if ($stmt_tenant_data) {
            $stmt_tenant_data->close();
        }
    }

    // Fetch Permanent Unit Members (from 'members' table) - UPDATED TO MATCH REGISTERED MEMBERS DESIGN
    $stmt_permanent_members = null;
    try {
        $sql_permanent_members = "SELECT member_id, full_name, phone_number, relationship_to_tenant, profile_pic_path
                                  FROM members
                                  WHERE tenant_id = ? ORDER BY full_name ASC";
        $stmt_permanent_members = $conn->prepare($sql_permanent_members);
        if ($stmt_permanent_members) {
            $stmt_permanent_members->bind_param("i", $logged_in_tenant_id);
            $stmt_permanent_members->execute();
            $result_permanent_members = $stmt_permanent_members->get_result();

            if ($result_permanent_members->num_rows > 0) {
                $permanent_members_html = "<ul class='permanent-members-list'>";
                while ($member = $result_permanent_members->fetch_assoc()) {
                    $initials = '';
                    if (!empty($member['full_name'])) {
                        $name_parts = explode(' ', $member['full_name']);
                        foreach ($name_parts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    } else {
                        $initials = 'M';
                    }
                    // Use realpath for file_exists check
                    $member_photo_src_fs = !empty($member['profile_pic_path']) ? realpath($member['profile_pic_path']) : false;
                    $member_photo_src = ($member_photo_src_fs && file_exists($member_photo_src_fs)) ? e($member['profile_pic_path']) : 'https://placehold.co/50x50/A0A0A0/FFFFFF?text=' . $initials;

                    $permanent_members_html .= "
                        <li class='permanent-member-item' data-member-id='" . e($member['member_id']) . "'>
                            <div class='member-list-avatar'><img src='{$member_photo_src}' alt='Member Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/50x50/A0A0A0/FFFFFF?text=" . $initials . "';\"></div>
                            <div class='member-list-info'>
                                <p><strong>Name:</strong> " . e($member['full_name']) . "</p>
                                <p><strong>Phone:</strong> " . e($member['phone_number']) . "</p>
                                <p><strong>Relationship:</strong> " . e($member['relationship_to_tenant']) . "</p>
                            </div>
                            <div class='member-list-actions'>
                                <button class='btn btn-remove btn-small' onclick=\"showConfirmModal('Remove Member', 'Are you sure you want to remove " . e($member['full_name']) . " as a permanent member? This action cannot be undone.', () => removePermanentMember('" . e($member['member_id']) . "'), false)\">Remove</button>
                            </div>
                        </li>
                    ";
                }
                $permanent_members_html .= "</ul>";
            } else {
                $permanent_members_html = "
                    <div class='empty-members-state' id='emptyPermanentMembersState'>
                        <h3>No Permanent Members Added Yet</h3>
                        <p>Permanent members are typically those added by an admin or converted from visitors.</p>
                    </div>
                ";
            }
        } else {
            error_log("Failed to prepare permanent members statement: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error fetching permanent members: " . $e->getMessage());
    } finally {
        if ($stmt_permanent_members) {
            $stmt_permanent_members->close();
        }
    }

    // Fetch Registered Members (from 'addedmembers' table)
    $stmt_registered_members = null;
    try {
        $sql_registered_members = "SELECT added_member_id, full_name, username, phone_number, relationship_to_tenant, known_face_path_2
                                   FROM addedmembers
                                   WHERE tenant_id = ? ORDER BY full_name ASC";
        $stmt_registered_members = $conn->prepare($sql_registered_members);
        if ($stmt_registered_members) {
            $stmt_registered_members->bind_param("i", $logged_in_tenant_id);
            $stmt_registered_members->execute();
            $result_registered_members = $stmt_registered_members->get_result();

            if ($result_registered_members->num_rows > 0) {
                $registered_members_html = "<ul class='registered-members-list'>";
                while ($member = $result_registered_members->fetch_assoc()) {
                    $initials = '';
                    if (!empty($member['full_name'])) {
                        $name_parts = explode(' ', $member['full_name']);
                        foreach ($name_parts as $part) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    } else {
                        $initials = 'R';
                    }
                    // Crucial Change: Use realpath for file_exists check to resolve absolute path
                    $member_photo_src_fs = !empty($member['known_face_path_2']) ? realpath($member['known_face_path_2']) : false;

                    // If file exists, use the original stored path for the browser (which understands web paths)
                    // Otherwise, fallback to placeholder
                    $member_photo_src = ($member_photo_src_fs && file_exists($member_photo_src_fs)) ? e($member['known_face_path_2']) : 'https://placehold.co/50x50/A0A0A0/FFFFFF?text=' . $initials;

                    $registered_members_html .= "
                        <li class='registered-member-item' data-member-id='" . e($member['added_member_id']) . "'>
                            <div class='member-list-avatar'><img src='{$member_photo_src}' alt='Member Photo' onerror=\"this.onerror=null;this.src='https://placehold.co/50x50/A0A0A0/FFFFFF?text=" . $initials . "';\"></div>
                            <div class='member-list-info'>
                                <p><strong>Name:</strong> " . e($member['full_name']) . "</p>
                                <p><strong>Username:</strong> " . e($member['username']) . "</p>
                            </div>
                            <div class='member-list-actions'>
                                <button class='btn btn-remove btn-small' onclick=\"showConfirmModal('Remove Registered Member', 'Are you sure you want to remove " . e($member['full_name']) . " from registered members? This action cannot be undone.', () => removeRegisteredMember('" . e($member['added_member_id']) . "'), false)\">Remove</button>
                            </div>
                        </li>
                    ";
                }
                $registered_members_html .= "</ul>";
            } else {
                $registered_members_html = "
                    <div class='empty-members-state' id='emptyRegisteredMembersState'>
                        <h3>No Registered Members Yet</h3>
                        <p>Members registered through the 'Member Registration' page will appear here.</p>
                        <p><a href='T_MemberRegistration.php' class='btn btn-neutral'>Register New Member</a></p>
                    </div>
                ";
            }
        } else {
            error_log("Failed to prepare registered members statement: " . $conn->error);
        }
    } catch (Throwable $e) {
        error_log("Error fetching registered members: " . $e->getMessage());
    } finally {
        if ($stmt_registered_members) {
            $stmt_registered_members->close();
        }
    }

    // The 'Visitors' section and its data fetching logic will remain commented out or removed
    // as per the request to remove the tab.
    // If you need the data for other purposes (e.g., T_VisitorsList.php), keep the fetch logic
    // but simply don't generate the HTML for it here.

    // No need to fetch visitors here if the tab and content are removed
    // $stmt_visitors = null;
    // try {
    //     $sql_visitors = "SELECT visitor_id, full_name, phone_number, purpose_of_visit, status, visit_timestamp, processed_at, profile_pic_path
    //                      FROM visitors
    //                      WHERE host_tenant_id = ? ORDER BY visit_timestamp DESC";
    //     $stmt_visitors = $conn->prepare($sql_visitors);
    //     if ($stmt_visitors) {
    //         $stmt_visitors->bind_param("i", $logged_in_tenant_id);
    //         $stmt_visitors->execute();
    //         $result_visitors = $stmt_visitors->get_result();

    //         if ($result_visitors->num_rows > 0) {
    //             while ($visitor = $result_visitors->fetch_assoc()) {
    //                 $initials = '';
    //                 if (!empty($visitor['full_name'])) {
    //                     $name_parts = explode(' ', $visitor['full_name']);
    //                     foreach ($name_parts as $part) {
    //                         $initials .= strtoupper(substr($part, 0, 1));
    //                     }
    //                 } else {
    //                     $initials = 'V';
    //                 }
    //                 $status_class = strtolower($visitor['status']);
    //                 $timestamp_display = '';
    //                 $delete_btn_html = "";

    //                 // Use realpath for file_exists check
    //                 $visitor_photo_src_fs = !empty($visitor['profile_pic_path']) ? realpath($visitor['profile_pic_path']) : false;
    //                 $visitor_photo_src = ($visitor_photo_src_fs && file_exists($visitor_photo_src_fs)) ? e($visitor['profile_pic_path']) : 'https://placehold.co/50x50/A0A0A0/FFFFFF?text=' . $initials;

    //                 if ($status_class === 'deleted') {
    //                     if (!empty($visitor['processed_at'])) {
    //                         $timestamp_display = "<p class='removed-timestamp'><strong>Removed:</strong> " . date("Y-m-d H:i:s", strtotime($visitor['processed_at'])) . "</p>";
    //                     }
    //                     $delete_btn_html = ""; // No button for already deleted visitors
    //                 } else {
    //                     $delete_btn_html = "<button class='btn btn-remove btn-small' onclick=\"showConfirmModal('Remove Visitor', 'Are you sure you want to delete this visitor record for " . e($visitor['full_name']) . "? This action cannot be undone.', () => softDeleteVisitor('" . e($visitor['visitor_id']) . "'), false)\">Delete</button>";
    //                     $timestamp_display = ""; // No timestamp if not deleted
    //                 }

    //                 $visitors_list_html .= "
    //                     <div class='visitor-item' data-visitor-id='" . e($visitor['visitor_id']) . "'>
    //                         <div class='visitor-avatar'><img src='{$visitor_photo_src}' alt='Visitor Photo'></div>
    //                         <div class='visitor-info'>
    //                             <h4>" . e($visitor['full_name']) . "</h4>
    //                             <p><strong>Phone:</strong> " . e($visitor['phone_number']) . "</p>
    //                             <p><strong>Purpose:</strong> " . e($visitor['purpose_of_visit']) . "</p>
    //                             {$timestamp_display}
    //                         </div>
    //                         <span class='visitor-status {$status_class}'>" . e(ucfirst($visitor['status'])) . "</span>
    //                         <div class='visitor-actions'>
    //                             {$delete_btn_html}
    //                         </div>
    //                     </div>
    //                 ";
    //             }
    //         } else {
    //             $visitors_list_html = "
    //                 <div class='empty-members-state'>
    //                     <h3>No Visitor Records Found</h3>
    //                     <p>Your unit has not received any visitors yet, or their records are not available.</p>
    //                 </div>
    //             ";
    //         }
    //     }
    // } catch (Throwable $e) {
    //     error_log("Error fetching visitors: " . $e->getMessage());
    //     $visitors_list_html = "<div class='empty-members-state'><h3>Error loading visitors.</h3><p>An unexpected error occurred.</p></div>";
    // } finally {
    //     if ($stmt_visitors) {
    //         $stmt_visitors->close();
    //     }
    // }


    // Fetch Activity Log (from 'tenant_activity_log' table)
    $stmt_activity_log = null;
    try {
        $sql_activity_log = "SELECT action_type, description, timestamp
                             FROM tenant_activity_log
                             WHERE tenant_id = ? ORDER BY timestamp DESC LIMIT 20";
        $stmt_activity_log = $conn->prepare($sql_activity_log);
        if ($stmt_activity_log) {
            $stmt_activity_log->bind_param("i", $logged_in_tenant_id);
            $stmt_activity_log->execute();
            $result_activity_log = $stmt_activity_log->get_result();

            if ($result_activity_log->num_rows > 0) {
                $activity_log_html = "<ul class='activity-list'>";
                while ($log_entry = $result_activity_log->fetch_assoc()) {
                    $action_display = str_replace('_', ' ', ucfirst($log_entry['action_type']));
                    $timestamp_formatted = date("Y-m-d H:i:s", strtotime($log_entry['timestamp']));
                    $activity_log_html .= "
                        <li>
                            <span class='activity-action'><strong>" . e($action_display) . ":</strong> " . e($log_entry['description']) . "</span>
                            <span class='activity-time'>" . e($timestamp_formatted) . "</span>
                        </li>
                    ";
                }
                $activity_log_html .= "</ul>";
            } else {
                $activity_log_html = "
                    <div class='empty-members-state'>
                        <h3>No Activity Log Entries</h3>
                        <p>Check-in/out and other tenant activities will appear here.</p>
                    </div>
                ";
            }
        }
    } catch (Throwable $e) {
        error_log("Error fetching activity log: " . $e->getMessage());
        $activity_log_html = "<div class='empty-members-state'><h3>Error loading activity log.</h3><p>An unexpected error occurred.</p></div>";
    } finally {
        if ($stmt_activity_log) {
            $stmt_activity_log->close();
        }
    }

} else {
    $permanent_members_html = "<div class='empty-members-state'><h3>Database connection error.</h3><p>Could not load permanent members.</p></div>";
    $registered_members_html = "<div class='empty-members-state'><h3>Database connection error.</h3><p>Could not load registered members.</p></div>";
    $visitors_list_html = "<div class='empty-members-state'><h3>Database connection error.</h3><p>Could not load visitors.</p></div>"; // Still here for consistency, but won't be used
    $activity_log_html = "<div class='empty-members-state'><h3>Database connection error.</h3><p>Could not load activity log.</p></div>";
    error_log("Database connection not available in T_UnitProfile.php.");
}


// Flash Message handling
$flash_message = '';
$flash_message_type = '';
if (isset($_SESSION['flash_message'])) {
    $flash_message = e($_SESSION['flash_message']);
    $flash_message_type = e($_SESSION['flash_message_type'] ?? 'info');
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verifaced: Unit Profile</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    /* Compact Professional Khaki Theme CSS */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    :root {
      /* Khaki Color Palette */
      --khaki-primary: #BDB76B;
      --khaki-secondary: #F0E68C;
      --khaki-dark: #8B864E;
      --khaki-light: #F5F5DC;
      --khaki-accent: #DDD3A0;
      --khaki-muted: #E6E2C3;
      
      /* Custom Action Colors */
      --action-accept: #BDB76B;
      --action-accept-dark: #8B864E;
      --action-reject: #DC3545;
      --action-reject-dark: #C82333;
      
      /* New color for the neutral logout button (matching image) */
      --button-neutral-bg: #F5F0E1;
      --button-neutral-text: #8B7D6B;
      --button-neutral-border: #E6DCC6;
      --button-neutral-hover-bg: #EAE5D6;
      --button-neutral-hover-text: #6B5B4F;
      
      /* Neutral Colors */
      --white: #FFFFFF;
      --off-white: #FEFEFE;
      --gray-100: #F8F9FA;
      --gray-200: #E9ECEF;
      --gray-300: #DEE2E6;
      --gray-400: #CED4DA;
      --gray-500: #ADB5BD;
      --gray-600: #6C757D;
      --gray-700: #495057;
      --gray-800: #343A40;
      --gray-900: #212529;
      
      /* Status Colors */
      --success: #28A745;
      --danger: #DC3545;
      --warning: #FFC107;
      --info: #17A2B8;
      
      /* Compact Shadows */
      --shadow-sm: 0 1px 3px rgba(139, 134, 78, 0.1);
      --shadow-md: 0 2px 8px rgba(139, 134, 78, 0.12);
      --shadow-lg: 0 4px 16px rgba(139, 134, 78, 0.15);
      --shadow-xl: 0 6px 24px rgba(139, 134, 78, 0.18);
      
      /* Compact Border Radius */
      --radius-sm: 4px;
      --radius-md: 8px;
      --radius-lg: 12px;
      --radius-xl: 16px;
      
      /* Transitions */
      --transition-fast: 0.15s ease;
      --transition-normal: 0.25s ease;
      --transition-slow: 0.4s ease;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: linear-gradient(135deg, var(--khaki-light) 0%, var(--khaki-muted) 50%, var(--khaki-accent) 100%);
      min-height: 100vh;
      color: var(--gray-800);
      line-height: 1.5;
      font-size: 14px;
    }

    .container {
      /* display: flex;  REMOVE THIS */
      /* min-height: 100vh; REMOVE THIS */
    }

    /* SIDEBAR STYLES */
    .sidebar {
      width: 240px;
      background: linear-gradient(180deg, var(--white) 0%, var(--off-white) 100%);
      backdrop-filter: blur(20px);
      padding: 20px 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      box-shadow: var(--shadow-lg);
      position: fixed; /* Changed from relative to fixed */
      top: 0;          /* Added */
      left: 0;         /* Added */
      height: 100vh;   /* Added to make it full height */
      border-right: 1px solid var(--gray-200);
    }

    .sidebar::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .logo-circle {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--white);
      font-weight: 700;
      font-size: 18px;
      margin-bottom: 12px;
      box-shadow: var(--shadow-md);
      border: 2px solid var(--white);
      transition: var(--transition-normal);
    }

    .logo-circle:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-lg);
    }

    .logo-circle::before {
      content: "V";
    }

    .sidebar h2 {
      color: var(--gray-800);
      font-size: 22px;
      font-weight: 700;
      margin-bottom: 32px;
      text-align: center;
      letter-spacing: -0.3px;
    }

    .sidebar nav ul {
      list-style: none;
      width: 100%;
      padding: 0 16px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .sidebar nav li {
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
    }

    .sidebar nav li a {
      text-decoration: none;
      color: var(--gray-600);
      display: flex;
      align-items: center;
      gap: 12px;
      width: 100%;
      padding: 12px 16px;
      font-weight: 500;
      font-size: 13px;
      transition: var(--transition-normal);
      border-radius: var(--radius-md);
    }

    .sidebar nav li:hover {
      transform: translateX(3px);
    }

    .sidebar nav li:hover a {
      color: var(--khaki-dark);
      background: linear-gradient(135deg, var(--khaki-light) 0%, var(--khaki-muted) 100%);
    }

    .sidebar nav li.active a {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      color: var(--white);
      font-weight: 600;
      box-shadow: var(--shadow-sm);
    }

    .sidebar nav li.active {
      transform: translateX(3px);
    }

    .sidebar nav li.active a:hover {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      transform: none;
    }

    .badge {
      background: var(--danger);
      color: var(--white);
      border-radius: 8px;
      padding: 2px 6px;
      font-size: 10px;
      font-weight: 600;
      margin-left: auto;
      min-width: 16px;
      text-align: center;
      box-shadow: var(--shadow-sm);
    }

    .logout {
      position: absolute;
      bottom: 20px;
      left: 16px;
      right: 16px;
      padding: 12px;
      background: var(--button-neutral-bg);
      color: var(--button-neutral-text);
      text-decoration: none;
      border-radius: var(--radius-md);
      text-align: center;
      font-weight: 600;
      font-size: 13px;
      transition: var(--transition-normal);
      border: 1px solid var(--button-neutral-border);
      box_shadow: 0 2px 5px rgba(0,0,0,0.05);
    }

    .logout:hover {
      background: var(--button-neutral-hover-bg);
      color: var(--button-neutral-hover-text);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    /* MAIN CONTENT STYLES */
    .main-content {
      flex: 1;
      padding: 24px;
      overflow-y: auto;
      background: linear-gradient(135deg,
        rgba(245, 245, 220, 0.3) 0%,
        rgba(230, 226, 195, 0.3) 50%,
        rgba(221, 211, 160, 0.3) 100%);
      margin-left: 240px; /* Added: This creates space for the fixed sidebar */
    }

    .content-header {
      margin-bottom: 24px;
      text-align: center;
    }

    .content-header h1 {
      color: var(--gray-800);
      font-size: 28px;
      font-weight: 800;
      margin-bottom: 8px;
      letter-spacing: -0.5px;
      text-shadow: 0 1px 3px rgba(139, 134, 78, 0.1);
    }

    .content-header p {
      color: var(--gray-600);
      font-size: 14px;
      font-weight: 400;
    }

    /* UNIT PROFILE CARD */
    .unit-profile-card {
      background: var(--white);
      border-radius: var(--radius-lg);
      padding: 24px;
      box-shadow: var(--shadow-md);
      border: 1px solid var(--gray-200);
      transition: var(--transition-normal);
      position: relative;
      overflow: hidden;
      margin-bottom: 24px;
    }

    .unit-profile-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .unit-profile-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
    }

    .profile-header {
      display: flex;
      align-items: flex-start;
      gap: 24px;
      margin-bottom: 24px;
    }

    .profile-image-container {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
    }

    .profile-image {
      width: 120px;
      height: 120px;
      border-radius: var(--radius-md);
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--white);
      font-size: 14px;
      font-weight: 600;
      text-align: center;
      box-shadow: var(--shadow-md);
      border: 3px solid var(--white);
      position: relative;
      overflow: hidden;
      transition: var(--transition-normal);
    }

    .profile-image:hover {
      transform: scale(1.01);
      box-shadow: var(--shadow-lg);
    }

    .profile-image img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: calc(var(--radius-md) - 3px);
    }

    .profile-details {
      flex: 1;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
    }

    .detail-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .detail-label {
      font-size: 11px;
      color: var(--gray-600);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 2px;
    }

    .detail-input {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid var(--gray-300);
      border-radius: var(--radius-md);
      font-size: 14px;
      font-weight: 500;
      color: var(--gray-800);
      background: var(--white);
      transition: var(--transition-normal);
      font-family: inherit;
    }

    .detail-input:focus {
      outline: none;
      border-color: var(--khaki-primary);
      background: var(--off-white);
      box-shadow: 0 0 0 3px rgba(189, 183, 107, 0.1);
      transform: translateY(-1px);
    }

    .detail-input::placeholder {
      color: var(--gray-400);
      font-style: italic;
    }

    .detail-input:read-only {
      background: var(--gray-100);
      color: var(--gray-600);
      cursor: not-allowed;
      border-color: var(--gray-200);
    }

    .action-buttons {
      display: flex;
      justify-content: center;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 16px;
    }

    .btn {
      padding: 10px 20px;
      border: none;
      border-radius: var(--radius-md);
      font-size: 14px;
      font-weight: 600;
      font-family: inherit;
      cursor: pointer;
      transition: var(--transition-normal);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      min-width: 100px;
      box-shadow: var(--shadow-sm);
    }

    .btn-save {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      color: var(--white);
    }

    .btn-save:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
      background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
    }

    .btn-neutral {
      background: var(--button-neutral-bg);
      color: var(--button-neutral-text);
      border: 1px solid var(--button-neutral-border);
    }

    .btn-neutral:hover {
      background: var(--button-neutral-hover-bg);
      color: var(--button-neutral-hover-text);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    .btn-in {
      background: #8B864E;
      color: var(--white);
      border: 1px solid #8B864E;
    }

    .btn-in:hover {
      background: #6B6B3A;
      transform: translateY(-1px);
      box-shadow: var(--shadow-sm);
    }

    .btn-out {
      background: #B8860B;
      color: var(--white);
      border: 1px solid #B8860B;
    }

    .btn-out:hover {
      background: #996F08;
      transform: translateY(-1px);
      box-shadow: var(--shadow-sm);
    }


    /* TABS SECTION STYLES */
    .tabs-container {
        margin-top: 30px; /* Space from unit profile card */
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-md);
        border: 1px solid var(--gray-200);
        overflow: hidden;
    }

    .tabs-header {
        display: flex;
        justify-content: flex-start;
        align-items: flex-end; /* Align tabs to the bottom of the header area */
        border-bottom: 2px solid var(--gray-300);
        background: linear-gradient(180deg, var(--gray-100) 0%, var(--white) 100%);
        padding-top: 15px; /* Padding above tabs */
        padding-left: 20px;
    }

    .tab-button {
        background: transparent;
        border: none;
        padding: 12px 20px;
        font-size: 15px;
        font-weight: 600;
        color: var(--gray-600);
        cursor: pointer;
        transition: all 0.25s ease;
        position: relative;
        top: 2px; /* Lift slightly to create overlap effect */
        border-top-left-radius: var(--radius-md);
        border-top-right-radius: var(--radius-md);
        margin-right: 5px; /* Space between tabs */
        border-bottom: 3px solid transparent; /* For active indicator */
    }

    .tab-button:hover:not(.active) {
        color: var(--khaki-dark);
        background: var(--khaki-light);
        border-color: var(--khaki-muted);
    }

    .tab-button.active {
        color: var(--khaki-dark);
        background: var(--khaki-light);
        border-bottom: 3px solid var(--khaki-primary);
        z-index: 1; /* Bring active tab to front */
        box-shadow: var(--shadow-sm);
        padding-bottom: 15px; /* Adjust padding to make it visually "stand out" */
    }
    /* Add a subtle shadow for the active tab to make it pop out more */
    .tab-button.active {
      box-shadow: 0 -2px 8px rgba(0,0,0,0.05);
    }


    .tab-content {
        display: none;
        padding: 24px;
    }

    .tab-content.active {
        display: block;
    }

    /* Common Styles for Member Lists - UPDATED TO USE SAME STYLING FOR BOTH PERMANENT AND REGISTERED */
    .permanent-members-list,
    .registered-members-list {
        list-style: none;
        padding: 0;
    }

    .permanent-member-item,
    .registered-member-item {
        display: flex;
        align-items: center;
        padding: 15px 0;
        border-bottom: 1px solid var(--gray-200);
        transition: all 0.25s ease;
    }

    .permanent-member-item:last-child,
    .registered-member-item:last-child {
        border-bottom: none;
    }

    .permanent-member-item:hover,
    .registered-member-item:hover {
        background-color: var(--gray-100);
        transform: translateX(3px); /* Subtle slide on hover */
    }

    .member-list-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%; /* Make it circular */
        background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        font-size: 20px;
        font-weight: 700;
        margin-right: 15px;
        box-shadow: var(--shadow-sm);
        border: 2px solid var(--white);
        overflow: hidden;
    }

    .member-list-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover; /* Ensures the image fills the circular space */
    }

    .member-list-info {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .member-list-info p {
        margin: 2px 0;
        font-size: 14px;
        color: var(--gray-700);
    }

    .member-list-info p strong {
        font-weight: 600;
        color: var(--gray-800);
        margin-right: 5px;
    }

    .member-list-actions {
        margin-left: auto;
    }

    .member-list-actions .btn-small {
        padding: 6px 12px;
        font-size: 12px;
        min-width: 80px;
    }

    /* REMOVED OLD MEMBER CARD STYLES - no longer needed since both tabs use list style */

    .visitors-list { /* Specific styles for Visitors tab */
        display: grid;
        grid-template-columns: repeat(3, 1fr); /* Default 3 columns */
        gap: 20px;
    }

    .btn-remove {
      background: linear-gradient(135deg, var(--action-reject) 0%, var(--action-reject-dark) 100%);
      color: var(--white);
      border: 1px solid var(--action-reject);
    }

    .btn-remove:hover {
      background: linear-gradient(135deg, var(--action-reject-dark) 0%, #B22222 100%);
      color: var(--white);
      transform: translateY(-1px);
      box-shadow: var(--shadow-sm);
    }

    /* Visitor Item Specific Styles (re-applied or adjusted for clarity) */
    .visitor-item {
        background: var(--white); /* Apply background like other cards */
        border-radius: var(--radius-lg); /* Rounded corners */
        box-shadow: var(--shadow-md); /* Subtle shadow */
        border: 1px solid var(--gray-200); /* Border */
        transition: var(--transition-normal); /* Transition for hover */
        position: relative;
        overflow: hidden;

        display: flex; /* Maintain horizontal layout for avatar, info, actions */
        align-items: center;
        gap: 15px;
        padding: 15px;
    }

    .visitor-item::before { /* Add top accent bar */
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }
    .visitor-item:hover {
        transform: translateY(-2px);
        box-shadow: var(--shadow-lg);
    }

    .visitor-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%; /* Ensure circular shape */
        background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--white);
        font-size: 20px;
        font-weight: 700;
        margin-right: 0; /* Reset */
        margin-bottom: 0; /* Reset */
        box-shadow: var(--shadow-sm);
        border: 2px solid var(--white);
        overflow: hidden;
        flex-shrink: 0; /* Prevent shrinking when space is tight */
    }
    .visitor-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .visitor-info {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }
    .visitor-info h4 {
        font-size: 16px;
        margin-bottom: 5px;
        color: var(--gray-800);
        font-weight: 600;
    }
    .visitor-info p {
        margin: 2px 0;
        font-size: 13px;
        color: var(--gray-700);
    }
    .visitor-info p strong {
        font-weight: 600;
        color: var(--gray-800);
        margin-right: 5px;
    }

    .visitor-status {
        font-size: 11px;
        font-weight: 600;
        padding: 4px 8px;
        border-radius: var(--radius-sm);
        text-transform: uppercase;
        margin-left: auto; /* Push to the right for row layout */
        flex-shrink: 0; /* Prevent shrinking */
    }
    .visitor-status.pending { background: var(--warning); color: var(--gray-900); }
    .visitor-status.accepted { background: var(--success); color: var(--white); }
    .visitor-status.rejected { background: var(--danger); color: var(--white); }
    .visitor-status.deleted { /* New style for deleted status */
        background: var(--gray-300);
        color: var(--gray-600);
    }


    .visitor-actions {
        margin-top: 0;
        margin-left: 10px; /* Space from status */
        flex-direction: column;
        gap: 5px;
        width: auto;
        align-items: flex-end; /* Align buttons to the right edge */
        flex-shrink: 0; /* Prevent shrinking */
    }
    .visitor-actions .btn-small {
        padding: 6px 12px;
        font-size: 12px;
        min-width: 60px;
        max-width: none;
    }


    /* ACTIVITY LOG STYLES */
    .activity-list {
        list-style: none;
        padding: 0;
        background: var(--white);
        border-radius: var(--radius-lg);
        border: 1px solid var(--gray-200);
        box-shadow: var(--shadow-md);
    }

    .activity-list li {
        padding: 15px 20px;
        border-bottom: 1px solid var(--gray-200);
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: background-color 0.2s ease;
    }
    .activity-list li:last-child {
        border-bottom: none;
    }
    .activity-list li:hover {
        background-color: var(--gray-100);
    }

    .activity-action {
        font-weight: 600;
        color: var(--gray-800);
    }
    .activity-time {
        font-size: 12px;
        color: var(--gray-600);
    }


    /* EMPTY STATE */
    .empty-members-state {
      text-align: center;
      padding: 48px 20px;
      color: var(--gray-600);
      background: linear-gradient(135deg,
        rgba(255, 255, 255, 0.8) 0%,
        rgba(245, 245, 220, 0.6) 100%);
      border-radius: var(--radius-lg);
      margin-top: 16px;
      border: 2px dashed var(--gray-300);
    }

    .empty-members-state h3 {
      font-size: 20px;
      margin-bottom: 12px;
      color: var(--gray-700);
      font-weight: 600;
    }

    .empty-members-state p {
      font-size: 14px;
      opacity: 0.8;
      line-height: 1.5;
    }
    .empty-members-state .btn {
        margin-top: 15px;
        padding: 10px 18px;
        font-size: 13px;
    }


    /* SUCCESS/FLASH MESSAGES */
    .success-message {
      display: none;
      position: fixed;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      background: linear-gradient(135deg, rgba(40, 167, 69, 0.9) 0%, rgba(40, 167, 69, 0.7) 100%);
      color: var(--white);
      padding: 14px 20px;
      border-radius: var(--radius-md);
      font-size: 14px;
      font-weight: 600;
      box-shadow: var(--shadow-lg);
      z-index: 1000;
      opacity: 0;
      visibility: hidden;
      transition: var(--transition-normal);
      border: 1px solid rgba(40, 167, 69, 0.8);
      max-width: 400px;
    }

    .success-message.show {
      opacity: 1;
      visibility: visible;
      display: block;
    }

    /* MODAL STYLES */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      overflow: auto;
      background-color: rgba(33, 37, 41, 0.5);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      visibility: hidden;
      transition: var(--transition-normal);
    }

    .modal.show {
      opacity: 1;
      visibility: visible;
      display: flex;
    }

    .modal-content {
      background: var(--white);
      margin: auto;
      padding: 24px;
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-xl);
      max-width: 360px;
      width: 90%;
      text-align: center;
      transform: translateY(-20px);
      transition: var(--transition-normal);
      position: relative;
    }

    .modal.show .modal-content {
      transform: translateY(0);
    }

    .modal-content::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
    }

    .modal-content h3 {
      margin-bottom: 12px;
      color: var(--gray-800);
      font-size: 18px;
      font-weight: 700;
    }

    .modal-content p {
      margin-bottom: 24px;
      color: var(--gray-600);
      font-size: 14px;
      line-height: 1.4;
    }

    .modal-buttons {
      display: flex;
      justify-content: center;
      gap: 12px;
    }

    .modal-buttons .btn-cancel,
    .modal-buttons .btn-confirm {
      padding: 10px 20px;
      border: none;
      border-radius: var(--radius-md);
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-normal);
      box-shadow: var(--shadow-sm);
    }

    .modal-buttons .btn-confirm {
      background: linear-gradient(135deg, var(--khaki-primary) 0%, var(--khaki-dark) 100%);
      color: var(--white);
      border: 1px solid var(--khaki-primary);
    }

    .modal-buttons .btn-confirm.danger {
        background: var(--action-reject);
        color: var(--white);
        border-color: var(--action-reject);
    }

    .modal-buttons .btn-confirm:hover {
      background: linear-gradient(135deg, var(--khaki-dark) 0%, #6B6B23 100%);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }

    .modal-buttons .btn-confirm.danger:hover {
        background: linear-gradient(135deg, var(--action-reject-dark) 0%, #B22222 100%);
    }

    .modal-buttons .btn-cancel {
      background: var(--gray-200);
      color: var(--gray-700);
      border: 1px solid var(--gray-300);
      padding: 10px 20px;
      font-size: 13px;
      font-weight: 600;
      border-radius: var(--radius-md);
      cursor: pointer;
      transition: var(--transition-normal);
      min-width: 80px;
      box-shadow: var(--shadow-sm);
      font-family: inherit;
    }

    .modal-buttons .btn-cancel:hover {
      background: var(--gray-300);
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    /* RESPONSIVE DESIGN */
    @media (max-width: 1024px) {
      .sidebar {
        width: 200px;
      }
      .sidebar h2 {
        font-size: 20px;
      }
      .sidebar nav li a {
        font-size: 12px;
        padding: 10px 14px;
        gap: 10px;
      }
      .logo-circle {
        width: 44px;
        height: 44px;
        font-size: 16px;
      }

      .main-content {
        padding: 20px;
        margin-left: 200px; /* Adjust margin for smaller sidebar */
      }

      .profile-details {
        grid-template-columns: 1fr;
        gap: 16px;
      }
      .unit-profile-card {
        padding: 20px;
      }
      .profile-image {
        width: 100px;
        height: 100px;
      }
      .detail-label {
        font-size: 10px;
      }
      .detail-input {
        font-size: 13px;
        padding: 10px 14px;
      }
      .action-buttons {
        flex-direction: row;
        gap: 10px;
      }
      .btn {
        padding: 8px 16px;
        font-size: 13px;
        min-width: 90px;
      }
      .section-header {
        font-size: 24px;
      }
      .visitors-list { /* Visitors list 2 columns on medium screens */
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
      }
      .permanent-member-item,
      .registered-member-item {
        padding: 12px 0; /* Adjust padding for list items */
      }
      .member-list-avatar { /* Specific for list avatar */
        width: 45px;
        height: 45px;
        font-size: 18px;
        margin-right: 10px;
      }
      .visitor-avatar {
        width: 45px;
        height: 45px;
        font-size: 18px;
      }
      .member-list-info p {
        font-size: 13px;
      }
      .visitor-item h4 {
        font-size: 15px;
      }
      .member-list-actions .btn-small {
        padding: 6px 10px;
        font-size: 11px;
        min-width: 60px;
      }
    }

    @media (max-width: 768px) {
      .container {
        flex-direction: column;
      }

      .sidebar {
        width: 100%;
        height: auto;
        padding: 16px 0;
        box-shadow: var(--shadow-md);
        position: static; /* Changed back to static for smaller screens */
        height: auto;    /* Revert height for smaller screens */
      }

      /* Add this to remove margin-left when sidebar is no longer fixed */
      .main-content {
        margin-left: 0;
      }

      .sidebar nav ul {
        display: flex;
        gap: 6px;
        overflow-x: auto;
        padding: 0 12px;
        justify-content: flex-start;
      }

      .sidebar nav li {
        white-space: nowrap;
        min-width: auto;
      }

      .sidebar nav li a {
        padding: 10px 12px;
        font-size: 12px;
      }

      .logout {
        position: static;
        margin-top: 16px;
        margin-left: auto;
        margin-right: auto;
        max-width: 160px;
      }

      .main-content {
        padding: 16px;
        margin-left: 0; /* Remove margin-left */
      }

      .content-header h1 {
        font-size: 24px;
      }

      .profile-header {
        flex-direction: column;
        text-align: center;
        align-items: center;
        gap: 16px;
      }

      .profile-image {
        width: 100px;
        height: 100px;
      }
      .profile-image-container button {
        padding: 6px 12px !important;
        font-size: 12px !important;
      }

      .action-buttons {
        flex-direction: column;
        gap: 8px;
      }
      .btn {
        min-width: unset;
        width: 100%;
      }

      .visitors-list { /* All grids become single column on small screens */
        grid-template-columns: 1fr;
        gap: 16px;
      }

      .member-list-actions .btn-small { /* Adjust for stacked buttons in list items */
        max-width: none;
        flex: 1;
      }
      .permanent-member-item,
      .registered-member-item {
        flex-direction: column;
        align-items: flex-start;
        text-align: left;
        gap: 10px;
      }
      .member-list-avatar {
          align-self: center;
          margin-bottom: 10px;
      }
      .member-list-info {
          align-self: flex-start;
      }
      .member-list-actions {
          margin-left: 0;
          margin-top: 10px;
          align-self: stretch;
      }


      .visitor-item {
          flex-direction: column; /* Stack visitor items vertically */
          align-items: flex-start;
          text-align: left;
          gap: 10px;
      }
      .visitor-avatar {
          align-self: center; /* Center avatar in stacked layout */
          margin-bottom: 10px;
      }
      .visitor-actions {
          margin-left: 0;
          margin-top: 10px;
          flex-direction: row; /* Keep actions in a row on smaller screens */
          justify-content: center;
      }
      .visitor-status {
          align-self: flex-start;
          margin-left: 0;
      }


      .modal-content {
        margin: 16px;
        padding: 20px;
      }
    }

    @media (max-width: 480px) {
      body {
        font-size: 13px;
      }
      .main-content {
        padding: 12px;
      }
      .content-header h1 {
        font-size: 20px;
      }
      .unit-profile-card {
        padding: 16px;
      }
      .profile-image {
        width: 80px;
        height: 80px;
      }
      .detail-input {
        font-size: 12px;
        padding: 8px 12px;
      }
      .section-header {
        font-size: 20px;
      }
      .permanent-member-item,
      .registered-member-item,
      .visitor-item {
        padding: 12px;
      }
      .member-list-avatar, .visitor-avatar {
        width: 40px;
        height: 40px;
        font-size: 16px;
      }
      .visitor-item h4 {
        font-size: 15px;
      }
      .member-list-info p {
        font-size: 12px;
      }
      .member-list-actions .btn-small, .visitor-actions .btn-small {
        font-size: 10px;
        padding: 5px 8px;
      }
      .success-message {
        padding: 10px 15px;
        font-size: 12px;
        bottom: 16px;
      }
      .modal-content {
        padding: 16px;
      }
      .modal-content h3 {
        font-size: 16px;
      }
      .modal-content p {
        font-size: 13px;
      }
      .modal-buttons button {
        padding: 8px 15px;
        font-size: 12px;
      }
    }

    /* ANIMATIONS */
    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .unit-profile-card {
      animation: fadeInUp 0.6s ease-out;
    }

    .permanent-member-item, .registered-member-item, .visitor-item, .activity-list li {
      animation: fadeInUp 0.6s ease-out;
    }

    /* Staggered animation for list items */
    .permanent-member-item:nth-child(2n), .registered-member-item:nth-child(2n), .visitor-item:nth-child(2n), .activity-list li:nth-child(2n) {
      animation-delay: 0.05s;
    }

    /* FOCUS STYLES FOR ACCESSIBILITY */
    .btn:focus,
    .detail-input:focus,
    .sidebar nav li a:focus,
    .member-list-actions .btn:focus,
    .modal-buttons button:focus,
    .logout:focus,
    .tab-button:focus {
      outline: 2px solid var(--khaki-primary);
      outline-offset: 2px;
    }

    /* LOADING STATES */
    .btn:disabled,
    .member-list-actions .btn:disabled,
    .modal-buttons button:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    /* PRINT STYLES */
    @media print {
      .sidebar,
      .action-buttons,
      .member-list-actions,
      .visitor-actions,
      .modal,
      .success-message,
      .profile-image-container button,
      .tabs-header { /* Hide tabs for print */
        display: none;
      }

      .main-content {
        padding: 0;
      }

      .unit-profile-card,
      .permanent-member-item,
      .registered-member-item,
      .visitor-item,
      .activity-list {
        box-shadow: none;
        border: 1px solid var(--gray-300);
        break-inside: avoid;
        margin-bottom: 20px; /* Add margin for printed sections */
      }
      .profile-header {
        flex-direction: row;
        align-items: flex-start;
      }
      .permanent-member-item .member-list-info p,
      .registered-member-item .member-list-info p,
      .visitor-item .visitor-info p {
        display: block;
        text-align: left;
      }
      .permanent-member-item .member-list-info p strong,
      .permanent-member-item .member-list-info p span,
      .registered-member-item .member-list-info p strong,
      .registered-member-item .member-list-info p span,
      .visitor-item .visitor-info strong,
      .visitor-item .visitor-info p span {
        display: block;
        text-align: left;
      }
      /* Ensure all tab content is visible when printing */
      .tab-content {
          display: block !important;
          padding: 10px;
          border: 1px solid var(--gray-300);
          border-radius: var(--radius-lg);
          margin-bottom: 20px;
      }
      .activity-list li {
        border-bottom: 1px dashed var(--gray-300);
      }
    }
  </style>
</head>
<body>
  <div class="container">
    <aside class="sidebar">
      <div class="logo-circle"></div>
      <h2>Verifaced</h2>
      <nav>
        <ul>
          <li class="active"><a href="T_UnitProfile.php"><span></span> Unit Profile</a></li>
          <li><a href="T_VisitorsList.php"><span></span> Visitors List</a></li>
          <li><a href="T_Pending.php"><span></span> Pending <span class="badge" id="pendingBadgeUnitProfile"><?php echo $pending_badge_count; ?></span></a></li>
          <li><a href="T_MemberRegistration.php"><span></span> Member Registration</a></li>
        </ul>
      </nav>
      <a href="#" class="logout" id="logoutLink">Log out</a>
    </aside>

    <main class="main-content">
      <div class="content-header">
        <h1>Unit Profile</h1>
        <p>Manage your unit information and details</p>
      </div>

      <?php if ($flash_message): ?>
          <div id="phpFlashMessage" class='success-message <?php echo ($flash_message ? "show" : ""); ?>' style="background: <?php echo ($flash_message_type === 'success' ? 'linear-gradient(135deg, var(--success) 0%, #218838 100%)' : 'linear-gradient(135deg, var(--danger) 0%, #C82333 100%)'); ?>; color: white; border-color: <?php echo ($flash_message_type === 'success' ? '#218838' : '#C82333'); ?>;">
            <span><?php echo $flash_message; ?></span>
          </div>
      <?php endif; ?>

      <form id="unitProfileForm" method="POST" action="T_UnitProfile.php" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_profile">
        <div class="unit-profile-card">
          <div class="profile-header">
            <div class="profile-image-container">
              <div class="profile-image">
                <img src="<?php echo $tenant_profile_pic_path; ?>" alt="Owner Image" id="ownerImagePreview" onerror="this.onerror=null;this.src='https://placehold.co/150x150/A0A0A0/FFFFFF?text=Owner+Image';">
              </div>
              </div>

            <div class="profile-details">
              <div class="detail-group">
                <label class="detail-label">Unit Number</label>
                <input type="text" class="detail-input" name="unit_number" placeholder="Enter unit number" value="<?php echo $unit_number; ?>" readonly>
              </div>

              <div class="detail-group">
                <label class="detail-label">First Name</label>
                <input type="text" class="detail-input" name="first_name" placeholder="Enter first name" value="<?php echo $tenant_first_name; ?>" required>
              </div>

              <div class="detail-group">
                <label class="detail-label">Last Name</label>
                <input type="text" class="detail-input" name="last_name" placeholder="Enter last name" value="<?php echo $tenant_last_name; ?>" required>
              </div>

              <div class="detail-group">
                <label class="detail-label">Phone Number</label>
                <input type="tel" class="detail-input" name="phone_number" placeholder="Enter phone number" value="<?php echo $tenant_phone_number; ?>" required>
              </div>

              <div class="detail-group">
                <label class="detail-label">Email Address</label>
                <input type="email" class="detail-input" name="email_address" placeholder="Enter email address" value="<?php echo $tenant_email; ?>" required>
              </div>
              
              <div class="detail-group">
                <label class="detail-label">Current Status</label>
                <input type="text" class="detail-input" name="current_status" value="<?php echo $tenant_current_status; ?>" readonly>
              </div>
              <div class="detail-group">
                <label class="detail-label">Last Status Update</label>
                <input type="text" class="detail-input" name="last_status_update" value="<?php echo $tenant_last_status_update; ?>" readonly>
              </div>
              </div>
          </div>
          <div class="action-buttons">
              <button type="submit" class="btn btn-save">Save Changes</button>
              <button type="button" class="btn btn-neutral" id="changePasswordBtn">Change Password</button>
              <button type="button" class="btn btn-in" id="checkInBtn">Check In</button>
              <button type="button" class="btn btn-out" id="checkOutBtn">Check Out</button>
              </div>
        </div>
      </form>

      <div class="tabs-container">
        <div class="tabs-header">
          <button class="tab-button active" data-tab="permanent-members">Permanent Members</button>
          <button class="tab-button" data-tab="registered-members">Registered Members</button>
          <button class="tab-button" data-tab="activity-log">Activity Log</button>
        </div>

        <div id="permanent-members" class="tab-content active">
          <h2 class="section-header">Permanent Unit Members</h2>
          <div id="permanentMembersListWrapper">
            <?php echo $permanent_members_html; ?>
          </div>
        </div>

        <div id="registered-members" class="tab-content">
          <h2 class="section-header">Registered Unit Members</h2>
          <div id="registeredMembersListWrapper">
            <?php echo $registered_members_html; ?>
          </div>
        </div>

        <div id="activity-log" class="tab-content">
          <h2 class="section-header">Tenant Activity Log</h2>
          <div id="activityLogContent">
            <?php echo $activity_log_html; ?>
          </div>
        </div>

      </div> </main>
  </div>

  <div id="logoutModal" class="modal">
    <div class="modal-content">
      <h3>Confirm Logout</h3>
      <p>Are you sure you want to log out?</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelLogout">Cancel</button>
        <button class="btn-confirm danger" id="confirmLogout">Log out</button>
      </div>
    </div>
  </div>

  <div id="genericConfirmModal" class="modal">
    <div class="modal-content">
      <h3 id="genericConfirmModalTitle"></h3>
      <p id="genericConfirmModalMessage"></p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="genericCancelBtn">Cancel</button>
        <button class="btn-confirm" id="genericConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>

  <div id="changePasswordModal" class="modal">
    <div class="modal-content">
      <h3>Change Password</h3>
      <p>You will be redirected to a secure page to change your password.</p>
      <div class="modal-buttons">
        <button class="btn-cancel" id="cancelPasswordChange">Cancel</button>
        <button class="btn-confirm" id="confirmPasswordChange">Proceed</button>
      </div>
    </div>
  </div>

  <div id="successMessage" class="success-message">
    <span></span>
  </div>
  <script>
    // Get references to DOM elements
    const logoutLink = document.getElementById('logoutLink');
    const logoutModal = document.getElementById('logoutModal');
    const confirmLogoutBtn = document.getElementById('confirmLogout');
    const cancelLogoutBtn = document.getElementById('cancelLogout');
    const pendingBadgeUnitProfile = document.getElementById('pendingBadgeUnitProfile');
    const successMessage = document.getElementById('successMessage');

    // Generic Confirmation Modal Elements
    const genericConfirmModal = document.getElementById('genericConfirmModal');
    const genericConfirmModalTitle = document.getElementById('genericConfirmModalTitle');
    const genericConfirmModalMessage = document.getElementById('genericConfirmModalMessage');
    const genericConfirmBtn = document.getElementById('genericConfirmBtn');
    const genericCancelBtn = document.getElementById('genericCancelBtn');
    let currentConfirmAction = null; // To store the callback function for the current confirmation

    // Change Password Modal Elements
    const changePasswordBtn = document.getElementById('changePasswordBtn');
    const changePasswordModal = document.getElementById('changePasswordModal');
    const confirmPasswordChangeBtn = document.getElementById('confirmPasswordChange');
    const cancelPasswordChangeBtn = document.getElementById('cancelPasswordChange');

    // New In/Out Buttons
    const checkInBtn = document.getElementById('checkInBtn');
    const checkOutBtn = document.getElementById('checkOutBtn');

    // Tenant ID for AJAX calls (already available from PHP)
    const currentTenantId = <?php echo json_encode($logged_in_tenant_id); ?>;

    // Tab elements
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');


    /**
     * Displays a generic confirmation modal.
     * @param {string} title - The title for the modal.
     * @param {string} message - The message for the modal.
     * @param {function} confirmCallback - The function to execute if confirmed.
     * @param {boolean} isPositiveAction - If true, confirm button is primary. If false, it's destructive (red).
     */
    function showConfirmModal(title, message, confirmCallback, isPositiveAction = true) {
        genericConfirmModalTitle.textContent = title;
        genericConfirmModalMessage.innerHTML = message; // Use innerHTML for <br> tags
        genericConfirmBtn.className = 'btn-confirm'; // Reset classes
        if (!isPositiveAction) {
            genericConfirmBtn.classList.add('danger');
        }
        currentConfirmAction = confirmCallback;
        genericConfirmModal.classList.add('show');
    }

    // Event listener for generic confirm button
    genericConfirmBtn.addEventListener('click', () => {
        if (currentConfirmAction) {
            currentConfirmAction(); // Execute the stored callback
        }
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    });

    // Event listener for generic cancel button
    genericCancelBtn.addEventListener('click', () => {
        genericConfirmModal.classList.remove('show');
        currentConfirmAction = null; // Clear the callback
    });

    // Close generic modal if clicked outside content
    genericConfirmModal.addEventListener('click', function(e) {
        if (e.target === genericConfirmModal) {
            genericConfirmModal.classList.remove('show');
            currentConfirmAction = null;
        }
    });

    // Function to show a temporary success message
    function showSuccessMessage(message) {
      successMessage.querySelector('span').textContent = message;
      successMessage.classList.add('show');
      setTimeout(() => {
        successMessage.classList.remove('show');
      }, 3000);
    }

    /**
     * Sends an AJAX request to remove a permanent member.
     * @param {string} memberId - The ID of the member to remove.
     */
    function removePermanentMember(memberId) {
        fetch('process_tenant_member_action.php', { // This PHP needs to be implemented to handle permanent member removal
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=remove_permanent_member&member_id=${memberId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessMessage(data.message);
                const itemToRemove = document.querySelector(`#permanentMembersListWrapper .permanent-member-item[data-member-id='${memberId}']`);
                if (itemToRemove) {
                    itemToRemove.style.transition = 'all 0.25s ease';
                    itemToRemove.style.transform = 'scale(0.95)';
                    itemToRemove.style.opacity = '0';
                    setTimeout(() => {
                        itemToRemove.remove();
                        // Potentially update empty state for permanent members
                        const permanentMembersListWrapper = document.getElementById('permanentMembersListWrapper');
                        const permanentMembersList = permanentMembersListWrapper.querySelector('.permanent-members-list');
                        if (permanentMembersList && permanentMembersList.children.length === 0) {
                            permanentMembersListWrapper.innerHTML = `
                                <div class='empty-members-state' id='emptyPermanentMembersState'>
                                    <h3>No Permanent Members Added Yet</h3>
                                    <p>Permanent members are typically those added by an admin or converted from visitors.</p>
                                </div>
                            `;
                        }
                    }, 250);
                }
            } else {
                showSuccessMessage(data.message);
                console.error("Error removing permanent member:", data.message);
            }
        })
        .catch(error => {
            console.error('Error in AJAX request to remove permanent member:', error);
            showSuccessMessage('An error occurred while trying to remove the permanent member.');
        });
    }

    /**
     * Sends an AJAX request to remove a registered member.
     * @param {string} memberId - The ID of the member to remove from 'addedmembers'.
     */
    function removeRegisteredMember(memberId) {
        fetch('process_tenant_member_action.php', { // This PHP needs to be implemented to handle registered member removal
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=remove_registered_member&added_member_id=${memberId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessMessage(data.message);
                const itemToRemove = document.querySelector(`#registeredMembersListWrapper .registered-member-item[data-member-id='${memberId}']`); // Targeting list item
                if (itemToRemove) {
                    itemToRemove.style.transition = 'all 0.25s ease';
                    itemToRemove.style.transform = 'scale(0.95)';
                    itemToRemove.style.opacity = '0';
                    setTimeout(() => {
                        itemToRemove.remove();
                        // Potentially update empty state for registered members
                        const registeredMembersListWrapper = document.getElementById('registeredMembersListWrapper');
                        const registeredMembersList = registeredMembersListWrapper.querySelector('.registered-members-list');
                        if (registeredMembersList && registeredMembersList.children.length === 0) {
                             registeredMembersListWrapper.innerHTML = `
                                <div class='empty-members-state' id='emptyRegisteredMembersState'>
                                    <h3>No Registered Members Yet</h3>
                                    <p>Members registered through the 'Member Registration' page will appear here.</p>
                                    <p><a href='T_MemberRegistration.php' class='btn btn-neutral'>Register New Member</a></p>
                                </div>
                            `;
                        }
                    }, 250);
                }
            } else {
                showSuccessMessage(data.message);
                console.error("Error removing registered member:", data.message);
            }
        })
        .catch(error => {
            console.error('Error in AJAX request to remove registered member:', error);
            showSuccessMessage('An error occurred while trying to remove the registered member.');
        });
    }

    // Since the Visitors tab is removed, this function is no longer called from the page.
    // However, keeping it for completeness in case it's used elsewhere or re-added later.
    /**
     * Sends an AJAX request to soft-delete a visitor record (update status to 'deleted' and set processed_at).
     * @param {string} visitorId - The ID of the visitor to soft-delete.
     */
    function softDeleteVisitor(visitorId) {
        fetch('process_tenant_visitor_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=soft_delete_visitor&visitor_id=${visitorId}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showSuccessMessage(data.message);
                // Find the visitor item and update its status visually
                const visitorItem = document.querySelector(`.visitor-card[data-visitor-id='${visitorId}']`);
                if (visitorItem) {
                    const statusSpan = visitorItem.querySelector('.visitor-status');
                    const deleteButtonDiv = visitorItem.querySelector('.visitor-actions');
                    const visitorInfo = visitorItem.querySelector('.visitor-info');

                    const now = new Date();
                    const year = now.getFullYear();
                    const month = (now.getMonth() + 1).toString().padStart(2, '0');
                    const day = now.getDate().toString().padStart(2, '0');
                    const hours = now.getHours().toString().padStart(2, '0');
                    const minutes = now.getMinutes().toString().padStart(2, '0');
                    const seconds = now.getSeconds().toString().padStart(2, '0');
                    const currentTime = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;

                    if (statusSpan) {
                        statusSpan.classList.remove('pending', 'accepted', 'rejected');
                        statusSpan.classList.add('deleted');
                        statusSpan.textContent = 'Deleted';
                    }

                    if (deleteButtonDiv) {
                        deleteButtonDiv.innerHTML = '';
                    }

                    let removedTimestampP = visitorInfo.querySelector('.removed-timestamp');
                    if (!removedTimestampP) {
                        removedTimestampP = document.createElement('p');
                        removedTimestampP.className = 'removed-timestamp';
                        removedTimestampP.innerHTML = `<strong>Removed:</strong> ${currentTime}`;
                        visitorInfo.appendChild(removedTimestampP);
                    } else {
                        removedTimestampP.innerHTML = `<strong>Removed:</strong> ${currentTime}`;
                    }
                }
            } else {
                showSuccessMessage(data.message);
                console.error("Error soft deleting visitor:", data.message);
            }
        })
        .catch(error => {
            console.error('Error in AJAX request to soft delete visitor:', error);
            showSuccessMessage('An error occurred while trying to delete the visitor record.');
        });
    }


    // Logout Modal Logic
    logoutLink.addEventListener('click', function (e) {
      e.preventDefault();
      logoutModal.classList.add('show');
    });

    confirmLogoutBtn.addEventListener('click', function () {
      document.body.style.opacity = '0';
      document.body.style.transition = 'opacity 0.25s ease';
      setTimeout(() => {
        window.location.href = 'logout.php';
      }, 400);
    });

    cancelLogoutBtn.addEventListener('click', function () {
      logoutModal.classList.remove('show');
    });

    // Close modal when clicking outside
    logoutModal.addEventListener('click', function(e) {
      if (e.target === logoutModal) {
        logoutModal.classList.remove('show');
      }
    });

    // Change Password Modal Logic
    changePasswordBtn.addEventListener('click', function() {
        changePasswordModal.classList.add('show');
    });

    confirmPasswordChangeBtn.addEventListener('click', function() {
        console.log('Redirecting to password change page...');
        window.location.href = 'T_ChangePassword.php';
    });

    cancelPasswordChangeBtn.addEventListener('click', function() {
        changePasswordModal.classList.remove('show');
    });

    // Close change password modal when clicking outside
    changePasswordModal.addEventListener('click', function(e) {
        if (e.target === changePasswordModal) {
            changePasswordModal.classList.remove('show');
        }
    });

    // New "In" and "Out" button click handlers
    checkInBtn.addEventListener('click', function() {
        showConfirmModal('Confirm Check In', 'Are you sure you want to mark yourself as "In"?', () => {
            fetch('process_check_in_out.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=check_in&tenant_id=${currentTenantId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessMessage(data.message);
                    // Reload the page to reflect new status and update the log via PHP rendering
                    // A full reload ensures status fields are updated. For a smoother UX,
                    // you could fetch just the status and update DOM elements if needed.
                    location.reload();
                } else {
                    showSuccessMessage(data.message);
                }
            })
            .catch(error => {
                console.error('Error during check-in:', error);
                showSuccessMessage('An error occurred during check-in.');
            });
        });
    });

    checkOutBtn.addEventListener('click', function() {
        showConfirmModal('Confirm Check Out', 'Are you sure you want to mark yourself as "Out"?', () => {
            fetch('process_check_in_out.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=check_out&tenant_id=${currentTenantId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessMessage(data.message);
                    // Reload the page to reflect new status and update the log via PHP rendering
                    location.reload();
                } else {
                    showSuccessMessage(data.message);
                }
            })
            .catch(error => {
                console.error('Error during check-out:', error);
                showSuccessMessage('An error occurred during check-out.');
            });
        });
    });


    // Input focus animations
    document.querySelectorAll('.detail-input').forEach(input => {
      input.addEventListener('focus', function() {
        this.closest('.detail-group').style.transform = 'translateY(-1px)';
      });

      input.addEventListener('blur', function() {
        this.closest('.detail-group').style.transform = 'translateY(0)';
      });
    });

    // Tab switching logic
    tabButtons.forEach(button => {
        button.addEventListener('click', () => {
            const tabToActivate = button.dataset.tab;

            // Deactivate all buttons and content
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            // Activate the clicked button and its corresponding content
            button.classList.add('active');
            document.getElementById(tabToActivate).classList.add('active');
        });
    });


    // Sidebar navigation
    document.addEventListener('DOMContentLoaded', function() {
      const currentPath = window.location.pathname.split('/').pop();
      document.querySelectorAll('.sidebar nav li a').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
          link.closest('li').classList.add('active');
        } else {
          link.closest('li').classList.remove('active');
        }
      });

      // Initial page animation for the unit profile card and member cards
      const profileCard = document.querySelector('.unit-profile-card');
      if (profileCard) {
          profileCard.style.opacity = '0';
          profileCard.style.transform = 'translateY(20px)';
          setTimeout(() => {
            profileCard.style.transition = 'all 0.4s ease';
            profileCard.style.opacity = '1';
            profileCard.style.transform = 'translateY(0)';
          }, 50);
      }

      // Initial animation for relevant tab content on page load
      const initialTabContent = document.querySelector('.tab-content.active .permanent-members-list, .tab-content.active .registered-members-list, .tab-content.active .visitors-list, .tab-content.active .activity-list');
        if (initialTabContent) {
            Array.from(initialTabContent.children).forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'all 0.4s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, 50 + (index * 50));
            });
        }


      // Initialize tab system to show the first tab by default
      // Modified: Ensure the correct first tab is activated
      const firstAvailableTabButton = document.querySelector('.tabs-header .tab-button');
      if (firstAvailableTabButton) {
          firstAvailableTabButton.click();
      }


      // --- MODIFIED CODE FOR FLASH MESSAGE DISPLAY ---
      // Handle initial PHP-generated flash message
      const phpFlashMessageDiv = document.getElementById('phpFlashMessage');
      const flashMessageContent = phpFlashMessageDiv ? phpFlashMessageDiv.querySelector('span').textContent.trim() : '';

      if (phpFlashMessageDiv && flashMessageContent !== '') {
          // Use the existing showSuccessMessage function for consistency
          showSuccessMessage(flashMessageContent);
          // The showSuccessMessage function already handles the hiding via setTimeout
          // No need for a separate setTimeout here for the initial PHP message.
      }
      // --- END MODIFIED CODE ---
    });
  </script>
</body>
</html>