<?php
// THIS MUST BE THE VERY FIRST LINE IN THE FILE, BEFORE ANY HTML, BLANK LINES, OR SPACES.
session_start();

// Enable aggressive error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include your database connection file. This file MUST create a $conn variable
// that is a valid MySQLi database connection object.
require_once 'db_connect.php';

// Function to safely output HTML to prevent Cross-Site Scripting (XSS) vulnerabilities.
function e($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

// Basic authentication check: Redirect if the admin is not logged in.
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Set a flash message to inform the user why they were redirected.
    $_SESSION['flash_message'] = "Please log in to view tenant information.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: adminlogin.php"); // Redirect to your admin login page.
    exit(); // Important: Always call exit() after a header redirect.
}

// Initialize variables for tenant data
$tenant_id = null;
$tenant_name = 'N/A';
$tenant_unit_number = 'N/A';
$tenant_phone_number = 'N/A';
$tenant_email = 'N/A';
$tenant_photo_path = 'https://placehold.co/100x100/A0A0A0/FFFFFF?text=No+Photo'; // Default placeholder
// Initialize tenant status variables
$tenant_current_status = 'N/A';
$tenant_last_status_update = 'N/A';


$permanent_members_html = '<div class="empty-state"><h3>No Permanent Members</h3><p>This unit has no permanent members registered.</p></div>'; // Improved default empty state
$registered_members_html = '<div class="empty-state"><h3>No Registered Members</h3><p>This unit has no registered members yet.</p></div>'; // Improved default empty state
$visitors_html = '<div class="empty-state"><h3>No Accepted Visitors</h3><p>This unit has no accepted visitors on record.</p></div>'; // Improved default empty state


// Get the unit_id from the URL query string
if (isset($_GET['unit_id']) && is_numeric($_GET['unit_id'])) {
    $requested_unit_id = (int)$_GET['unit_id'];

    // Define a consistent base path for file_exists checks
    $base_upload_dir_for_fs_check = __DIR__ . DIRECTORY_SEPARATOR;

    // Ensure database connection is available
    if (isset($conn) && $conn instanceof mysqli) {
        // --- Fetch Main Tenant Information ---
        $stmt_tenant = null;
        try {
            // MODIFIED SQL: Select current_status and last_status_update from tenants table
            $sql_tenant = "SELECT t.tenant_id, t.first_name, t.last_name, t.phone_number, t.email, t.known_face_path_1 AS profile_pic_path, u.unit_number,
                                   t.current_status, t.last_status_update
                           FROM tenants t
                           JOIN units u ON t.unit_id = u.unit_id
                           WHERE t.unit_id = ? LIMIT 1";

            $stmt_tenant = $conn->prepare($sql_tenant);
            if ($stmt_tenant) {
                $stmt_tenant->bind_param("i", $requested_unit_id);
                $stmt_tenant->execute();
                $result_tenant = $stmt_tenant->get_result();

                if ($result_tenant->num_rows === 1) {
                    $tenant_data = $result_tenant->fetch_assoc();
                    $tenant_id = e($tenant_data['tenant_id']);
                    $tenant_name = e($tenant_data['first_name'] . ' ' . $tenant_data['last_name']);
                    $tenant_unit_number = e($tenant_data['unit_number']);
                    $tenant_phone_number = e($tenant_data['phone_number']);
                    $tenant_email = e($tenant_data['email']);

                    $full_server_path_to_image = $base_upload_dir_for_fs_check . str_replace('/', DIRECTORY_SEPARATOR, $tenant_data['profile_pic_path']);

                    if (!empty($tenant_data['profile_pic_path']) && file_exists($full_server_path_to_image)) {
                        $tenant_photo_path = e($tenant_data['profile_pic_path']);
                    } else {
                        $tenant_photo_path = 'https://placehold.co/100x100/A0A0A0/FFFFFF?text=No+Photo';
                        error_log("Tenant photo not found at: " . $full_server_path_to_image);
                    }
                    // NEW: Assign tenant status data
                    $tenant_current_status = e($tenant_data['current_status'] ?? 'N/A');
                    $tenant_last_status_update = 'N/A';
                    if (!empty($tenant_data['last_status_update'])) {
                        $dt = new DateTime($tenant_data['last_status_update']);
                        $tenant_last_status_update = e($dt->format('M d, Y h:i A'));
                    }

                } else {
                    $_SESSION['flash_message'] = "Tenant not found for Unit ID: " . e($requested_unit_id);
                    $_SESSION['flash_message_type'] = "danger";
                    header("Location: index.php");
                    exit();
                }
            } else {
                $_SESSION['flash_message'] = "Database error fetching tenant: " . e($conn->error);
                $_SESSION['flash_message_type'] = "danger";
                error_log("Failed to prepare statement for main tenant info: " . $conn->error);
                header("Location: index.php");
                exit();
            }
        } catch (Throwable $e) {
            $_SESSION['flash_message'] = "An unexpected error occurred fetching tenant info.";
            $_SESSION['flash_message_type'] = "danger";
            error_log("Error fetching main tenant info: " . $e->getMessage());
            header("Location: index.php");
            exit();
        } finally {
            if ($stmt_tenant) {
                $stmt_tenant->close();
            }
        }

        // --- Fetch Permanent Members (associated with the tenant) ---
        if ($tenant_id) {
            $stmt_permanent_members = null; // Renamed to avoid conflict
            try {
                // Select 'date_added' from members table. Assuming this column exists for permanent members.
                // If not, you might need to use a creation timestamp or add a new column for "date_added".
                // Let's use profile_pic_path for creation timestamp example if no 'date_added'
                $sql_permanent_members = "SELECT m.member_id, m.full_name, m.phone_number, m.relationship_to_tenant, m.profile_pic_path,
                                    (SELECT MIN(timestamp) FROM tenant_activity_log WHERE action_type = 'member_added_from_visitor' AND description LIKE CONCAT('%', m.full_name, '%') AND tenant_id = m.tenant_id) as date_added
                                FROM members m
                                WHERE m.tenant_id = ? ORDER BY m.full_name ASC";
                
                $stmt_permanent_members = $conn->prepare($sql_permanent_members);
                if ($stmt_permanent_members) {
                    $stmt_permanent_members->bind_param("i", $tenant_id);
                    $stmt_permanent_members->execute();
                    $result_permanent_members = $stmt_permanent_members->get_result();

                    if ($result_permanent_members->num_rows > 0) {
                        $permanent_members_html = '<div class="members-list-wrapper">'; // Wrap in a div for consistent styling
                        while ($member_data = $result_permanent_members->fetch_assoc()) {
                            $member_name = e($member_data['full_name']);
                            $member_phone = e($member_data['phone_number']);
                            $member_relationship = e($member_data['relationship_to_tenant']);
                            
                            // Get date added. If from a specific 'date_added' column, use that.
                            // Otherwise, if using activity log, this will get it. Default if not found.
                            $member_date_added_display = 'N/A';
                            if (!empty($member_data['date_added'])) {
                                $dt = new DateTime($member_data['date_added']);
                                $member_date_added_display = e($dt->format('M d, Y h:i A'));
                            }

                            $member_photo_db_path = $member_data['profile_pic_path'];
                            $member_full_server_path = $base_upload_dir_for_fs_check . str_replace('/', DIRECTORY_SEPARATOR, $member_photo_db_path);
                            $member_pic_path = ($member_photo_db_path && file_exists($member_full_server_path)) ? e($member_photo_db_path) : 'https://placehold.co/45x45/E0E0E0/333333?text=Member';

                            $permanent_members_html .= "<div class='member-card'>";
                            $permanent_members_html .= "<img src='{$member_pic_path}' alt='Member Photo' class='profile-pic-small'>";
                            $permanent_members_html .= "<div class='info'>";
                            $permanent_members_html .= "<p><strong>Name:</strong> {$member_name}</p>";
                            $permanent_members_html .= "<p><strong>Phone Number:</strong> {$member_phone}</p>";
                            $permanent_members_html .= "<p><strong>Relationship:</strong> {$member_relationship}</p>";
                            // Display date added
                            $permanent_members_html .= "<p><strong>Date Added:</strong> {$member_date_added_display}</p>";
                            $permanent_members_html .= "</div>";
                            $permanent_members_html .= "</div>";
                        }
                        $permanent_members_html .= '</div>';
                    } else {
                        // Reset to default empty message if no permanent members
                        $permanent_members_html = '<div class="empty-state"><h3>No Permanent Members</h3><p>This unit has no permanent members registered.</p></div>';
                    }
                } else {
                    error_log("Failed to prepare statement for permanent members: " . $conn->error);
                }
            } catch (Throwable $e) {
                error_log("Error fetching permanent members: " . $e->getMessage());
            } finally {
                if ($stmt_permanent_members) {
                    $stmt_permanent_members->close();
                }
            }

            // --- Fetch Registered Members (from 'addedmembers' table) ---
            $stmt_registered_members = null; // Renamed to avoid conflict
            try {
                // MODIFIED SQL: Select current_status and last_status_update from addedmembers table
                $sql_registered_members = "SELECT added_member_id, full_name, username, phone_number, relationship_to_tenant, known_face_path_2, current_status, last_status_update
                                   FROM addedmembers
                                   WHERE tenant_id = ? ORDER BY full_name ASC";
                $stmt_registered_members = $conn->prepare($sql_registered_members);
                if ($stmt_registered_members) {
                    $stmt_registered_members->bind_param("i", $tenant_id);
                    $stmt_registered_members->execute();
                    $result_registered_members = $stmt_registered_members->get_result();

                    if ($result_registered_members->num_rows > 0) {
                        $registered_members_html = '<div class="members-list-wrapper">'; // Wrap in a div for consistent styling
                        while ($member_data = $result_registered_members->fetch_assoc()) {
                            $member_name = e($member_data['full_name']);
                            $member_username = e($member_data['username']);
                            // Registered Member status data
                            $registered_member_current_status_display = e($member_data['current_status'] ?? 'N/A');
                            $registered_member_last_status_update_display = 'N/A';
                            if (!empty($member_data['last_status_update'])) {
                                $dt = new DateTime($member_data['last_status_update']);
                                $registered_member_last_status_update_display = e($dt->format('M d, Y h:i A'));
                            }

                            $member_photo_db_path = $member_data['known_face_path_2'];
                            $member_full_server_path = $base_upload_dir_for_fs_check . str_replace('/', DIRECTORY_SEPARATOR, $member_photo_db_path);
                            $member_pic_path = ($member_photo_db_path && file_exists($member_full_server_path)) ? e($member_photo_db_path) : 'https://placehold.co/45x45/E0E0E0/333333?text=Reg';

                            $registered_members_html .= "<div class='member-card'>";
                            $registered_members_html .= "<img src='{$member_pic_path}' alt='Member Photo' class='profile-pic-small'>";
                            $registered_members_html .= "<div class='info'>";
                            $registered_members_html .= "<p><strong>Name:</strong> {$member_name}</p>";
                            $registered_members_html .= "<p><strong>Username:</strong> {$member_username}</p>";
                            // Display registered member status
                            $registered_members_html .= "<p><strong>Current Status:</strong> <span class='status-indicator status-{$registered_member_current_status_display}'>{$registered_member_current_status_display}</span></p>";
                            $registered_members_html .= "<p><strong>Last Update:</strong> {$registered_member_last_status_update_display}</p>";
                            $registered_members_html .= "</div>";
                            $registered_members_html .= "</div>";
                        }
                        $registered_members_html .= '</div>';
                    } else {
                        // Reset to default empty message if no registered members
                        $registered_members_html = '<div class="empty-state"><h3>No Registered Members</h3><p>This unit has no registered members yet.</p></div>';
                    }
                } else {
                    error_log("Failed to prepare statement for registered members: " . $conn->error);
                }
            } catch (Throwable $e) {
                error_log("Error fetching registered members: " . $e->getMessage());
            } finally {
                if ($stmt_registered_members) {
                    $stmt_registered_members->close();
                }
            }
        }

        // --- Fetch Accepted Visitors (associated with the tenant) ---
        $stmt_visitors = null;
        try {
            $sql_visitors = "SELECT visitor_id, full_name, phone_number, purpose_of_visit, relationship_to_tenant, profile_pic_path, visit_timestamp
                              FROM visitors
                              WHERE host_tenant_id = ? AND status = 'accepted'
                              ORDER BY visit_timestamp DESC";
            $stmt_visitors = $conn->prepare($sql_visitors);
            if ($stmt_visitors) {
                $stmt_visitors->bind_param("i", $tenant_id);
                $stmt_visitors->execute();
                $result_visitors = $stmt_visitors->get_result();

                if ($result_visitors->num_rows > 0) {
                    $visitors_html = '<div class="visitors-list-wrapper">'; // Wrap in a div for consistent styling
                    while ($visitor_data = $result_visitors->fetch_assoc()) {
                        // --- DEBUGGING OUTPUT START (for visitors tab) ---
                        echo "\n";
                        echo "\n";

                        $visitor_initials = '';
                        if (!empty($visitor_data['full_name'])) {
                            $name_parts = explode(' ', $visitor_data['full_name']);
                            foreach ($name_parts as $part) {
                                $visitor_initials .= strtoupper(substr($part, 0, 1));
                            }
                        } else {
                            $visitor_initials = 'V';
                        }
                        
                        $visitor_photo_db_path = $visitor_data['profile_pic_path'];
                        $visitor_full_fs_path = $base_upload_dir_for_fs_check . str_replace('/', DIRECTORY_SEPARATOR, $visitor_photo_db_path);
                        $visitor_fileExists = ($visitor_photo_db_path && file_exists($visitor_full_fs_path));

                        echo "\n";
                        echo "\n";
                        
                        $visitor_pic_path = ($visitor_fileExists) ? e($visitor_photo_db_path) : 'https://placehold.co/45x45/E0E0E0/333333?text=' . $visitor_initials;

                        echo "\n";
                        // --- DEBUGGING OUTPUT END ---

                        $visitor_name = e($visitor_data['full_name']);
                        $visitor_phone = e($visitor_data['phone_number']);
                        $visitor_purpose = e($visitor_data['purpose_of_visit']);
                        $visitor_relationship = e($visitor_data['relationship_to_tenant']);
                        $visitor_timestamp = new DateTime($visitor_data['visit_timestamp']);
                        $visitor_date = e($visitor_timestamp->format('M d, Y h:i A'));

                        $visitors_html .= "<div class='member-card visitor-card'>"; // Re-using member-card for similar styling
                        $visitors_html .= "<img src='{$visitor_pic_path}' alt='Visitor Photo' class='profile-pic-small'>";
                        $visitors_html .= "<div class='info'>";
                        $visitors_html .= "<p><strong>Name:</strong> {$visitor_name}</p>";
                        $visitors_html .= "<p><strong>Phone Number:</strong> {$visitor_phone}</p>";
                        $visitors_html .= "<p><strong>Purpose:</strong> {$visitor_purpose}</p>";
                        $visitors_html .= "<p><strong>Relationship:</strong> {$visitor_relationship}</p>";
                        $visitors_html .= "<p><strong>Visit Date:</strong> {$visitor_date}</p>";
                        $visitors_html .= "</div>";
                        $visitors_html .= "</div>";
                    }
                    $visitors_html .= '</div>'; // Close visitors-list-wrapper
                } else {
                    $visitors_html = '<div class="empty-state"><h3>No Accepted Visitors</h3><p>This unit has no accepted visitors on record.</p></div>';
                }
            } else {
                error_log("Failed to prepare statement for visitors: " . $conn->error);
            }
        } catch (Throwable $e) {
            error_log("Error fetching visitors: " . $e->getMessage());
        } finally {
            if ($stmt_visitors) {
                $stmt_visitors->close();
            }
        }
    } else {
        $_SESSION['flash_message'] = "Database connection error. Unable to fetch tenant details.";
        $_SESSION['flash_message_type'] = "danger";
        error_log("Database connection not available in tenant_info.php.");
        header("Location: index.php");
        exit();
    }
} else {
    // If no unit_id is provided in the URL, redirect back to the unit profiles page
    $_SESSION['flash_message'] = "No tenant selected. Please select a unit to view details.";
    $_SESSION['flash_message_type'] = "warning";
    header("Location: index.php");
    exit();
}

// After PHP processing, retrieve flash messages for display
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifaced - Tenant Information</title>
    <style>
        /* --- GLOBAL STYLES --- */
        body {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #F5F5DC 0%, #F0F0E8 100%); /* Subtle khaki gradient */
            color: #2C2C2C; /* Rich dark text */
            display: flex; /* Changed to flex */
            font-size: 16px;
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden; /* Prevent horizontal scrollbar on the whole page */
        }

        .container {
            width: 100vw;
            display: flex;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #8B7D6B 0%, #6B5B4F 100%); /* Rich khaki gradient */
            color: #FFFFFF;
            padding: 30px 25px;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            position: fixed;
            top: 0;
            left: 0;
            align-items: center;
            transition: transform 0.3s ease-in-out;
            z-index: 1000;
        }

        .sidebar::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="20" cy="20" r="1" fill="%23FFFFFF" opacity="0.03"/><circle cx="80" cy="40" r="1" fill="%23FFFFFF" opacity="0.03"/><circle cx="40" cy="60" r="1" fill="%23FFFFFF" opacity="0.03"/><circle cx="60" cy="80" r="1" fill="%23FFFFFF" opacity="0.03"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
        }

        .sidebar .logo {
            font-size: 2em;
            font-weight: 700;
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 25px;
            border-bottom: 2px solid rgba(255,255,255,0.2);
            color: #FFFFFF;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
            letter-spacing: 1px;
            position: relative;
            z-index: 1;
        }

        .sidebar nav {
            width: 100%;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .sidebar nav ul {
            list-style: none;
            padding: 0;
            margin: 0;
            width: 100%;
        }

        .sidebar nav ul li {
            margin-bottom: 8px;
            width: 100%;
        }

        .sidebar nav ul li a {
            color: rgba(255,255,255,0.85);
            text-decoration: none;
            font-size: 1em;
            font-weight: 500;
            display: flex;
            align-items: center;
            padding: 14px 25px;
            width: 100%;
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
        }

        .sidebar nav ul li a::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .sidebar nav ul li a:hover::before {
            left: 100%;
        }

        .sidebar nav ul li.active a,
        .sidebar nav ul li a:hover {
            background: linear-gradient(135deg, rgba(255,255,255,0.2) 0%, rgba(255,255,255,0.1) 100%);
            color: #FFFFFF;
            transform: translateX(0);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .logout-btn {
            margin-top: auto;
            background: linear-gradient(135deg, #D2691E 0%, #B8860B 100%);
            color: #FFFFFF;
            border: 2px solid rgba(255,255,255,0.2);
            padding: 12px 25px;
            width: 100%;
            max-width: none;
            cursor: pointer;
            border-radius: 12px;
            font-size: 0.95em;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: block;
            text-align: center;
            margin-left: 0;
            margin-right: 0;
            position: relative;
            z-index: 1;
            overflow: hidden;
            text-shadow: 0 1px 2px rgba(0,0,0,0.3);
            box-sizing: border-box;
        }

        .logout-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .logout-btn:hover::before {
            left: 100%;
        }

        .logout-btn:hover {
            background: linear-gradient(135deg, #B8860B 0%, #D2691E 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }

        .content-area {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            position: relative;
            margin-left: 280px;
            flex-grow: 1;
        }

        .main-content {
            flex-grow: 1;
            padding: 35px;
            overflow-y: auto;
            background: linear-gradient(135deg, #F5F5DC 0%, #F0F0E8 100%);
            position: relative;
        }

        .main-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><defs><pattern id="dots" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="10" cy="10" r="1" fill="%23DDD8B8" opacity="0.3"/></pattern></defs><rect width="200" height="200" fill="url(%23dots)"/></svg>');
            pointer-events: none;
        }

        .main-content > * {
            position: relative;
            z-index: 1;
        }

        .main-content h2 {
            font-size: 2rem;
            font-weight: 700;
            color: #6B5B4F;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid #D2B48C;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        /* Common Card Style */
        .card-common {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 25px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12), 0 4px 10px rgba(107, 91, 79, 0.06);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-common:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(107, 91, 79, 0.18), 0 6px 15px rgba(107, 91, 79, 0.1);
        }

        /* Tenant Profile Card */
        .tenant-profile-card { /* Corrected class name used in PHP */
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12), 0 4px 10px rgba(107, 91, 79, 0.06);
            display: flex;
            align-items: flex-start; /* Changed to flex-start to align top */
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            flex-wrap: wrap; /* Allow content to wrap */
        }

        .tenant-profile-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(210, 180, 140, 0.1), transparent);
            transition: left 0.6s;
        }

        .tenant-profile-card:hover::before {
            left: 100%;
        }

        .tenant-profile-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 40px rgba(107, 91, 79, 0.2), 0 8px 20px rgba(107, 91, 79, 0.1);
            border-color: #D2B48C;
        }

        .profile-pic {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 24px;
            border: 3px solid #D2B48C;
            box-shadow: 0 4px 12px rgba(107, 91, 79, 0.2);
            transition: all 0.3s ease;
        }

        .profile-pic:hover {
            transform: scale(1.05);
        }

        .profile-pic-large {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 28px;
            border: 4px solid #B8860B;
            box-shadow: 0 6px 18px rgba(107, 91, 79, 0.25);
            flex-shrink: 0; /* Prevent shrinking */
        }

        .profile-pic-small {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            margin-right: 18px;
            border: 2px solid #D2B48C;
            box-shadow: 0 3px 8px rgba(107, 91, 79, 0.15);
            flex-shrink: 0; /* Prevent shrinking */
        }

        .info { /* Used for general info blocks, like tenant and member details */
            flex-grow: 1;
            /* No margin-right: 20px here, as it's part of the flex/grid behavior */
        }

        .info p {
            margin: 6px 0;
            font-size: 0.95rem;
            color: #4A4A4A;
            font-weight: 400;
            display: grid; /* Changed to grid for better alignment control */
            grid-template-columns: 140px 1fr; /* Explicitly define two columns: label (fixed width) and value */
            align-items: center; /* Vertically align items in the grid row */
            gap: 10px; /* Space between columns */
        }

        .info p strong {
            color: #6B5B4F;
            font-weight: 600;
            /* Removed min-width, display: inline-block, flex-shrink: 0 as grid handles it */
            justify-self: start; /* Align content to the start of the grid cell */
        }

        /* NEW: Status indicator styling */
.status-indicator {
    padding: 6px 12px;
    border-radius: 20px; /* More rounded for pill shape */
    font-size: 0.8em;
    font-weight: 700; /* Bolder text */
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: inline-block;
    width: fit-content;
    white-space: nowrap; /* Prevent text wrapping */
    text-align: center;
    min-width: 45px; /* Minimum width for consistency */
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1); /* Subtle shadow for depth */
}

.status-indicator.status-In {
    background-color: #D4EDDA; /* Light green */
    color: #155724; /* Dark green */
    border: 1px solid #C3E6CB;
}

.status-indicator.status-Out {
    background-color: #F8D7DA; /* Light red */
    color: #721C24; /* Dark red */
    border: 1px solid #F5C6CB;
}

.status-indicator.status-N\/A { /* For 'N/A' status */
    background-color: #E2E3E5; /* Light gray */
    color: #495057; /* Dark gray */
    border: 1px solid #D6D8DB;
}


        /* Button Styles */
        .btn {
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            text-align: center;
            position: relative;
            overflow: hidden;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #8B7D6B 0%, #6B5B4F 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(107, 91, 79, 0.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 91, 79, 0.4);
        }

        .btn-danger {
            background: linear-gradient(135deg, #CD853F 0%, #A0522D 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(160, 82, 45, 0.3);
        }

        .btn-danger:hover {
            background: linear-gradient(135deg, #A0522D 0%, #CD853F 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(160, 82, 45, 0.4);
        }

        .btn-success {
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(184, 134, 11, 0.3);
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #B8860B 0%, #DAA520 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(184, 134, 11, 0.4);
        }

        .tenant-actions { /* These are the buttons to the right of main tenant info */
            margin-left: auto;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap; /* Allow buttons to wrap */
            justify-content: flex-end; /* Align to the right if wrapped */
            max-width: 100%; /* Ensure it doesn't overflow */
        }

        .tenant-actions button {
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            text-align: center;
            padding: 10px 18px;
            font-size: 0.85rem;
            border-radius: 12px;
            position: relative;
            overflow: hidden;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
            flex-shrink: 0; /* Prevent buttons from shrinking too much */
        }

        .tenant-actions .view-profile-btn {
            background: linear-gradient(135deg, #8B7D6B 0%, #6B5B4F 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(107, 91, 79, 0.25);
        }

        .tenant-actions .view-profile-btn:hover {
            background: linear-gradient(135deg, #6B5B4F 0%, #8B7D6B 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(107, 91, 79, 0.35);
        }

        .tenant-actions .delete-btn {
            background: linear-gradient(135deg, #CD853F 0%, #A0522D 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(160, 82, 45, 0.25);
        }

        .tenant-actions .delete-btn:hover {
            background: linear-gradient(135deg, #A0522D 0%, #CD853F 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(160, 82, 45, 0.35);
        }

        .tenant-actions .view-log-btn {
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(184, 134, 11, 0.25);
        }

        .tenant-actions .view-log-btn:hover {
            background: linear-gradient(135deg, #B8860B 0%, #DAA520 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(184, 134, 11, 0.35);
        }


        /* Form Styles */
        #registrationForm {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 20px;
            padding: 35px;
            margin-bottom: 25px;
            box-shadow: 0 12px 35px rgba(107, 91, 79, 0.15), 0 6px 15px rgba(107, 91, 79, 0.1);
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin-left: -12px;
            margin-right: -12px;
            margin-bottom: 18px;
        }

        .form-group {
            flex: 1 1 calc(50% - 24px);
            min-width: 240px;
            display: flex;
            flex-direction: column;
            margin: 0 12px 24px 12px;
        }

        .form-group label {
            margin-bottom: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            color: #6B5B4F;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="file"],
        .form-group select {
            width: 100%;
            padding: 14px 16px;
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 2px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 12px;
            font-size: 0.95rem;
            box-sizing: border-box;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: inset 0 2px 4px rgba(107, 91, 79, 0.05);
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #B8860B;
            outline: 0;
            box-shadow: 0 0 0 0.3rem rgba(184, 134, 11, 0.25), inset 0 2px 4px rgba(107, 91, 79, 0.05);
            transform: translateY(-1px);
        }

        .form-group input::placeholder {
            color: #999999;
            font-style: italic;
        }

        .form-group select option {
            padding: 8px;
            color: #2C2C2C;
            background-color: #FFFFFF;
        }

        .form-group select option:disabled {
            color: #999999;
            background-color: #F5F5F5;
        }

        .form-group input[type="file"] {
            padding: 12px 14px;
            height: auto;
            background: linear-gradient(135deg, #F8F8F8 0%, #F0F0F0 100%);
        }

        .form-actions {
            text-align: right;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid #E6DCC6;
        }

        .done-btn {
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            text-align: center;
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            color: white;
            padding: 14px 30px;
            font-size: 1.1rem;
            border-radius: 25px;
            box-shadow: 0 6px 18px rgba(184, 134, 11, 0.3);
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            position: relative;
            overflow: hidden;
        }

        .done-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .done-btn:hover::before {
            left: 100%;
        }

        .done-btn:hover {
            background: linear-gradient(135deg, #B8860B 0%, #DAA520 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(184, 134, 11, 0.4);
        }

        /* Tenant Information Page Styles */
        #tenantInfoContent h2 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #6B5B4F;
            margin-bottom: 25px;
            padding-bottom: 18px;
            border-bottom: 3px solid #D2B48C;
            text-shadow: 0 1px 2px rgba(0,0,0,0.1);
        }

        .tenant-info-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: 0 12px 35px rgba(107, 91, 79, 0.15), 0 6px 15px rgba(107, 91, 79, 0.1);
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap; /* Allow content to wrap */
        }

        .tenant-info-card .info {
            flex-grow: 1;
            margin-right: 20px; /* Space between info and buttons */
        }

        .tenant-info-card .info p {
            margin: 8px 0;
            font-size: 1.05rem;
            color: #4A4A4A;
            display: grid; /* Changed to grid for better alignment control */
            grid-template-columns: 140px 1fr; /* Explicitly define two columns: label (fixed width) and value */
            align-items: center; /* Vertically align items in the grid row */
            gap: 10px; /* Space between columns */
        }

        .tenant-info-card .info p strong {
            color: #6B5B4F;
            font-weight: 600;
            justify-self: start; /* Align content to the start of the grid cell */
        }

        .tabs {
            margin-bottom: 0;
            border-bottom: 3px solid #E6DCC6;
            display: flex;
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border-radius: 16px 16px 0 0;
            padding: 0 10px;
            box-shadow: 0 4px 12px rgba(107, 91, 79, 0.1);
        }

        .tab-button {
            background-color: transparent;
            border: none;
            border-bottom: 4px solid transparent;
            padding: 16px 22px;
            cursor: pointer;
            font-size: 1.05rem;
            font-weight: 600;
            margin-right: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            color: #8B7D6B;
            border-radius: 12px 12px 0 0;
            position: relative;
        }

        .tab-button::before {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #B8860B 0%, #DAA520 100%);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .tab-button.active {
            color: #6B5B4F;
            background: linear-gradient(135deg, rgba(184, 134, 11, 0.1) 0%, rgba(218, 165, 32, 0.1) 100%);
        }

        .tab-button.active::before {
            transform: scaleX(1);
        }

        .tab-button:hover:not(.active) {
            color: #6B5B4F;
            background: linear-gradient(135deg, rgba(139, 125, 107, 0.05) 0%, rgba(184, 134, 11, 0.05) 100%);
            transform: translateY(-2px);
        }

        .tab-content {
            display: none;
            padding: 30px;
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            border-top: none;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12);
        }

        .tab-content.active {
            display: block;
        }

        .members-list-wrapper, .visitors-list-wrapper { /* Added for consistent wrapping for all lists */
            display: grid; /* Make it a grid container */
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* Responsive columns */
            gap: 20px; /* Space between cards */
        }

        .member-card, .visitor-card {
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            color: #2C2C2C;
            border-radius: 12px; /* Slightly smaller radius for cards within tabs */
            padding: 18px;
            box-shadow: 0 4px 12px rgba(107, 91, 79, 0.08);
            display: flex;
            align-items: flex-start; /* Align items to the top */
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .member-card:hover, .visitor-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(107, 91, 79, 0.12);
            border-color: #D2B48C;
        }

        .member-card::before, .visitor-card::before { /* Left border accent */
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #B8860B 0%, #DAA520 100%);
            border-radius: 12px 0 0 12px;
        }

        .member-card .info p, .visitor-card .info p {
            margin: 4px 0;
            font-size: 0.9rem;
            display: grid; /* Changed to grid for consistent alignment */
            grid-template-columns: 100px 1fr; /* Labels (e.g., Name, Phone) at 100px, value takes rest */
            align-items: center;
            gap: 8px; /* Space between columns */
        }

        .member-card .info p strong, .visitor-card .info p strong {
            color: #6B5B4F;
            font-weight: 600;
            justify-self: start;
        }

        .member-card img.profile-pic-small, .visitor-card img.profile-pic-small {
            margin-right: 15px; /* Adjust margin */
        }
        
        .member-card .member-actions { /* Styles for buttons within member cards */
            margin-top: 15px;
            width: 100%;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .member-card .member-actions .btn {
            padding: 8px 15px;
            font-size: 0.85rem;
            border-radius: 8px;
            min-width: unset;
        }


        /* Log Table Styles */
        .log-table-container {
            overflow-x: auto; /* Enable horizontal scrolling for small screens */
            background: linear-gradient(135deg, #FFFFFF 0%, #FEFEFE 100%);
            border: 1px solid #E6DCC6;
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(107, 91, 79, 0.12);
        }

        .log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .log-table th, .log-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #E6DCC6;
        }

        .log-table th {
            background-color: #F0E68C; /* Light khaki header */
            color: #6B5B4F;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-top-left-radius: 16px; /* Matched container border radius */
            border-top-right-radius: 16px; /* Matched container border radius */
        }

        .log-table tbody tr:nth-child(even) {
            background-color: #F8F8F0; /* Slightly different background for even rows */
        }

        .log-table tbody tr:hover {
            background-color: #F0F5E0; /* Light hover effect */
        }

        /* General styling for action types */
        .log-table td.action-type {
            font-weight: 600;
            white-space: normal; /* Allow text to wrap */
            word-break: break-word; /* Break long words */
        }

        /* Specific colors for 'check-in' and 'check-out' based on common keywords */
        .log-table td.action-type[class*="check-in"] {
            color: #28A745; /* Green for check-in */
        }

        .log-table td.action-type[class*="check-out"] {
            color: #17A2B8; /* Blue for check-out */
        }


        .log-table .no-logs-message {
            text-align: center;
            padding: 20px;
            color: #6B5B4F;
            font-style: italic;
        }

        /* Custom Logout Confirmation Modal */
        .logout-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 3000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .logout-modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .logout-modal-content {
            background: #FFFFFF;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(107, 91, 79, 0.3);
            max-width: 420px;
            width: 90%;
            margin: 20px;
            transform: scale(0.7);
            transition: transform 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .logout-modal-overlay.show .logout-modal-content {
            transform: scale(1);
        }

        .logout-modal-header {
            background: linear-gradient(135deg, #8B7D6B 0%, #6B5B4F 100%);
            color: #FFFFFF;
            padding: 24px 32px;
            border-bottom: none;
            position: relative;
        }

        .logout-modal-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
        }

        .logout-modal-header h3 {
            margin: 0;
            font-size: 1.4rem;
            font-weight: 600;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .logout-modal-close {
            position: absolute;
            top: 18px;
            right: 24px;
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.8);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.3s ease;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logout-modal-close:hover {
            color: #FFFFFF;
            background: rgba(255,255,255,0.1);
            transform: rotate(90deg);
        }

        .logout-modal-body {
            padding: 32px;
            text-align: left;
        }

        .logout-modal-body p {
            margin: 0;
            color: #4A4A4A;
            font-size: 1.1rem;
            line-height: 1.6;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logout-modal-footer {
            padding: 0 32px 32px;
            display: flex;
            gap: 16px;
            justify-content: flex-end;
        }

        .logout-modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            min-width: 100px;
        }

        .logout-modal-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .logout-modal-btn:hover::before {
            left: 100%;
        }

        .logout-modal-btn-cancel {
            background: linear-gradient(135deg, #E6DCC6 0%, #D2B48C 100%);
            color: #6B5B4F;
            border: 2px solid #D2B48C;
        }

        .logout-modal-btn-cancel:hover {
            background: linear-gradient(135deg, #D2B48C 0%, #C8A882 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(210, 180, 140, 0.4);
        }

        .logout-modal-btn-confirm {
            background: linear-gradient(135deg, #DAA520 0%, #B8860B 100%);
            color: #FFFFFF;
            border: 2px solid #B8860B;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }

        .logout-modal-btn-confirm:hover {
            background: linear-gradient(135deg, #B8860B 0%, #9A7209 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(184, 134, 11, 0.4);
        }

        /* Flash Messages */
        .flash-message {
            padding: 16px 24px;
            border-radius: 8px;
            margin: 0 0 24px 0;
            font-weight: 500;
            font-size: 0.95rem;
            line-height: 1.5;
            opacity: 1;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid;
            box-sizing: border-box;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08), 0 1px 4px rgba(0, 0, 0, 0.04);
            position: relative;
            overflow: hidden;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
        }

        /* Subtle left border accent */
        .flash-message::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background-color: currentColor;
            opacity: 0.6;
        }

        /* Hover effects for interactive feel */
        .flash-message:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12), 0 2px 6px rgba(0, 0, 0, 0.08);
        }

        /* Success - Earthy Green with Khaki undertones */
        .flash-message.success {
            background: linear-gradient(135deg, #8FBC8F 0%, #9ACD32 100%);
            border-color: #7B9F7B;
            color: #2F4F2F;
            background-color: #8FBC8F; /* Fallback */
        }

        /* Danger - Muted Terracotta */
        .flash-message.danger {
            background: linear-gradient(135deg, #CD853F 0%, #D2691E 100%);
            border-color: #A0522D;
            color: #FFFFFF;
            background-color: #CD853F; /* Fallback */
        }

        /* Warning - Rich Khaki Gold */
        .flash-message.warning {
            background: linear-gradient(135deg, #DAA520 0%, #F0E68C 100%);
            border-color: #B8860B;
            color: #2F2F2F;
            background-color: #DAA520; /* Fallback */
        }

        /* Info - Sage Blue-Green */
        .flash-message.info {
            background: linear-gradient(135deg, #8FBC8F 0%, #20B2AA 100%);
            border-color: #5F9EA0;
            color: #FFFFFF;
            background-color: #8FBC8F; /* Fallback */
        }

        /* Primary - Deep Khaki */
        .flash-message.primary {
            background: linear-gradient(135deg, #BDB76B 0%, #9ACD32 100%);
            border-color: #8B8B00;
            color: #2F2F2F;
            background-color: #BDB76B; /* Fallback */
        }

        /* Secondary - Neutral Beige */
        .flash-message.secondary {
            background: linear-gradient(135deg, #D2B48C 0%, #F5DEB3 100%);
            border-color: #BC9A6A;
            color: #5D4E37;
            background-color: #D2B48C; /* Fallback */
        }

        /* Enhanced dismissible button styling */
        .flash-message .btn-close {
            position: absolute;
            top: 12px;
            right: 16px;
            background: transparent;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.2s ease;
            color: currentColor;
            padding: 4px;
            border-radius: 4px;
        }

        .flash-message .btn-close:hover {
            opacity: 1;
            background-color: rgba(0, 0, 0, 0.1);
        }

        /* Icon support */
        .flash-message .flash-icon {
            display: inline-block;
            margin-right: 12px;
            font-size: 1.1rem;
            vertical-align: middle;
        }

        /* Animation for new messages */
        .flash-message.flash-enter {
            opacity: 0;
            transform: translateY(-20px);
        }

        .flash-message.flash-enter-active {
            opacity: 1;
            transform: translateY(0);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* Exit animation */
        .flash-message.flash-exit {
            opacity: 1;
            max-height: 200px;
        }

        .flash-message.flash-exit-active {
            opacity: 0;
            max-height: 0;
            margin: 0;
            padding-top: 0;
            padding-bottom: 0;
            transition: all 0.3s ease-out;
        }

        /* Strong text emphasis */
        .flash-message strong {
            font-weight: 600;
            margin-right: 8px;
        }

        /* Link styling within messages */
        .flash-message a {
            color: currentColor;
            text-decoration: underline;
            text-underline-offset: 2px;
            text-decoration-thickness: 1px;
            transition: opacity 0.2s ease;
        }

        .flash-message a:hover {
            opacity: 0.8;
            text-decoration-thickness: 2px;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .flash-message {
                padding: 14px 20px;
                font-size: 0.9rem;
                margin: 0 0 20px 0;
                border-radius: 6px;
            }

            .flash-message .btn-close {
                top: 10px;
                right: 14px;
                font-size: 1.1rem;
            }

            .flash-message .flash-icon {
                margin-right: 10px;
                font-size: 1rem;
            }

            .tenant-profile-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }

            .profile-pic-large {
                margin-bottom: 20px;
                margin-right: 0;
            }

            .info {
                margin-right: 0;
                width: 100%;
            }

            .tenant-actions {
                flex-direction: column;
                width: 100%;
                margin-top: 20px;
            }

            .tenant-actions button {
                width: 100%;
            }

            .logout-modal-content {
                margin: 10px;
                max-width: 95%;
            }

            .logout-modal-header,
            .logout-modal-body {
                padding: 20px;
            }

            .logout-modal-footer {
                padding: 0 20px 20px;
                flex-direction: column;
            }

            .logout-modal-btn {
                width: 100%;
            }

            /* Member and visitor cards in tabs */
            .members-list-wrapper, .visitors-list-wrapper {
                grid-template-columns: 1fr; /* Single column on small screens */
                gap: 15px;
            }
            .member-card, .visitor-card {
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            .member-card img.profile-pic-small, .visitor-card img.profile-pic-small {
                margin-bottom: 15px;
                margin-right: 0;
            }
            .member-card .info p, .visitor-card .info p {
                flex-direction: column; /* Stack label and value */
                align-items: flex-start;
            }
            .member-card .info p strong, .visitor-card .info p strong {
                min-width: unset;
                margin-bottom: 5px;
            }
        }

        @media (max-width: 480px) {
            .flash-message {
                padding: 12px 16px;
                font-size: 0.85rem;
                margin: 0 0 16px 0;
            }

            .flash-message .btn-close {
                top: 8px;
                right: 12px;
            }

            .tabs {
                flex-wrap: wrap;
                padding: 0 5px;
            }

            .tab-button {
                padding: 12px 15px;
                font-size: 0.95rem;
                margin-right: 8px;
                flex-grow: 1;
            }

            .log-table th, .log-table td {
                padding: 8px 10px;
                font-size: 0.8rem;
            }
        }

        /* High contrast mode support */
        @media (prefers-contrast: high) {
            .flash-message {
                border-width: 2px;
                box-shadow: none;
            }

            .flash-message::before {
                width: 6px;
            }
        }

        /* Reduced motion support */
        @media (prefers-reduced-motion: reduce) {
            .flash-message,
            .flash-message.flash-enter-active,
            .flash-message.flash-exit-active,
            .flash-message .btn-close {
                transition: none;
            }

            .flash-message:hover {
                transform: none;
            }

            .logout-modal-overlay,
            .logout-modal-content {
                transition: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <aside class="sidebar">
            <div class="logo">Verifaced</div>
            <nav>
                <ul>
                    <li><a href="index.php">Unit Profiles</a></li>
                    <li><a href="registration.php">Account Registration</a></li>
                </ul>
            </nav>
            <a href="logout.php" class="logout-btn">Log out</a>
        </aside>
        <div class="content-area">
             <div class="menu-toggle" id="menuToggle">
                <div></div>
                <div></div>
                <div></div>
            </div>
            <main class="main-content" id="tenantInfoContent">
                <div id="flashMessageContainer">
                    <?php
                    // Display flash messages
                    if ($flash_message) {
                        echo "<div class='flash-message {$flash_message_type}'>{$flash_message}</div>";
                    }
                    ?>
                </div>

                <h2>Tenant Information</h2>
                <div class="tenant-profile-card"> <img src="<?php echo $tenant_photo_path; ?>" alt="Tenant Photo" id="tenantPhotoInfo" class="profile-pic-large">
                    <div class="info">
                        <p><strong>Name:</strong> <span id="tenantNameInfo"><?php echo $tenant_name; ?></span></p>
                        <p><strong>Unit Number:</strong> <span id="tenantUnitNumberInfo"><?php echo $tenant_unit_number; ?></span></p>
                        <p><strong>Phone Number:</strong> <span id="tenantPhoneNumberInfo"><?php echo $tenant_phone_number; ?></span></p>
                        <p><strong>Email:</strong> <span id="tenantEmailInfo"><?php echo $tenant_email; ?></span></p>
                        <p><strong>Current Status:</strong> <span class="status-indicator status-<?php echo e(str_replace(' ', '-', $tenant_current_status)); ?>"><?php echo $tenant_current_status; ?></span></p>
                        <p><strong>Last Status Update:</strong> <?php echo $tenant_last_status_update; ?></p>
                    </div>
                    </div>

                <div class="tabs">
                    <button class="tab-button active" data-tab-target="members">Members</button>
                    <button class="tab-button" data-tab-target="visitors">Visitors</button>
                    <button class="tab-button" data-tab-target="activityLog">Activity Log</button> </div>

                <div id="members" class="tab-content active">
                    <h3 class="section-header">Permanent Members</h3>
                    <?php echo $permanent_members_html; ?>
                    <h3 class="section-header" style="margin-top: 30px;">Registered Members</h3>
                    <?php echo $registered_members_html; ?>
                </div>

                <div id="visitors" class="tab-content">
                    <h3 class="section-header">Accepted Visitors</h3>
                    <?php echo $visitors_html; ?>
                </div>

                <div id="activityLog" class="tab-content">
                    <h3 class="section-header">Activity Log</h3>
                    <div class="log-table-container">
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>Action</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody id="activityLogTableBody">
                                <tr><td colspan="2" class="no-logs-message">Click "Activity Log" tab to see the tenant's activity log.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>


            </main>
        </div>
        <div class="overlay" id="overlay"></div>
    </div>

    <div id="logoutModal" class="logout-modal-overlay">
        <div class="logout-modal-content">
            <div class="logout-modal-header">
                <h3>Confirm Logout</h3>
                <button class="logout-modal-close" type="button">&times;</button>
            </div>
            <div class="logout-modal-body">
                <p>Are you sure you want to log out?</p>
            </div>
            <div class="logout-modal-footer">
                <button class="logout-modal-btn logout-modal-btn-cancel" type="button">Cancel</button>
                <button class="logout-modal-btn logout-modal-btn-confirm" type="button">Log Out</button>
            </div>
        </div>
    </div>

    <script>
        // Client-side JavaScript for tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');
            const tenantId = <?php echo json_encode($tenant_id); ?>; // Get tenant_id from PHP

            // Custom logout modal functionality
            function showLogoutModal() {
                const modal = document.getElementById('logoutModal');
                const confirmBtn = modal.querySelector('.logout-modal-btn-confirm');
                const cancelBtn = modal.querySelector('.logout-modal-btn-cancel');
                const closeBtn = modal.querySelector('.logout-modal-close');

                // Show modal with animation
                modal.classList.add('show');

                // Handle confirm button
                const handleConfirm = () => {
                    hideLogoutModal();
                    // Add smooth fade-out effect
                    document.body.style.opacity = '0';
                    document.body.style.transition = 'opacity 0.25s ease';
                    setTimeout(() => {
                        window.location.href = 'logout.php';
                    }, 250);
                };

                // Handle cancel button and close button
                const handleCancel = () => {
                    hideLogoutModal();
                };

                // Hide modal function
                const hideLogoutModal = () => {
                    modal.classList.remove('show');
                    // Remove event listeners to prevent memory leaks
                    confirmBtn.removeEventListener('click', handleConfirm);
                    cancelBtn.removeEventListener('click', handleCancel);
                    closeBtn.removeEventListener('click', handleCancel);
                    modal.removeEventListener('click', handleOverlayClick);
                    document.removeEventListener('keydown', handleEscape);
                };

                // Handle clicking outside modal
                const handleOverlayClick = (e) => {
                    if (e.target === modal) {
                        hideLogoutModal();
                    }
                };

                // Handle Escape key
                const handleEscape = (e) => {
                    if (e.key === 'Escape') {
                        hideLogoutModal();
                    }
                };

                // Add event listeners
                confirmBtn.addEventListener('click', handleConfirm);
                cancelBtn.addEventListener('click', handleCancel);
                closeBtn.addEventListener('click', handleCancel);
                modal.addEventListener('click', handleOverlayClick);
                document.addEventListener('keydown', handleEscape);
            }

            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const targetId = tab.dataset.tabTarget;

                    // Remove 'active' from all tabs and contents
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(tc => tc.classList.remove('active'));

                    // Add 'active' to clicked tab and its content
                    tab.classList.add('active');
                    document.getElementById(targetId).classList.add('active');

                    // If the Activity Log tab is clicked, fetch the log data
                    if (targetId === 'activityLog') {
                        fetchActivityLog(tenantId);
                    }
                });
            });

            // Function to fetch activity log data via AJAX
            function fetchActivityLog(tenantId) {
                const logTableBody = document.getElementById('activityLogTableBody');
                logTableBody.innerHTML = '<tr><td colspan="2" class="no-logs-message">Loading activity log...</td></tr>';

                fetch(`tenant_log.php?tenant_id=${tenantId}&fetch_data=true`)
                    .then(response => response.json())
                    .then(data => {
                        logTableBody.innerHTML = '';

                        if (data.success && data.logs.length > 0) {
                            data.logs.forEach(log => {
                                const row = logTableBody.insertRow();
                                const actionCell = row.insertCell();
                                const timestampCell = row.insertCell();

                                // Use log.description for the action text
                                actionCell.textContent = log.description;

                                // Optional: Keep original action_type for styling if needed
                                let baseActionType = log.action_type.split(':')[0].trim();
                                let sanitizedClass = baseActionType.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '');
                                actionCell.classList.add('action-type', sanitizedClass);

                                timestampCell.textContent = log.timestamp;
                            });
                        } else {
                            logTableBody.innerHTML = '<tr><td colspan="2" class="no-logs-message">No activity logs found for this tenant.</td></tr>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching activity log:', error);
                        logTableBody.innerHTML = '<tr><td colspan="2" class="no-logs-message" style="color: red;">Failed to load activity log.</td></tr>';
                    });
            }

            // --- Tenant Delete Function ---
            function deleteTenant(tenantId) {
                // You'll need to create a PHP file (e.g., process_tenant_action.php) to handle this.
                // This is a placeholder for the actual AJAX call and handling.
                console.log(`Attempting to delete tenant with ID: ${tenantId}`);

                fetch('process_tenant_action.php', { // You need to implement this file
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=delete_tenant&tenant_id=${tenantId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message); // Use a more sophisticated flash message system
                        window.location.href = 'index.php'; // Redirect back to unit profiles list
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred during deletion.');
                });
            }
            // Make deleteTenant globally accessible (or attach to window object)
            window.deleteTenant = deleteTenant;


            // ENHANCED: Logout button functionality with custom modal
            document.querySelector('.logout-btn').addEventListener('click', function(e) {
                e.preventDefault(); // Prevent the default href navigation
                showLogoutModal();
            });

            // Sidebar highlighting based on current page
            const currentPage = window.location.pathname.split("/").pop();
            document.querySelectorAll('.sidebar nav li a').forEach(link => {
                const linkPage = link.getAttribute('href');
                if (linkPage === currentPage) {
                    link.closest('li').classList.add('active');
                } else {
                    link.closest('li').classList.remove('active');
                }
            });

            // Menu toggle functionality for mobile
            const menuToggle = document.getElementById('menuToggle');
            const sidebar = document.querySelector('.sidebar');
            const overlay = document.getElementById('overlay');
            const body = document.body;

            if (menuToggle && sidebar && overlay && body) {
                menuToggle.addEventListener('click', function() {
                    sidebar.classList.toggle('active');
                    menuToggle.classList.toggle('active');
                    body.classList.toggle('sidebar-active');
                });

                overlay.addEventListener('click', function() {
                    sidebar.classList.remove('active');
                    menuToggle.classList.remove('active');
                    body.classList.remove('sidebar-active');
                });
            }
        });
    </script>
</body>
</html>